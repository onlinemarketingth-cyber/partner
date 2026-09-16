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
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
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
 * 2026-09-13 — THE MINIMUM IS READ HERE AND WRITTEN SOMEWHERE ELSE.
 *
 * Owner: "ยอดขั้นต่ำในการเบิก ปรับมาเป็น UI หน้านี้หน้าเดียวให้จบ นำของเก่า
 * ออกเลย" — the edit now lives on แผนคอมมิชชั่น → ขั้นที่ 4, with every other
 * commission setting, and the input that used to be on this page is gone.
 *
 * The READ stays, deliberately. The original comment on that input argued the
 * right thing for the wrong control: when an admin wonders "why was that
 * request refused", the answer is this number and the question gets asked HERE
 * — so the number is shown here. What it no longer is, is a second place to
 * change it.
 *
 * NULL IS A REAL ANSWER (no minimum) and is rendered as such. `unknown` is the
 * separate state for a read that failed, because a page that printed "ไม่มี
 * ขั้นต่ำ" after a 500 would explain a refusal with a fact it does not have.
 */
const minWithdrawalSatang = ref<number | null>(null)
const minWithdrawalUnknown = ref(false)

const settingCompanyQuery = computed(() =>
  isSuperAdmin.value && activeCompany.companyId ? `?company_id=${activeCompany.companyId}` : '',
)

async function loadSetting(): Promise<void> {
  if (isSuperAdmin.value && !activeCompany.companyId) return

  try {
    const res = await api.get<{ min_withdrawal_satang: number | null }>(
      `/commission-withdrawal-settings${settingCompanyQuery.value}`,
    )
    minWithdrawalSatang.value = res.min_withdrawal_satang
    minWithdrawalUnknown.value = false
  } catch {
    // A settings row that will not load must not take the queue down with
    // it — the queue is the reason this page exists.
    minWithdrawalUnknown.value = true
  }
}

const loading = ref(true)
const errorMessage = ref('')
const requests = ref<WithdrawalRequest[]>([])
// Opens on the queue that needs a human, not on everything.
const activeTab = ref<'' | WithdrawalStatus>('pending_review')
const busyId = ref<number | null>(null)

const heading = computed(() => TABS.find((t) => t.value === activeTab.value)?.label ?? '')

/*
 * 2026-09-15 — HEADER FIGURES THAT DESCRIBE THE TAB, AND SAY SO.
 *
 * Every other admin screen carries KPIs in its header, and this one looked
 * unfinished without them. The honest ones here are NOT company-wide totals:
 * this page loads one status at a time (`?status=`), so the only numbers it
 * actually has are about the rows currently listed. The labels therefore name
 * the tab rather than the company — a header reading "รอโอนรวม" over a list
 * filtered to "โอนแล้ว" would be a figure about money nobody measured.
 */
const kpis = computed(() => [
  { label: `${heading.value} (รายการ)`, value: requests.value.length },
  {
    label: `${heading.value} (ยอดรวม)`,
    value: formatSatang(requests.value.reduce((sum, r) => sum + r.amount_satang, 0)),
  },
])

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
    /*
     * 2026-09-04 — the queue itself was never scoped to the header's
     * company, on either side: this request sent no company_id, and the
     * controller applied no filter. A Super Admin therefore reviewed every
     * tenant's withdrawal requests under a header naming one of them. Both
     * halves are fixed; this is the half that asks.
     */
    const res = await api.get<{ data: WithdrawalRequest[] }>(
      activeCompany.scopedPath(`/commission-withdrawals${query}`),
    )
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
  const reason = window.prompt(`เหตุผลที่ไม่อนุมัติคำขอของ ${r.agent_name ?? 'สมาชิก'}`)

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

// The minimum-withdrawal box is per company too, and it sits directly above
// the queue — leaving one of them on the old company while the other moved
// would be worse than not following the switch at all.
watch(() => activeCompany.companyId, () => {
  void load()
  void loadSetting()
})
</script>

<template>
  <!--
    2026-09-15 — FULL WIDTH, like every other admin screen.

    This was `p-6 max-w-5xl mx-auto`: a centred column roughly half the width
    of the window, while จ่ายเงิน, แผนคอมมิชชั่น, จัดการตัวแทน and the rest all
    use `min-h-screen px-4 py-6 lg:px-8`. The queue rows are wide — amount,
    agent, bank account, three buttons — so the narrow column was also the
    place where they wrapped soonest.
  -->
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="money"
      title="คำขอเบิกค่าแนะนำ"
      subtitle="ตรวจสอบ อนุมัติ และบันทึกการโอนเงินให้สมาชิก"
      description="สมาชิกกดขอเบิกเองจากพอร์ทัล → ที่นี่คืออนุมัติ แล้วบันทึกว่าโอนจริงแล้ว — ยอดที่เบิกได้มาจากค่าแนะนำที่ยัง “รอจ่าย” เท่านั้น"
      :kpis="kpis"
      accent-color="brand"
      storage-key="commission-withdrawals"
    />

    <CompanyScopeNotice action="ดูคำขอเบิกค่าแนะนำ" />

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

    <!-- 2026-09-13 — the floor, READ-ONLY. It explains refusals, which is why
         it is on this page at all; it is no longer edited here, which is why
         there is no input. See the script's own note. -->
    <div class="mt-4 bg-white border border-slate-200 rounded-2xl px-4 py-3" data-test="withdrawal-minimum-readout">
      <p class="text-xs text-slate-500">ยอดขั้นต่ำในการเบิกของบริษัทนี้</p>
      <p class="text-sm font-bold text-slate-900 mt-0.5">
        <span v-if="minWithdrawalUnknown" class="text-rose-600">อ่านค่าไม่สำเร็จ</span>
        <span v-else-if="minWithdrawalSatang === null">ไม่มีขั้นต่ำ — สมาชิกเบิกเท่าไรก็ได้</span>
        <span v-else>{{ formatSatang(minWithdrawalSatang) }}</span>
      </p>
      <p class="text-xs text-slate-400 mt-1">
        แก้ไขที่
        <RouterLink :to="{ name: 'commission-plan-settings' }" class="font-bold text-brand-600 hover:underline" data-test="link-commission-step4">
          แผนค่าแนะนำ → ขั้นที่ 4 →
        </RouterLink>
      </p>
    </div>

    <p v-if="errorMessage" class="mt-4 text-sm font-bold text-rose-600">{{ errorMessage }}</p>

    <LoadingSkeleton v-if="loading" class="mt-4" />

    <template v-else-if="requests.length === 0">
      <EmptyState
        icon="money"
        :title="`ไม่มีรายการ${heading}`"
        message="เมื่อมีสมาชิกส่งคำขอเบิก รายการจะแสดงที่นี่"
        class="mt-4"
      />

      <!--
        2026-09-15 — WHY IT IS EMPTY, NOT JUST THAT IT IS.

        Owner: "ทำไมข้อมูลไม่ขึ้น". An empty queue on a money screen reads as a
        broken page, and the old empty state ("เมื่อมีตัวแทนส่งคำขอเบิก…") was
        true without being an answer: it never said that this queue only ever
        fills from the AGENT's side, nor that settling rows directly on
        จ่ายเงิน removes them from what an agent is able to ask for.

        Those two routes to the same money genuinely compete — a company that
        pays its agents from the payout screen will see this page empty
        forever, and that is correct behaviour rather than a fault. Saying so
        here is the difference between an admin checking the other tabs and an
        admin filing a bug.
      -->
      <div class="mt-3 rounded-2xl border border-slate-200 bg-white px-4 py-3.5" data-test="withdrawal-empty-help">
        <p class="text-[13px] font-extrabold text-slate-900">รายการมาจากไหน</p>
        <ul class="mt-1.5 space-y-1 text-[12.5px] text-slate-600 list-disc pl-4">
          <li>
            สมาชิกเป็นคนกดขอเบิกเองในพอร์ทัลสมาชิก (เมนู <b>เบิกค่าแนะนำ</b>) — หน้านี้ไม่มีปุ่มสร้างคำขอแทนสมาชิก
          </li>
          <li>
            สมาชิกขอได้เฉพาะค่าแนะนำที่สถานะยัง <b>“รอจ่าย”</b> เท่านั้น
          </li>
          <li>
            ถ้าคุณกด <b>“จ่ายแล้ว”</b> หรือ <b>“จ่ายทั้งหมด”</b> ในหน้า
            <RouterLink :to="{ name: 'commission-management' }" class="font-bold text-brand-600 hover:underline" data-test="link-payouts">จ่ายเงิน</RouterLink>
            ไปแล้ว ค่าแนะนำก้อนนั้นจะถูกปิดไปเลย สมาชิกจะขอเบิกไม่ได้อีก และหน้านี้จะว่างตลอด — ไม่ใช่ระบบเสีย
          </li>
          <li>
            ลองกดแท็บ <b>ทั้งหมด</b> ด้านบนดูก่อน เผื่อมีคำขอที่อนุมัติหรือโอนไปแล้ว
          </li>
        </ul>
        <p class="mt-2 text-[12px] text-slate-500">
          พูดง่าย ๆ คือมีสองทางจ่ายเงินให้สมาชิก และใช้ทางไหนทางหนึ่ง —
          <b>คุณจ่ายเอง</b> ที่หน้าจ่ายเงิน หรือ <b>ให้สมาชิกขอเบิก</b> แล้วมาอนุมัติที่หน้านี้
        </p>
      </div>
    </template>

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
            <span v-if="r.item_count"> · {{ r.item_count }} รายการค่าแนะนำ</span>
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
