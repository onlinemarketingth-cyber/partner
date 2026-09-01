<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * CoAgentEditor — TASK-026's split-commission control for one deal row.
 *
 * WHAT IT EDITS, AND WHY THAT MATTERS
 * -----------------------------------
 * `co_agent_id` + `split_percentage` decide WHO GETS PAID. When the referral
 * reaches Complete Payment, CommissionService writes TWO immutable BR-4
 * ledger rows off these two fields instead of one. There is no screen
 * anywhere else in the Agent Portal that can set them after creation, so if
 * this control is missing the money silently all goes to one agent.
 *
 * TASK-169 Phase 4a — it lives here, in ONE component, because Phase 4b
 * deletes `ReferralsView.vue`, which is where it used to be inlined. ag-lead
 * ruled the deletion is blocked until the capability is reachable from the
 * client drawer (§5b item 1). Extracting rather than re-typing it in the
 * drawer is the whole point: two copies of a money control are two copies
 * that drift, and the one that drifts is the one nobody is looking at.
 *
 * IT OWNS ITS OWN PATCH, unlike ReferralRow.vue which is deliberately
 * presentational. The reason ReferralRow defers to its parent is the ORDER
 * strategy — paging, the duplicate-order 422 recovery — which has to be
 * decided once per SCREEN, not once per row. Setting a co-agent has no such
 * strategy: it is one self-contained request about one referral, so keeping
 * it here is what makes "one implementation of the split logic" true.
 *
 * The API is the authority, always. `ReferralService::setCoAgent()` re-checks
 * the stage cutoff and rejects a co-agent who is the referring agent
 * themselves; `SetCoAgentRequest` re-checks the both-or-neither rule and the
 * 1–99 range, and `ReferralPolicy::setCoAgent` re-checks ownership (BR-6).
 * Everything below is honest reflection of that state — never a gate the
 * client is trusted to enforce.
 *
 * AFFORDANCE (TASK-169 Phase 4a) — a full-width line in the row's `footer`,
 * not another chip in its action row. The action row already carries four
 * controls at 375px (product-detail toggle, stage chip, order chip,
 * "เก็บเงินเลย") and they wrap; a fifth would put "who gets paid" beside a
 * browse toggle where it reads as one more filter, and would be the first
 * thing to wrap off the visible line. A full-width line never competes for
 * width, so nothing clips on a phone, and it can carry its own label — which
 * a bare "+ แบ่งคอมฯ" chip cannot. The editor expands in place directly under
 * its own trigger rather than opening a modal: in the client drawer that
 * modal would be a second overlay stacked on the drawer, which on a phone
 * takes two back gestures to escape.
 */
import { computed, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { apiErrorMessage } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
import AppButton from './AppButton.vue'

/** One selectable co-agent: this company's other agents, never yourself. */
export interface CoAgentOption {
  id: number
  name: string
}

/** Only the fields this control reads — never the full ReferralResource. */
export interface CoAgentEditorReferral {
  id: number
  /**
   * Optional, not just nullable: `co_agent` is a `whenLoaded` key, so it is
   * absent (rather than null) on any response that did not eager-load the
   * relation.
   */
  co_agent?: { id: number; name: string } | null
  /**
   * Optional for a SECOND reason since TASK-174: while the company's split is
   * switched off, `ReferralResource` omits `co_agent` AND `split_percentage`
   * entirely instead of nulling them. This component is not mounted at all in
   * that state (its host `v-if`s on the flag), but the type must still admit
   * an absent key, or a caller could be told a missing field is a real `null`.
   */
  split_percentage?: number | null
  current_stage: { key: string; label: string }
}

const props = defineProps<{
  referral: CoAgentEditorReferral
  /** `GET /referrals/co-agent-options` — the host loads it once per screen. */
  options: CoAgentOption[]
}>()

const emit = defineEmits<{
  /** The split was written; the host should re-fetch whatever it renders. */
  saved: []
  /**
   * The write failed. Emitted rather than rendered here because the two
   * hosts report failures in different PLACES: the client drawer's own
   * banner sits behind a fixed overlay, so it toasts as well.
   */
  error: [string]
}>()

const toast = useToastStore()

/**
 * The edit cutoff, mirroring `ReferralService::setCoAgent()`: once the money
 * has landed, BR-4's ledger row already exists (possibly already split) and
 * BR-4 forbids rewriting a ledger entry after the fact.
 *
 * TODO: CONFIRM (business rule) — this predicate is a DENY-list of two stage
 * keys, carried over verbatim from ReferralsView.vue so TASK-169 Phase 4a
 * changes no behaviour. The server uses an ALLOW-list of the three
 * pre-payment MEDICAL stages, and since ADR-026 added `delivery`,
 * `service_appointment` and `follow_up` the two no longer agree: a referral
 * parked on a post-sale stage is offered this control here and rejected by
 * the API. Same shape of defect as the `DONE_STAGE_KEYS` list Phase 3b
 * removed from the board. Fixing it is a one-line change in ONE place now,
 * but which fix is right ("the three medical stages" vs "anything before
 * `complete_payment` in the referral's OWN template", and what a referral
 * with an unreadable template should get) is ag-lead's call, not a
 * refactor's. Raised with the Phase 4 report.
 */
const NON_EDITABLE_STAGE_KEYS = ['complete_payment', 'ongoing_next_meeting']
const canEdit = computed(() => !NON_EDITABLE_STAGE_KEYS.includes(props.referral.current_stage.key))

const open = ref(false)
const saving = ref(false)
const form = ref<{ co_agent_id: string | number; split_percentage: string | number }>({
  co_agent_id: '',
  split_percentage: '',
})

/**
 * Opening RE-READS the referral rather than keeping whatever was typed last
 * time: the row may have been re-fetched since (the host reloads after every
 * save), and an editor showing a stale split is an editor that will write one
 * back.
 */
function toggle(): void {
  if (open.value) {
    open.value = false
    return
  }
  form.value = {
    co_agent_id: props.referral.co_agent ? String(props.referral.co_agent.id) : '',
    split_percentage: props.referral.split_percentage ?? '',
  }
  open.value = true
}

/**
 * Both-or-neither, the client half of `SetCoAgentRequest`'s rule: a co-agent
 * with no percentage is not a saveable state. Clearing the co-agent IS
 * saveable — that is how a split is removed, and it sends both fields as
 * null (the server defaults the absent keys to null for exactly this).
 */
const saveDisabled = computed(() => !!form.value.co_agent_id && form.value.split_percentage === '')

async function submit(): Promise<void> {
  saving.value = true
  try {
    await api.patch(`/referrals/${props.referral.id}/co-agent`, {
      co_agent_id: form.value.co_agent_id ? Number(form.value.co_agent_id) : null,
      split_percentage:
        form.value.co_agent_id && form.value.split_percentage !== ''
          ? Number(form.value.split_percentage)
          : null,
    })
    open.value = false
    emit('saved')
    toast.success('บันทึกผู้ร่วมทีมแล้ว')
  } catch (e) {
    // 422 carries the server's own field messages (the stage cutoff, the
    // "must be a different agent" rule, the 1–99 range). They are more
    // specific than anything the shared normalizer can produce, so they are
    // flattened and shown rather than replaced.
    if (e instanceof ApiError && e.status === 422) {
      const errors = (e.body as { errors?: Record<string, string[]> } | undefined)?.errors
      emit('error', errors ? Object.values(errors).flat().join(' ') : 'บันทึกไม่สำเร็จ')
    } else {
      emit('error', apiErrorMessage(e, 'บันทึกไม่สำเร็จ'))
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <!-- Past the cutoff there is nothing to offer: the split is already law in
       the ledger. ReferralRow still RENDERS an existing co-agent above (read
       -only), so the fact never disappears — only the ability to change it. -->
  <div v-if="canEdit" class="mt-3 pt-3 border-t border-line-card-subtle">
    <div class="flex items-center justify-between gap-2">
      <div class="min-w-0">
        <p class="text-[11px] font-bold text-ink-card-subtle uppercase tracking-wider">{{ td('commission.split') }}</p>
        <p class="text-xs text-ink-card-muted truncate">
          <template v-if="referral.co_agent">
            {{ referral.co_agent.name }} · {{ referral.split_percentage }}%
          </template>
          <template v-else>{{ td('commission.split_none') }}</template>
        </p>
      </div>
      <!-- Wording preserved verbatim from ReferralsView: the two states read
           differently on purpose ("add" vs "change"), and an agent looking
           for this control is looking for these exact words. -->
      <button
        type="button"
        class="shrink-0 min-h-[44px] inline-flex items-center text-xs font-bold text-ink-brand hover:text-ink-brand active:scale-95 transition-transform whitespace-nowrap"
        @click="toggle"
      >
        {{ referral.co_agent ? 'แก้ไขคอมฯ ร่วม' : '+ แบ่งคอมฯ' }}
      </button>
    </div>

    <div v-if="open" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2">
      <select
        v-model="form.co_agent_id"
        class="bg-surface-input text-ink-input px-3 py-2 rounded-lg border border-line-input text-sm"
      >
        <!-- Selecting this and saving is how a split is REMOVED. -->
        <option value="">{{ td('commission.no_split') }}</option>
        <option v-for="a in options" :key="a.id" :value="a.id">{{ a.name }}</option>
      </select>
      <input
        v-model="form.split_percentage"
        type="number"
        min="1"
        max="99"
        :disabled="!form.co_agent_id"
        :placeholder="td('commission.split_ph')"
        class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder px-3 py-2 rounded-lg border border-line-input text-sm disabled:bg-surface-chip"
      />
      <div class="md:col-span-2 flex gap-2">
        <AppButton size="sm" :loading="saving" :disabled="saveDisabled" @click="submit">{{ td('common.save2') }}</AppButton>
        <button
          type="button"
          class="min-h-[44px] px-3 py-1.5 rounded-lg text-ink-card-muted text-xs font-bold active:scale-95 transition-transform inline-flex items-center justify-center"
          @click="open = false"
        >
          {{ td('common.cancel2') }}
        </button>
      </div>
    </div>
  </div>
</template>
