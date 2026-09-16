<script setup lang="ts">
/**
 * ตั้งจ่าย — who is owed referral commission, and the one press that queues it.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * 2026-09-16 — THE THREE GROUPS (ร่าง 1: the money band IS the switcher).
 *
 * Owner: "สถานะการแสดงผล กับการตั้งจ่าย กับการรอจ่าย มันควรแยกกัน ดูง่าย และ
 * ตัวที่พบปัญหา มีค่าคอมแต่ตั้งจ่ายไม่ได้ เช่นยังไม่กรอกเลขที่บัญชี ควรแยกเป็น
 * 3 กลุ่ม" — then, after two drafts: "ทำร่าง 1 เลย".
 *
 * Until now one list held four different situations under one heading, and the
 * only thing telling them apart was a line of small text on the row:
 *
 *   · owed money, bank details complete → the actual work
 *   · owed money, already raised, waiting at รอบจ่าย → nothing to do HERE
 *   · owed money, no bank account → work, but a different kind of work
 *   · paid → history
 *
 * The ค้างจ่าย / จ่ายแล้ว / ทั้งหมด tab strip that used to sit at the top split
 * that list on the WRONG axis: it separated history from work, and left the
 * three kinds of work piled together. It is gone.
 *
 * ── HOW ร่าง 1 IS PUT TOGETHER ──
 *
 * The 3-step money band does two jobs at once, deliberately: it says where the
 * company's money is, AND pressing a step opens that group. The step in view
 * carries a "กำลังดู" label and a heavy border, and the list underneath is that
 * step — so the number and the names are never two separate ideas.
 *
 * ── WHY "ติดปัญหา" IS NOT A FOURTH STEP ──
 *
 * Because it is not a step. Someone with no bank account has not entered the
 * conveyor at all — that is WHY they are stuck — so putting them on it as step
 * zero would describe a flow that does not exist. They get the amber bar ABOVE
 * the band, which is also the only element on this screen that has to be seen
 * without being looked for. Pressing ดูรายชื่อ opens them as a group like any
 * other; the band then shows no "กำลังดู", which is honest — they are not at
 * any step.
 *
 * ── TWO REQUESTS, CACHED, NOT ONE UNFILTERED ONE ──
 *
 * Steps 1 and 2 and the amber bar all come out of ONE payment_status=pending
 * request, partitioned here. Step 3 is a second request, fired the first time
 * somebody opens it and then kept. Dropping the filter entirely and slicing one
 * unfiltered response would have been less code and would have pulled every
 * agent ever settled into the payload of a screen whose job is "who do I pay
 * today" — and, until that request landed, step 3 would have had to print a
 * number nobody had measured. It prints "กดเพื่อดู" instead (§3.7/F-10: an
 * unmeasured bucket is never rendered as a figure).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * BR-3: amounts come back as integer satang; divided by 100 only here, at the
 * display layer.
 *
 * The drill-down ("ดูรายละเอียด", TASK-046/047) is unchanged: which products
 * were sold, which clients bought, and the stored calculation snapshot per
 * entry — never a derived "rate × base price", because the base sale price is
 * not on commission_ledger and back-deriving one would be an invented number.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import DateRangeFilter from '@/design-system/components/DateRangeFilter.vue'
import { RouterLink, useRoute } from 'vue-router'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

const route = useRoute()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()

interface AgentSummaryItem {
  agent_id: number
  agent_name: string | null
  /**
   * TASK-179 §3.7 (F-10) — NULL means "this bucket was excluded by the
   * payment-status filter, so nobody measured it". It is NOT zero.
   *
   * The old contract forced the excluded bucket to literal 0, so filtering
   * by "จ่ายแล้ว" rendered "รอจ่ายรวม 0 บาท" — visually identical to "we owe
   * our agents nothing", which is a statement about money nobody computed.
   *
   * A `?? 0` anywhere below re-creates that defect. Render "ไม่ได้แสดง"
   * instead — see formatSatangOrUnmeasured().
   */
  total_paid_satang: number | null
  total_pending_satang: number | null
  entry_count: number
  bank_name: string | null
  bank_account_number: string | null
  bank_account_holder_name: string | null
  /**
   * 2026-09-15 — this payee is the COMPANY, not one of its agents.
   *
   * A company can hold a seat at the top of its own hierarchy and be paid a
   * leader's override (ขั้นตอนที่ 4.2). It is a payable row like any other
   * now; the flag changes how it is LABELLED and where its bank details are
   * edited, not whether it can be selected.
   */
  is_company_share?: boolean
  /**
   * Can this payee actually be paid right now?
   *
   * The SERVER's answer (User::hasCompletePayoutDetails), not a rule
   * re-derived here from the three bank fields — an agent also needs an
   * identity document, the company seat deliberately does not, and a copy of
   * that rule in the browser would start disagreeing with the one that
   * refuses the press.
   *
   * This is also what sorts a payee into กลุ่มติดปัญหา rather than กลุ่ม
   * ตั้งจ่ายได้เลย, so the grouping and the refusal can never disagree.
   */
  payout_details_complete?: boolean
  /*
   * ═══ 2026-09-16 — WHAT IS OWED vs WHAT CAN STILL BE RAISED ═══
   *
   * Owner: "ผมกดยืนยันการจ่ายไปแล้ว แต่ปัญหาคือหน้าจอ Ui ยังขึ้นค้างจ่ายอยู่".
   *
   * แนวทาง C does not settle the ledger when a payout is raised — the money
   * has not moved — so total_pending_satang above is UNCHANGED after a
   * successful ตั้งจ่าย, and this screen showed the row exactly as before:
   * same amount, still ticked, still selectable. Pressing again was then
   * refused forever, because the server compares against a different number
   * (availableSatang, which subtracts what an open payout reserved). The
   * screen was offering an action that could only fail.
   *
   * `reserved_satang` is what is already in flight; `available_satang` is what
   * a NEW payout may be raised for, and is the figure the server checks the
   * press against. Both are the server's own arithmetic — deriving them here
   * would be the same two-numbers-one-name defect in a new place.
   *
   * The three groups are these two fields read as a question each:
   *   available > 0 and payable   → ตั้งจ่ายได้เลย
   *   reserved  > 0               → รอฝ่ายบัญชีโอน
   *   available > 0 but not payable → ติดปัญหา
   */
  reserved_satang?: number
  available_satang?: number | null
  avatar_url: string | null
  cert_tier: { id: number; key: string; name: string } | null
}

/**
 * 2026-09-16 — WHAT THE รายรายการ PAGE KNEW, MOVED IN HERE.
 *
 * Owner: "ที่คุณออกแบบใหม่มา 2 หน้า … หน้านี้ยังจำเป็นไหม ผมว่ามันทับซ้อน".
 *
 * It was, once these two fields moved. รายรายการ was the only screen in the
 * app that said WHY a person is paid for a sale they did not make — the payout
 * type, and whose sale produced an override — and this drill-down, which reads
 * the very same endpoint, was throwing both away.
 *
 * That is the same defect TASK-215 fixed on the old screen in August: one sale
 * writes three rows (the seller's own rate, the leader's override, a campaign
 * bonus) and without these fields they render as three identical lines with
 * three different amounts, which reads as "this sale paid the same agent three
 * times".
 */
type EarnedVia =
  | 'direct'
  | 'renewal'
  | 'override'
  | 'binary_match'
  | 'matrix_override'
  | 'stairstep_override'
  | 'generation_override'
  | 'promotion_bonus'

/**
 * Thai label + colour per payout type. Deliberately NOT one neutral chip for
 * everything: "ค่าคอมของตัวเอง" and "ค่าคอมหัวหน้าทีม" land in different
 * people's pockets for different reasons, and being able to tell them apart at
 * a glance is the whole point.
 */
const earnedViaLabels: Record<EarnedVia, { label: string; cls: string }> = {
  direct: { label: 'ค่าคอมของตัวเอง', cls: 'bg-brand-50 text-brand-700' },
  renewal: { label: 'ค่าคอมปีต่ออายุ', cls: 'bg-sky-50 text-sky-700' },
  override: { label: 'ค่าคอมหัวหน้าทีม', cls: 'bg-amber-100 text-amber-800' },
  binary_match: { label: 'Binary (จับคู่ขา)', cls: 'bg-violet-50 text-violet-700' },
  matrix_override: { label: 'Matrix (ชั้นล่าง)', cls: 'bg-violet-50 text-violet-700' },
  stairstep_override: { label: 'อันดับ (ส่วนต่าง)', cls: 'bg-violet-50 text-violet-700' },
  generation_override: { label: 'Generation', cls: 'bg-violet-50 text-violet-700' },
  promotion_bonus: { label: 'โบนัสแคมเปญ', cls: 'bg-emerald-50 text-emerald-700' },
}

function earnedViaBadge(e: LedgerItem): { label: string; cls: string } {
  // An unknown or absent value must read as unknown, never silently as
  // "direct" — mislabelling a payout type is the exact failure these badges
  // exist to remove.
  return e.earned_via
    ? (earnedViaLabels[e.earned_via] ?? { label: e.earned_via, cls: 'bg-slate-100 text-slate-600' })
    : { label: 'ไม่ระบุประเภท', cls: 'bg-slate-100 text-slate-500' }
}

interface LedgerItem {
  id: number
  referral: { id: number; client: { id: number; name: string } | null } | null
  agent: { id: number; name: string } | null
  cert_tier_at_time: { id: number; key: string; name: string } | null
  product: { id: number; name: string } | null
  rate_type_applied: 'percentage' | 'fixed_satang'
  rate_applied: number
  amount_satang: number
  sale_price_satang_at_time: number | null
  applied_price_promotion: { id: number; note: string | null; discounted_price_satang: number } | null
  payment_status: 'pending' | 'paid'
  earned_via: EarnedVia | null
  /** Whose sale produced this override — null on a row the agent earned themselves. */
  override_source_agent: { id: number; name: string } | null
  is_company_share?: boolean
  paid_at: string | null
  created_at: string
}

interface AgentDetail {
  id: number
  name: string
  email: string | null
  phone: string | null
  avatar_url: string | null
  created_at: string
  cert_tier: { id: number; key: string; name: string } | null
}

const CERT_TIER_COLORS: Record<string, { bg: string; text: string }> = {
  basic: { bg: 'bg-slate-400', text: 'text-white' },
  intermediate: { bg: 'bg-brand-500', text: 'text-white' },
  high: { bg: 'bg-amber-500', text: 'text-white' },
}
function tierColor(tierKey: string | undefined): { bg: string; text: string } {
  return (tierKey && CERT_TIER_COLORS[tierKey]) || { bg: 'bg-slate-400', text: 'text-white' }
}
function initial(name: string | null | undefined): string {
  return (name ?? '?').trim().charAt(0).toUpperCase() || '?'
}

// ── The three groups (plus history) ─────────────────────────────────────
/**
 * `blocked` is a group but NOT a step on the band — see the header comment.
 */
type Group = 'payable' | 'reserved' | 'blocked' | 'paid'

/**
 * 2026-09-16 — WHERE A LINK CAN ASK FOR A DIFFERENT GROUP.
 *
 * รายรายการ was folded into this screen, and two links in the wild named its
 * tab: the dashboard's "ค่าคอมที่จ่ายให้ตัวแทนแล้ว" card, and the old
 * /commission?tab= redirect. Both mean "show me what has been PAID", which is
 * a different set from where this screen opens — so they are honoured rather
 * than silently landing somebody on the payout queue.
 *
 * `?view=all` predates the groups and no longer names one. It resolves to the
 * work group rather than being ignored, because whoever wrote that link wanted
 * a starting point, not a specific slice of history.
 *
 * Read ONCE at setup, never watched: this is a starting point somebody handed
 * over, and re-applying it would fight the next step the reader presses.
 */
function groupFromQuery(): Group {
  const asked = route.query.view ?? route.query.tab

  return asked === 'paid' ? 'paid' : 'payable'
}

const group = ref<Group>(groupFromQuery())

const GROUP_LABELS: Record<Group, string> = {
  payable: 'ตั้งจ่ายได้เลย',
  reserved: 'รอฝ่ายบัญชีโอน',
  blocked: 'ติดปัญหา — ตั้งจ่ายไม่ได้',
  paid: 'โอนแล้ว',
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

/*
 * Two result sets, each from its own request, each kept once fetched.
 *
 * They are NOT interchangeable and must never be concatenated: a row in
 * `paidRows` came back from payment_status=paid, so its pending bucket is null
 * by contract (F-10) — mixing the two would put unmeasured rows into sums that
 * are supposed to be measured.
 */
const pendingRows = ref<AgentSummaryItem[]>([])
const paidRows = ref<AgentSummaryItem[]>([])
const pendingLoaded = ref(false)
const paidLoaded = ref(false)

const filters = ref({
  date_from: '',
  date_to: '',
})

/** Free-text narrowing of what is already on screen — never a server query. */
const search = ref('')

/**
 * The date range starts folded away.
 *
 * Six selects and eight quick chips used to occupy the top third of the page
 * before a single payee appeared, for a control that is touched once a month.
 * Folded, not removed — the button above still says "กรองอยู่" when a range is
 * applied, so a filtered screen can never look like an unfiltered one.
 */
const showDates = ref(false)

/**
 * Dates only.
 *
 * payment_status used to live in `filters` and be user-facing. It is now an
 * implementation detail of which of the two result sets is being fetched, so
 * it is passed explicitly at the call site rather than carried in shared state
 * where the drill-down would silently inherit it.
 */
function buildQuery(): string {
  const params = new URLSearchParams()
  if (filters.value.date_from) params.set('date_from', filters.value.date_from)
  if (filters.value.date_to) params.set('date_to', filters.value.date_to)
  return params.toString()
}

async function fetchSummary(status: 'pending' | 'paid'): Promise<AgentSummaryItem[]> {
  const params = new URLSearchParams(buildQuery())
  params.set('payment_status', status)

  const res = await api.get<{ data: AgentSummaryItem[]; computed_at: string }>(
    activeCompany.scopedPath(`/agent-commission-summary?${params.toString()}`),
  )

  return res.data
}

/**
 * Fetch whatever the screen currently needs, and nothing it already has.
 *
 * `pending` is ALWAYS needed, even while the reader is looking at history: the
 * amber bar and the first two steps of the band are drawn from it, and they
 * stay on screen in every group. `paid` is fetched the first time step 3 is
 * opened and then kept — so stepping back and forth does not re-request, and
 * step 3 never loses the figure it just showed.
 */
async function ensureLoaded(force = false): Promise<void> {
  const needPending = force || !pendingLoaded.value
  const needPaid = (force && paidLoaded.value) || (group.value === 'paid' && !paidLoaded.value)

  if (!needPending && !needPaid) return

  loading.value = true
  errorMessage.value = ''
  try {
    if (needPending) {
      pendingRows.value = await fetchSummary('pending')
      pendingLoaded.value = true
    }
    if (needPaid) {
      paidRows.value = await fetchSummary('paid')
      paidLoaded.value = true
    }
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

/** A write happened, or the window changed: everything on screen is stale. */
async function reloadAll(): Promise<void> {
  await ensureLoaded(true)
}

onMounted(() => ensureLoaded())

/**
 * Every filter change drops the selection.
 *
 * A tick means "pay this person this amount". Change the window and the
 * amounts on screen change with it, so a selection carried across would be a
 * set of agreements to figures the admin is no longer looking at.
 */
function applyFilters() {
  selected.value = new Set()
  reloadAll()
}

/**
 * Switching groups drops the selection for the same reason, even though the
 * figures do not move: a tick made in กลุ่มตั้งจ่ายได้เลย and then left behind
 * while the reader browses history would be agreed to at a confirmation opened
 * from somewhere else entirely.
 */
function openGroup(next: Group): void {
  if (group.value === next) return

  group.value = next
  selected.value = new Set()
  batchError.value = ''
  confirming.value = false
  ensureLoaded()
}

/** Puts the checkboxes back — see the note under the filter bar. */
function clearDateFilter(): void {
  filters.value.date_from = ''
  filters.value.date_to = ''
  applyFilters()
}

/**
 * Hidden rather than disabled under a date filter: a payout settles an agent's
 * WHOLE outstanding balance, and under a date filter the figure on each row is
 * a slice of it. Rather than pay a number that does not match the one on
 * screen, the checkboxes go and the line under the toolbar says why.
 */
const dateFilterActive = computed(() => Boolean(filters.value.date_from || filters.value.date_to))

/**
 * Ticking exists in ONE group.
 *
 * That is the point of the split: a tick always means "pay this person this
 * amount", and there is nowhere on this screen where it could mean anything
 * else. Nothing in กลุ่มรอฝ่ายบัญชีโอน can be raised (it already has been),
 * nothing in กลุ่มติดปัญหา can be raised (the server would refuse), and
 * โอนแล้ว is history.
 */
const canSelect = computed(() => !dateFilterActive.value && group.value === 'payable')

/** The amount a NEW payout for this payee would be for. */
function availableOf(s: AgentSummaryItem): number | null {
  /*
   * Falls back to the pending figure only when the server did not send the
   * field at all — an older API during a rolling deploy. `?? 0` would be
   * wrong in the other direction (it would empty the work group); this keeps
   * the screen working and merely un-improved.
   */
  return s.available_satang === undefined ? s.total_pending_satang : s.available_satang
}

/** The server would accept a NEW payout for this payee right now. */
function isPayable(s: AgentSummaryItem): boolean {
  return (availableOf(s) ?? 0) > 0 && s.payout_details_complete === true
}

/** Owed money that could be raised, if only the payee's details were complete. */
function isBlocked(s: AgentSummaryItem): boolean {
  return (availableOf(s) ?? 0) > 0 && s.payout_details_complete !== true
}

/** Something is already in flight for this payee, waiting at รอบจ่าย. */
function isReserved(s: AgentSummaryItem): boolean {
  return (s.reserved_satang ?? 0) > 0
}

/*
 * ── THE GROUPS OVERLAP ON PURPOSE ──
 *
 * An agent with 1,500 raised and 500 still available belongs in BOTH
 * ตั้งจ่ายได้เลย and รอฝ่ายบัญชีโอน, because both sentences are true of them and
 * each group answers a different question ("who can I pay" / "what is accounting
 * holding"). Forcing a partition would have to drop them out of one of the two,
 * and either choice makes a true list incomplete. The row itself says which part
 * of its balance is which.
 */
const payableRows = computed(() => pendingRows.value.filter(isPayable))
const blockedRows = computed(() => pendingRows.value.filter(isBlocked))
const reservedRows = computed(() => pendingRows.value.filter(isReserved))

const groupRows = computed<AgentSummaryItem[]>(() => {
  switch (group.value) {
    case 'paid':
      return paidRows.value
    case 'blocked':
      return blockedRows.value
    case 'reserved':
      return reservedRows.value
    default:
      return payableRows.value
  }
})

const visibleRows = computed(() => {
  const needle = search.value.trim().toLowerCase()

  if (needle === '') return groupRows.value

  return groupRows.value.filter((s) => (s.agent_name ?? '').toLowerCase().includes(needle))
})

/**
 * TASK-179 §3.7 (F-10) — the wording for a bucket that was never measured.
 *
 * Deliberately NOT a number and NOT "0 บาท": the point of the backend
 * returning null is that the UI must be unable to present an unmeasured
 * bucket as a monetary fact.
 */
const UNMEASURED_LABEL = 'ไม่ได้แสดง (ถูกกรองออก)'
function formatSatangOrUnmeasured(satang: number | null): string {
  return satang === null ? UNMEASURED_LABEL : formatSatang(satang)
}

/**
 * Sum a bucket across a set of rows — or report that it was not measured.
 *
 * Returns null the moment ANY row's bucket is null, rather than skipping
 * those rows: the filter excludes the bucket for the whole request, so a
 * partial sum would be a company-wide total assembled from a subset nobody
 * defined. (`.reduce()` with `?? 0` here is precisely the F-10 shape.)
 */
function sumBucket(rows: AgentSummaryItem[], pick: (s: AgentSummaryItem) => number | null): number | null {
  let total = 0
  for (const s of rows) {
    const value = pick(s)
    if (value === null) return null
    total += value
  }
  return total
}

/**
 * Was the pending bucket measured at all?
 *
 * Checked on the WHOLE result set rather than on the filtered group, because a
 * group filtered by `available > 0` is empty exactly when the bucket is null —
 * and an empty group summed with `.reduce()` yields 0, which is the F-10 defect
 * wearing a different hat: "nobody measured this" rendered as "0 บาท".
 */
const pendingMeasured = computed(() => pendingRows.value.every((s) => s.total_pending_satang !== null))

// ── The band: three steps, each a sum of money ──────────────────────────
const payableSatang = computed(() =>
  pendingMeasured.value ? payableRows.value.reduce((t, s) => t + (availableOf(s) ?? 0), 0) : null,
)
const blockedSatang = computed(() =>
  pendingMeasured.value ? blockedRows.value.reduce((t, s) => t + (availableOf(s) ?? 0), 0) : null,
)
/*
 * reserved_satang is never null: it is counted off the withdrawal items, which
 * the payment-status filter does not touch. It is the one figure on this screen
 * that means the same thing in every group.
 */
const reservedSatang = computed(() => pendingRows.value.reduce((t, s) => t + (s.reserved_satang ?? 0), 0))
/** null until step 3 has been opened once — see "TWO REQUESTS, CACHED". */
const paidSatang = computed(() => (paidLoaded.value ? sumBucket(paidRows.value, (s) => s.total_paid_satang) : null))

const steps = computed(() => [
  {
    key: 'payable' as const,
    n: 1,
    label: 'ตั้งจ่ายได้เลย',
    satang: payableSatang.value,
    count: payableRows.value.length,
    note: 'พร้อมตั้งจ่ายรอบนี้',
  },
  {
    key: 'reserved' as const,
    n: 2,
    label: 'รอฝ่ายบัญชีโอน',
    satang: reservedSatang.value,
    count: reservedRows.value.length,
    note: 'ตั้งจ่ายแล้ว งานอยู่ที่หน้ารอบจ่าย',
  },
  {
    key: 'paid' as const,
    n: 3,
    label: 'โอนแล้ว',
    // Not a figure until the request that measures it has run.
    satang: paidSatang.value,
    count: paidLoaded.value ? paidRows.value.length : null,
    note: paidLoaded.value ? 'บันทึกว่าโอนแล้วเรียบร้อย' : 'กดเพื่อดูประวัติการโอน',
  },
])

/**
 * Why this row cannot be ticked — or '' when it can.
 *
 * Said on the row itself rather than after a failed press: the reasons an admin
 * can act on are both fixable from this screen, and finding them out from a 422
 * after ticking eight people is the expensive way to learn either.
 *
 * Deliberately NOT gated on `canSelect`: กลุ่มติดปัญหา and กลุ่มรอฝ่ายบัญชีโอน
 * are lists where nothing is tickable, and they are precisely the two lists
 * where the reader came to find out why.
 */
function blockedReason(s: AgentSummaryItem): string {
  if (group.value === 'paid') return ''
  if (s.total_pending_satang === null) return ''

  if (isBlocked(s)) {
    return s.is_company_share === true
      ? 'ยังไม่ได้กรอกบัญชีรับเงินของบริษัท'
      : 'ยังกรอกบัญชีธนาคารหรือเอกสารยืนยันตัวตนไม่ครบ'
  }

  /*
   * The case the owner hit. The row still shows money owed — correctly, the
   * ledger is untouched until the transfer is recorded — but all of it is
   * already in a payout waiting at รอบจ่าย, so there is nothing left to raise.
   * Saying "ไม่มียอดค้าง" here would contradict the amount printed beside it.
   */
  if ((availableOf(s) ?? 0) <= 0) {
    return isReserved(s) ? 'ตั้งจ่ายไปแล้ว รอโอนอยู่ที่ รอบจ่าย' : 'ไม่มียอดค้าง'
  }

  return ''
}

// ── The selection ───────────────────────────────────────────────────────
const selected = ref<Set<number>>(new Set())

function isSelected(id: number): boolean {
  return selected.value.has(id)
}

/** Owed money, the server would accept a payout, and this screen is offering one. */
function selectable(s: AgentSummaryItem): boolean {
  return canSelect.value && isPayable(s)
}

function toggleRow(s: AgentSummaryItem): void {
  if (!selectable(s)) return

  // A NEW Set, not .add() on the existing one: Vue tracks the ref's value,
  // and mutating the same object in place leaves every computed below it
  // reading a stale answer.
  const next = new Set(selected.value)
  next.has(s.agent_id) ? next.delete(s.agent_id) : next.add(s.agent_id)
  selected.value = next
  batchError.value = ''
}

const selectableRows = computed(() => visibleRows.value.filter(selectable))
const allSelected = computed(() =>
  selectableRows.value.length > 0 && selectableRows.value.every((s) => selected.value.has(s.agent_id)),
)

function toggleAll(): void {
  selected.value = allSelected.value
    ? new Set()
    : new Set(selectableRows.value.map((s) => s.agent_id))
  batchError.value = ''
}

const selectedRows = computed(() =>
  // Read back off the CURRENT rows rather than kept as objects when ticked:
  // a reload between the tick and the press must not pay a figure that is no
  // longer on screen.
  pendingRows.value.filter((s) => selected.value.has(s.agent_id) && selectable(s)),
)

const selectedTotalSatang = computed(() =>
  // The AVAILABLE figure, not what is owed: it is what the press will actually
  // raise, and what the server will check it against.
  selectedRows.value.reduce((sum, s) => sum + (availableOf(s) ?? 0), 0),
)

// ── The press ───────────────────────────────────────────────────────────
const confirming = ref(false)
const paying = ref(false)
const batchError = ref('')
const batchDone = ref<{ count: number; satang: number } | null>(null)

function askToPay(): void {
  batchError.value = ''
  batchDone.value = null
  confirming.value = true
}

/**
 * 2026-09-16 — "ตั้งจ่ายคนนี้", the per-row shortcut (owner: ข้อ 3).
 *
 * ── WHY THIS IS NOT THE SECOND WAY TO DO ONE THING ──
 *
 * A per-row payout button existed before แบบ C and was deliberately removed:
 * two controls on one row, one collecting a selection and one writing
 * immediately, is the duplication this screen has been consolidated three
 * times to get rid of.
 *
 * This is not that button. It WRITES NOTHING. It replaces the selection with
 * this one person and opens the same confirmation the batch press opens —
 * same endpoint, same payload shape, same audit trail. There is still exactly
 * one way to raise a payout; this is a shortcut into it for the case that is
 * actually common here, which is paying one person.
 *
 * Replaces rather than adds, on purpose: pressing "ตั้งจ่ายคนนี้" on a row
 * while three others are ticked has to mean what it says, not "and also those
 * three" — the confirmation would name a total the presser never chose.
 */
function askToPayOne(s: AgentSummaryItem): void {
  if (!selectable(s)) return

  selected.value = new Set([s.agent_id])
  askToPay()
}

async function paySelected(): Promise<void> {
  if (paying.value || selectedRows.value.length === 0) return

  paying.value = true
  batchError.value = ''
  const attempted = selectedRows.value.length
  const attemptedSatang = selectedTotalSatang.value

  try {
    await api.post('/commission-withdrawals/payout-batch', {
      payees: selectedRows.value.map((s) => ({
        agent_id: s.agent_id,
        /*
         * The AVAILABLE balance, which is what
         * CommissionWithdrawalService::availableSatang() compares it against.
         *
         * This sent total_pending_satang until 2026-09-16, and the two are the
         * same number only until the first payout is raised. After that every
         * press was refused with "0.00 ไม่ตรงกับ 1,046.50" — the screen and the
         * server calling two different figures by the same name.
         */
        expected_total_satang: availableOf(s),
      })),
    })

    confirming.value = false
    selected.value = new Set()
    batchDone.value = { count: attempted, satang: attemptedSatang }
    /*
     * Both result sets, not just the one on screen: what was just raised moves
     * money from step 1 to step 2 of the band, and the band is visible from
     * every group. A partial reload would leave the number the reader is
     * looking at disagreeing with the list they just acted on.
     */
    await reloadAll()
  } catch (e) {
    // The server's own sentence: for a stale total it names both figures and
    // says what to do, which no generic copy here could replace.
    batchError.value = e instanceof ApiError ? e.message : 'ตั้งจ่ายไม่สำเร็จ'
  } finally {
    paying.value = false
  }
}

// ── Bank account (TASK-045/047) ─────────────────────────────────────────
// Prefills from the agent's REAL current number, and the panel stays open
// after saving showing what was recorded.
const bankEditId = ref<number | null>(null)
const bankForm = ref({ bank_name: '', bank_account_number: '', bank_account_holder_name: '' })
const bankSaving = ref(false)
const bankSavedMessage = ref('')
function openBankEdit(agent: AgentSummaryItem) {
  const opening = bankEditId.value !== agent.agent_id
  bankEditId.value = opening ? agent.agent_id : null
  bankSavedMessage.value = ''
  if (opening) {
    bankForm.value = {
      bank_name: agent.bank_name ?? '',
      bank_account_number: agent.bank_account_number ?? '',
      bank_account_holder_name: agent.bank_account_holder_name ?? '',
    }
  }
}
async function submitBankAccount(agent: AgentSummaryItem) {
  const payload: Record<string, string> = {
    bank_name: bankForm.value.bank_name.trim(),
    bank_account_number: bankForm.value.bank_account_number.trim(),
    bank_account_holder_name: bankForm.value.bank_account_holder_name.trim(),
  }
  bankSaving.value = true
  bankSavedMessage.value = ''
  try {
    await api.put(`/users/${agent.agent_id}`, payload)
    bankSavedMessage.value = `บันทึกสำเร็จ — เลขที่บัญชี ${payload.bank_account_number || '-'}`
    /*
     * Completing a bank account moves this payee out of กลุ่มติดปัญหา and into
     * กลุ่มตั้งจ่ายได้เลย — which is the whole reason somebody opened this form.
     * The row will vanish from the list they are looking at; the amber bar
     * above the band is what tells them it worked.
     */
    await reloadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกบัญชีธนาคารไม่สำเร็จ (${e.status})` : 'บันทึกบัญชีธนาคารไม่สำเร็จ'
  } finally {
    bankSaving.value = false
  }
}

// ── Detail drill-down (TASK-046/047) ────────────────────────────────────
const detailAgentId = ref<number | null>(null)
const detailLoading = ref(false)
const detailError = ref('')
const detailEntries = ref<LedgerItem[]>([])
const detailTotal = ref(0)
const agentDetail = ref<AgentDetail | null>(null)
const agentDetailLoading = ref(false)

async function toggleDetail(agent: AgentSummaryItem, options: { keepOpen?: boolean } = {}) {
  if (detailAgentId.value === agent.agent_id && options.keepOpen !== true) {
    detailAgentId.value = null
    return
  }
  detailAgentId.value = agent.agent_id
  detailEntries.value = []
  detailError.value = ''
  detailLoading.value = true
  agentDetail.value = null
  agentDetailLoading.value = true
  try {
    const params = new URLSearchParams(buildQuery())
    params.set('agent_id', String(agent.agent_id))
    const [ledgerRes, userRes] = await Promise.all([
      api.get<{ data: LedgerItem[]; meta?: { total: number } }>(activeCompany.scopedPath(`/commission-ledger?${params.toString()}`)),
      api.get<{ data: AgentDetail }>(`/users/${agent.agent_id}`),
    ])
    detailEntries.value = ledgerRes.data
    detailTotal.value = ledgerRes.meta?.total ?? ledgerRes.data.length
    agentDetail.value = userRes.data
  } catch (e) {
    detailError.value = e instanceof ApiError ? `โหลดรายละเอียดไม่สำเร็จ (${e.status})` : 'โหลดรายละเอียดไม่สำเร็จ'
  } finally {
    detailLoading.value = false
    agentDetailLoading.value = false
  }
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

// ── Export CSV (TASK-044 §3) ────────────────────────────────────────────
// The export endpoint ALWAYS forces payment_status=pending server-side (a
// payout file only ever needs money still owed), so the group is deliberately
// NOT sent — sending it would be silently ignored and would suggest the file
// respects which step is open, which it never does. Only the date range
// carries over.
const exporting = ref(false)
async function exportCsv() {
  exporting.value = true
  errorMessage.value = ''
  try {
    const query = buildQuery()
    await api.download(`/agent-commission-summary/export${query ? `?${query}` : ''}`)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ส่งออก CSV ไม่สำเร็จ (${e.status})` : 'ส่งออก CSV ไม่สำเร็จ'
  } finally {
    exporting.value = false
  }
}

/**
 * 2026-09-16 — ONE KPI, BECAUSE THE BAND CARRIES THE REST.
 *
 * The header used to hold ค้างจ่ายรวม / รอตั้งจ่าย / เลือกไว้. Two of those are
 * now the first step of the band and the sticky bar, printed larger and next to
 * the names they describe; leaving them here as well would put the same figure
 * on the screen twice, which is how the two-numbers-one-name defect starts.
 *
 * What survives is the one number nothing else on the screen says: the WHOLE
 * outstanding liability, which is neither what can be raised today (step 1, net
 * of what is in flight) nor what is waiting at รอบจ่าย (step 2).
 */
const kpis = computed(() => [
  { label: 'ค้างจ่ายรวมทั้งหมด', value: formatSatangOrUnmeasured(sumBucket(pendingRows.value, (s) => s.total_pending_satang)) },
])

// BR-3 — satang in, baht out. Divide by 100 only here, at the display layer.
function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}

// TASK-209 — every list is scoped server-side, so a change of the header
// company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => {
  selected.value = new Set()
  paidLoaded.value = false
  pendingLoaded.value = false
  ensureLoaded(true)
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8 pb-28">
    <HeroHeader
      icon="money"
      title="ตั้งจ่าย"
      subtitle="กดที่ขั้นในแถบด้านล่างเพื่อสลับกลุ่ม แล้วคลิกที่แถวเพื่อเลือกคนที่จะจ่าย"
      description="ตั้งจ่ายแล้วรายการจะไปรอที่ รอบจ่าย ให้ฝ่ายบัญชีโอน แล้วกลับมากด “บันทึกว่าโอนแล้ว” — ยังไม่มีการปิดรายการค่าคอมและยังไม่แจ้งตัวแทนในขั้นนี้"
      :kpis="kpis"
      accent-color="brand"
      storage-key="agent-commission-summary"
    />

    <CompanyScopeNotice action="ดูสรุปคอมมิชชั่น" />

    <!--
      ═══ ONE TOOLBAR, AND IT NO LONGER SWITCHES ANYTHING ═══
      The ค้างจ่าย / จ่ายแล้ว / ทั้งหมด strip that used to sit here split the
      list on the wrong axis. Switching groups is the band's job now, so what
      is left are the two conveniences: find a name, and narrow the window.
    -->
    <div class="mt-4 flex flex-wrap items-center gap-3">
      <label class="relative">
        <span class="sr-only">ค้นหาชื่อตัวแทน</span>
        <Icon name="search" :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input
          v-model="search"
          type="search"
          placeholder="ค้นหาชื่อ"
          class="h-9 w-48 pl-8 pr-3 rounded-xl border border-slate-200 text-sm bg-white"
          data-test="payout-search"
        />
      </label>

      <div class="ml-auto flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="h-9 px-3 rounded-xl border border-slate-200 text-slate-600 text-[12.5px] font-bold hover:bg-slate-50 flex items-center gap-1.5"
          data-test="payout-toggle-dates"
          @click="showDates = !showDates"
        >
          <Icon name="filter" :size="14" />
          ช่วงวันที่{{ dateFilterActive ? ' · กรองอยู่' : '' }}
        </button>
        <button
          type="button"
          :disabled="exporting"
          title="ไฟล์สำหรับโอนจ่ายจริง — มีเฉพาะยอดค้างจ่ายเท่านั้น ไม่รวมรายการที่จ่ายแล้ว"
          class="h-9 px-3 rounded-xl border border-slate-200 text-slate-600 text-[12.5px] font-bold hover:bg-slate-50 flex items-center gap-1.5 disabled:opacity-50"
          @click="exportCsv"
        >
          <Icon name="download" :size="14" />
          {{ exporting ? 'กำลังส่งออก...' : 'ส่งออก CSV' }}
        </button>
      </div>
    </div>

    <!-- The six selects are behind the button above now, not in front of the work. -->
    <div v-if="showDates" class="mt-3 p-4 rounded-xl bg-white/95 border border-slate-200 flex flex-wrap items-end gap-3">
      <DateRangeFilter v-model:date-from="filters.date_from" v-model:date-to="filters.date_to" :years-back="3" :years-forward="0" />
      <button
        type="button"
        class="h-9 px-4 rounded-xl bg-brand-600 text-white font-bold text-sm hover:bg-brand-700"
        @click="applyFilters"
      >
        ใช้ช่วงวันที่
      </button>
      <button
        v-if="dateFilterActive"
        type="button"
        class="h-9 px-4 rounded-xl border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50"
        @click="clearDateFilter"
      >
        ล้างวันที่
      </button>
    </div>

    <!--
      ═══ กลุ่มติดปัญหา — ABOVE THE BAND, NOT ON IT ═══

      These people have not entered the conveyor at all; that is WHY they are
      stuck. Putting them on the band as a step would draw a flow that does not
      exist. This is also the only thing on the screen that has to be noticed
      without being looked for, which is the other reason it is first.

      It disappears entirely when the group is empty — a permanent warning bar
      on a day with no problems teaches people to stop reading warning bars.
    -->
    <div
      v-if="blockedRows.length"
      class="mt-4 px-4 py-3 rounded-2xl bg-amber-50 border border-amber-200 flex flex-wrap items-center gap-3"
      :class="group === 'blocked' ? 'ring-2 ring-amber-400' : ''"
      data-test="payout-blocked-alert"
    >
      <span class="w-7 h-7 rounded-lg bg-amber-700 text-white inline-flex items-center justify-center text-sm font-extrabold shrink-0">!</span>
      <div class="min-w-0">
        <p class="text-[13.5px] font-extrabold text-amber-900">
          {{ blockedRows.length }} รายมีค่าคอม แต่ตั้งจ่ายไม่ได้
          <span v-if="blockedSatang !== null" class="tabular-nums">· {{ formatSatang(blockedSatang) }}</span>
        </p>
        <p class="text-[12px] text-amber-800 truncate">
          {{ blockedRows.map((s) => s.agent_name ?? '—').join(' · ') }}
        </p>
      </div>
      <div class="ml-auto flex items-center gap-2">
        <button
          type="button"
          class="h-9 px-4 rounded-xl border-[1.5px] border-amber-700 text-amber-900 text-[12.5px] font-extrabold hover:bg-amber-100"
          data-test="payout-blocked-open"
          @click="openGroup('blocked')"
        >
          {{ group === 'blocked' ? 'กำลังดูรายชื่อ' : 'ดูรายชื่อ' }}
        </button>
        <!--
          The one repair that cannot be made from this screen: the seat is not
          a person, and PUT /users/{id} refuses it. Offered here only when the
          company's own share is actually one of the blocked payees.
        -->
        <RouterLink
          v-if="blockedRows.some((s) => s.is_company_share)"
          :to="{ name: 'commission-plan-settings' }"
          class="h-9 px-4 rounded-xl bg-amber-700 text-white text-[12.5px] font-extrabold hover:bg-amber-800 inline-flex items-center"
          data-test="payout-blocked-company-link"
        >
          กรอกบัญชีบริษัท →
        </RouterLink>
      </div>
    </div>

    <!--
      ═══ THE BAND: A SUMMARY AND THE SWITCHER, ONE OBJECT ═══

      Each step carries MONEY rather than a count, because the question this
      screen is opened to answer is about money. The step in view has a heavy
      border and says "กำลังดู", and the list underneath it is that step — so
      nobody has to hold in their head which number the list belongs to.

      Step 3 has no figure until it has been opened once: its bucket is
      measured by a request that has not been made, and §3.7/F-10 forbids
      printing a number for a bucket nobody measured. It says "กดเพื่อดู".
    -->
    <div class="mt-4 flex flex-col sm:flex-row items-stretch gap-2" role="tablist" data-test="payout-step-band">
      <template v-for="(step, i) in steps" :key="step.key">
        <div v-if="i > 0" class="hidden sm:flex items-center px-1 text-slate-300 shrink-0">
          <Icon name="chevron-right" :size="18" />
        </div>
        <button
          type="button"
          role="tab"
          :aria-selected="group === step.key"
          class="flex-1 text-left px-4 py-3 rounded-2xl transition"
          :class="group === step.key
            ? 'border-2 border-brand-700 bg-brand-50'
            : 'border border-slate-200 bg-white hover:border-brand-300 hover:bg-brand-50/40'"
          :data-test="`payout-step-${step.key}`"
          @click="openGroup(step.key)"
        >
          <div class="flex items-center gap-2">
            <span
              class="w-5 h-5 rounded-md inline-flex items-center justify-center text-[11px] font-extrabold"
              :class="group === step.key ? 'bg-brand-700 text-white' : 'bg-slate-200 text-slate-600'"
            >{{ step.n }}</span>
            <span class="text-[13.5px] font-bold" :class="group === step.key ? 'text-brand-700' : 'text-slate-700'">
              {{ step.label }}
            </span>
            <span v-if="group === step.key" class="ml-auto text-[11px] font-extrabold text-brand-700">กำลังดู</span>
          </div>
          <p
            class="mt-1.5 text-[21px] font-extrabold tabular-nums leading-tight"
            :class="step.key === 'paid' ? 'text-emerald-600' : 'text-slate-900'"
            :data-test="`payout-step-amount-${step.key}`"
          >
            <template v-if="step.satang !== null">{{ formatSatang(step.satang) }}</template>
            <span v-else class="text-[15px] font-bold text-slate-400">
              {{ step.key === 'paid' && !paidLoaded ? 'กดเพื่อดู' : UNMEASURED_LABEL }}
            </span>
          </p>
          <p class="mt-0.5 text-[11.5px] text-slate-500">
            <template v-if="step.count !== null">{{ step.count }} ราย · </template>{{ step.note }}
          </p>
        </button>
      </template>
    </div>

    <!--
      Why the checkboxes vanished. A payout is raised for an agent's WHOLE
      outstanding balance, and under a date filter the figure on each row is a
      slice of it.
    -->
    <p
      v-if="dateFilterActive"
      class="mt-3 text-[12.5px] text-slate-500"
      data-test="payout-date-filter-note"
    >
      กรองตามวันที่อยู่ — ตั้งจ่ายไม่ได้ เพราะการตั้งจ่ายคิดจากยอดค้างทั้งหมดของตัวแทน ไม่ใช่เฉพาะช่วงที่กรอง
      <button type="button" class="ml-1 font-bold text-brand-600 hover:underline" @click="clearDateFilter">ล้างวันที่</button>
    </p>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <p
      v-if="batchDone"
      class="mt-4 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-sm font-bold text-emerald-700"
      data-test="payout-batch-done"
    >
      ตั้งจ่าย {{ batchDone.count }} ราย รวม {{ formatSatang(batchDone.satang) }} เรียบร้อย —
      <RouterLink :to="{ name: 'commission-runs' }" class="underline">ดูต่อที่ รอบจ่าย</RouterLink>
    </p>

    <!--
      The list says which group it is, in words, right above itself. The band
      says it too, but the band is a row of numbers and this is a heading — and
      กลุ่มติดปัญหา has no step on the band at all, so without this line that
      group would be the only one whose list is unlabelled.
    -->
    <div class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1">
      <h2 class="text-[13.5px] font-extrabold text-slate-800" data-test="payout-group-heading">
        {{ GROUP_LABELS[group] }}
        <span class="font-bold text-slate-400">· {{ visibleRows.length }} ราย</span>
      </h2>
      <span v-if="canSelect && selectableRows.length" class="text-[12px] text-slate-400">คลิกที่แถวเพื่อเลือก</span>
      <button
        v-if="group !== 'payable'"
        type="button"
        class="text-[12px] font-bold text-brand-600 hover:underline"
        data-test="payout-back-to-work"
        @click="openGroup('payable')"
      >
        ← กลับไปที่ ตั้งจ่ายได้เลย
      </button>
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-3" />
    <template v-else>
      <EmptyState
        v-if="!groupRows.length"
        :icon="group === 'blocked' ? 'shield' : 'money'"
        :title="group === 'payable' ? 'ไม่มีใครที่ตั้งจ่ายได้ตอนนี้'
          : group === 'reserved' ? 'ไม่มีรายการที่รอฝ่ายบัญชีโอน'
          : group === 'blocked' ? 'ไม่มีใครติดปัญหา'
          : 'ยังไม่มีประวัติการโอน'"
        class="mt-3"
        data-test="payout-group-empty"
      />
      <EmptyState
        v-else-if="!visibleRows.length"
        icon="search"
        title="ไม่พบชื่อที่ค้นหา"
        class="mt-3"
      />

      <template v-else>
      <!--
        2026-09-16 — ONE LINE THAT SAYS HOW THE SCREEN WORKS.

        Owner: "ผู้ใช้ไม่รู้ Action ในการติ๊กเครื่องหมายถูกหน้ารายชื่อที่ต้องการ
        โอนค่าคอม ทำไห้ผู้ใช้ไม่เข้าใจในการใช้งาน".

        Shown only while nothing is ticked, and only where ticking is possible:
        once somebody has selected a row they have understood, and a permanent
        instruction is a permanent admission that the screen did not explain
        itself.
      -->
      <p
        v-if="canSelect && selectableRows.length && !selectedRows.length"
        class="mt-3 mb-1 text-[12.5px] text-slate-500"
        data-test="payout-select-hint"
      >
        <b class="text-slate-700">วิธีใช้:</b> คลิกที่แถวของคนที่จะจ่ายรอบนี้เพื่อเลือก (เลือกได้หลายคน) แล้วกดปุ่มตั้งจ่ายที่แถบด้านล่าง
        — หรือกด “ตั้งจ่ายคนนี้” ที่ท้ายแถวถ้าจะจ่ายทีละคน
      </p>

      <!-- ═══ THE TABLE ═══
           Denser than the card list it replaces: forty payees fit on one
           screen, and the bank column is in view while the admin is ticking
           rather than discovered when the transfer file is opened. -->
      <div class="mt-3 bg-white/95 border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-slate-50 text-left text-[11.5px] font-bold text-slate-500 border-b border-slate-200">
              <!-- 2026-09-16 — the column has a NAME now. An unlabelled
                   checkbox column is only obvious to somebody who already
                   knows the screen works by selection, which is exactly the
                   person it did not need to tell. -->
              <th v-if="canSelect" class="w-16 py-2.5 pl-4 font-bold">
                <input
                  type="checkbox"
                  class="w-4 h-4 rounded accent-brand-600"
                  :checked="allSelected"
                  :disabled="!selectableRows.length"
                  aria-label="เลือกทั้งหมดที่ตั้งจ่ายได้"
                  data-test="payout-select-all"
                  @change="toggleAll"
                />
                <span class="ml-1.5 align-middle">เลือก</span>
              </th>
              <th class="py-2.5 px-3">ผู้รับ</th>
              <th class="py-2.5 px-3 w-24">รายการ</th>
              <th class="py-2.5 px-3 w-64">บัญชีธนาคาร</th>
              <th v-if="group === 'paid'" class="py-2.5 px-3 w-36 text-right">จ่ายแล้ว</th>
              <th v-else class="py-2.5 px-3 w-40 text-right">ยอดค้างจ่าย</th>
              <th class="py-2.5 pr-4 w-28"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="s in visibleRows" :key="s.agent_id">
              <!--
                2026-09-16 — THE WHOLE ROW IS THE TARGET.

                Owner: "ผู้ใช้ไม่รู้ Action ในการติ๊กเครื่องหมายถูกหน้ารายชื่อ".
                A 16px checkbox at the far left of a full-width row is a small
                target and a quiet one; the row is what people aim at.

                The checkbox stays and stays the accessible control — this only
                widens where a pointer may land. Every interactive child
                (ดูรายละเอียด, แก้ไข, the links) stops the event, or clicking
                one would also tick the row underneath it.
              -->
              <tr
                class="border-b border-slate-100 last:border-0 transition-colors"
                :class="[
                  s.is_company_share ? 'bg-emerald-50/40' : '',
                  isSelected(s.agent_id) ? 'bg-brand-50' : '',
                  selectable(s) ? 'cursor-pointer hover:bg-brand-50/60' : '',
                ]"
                :data-test="s.is_company_share ? `company-share-row-${s.agent_id}` : `payout-row-${s.agent_id}`"
                @click="toggleRow(s)"
              >
                <td v-if="canSelect" class="py-3 pl-4 align-top" @click.stop>
                  <input
                    type="checkbox"
                    class="w-4 h-4 mt-1 rounded accent-brand-600 disabled:opacity-40"
                    :checked="isSelected(s.agent_id)"
                    :disabled="!selectable(s)"
                    :aria-label="`เลือก ${s.agent_name ?? ''}`"
                    :data-test="`payout-select-${s.agent_id}`"
                    @change="toggleRow(s)"
                  />
                </td>

                <td class="py-3 px-3">
                  <div class="flex items-start gap-3 min-w-0">
                    <img v-if="s.avatar_url" :src="s.avatar_url" alt="" class="w-8 h-8 rounded-full object-cover shrink-0" />
                    <div
                      v-else
                      class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold shrink-0"
                      :class="[tierColor(s.cert_tier?.key).bg, tierColor(s.cert_tier?.key).text]"
                    >
                      {{ initial(s.agent_name) }}
                    </div>
                    <div class="min-w-0">
                      <p class="font-bold text-slate-900 truncate">{{ s.agent_name ?? '—' }}</p>
                      <!--
                        THE COMPANY'S OWN SHARE, LABELLED RATHER THAN LIFTED
                        OUT. It is payable now, so it belongs in the list it is
                        paid from; an admin reconciling a transfer file still
                        has to be able to tell that this line is not a person.
                      -->
                      <p v-if="s.is_company_share" class="text-[11px] font-extrabold text-emerald-700 mt-0.5">
                        ส่วนของบริษัท · ค่าแนะนำที่บริษัทได้ในฐานะหัวหน้าสายงาน
                      </p>
                      <p v-if="blockedReason(s)" class="text-[11.5px] text-amber-700 font-bold mt-0.5" :data-test="`payout-blocked-${s.agent_id}`">
                        {{ blockedReason(s) }}
                      </p>
                      <!--
                        Part of the balance is in flight, part is still
                        raisable — so this payee is in BOTH group 1 and group 2
                        and the two lists show two different figures for them.
                        This line is what reconciles them.
                      -->
                      <p
                        v-if="!blockedReason(s) && isReserved(s)"
                        class="text-[11.5px] text-slate-500 mt-0.5"
                        :data-test="`payout-reserved-${s.agent_id}`"
                      >
                        ตั้งจ่ายแล้วรอโอน {{ formatSatang(s.reserved_satang ?? 0) }} ·
                        ตั้งจ่ายเพิ่มได้ {{ formatSatang(availableOf(s) ?? 0) }}
                        <RouterLink :to="{ name: 'commission-runs' }" class="font-bold text-brand-600 hover:underline" @click.stop>ดูรอบจ่าย</RouterLink>
                      </p>
                    </div>
                  </div>
                </td>

                <td class="py-3 px-3 text-slate-500 whitespace-nowrap align-top">{{ s.entry_count }} รายการ</td>

                <td class="py-3 px-3 align-top">
                  <!--
                    The seat is not a person: PUT /users/{id} refuses it, so
                    its account is edited where it was created.
                  -->
                  <template v-if="s.is_company_share">
                    <span v-if="s.bank_account_number" class="text-slate-600">
                      {{ s.bank_name || '—' }} · {{ s.bank_account_number }}
                    </span>
                    <RouterLink
                      v-else
                      :to="{ name: 'commission-plan-settings' }"
                      class="text-[12.5px] font-bold text-amber-700 hover:underline"
                      data-test="payout-company-bank-link"
                      @click.stop
                    >
                      กรอกบัญชีบริษัท →
                    </RouterLink>
                  </template>
                  <template v-else>
                    <span v-if="s.bank_account_number" class="text-slate-600">
                      {{ s.bank_name || '—' }} · {{ s.bank_account_number }}
                    </span>
                    <span v-else class="text-amber-700 font-bold text-[12.5px]">ยังไม่มีบัญชี</span>
                    <button
                      type="button"
                      class="ml-2 text-[12px] font-bold text-slate-500 hover:text-brand-600 hover:underline"
                      :data-test="`payout-bank-edit-${s.agent_id}`"
                      @click.stop="openBankEdit(s)"
                    >
                      แก้ไข
                    </button>
                  </template>
                </td>

                <td
                  v-if="group === 'paid'"
                  class="py-3 px-3 text-right tabular-nums align-top"
                  :class="s.total_paid_satang === null ? 'text-slate-400' : 'text-emerald-600 font-bold'"
                >
                  {{ formatSatangOrUnmeasured(s.total_paid_satang) }}
                </td>
                <td
                  v-else
                  class="py-3 px-3 text-right tabular-nums align-top"
                  :class="s.total_pending_satang === null ? 'text-slate-400' : 'text-amber-600 font-bold'"
                >
                  {{ formatSatangOrUnmeasured(s.total_pending_satang) }}
                </td>

                <td class="py-3 pr-4 text-right align-top" @click.stop>
                  <!--
                    ข้อ 3 — the shortcut for the case that is actually common
                    here: paying one person. It writes nothing; it selects this
                    row and opens the SAME confirmation the batch press opens.
                  -->
                  <button
                    v-if="selectable(s)"
                    type="button"
                    class="inline-flex items-center gap-1 h-8 px-3 rounded-lg bg-brand-600 text-white text-[12px] font-extrabold hover:bg-brand-700 whitespace-nowrap"
                    :data-test="`payout-pay-one-${s.agent_id}`"
                    @click="askToPayOne(s)"
                  >
                    <Icon name="money" :size="13" />
                    ตั้งจ่ายคนนี้
                  </button>
                  <!--
                    กลุ่มติดปัญหา is a list of repairs, so the row carries the
                    way to make one. An agent's bank account is edited inline
                    (the แก้ไข link in the column beside it); the company seat
                    is not a person and is repaired on ตั้งค่าค่าแนะนำ.
                  -->
                  <RouterLink
                    v-else-if="group === 'blocked' && s.is_company_share"
                    :to="{ name: 'commission-plan-settings' }"
                    class="inline-flex items-center h-8 px-3 rounded-lg bg-amber-700 text-white text-[12px] font-extrabold hover:bg-amber-800 whitespace-nowrap"
                    :data-test="`payout-fix-company-${s.agent_id}`"
                  >
                    ไปกรอกบัญชี →
                  </RouterLink>
                  <button
                    v-else-if="group === 'blocked'"
                    type="button"
                    class="inline-flex items-center h-8 px-3 rounded-lg bg-amber-700 text-white text-[12px] font-extrabold hover:bg-amber-800 whitespace-nowrap"
                    :data-test="`payout-fix-bank-${s.agent_id}`"
                    @click="openBankEdit(s)"
                  >
                    กรอกบัญชี
                  </button>
                  <button
                    type="button"
                    class="block ml-auto mt-1 text-[12px] font-bold text-slate-500 hover:text-brand-600 hover:underline whitespace-nowrap"
                    :data-test="`payout-detail-${s.agent_id}`"
                    @click="toggleDetail(s)"
                  >
                    ดูรายละเอียด
                  </button>
                </td>
              </tr>

              <!-- Bank editor, in the row it belongs to. -->
              <tr v-if="bankEditId === s.agent_id" :key="`bank-${s.agent_id}`" class="border-b border-slate-100 bg-slate-50/60">
                <td :colspan="canSelect ? 6 : 5" class="px-4 py-3">
                  <p v-if="bankSavedMessage" class="text-xs font-bold text-emerald-600 mb-2">{{ bankSavedMessage }}</p>
                  <p v-else class="text-xs text-slate-400 mb-2">
                    ปัจจุบัน: ธนาคาร {{ s.bank_name || '-' }} · เลขบัญชี {{ s.bank_account_number || '-' }} · ชื่อบัญชี {{ s.bank_account_holder_name || '-' }}
                    — แก้ไขช่องที่ต้องการเปลี่ยนแล้วกดบันทึก
                  </p>
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <input v-model="bankForm.bank_name" type="text" placeholder="ธนาคาร" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm" />
                    <input v-model="bankForm.bank_account_number" type="text" inputmode="numeric" placeholder="เลขที่บัญชี" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm" />
                    <input v-model="bankForm.bank_account_holder_name" type="text" placeholder="ชื่อบัญชี" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm" />
                  </div>
                  <button type="button" :disabled="bankSaving" class="mt-2 btn-primary" @click="submitBankAccount(s)">
                    {{ bankSaving ? 'กำลังบันทึก...' : 'บันทึกบัญชีธนาคาร' }}
                  </button>
                </td>
              </tr>

              <!-- Drill-down: products sold, clients bought, stored snapshot. -->
              <tr v-if="detailAgentId === s.agent_id" :key="`detail-${s.agent_id}`" class="border-b border-slate-100 bg-slate-50/60">
                <td :colspan="canSelect ? 6 : 5" class="px-4 py-3">
                  <div v-if="agentDetailLoading" class="text-xs text-slate-400 mb-3">กำลังโหลดข้อมูลตัวแทน...</div>
                  <div v-else-if="agentDetail" class="flex items-center gap-2 mb-3 pb-3 border-b border-slate-200 text-xs text-slate-500">
                    <span>{{ agentDetail.email || '—' }}</span>
                    <span v-if="agentDetail.phone">· {{ agentDetail.phone }}</span>
                    <span>· เข้าร่วมเมื่อ {{ formatDate(agentDetail.created_at) }}</span>
                    <span
                      class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold"
                      :class="[tierColor(agentDetail.cert_tier?.key).bg, tierColor(agentDetail.cert_tier?.key).text]"
                    >
                      {{ agentDetail.cert_tier?.name ?? 'ยังไม่ผ่านเกณฑ์' }}
                    </span>
                  </div>

                  <div v-if="detailLoading" class="text-xs text-slate-400">กำลังโหลด...</div>
                  <div v-else-if="detailError" class="text-xs text-rose-600">{{ detailError }}</div>
                  <div v-else-if="!detailEntries.length" class="text-xs text-slate-400">ไม่มีรายการคอมมิชชั่น</div>
                  <div v-else class="overflow-x-auto">
                    <table class="w-full text-xs">
                      <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-200">
                          <th class="py-1.5 pr-3 font-bold">วันที่ขาย</th>
                          <th class="py-1.5 pr-3 font-bold">ชื่อลูกค้า</th>
                          <!-- 2026-09-16 — the two columns that used to live
                               only on รายรายการ. Without them an override and
                               a direct commission on the same sale are two
                               identical lines with different amounts. -->
                          <th class="py-1.5 pr-3 font-bold">ประเภท</th>
                          <th class="py-1.5 pr-3 font-bold">ชื่อสินค้า</th>
                          <th class="py-1.5 pr-3 font-bold text-right">ราคาที่ขายได้</th>
                          <th class="py-1.5 pr-3 font-bold">โปรโมชั่น</th>
                          <th class="py-1.5 pr-3 font-bold text-right">ค่าคอม</th>
                          <th class="py-1.5 font-bold">สถานะ</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="e in detailEntries" :key="e.id" class="border-b border-slate-100 last:border-0">
                          <td class="py-2 pr-3 text-slate-500 whitespace-nowrap">{{ formatDate(e.created_at) }}</td>
                          <td class="py-2 pr-3 text-slate-700 font-bold">{{ e.referral?.client?.name ?? '—' }}</td>
                          <td class="py-2 pr-3 whitespace-nowrap">
                            <span
                              class="px-2 py-0.5 rounded-md text-[10.5px] font-bold"
                              :class="earnedViaBadge(e).cls"
                              :data-test="`entry-via-${e.id}`"
                            >{{ earnedViaBadge(e).label }}</span>
                            <!-- Whose sale earned it. Only meaningful on an
                                 override row, where the payee is the RECIPIENT
                                 and not the seller — the single most misread
                                 thing this list ever showed. -->
                            <span
                              v-if="e.override_source_agent"
                              class="block text-[10.5px] text-amber-700 font-bold mt-0.5"
                              :data-test="`entry-source-${e.id}`"
                            >จากการขายของ {{ e.override_source_agent.name }}</span>
                          </td>
                          <td class="py-2 pr-3 text-slate-500">{{ e.product?.name ?? '—' }}</td>
                          <td class="py-2 pr-3 text-slate-700 text-right whitespace-nowrap">
                            {{ e.sale_price_satang_at_time !== null ? formatSatang(e.sale_price_satang_at_time) : '—' }}
                          </td>
                          <td class="py-2 pr-3 text-slate-500">
                            {{ e.applied_price_promotion ? (e.applied_price_promotion.note || formatSatang(e.applied_price_promotion.discounted_price_satang)) : '—' }}
                          </td>
                          <td class="py-2 pr-3 text-slate-900 font-bold text-right whitespace-nowrap">{{ formatSatang(e.amount_satang) }}</td>
                          <td class="py-2 whitespace-nowrap">
                            <span
                              class="text-[10px] font-bold px-1.5 py-0.5 rounded"
                              :class="e.payment_status === 'paid' ? 'text-emerald-600 bg-emerald-50' : 'text-amber-600 bg-amber-50'"
                            >
                              {{ e.payment_status === 'paid' ? 'จ่ายแล้ว' : 'รอจ่าย' }}
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <p v-if="detailTotal > detailEntries.length" class="text-[11px] text-slate-400 pt-2">
                      แสดง {{ detailEntries.length }} จาก {{ detailTotal }} รายการทั้งหมด
                    </p>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      </template>
    </template>

    <!--
      ═══ THE SELECTION BAR ═══
      It carries the running total because that figure — not the number of
      people — is what an admin checks against the transfer they are about to
      ask accounting for.

      2026-09-16 — IT IS THERE BEFORE ANYTHING IS TICKED. It used to appear
      only once something was selected, which meant the one control that would
      have explained the screen was invisible to exactly the person who had not
      worked it out. It now renders whenever selecting is possible, and says
      what to do while the selection is empty.
    -->
    <div
      v-if="canSelect && selectableRows.length"
      class="sticky bottom-4 mt-5 z-20 rounded-2xl shadow-xl px-5 py-4"
      :class="selectedRows.length ? 'bg-brand-700 text-white' : 'bg-white border border-slate-200'"
      data-test="payout-selection-bar"
    >
      <!-- Empty state: an instruction, not a disabled button with no total. -->
      <div v-if="!selectedRows.length" class="flex flex-wrap items-center gap-x-4 gap-y-2" data-test="payout-selection-empty">
        <Icon name="money" :size="18" class="text-slate-300" />
        <span class="text-[13.5px] font-bold text-slate-600">ยังไม่ได้เลือกใคร</span>
        <span class="text-[12.5px] text-slate-400">คลิกที่แถวของคนที่จะจ่ายรอบนี้ — เลือกได้หลายคน ยอดรวมจะขึ้นตรงนี้</span>
        <button
          type="button"
          class="ml-auto h-9 px-4 rounded-xl border border-slate-200 text-slate-600 text-[12.5px] font-bold hover:bg-slate-50"
          data-test="payout-select-all-shortcut"
          @click="toggleAll"
        >
          เลือกทั้งหมด {{ selectableRows.length }} ราย
        </button>
      </div>

      <div v-else class="flex flex-wrap items-center gap-x-5 gap-y-3">
        <span class="text-sm font-bold text-brand-100">เลือกไว้ {{ selectedRows.length }} ราย</span>
        <span class="text-xl font-extrabold tabular-nums" data-test="payout-selection-total">
          {{ formatSatang(selectedTotalSatang) }}
        </span>
        <button type="button" class="text-[12.5px] font-bold text-brand-100 hover:underline" @click="selected = new Set()">
          ล้างที่เลือก
        </button>

        <div class="ml-auto flex items-center gap-2">
          <button
            v-if="!confirming"
            type="button"
            class="h-10 px-6 rounded-xl bg-white text-brand-700 text-sm font-extrabold hover:bg-brand-50"
            data-test="payout-batch-submit"
            @click="askToPay"
          >
            ตั้งจ่าย {{ selectedRows.length }} ราย
          </button>
        </div>
      </div>

      <!--
        THE SECOND PRESS. It names the AMOUNT, not "are you sure" — the figure
        is what is being agreed to, and it is the last moment it can be checked
        against what accounting will be asked to transfer.
      -->
      <div v-if="confirming" class="mt-3 pt-3 border-t border-white/20" data-test="payout-batch-confirm">
        <p class="text-[13px]">
          ตั้งจ่ายให้ <b>{{ selectedRows.length }} ราย</b> รวม
          <b class="tabular-nums">{{ formatSatang(selectedTotalSatang) }}</b> —
          ทั้งหมดนี้จะถูกบันทึกพร้อมกัน ถ้ามีรายใดไม่ผ่านจะไม่บันทึกเลยสักราย
        </p>
        <p class="mt-1 text-[12px] text-brand-100">
          ยังไม่ปิดรายการค่าคอม และยังไม่แจ้งตัวแทนว่าเงินเข้า — รายการจะไปรออยู่ที่ <b>รอบจ่าย</b>
          ให้ส่งฝ่ายบัญชีโอน แล้วกลับมากด “บันทึกว่าโอนแล้ว” อีกครั้ง
        </p>
        <div class="mt-3 flex items-center gap-2">
          <button
            type="button"
            class="h-10 px-6 rounded-xl bg-white text-brand-700 text-sm font-extrabold hover:bg-brand-50 disabled:opacity-60"
            :disabled="paying"
            data-test="payout-batch-confirm-submit"
            @click="paySelected"
          >
            {{ paying ? 'กำลังบันทึก…' : 'ยืนยันตั้งจ่าย' }}
          </button>
          <button
            type="button"
            class="h-10 px-5 rounded-xl border border-white/40 text-white text-sm font-bold hover:bg-white/10 disabled:opacity-60"
            :disabled="paying"
            @click="confirming = false"
          >
            ยกเลิก
          </button>
        </div>
        <p v-if="batchError" class="mt-2 text-[12.5px] font-bold text-amber-200" data-test="payout-batch-error">
          {{ batchError }}
        </p>
      </div>
    </div>
  </main>
</template>
