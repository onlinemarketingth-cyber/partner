<script setup lang="ts">
/**
 * CommissionWithdrawalsView — the admin queue for agent payout requests.
 *
 * 2026-08-27. Two decisions, deliberately separate (see
 * App\Enums\WithdrawalStatus): APPROVE says the request is legitimate;
 * MARK TRANSFERRED says the money actually left the bank. Only the second
 * one settles commission ledger rows, and only rows the payout finished off
 * — a row a request drew on only partly stays owed, because part of it is.
 *
 * ── WHY THE BANK DETAILS SHOWN HERE ARE A SNAPSHOT ──
 *
 * They were copied onto the request when the agent submitted it, not read
 * live. An agent may edit their profile while a request is open — that is
 * normal — but the account an admin approves must be the account that was
 * on screen when they approved it. The number is masked to its last four:
 * enough to recognise the account, never enough to be handed it.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

type WithdrawalStatus = 'pending_review' | 'approved' | 'rejected' | 'cancelled' | 'transferred'

interface WithdrawalRequest {
  id: number
  agent_id: number
  agent_name: string | null
  amount_satang: number
  status: WithdrawalStatus
  status_label: string
  rejection_reason: string | null
  decided_at: string | null
  decided_by: string | null
  transferred_at: string | null
  transfer_reference: string | null
  bank_name: string | null
  bank_account_number_masked: string | null
  bank_account_holder_name: string | null
  item_count: number | null
  created_at: string
}

const TABS: Array<{ value: '' | WithdrawalStatus; label: string }> = [
  { value: 'pending_review', label: 'รอตรวจสอบ' },
  { value: 'approved', label: 'อนุมัติแล้ว รอโอน' },
  { value: 'transferred', label: 'โอนแล้ว' },
  { value: '', label: 'ทั้งหมด' },
]

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const activeCompany = useActiveCompanyStore()

/*
 * The per-company minimum withdrawal, edited here rather than on a settings
 * page of its own: it is one number, and the place an admin thinks about it
 * is the queue where they see what agents are asking for.
 *
 * EMPTY MEANS NO MINIMUM, and that is a real setting — the field is bound to
 * a string so that "" survives as null instead of collapsing into a 0 that
 * would be saved back as a floor of zero baht.
 */
const minBaht = ref('')
const settingLoading = ref(false)
const settingSaving = ref(false)
const settingMessage = ref('')

const settingCompanyQuery = computed(() =>
  isSuperAdmin.value && activeCompany.companyId ? `?company_id=${activeCompany.companyId}` : '',
)

async function loadSetting(): Promise<void> {
  if (isSuperAdmin.value && !activeCompany.companyId) return

  settingLoading.value = true
  try {
    const res = await api.get<{ min_withdrawal_satang: number | null }>(
      `/commission-withdrawal-settings${settingCompanyQuery.value}`,
    )
    minBaht.value = res.min_withdrawal_satang === null ? '' : (res.min_withdrawal_satang / 100).toFixed(2)
  } catch {
    // A settings row that will not load must not take the queue down with
    // it — the queue is the reason this page exists.
    settingMessage.value = 'โหลดค่าขั้นต่ำไม่สำเร็จ'
  } finally {
    settingLoading.value = false
  }
}

async function saveSetting(): Promise<void> {
  if (settingSaving.value) return
  settingMessage.value = ''

  const trimmed = minBaht.value.trim()
  let satang: number | null = null

  if (trimmed !== '') {
    const baht = Number(trimmed)

    if (!Number.isFinite(baht) || baht < 0) {
      settingMessage.value = 'ยอดขั้นต่ำไม่ถูกต้อง'

      return
    }

    satang = Math.round(baht * 100)
  }

  settingSaving.value = true
  try {
    await api.put(`/commission-withdrawal-settings${settingCompanyQuery.value}`, {
      min_withdrawal_satang: satang,
    })
    settingMessage.value = satang === null ? 'บันทึกแล้ว — ไม่มีขั้นต่ำ' : 'บันทึกแล้ว'
  } catch (e) {
    const parsed = e instanceof ApiError ? (e.body as { errors?: Record<string, string[]> }) : null
    settingMessage.value = parsed?.errors?.min_withdrawal_satang?.[0] ?? 'บันทึกไม่สำเร็จ'
  } finally {
    settingSaving.value = false
  }
}

const loading = ref(true)
const errorMessage = ref('')
const requests = ref<WithdrawalRequest[]>([])
// Opens on the queue that needs a human, not on everything.
const activeTab = ref<'' | WithdrawalStatus>('pending_review')
const busyId = ref<number | null>(null)

const heading = computed(() => TABS.find((t) => t.value === activeTab.value)?.label ?? '')

function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท'
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

function statusClass(status: WithdrawalStatus): string {
  if (status === 'transferred') return 'bg-emerald-50 text-emerald-700'
  if (status === 'approved') return 'bg-slate-100 text-slate-700'
  if (status === 'rejected') return 'bg-rose-50 text-rose-700'
  if (status === 'cancelled') return 'bg-slate-100 text-slate-500'
  return 'bg-amber-50 text-amber-700'
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const query = activeTab.value ? `?status=${activeTab.value}` : ''
    const res = await api.get<{ data: WithdrawalRequest[] }>(`/commission-withdrawals${query}`)
    requests.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? 'โหลดข้อมูลไม่สำเร็จ' : 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'
  } finally {
    loading.value = false
  }
}

function selectTab(value: '' | WithdrawalStatus): void {
  activeTab.value = value
  void load()
}

/**
 * One place every decision goes through, so the busy state, the error
 * handling and the reload can never be wired up three slightly different
 * ways for three buttons that all move money.
 */
async function act(id: number, path: string, body?: Record<string, unknown>): Promise<void> {
  if (busyId.value !== null) return
  busyId.value = id
  errorMessage.value = ''
  try {
    await api.post(`/commission-withdrawals/${id}/${path}`, body)
    await load()
  } catch (e) {
    const parsed = e instanceof ApiError ? (e.body as { errors?: Record<string, string[]>; message?: string }) : null
    errorMessage.value =
      parsed?.errors?.status?.[0] ?? parsed?.message ?? 'ดำเนินการไม่สำเร็จ'
  } finally {
    busyId.value = null
  }
}

function approve(r: WithdrawalRequest): void {
  void act(r.id, 'approve')
}

function reject(r: WithdrawalRequest): void {
  // A reason is REQUIRED by the server, and it is shown to the agent
  // verbatim — so it is asked for here rather than sent blank and rejected.
  const reason = window.prompt(`เหตุผลที่ไม่อนุมัติคำขอของ ${r.agent_name ?? 'ตัวแทน'}`)

  if (reason === null) return

  if (!reason.trim()) {
    errorMessage.value = 'กรุณาระบุเหตุผลที่ไม่อนุมัติ'

    return
  }

  void act(r.id, 'reject', { rejection_reason: reason.trim() })
}

function markTransferred(r: WithdrawalRequest): void {
  // Optional on purpose (see MarkWithdrawalTransferredRequest): an empty
  // answer is a transfer with no reference worth recording, not a mistake.
  const reference = window.prompt(`เลขอ้างอิงการโอน (ไม่บังคับ) — ${formatSatang(r.amount_satang)}`)

  if (reference === null) return

  void act(r.id, 'mark-transferred', { transfer_reference: reference.trim() || null })
}

onMounted(async () => {
  await activeCompany.loadCompanies()
  await Promise.all([load(), loadSetting()])
})
</script>

<template>
  <main class="p-6 max-w-5xl mx-auto">
    <HeroHeader title="คำขอเบิกค่าคอมมิชชั่น" subtitle="ตรวจสอบ อนุมัติ และบันทึกการโอนเงินให้ตัวแทน" />

    <div class="mt-4 flex flex-wrap gap-2">
      <button
        v-for="tab in TABS"
        :key="tab.value"
        type="button"
        class="px-3 py-1.5 rounded-full text-xs font-bold border transition-colors"
        :class="
          activeTab === tab.value
            ? 'bg-slate-900 border-slate-900 text-white'
            : 'bg-white border-slate-200 text-slate-600 hover:border-slate-400'
        "
        @click="selectTab(tab.value)"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- 2026-08-27 — the per-company floor. Deliberately compact and above
         the queue: it is set once and then rarely touched, but when somebody
         wonders "why was that request refused", this is the answer and it
         should be on the same screen. -->
    <div class="mt-4 bg-white border border-slate-200 rounded-2xl p-4 max-w-xl">
      <p class="text-sm font-bold text-slate-900">ยอดขั้นต่ำในการเบิก</p>
      <p class="text-xs text-slate-500 mt-0.5">
        เว้นว่างไว้ = ไม่มีขั้นต่ำ (ตัวแทนเบิกเท่าไรก็ได้)
      </p>
      <div class="mt-2 flex gap-2">
        <input
          v-model="minBaht"
          type="text"
          inputmode="decimal"
          placeholder="เช่น 1000.00"
          :disabled="settingLoading || (isSuperAdmin && !activeCompany.companyId)"
          class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
        />
        <button
          type="button"
          :disabled="settingSaving || settingLoading || (isSuperAdmin && !activeCompany.companyId)"
          class="px-4 py-2 rounded-xl bg-slate-900 text-white text-xs font-bold disabled:opacity-50"
          @click="saveSetting"
        >
          {{ settingSaving ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
      <p v-if="settingMessage" class="mt-1 text-xs font-bold text-slate-600">{{ settingMessage }}</p>
    </div>

    <p v-if="errorMessage" class="mt-4 text-sm font-bold text-rose-600">{{ errorMessage }}</p>

    <LoadingSkeleton v-if="loading" class="mt-4" />

    <EmptyState
      v-else-if="requests.length === 0"
      icon="money"
      :title="`ไม่มีรายการ${heading}`"
      message="เมื่อมีตัวแทนส่งคำขอเบิก รายการจะแสดงที่นี่"
      class="mt-4"
    />

    <div v-else class="mt-4 space-y-3">
      <div
        v-for="r in requests"
        :key="r.id"
        class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3"
      >
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <p class="text-lg font-bold text-slate-900">{{ formatSatang(r.amount_satang) }}</p>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold" :class="statusClass(r.status)">
              {{ r.status_label }}
            </span>
          </div>
          <p class="text-sm text-slate-700 mt-0.5">{{ r.agent_name ?? '—' }}</p>
          <p class="text-xs text-slate-500 mt-0.5">
            ขอเมื่อ {{ formatDate(r.created_at) }}
            <span v-if="r.item_count"> · {{ r.item_count }} รายการค่าคอม</span>
          </p>
          <p v-if="r.bank_account_number_masked" class="text-xs text-slate-500 mt-1">
            <Icon name="money" :size="12" class="inline-block mr-1" />
            {{ r.bank_name }} {{ r.bank_account_number_masked }} · {{ r.bank_account_holder_name }}
          </p>
          <p v-if="r.rejection_reason" class="mt-1 text-xs font-bold text-rose-600">
            เหตุผล: {{ r.rejection_reason }}
          </p>
          <p v-if="r.decided_at" class="mt-1 text-xs text-slate-500">
            ตัดสินใจโดย {{ r.decided_by ?? '—' }} เมื่อ {{ formatDate(r.decided_at) }}
          </p>
          <p v-if="r.transferred_at" class="mt-1 text-xs text-emerald-700">
            โอนเมื่อ {{ formatDate(r.transferred_at) }}
            <span v-if="r.transfer_reference">· อ้างอิง {{ r.transfer_reference }}</span>
          </p>
        </div>

        <div class="flex flex-wrap gap-2 shrink-0">
          <template v-if="r.status === 'pending_review'">
            <button
              type="button"
              :disabled="busyId !== null"
              class="px-3 py-2 rounded-xl bg-slate-900 text-white text-xs font-bold disabled:opacity-50"
              @click="approve(r)"
            >
              อนุมัติ
            </button>
            <button
              type="button"
              :disabled="busyId !== null"
              class="px-3 py-2 rounded-xl border border-rose-200 text-rose-700 text-xs font-bold disabled:opacity-50"
              @click="reject(r)"
            >
              ไม่อนุมัติ
            </button>
          </template>

          <!-- Only after approval, and this is the ONLY button that settles
               ledger rows. Approving does not, on purpose. -->
          <button
            v-else-if="r.status === 'approved'"
            type="button"
            :disabled="busyId !== null"
            class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold disabled:opacity-50"
            @click="markTransferred(r)"
          >
            บันทึกการโอนเงิน
          </button>
        </div>
      </div>
    </div>
  </main>
</template>
