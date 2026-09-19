<script setup lang="ts">
/**
 * WithdrawalsView — the agent asks to be paid their earned commission.
 *
 * 2026-08-27. Until now the only payout mechanism was an admin opening the
 * ledger and marking rows paid one by one; an agent had no way to ask, and
 * no record existed that they had.
 *
 * ── EVERY NUMBER ON THIS SCREEN COMES FROM THE SERVER ──
 *
 * The available balance is NOT derived here from the ledger the agent can
 * see. It is `GET /commission-withdrawals/available`, because the real
 * figure also subtracts requests that are already open and absorbs
 * reversals, and a second implementation in the browser is how the number
 * on the button starts disagreeing with the number the server will enforce.
 * BR-3: satang everywhere, divided by 100 only for display.
 *
 * ── THE GATE IS SHOWN, NOT ENFORCED, HERE ──
 *
 * `payout_details_complete` disables the form and points at the profile
 * page. The refusal that matters is the server's
 * (CommissionWithdrawalService::request); this is the courtesy of not
 * letting somebody fill in a form that cannot succeed.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { apiErrorMessage } from '@/utils/apiError'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import AppButton from '@/design-system/components/AppButton.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { useToastStore } from '@/stores/toast'
// Sprint TZI18N-2 — every visible string on this screen comes from
// /lang/{th,en}.json via td(). Nothing user-facing is hardcoded here, so the
// language switch in the top bar actually changes this page.
import { useI18n } from '@/composables/useI18n'

const { td } = useI18n()
const toast = useToastStore()

interface WithdrawalRequest {
  id: number
  /*
   * GROSS — what this agent earned. The tax below does not reduce it: the
   * company withholds part of it and remits that part in the agent's name,
   * so the agent is credited with the withheld amount when they file. The
   * headline on each card stays this number for exactly that reason.
   */
  amount_satang: number
  /*
   * 2026-09-19 — ภาษีหัก ณ ที่จ่าย, as it was when this request was opened.
   * Null rate = nothing was withheld, which is every request made before the
   * setting existed.
   *
   * `net_transfer_satang` is what actually reaches the agent's bank, resolved
   * by the server (a historical row with no net recorded reports its gross).
   * This screen never subtracts anything itself.
   */
  wht_rate_at_time: number | null
  wht_satang: number
  net_transfer_satang: number
  status: 'pending_review' | 'approved' | 'rejected' | 'cancelled' | 'transferred'
  status_label: string
  rejection_reason: string | null
  transferred_at: string | null
  transfer_reference: string | null
  bank_name: string | null
  bank_account_number_masked: string | null
  created_at: string
}

interface AvailableResponse {
  available_satang: number
  min_withdrawal_satang: number | null
  /*
   * 2026-09-19 — the company's withholding rate in basis points, or null for
   * no withholding.
   *
   * The RATE, not a computed net: the amount is not known until the agent
   * types it, so a net sent with this response would go stale on every
   * keystroke. The estimate below is computed from whatever is in the field
   * at the moment, and labelled as an estimate — the figure that binds is the
   * one stamped onto the request when it is opened.
   */
  wht_rate: number | null
  payout_details_complete: boolean
}

const loading = ref(true)
const errorMessage = ref('')
const requests = ref<WithdrawalRequest[]>([])
const available = ref<AvailableResponse | null>(null)

// One controller for the view's lifetime — navigating away mid-load must
// not resolve into an unmounted view (TASK-079 Phase 4).
const controller = new AbortController()
onUnmounted(() => controller.abort())

const amountBaht = ref('')
const submitting = ref(false)
const formError = ref('')

const canRequest = computed(() =>
  Boolean(available.value?.payout_details_complete) && (available.value?.available_satang ?? 0) > 0,
)

function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท'
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

/** Colour by outcome, not by novelty: a rejection must not look like a pending one. */
function statusClass(status: WithdrawalRequest['status']): string {
  if (status === 'transferred') return 'bg-surface-success text-ink-success'
  if (status === 'approved') return 'bg-surface-chip text-ink-card'
  if (status === 'rejected') return 'bg-surface-danger text-ink-danger'
  if (status === 'cancelled') return 'bg-surface-chip text-ink-card-muted'
  return 'bg-surface-warning text-ink-warning'
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const [avail, list] = await Promise.all([
      api.get<AvailableResponse>('/commission-withdrawals/available', controller.signal),
      api.get<{ data: WithdrawalRequest[] }>('/commission-withdrawals', controller.signal),
    ])
    available.value = avail
    requests.value = list.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, td('withdrawal.load_failed'))
  } finally {
    loading.value = false
  }
}

/**
 * 2026-09-19 — what the agent will actually receive for the amount currently
 * typed, or null when nothing is withheld or the field is not a number yet.
 *
 * AN ESTIMATE, and labelled as one. The binding figure is the one the server
 * stamps onto the request when it is opened — this exists so the agent is not
 * surprised by a smaller transfer, not so they can reconcile to the satang.
 *
 * Truncated with Math.floor, matching the server's intdiv: rounding up here
 * would quote a tax one satang larger than the one that will be withheld,
 * which is the wrong direction to be wrong in on somebody's own money.
 */
const withholdingPreview = computed<{ rate: number; tax: number; net: number } | null>(() => {
  const rate = available.value?.wht_rate ?? null

  if (rate === null || rate <= 0) return null

  const baht = Number(amountBaht.value)

  if (!Number.isFinite(baht) || baht <= 0) return null

  const gross = Math.round(baht * 100)
  const tax = Math.floor((gross * rate) / 10000)

  return { rate, tax, net: gross - tax }
})

function fillMaximum(): void {
  if (!available.value) return
  amountBaht.value = (available.value.available_satang / 100).toFixed(2)
  formError.value = ''
}

async function submit(): Promise<void> {
  if (submitting.value) return
  formError.value = ''

  // Parsed to satang HERE and sent as an integer — a float never goes on
  // the wire (BR-3). Math.round, not truncation: 1234.565 typed by a human
  // is 123457 satang, not 123456.
  const baht = Number(amountBaht.value)

  if (!Number.isFinite(baht) || baht <= 0) {
    formError.value = td('withdrawal.amount_invalid')
    return
  }

  submitting.value = true
  try {
    await api.post('/commission-withdrawals', { amount_satang: Math.round(baht * 100) })
    amountBaht.value = ''
    toast.success(td('withdrawal.submitted'))
    await load()
  } catch (e) {
    // The server's own message when it has one: it carries the specific
    // refusal (below the minimum, more than available, details incomplete),
    // and replacing those with a generic sentence would throw away the only
    // part the agent can act on.
    const body = e instanceof ApiError ? (e.body as { errors?: Record<string, string[]> }) : null
    formError.value = body?.errors?.amount_satang?.[0] ?? apiErrorMessage(e, td('withdrawal.submit_failed'))
  } finally {
    submitting.value = false
  }
}

async function cancel(request: WithdrawalRequest): Promise<void> {
  try {
    await api.post(`/commission-withdrawals/${request.id}/cancel`)
    toast.success(td('withdrawal.cancelled'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, td('withdrawal.cancel_failed')))
  }
}

onMounted(load)
</script>

<template>
  <main class="p-4 pb-24 max-w-3xl mx-auto">
    <HeroHeader :title="td('withdrawal.title')" :subtitle="td('withdrawal.subtitle')" />

    <LoadingSkeleton v-if="loading" class="mt-4" />

    <p v-else-if="errorMessage" class="mt-4 text-sm font-bold text-ink-danger">{{ errorMessage }}</p>

    <template v-else>
      <AppCard class="mt-4">
        <p class="text-xs font-bold text-ink-card-muted">{{ td('withdrawal.available') }}</p>
        <p class="text-3xl font-bold text-ink-card mt-1">{{ formatSatang(available?.available_satang ?? 0) }}</p>
        <p v-if="available?.min_withdrawal_satang" class="text-xs text-ink-card-subtle mt-1">
          {{ td('withdrawal.minimum') }} {{ formatSatang(available.min_withdrawal_satang) }}
        </p>

        <!-- The gate, shown before the form rather than after a failed
             submit: an agent who cannot succeed should be told what to do,
             not made to discover it by trying. -->
        <div
          v-if="available && !available.payout_details_complete"
          class="mt-3 flex items-start gap-2 rounded-xl bg-surface-warning border border-line-card px-3 py-2.5"
        >
          <Icon name="info" :size="16" class="mt-0.5 shrink-0 text-ink-warning" />
          <div class="text-sm">
            <p class="font-bold text-ink-warning">{{ td('withdrawal.blocked_title') }}</p>
            <p class="text-xs text-ink-card-subtle mt-0.5">
              {{ td('withdrawal.blocked_body') }}
              <RouterLink to="/profile" class="font-bold underline">{{ td('withdrawal.blocked_cta') }}</RouterLink>
            </p>
          </div>
        </div>

        <div v-else class="mt-4">
          <label for="withdraw_amount" class="text-xs font-bold text-ink-card-muted block mb-1">
            {{ td('withdrawal.amount_label') }}
          </label>
          <div class="flex gap-2">
            <input
              id="withdraw_amount"
              v-model="amountBaht"
              type="text"
              inputmode="decimal"
              placeholder="0.00"
              :disabled="!canRequest"
              class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder flex-1 px-3 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-brand-200 disabled:opacity-60"
              :class="formError ? 'border-rose-400' : 'border-line-input'"
              @input="formError = ''"
            />
            <button
              type="button"
              :disabled="!canRequest"
              class="px-3 rounded-xl border border-line-card text-xs font-bold text-ink-card-muted hover:border-brand-500 disabled:opacity-60"
              @click="fillMaximum"
            >
              {{ td('withdrawal.fill_max') }}
            </button>
          </div>
          <p v-if="formError" class="mt-1 text-xs font-bold text-ink-danger">{{ formError }}</p>
          <!--
            2026-09-19 — told BEFORE the button, not after the transfer.

            An agent who asks for 1,500 and receives 1,455 without warning
            reads it as the company short-paying them, and the first anybody
            hears of it is a complaint. Shown only when there is something to
            withhold, and only once an amount has been typed: a tax line over
            an empty field is a warning about nothing.
          -->
          <p
            v-if="withholdingPreview"
            class="mt-1.5 text-xs text-ink-card-subtle"
            data-test="withdraw-wht-preview"
          >
            {{ td('withdrawal.wht_notice', '', {
              rate: String(withholdingPreview.rate / 100),
              net: formatSatang(withholdingPreview.net),
            }) }}
          </p>
          <AppButton :loading="submitting" :disabled="!canRequest" class="mt-3" block @click="submit">
            {{ td('withdrawal.submit') }}
          </AppButton>
        </div>
      </AppCard>

      <h2 class="mt-6 mb-2 text-sm font-bold text-ink-card">{{ td('withdrawal.history') }}</h2>

      <EmptyState v-if="requests.length === 0" icon="money" :title="td('withdrawal.empty_title')" :message="td('withdrawal.empty_body')" />

      <div v-else class="space-y-2">
        <AppCard v-for="r in requests" :key="r.id">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-lg font-bold text-ink-card">{{ formatSatang(r.amount_satang) }}</p>
              <!--
                The headline above is the GROSS, and stays so: it is what this
                agent earned, it is what their commission statement says, and
                it is the figure their withholding certificate will be written
                against. This line is the separate fact of what reached the
                bank — absent entirely when nothing was withheld.
              -->
              <p
                v-if="r.wht_satang > 0"
                class="text-xs text-ink-card-subtle mt-0.5"
                :data-test="`withdrawal-wht-${r.id}`"
              >
                {{ td('withdrawal.wht_line', '', {
                  rate: String((r.wht_rate_at_time ?? 0) / 100),
                  tax: formatSatang(r.wht_satang),
                }) }}
                ·
                <b class="text-ink-card">{{ td('withdrawal.wht_net', '', { net: formatSatang(r.net_transfer_satang) }) }}</b>
              </p>
              <p class="text-xs text-ink-card-subtle mt-0.5">{{ td('withdrawal.requested_on') }} {{ formatDate(r.created_at) }}</p>
              <p v-if="r.bank_account_number_masked" class="text-xs text-ink-card-subtle">
                {{ r.bank_name }} {{ r.bank_account_number_masked }}
              </p>
              <!-- Rendered verbatim: the admin wrote it for this agent, and
                   softening or summarising it here would remove the only
                   part that tells them what to change. -->
              <p v-if="r.rejection_reason" class="mt-1 text-xs font-bold text-ink-danger">
                {{ td('withdrawal.reason') }}: {{ r.rejection_reason }}
              </p>
              <p v-if="r.transferred_at" class="mt-1 text-xs text-ink-success">
                {{ td('withdrawal.transferred_on') }} {{ formatDate(r.transferred_at) }}
                <span v-if="r.transfer_reference">· {{ td('withdrawal.reference') }} {{ r.transfer_reference }}</span>
              </p>
            </div>
            <span class="shrink-0 px-2.5 py-1 rounded-full text-xs font-bold" :class="statusClass(r.status)">
              {{ r.status_label }}
            </span>
          </div>

          <!-- Only while nobody has decided. After approval the money may
               already be moving, and the server refuses anyway. -->
          <AppButton
            v-if="r.status === 'pending_review'"
            variant="secondary"
            size="sm"
            class="mt-3"
            @click="cancel(r)"
          >
            {{ td('withdrawal.cancel') }}
          </AppButton>
        </AppCard>
      </div>
    </template>
  </main>
</template>
