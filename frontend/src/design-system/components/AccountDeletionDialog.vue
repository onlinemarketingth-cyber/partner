<script setup lang="ts">
/**
 * AccountDeletionDialog — MOB-30 (2026-10-02), reshaped by MOB-12's follow-up
 * (owner decision 2026-10-03).
 *
 * "ลบบัญชี": App Store guideline 5.1.1(v) requires the option inside the app;
 * it is offered on the web portal too, so the two never disagree about what
 * an agent can do.
 *
 * ── WHAT HAPPENS DEPENDS ON MONEY STILL OWED ──
 *
 * The dialog first asks the server (previewAccountDeletion) how much
 * commission is still unpaid, then shows one of three things:
 *
 *   * nothing unpaid → the account is deleted IMMEDIATELY on submit, and that
 *     cannot be undone. Said plainly, because it is the moment of decision.
 *   * unpaid → the amount, and two choices:
 *       (a) "ยินยอมไม่รับค่าคอม" — give it up; deleted immediately;
 *       (b) "ต้องการรับค่าคอม"   — the request goes to the company's admin,
 *           who pays first and then deletes; signed out meanwhile.
 *     When the server says waiving is not possible right now (part of the
 *     money is already in a payout), only (b) is offered, with the server's
 *     own sentence saying why.
 *
 * The server re-decides all of this when the request arrives (the preview
 * may be stale); a 422/409 from it is shown as its own sentence and the
 * preview is re-read so the choices on screen match the server again.
 *
 * The short consequence of each choice is in the option itself; the longer
 * explanation (what happens to commission history, downline and customers)
 * sits behind the ⓘ — CLAUDE.md §7.
 *
 * Money arrives as integer satang and is divided by 100 only in formatBaht()
 * below (BR-3). The request, the preview and the sign-out live in the auth
 * store; this component only collects input and shows outcomes (§7).
 *
 * Built on ConfirmDialog's pattern (same scrim, card, z-index and button
 * row); it needs its own file because ConfirmDialog has no form fields.
 *
 * Usage:
 *   <AccountDeletionDialog v-model:show="open" @requested="(outcome) => ..." />
 *   outcome: 'deleted' (gone now) | 'pending' (waiting for an admin)
 */
import { computed, ref, watch } from 'vue'
import Icon from './Icon.vue'
import InfoPopover from './InfoPopover.vue'
import { useI18n } from '@/composables/useI18n'
import {
  useAuthStore,
  type AccountDeletionChoice,
  type AccountDeletionOutcome,
  type AccountDeletionPreview,
} from '@/stores/auth'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useCloseOnBack } from '@/platform/backStack'

const props = withDefaults(defineProps<{ show?: boolean }>(), { show: false })

const emit = defineEmits<{
  'update:show': [value: boolean]
  /** The server accepted the request and this device is signed out. */
  requested: [outcome: AccountDeletionOutcome]
}>()

const { td } = useI18n()
const auth = useAuthStore()

/** Same cap as the server's Form Request. */
const REASON_MAX = 500

const password = ref('')
const reason = ref('')
const showPassword = ref(false)
const submitting = ref(false)
const error = ref('')

const preview = ref<AccountDeletionPreview | null>(null)
const previewLoading = ref(false)
const previewError = ref('')
const choice = ref<AccountDeletionChoice | null>(null)

const owed = computed(() => preview.value?.pending_commission_satang ?? 0)
const hasUnpaid = computed(() => owed.value !== 0)
const canWaive = computed(() => preview.value?.can_waive ?? false)
/**
 * Will pressing the button delete the account right now? True with nothing
 * unpaid (and nothing in flight), or when the agent picked "waive".
 */
const deletesImmediately = computed(() => {
  if (!preview.value) return false
  if (!hasUnpaid.value) return canWaive.value
  return choice.value === 'waive'
})

/** BR-3 — the only place this dialog divides satang by 100. */
function formatBaht(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

async function loadPreview(): Promise<void> {
  previewLoading.value = true
  previewError.value = ''
  try {
    preview.value = await auth.previewAccountDeletion()
    // Only "keep" exists when waiving is blocked, so it is the answer.
    if (preview.value.pending_commission_satang !== 0 && !preview.value.can_waive) {
      choice.value = 'keep'
    } else if (choice.value === 'waive' && !preview.value.can_waive) {
      choice.value = null
    }
  } catch (e) {
    if (isAbortError(e)) return
    preview.value = null
    previewError.value = apiErrorMessage(
      e,
      td('account_deletion.preview_failed', 'โหลดข้อมูลค่าแนะนำค้างจ่ายไม่สำเร็จ'),
    )
  } finally {
    previewLoading.value = false
  }
}

// A reopened dialog is a new decision — never prefilled with the last attempt.
watch(
  () => props.show,
  (open) => {
    if (!open) return
    password.value = ''
    reason.value = ''
    showPassword.value = false
    error.value = ''
    choice.value = null
    preview.value = null
    void loadPreview()
  },
  { immediate: true },
)

function close(): void {
  if (submitting.value) return
  emit('update:show', false)
}

// MOB-25 — Android's back button is "cancel" here, and does nothing mid-send.
useCloseOnBack(() => props.show, close)

async function submit(): Promise<void> {
  if (submitting.value || !preview.value) return

  if (hasUnpaid.value && choice.value === null) {
    error.value = td(
      'account_deletion.choice_required',
      'กรุณาเลือกว่าจะรอรับค่าแนะนำ หรือยินยอมสละสิทธิ์',
    )
    return
  }

  if (password.value === '') {
    error.value = td('account_deletion.password_required', 'กรุณากรอกรหัสผ่าน')
    return
  }

  submitting.value = true
  error.value = ''
  try {
    const outcome = await auth.requestAccountDeletion(
      password.value,
      reason.value,
      hasUnpaid.value ? choice.value : null,
    )
    password.value = ''
    emit('update:show', false)
    emit('requested', outcome)
  } catch (e) {
    // The server's own Thai sentence (wrong password, choice missing, waive
    // blocked, already pending) — shown as is.
    error.value = apiErrorMessage(e, td('account_deletion.failed', 'ส่งคำขอไม่สำเร็จ กรุณาลองใหม่'))
    // A 409/422 that is not about the password usually means what is unpaid
    // changed since the preview; re-read it so the options match again.
    const status = (e as { status?: number } | null)?.status
    const passwordError = (e as { body?: { errors?: Record<string, unknown> } } | null)?.body
      ?.errors?.password
    if ((status === 409 || status === 422) && !passwordError) void loadPreview()
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <transition name="fade">
    <div
      v-if="show"
      class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-slate-500/30 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-labelledby="account-deletion-title"
      @click.self="close"
    >
      <form
        class="w-full max-w-sm max-h-[90vh] overflow-y-auto rounded-2xl bg-surface-card/95 backdrop-blur-xl border border-line-card shadow-2xl p-6"
        novalidate
        @submit.prevent="submit"
      >
        <div
          class="w-16 h-16 rounded-full mx-auto mb-4 flex items-center justify-center bg-surface-danger text-ink-danger"
        >
          <Icon name="trash" :size="28" />
        </div>

        <h3
          id="account-deletion-title"
          class="text-lg font-bold text-ink-card text-center mb-3 flex items-center justify-center gap-1.5"
        >
          {{ td('account_deletion.title', 'ลบบัญชีผู้ใช้') }}
          <InfoPopover :label="td('account_deletion.title', 'ลบบัญชีผู้ใช้')">
            <p>
              {{
                td(
                  'account_deletion.info_records',
                  'ประวัติค่าแนะนำและการเบิกเงินจะยังถูกเก็บไว้ เพราะเป็นหลักฐานทางการเงินที่ต้องเก็บรักษา ส่วนข้อมูลส่วนตัว (ชื่อ อีเมล เบอร์โทร บัญชีธนาคาร รูปโปรไฟล์) จะถูกลบ',
                )
              }}
            </p>
            <p class="mt-2">
              {{
                td(
                  'account_deletion.info_waive',
                  'ถ้าเลือกสละสิทธิ์ ค่าแนะนำที่ค้างจ่ายจะไม่ถูกจ่ายให้อีก และบัญชีจะถูกลบทันที ถ้าเลือกรอรับ ผู้ดูแลระบบของบริษัทจะจ่ายให้ก่อนแล้วจึงลบบัญชี ระหว่างนั้นคุณจะเข้าสู่ระบบไม่ได้',
                )
              }}
            </p>
            <p class="mt-2">
              {{
                td(
                  'account_deletion.info_team',
                  'ลูกทีมและลูกค้าที่คุณดูแลจะถูกย้ายไปให้ผู้อื่นดูแลโดยผู้ดูแลระบบของบริษัท',
                )
              }}
            </p>
          </InfoPopover>
        </h3>

        <!-- ── what will happen ─────────────────────────────────────── -->
        <div
          v-if="previewLoading"
          class="py-6 text-center text-sm text-ink-card-muted"
          role="status"
          data-test="preview-loading"
        >
          {{ td('account_deletion.preview_loading', 'กำลังตรวจสอบค่าแนะนำค้างจ่าย...') }}
        </div>

        <div v-else-if="previewError" class="mb-4 text-center" data-test="preview-error">
          <p class="text-sm text-ink-danger" role="alert">{{ previewError }}</p>
          <button
            type="button"
            class="mt-3 min-h-[44px] px-4 py-2 rounded-xl text-sm font-bold text-ink-chip bg-surface-chip hover:opacity-80"
            @click="loadPreview"
          >
            {{ td('account_deletion.retry', 'ลองอีกครั้ง') }}
          </button>
        </div>

        <template v-else-if="preview">
          <!-- Nothing unpaid: deleted on the spot. -->
          <div
            v-if="!hasUnpaid && canWaive"
            class="mb-4 rounded-xl bg-surface-danger px-3 py-3 text-sm text-ink-danger"
            data-test="immediate-notice"
          >
            <p class="font-bold">
              {{ td('account_deletion.immediate_title', 'บัญชีจะถูกลบทันทีเมื่อกดยืนยัน') }}
            </p>
            <p class="mt-1 leading-relaxed">
              {{
                td(
                  'account_deletion.immediate_body',
                  'คุณไม่มีค่าแนะนำค้างจ่าย บัญชีและข้อมูลส่วนตัวจะถูกลบทันที และย้อนกลับไม่ได้',
                )
              }}
            </p>
          </div>

          <!-- Nothing unpaid, but a payout is still on its way: admin decides. -->
          <div
            v-else-if="!hasUnpaid"
            class="mb-4 rounded-xl bg-surface-warning px-3 py-3 text-sm text-ink-warning"
            data-test="admin-notice"
          >
            <p>{{ preview.waive_blocked_reason }}</p>
            <p class="mt-1">
              {{
                td(
                  'account_deletion.goes_to_admin',
                  'คำขอจะถูกส่งให้ผู้ดูแลระบบของบริษัทดำเนินการ ระหว่างนั้นคุณจะเข้าสู่ระบบไม่ได้',
                )
              }}
            </p>
          </div>

          <!-- Unpaid commission: the agent chooses. -->
          <fieldset v-else class="mb-4" data-test="commission-choice">
            <legend class="text-sm text-ink-card mb-2">
              {{ td('account_deletion.owed_prefix', 'คุณมีค่าแนะนำค้างจ่าย') }}
              <span class="font-bold" data-test="owed-amount">฿{{ formatBaht(owed) }}</span>
            </legend>

            <label
              v-if="canWaive"
              class="flex items-start gap-2 rounded-xl border px-3 py-2.5 mb-2 cursor-pointer"
              :class="
                choice === 'waive' ? 'border-rose-400 bg-surface-danger' : 'border-line-input'
              "
            >
              <input
                v-model="choice"
                type="radio"
                name="commission-choice"
                value="waive"
                class="mt-1"
                data-test="choice-waive"
                @change="error = ''"
              />
              <span class="text-sm">
                <span class="font-bold text-ink-card">
                  {{ td('account_deletion.choice_waive', 'ยินยอมไม่รับค่าคอม') }}
                </span>
                <span class="block text-ink-card-muted">
                  {{
                    td(
                      'account_deletion.choice_waive_hint',
                      'สละค่าแนะนำที่ค้าง แล้วลบบัญชีทันที ย้อนกลับไม่ได้',
                    )
                  }}
                </span>
              </span>
            </label>

            <label
              class="flex items-start gap-2 rounded-xl border px-3 py-2.5 cursor-pointer"
              :class="choice === 'keep' ? 'border-brand-400 bg-surface-chip' : 'border-line-input'"
            >
              <input
                v-model="choice"
                type="radio"
                name="commission-choice"
                value="keep"
                class="mt-1"
                data-test="choice-keep"
                @change="error = ''"
              />
              <span class="text-sm">
                <span class="font-bold text-ink-card">
                  {{ td('account_deletion.choice_keep', 'ต้องการรับค่าคอม') }}
                </span>
                <span class="block text-ink-card-muted">
                  {{
                    td(
                      'account_deletion.choice_keep_hint',
                      'ผู้ดูแลระบบจะจ่ายให้ก่อนแล้วจึงลบบัญชี ระหว่างนั้นคุณจะเข้าสู่ระบบไม่ได้',
                    )
                  }}
                </span>
              </span>
            </label>

            <p
              v-if="!canWaive && preview.waive_blocked_reason"
              class="mt-2 text-xs text-ink-warning"
              data-test="waive-blocked"
            >
              {{ preview.waive_blocked_reason }}
            </p>
          </fieldset>

          <label
            for="account-deletion-password"
            class="block text-xs font-bold text-ink-card-muted mb-1"
          >
            {{ td('account_deletion.password', 'รหัสผ่านปัจจุบัน (เพื่อยืนยันว่าเป็นคุณ)') }}
          </label>
          <div class="relative mb-3">
            <input
              id="account-deletion-password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              class="bg-surface-input text-ink-input w-full min-h-[44px] px-3 py-2 pr-10 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              :class="error ? 'border-rose-400' : 'border-line-input'"
              @input="error = ''"
            />
            <button
              type="button"
              tabindex="-1"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink-card-subtle hover:text-ink-card-muted"
              :aria-label="
                showPassword
                  ? td('account_deletion.hide_password', 'ซ่อนรหัสผ่าน')
                  : td('account_deletion.show_password', 'แสดงรหัสผ่าน')
              "
              @click="showPassword = !showPassword"
            >
              <Icon :name="showPassword ? 'eye_off' : 'eye'" :size="16" />
            </button>
          </div>

          <label
            for="account-deletion-reason"
            class="block text-xs font-bold text-ink-card-muted mb-1"
          >
            {{ td('account_deletion.reason', 'เหตุผล (ไม่บังคับ)') }}
          </label>
          <textarea
            id="account-deletion-reason"
            v-model="reason"
            rows="3"
            :maxlength="REASON_MAX"
            :placeholder="td('account_deletion.reason_ph', 'บอกเราได้ว่าทำไมจึงต้องการลบบัญชี')"
            class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
          ></textarea>
        </template>

        <p v-if="error" class="mt-2 text-xs font-bold text-ink-danger" role="alert">{{ error }}</p>

        <div class="mt-5 flex gap-3">
          <button
            type="button"
            :disabled="submitting"
            class="flex-1 min-h-[44px] px-4 py-2.5 rounded-xl text-sm font-bold text-ink-chip bg-surface-chip hover:opacity-80 transition disabled:opacity-50"
            @click="close"
          >
            {{ td('common.cancel', 'ยกเลิก') }}
          </button>
          <button
            type="submit"
            :disabled="submitting || !preview"
            class="flex-1 min-h-[44px] px-4 py-2.5 rounded-xl text-sm font-bold text-white shadow transition disabled:opacity-50 bg-rose-500 hover:bg-rose-600"
            data-test="submit"
          >
            {{
              submitting
                ? td('account_deletion.submitting', 'กำลังดำเนินการ...')
                : deletesImmediately
                  ? td('account_deletion.submit_delete', 'ลบบัญชีทันที')
                  : td('account_deletion.submit', 'ส่งคำขอลบบัญชี')
            }}
          </button>
        </div>
      </form>
    </div>
  </transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
