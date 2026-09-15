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
  /**
   * 2026-09-15 — which of the two doors this came through: an agent asked
   * (`agent_request`), or an admin raised it from ตั้งจ่าย (`company_payout`).
   *
   * They are the same object from here on, which is the point — but a
   * reviewer still has to be able to tell at a glance whose decision they are
   * looking at, especially in the รอโอน tab where both kinds sit together.
   */
  source: 'agent_request' | 'company_payout'
  source_label: string
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
 * ออกเลย" — the edit now lives on ตั้งค่าค่าแนะนำ → ขั้นที่ 4, with every other
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
/*
 * 2026-09-15 — THE THREE-STEP RAIL (แบบ A).
 *
 * Owner: "มันดูแล้วไม่เข้าใจทันทีว่า User เข้ามาต้องทำอะไร ดูอะไรบ้าง".
 *
 * The screen was four boxes of equal weight — heading, filter chips, a
 * settings read-out, an empty state and a four-bullet explainer — and not one
 * figure anywhere. A reader could not learn where the money was or what to
 * press.
 *
 * The chips were already the three states; they just looked like filters
 * rather than a process. They are now a band that carries each step's COUNT
 * and MONEY, with arrows between, so "where is this money and what is left
 * for me" is answered before anything is clicked. Filtering still happens by
 * pressing a step — the same act, now with the reason for it on screen.
 *
 * The figures are server-computed (GET /commission-withdrawals/summary,
 * scoped by the same `visibleTo()` as the list) rather than counted from the
 * loaded page: this panel loads ONE status at a time, twenty rows at a time,
 * so anything derived here would describe the page and be read as describing
 * the step.
 */
interface StepSummary { count: number; satang: number }

const summary = ref<Record<string, StepSummary>>({})
const summaryFailed = ref(false)

const RAIL: Array<{ value: WithdrawalStatus; step: number; label: string; hint: string }> = [
  { value: 'pending_review', step: 1, label: 'รอตรวจสอบ', hint: 'รอคุณตัดสินใจ' },
  { value: 'approved', step: 2, label: 'รอโอน', hint: 'อยู่ที่ฝ่ายบัญชี' },
  { value: 'transferred', step: 3, label: 'โอนแล้ว', hint: 'ปิดรายการแล้ว' },
]

const rail = computed(() => RAIL.map((step) => ({
  ...step,
  count: summary.value[step.value]?.count ?? 0,
  satang: summary.value[step.value]?.satang ?? 0,
})))

async function loadSummary(): Promise<void> {
  try {
    const res = await api.get<{ data: Record<string, StepSummary> }>(
      activeCompany.scopedPath('/commission-withdrawals/summary'),
    )
    summary.value = res.data
    summaryFailed.value = false
  } catch {
    /*
     * A band that cannot be read must not print zeros: "รอโอน 0 บาท" is a
     * statement that nothing is owed, and this is the screen where somebody
     * decides whether a payout run is finished. The rail hides itself and
     * says so — the queue underneath still works.
     */
    summary.value = {}
    summaryFailed.value = true
  }
}

const activeTab = ref<'' | WithdrawalStatus>('pending_review')
const busyId = ref<number | null>(null)

const heading = computed(() => TABS.find((t) => t.value === activeTab.value)?.label ?? '')

/**
 * What the reader is meant to DO with the tab they are looking at.
 *
 * One sentence, above the list, because "รอโอน" names a STATE and not a job —
 * and the job is the thing the owner could not find anywhere on this screen.
 */
const INSTRUCTIONS: Record<string, string> = {
  pending_review: 'ตรวจแล้วกดอนุมัติ หรือไม่อนุมัติพร้อมเหตุผล',
  approved: 'ส่งให้ฝ่ายบัญชีโอน แล้วกลับมากดบันทึกว่าโอนแล้ว',
  transferred: 'ปิดเรียบร้อยแล้ว ดูอย่างเดียว',
  rejected: 'ไม่อนุมัติไปแล้ว — เงินกลับไปให้ตัวแทนขอใหม่ได้',
  cancelled: 'ตัวแทนยกเลิกเอง',
  '': 'ทุกสถานะรวมกัน',
}

const instruction = computed(() => INSTRUCTIONS[activeTab.value] ?? '')


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
    // Both, always: a decision moves a row from one step to the next, so a
    // reloaded list under a stale band would show the row gone from รอตรวจสอบ
    // while the band still counted it there.
    await Promise.all([load(), loadSummary()])
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
  await Promise.all([load(), loadSetting(), loadSummary()])
})

// The minimum-withdrawal box is per company too, and it sits directly above
// the queue — leaving one of them on the old company while the other moved
// would be worse than not following the switch at all.
watch(() => activeCompany.companyId, () => {
  void load()
  void loadSetting()
  void loadSummary()
})
</script>

<template>
  <!--
    2026-09-15 — THE QUEUE, AS A PANEL INSIDE จ่ายเงิน (แนวทาง C).

    Owner: "การทำให้ Admin ทำงานควรเห็น 2 แบบ 1 คือ agent ขอ กับถึงกำหนดทำจ่าย
    ค่าคอม ถึงไปขั้นตอนทำจ่ายจริง คือการกดยืนยันจ่ายจริง".

    This was its own menu item, over a queue that was always empty because the
    other menu settled the same money in one click. Both now raise the same
    object, so there is one queue and it lives beside the screen that feeds
    it — no page chrome of its own, no second company scope notice, no second
    HeroHeader. See CommissionPayoutsView, which hosts it.
  -->
  <div>
    <!--
      ═══ THE RAIL (แบบ A, 2026-09-15) ═══

      These were four flat chips that filtered a list and said nothing else.
      They are the same three states, drawn as the process they are, each
      carrying its own count and its own money — so a reader learns the whole
      flow and where they stand from one band, before pressing anything.

      Still buttons, still filters. The arrows are decoration with a job:
      they say the money moves left to right and cannot skip a step.
    -->
    <div v-if="!summaryFailed" class="mt-4 flex items-stretch gap-0" data-test="queue-rail">
      <template v-for="(step, i) in rail" :key="step.value">
        <div v-if="i > 0" class="flex items-center px-2 shrink-0" aria-hidden="true">
          <Icon name="chevron_right" :size="18" class="text-slate-300" />
        </div>
        <button
          type="button"
          class="flex-1 min-w-0 text-left px-4 py-3 rounded-2xl border transition-colors"
          :class="activeTab === step.value
            ? 'border-2 border-brand-600 bg-brand-50'
            : 'border-slate-200 bg-white hover:border-slate-300'"
          :aria-pressed="activeTab === step.value"
          :data-test="`queue-step-${step.value}`"
          @click="selectTab(step.value)"
        >
          <span class="flex items-center gap-2">
            <span
              class="inline-flex items-center justify-center w-5 h-5 rounded-md text-[11px] font-extrabold"
              :class="activeTab === step.value ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
            >{{ step.step }}</span>
            <span class="text-[13.5px] font-extrabold" :class="activeTab === step.value ? 'text-brand-700' : 'text-slate-700'">{{ step.label }}</span>
          </span>
          <span class="block mt-2 text-[21px] font-extrabold text-slate-900 tabular-nums">
            {{ formatSatang(step.satang) }}
          </span>
          <span class="block mt-0.5 text-[12px]" :class="step.count > 0 && step.value === 'pending_review' ? 'text-amber-700 font-bold' : 'text-slate-500'">
            {{ step.count }} รายการ · {{ step.hint }}
          </span>
        </button>
      </template>
    </div>

    <!-- The band failed to load. Zeros here would read as "nothing is owed"
         on the screen where somebody decides a payout run is finished. -->
    <p v-else class="mt-4 text-xs text-rose-600" data-test="queue-rail-error">
      อ่านยอดรวมของแต่ละขั้นไม่สำเร็จ — รายการด้านล่างยังใช้งานได้ตามปกติ
    </p>

    <!-- "ทั้งหมด" is not a step, so it is not in the rail. It stays as the
         way out of the three-step view for anyone looking for a row they
         cannot place. -->
    <div class="mt-2 flex items-center gap-3">
      <button
        type="button"
        class="text-[12.5px] font-bold transition-colors"
        :class="activeTab === '' ? 'text-brand-700' : 'text-slate-400 hover:text-slate-600'"
        data-test="queue-step-all"
        @click="selectTab('')"
      >
        {{ activeTab === '' ? '· กำลังแสดงทุกสถานะ' : 'ดูทุกสถานะ' }}
      </button>
    </div>

    <p v-if="errorMessage" class="mt-4 text-sm font-bold text-rose-600">{{ errorMessage }}</p>

    <!-- One line, where the eye already is: what this tab is, and what the
         reader is expected to do with it. -->
    <p v-if="!loading && requests.length" class="mt-5 text-sm font-bold text-slate-700" data-test="queue-instruction">
      {{ heading }} {{ requests.length }} รายการ
      <span class="font-normal text-slate-500">— {{ instruction }}</span>
    </p>

    <LoadingSkeleton v-if="loading" class="mt-4" />

    <template v-else-if="requests.length === 0">
      <EmptyState
        icon="money"
        :title="`ไม่มีรายการ${heading}`"
        message="เมื่อมีตัวแทนส่งคำขอเบิก รายการจะแสดงที่นี่"
        class="mt-4"
      />

      <!--
        2026-09-15 (แบบ A) — FOUR BULLETS BECAME ONE SENTENCE.

        The list version of this was written to answer "ทำไมข้อมูลไม่ขึ้น" and
        did — at the cost of being the largest thing on the screen. An
        explainer outweighing the work is exactly what the owner was looking
        at when they said the page never told them what to do.

        The rail above now answers most of it in numbers. What is left is the
        one fact a number cannot carry: nothing arrives here by itself.
      -->
      <div class="mt-3 rounded-2xl border border-slate-200 bg-white px-5 py-6 text-center" data-test="withdrawal-empty-help">
        <p class="text-[15px] font-extrabold text-slate-900">ไม่มีอะไรรออยู่ในขั้นนี้</p>
        <p class="mt-1.5 text-[13px] text-slate-500">
          รายการจะเข้ามาเมื่อ<b class="font-bold text-slate-700">ตัวแทนกดขอเบิก</b>
          หรือเมื่อ<b class="font-bold text-slate-700">คุณกดตั้งจ่าย</b>ให้ใครสักคน
        </p>
        <RouterLink
          :to="{ name: 'commission-payouts' }"
          class="inline-flex items-center justify-center h-[40px] min-w-[90px] px-5 mt-4 rounded-[0.65rem] border-2 border-brand-600 text-brand-600 text-sm font-extrabold hover:bg-brand-50"
          data-test="link-payouts"
        >
          ไปหน้าตั้งจ่าย
        </RouterLink>
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
            <!--
              WHOSE DECISION THIS WAS. In the รอโอน tab an agent's approved
              request and a payout the company raised sit side by side, and
              they are answerable to different people: one was agreed to by
              whoever approved it, the other by whoever pressed ตั้งจ่าย.
            -->
            <span
              class="px-2 py-0.5 rounded-full text-[11px] font-bold"
              :class="r.source === 'company_payout' ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-600'"
              :data-test="`payout-source-${r.id}`"
            >
              {{ r.source_label }}
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

    <!--
      2026-09-15 (แบบ A) — THE FLOOR, DEMOTED TO A FOOTNOTE.

      Read-only, and always was; what changed is its weight. It answers "why
      was that request refused" — a question nobody asks until a refusal has
      happened — so it no longer sits between the reader and the queue as one
      of four boxes of equal size.
    -->
    <p class="mt-6 text-xs text-slate-400" data-test="withdrawal-minimum-readout">
      <span v-if="minWithdrawalUnknown" class="text-rose-600 font-bold">อ่านยอดขั้นต่ำในการเบิกไม่สำเร็จ</span>
      <template v-else>
        ยอดขั้นต่ำในการเบิกของบริษัทนี้:
        <b class="font-bold text-slate-500">
          <span v-if="minWithdrawalSatang === null">ไม่มีขั้นต่ำ — ตัวแทนเบิกเท่าไรก็ได้</span>
          <span v-else>{{ formatSatang(minWithdrawalSatang) }}</span>
        </b>
      </template>
      ·
      <RouterLink :to="{ name: 'commission-plan-settings' }" class="font-bold text-brand-600 hover:underline" data-test="link-commission-step4">
        แก้ที่ตั้งค่าค่าแนะนำ → ขั้นที่ 4
      </RouterLink>
    </p>
  </div>
</template>
