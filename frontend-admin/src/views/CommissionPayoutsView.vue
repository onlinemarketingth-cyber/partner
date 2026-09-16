<script setup lang="ts">
/**
 * จ่ายค่าแนะนำ — the whole payout lifecycle, on one screen.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * 2026-09-16 — ตั้งจ่าย AND รอบจ่าย WERE MERGED INTO THIS FILE.
 *
 * Owner: "การตั้งจ่าย กับรอบจ่าย มันต่างกันตรงไหน มันแทบจะแทนกันได้แล้ว" → and
 * then, having seen the comparison: "รวมเป็นหน้าเดียวจริงๆ".
 *
 * They were not the same object — ตั้งจ่าย listed PEOPLE and รอบจ่าย listed
 * REQUESTS — but they had become the same SCREEN: two 3-step money bands, two
 * lists of names and amounts, and overlapping steps (ตั้งจ่าย's "รอฝ่ายบัญชีโอน"
 * was รอบจ่าย's "รอตรวจสอบ" plus "รอโอน", added together and mislabelled). The
 * middle of the errand was a dead end: a step you could read a number off and
 * not act on, whose only real content was "go to the other page".
 *
 * So there is one band and one errand now:
 *
 *   ① ตั้งจ่ายได้เลย → ② รอคุณตรวจสอบ → ③ รอฝ่ายบัญชีโอน → (โอนแล้ว)
 *
 * ── THE UNIT OF A ROW CHANGES AT STEP 2, AND THAT IS NOT HIDDEN ──
 *
 * Step ① is a list of PEOPLE: one row per payee, the amount computed live from
 * commission_ledger, the bank account the one they have today and editable
 * here. Steps ② and ③ are lists of REQUESTS: one row per document, the amount
 * frozen when it was raised, the bank account a snapshot taken at that moment
 * and not editable at all.
 *
 * The change happens at the press — a person becomes a document — and it is
 * real, not cosmetic: the same agent can be a row in ① for 500 and a row in ③
 * for 1,500 at the same time, and those are two different facts. Every list
 * therefore carries a "หน่วย: คน" / "หน่วย: ใบคำขอ" badge in its heading. Left
 * unlabelled, a reader would reasonably assume that scrolling down the band
 * follows the same people.
 *
 * ── WHY "โอนแล้ว" IS A DOOR AND NOT A FOURTH STEP ──
 *
 * There is nothing to do to it. Rendering it as a step would put a list with no
 * actions back in the middle of a screen that exists to be acted on — the exact
 * dead end this merge removed. It is a green tile that carries the figure and
 * opens รายงานการจ่าย, which is where history, filters and the CSV live now.
 *
 * ── AND WHY ติดปัญหา IS NOT A STEP EITHER ──
 *
 * Somebody with no bank account has not entered the conveyor at all; that is
 * WHY they are stuck. They get the amber bar above the band, which is also the
 * only thing on this screen that has to be noticed without being looked for.
 *
 * ── THE DATE FILTER IS GONE FROM HERE, DELIBERATELY ──
 *
 * It only ever applied to step ①, it disabled the checkboxes whenever it was
 * on (a payout settles a WHOLE balance, not a slice of one), and every question
 * it answered is a question รายงานการจ่าย answers better. Filtering is that
 * page's job now. The CSV that remains here is the BANK FILE — every baht still
 * owed, full account numbers, made to be acted on — which is why it takes no
 * date window at all.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * BR-3: amounts come back as integer satang; divided by 100 only here, at the
 * display layer.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
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
   * payment-status filter, so nobody measured it". It is NOT zero, and a
   * `?? 0` anywhere below re-creates the defect that contract was added to
   * remove. Render "ไม่ได้แสดง" instead — see formatSatangOrUnmeasured().
   */
  total_paid_satang: number | null
  total_pending_satang: number | null
  entry_count: number
  bank_name: string | null
  bank_account_number: string | null
  bank_account_holder_name: string | null
  /** This payee is the COMPANY's own seat, not one of its agents. */
  is_company_share?: boolean
  /**
   * The SERVER's answer to "can this payee be paid at all"
   * (User::hasCompletePayoutDetails) — not a rule re-derived here from the
   * three bank fields. An agent also needs an identity document, the company
   * seat deliberately does not, and a copy of that rule in the browser would
   * start disagreeing with the one that refuses the press.
   *
   * It is also what sorts a payee into กลุ่มติดปัญหา rather than ขั้นที่ ①, so
   * the grouping and the refusal can never disagree.
   */
  payout_details_complete?: boolean
  /**
   * `reserved_satang` is what is already in flight; `available_satang` is what
   * a NEW payout may be raised for, and is the figure the server checks the
   * press against. Both are the server's own arithmetic — deriving them here
   * would be the two-numbers-one-name defect in a new place.
   */
  reserved_satang?: number
  available_satang?: number | null
  avatar_url: string | null
  cert_tier: { id: number; key: string; name: string } | null
}

/**
 * A raised payout: one document, one amount, one lifecycle.
 *
 * Moved here verbatim from CommissionPayoutQueuePanel when that page was folded
 * into this one. The bank fields are a SNAPSHOT taken when the request was
 * raised, not a live read — an agent may edit their profile while a request is
 * open, and the account an admin confirms must be the account that was on
 * screen when they confirmed it. Masked to the last four: enough to recognise
 * an account, never enough to be handed it.
 */
interface WithdrawalRequest {
  id: number
  agent_id: number
  agent_name: string | null
  /** Which of the two doors this came through. */
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
  bank_account_number_masked: string | null
  bank_account_holder_name: string | null
  item_count: number | null
  created_at: string
}

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

// ── The groups ──────────────────────────────────────────────────────────
/**
 * `blocked` is a group but NOT a step on the band — see the header comment.
 * `payable` and `blocked` list people; `review` and `transfer` list requests.
 */
type Group = 'payable' | 'blocked' | 'review' | 'transfer'

/** Which kind of thing a row in this group is. Drives the heading badge. */
const GROUPS: Record<Group, { label: string; unit: 'people' | 'requests' }> = {
  payable: { label: 'ขั้นที่ 1 · ตั้งจ่ายได้เลย', unit: 'people' },
  blocked: { label: 'ติดปัญหา — มีค่าคอมแต่ตั้งจ่ายไม่ได้', unit: 'people' },
  review: { label: 'ขั้นที่ 2 · รอคุณตรวจสอบ', unit: 'requests' },
  transfer: { label: 'ขั้นที่ 3 · รอฝ่ายบัญชีโอน', unit: 'requests' },
}

/**
 * Where a link may ask this screen to open.
 *
 * Two links in the wild named the old รอบจ่าย tabs, and the router redirect
 * from /commission still carries `?view=`. `queue` and `pending_review` mean
 * "the review step"; `approved` means the transfer step; anything else opens
 * the work.
 *
 * Read ONCE at setup, never watched: this is a starting point somebody handed
 * over, and re-applying it would fight the next step the reader presses.
 */
function groupFromQuery(): Group {
  const asked = String(route.query.view ?? route.query.tab ?? '')

  if (asked === 'queue' || asked === 'pending_review') return 'review'
  if (asked === 'approved' || asked === 'transfer') return 'transfer'

  return 'payable'
}

const group = ref<Group>(groupFromQuery())

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

/*
 * THREE SOURCES, NOT ONE, AND THEY ARE NOT INTERCHANGEABLE.
 *
 * `pendingRows` is a per-AGENT aggregate of unpaid commission (steps ① and the
 * amber bar). `queue` is per-REQUEST (steps ② and ③). `stepTotals` is the
 * server's own count and sum per status, which is what the band prints for the
 * two request steps and for the โอนแล้ว tile.
 *
 * The band could have counted the loaded queue rows instead — and would then be
 * describing one page of twenty rather than the step. The summary endpoint
 * exists precisely because that distinction is invisible until the day somebody
 * has twenty-one approved payouts.
 */
const pendingRows = ref<AgentSummaryItem[]>([])
const pendingLoaded = ref(false)

const queue = ref<Record<'pending_review' | 'approved', WithdrawalRequest[]>>({
  pending_review: [],
  approved: [],
})
const queueLoaded = ref<Record<'pending_review' | 'approved', boolean>>({
  pending_review: false,
  approved: false,
})

interface StepSummary { count: number; satang: number }
const stepTotals = ref<Record<string, StepSummary>>({})
/*
 * A band that cannot be read must not print zeros: "รอโอน 0 บาท" is a statement
 * that nothing is owed, on the screen where somebody decides whether a payout
 * round is finished. When the summary fails those two steps say so instead.
 */
const stepTotalsFailed = ref(false)

/** Free-text narrowing of what is already on screen — never a server query. */
const search = ref('')

/** The company's floor for an agent's own request — read here, edited on ตั้งค่าค่าแนะนำ. */
const minWithdrawalSatang = ref<number | null>(null)
const minWithdrawalUnknown = ref(false)

// ── Loading ─────────────────────────────────────────────────────────────
async function fetchPending(): Promise<AgentSummaryItem[]> {
  const res = await api.get<{ data: AgentSummaryItem[]; computed_at: string }>(
    activeCompany.scopedPath('/agent-commission-summary?payment_status=pending'),
  )

  return res.data
}

async function fetchQueue(status: 'pending_review' | 'approved'): Promise<WithdrawalRequest[]> {
  const res = await api.get<{ data: WithdrawalRequest[] }>(
    activeCompany.scopedPath(`/commission-withdrawals?status=${status}`),
  )

  return res.data
}

async function fetchStepTotals(): Promise<void> {
  try {
    const res = await api.get<{ data: Record<string, StepSummary> }>(
      activeCompany.scopedPath('/commission-withdrawals/summary'),
    )
    stepTotals.value = res.data
    stepTotalsFailed.value = false
  } catch {
    // Does not take the screen down with it: the lists underneath still work,
    // and the band says which figures it could not read.
    stepTotals.value = {}
    stepTotalsFailed.value = true
  }
}

async function fetchMinimum(): Promise<void> {
  try {
    const res = await api.get<{ min_withdrawal_satang: number | null }>(
      activeCompany.scopedPath('/commission-withdrawal-settings'),
    )
    minWithdrawalSatang.value = res.min_withdrawal_satang
    minWithdrawalUnknown.value = false
  } catch {
    // NULL is a real answer here (no minimum); `unknown` is the separate state
    // for a read that failed. A page that printed "ไม่มีขั้นต่ำ" after a 500
    // would explain a refusal with a fact it does not have.
    minWithdrawalUnknown.value = true
  }
}

/**
 * Fetch what the screen needs and nothing it already has.
 *
 * `pending` and the band totals are ALWAYS needed — the amber bar and every
 * step's figure are on screen in every group. A queue list is fetched the first
 * time its step is opened and then kept, so stepping back and forth does not
 * re-request.
 */
async function ensureLoaded(force = false): Promise<void> {
  const wanted: 'pending_review' | 'approved' | null =
    group.value === 'review' ? 'pending_review' : group.value === 'transfer' ? 'approved' : null

  const needPending = force || !pendingLoaded.value
  const needQueue = wanted !== null && (force || !queueLoaded.value[wanted])
  // Refreshed on any forced reload: a press moves money between steps, and the
  // band is visible from every group.
  const needTotals = force || !hasLoadedOnce.value

  if (!needPending && !needQueue && !needTotals) return

  loading.value = true
  errorMessage.value = ''
  try {
    if (needPending) {
      pendingRows.value = await fetchPending()
      pendingLoaded.value = true
    }
    if (needQueue && wanted) {
      queue.value[wanted] = await fetchQueue(wanted)
      queueLoaded.value[wanted] = true
    }
    if (needTotals) await fetchStepTotals()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

/**
 * A write happened: everything on screen is stale.
 *
 * Both queue lists are dropped, not just the one being looked at — approving a
 * request moves it from step ② to step ③, and a cached step ③ would be missing
 * the row the admin just sent there.
 */
async function reloadAll(): Promise<void> {
  queueLoaded.value = { pending_review: false, approved: false }
  await ensureLoaded(true)
}

onMounted(() => {
  void ensureLoaded()
  void fetchMinimum()
})

/**
 * Switching steps drops both selections.
 *
 * A tick is an agreement about a named person and a named amount — "จะจ่ายคนนี้"
 * in step ①, "คนนี้โอนแล้ว" in step ③. Carried across, it would be confirmed
 * from a screen showing different names, a different total and a different verb.
 */
function openGroup(next: Group): void {
  if (group.value === next) return

  group.value = next
  clearSelections()
  ensureLoaded()
}

function clearSelections(): void {
  selectedAgents.value = new Set()
  selectedRequests.value = new Set()
  batchError.value = ''
  confirming.value = false
  rejectingId.value = null
}

// ── Step ①: who can be paid ─────────────────────────────────────────────
/** The amount a NEW payout for this payee would be for. */
function availableOf(s: AgentSummaryItem): number | null {
  /*
   * Falls back to the pending figure only when the server did not send the
   * field at all — an older API during a rolling deploy. `?? 0` would be wrong
   * in the other direction (it would empty the work step); this keeps the
   * screen working and merely un-improved.
   */
  return s.available_satang === undefined ? s.total_pending_satang : s.available_satang
}

function isPayable(s: AgentSummaryItem): boolean {
  return (availableOf(s) ?? 0) > 0 && s.payout_details_complete === true
}

/** Owed money that could be raised, if only the payee's details were complete. */
function isBlocked(s: AgentSummaryItem): boolean {
  return (availableOf(s) ?? 0) > 0 && s.payout_details_complete !== true
}

const payableRows = computed(() => pendingRows.value.filter(isPayable))
const blockedRows = computed(() => pendingRows.value.filter(isBlocked))

/**
 * Was the pending bucket measured at all?
 *
 * Checked on the WHOLE result set rather than on the filtered step, because a
 * step filtered by `available > 0` is empty exactly when the bucket is null —
 * and an empty list summed with `.reduce()` yields 0, which is the F-10 defect
 * wearing a different hat: "nobody measured this" rendered as "0 บาท".
 */
const pendingMeasured = computed(() => pendingRows.value.every((s) => s.total_pending_satang !== null))

const payableSatang = computed(() =>
  pendingMeasured.value ? payableRows.value.reduce((t, s) => t + (availableOf(s) ?? 0), 0) : null,
)
const blockedSatang = computed(() =>
  pendingMeasured.value ? blockedRows.value.reduce((t, s) => t + (availableOf(s) ?? 0), 0) : null,
)

/**
 * Why this payee cannot be ticked, for the rows in กลุ่มติดปัญหา.
 *
 * Said on the row rather than after a failed press: the reason is fixable from
 * this screen, and finding it out from a 422 after ticking eight people is the
 * expensive way to learn it.
 */
function blockedReason(s: AgentSummaryItem): string {
  if (!isBlocked(s)) return ''

  return s.is_company_share === true
    ? 'ยังไม่ได้กรอกบัญชีรับเงินของบริษัท'
    : 'ยังกรอกบัญชีธนาคารหรือเอกสารยืนยันตัวตนไม่ครบ'
}

// ── The band ────────────────────────────────────────────────────────────
function totalsFor(status: string): StepSummary | null {
  return stepTotalsFailed.value ? null : (stepTotals.value[status] ?? { count: 0, satang: 0 })
}

const steps = computed(() => {
  const review = totalsFor('pending_review')
  const transfer = totalsFor('approved')

  return [
    {
      key: 'payable' as const,
      n: 1,
      label: 'ตั้งจ่ายได้เลย',
      satang: payableSatang.value,
      count: payableRows.value.length,
      unit: 'คน',
      note: 'คุณเป็นคนกด',
    },
    {
      key: 'review' as const,
      n: 2,
      label: 'รอคุณตรวจสอบ',
      satang: review?.satang ?? null,
      count: review?.count ?? null,
      unit: 'ใบ',
      note: 'ตัวแทนกดขอเบิกเอง',
    },
    {
      key: 'transfer' as const,
      n: 3,
      label: 'รอฝ่ายบัญชีโอน',
      satang: transfer?.satang ?? null,
      count: transfer?.count ?? null,
      unit: 'ใบ',
      note: 'รอบัญชีแจ้งกลับ',
    },
  ]
})

/** The exit tile. Not a step: nothing on it can be acted on. */
const transferredTotals = computed(() => totalsFor('transferred'))

// ── The rows currently on screen ────────────────────────────────────────
const requestRows = computed<WithdrawalRequest[]>(() =>
  group.value === 'review'
    ? queue.value.pending_review
    : group.value === 'transfer'
      ? queue.value.approved
      : [],
)

const peopleRows = computed<AgentSummaryItem[]>(() =>
  group.value === 'blocked' ? blockedRows.value : group.value === 'payable' ? payableRows.value : [],
)

const showsPeople = computed(() => GROUPS[group.value].unit === 'people')

function matchesSearch(name: string | null): boolean {
  const needle = search.value.trim().toLowerCase()

  return needle === '' || (name ?? '').toLowerCase().includes(needle)
}

const visiblePeople = computed(() => peopleRows.value.filter((s) => matchesSearch(s.agent_name)))
const visibleRequests = computed(() => requestRows.value.filter((r) => matchesSearch(r.agent_name)))
const visibleCount = computed(() => (showsPeople.value ? visiblePeople.value.length : visibleRequests.value.length))
const groupIsEmpty = computed(() =>
  showsPeople.value ? peopleRows.value.length === 0 : requestRows.value.length === 0,
)

// ── Selections ──────────────────────────────────────────────────────────
/*
 * TWO selections, not one polymorphic set. A tick in step ① is an agent_id and
 * a tick in step ③ is a withdrawal-request id, and the two id spaces overlap —
 * one Set holding both would silently mark the wrong row the first time agent 7
 * and request 7 were on screen in the same session.
 */
const selectedAgents = ref<Set<number>>(new Set())
const selectedRequests = ref<Set<number>>(new Set())

const canSelectPeople = computed(() => group.value === 'payable')
const canSelectRequests = computed(() => group.value === 'transfer')

function toggleAgent(s: AgentSummaryItem): void {
  if (!canSelectPeople.value || !isPayable(s)) return

  // A NEW Set, not .add() on the existing one: Vue tracks the ref's value, and
  // mutating the same object in place leaves every computed below it reading a
  // stale answer.
  const next = new Set(selectedAgents.value)
  next.has(s.agent_id) ? next.delete(s.agent_id) : next.add(s.agent_id)
  selectedAgents.value = next
  batchError.value = ''
}

function toggleRequest(r: WithdrawalRequest): void {
  if (!canSelectRequests.value) return

  const next = new Set(selectedRequests.value)
  next.has(r.id) ? next.delete(r.id) : next.add(r.id)
  selectedRequests.value = next
  batchError.value = ''
}

const selectableAgents = computed(() => visiblePeople.value.filter(isPayable))
const allAgentsSelected = computed(() =>
  selectableAgents.value.length > 0 && selectableAgents.value.every((s) => selectedAgents.value.has(s.agent_id)),
)
const allRequestsSelected = computed(() =>
  visibleRequests.value.length > 0 && visibleRequests.value.every((r) => selectedRequests.value.has(r.id)),
)

function toggleAllAgents(): void {
  selectedAgents.value = allAgentsSelected.value
    ? new Set()
    : new Set(selectableAgents.value.map((s) => s.agent_id))
  batchError.value = ''
}

function toggleAllRequests(): void {
  selectedRequests.value = allRequestsSelected.value
    ? new Set()
    : new Set(visibleRequests.value.map((r) => r.id))
  batchError.value = ''
}

const selectedAgentRows = computed(() =>
  // Read back off the CURRENT rows rather than kept as objects when ticked: a
  // reload between the tick and the press must not pay a figure that is no
  // longer on screen.
  pendingRows.value.filter((s) => selectedAgents.value.has(s.agent_id) && isPayable(s)),
)
const selectedRequestRows = computed(() =>
  queue.value.approved.filter((r) => selectedRequests.value.has(r.id)),
)

const selectedTotalSatang = computed(() =>
  canSelectRequests.value
    ? selectedRequestRows.value.reduce((sum, r) => sum + r.amount_satang, 0)
    // The AVAILABLE figure, not what is owed: it is what the press will
    // actually raise, and what the server will check it against.
    : selectedAgentRows.value.reduce((sum, s) => sum + (availableOf(s) ?? 0), 0),
)
const selectedCount = computed(() =>
  canSelectRequests.value ? selectedRequestRows.value.length : selectedAgentRows.value.length,
)

// ── The presses ─────────────────────────────────────────────────────────
const confirming = ref(false)
const working = ref(false)
const batchError = ref('')
const batchDone = ref<string>('')
/** One reference for the whole round — see MarkWithdrawalsTransferredRequest. */
const transferReference = ref('')

function askToPay(): void {
  batchError.value = ''
  batchDone.value = ''
  confirming.value = true
}

/**
 * "ตั้งจ่ายคนนี้" / "บันทึกว่าโอนแล้ว" on a single row.
 *
 * It WRITES NOTHING. It replaces the selection with this one row and opens the
 * same confirmation the batch press opens — same endpoint, same payload shape,
 * same audit trail. There is still exactly one way to do each of these things;
 * this is a shortcut into it for the case that is actually common, which is one
 * at a time.
 *
 * Replaces rather than adds, on purpose: pressing it on a row while three
 * others are ticked has to mean what it says, not "and also those three" — the
 * confirmation would name a total the presser never chose.
 */
function askToPayOne(s: AgentSummaryItem): void {
  if (!isPayable(s)) return

  selectedAgents.value = new Set([s.agent_id])
  askToPay()
}

function askToTransferOne(r: WithdrawalRequest): void {
  selectedRequests.value = new Set([r.id])
  askToPay()
}

async function submitBatch(): Promise<void> {
  if (working.value || selectedCount.value === 0) return

  working.value = true
  batchError.value = ''
  const attempted = selectedCount.value
  const attemptedSatang = selectedTotalSatang.value

  try {
    if (canSelectRequests.value) {
      await api.post('/commission-withdrawals/mark-transferred-batch', {
        withdrawal_request_ids: selectedRequestRows.value.map((r) => r.id),
        transfer_reference: transferReference.value.trim() || null,
      })
      batchDone.value = `บันทึกว่าโอนแล้ว ${attempted} ใบ รวม ${formatSatang(attemptedSatang)} — ปิดรายการค่าคอมและแจ้งตัวแทนเรียบร้อย`
      transferReference.value = ''
    } else {
      await api.post('/commission-withdrawals/payout-batch', {
        payees: selectedAgentRows.value.map((s) => ({
          agent_id: s.agent_id,
          /*
           * The AVAILABLE balance, which is what
           * CommissionWithdrawalService::availableSatang() compares it against.
           * Sending the pending total instead is what produced the permanent
           * "0.00 ไม่ตรงกับ 1,046.50" loop in September.
           */
          expected_total_satang: availableOf(s),
        })),
      })
      batchDone.value = `ตั้งจ่าย ${attempted} คน รวม ${formatSatang(attemptedSatang)} เรียบร้อย — ไปรออยู่ที่ขั้นที่ 3 รอฝ่ายบัญชีโอน`
    }

    confirming.value = false
    clearSelections()
    await reloadAll()
  } catch (e) {
    // The server's own sentence: for a stale total it names both figures and
    // says what to do, which no generic copy here could replace.
    batchError.value = e instanceof ApiError ? e.message : 'ดำเนินการไม่สำเร็จ'
  } finally {
    working.value = false
  }
}

// ── Step ②: approve / reject ────────────────────────────────────────────
const busyId = ref<number | null>(null)
const rejectingId = ref<number | null>(null)
const rejectReason = ref('')

async function decide(r: WithdrawalRequest, action: 'approve' | 'reject', body?: Record<string, unknown>): Promise<void> {
  if (busyId.value !== null) return

  busyId.value = r.id
  errorMessage.value = ''
  try {
    await api.post(`/commission-withdrawals/${r.id}/${action}`, body ?? {})
    rejectingId.value = null
    rejectReason.value = ''
    await reloadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'ดำเนินการไม่สำเร็จ'
  } finally {
    busyId.value = null
  }
}

/**
 * The reason is asked for in the ROW, not in a browser prompt.
 *
 * It is required by the server and shown to the agent verbatim, which makes it
 * a piece of writing — and window.prompt gives a single line with no wrapping,
 * no editing and no sight of the request it is about. It was also the second
 * modal on this screen; the transfer reference used to be the other one.
 */
function openReject(r: WithdrawalRequest): void {
  rejectingId.value = rejectingId.value === r.id ? null : r.id
  rejectReason.value = ''
  errorMessage.value = ''
}

function submitReject(r: WithdrawalRequest): void {
  if (!rejectReason.value.trim()) {
    errorMessage.value = 'กรุณาระบุเหตุผลที่ไม่อนุมัติ — ตัวแทนจะเห็นข้อความนี้'

    return
  }

  void decide(r, 'reject', { rejection_reason: rejectReason.value.trim() })
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
    // Completing an account moves this payee out of ติดปัญหา and into ขั้นที่ 1,
    // which is the whole reason somebody opened this form.
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

async function toggleDetail(agent: AgentSummaryItem) {
  if (detailAgentId.value === agent.agent_id) {
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
    const [ledgerRes, userRes] = await Promise.all([
      api.get<{ data: LedgerItem[]; meta?: { total: number } }>(
        activeCompany.scopedPath(`/commission-ledger?agent_id=${agent.agent_id}`),
      ),
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
function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' }) : '—'
}

// ── The bank file ───────────────────────────────────────────────────────
/**
 * The payout CSV — every baht still owed, full account numbers, made to be
 * opened and acted on.
 *
 * Offered on step ① only, and with no date window at all. The endpoint forces
 * payment_status=pending server-side because a file you pay people from must
 * never contain money that has already moved; a date range on top of that would
 * produce a bank file that silently omits somebody who is owed.
 *
 * This is NOT the report's export. That one is one row per REQUEST, every
 * status, masked accounts, made to be filed — see รายงานการจ่าย.
 */
const exporting = ref(false)
async function exportCsv() {
  exporting.value = true
  errorMessage.value = ''
  try {
    await api.download('/agent-commission-summary/export')
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ส่งออก CSV ไม่สำเร็จ (${e.status})` : 'ส่งออก CSV ไม่สำเร็จ'
  } finally {
    exporting.value = false
  }
}

/**
 * TASK-179 §3.7 (F-10) — the wording for a bucket that was never measured.
 *
 * Deliberately NOT a number and NOT "0 บาท": the point of the backend returning
 * null is that the UI must be unable to present an unmeasured bucket as a
 * monetary fact.
 */
const UNMEASURED_LABEL = 'ไม่ได้แสดง'
function formatSatangOrUnmeasured(satang: number | null): string {
  return satang === null ? UNMEASURED_LABEL : formatSatang(satang)
}

/**
 * Sum a bucket across rows — or report that it was not measured.
 *
 * Returns null the moment ANY row's bucket is null, rather than skipping those
 * rows: the filter excludes the bucket for the whole request, so a partial sum
 * would be a company-wide total assembled from a subset nobody defined.
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
 * ONE KPI, because the band carries the rest.
 *
 * What survives is the one number nothing else on the screen says: the WHOLE
 * outstanding liability, which is neither what can be raised today (step ①, net
 * of what is in flight) nor what is waiting at any one step.
 */
const kpis = computed(() => [
  {
    label: 'ค้างจ่ายรวมทั้งหมด',
    value: formatSatangOrUnmeasured(sumBucket(pendingRows.value, (s) => s.total_pending_satang)),
  },
])

// BR-3 — satang in, baht out. Divide by 100 only here, at the display layer.
function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}

// TASK-209 — every list is scoped server-side, so a change of the header
// company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => {
  clearSelections()
  pendingLoaded.value = false
  void reloadAll()
  void fetchMinimum()
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8 pb-28">
    <HeroHeader
      icon="money"
      title="จ่ายค่าแนะนำ"
      subtitle="งานทั้งเส้นอยู่หน้านี้ — กดที่ขั้นเพื่อสลับกลุ่ม แล้วทำให้จบทีละขั้น"
      description="ตั้งจ่าย → ตรวจสอบคำขอของตัวแทน → ส่งฝ่ายบัญชีโอน → กลับมากดบันทึกว่าโอนแล้ว · ค่าแนะนำจะถูกปิดและตัวแทนจะได้อีเมลก็ต่อเมื่อกดขั้นสุดท้ายเท่านั้น"
      :kpis="kpis"
      accent-color="brand"
      storage-key="commission-payouts"
    />

    <CompanyScopeNotice action="ดูและจ่ายค่าแนะนำ" />

    <div class="mt-4 flex flex-wrap items-center gap-3">
      <label class="relative">
        <span class="sr-only">ค้นหาชื่อผู้รับ</span>
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
        <!--
          The bank file, on the work step only. It is a list of people to pay,
          so it has no place under a list of documents already raised.
        -->
        <button
          v-if="group === 'payable'"
          type="button"
          :disabled="exporting"
          title="ไฟล์สำหรับโอนจ่ายจริง — ทุกยอดที่ยังค้างจ่าย พร้อมเลขบัญชีเต็ม"
          class="h-9 px-3 rounded-xl border border-slate-200 text-slate-600 text-[12.5px] font-bold hover:bg-slate-50 flex items-center gap-1.5 disabled:opacity-50"
          data-test="payout-export"
          @click="exportCsv"
        >
          <Icon name="download" :size="14" />
          {{ exporting ? 'กำลังส่งออก...' : 'ไฟล์สำหรับโอน (CSV)' }}
        </button>
      </div>
    </div>

    <!--
      ═══ กลุ่มติดปัญหา — ABOVE THE BAND, NOT ON IT ═══

      These people have not entered the conveyor at all; that is WHY they are
      stuck. Putting them on the band as a step would draw a flow that does not
      exist. This is also the only thing on the screen that has to be noticed
      without being looked for.

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
          The one repair that cannot be made from this screen: the seat is not a
          person, and PUT /users/{id} refuses it. Offered only when the
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
      ═══ THE BAND: ONE ERRAND, THREE STEPS, THEN A DOOR ═══

      Each step carries MONEY rather than a count, because the question this
      screen is opened to answer is about money. The step in view has a heavy
      border and says "กำลังดู", and the list underneath it IS that step.

      The green tile at the end is not a step. It has no number badge, no
      "กำลังดู" state and a dashed border, because nothing on it can be acted on
      — it carries the figure and opens รายงานการจ่าย.
    -->
    <div class="mt-4 flex flex-col lg:flex-row items-stretch gap-2" role="tablist" data-test="payout-step-band">
      <template v-for="(step, i) in steps" :key="step.key">
        <div v-if="i > 0" class="hidden lg:flex items-center px-1 text-slate-300 shrink-0">
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
            <span class="text-[13px] font-bold" :class="group === step.key ? 'text-brand-700' : 'text-slate-700'">
              {{ step.label }}
            </span>
            <span v-if="group === step.key" class="ml-auto text-[11px] font-extrabold text-brand-700">กำลังดู</span>
          </div>
          <p
            class="mt-1.5 text-[21px] font-extrabold tabular-nums leading-tight"
            :data-test="`payout-step-amount-${step.key}`"
          >
            <template v-if="step.satang !== null">{{ formatSatang(step.satang) }}</template>
            <span v-else class="text-[15px] font-bold text-slate-400">{{ UNMEASURED_LABEL }}</span>
          </p>
          <p class="mt-0.5 text-[11.5px] text-slate-500">
            <template v-if="step.count !== null">{{ step.count }} {{ step.unit }} · </template>{{ step.note }}
          </p>
        </button>
      </template>

      <div class="hidden lg:flex items-center px-1 text-slate-300 shrink-0">
        <Icon name="chevron-right" :size="18" />
      </div>
      <RouterLink
        :to="{ name: 'payout-report' }"
        class="lg:w-[250px] px-4 py-3 rounded-2xl border border-dashed border-emerald-300 bg-emerald-50 hover:bg-emerald-100 transition block"
        data-test="payout-transferred-tile"
      >
        <div class="flex items-center gap-2">
          <Icon name="check" :size="15" class="text-emerald-700" />
          <span class="text-[13px] font-bold text-emerald-800">โอนแล้ว</span>
        </div>
        <p class="mt-1.5 text-[21px] font-extrabold tabular-nums leading-tight text-emerald-700">
          <template v-if="transferredTotals">{{ formatSatang(transferredTotals.satang) }}</template>
          <span v-else class="text-[15px] font-bold text-slate-400">{{ UNMEASURED_LABEL }}</span>
        </p>
        <p class="mt-0.5 text-[11.5px] font-extrabold text-emerald-700">ดูรายงานการจ่าย →</p>
      </RouterLink>
    </div>

    <p v-if="stepTotalsFailed" class="mt-2 text-[12px] font-bold text-rose-600" data-test="payout-band-error">
      อ่านยอดรวมของแต่ละขั้นไม่สำเร็จ — รายชื่อด้านล่างยังใช้งานได้ตามปกติ
    </p>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700" data-test="payout-error">
      {{ errorMessage }}
    </div>

    <p
      v-if="batchDone"
      class="mt-4 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-sm font-bold text-emerald-700"
      data-test="payout-batch-done"
    >
      {{ batchDone }}
    </p>

    <!--
      The list says which step it is and WHAT A ROW IS, in words, right above
      itself. The unit badge is not decoration: the same person can be a row in
      step ① for 500 and a row in step ③ for 1,500 at the same time, and a reader
      who assumed the band followed the same people would read that as a
      contradiction.
    -->
    <div class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1">
      <h2 class="text-[13.5px] font-extrabold text-slate-800" data-test="payout-group-heading">
        {{ GROUPS[group].label }}
        <span class="font-bold text-slate-400">· {{ visibleCount }} {{ showsPeople ? 'คน' : 'ใบ' }}</span>
      </h2>
      <span
        class="px-2 py-0.5 rounded-full text-[10.5px] font-extrabold"
        :class="showsPeople ? 'bg-slate-200 text-slate-600' : 'bg-amber-100 text-amber-800'"
        data-test="payout-unit-badge"
      >
        {{ showsPeople ? 'หน่วย: คน' : 'หน่วย: ใบคำขอ' }}
      </span>
      <span v-if="canSelectPeople && selectableAgents.length" class="text-[12px] text-slate-400">
        คลิกที่แถวเพื่อเลือกคนที่จะจ่าย
      </span>
      <span v-else-if="canSelectRequests && visibleRequests.length" class="text-[12px] text-slate-400">
        ติ๊กใบที่ฝ่ายบัญชีแจ้งว่าโอนแล้ว
      </span>
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
        v-if="groupIsEmpty"
        :icon="group === 'blocked' ? 'shield' : 'money'"
        :title="group === 'payable' ? 'ไม่มีใครที่ตั้งจ่ายได้ตอนนี้'
          : group === 'blocked' ? 'ไม่มีใครติดปัญหา'
          : group === 'review' ? 'ไม่มีคำขอรอตรวจสอบ'
          : 'ไม่มีใบที่รอฝ่ายบัญชีโอน'"
        :message="group === 'review' ? 'รายการจะเข้ามาเมื่อตัวแทนกดขอเบิกเอง' : ''"
        class="mt-3"
        data-test="payout-group-empty"
      />
      <EmptyState
        v-else-if="visibleCount === 0"
        icon="search"
        title="ไม่พบชื่อที่ค้นหา"
        class="mt-3"
      />

      <!-- ═══ STEPS ① AND ติดปัญหา — A TABLE OF PEOPLE ═══ -->
      <div v-else-if="showsPeople" class="mt-3 bg-white/95 border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-slate-50 text-left text-[11.5px] font-bold text-slate-500 border-b border-slate-200">
              <!-- The column has a NAME. An unlabelled checkbox column is only
                   obvious to somebody who already knows the screen works by
                   selection, which is exactly the person it did not need to
                   tell. -->
              <th v-if="canSelectPeople" class="w-16 py-2.5 pl-4 font-bold">
                <input
                  type="checkbox"
                  class="w-4 h-4 rounded accent-brand-600"
                  :checked="allAgentsSelected"
                  :disabled="!selectableAgents.length"
                  aria-label="เลือกทั้งหมดที่ตั้งจ่ายได้"
                  data-test="payout-select-all"
                  @change="toggleAllAgents"
                />
                <span class="ml-1.5 align-middle">เลือก</span>
              </th>
              <th class="py-2.5 px-3">ผู้รับ</th>
              <th class="py-2.5 px-3 w-24">รายการ</th>
              <th class="py-2.5 px-3 w-64">บัญชีธนาคาร</th>
              <th class="py-2.5 px-3 w-40 text-right">ยอดค้างจ่าย</th>
              <th class="py-2.5 pr-4 w-28"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="s in visiblePeople" :key="s.agent_id">
              <!--
                THE WHOLE ROW IS THE TARGET. A 16px checkbox at the far left of
                a full-width row is a small target and a quiet one; the row is
                what people aim at. The checkbox stays and stays the accessible
                control. Every interactive child stops the event, or clicking it
                would also tick the row underneath.
              -->
              <tr
                class="border-b border-slate-100 last:border-0 transition-colors"
                :class="[
                  s.is_company_share ? 'bg-emerald-50/40' : '',
                  selectedAgents.has(s.agent_id) ? 'bg-brand-50' : '',
                  canSelectPeople && isPayable(s) ? 'cursor-pointer hover:bg-brand-50/60' : '',
                ]"
                :data-test="s.is_company_share ? `company-share-row-${s.agent_id}` : `payout-row-${s.agent_id}`"
                @click="toggleAgent(s)"
              >
                <td v-if="canSelectPeople" class="py-3 pl-4 align-top" @click.stop>
                  <input
                    type="checkbox"
                    class="w-4 h-4 mt-1 rounded accent-brand-600 disabled:opacity-40"
                    :checked="selectedAgents.has(s.agent_id)"
                    :disabled="!isPayable(s)"
                    :aria-label="`เลือก ${s.agent_name ?? ''}`"
                    :data-test="`payout-select-${s.agent_id}`"
                    @change="toggleAgent(s)"
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
                        OUT. It is payable like anybody else now, so it belongs
                        in the list it is paid from; an admin reconciling a
                        transfer file still has to be able to tell that this
                        line is not a person.
                      -->
                      <p v-if="s.is_company_share" class="text-[11px] font-extrabold text-emerald-700 mt-0.5">
                        ส่วนของบริษัท · ค่าแนะนำที่บริษัทได้ในฐานะหัวหน้าสายงาน
                      </p>
                      <p v-if="blockedReason(s)" class="text-[11.5px] text-amber-700 font-bold mt-0.5" :data-test="`payout-blocked-${s.agent_id}`">
                        {{ blockedReason(s) }}
                      </p>
                      <!--
                        Part of the balance is in flight, part is still
                        raisable. Without this line the ยอดค้างจ่าย column and
                        the amount the press raises differ with nothing on
                        screen accounting for the difference.
                      -->
                      <p
                        v-else-if="(s.reserved_satang ?? 0) > 0"
                        class="text-[11.5px] text-slate-500 mt-0.5"
                        :data-test="`payout-reserved-${s.agent_id}`"
                      >
                        ตั้งจ่ายแล้วรอโอน {{ formatSatang(s.reserved_satang ?? 0) }} ·
                        ตั้งจ่ายเพิ่มได้ {{ formatSatang(availableOf(s) ?? 0) }}
                        <button type="button" class="font-bold text-brand-600 hover:underline" @click.stop="openGroup('transfer')">ดูขั้นที่ 3</button>
                      </p>
                    </div>
                  </div>
                </td>

                <td class="py-3 px-3 text-slate-500 whitespace-nowrap align-top">{{ s.entry_count }} รายการ</td>

                <td class="py-3 px-3 align-top">
                  <!-- The seat is not a person: PUT /users/{id} refuses it, so
                       its account is edited where it was created. -->
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
                  class="py-3 px-3 text-right tabular-nums align-top"
                  :class="s.total_pending_satang === null ? 'text-slate-400' : 'text-amber-600 font-bold'"
                >
                  {{ formatSatangOrUnmeasured(s.total_pending_satang) }}
                </td>

                <td class="py-3 pr-4 text-right align-top" @click.stop>
                  <button
                    v-if="canSelectPeople && isPayable(s)"
                    type="button"
                    class="inline-flex items-center gap-1 h-8 px-3 rounded-lg bg-brand-600 text-white text-[12px] font-extrabold hover:bg-brand-700 whitespace-nowrap"
                    :data-test="`payout-pay-one-${s.agent_id}`"
                    @click="askToPayOne(s)"
                  >
                    <Icon name="money" :size="13" />
                    ตั้งจ่ายคนนี้
                  </button>
                  <!--
                    กลุ่มติดปัญหา is a list of REPAIRS, so the row carries the
                    way to make one.
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
                <td :colspan="canSelectPeople ? 6 : 5" class="px-4 py-3">
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
                <td :colspan="canSelectPeople ? 6 : 5" class="px-4 py-3">
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
                          <!-- The two columns that used to live only on
                               รายรายการ. Without them an override and a direct
                               commission on the same sale are two identical
                               lines with different amounts. -->
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

      <!-- ═══ STEPS ② AND ③ — A LIST OF REQUESTS ═══
           Cards rather than a table, because a request carries prose (whose
           decision, which snapshot account, why it was refused) that a row of
           columns turns into abbreviations. -->
      <div v-else class="mt-3 space-y-2">
        <div
          v-if="canSelectRequests && visibleRequests.length > 1"
          class="flex items-center gap-2 px-1"
        >
          <input
            type="checkbox"
            class="w-4 h-4 rounded accent-emerald-600"
            :checked="allRequestsSelected"
            aria-label="เลือกทุกใบในขั้นนี้"
            data-test="payout-select-all-requests"
            @change="toggleAllRequests"
          />
          <span class="text-[12.5px] font-bold text-slate-600">เลือกทั้งหมด {{ visibleRequests.length }} ใบ</span>
        </div>

        <template v-for="r in visibleRequests" :key="r.id">
          <div
            class="bg-white border rounded-2xl p-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 transition-colors"
            :class="[
              selectedRequests.has(r.id) ? 'bg-emerald-50 border-emerald-300' : 'border-slate-200',
              canSelectRequests ? 'cursor-pointer hover:border-emerald-300' : '',
            ]"
            :data-test="`payout-request-${r.id}`"
            @click="toggleRequest(r)"
          >
            <div class="flex items-start gap-3 min-w-0">
              <input
                v-if="canSelectRequests"
                type="checkbox"
                class="w-4 h-4 mt-1.5 rounded accent-emerald-600 shrink-0"
                :checked="selectedRequests.has(r.id)"
                :aria-label="`เลือกใบของ ${r.agent_name ?? ''}`"
                :data-test="`payout-request-select-${r.id}`"
                @click.stop
                @change="toggleRequest(r)"
              />
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <p class="text-[17px] font-extrabold text-slate-900 tabular-nums">{{ formatSatang(r.amount_satang) }}</p>
                  <span class="px-2 py-0.5 rounded-full text-[10.5px] font-extrabold bg-slate-100 text-slate-600">
                    {{ r.status_label }}
                  </span>
                  <!--
                    WHOSE DECISION THIS WAS. In ขั้นที่ 3 an agent's approved
                    request and a payout the company raised sit side by side,
                    and they are answerable to different people: one was agreed
                    to by whoever approved it, the other by whoever pressed
                    ตั้งจ่าย.
                  -->
                  <span
                    class="px-2 py-0.5 rounded-full text-[10.5px] font-extrabold"
                    :class="r.source === 'company_payout' ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-600'"
                    :data-test="`payout-source-${r.id}`"
                  >
                    {{ r.source_label }}
                  </span>
                </div>
                <p class="text-[13px] font-bold text-slate-700 mt-1">{{ r.agent_name ?? '—' }}</p>
                <p class="text-[11.5px] text-slate-500 mt-0.5">
                  {{ r.source === 'company_payout' ? 'ตั้งจ่ายเมื่อ' : 'ขอเมื่อ' }} {{ formatDate(r.created_at) }}
                  <span v-if="r.item_count"> · {{ r.item_count }} รายการค่าคอม</span>
                </p>
                <p v-if="r.bank_account_number_masked" class="text-[11.5px] text-slate-500 mt-0.5">
                  {{ r.bank_name }} {{ r.bank_account_number_masked }} · {{ r.bank_account_holder_name }}
                  <span class="text-slate-400">(บันทึกไว้ตอนยื่นใบนี้)</span>
                </p>
                <p v-if="r.decided_at" class="text-[11.5px] text-slate-500 mt-0.5">
                  ตัดสินใจโดย {{ r.decided_by ?? '—' }} เมื่อ {{ formatDate(r.decided_at) }}
                </p>
              </div>
            </div>

            <div class="flex flex-wrap gap-2 shrink-0" @click.stop>
              <template v-if="group === 'review'">
                <button
                  type="button"
                  :disabled="busyId !== null"
                  class="h-9 px-4 rounded-xl bg-slate-900 text-white text-[12.5px] font-extrabold disabled:opacity-50"
                  :data-test="`payout-approve-${r.id}`"
                  @click="decide(r, 'approve')"
                >
                  อนุมัติ
                </button>
                <button
                  type="button"
                  :disabled="busyId !== null"
                  class="h-9 px-4 rounded-xl border border-rose-200 text-rose-700 text-[12.5px] font-extrabold disabled:opacity-50"
                  :data-test="`payout-reject-${r.id}`"
                  @click="openReject(r)"
                >
                  ไม่อนุมัติ
                </button>
              </template>
              <button
                v-else
                type="button"
                :disabled="working"
                class="h-9 px-4 rounded-xl bg-emerald-600 text-white text-[12.5px] font-extrabold disabled:opacity-50"
                :data-test="`payout-transfer-one-${r.id}`"
                @click="askToTransferOne(r)"
              >
                บันทึกว่าโอนแล้ว
              </button>
            </div>
          </div>

          <!--
            The refusal reason, in the row. It is required by the server and
            shown to the agent verbatim, which makes it a piece of writing —
            window.prompt gives one line, no wrapping, no editing, and hides the
            request it is about.
          -->
          <div
            v-if="rejectingId === r.id"
            :key="`reject-${r.id}`"
            class="px-4 py-3 rounded-2xl bg-rose-50 border border-rose-200"
            :data-test="`payout-reject-panel-${r.id}`"
          >
            <p class="text-[12.5px] font-bold text-rose-800">
              เหตุผลที่ไม่อนุมัติคำขอของ {{ r.agent_name ?? 'ตัวแทน' }} — ตัวแทนจะเห็นข้อความนี้
            </p>
            <textarea
              v-model="rejectReason"
              rows="2"
              class="mt-2 w-full px-3 py-2 rounded-xl border border-rose-200 text-sm bg-white"
              placeholder="เช่น ยอดไม่ตรงกับที่ตรวจสอบ กรุณาติดต่อฝ่ายบัญชี"
              :data-test="`payout-reject-reason-${r.id}`"
            ></textarea>
            <div class="mt-2 flex items-center gap-2">
              <button
                type="button"
                :disabled="busyId !== null"
                class="h-9 px-4 rounded-xl bg-rose-600 text-white text-[12.5px] font-extrabold disabled:opacity-50"
                :data-test="`payout-reject-submit-${r.id}`"
                @click="submitReject(r)"
              >
                ยืนยันไม่อนุมัติ
              </button>
              <button
                type="button"
                class="h-9 px-4 rounded-xl border border-slate-200 text-slate-600 text-[12.5px] font-bold"
                @click="rejectingId = null"
              >
                ยกเลิก
              </button>
            </div>
          </div>
        </template>

        <!--
          The floor, as a footnote on the step it explains. Read-only: it is
          edited on ตั้งค่าค่าแนะนำ with every other commission setting, and it
          answers "why was that request refused" — a question nobody asks until
          a refusal has happened.
        -->
        <p v-if="group === 'review'" class="pt-2 text-[11.5px] text-slate-400" data-test="withdrawal-minimum-readout">
          <span v-if="minWithdrawalUnknown" class="text-rose-600 font-bold">อ่านยอดขั้นต่ำในการเบิกไม่สำเร็จ</span>
          <template v-else>
            ยอดขั้นต่ำในการเบิกของบริษัทนี้:
            <b class="font-bold text-slate-500">
              <span v-if="minWithdrawalSatang === null">ไม่มีขั้นต่ำ — ตัวแทนเบิกเท่าไรก็ได้</span>
              <span v-else>{{ formatSatang(minWithdrawalSatang) }}</span>
            </b>
          </template>
        </p>
      </div>
    </template>

    <!--
      ═══ THE SELECTION BAR ═══
      It carries the running total because that figure — not the number of rows
      — is what an admin checks against the transfer they are about to ask for,
      or the one accounting has just reported.

      It is there BEFORE anything is ticked, and says what to do while the
      selection is empty: it used to appear only once something was selected,
      which hid the one control that would have explained the screen from
      exactly the person who had not worked it out.

      Its VERB and its COLOUR change with the step, because the two presses are
      not the same act — blue raises a payout that settles nothing, green closes
      commission ledger rows and emails agents.
    -->
    <div
      v-if="(canSelectPeople && selectableAgents.length) || (canSelectRequests && visibleRequests.length)"
      class="sticky bottom-4 mt-5 z-20 rounded-2xl shadow-xl px-5 py-4"
      :class="selectedCount
        ? (canSelectRequests ? 'bg-emerald-800 text-white' : 'bg-brand-700 text-white')
        : 'bg-white border border-slate-200'"
      data-test="payout-selection-bar"
    >
      <div v-if="!selectedCount" class="flex flex-wrap items-center gap-x-4 gap-y-2" data-test="payout-selection-empty">
        <Icon name="money" :size="18" class="text-slate-300" />
        <span class="text-[13.5px] font-bold text-slate-600">ยังไม่ได้เลือก</span>
        <span class="text-[12.5px] text-slate-400">
          {{ canSelectRequests
            ? 'ติ๊กใบที่ฝ่ายบัญชีแจ้งว่าโอนแล้ว — เลือกได้หลายใบ ยอดรวมจะขึ้นตรงนี้'
            : 'คลิกที่แถวของคนที่จะจ่ายรอบนี้ — เลือกได้หลายคน ยอดรวมจะขึ้นตรงนี้' }}
        </span>
        <button
          type="button"
          class="ml-auto h-9 px-4 rounded-xl border border-slate-200 text-slate-600 text-[12.5px] font-bold hover:bg-slate-50"
          data-test="payout-select-all-shortcut"
          @click="canSelectRequests ? toggleAllRequests() : toggleAllAgents()"
        >
          {{ canSelectRequests ? `เลือกทั้งหมด ${visibleRequests.length} ใบ` : `เลือกทั้งหมด ${selectableAgents.length} คน` }}
        </button>
      </div>

      <div v-else class="flex flex-wrap items-center gap-x-5 gap-y-3">
        <span class="text-sm font-bold" :class="canSelectRequests ? 'text-emerald-100' : 'text-brand-100'">
          เลือกไว้ {{ selectedCount }} {{ canSelectRequests ? 'ใบ' : 'คน' }}
        </span>
        <span class="text-xl font-extrabold tabular-nums" data-test="payout-selection-total">
          {{ formatSatang(selectedTotalSatang) }}
        </span>
        <button
          type="button"
          class="text-[12.5px] font-bold hover:underline"
          :class="canSelectRequests ? 'text-emerald-100' : 'text-brand-100'"
          @click="clearSelections()"
        >
          ล้างที่เลือก
        </button>

        <!--
          The reference, inline. A round of transfers made from one bank file
          has one, and it used to be a browser prompt fired once per row.
        -->
        <input
          v-if="canSelectRequests"
          v-model="transferReference"
          type="text"
          placeholder="เลขอ้างอิงการโอน (ไม่บังคับ)"
          class="h-9 w-56 px-3 rounded-xl bg-white/15 border border-white/25 text-sm text-white placeholder:text-emerald-100/70"
          data-test="payout-transfer-reference"
          @click.stop
        />

        <div class="ml-auto flex items-center gap-2">
          <button
            v-if="!confirming"
            type="button"
            class="h-10 px-6 rounded-xl bg-white text-sm font-extrabold"
            :class="canSelectRequests ? 'text-emerald-800 hover:bg-emerald-50' : 'text-brand-700 hover:bg-brand-50'"
            data-test="payout-batch-submit"
            @click="askToPay"
          >
            {{ canSelectRequests ? `บันทึกว่าโอนแล้ว ${selectedCount} ใบ` : `ตั้งจ่าย ${selectedCount} คน` }}
          </button>
        </div>
      </div>

      <!--
        THE SECOND PRESS. It names the AMOUNT, not "are you sure" — the figure
        is what is being agreed to, and it is the last moment it can be checked.
      -->
      <div v-if="confirming" class="mt-3 pt-3 border-t border-white/20" data-test="payout-batch-confirm">
        <template v-if="canSelectRequests">
          <p class="text-[13px]">
            บันทึกว่าโอนแล้ว <b>{{ selectedCount }} ใบ</b> รวม
            <b class="tabular-nums">{{ formatSatang(selectedTotalSatang) }}</b>
            <template v-if="transferReference.trim()"> · อ้างอิง <b>{{ transferReference.trim() }}</b></template>
          </p>
          <p class="mt-1 text-[12px] text-emerald-100">
            ขั้นนี้จะ <b>ปิดรายการค่าคอมจริง</b> และ <b>ส่งอีเมลแจ้งตัวแทน</b> ว่าเงินเข้าแล้ว —
            กดเมื่อฝ่ายบัญชียืนยันว่าโอนออกจากบัญชีบริษัทแล้วเท่านั้น
          </p>
        </template>
        <template v-else>
          <p class="text-[13px]">
            ตั้งจ่ายให้ <b>{{ selectedCount }} คน</b> รวม
            <b class="tabular-nums">{{ formatSatang(selectedTotalSatang) }}</b> —
            ทั้งหมดนี้จะถูกบันทึกพร้อมกัน ถ้ามีรายใดไม่ผ่านจะไม่บันทึกเลยสักราย
          </p>
          <p class="mt-1 text-[12px] text-brand-100">
            ยังไม่ปิดรายการค่าคอม และยังไม่แจ้งตัวแทนว่าเงินเข้า — รายการจะไปรออยู่ที่ <b>ขั้นที่ 3 รอฝ่ายบัญชีโอน</b>
          </p>
        </template>
        <div class="mt-3 flex items-center gap-2">
          <button
            type="button"
            class="h-10 px-6 rounded-xl bg-white text-sm font-extrabold disabled:opacity-60"
            :class="canSelectRequests ? 'text-emerald-800' : 'text-brand-700'"
            :disabled="working"
            data-test="payout-batch-confirm-submit"
            @click="submitBatch"
          >
            {{ working ? 'กำลังบันทึก…' : (canSelectRequests ? 'ยืนยันว่าโอนแล้ว' : 'ยืนยันตั้งจ่าย') }}
          </button>
          <button
            type="button"
            class="h-10 px-5 rounded-xl border border-white/40 text-white text-sm font-bold hover:bg-white/10 disabled:opacity-60"
            :disabled="working"
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
