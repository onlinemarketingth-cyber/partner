<script setup lang="ts">
/**
 * รายงานการจ่าย — /commission/runs.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * 2026-09-16 — THIS ROUTE USED TO BE รอบจ่าย, THE SECOND WORKING SCREEN.
 *
 * Owner: "การตั้งจ่าย กับรอบจ่าย มันแทบจะแทนกันได้แล้ว … รวมเป็นหน้าเดียว" and,
 * for what was left behind: "หน้าเดิมเป็นสรุปรายการ เป็น Log ที่โอนแล้ว รอโอน
 * โดยบัญชี Filter ได้ Export เป็น CSV ได้ตามที่ Filter".
 *
 * Every button that wrote anything moved to จ่ายค่าแนะนำ. What is left is the
 * record — and the rule that keeps the two screens from becoming
 * interchangeable again is a single sentence:
 *
 *   ═══ THERE IS NO CONTROL ON THIS PAGE THAT WRITES ANYTHING. ═══
 *
 * No approve, no reject, no mark-transferred, not now and not later. The day a
 * write button appears here is the day we have rebuilt the duplication this
 * merge removed. If something on this page needs changing, the link back to the
 * working screen is what the reader wants.
 *
 * ── WHY THE STATUSES ARE CHIPS AND NOT A BAND OF CARDS ──
 *
 * The working screen shows its statuses as a numbered band of big cards,
 * because there they are STEPS — stages of an errand, in order, with work
 * waiting in each. Here a status is a SEARCH CONDITION: unordered, combinable,
 * and including two (ไม่อนุมัติ, ยกเลิกโดยตัวแทน) that are not stages of
 * anything. Drawing them the same way is how two pages start looking like each
 * other again even when they do different jobs.
 *
 * ── WHY THE DATE WINDOW ASKS WHICH DATE ──
 *
 * A payout has two, and they answer different questions. "How much did we pay
 * out in September" is the transfer date; "how much was asked for in September"
 * is the request date — and a payout raised in August and transferred in
 * September belongs to both answers, once each. Choosing one silently would
 * make this report right for one reader and quietly wrong for the other.
 *
 * ── THE PROMISE ──
 *
 * The totals bar, the rows and the CSV are the SAME SET, always. They are three
 * reads of one server-side query (reportQuery in the Controller) and every
 * filter below is sent to all three. A figure that describes a different set
 * from the rows under it is the defect this whole page exists to avoid.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * BR-3: amounts are integer satang; divided by 100 only here, at the display
 * layer.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import DateRangeFilter from '@/design-system/components/DateRangeFilter.vue'
import { RouterLink } from 'vue-router'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

const activeCompany = useActiveCompanyStore()

interface WithdrawalRequest {
  id: number
  agent_id: number
  agent_name: string | null
  source: 'agent_request' | 'company_payout'
  source_label: string
  amount_satang: number
  status: 'pending_review' | 'approved' | 'rejected' | 'cancelled' | 'transferred'
  status_label: string
  rejection_reason: string | null
  decided_at: string | null
  decided_by: string | null
  transferred_at: string | null
  transfer_reference: string | null
  bank_name: string | null
  /**
   * MASKED, always, and masked in the CSV too. Unlike the bank file on the
   * working screen — which carries full numbers because it is an instruction
   * somebody pays people from — this is a record that gets filed, mailed and
   * forwarded, and nothing it is used for needs the digits back.
   */
  bank_account_number_masked: string | null
  bank_account_holder_name: string | null
  item_count: number | null
  created_at: string
}

interface ReportSummary {
  total_satang: number
  count: number
  transferred_satang: number
  transferred_count: number
  outstanding_satang: number
  outstanding_count: number
  payee_count: number
}

const STATUS_CHIPS = [
  { value: 'pending_review', label: 'รอตรวจสอบ' },
  { value: 'approved', label: 'รอโอน' },
  { value: 'transferred', label: 'โอนแล้ว' },
  { value: 'rejected', label: 'ไม่อนุมัติ' },
  { value: 'cancelled', label: 'ตัวแทนยกเลิก' },
] as const

const SOURCE_CHIPS = [
  { value: 'company_payout', label: 'บริษัทตั้งจ่าย' },
  { value: 'agent_request', label: 'ตัวแทนขอเอง' },
] as const

const STATUS_CLASSES: Record<string, string> = {
  transferred: 'bg-emerald-100 text-emerald-800',
  approved: 'bg-slate-200 text-slate-700',
  pending_review: 'bg-amber-100 text-amber-800',
  rejected: 'bg-rose-100 text-rose-800',
  cancelled: 'bg-slate-100 text-slate-500',
}

// ── The filters ─────────────────────────────────────────────────────────
/*
 * An EMPTY set means "every one of them", not "none".
 *
 * The chips render "ทั้งหมด" as its own pressable state rather than as an
 * absence, so the reader is never looking at an unfiltered table while every
 * chip is unlit — which reads as "no results yet" on a page whose whole purpose
 * is to say how much money is in a set.
 */
const statuses = ref<Set<string>>(new Set())
const sources = ref<Set<string>>(new Set())
const dateFrom = ref('')
const dateTo = ref('')
const dateBasis = ref<'transferred_at' | 'created_at'>('transferred_at')
const search = ref('')

/*
 * The two chip rows call NAMED functions rather than one helper handed the ref
 * it should update.
 *
 * `<script setup>` unwraps a ref when the template reads it, so passing
 * `statuses` from the template hands the function the plain Set inside — not
 * the ref — and every reassignment lands on a detached object while the screen
 * silently keeps the old one. The page still looked like it worked: chips lit
 * up, nothing reached the server. Two functions and no argument is duller and
 * cannot do that.
 */
function toggleStatus(value: string): void {
  const next = new Set(statuses.value)
  next.has(value) ? next.delete(value) : next.add(value)
  statuses.value = next
  reload()
}

function toggleSource(value: string): void {
  const next = new Set(sources.value)
  next.has(value) ? next.delete(value) : next.add(value)
  sources.value = next
  reload()
}

function clearStatuses(): void {
  if (statuses.value.size === 0) return

  statuses.value = new Set()
  reload()
}

function clearSources(): void {
  if (sources.value.size === 0) return

  sources.value = new Set()
  reload()
}

function setDateBasis(basis: 'transferred_at' | 'created_at'): void {
  if (dateBasis.value === basis) return

  dateBasis.value = basis
  // Only matters while a window is applied — but reloading unconditionally
  // keeps the rule "the screen always shows what the controls say" true without
  // a special case that would have to be kept correct.
  reload()
}

/**
 * ONE query string, read by the list, the totals and the CSV alike.
 *
 * Three separately-assembled parameter sets is exactly how the figure at the
 * top starts describing a different set from the rows underneath it.
 */
function buildQuery(): string {
  const params = new URLSearchParams()

  for (const value of statuses.value) params.append('statuses[]', value)
  for (const value of sources.value) params.append('sources[]', value)
  if (dateFrom.value) params.set('date_from', dateFrom.value)
  if (dateTo.value) params.set('date_to', dateTo.value)
  if (dateFrom.value || dateTo.value) params.set('date_basis', dateBasis.value)
  if (search.value.trim()) params.set('q', search.value.trim())

  return params.toString()
}

const rows = ref<WithdrawalRequest[]>([])
const summary = ref<ReportSummary | null>(null)
const meta = ref<{ total: number; current_page: number; last_page: number } | null>(null)
const page = ref(1)
const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
/*
 * Tracked separately from errorMessage: the rows can load while the totals fail,
 * and a bar that printed zeros in that case would state that no money matched a
 * filter the table underneath it is listing rows for.
 */
const summaryFailed = ref(false)

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  const query = buildQuery()

  try {
    const res = await api.get<{
      data: WithdrawalRequest[]
      meta?: { total: number; current_page: number; last_page: number }
    }>(activeCompany.scopedPath(`/commission-withdrawals/report?${query}&page=${page.value}`))

    rows.value = res.data
    meta.value = res.meta ?? null
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดรายงานไม่สำเร็จ (${e.status})` : 'โหลดรายงานไม่สำเร็จ'
    rows.value = []
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }

  try {
    const res = await api.get<{ data: ReportSummary }>(
      activeCompany.scopedPath(`/commission-withdrawals/report/summary?${query}`),
    )
    summary.value = res.data
    summaryFailed.value = false
  } catch {
    summary.value = null
    summaryFailed.value = true
  }
}

/** Any filter change puts the reader back on page one — the set is different. */
function reload(): void {
  page.value = 1
  void load()
}

function goToPage(next: number): void {
  if (next < 1 || (meta.value && next > meta.value.last_page)) return

  page.value = next
  void load()
}

onMounted(load)

watch(() => activeCompany.companyId, reload)

const exporting = ref(false)
async function exportCsv(): Promise<void> {
  exporting.value = true
  errorMessage.value = ''
  try {
    // The same query string as the list and the totals — that is the promise.
    await api.download(activeCompany.scopedPath(`/commission-withdrawals/report/export?${buildQuery()}`))
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ส่งออก CSV ไม่สำเร็จ (${e.status})` : 'ส่งออก CSV ไม่สำเร็จ'
  } finally {
    exporting.value = false
  }
}

const anyFilterActive = computed(() =>
  statuses.value.size > 0 || sources.value.size > 0 || Boolean(dateFrom.value || dateTo.value) || search.value.trim() !== '',
)

function clearAll(): void {
  statuses.value = new Set()
  sources.value = new Set()
  dateFrom.value = ''
  dateTo.value = ''
  search.value = ''
  reload()
}

// BR-3 — satang in, baht out. Divide by 100 only here, at the display layer.
function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}
function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' }) : '—'
}

/**
 * The header carries the one figure that is true of the WHOLE page rather than
 * of the current filter — otherwise it would be a fourth place printing the
 * same number as the totals bar three inches below it.
 */
const kpis = computed(() => [
  { label: 'ที่กรองอยู่', value: summary.value ? `${summary.value.count} ใบ` : '—' },
])
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="invoice"
      title="รายงานการจ่าย"
      subtitle="อ่านอย่างเดียว — ทุกใบที่เคยตั้งจ่ายหรือตัวแทนขอเบิก พร้อมสถานะและเลขอ้างอิงการโอน"
      description="กรองแล้วส่งออก CSV ได้ตามที่กรอง · ถ้าต้องการอนุมัติหรือบันทึกการโอน ให้ไปที่หน้า จ่ายค่าแนะนำ"
      :kpis="kpis"
      accent-color="brand"
      storage-key="payout-report"
    />

    <CompanyScopeNotice action="ดูรายงานการจ่าย" />

    <div class="mt-4 flex flex-wrap items-center gap-3">
      <RouterLink
        :to="{ name: 'commission-payouts' }"
        class="h-9 px-4 rounded-xl border border-brand-200 bg-brand-50 text-brand-700 text-[12.5px] font-extrabold inline-flex items-center gap-1.5 hover:bg-brand-100"
        data-test="report-back-to-work"
      >
        <Icon name="money" :size="14" />
        ไปหน้า จ่ายค่าแนะนำ
      </RouterLink>

      <button
        type="button"
        :disabled="exporting"
        class="ml-auto h-9 px-4 rounded-xl bg-brand-700 text-white text-[12.5px] font-extrabold inline-flex items-center gap-1.5 hover:bg-brand-800 disabled:opacity-50"
        data-test="report-export"
        @click="exportCsv"
      >
        <Icon name="download" :size="14" />
        {{ exporting ? 'กำลังส่งออก...' : 'ส่งออก CSV ตามที่กรองอยู่' }}
      </button>
    </div>

    <!-- ═══ THE FILTERS ═══
         Chips, not cards. On the working screen a status is a STEP; here it is
         a search condition — unordered, combinable, and including two that are
         not stages of anything. -->
    <div class="mt-4 p-4 rounded-2xl bg-white border border-slate-200" data-test="report-filters">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-[11.5px] font-extrabold text-slate-400 w-12 shrink-0">สถานะ</span>
        <button
          type="button"
          class="h-8 px-3 rounded-lg text-[12px] font-bold border transition"
          :class="statuses.size === 0 ? 'bg-brand-700 border-brand-700 text-white' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
          data-test="report-status-all"
          @click="clearStatuses"
        >
          ทั้งหมด
        </button>
        <button
          v-for="chip in STATUS_CHIPS"
          :key="chip.value"
          type="button"
          class="h-8 px-3 rounded-lg text-[12px] font-bold border transition"
          :class="statuses.has(chip.value) ? 'bg-brand-700 border-brand-700 text-white' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
          :data-test="`report-status-${chip.value}`"
          @click="toggleStatus(chip.value)"
        >
          {{ chip.label }}
        </button>
      </div>

      <div class="mt-2.5 flex flex-wrap items-center gap-2">
        <span class="text-[11.5px] font-extrabold text-slate-400 w-12 shrink-0">ที่มา</span>
        <button
          type="button"
          class="h-8 px-3 rounded-lg text-[12px] font-bold border transition"
          :class="sources.size === 0 ? 'bg-brand-700 border-brand-700 text-white' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
          data-test="report-source-all"
          @click="clearSources"
        >
          ทั้งหมด
        </button>
        <button
          v-for="chip in SOURCE_CHIPS"
          :key="chip.value"
          type="button"
          class="h-8 px-3 rounded-lg text-[12px] font-bold border transition"
          :class="sources.has(chip.value) ? 'bg-brand-700 border-brand-700 text-white' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
          :data-test="`report-source-${chip.value}`"
          @click="toggleSource(chip.value)"
        >
          {{ chip.label }}
        </button>

        <label class="relative ml-auto">
          <span class="sr-only">ค้นหาชื่อผู้รับ</span>
          <Icon name="search" :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            v-model="search"
            type="search"
            placeholder="ค้นหาชื่อผู้รับ"
            class="h-8 w-52 pl-8 pr-3 rounded-lg border border-slate-200 text-[12.5px] bg-white"
            data-test="report-search"
            @change="reload"
            @keyup.enter="reload"
          />
        </label>
      </div>

      <div class="mt-2.5 pt-2.5 border-t border-slate-100 flex flex-wrap items-end gap-3">
        <DateRangeFilter v-model:date-from="dateFrom" v-model:date-to="dateTo" :years-back="3" :years-forward="0" />

        <!--
          WHICH DATE. A payout has two and they answer different questions — a
          request raised in August and transferred in September is in both
          Augusts and both Septembers depending on what is being asked.
        -->
        <div class="flex items-center gap-1.5">
          <span class="text-[11.5px] font-extrabold text-slate-400">นับจาก</span>
          <div class="inline-flex p-[3px] bg-slate-100 rounded-xl">
            <button
              v-for="basis in ([
                { key: 'transferred_at', label: 'วันที่โอน' },
                { key: 'created_at', label: 'วันที่ขอ' },
              ] as const)"
              :key="basis.key"
              type="button"
              class="px-3 py-1 rounded-[0.6rem] text-[12px] font-bold transition"
              :class="dateBasis === basis.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
              :data-test="`report-basis-${basis.key}`"
              @click="setDateBasis(basis.key)"
            >
              {{ basis.label }}
            </button>
          </div>
        </div>

        <button
          type="button"
          class="h-9 px-4 rounded-xl bg-brand-600 text-white font-bold text-sm hover:bg-brand-700"
          data-test="report-apply"
          @click="reload"
        >
          ใช้ตัวกรอง
        </button>
        <button
          v-if="anyFilterActive"
          type="button"
          class="h-9 px-4 rounded-xl border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50"
          data-test="report-clear"
          @click="clearAll"
        >
          ล้างตัวกรองทั้งหมด
        </button>
      </div>

      <p v-if="dateFrom || dateTo" class="mt-2 text-[11.5px] text-slate-400" data-test="report-basis-note">
        ช่วงวันที่นี้นับจาก<b class="text-slate-600">{{ dateBasis === 'transferred_at' ? 'วันที่โอนจริง' : 'วันที่ยื่นคำขอ/ตั้งจ่าย' }}</b>
        <template v-if="dateBasis === 'transferred_at'"> — ใบที่ยังไม่ได้โอนจะไม่อยู่ในช่วงนี้</template>
      </p>
    </div>

    <!-- ═══ THE TOTALS — OF THE FILTERED SET, AND SAID SO ═══ -->
    <div
      v-if="summary && !summaryFailed"
      class="mt-4 rounded-2xl bg-slate-900 px-5 py-4 flex flex-wrap items-center gap-y-3"
      data-test="report-totals"
    >
      <div class="pr-6 sm:border-r border-white/15">
        <p class="text-[11px] font-bold text-slate-400">ตามตัวกรองนี้</p>
        <p class="mt-0.5 text-[22px] font-extrabold text-white tabular-nums" data-test="report-total-satang">
          {{ formatSatang(summary.total_satang) }}
        </p>
      </div>
      <div class="px-6 sm:border-r border-white/15">
        <p class="text-[11px] font-bold text-slate-400">โอนแล้ว</p>
        <p class="mt-0.5 text-base font-extrabold text-emerald-300 tabular-nums">
          {{ formatSatang(summary.transferred_satang) }} · {{ summary.transferred_count }} ใบ
        </p>
      </div>
      <div class="px-6 sm:border-r border-white/15">
        <p class="text-[11px] font-bold text-slate-400">ยังไม่โอน</p>
        <p class="mt-0.5 text-base font-extrabold text-amber-300 tabular-nums">
          {{ formatSatang(summary.outstanding_satang) }} · {{ summary.outstanding_count }} ใบ
        </p>
      </div>
      <div class="pl-6">
        <p class="text-[11px] font-bold text-slate-400">ผู้รับ</p>
        <p class="mt-0.5 text-base font-extrabold text-white tabular-nums">{{ summary.payee_count }} ราย</p>
      </div>
      <!--
        Said out loud because it is the promise this page makes: the figure, the
        rows and the file are one set. "ยังไม่โอน" counts only รอตรวจสอบ and
        รอโอน — money that was refused or withdrawn is in the list but is not
        going anywhere, and counting it would state a liability that does not
        exist.
      -->
      <p class="ml-auto text-[11.5px] text-slate-500 max-w-[260px] sm:text-right">
        ไฟล์ CSV จะได้ {{ summary.count }} แถวนี้ · “ยังไม่โอน” นับเฉพาะรอตรวจสอบและรอโอน
      </p>
    </div>
    <p v-else-if="summaryFailed" class="mt-4 text-[12.5px] font-bold text-rose-600" data-test="report-totals-error">
      อ่านยอดรวมของตัวกรองนี้ไม่สำเร็จ — รายการด้านล่างยังแสดงตามปกติ
    </p>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700" data-test="report-error">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="5" class="mt-4" />
    <template v-else>
      <EmptyState
        v-if="!rows.length"
        icon="invoice"
        :title="anyFilterActive ? 'ไม่มีใบที่ตรงกับตัวกรองนี้' : 'ยังไม่มีการจ่ายค่าแนะนำ'"
        :message="anyFilterActive ? 'ลองล้างตัวกรองแล้วดูใหม่' : 'รายการจะเข้ามาเมื่อคุณกดตั้งจ่าย หรือเมื่อตัวแทนกดขอเบิกเอง'"
        class="mt-4"
        data-test="report-empty"
      />

      <div v-else class="mt-4 bg-white border border-slate-200 rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-slate-50 text-left text-[11px] font-extrabold text-slate-500 border-b border-slate-200">
                <th class="py-2.5 px-3 whitespace-nowrap">วันที่ขอ/ตั้งจ่าย</th>
                <th class="py-2.5 px-3 whitespace-nowrap">วันที่โอน</th>
                <th class="py-2.5 px-3">ผู้รับ</th>
                <th class="py-2.5 px-3">ที่มา</th>
                <th class="py-2.5 px-3 text-right whitespace-nowrap">ยอด</th>
                <th class="py-2.5 px-3 whitespace-nowrap">รายการ</th>
                <th class="py-2.5 px-3 whitespace-nowrap">บัญชีรับเงิน</th>
                <th class="py-2.5 px-3">สถานะ</th>
                <th class="py-2.5 px-3 whitespace-nowrap">เลขอ้างอิงการโอน</th>
                <th class="py-2.5 px-3 whitespace-nowrap">ผู้อนุมัติ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.id" class="border-b border-slate-100 last:border-0" :data-test="`report-row-${r.id}`">
                <td class="py-2.5 px-3 text-slate-500 tabular-nums whitespace-nowrap">{{ formatDate(r.created_at) }}</td>
                <td
                  class="py-2.5 px-3 tabular-nums whitespace-nowrap"
                  :class="r.transferred_at ? 'text-emerald-700 font-bold' : 'text-slate-300'"
                >
                  {{ formatDate(r.transferred_at) }}
                </td>
                <td class="py-2.5 px-3 font-bold text-slate-900">{{ r.agent_name ?? '—' }}</td>
                <td class="py-2.5 px-3">
                  <span
                    class="px-2 py-0.5 rounded-full text-[10.5px] font-extrabold whitespace-nowrap"
                    :class="r.source === 'company_payout' ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-600'"
                  >
                    {{ r.source_label }}
                  </span>
                </td>
                <td class="py-2.5 px-3 text-right font-extrabold tabular-nums whitespace-nowrap">
                  {{ formatSatang(r.amount_satang) }}
                </td>
                <td class="py-2.5 px-3 text-slate-500">{{ r.item_count ?? '—' }}</td>
                <td class="py-2.5 px-3 text-slate-600 whitespace-nowrap">
                  {{ r.bank_name || '—' }} {{ r.bank_account_number_masked || '' }}
                </td>
                <td class="py-2.5 px-3">
                  <span
                    class="px-2 py-0.5 rounded-full text-[10.5px] font-extrabold whitespace-nowrap"
                    :class="STATUS_CLASSES[r.status] ?? 'bg-slate-100 text-slate-600'"
                  >
                    {{ r.status_label }}
                  </span>
                  <span v-if="r.rejection_reason" class="block text-[10.5px] text-rose-600 font-bold mt-0.5 max-w-[200px]">
                    {{ r.rejection_reason }}
                  </span>
                </td>
                <td class="py-2.5 px-3 text-slate-600 tabular-nums">{{ r.transfer_reference || '—' }}</td>
                <td class="py-2.5 px-3 text-slate-500 whitespace-nowrap">{{ r.decided_by || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="meta && meta.last_page > 1" class="px-4 py-3 bg-slate-50 flex items-center gap-2">
          <span class="text-[11.5px] text-slate-400">
            แสดง {{ rows.length }} จาก {{ meta.total }} ใบ · หน้า {{ meta.current_page }}/{{ meta.last_page }}
          </span>
          <div class="ml-auto flex gap-2">
            <button
              type="button"
              class="h-8 px-3 rounded-lg border border-slate-200 bg-white text-[12px] font-bold text-slate-600 disabled:opacity-40"
              :disabled="meta.current_page <= 1"
              data-test="report-prev"
              @click="goToPage(page - 1)"
            >
              ก่อนหน้า
            </button>
            <button
              type="button"
              class="h-8 px-3 rounded-lg border border-slate-200 bg-white text-[12px] font-bold text-slate-600 disabled:opacity-40"
              :disabled="meta.current_page >= meta.last_page"
              data-test="report-next"
              @click="goToPage(page + 1)"
            >
              ถัดไป
            </button>
          </div>
        </div>
      </div>

      <p class="mt-3 text-[11.5px] text-slate-400">
        บัญชีรับเงินที่แสดงคือบัญชีที่<b class="text-slate-500">บันทึกไว้ตอนยื่นใบนั้น</b>
        ไม่ใช่บัญชีปัจจุบันของตัวแทน — และแสดงเลขท้าย 4 ตัวเท่านั้น ทั้งบนหน้าจอและในไฟล์ CSV
      </p>
    </template>
  </main>
</template>
