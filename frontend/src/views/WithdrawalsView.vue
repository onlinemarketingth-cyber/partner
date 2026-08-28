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

const toast = useToastStore()

interface WithdrawalRequest {
  id: number
  amount_satang: number
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
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลการเบิกไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

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
    formError.value = 'กรุณากรอกจำนวนเงินให้ถูกต้อง'
    return
  }

  submitting.value = true
  try {
    await api.post('/commission-withdrawals', { amount_satang: Math.round(baht * 100) })
    amountBaht.value = ''
    toast.success('ส่งคำขอเบิกแล้ว รอแอดมินตรวจสอบ')
    await load()
  } catch (e) {
    // The server's own message when it has one: it carries the specific
    // refusal (below the minimum, more than available, details incomplete),
    // and replacing those with a generic sentence would throw away the only
    // part the agent can act on.
    const body = e instanceof ApiError ? (e.body as { errors?: Record<string, string[]> }) : null
    formError.value = body?.errors?.amount_satang?.[0] ?? apiErrorMessage(e, 'ส่งคำขอไม่สำเร็จ')
  } finally {
    submitting.value = false
  }
}

async function cancel(request: WithdrawalRequest): Promise<void> {
  try {
    await api.post(`/commission-withdrawals/${request.id}/cancel`)
    toast.success('ยกเลิกคำขอแล้ว')
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, 'ยกเลิกไม่สำเร็จ'))
  }
}

onMounted(load)
</script>

<template>
  <main class="p-4 pb-24 max-w-3xl mx-auto">
    <HeroHeader title="เบิกค่าคอมมิชชั่น" subtitle="ขอรับเงินค่าคอมมิชชั่นที่สะสมไว้" />

    <LoadingSkeleton v-if="loading" class="mt-4" />

    <p v-else-if="errorMessage" class="mt-4 text-sm font-bold text-ink-danger">{{ errorMessage }}</p>

    <template v-else>
      <AppCard class="mt-4">
        <p class="text-xs font-bold text-ink-card-muted">ยอดที่เบิกได้</p>
        <p class="text-3xl font-bold text-ink-card mt-1">{{ formatSatang(available?.available_satang ?? 0) }}</p>
        <p v-if="available?.min_withdrawal_satang" class="text-xs text-ink-card-subtle mt-1">
          ขั้นต่ำในการเบิก {{ formatSatang(available.min_withdrawal_satang) }}
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
            <p class="font-bold text-ink-warning">ยังเบิกไม่ได้</p>
            <p class="text-xs text-ink-card-subtle mt-0.5">
              ต้องกรอกเอกสารยืนยันตัวตนและบัญชีธนาคารให้ครบก่อน
              <RouterLink to="/profile" class="font-bold underline">ไปที่หน้าโปรไฟล์</RouterLink>
            </p>
          </div>
        </div>

        <div v-else class="mt-4">
          <label for="withdraw_amount" class="text-xs font-bold text-ink-card-muted block mb-1">
            จำนวนเงินที่ต้องการเบิก (บาท)
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
              ทั้งหมด
            </button>
          </div>
          <p v-if="formError" class="mt-1 text-xs font-bold text-ink-danger">{{ formError }}</p>
          <AppButton :loading="submitting" :disabled="!canRequest" class="mt-3" block @click="submit">
            ส่งคำขอเบิก
          </AppButton>
        </div>
      </AppCard>

      <h2 class="mt-6 mb-2 text-sm font-bold text-ink-card">ประวัติการเบิก</h2>

      <EmptyState v-if="requests.length === 0" icon="money" title="ยังไม่มีคำขอเบิก" message="เมื่อคุณส่งคำขอ รายการจะแสดงที่นี่" />

      <div v-else class="space-y-2">
        <AppCard v-for="r in requests" :key="r.id">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-lg font-bold text-ink-card">{{ formatSatang(r.amount_satang) }}</p>
              <p class="text-xs text-ink-card-subtle mt-0.5">ขอเมื่อ {{ formatDate(r.created_at) }}</p>
              <p v-if="r.bank_account_number_masked" class="text-xs text-ink-card-subtle">
                {{ r.bank_name }} {{ r.bank_account_number_masked }}
              </p>
              <!-- Rendered verbatim: the admin wrote it for this agent, and
                   softening or summarising it here would remove the only
                   part that tells them what to change. -->
              <p v-if="r.rejection_reason" class="mt-1 text-xs font-bold text-ink-danger">
                เหตุผล: {{ r.rejection_reason }}
              </p>
              <p v-if="r.transferred_at" class="mt-1 text-xs text-ink-success">
                โอนเมื่อ {{ formatDate(r.transferred_at) }}
                <span v-if="r.transfer_reference">· อ้างอิง {{ r.transfer_reference }}</span>
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
            ยกเลิกคำขอ
          </AppButton>
        </AppCard>
      </div>
    </template>
  </main>
</template>
