<script setup lang="ts">
/**
 * PipelineBoard — the cross-client stage board for the §4.3 pipeline
 * state machine, wired to the real API.
 *
 * Moved out of PipelineView.vue (TASK-169 Phase 3) so the SAME board can
 * be mounted as the second view mode of the merged ลูกค้า screen without a
 * second copy that drifts. Same pattern as ReferralRow
 * in Phase 1. The board owns its own data, its own stage filter and its own
 * audit drawer; the host contributes only a page header, and receives the
 * KPI row through `kpis-change` so the header can show it.
 *
 * "ดำเนินการขั้นถัดไป" (advance) never lets the UI pick a target stage —
 * it just calls POST /referrals/{id}/advance with no body, because the
 * backend always computes the one allowed next stage itself
 * (PipelineService). This screen does not duplicate that state-machine
 * knowledge client-side; it only ever displays whatever current_stage the
 * API returns after the call, and every such change is written to the
 * audit log server-side (§4.3).
 *
 * The audit trail drawer (who/when/from→to) reads directly from
 * /referrals/{id}/stage-logs — CLAUDE.md §4.3's "every status change must
 * be recorded in an audit log" requirement, made visible here.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TASK-177 (2026-08-13) — ONE DOOR TO THE LEDGER.
 *
 * Advancing into Complete Payment books BR-4 commission but leaves the
 * referral's order `pending`, so the customer's public /pay/{token} page
 * stays live for a sale that is already closed and already paid commission
 * on (TASK-176 §2). A row that HAS such an open bill therefore offers
 * "รับชำระเงินแล้ว" — POST /orders/{id}/confirm, which closes the bill AND
 * advances the referral in one server-side transaction — INSTEAD OF the
 * advance button, never alongside it. The exclusion is one v-if/v-else-if
 * chain in the template, so it holds by construction rather than by two
 * conditions kept as each other's inverse.
 *
 * This is the Agent Portal half of TASK-176; the admin board's copy lives
 * in frontend-admin/src/views/ReferralPipelineManagementView.vue. ADR-003
 * means the two apps share no package, so `canConfirmOrder()` /
 * `formatBaht()` / `verifiedByLine()` below are a deliberate duplicate and
 * are kept spelled identically so the two files can be diffed.
 *
 * One difference from the admin board, in our favour: THIS BOARD HAS NO
 * DRAG. `draggable` appears nowhere in this file, so the button is the only
 * affordance that can reach POST /referrals/{id}/advance and there is
 * exactly one door to close, not three. Do not add a drag gesture here
 * without closing it against `canConfirmOrder()` too (the admin board's
 * TASK-176 §4.1 follow-up is what that looks like).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ADR-026 (2026-08-08) — THE STAGE LIST IS NOT A CONSTANT. DO NOT MAKE
 * IT ONE.
 *
 * This board used to hold `STAGE_ORDER`, a hardcoded copy of §4.3's five
 * medical stages, and used it for both the filter tabs and the group
 * ordering. Since ADR-026 the vocabulary is EIGHT cases and every referral
 * follows its OWN template's ordered SUBSET of them, so a hardcoded five
 * would (a) never show จัดส่ง / นัดใช้บริการ / ติดตามผล, and (b) rank a
 * short-journey referral's stages against a sequence its template does not
 * contain.
 *
 * Everything ordering-related therefore comes from each referral's own
 * `pipeline.stages` (ordered; its index IS the journey position) and
 * `pipeline.next_stage` (the one legal forward move, or null). A single
 * fixed column set for every referral is exactly the bug ADR-026 exists to
 * prevent.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TASK-169 Phase 3b, AMENDED BY TASK-171 (2026-08-12) — TWO FILTER AXES,
 * now NESTED: สถานะดีล is the main menu, ขั้นตอน is its contextual
 * SUB-MENU.
 *
 * `ReferralsView`'s old ทั้งหมด / รอดำเนินการ / เสร็จแล้ว tabs answered
 * "show me the set", which the stage axis structurally cannot: "done" is
 * EACH TEMPLATE'S OWN terminal stage, so one stage tab mixes finished
 * direct sales with mid-journey medical deals. Phase 4 deleted those tabs;
 * the capability lives here instead (ag-lead ruling, §5b item 2).
 *
 *   สถานะดีล (main)  → ทั้งหมด / รอดำเนินการ / เสร็จแล้ว — segmented, always 3
 *     └── ขั้นตอน (sub) → ONLY the stages that currently HOLD deals in the
 *         selected bucket. Under ทั้งหมด the row is not rendered at all.
 *
 * Both still apply together — "which of my DONE deals ended at จัดส่ง" is
 * a real triage question and an either/or control cannot ask it — and the
 * ≤3-click budget (§9) holds: status is one tap, stage is one more.
 *
 * TASK-171 DELIBERATELY REVERSES ONE PHASE 3b DECISION, and this is the
 * only rule in this file on the subject. 3b kept the stage vocabulary as
 * the union over ALL loaded referrals so that "tabs never appear/disappear
 * under the agent's thumb". That was sound while the two rows were
 * INDEPENDENT and both permanently visible. It is wrong for a hierarchy: a
 * sub-menu that ignores its parent is not a sub-menu, and contents that
 * follow the parent are the EXPECTED behaviour, not the surprise 3b was
 * guarding against. THE VOCABULARY IS NOW COMPUTED FROM THE SELECTED
 * BUCKET (TASK-171 §2/§3).
 *
 * WHAT IS NOT NEGOTIABLE: which bucket a referral falls in is decided by
 * `isOpen()` and by nothing else. A stage key may legitimately appear
 * under BOTH parents — `complete_payment` is terminal on a direct sale
 * (done) and mid-journey on a medical template that still owes a meeting
 * (open) — so NO static stage→bucket map can be true (ADR-026). Writing
 * one is the exact defect TASK-171 §2 records as having been proposed or
 * found three times in this codebase.
 *
 * Consequences of that choice, made deliberately:
 *  - The status bar gets its OWN row rather than three more tabs on the
 *    stage row. The stage row already scrolls horizontally on a phone;
 *    appending status tabs there would bury them off-screen AND imply they
 *    are mutually exclusive with a stage, which they are not.
 *  - The sub-menu offers only OCCUPIED stages, so tapping one can never
 *    produce an empty board. TASK-171 §4's "never strand the agent" is
 *    therefore enforced structurally, not by an apology message.
 *  - The sub-menu COUNTS are within the selected status; the STATUS counts
 *    stay over ALL referrals, matching the KPI row. Making each axis
 *    re-count against the other is circular and leaves an agent unable to
 *    see what a tap would actually reveal.
 *
 * MIXED TEMPLATES — this board GROUPS, it does not filter. ADR-026 §4 says
 * referrals on different templates "cannot share one board — the board is
 * filtered per template, or grouped". A Kanban must filter (its columns are
 * a single shared axis), but this is a vertical list, so grouping loses
 * nothing: each journey gets its own section header naming the path, and
 * its stage sub-groups are ordered by THAT journey. Filtering here would
 * hide referrals from an agent's own pipeline behind a control they did not
 * know to touch, which is the worse failure on a phone.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — raw HTTP status codes were leaking into
// user-facing copy; apiErrorMessage() is the single normalizer. The toast
// store covers the other finding: advancing a stage silently refetched with
// no confirmation at all.
import { apiErrorMessage } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
import AppButton from './AppButton.vue'
import TabFilterBar from './TabFilterBar.vue'
import EmptyState from './EmptyState.vue'
import Icon from './Icon.vue'
import LoadingSkeleton from './LoadingSkeleton.vue'
// TASK-082 (UX audit): a tracking board is homogeneous, comparable content
// — Material's rule is a list for that, never cards when the user has to
// scan comparable items. Rows are flat; the stage is the group header.
import AppCard from './AppCard.vue'
import AppList from './AppList.vue'
import AppListGroupHeader from './AppListGroupHeader.vue'
// TASK-177 §4.4 — confirming a payment writes an IMMUTABLE BR-4 ledger row
// and closes a customer's bill. This app's own confirmation UI (the one
// TASK-079 Phase 2 replaced the last window.confirm() with, and which
// OrdersView/ClientsView/MyTeamView/AffiliateLinksView already use) — NOT
// the admin's, which is a different file in a different app (ADR-003).
import ConfirmDialog from './ConfirmDialog.vue'
// TASK-191 §3.3/§3.4 — the SAME generic share sheet OrdersView.vue and the
// client drawer already use. No new modal, no new QR logic.
import ShareLinkModal from './ShareLinkModal.vue'
// ADR-026 — Thai stage wording + journey identity/labelling. The API sends
// English labels by design (§7); the Thai belongs to the UI.
import {
  journeyLabel,
  journeySignature,
  stageLabelTh,
  PAYMENT_STAGE_KEY,
  type PipelineStageRef,
} from '@/utils/pipelineStages'

/**
 * Structurally identical to HeroHeader's `HeroKpi`, declared here so the
 * board does not have to import from the header it is deliberately NOT
 * coupled to (the host owns the header).
 */
export interface PipelineBoardKpi {
  label: string
  value: string | number
}

/**
 * TASK-176 §1.2 — the ONE order this row may act on, exactly as
 * `ReferralResource` sends it (read from the Resource itself, not from the
 * spec doc).
 *
 * TASK-191 §2/§3.3 — `public_pay_url` used to be excluded here on purpose
 * ("no `public_pay_url`, so a board can never publish a live payment
 * link"). That reasoning no longer holds: TASK-189 made the same link the
 * one place a paid voucher renders, and TASK-190 exists specifically
 * because nothing currently re-surfaces that link to a customer after the
 * fact. Phase 1 (ag-dev) added the field to `ReferralResource`'s nested
 * `order` for exactly this reason, and it is now read here too — deliberate
 * reversal, recorded rather than silently changed (CLAUDE.md §8 rule 1).
 *
 * ADR-003 — this interface, `canConfirmOrder()`, `formatBaht()` and
 * `verifiedByLine()` below are a DELIBERATE COPY of
 * `frontend-admin/src/views/ReferralPipelineManagementView.vue`. The two
 * apps share no package, so the predicate is kept spelled identically on
 * purpose: the next reader is meant to be able to diff them. Admin's board
 * now also HAS `public_pay_url` available server-side (same Resource,
 * ADR-003), but this task does not add an Admin button — a later task could
 * without another backend change.
 */
interface ReferralOrder {
  id: number
  order_number: string
  status: 'pending' | 'awaiting_verification' | 'paid' | 'cancelled'
  status_label: string
  // BR-3 — integer satang on the wire. Divided by 100 for DISPLAY ONLY
  // (formatBaht below); nothing here ever stores or sends a float.
  amount_satang: number
  has_slip: boolean
  paid_at: string | null
  verified_by: { id: number; name: string } | null
  // TASK-191 §3.3 — the voucher/payment link, now shared by the new
  // "share" button below, same value as OrderResource's own field.
  public_pay_url: string
}

interface ReferralItem {
  id: number
  client: { id: number; name: string; phone: string } | null
  agent: { id: number; name: string } | null
  product: { id: number; name: string; price_satang: number } | null
  // TASK-134a — nullable since the branch column was widened. NULL means
  // "this sale did not happen at a branch" (a self-serve checkout), and the
  // Thai rendering of that is a UI decision (ag-lead ruling 2026-08-08) —
  // never a placeholder written into the column.
  branch: string | null
  preferred_time: string | null
  current_stage: PipelineStageRef
  meeting_number: number | null
  // ADR-026 §3.6 — THIS referral's own journey.
  //   stages     : ordered; index = position. EMPTY when the journey could
  //                not be read (template deleted/emptied) — the server
  //                fails closed, and so must this board.
  //   next_stage : the ONE legal forward move, or null at the end.
  pipeline: {
    stages: PipelineStageRef[]
    next_stage: PipelineStageRef | null
  }
  // TASK-176 §1.2 / TASK-177 §4.1 — OPTIONAL, not merely nullable: the key
  // is ABSENT when the backend did not eager-load `orders` (the nested uses
  // of ReferralResource — ClientResource, TeamClientResource), and null when
  // it did but there is no order this board may act on. Both mean exactly
  // the same thing here — no order — so every read goes through `?.`.
  order?: ReferralOrder | null
  submitted_at: string
}
interface StageLogItem {
  id: number
  from_stage: PipelineStageRef | null
  to_stage: PipelineStageRef
  changed_by: { id: number; name: string } | null
  changed_at: string
}

const emit = defineEmits<{ 'kpis-change': [PipelineBoardKpi[]] }>()

const toast = useToastStore()

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const referrals = ref<ReferralItem[]>([])

/**
 * BR-4 reporting KPI: "has this deal's money landed?".
 *
 * Reads the referral's OWN ordered journey — `pipeline.stages`'s index IS
 * its position (ReferralResource says so explicitly), so comparing the
 * current index against complete_payment's index is reading data, not
 * re-implementing the state machine. It cannot be a fixed stage list any
 * more: `complete_payment` is position 3 on the medical journey and
 * position 1 on a direct sale, and a medical referral at
 * `ongoing_next_meeting` has already paid.
 *
 * Fails CLOSED — an unreadable journey, or a referral parked off its own
 * journey, is not counted rather than optimistically counted.
 */
function isAtOrPastPayment(r: ReferralItem): boolean {
  const current = r.pipeline.stages.findIndex((s) => s.key === r.current_stage.key)
  const payment = r.pipeline.stages.findIndex((s) => s.key === PAYMENT_STAGE_KEY)
  return current !== -1 && payment !== -1 && current >= payment
}

/**
 * TASK-177 §4.2 (= TASK-176 §4.1) — THE ONE-DOOR PREDICATE.
 *
 * True when this row must offer "รับชำระเงินแล้ว" INSTEAD OF the advance
 * button. The template renders them as one v-if/v-else-if chain, so
 * "instead of" is structural, not a convention two conditions have to keep.
 *
 * ADR-003 — spelled IDENTICALLY to
 * `frontend-admin/src/views/ReferralPipelineManagementView.vue`'s
 * `canConfirmOrder()`, on purpose. The two apps share no package (§7 /
 * ADR-003), so this is a deliberate copy and the next reader is meant to be
 * able to diff the two files line for line.
 *
 * Composed from the two things that already exist in this file rather than
 * from a new notion of stage order:
 *
 *  - `PAYMENT_STAGE_KEY` — the one stage key with a rule attached (BR-4
 *    fires at Complete Payment and nowhere else, on EVERY template; ADR-026
 *    leaves that unchanged);
 *  - `isAtOrPastPayment()` above, which answers "has this deal's money stage
 *    already been reached?" against the referral's OWN ordered journey.
 *
 * THERE IS NO STAGE ARRAY HERE AND THERE MUST NEVER BE ONE. A hardcoded copy
 * of §4.3's five medical stages has been added to this codebase and removed
 * again three times since ADR-026; each time it broke the referrals whose
 * template is a different subset. It is also why there is exactly one
 * at-or-past-payment helper in this file, used by the KPI row and by this
 * predicate — not two comparisons inlined separately.
 *
 * The gate mirrors the server's (CLAUDE.md §4.3: "Order payment may be
 * confirmed once the referral's NEXT stage under its own template is
 * Complete Payment, or it is already at/past it"), so the agent never learns
 * that rule from a 422.
 *
 * Fails CLOSED with the rest of the file: an unreadable journey (`stages:
 * []`) has no next_stage and cannot be at-or-past anything, so it offers no
 * confirm — it keeps the "เส้นทางไม่ถูกต้อง" flag it already had.
 */
function canConfirmOrder(r: ReferralItem): boolean {
  const order = r.order
  if (!order) return false
  if (order.status === 'cancelled' || order.status === 'paid') return false
  return r.pipeline.next_stage?.key === PAYMENT_STAGE_KEY || isAtOrPastPayment(r)
}

/**
 * BR-3 — satang is an integer everywhere except right here, at the display
 * layer. Thousands-separated, two decimals, and the result is a string that
 * is only ever RENDERED: no computed baht value is stored on a ref, and the
 * confirm POST carries no body at all, so no float can reach the API.
 */
function formatBaht(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/**
 * TASK-176 §4.3 — requirement #2, "ยืนยันด้วย Admin". `verified_by` is null
 * both when nobody has confirmed and when the confirming user has since been
 * deleted; either way the line says ไม่ทราบ. Never blank, and never a
 * fabricated fallback name.
 */
function verifiedByLine(order: ReferralOrder): string {
  if (!order.verified_by) return 'ยืนยันโดย: ไม่ทราบ'
  return order.paid_at
    ? `ยืนยันโดย ${order.verified_by.name} · ${formatDateTime(order.paid_at)}`
    : `ยืนยันโดย ${order.verified_by.name}`
}

/**
 * THE open/done predicate. There is exactly ONE, and this is it — the KPI
 * row and the Phase 3b status filter both call it, so they can never
 * disagree about what "เสร็จแล้ว" means.
 *
 * "Open" is `next_stage !== null`, i.e. "this referral's OWN template still
 * has a forward move". Deliberately not a hardcoded "not in
 * [complete_payment, ongoing_next_meeting]" list — that was
 * `ReferralsView`'s pre-ADR-026 predicate and it is wrong in BOTH
 * directions: it files a paid, delivered referral sitting at จัดส่ง as
 * pending, and a `complete_payment` referral on a template with post-sale
 * stages still to run as done (ADR-026 §5 Q1). Any literal stage key here
 * would re-import that bug.
 *
 * THE BROKEN CASE COUNTS AS OPEN (ag-lead ruling, 2026-08-11). When the
 * journey cannot be read (`stages: []`, so `next_stage` is null for want of
 * data rather than for want of anything left to do), "no next stage" and
 * "finished" are not the same fact and must not share a bucket.
 *
 * Filed under เสร็จแล้ว it would drop out of the agent's open work
 * entirely — a customer stranded mid-journey, possibly already paid, that
 * nobody is looking at. Filed under รอดำเนินการ the agent sees a row
 * flagged "เส้นทางไม่ถูกต้อง" and asks someone. A misconfigured template is
 * exactly the thing that should be loud, so this fails toward visibility.
 *
 * Still ONE predicate — `done` is now stated precisely (a journey we could
 * read, with nothing after the current stage) instead of being inferred
 * from a null that has two meanings. The KPI reads the same function, so
 * the count and the filter cannot disagree.
 */
function isOpen(r: ReferralItem): boolean {
  if (r.pipeline.stages.length === 0) return true

  return r.pipeline.next_stage !== null
}

const kpis = computed<PipelineBoardKpi[]>(() => [
  { label: 'ดีลทั้งหมด', value: referrals.value.length },
  { label: 'รอดำเนินการต่อ', value: referrals.value.filter(isOpen).length },
  { label: 'ชำระเงินแล้ว', value: referrals.value.filter(isAtOrPastPayment).length },
])
// The host renders these in its own page header, so they have to travel up.
watch(kpis, (value) => emit('kpis-change', value), { immediate: true })

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: ReferralItem[] }>('/referrals')
    referrals.value = res.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

// ── MAIN MENU: สถานะดีล — open / done. TASK-169 Phase 3b.
//
// Ids are local UI constants, never stage keys (§7 no magic strings, and a
// stage key here would be the exact defect this axis exists to remove).
const STATUS_ALL = 'all'
const STATUS_OPEN = 'open'
const STATUS_DONE = 'done'

/**
 * TASK-171 §4 — DELIBERATELY NOT IN THE QUERY STRING, unlike the host's
 * view mode.
 *
 * `?view=pipeline` is in the URL because it has a hard requirement:
 * TASK-169 §5.3 redirects `/pipeline` (a bookmarked URL, still linked from
 * HomeView) onto this screen, so the mode needs somewhere specific to land.
 * Status and stage have no such requirement, and putting them there costs
 * more than it buys:
 *
 *  - This is a design-system component. The host owns routing; giving the
 *    board its own `useRoute()` would couple it to a router it does not
 *    need and would have to be stubbed everywhere it is mounted.
 *  - A shared "look at this" link is worth less here than it looks: BR-6 /
 *    §5.4 mean the recipient's board contains THEIR referrals, so the link
 *    reproduces a filter, never a view of the same deals.
 *  - A stage key in a URL is a public contract on the ADR-026 enum. A link
 *    carrying a stage that nobody in the recipient's bucket is parked at
 *    would land them on the empty board §4 exists to prevent — the reset
 *    below would have to undo it on arrival anyway.
 *
 * Both therefore live in component state. Leaving the board and returning
 * starts at ทั้งหมด, which is the state that hides nothing.
 */
const activeStatus = ref(STATUS_ALL)

/**
 * Counted over ALL referrals — same denominator as the KPI row, so the
 * badge on รอดำเนินการ and the "รอดำเนินการต่อ" KPI are always the same
 * number. Both read `isOpen`.
 */
const statusTabs = computed(() => [
  { id: STATUS_ALL, label: 'ทั้งหมด', count: referrals.value.length },
  { id: STATUS_OPEN, label: 'รอดำเนินการ', count: referrals.value.filter(isOpen).length },
  { id: STATUS_DONE, label: 'เสร็จแล้ว', count: referrals.value.filter((r) => !isOpen(r)).length },
])

const statusFilteredReferrals = computed(() => {
  if (activeStatus.value === STATUS_OPEN) return referrals.value.filter(isOpen)
  if (activeStatus.value === STATUS_DONE) return referrals.value.filter((r) => !isOpen(r))

  return referrals.value
})

// ── SUB-MENU: ขั้นตอน — the stages the SELECTED bucket actually holds.
const STAGE_ALL = 'all'
const activeTab = ref(STAGE_ALL)

/**
 * The stages that CURRENTLY HOLD one of `items` — TASK-171 §2.
 *
 * Note what this function does NOT contain: any stage key, and any notion
 * of open or done. It is handed a bucket and reports which stages that
 * bucket occupies; `isOpen()` alone decided what went into the bucket. That
 * separation is the whole point — `complete_payment` comes out of here for
 * the open bucket AND for the done bucket whenever both hold a referral
 * parked there, which on a mixed catalogue is routine (ADR-026: terminal on
 * a direct sale, mid-journey on a medical template).
 *
 * ORDER still comes from the referrals' own journeys — walk the LONGEST
 * journey in the bucket first and append anything new after it, so the
 * medical stages keep their familiar sequence and a short journey's stages
 * slot into their existing positions rather than reordering the bar. Only
 * occupied stages survive the walk, which is what shortens the row (§5).
 *
 * A referral parked at a stage its own journey no longer contains (the
 * fail-closed case) is picked up by the trailing loop, or it would have no
 * tab and no way to be reached through the filter.
 */
function stagesHolding(items: ReferralItem[]): PipelineStageRef[] {
  const occupied = new Map<string, PipelineStageRef>()
  for (const r of items) occupied.set(r.current_stage.key, r.current_stage)

  const ordered: PipelineStageRef[] = []
  const walked = new Set<string>()
  const journeys = items.map((r) => r.pipeline.stages).sort((a, b) => b.length - a.length)
  for (const stages of journeys) {
    for (const stage of stages) {
      if (walked.has(stage.key)) continue
      walked.add(stage.key)
      const held = occupied.get(stage.key)
      if (held) ordered.push(held)
    }
  }
  for (const [key, stage] of occupied) {
    if (!walked.has(key)) ordered.push(stage)
  }

  return ordered
}

/**
 * The sub-menu's vocabulary. EMPTY under ทั้งหมด — the human's explicit
 * instruction (TASK-171 §1): with no status chosen there is no parent for
 * a sub-menu to belong to, so the row is not rendered at all.
 */
const stageVocabulary = computed<PipelineStageRef[]>(() =>
  activeStatus.value === STATUS_ALL ? [] : stagesHolding(statusFilteredReferrals.value),
)

const hasStageRow = computed(() => stageVocabulary.value.length > 0)

/**
 * Counts are within the selected status (§4) — the parent already narrowed
 * the set, so the badge says what a tap would actually reveal. Every one of
 * them is ≥ 1 by construction.
 */
const stageTabs = computed(() => [
  { id: STAGE_ALL, label: 'ทั้งหมด', count: statusFilteredReferrals.value.length },
  ...stageVocabulary.value.map((stage) => ({
    id: stage.key,
    label: stageLabelTh(stage),
    count: statusFilteredReferrals.value.filter((r) => r.current_stage.key === stage.key).length,
  })),
])

/**
 * TASK-171 §4 — a selection the agent can no longer see must never keep
 * filtering the board.
 *
 * Switching status (or a refetch after `advance()`) can move the referral
 * that put the selected stage in the sub-menu. Reset to that status's own
 * ทั้งหมด rather than leaving the board narrowed to an empty set by an
 * invisible control. Selecting ทั้งหมด clears it for the same reason: the
 * stage row is gone, so any stage still selected would be filtering the
 * board with nothing on screen to explain it or undo it.
 *
 * Runs before render (a pre-flush watcher), so the empty frame it prevents
 * is never painted.
 */
watch(
  stageVocabulary,
  (stages) => {
    if (activeTab.value === STAGE_ALL) return
    if (!stages.some((s) => s.key === activeTab.value)) activeTab.value = STAGE_ALL
  },
  { immediate: true },
)

/** Both filters apply. The sub-menu narrows its parent, it does not replace it. */
const filteredReferrals = computed(() =>
  activeTab.value === STAGE_ALL
    ? statusFilteredReferrals.value
    : statusFilteredReferrals.value.filter((r) => r.current_stage.key === activeTab.value),
)

/**
 * Three empty states, not one: "no deals at all" is a new agent, while
 * "no deals in this status" and "no deals at this stage" are filters the
 * agent applied and can undo — and naming the WRONG axis sends them
 * looking for a bug. The stage axis is named when it is the narrower.
 *
 * TASK-171 — the STATUS branch is the reachable one (เสร็จแล้ว with
 * nothing finished yet). The STAGE branch should now be unreachable by
 * construction: the sub-menu only offers occupied stages and the reset
 * above drops a selection the moment its stage empties. It is kept as the
 * honest message for the case where that guarantee ever regresses, since
 * the alternative — falling through to "you have no deals" — is the wrong
 * thing to tell an agent who has some.
 */
const emptyFilterTitle = computed(() =>
  activeTab.value === STAGE_ALL ? 'ไม่มีดีลในกลุ่มนี้' : 'ไม่มีดีลในขั้นนี้',
)
const emptyFilterMessage = computed(() =>
  activeTab.value === STAGE_ALL
    ? 'เลือกสถานะอื่น หรือกลับไปที่ “ทั้งหมด” เพื่อดูดีลทุกรายการ'
    : 'เลือกขั้นอื่น หรือกลับไปที่ “ทั้งหมด” เพื่อดูดีลทุกรายการ',
)

interface StageGroup {
  key: string
  label: string
  items: ReferralItem[]
}
interface JourneyGroup {
  signature: string
  label: string
  stages: PipelineStageRef[]
  count: number
  stageGroups: StageGroup[]
}

/**
 * TASK-082 group headers, made journey-aware by ADR-026.
 *
 * TWO levels, because one flat stage ordering stopped being meaningful the
 * moment referrals could follow different journeys:
 *
 *   journey (only rendered as a header when there is more than one)
 *     └── stage, ordered by THAT journey's own sequence
 *
 * Grouped over `filteredReferrals`, never `referrals`, so the headers
 * always describe what is on screen; empty groups are dropped rather than
 * rendered. Journeys are ordered by size so the agent's most common path
 * stays on top.
 *
 * Still presentation only: no request, and no state-machine knowledge
 * duplicated client-side — the ordering is read straight off each
 * referral's server-sent `pipeline.stages`.
 */
const journeyGroups = computed<JourneyGroup[]>(() => {
  const journeys = new Map<string, { stages: PipelineStageRef[]; items: ReferralItem[] }>()
  for (const r of filteredReferrals.value) {
    const signature = journeySignature(r.pipeline.stages)
    const bucket = journeys.get(signature) ?? { stages: r.pipeline.stages, items: [] }
    bucket.items.push(r)
    journeys.set(signature, bucket)
  }

  return [...journeys.entries()]
    .map(([signature, journey]) => {
      const stageBuckets = new Map<string, StageGroup>()
      for (const r of journey.items) {
        const bucket = stageBuckets.get(r.current_stage.key) ?? {
          key: r.current_stage.key,
          label: stageLabelTh(r.current_stage),
          items: [],
        }
        bucket.items.push(r)
        stageBuckets.set(r.current_stage.key, bucket)
      }
      // Rank against THIS journey. A stage the journey does not contain
      // (fail-closed) sorts last rather than being dropped.
      const rank = (key: string) => {
        const i = journey.stages.findIndex((s) => s.key === key)
        return i === -1 ? journey.stages.length : i
      }
      return {
        signature,
        label: journeyLabel(journey.stages),
        stages: journey.stages,
        count: journey.items.length,
        stageGroups: [...stageBuckets.values()].sort((a, b) => rank(a.key) - rank(b.key)),
      }
    })
    .sort((a, b) => b.count - a.count)
})

/** Only worth naming the path when there is more than one on screen. */
const hasMixedJourneys = computed(() => journeyGroups.value.length > 1)

const advancing = ref<number | null>(null)
async function advance(referral: ReferralItem) {
  // ADR-026 — never SEND a move this referral's own template does not
  // allow. The button is already hidden in that case; this is the
  // belt-and-braces half, because "the user discovers the rule from a 422"
  // is exactly what a template-aware board exists to prevent.
  if (!referral.pipeline.next_stage) return
  advancing.value = referral.id
  errorMessage.value = ''
  try {
    await api.post(`/referrals/${referral.id}/advance`)
    await loadAll()
    // TASK-079 Phase 2 (UX audit): the stage chip re-renders somewhere in a
    // long list, so on a phone the agent often can't see that anything
    // happened. Confirm the write explicitly. The new stage itself is never
    // named here — the backend owns the state machine (§4.3).
    toast.success('อัปเดตสถานะแล้ว')
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ดำเนินการไม่สำเร็จ')
  } finally {
    advancing.value = null
  }
}

// ── TASK-177 §4.4 — confirm the ORDER, not the stage ────────────────
//
// The other half of the one-door rule. `advance()` above moves the referral
// (and fires BR-4 on the way past Complete Payment) but leaves the order
// `pending` forever — which leaves a live public /pay/{token} page for a sale
// that is already booked and already paid commission on (TASK-176 §2, the
// defect this task exists to close in THIS app). This closes the bill
// instead: the server marks the order paid, stamps `paid_at` +
// `verified_by_user_id`, and advances the referral itself
// (OrderService::confirmPayment). That is why the two buttons are mutually
// exclusive rather than merely ordered.
//
// Routed through ConfirmDialog because it writes an IMMUTABLE ledger row
// (BR-4) and closes a customer's bill. It is not a toggle and there is no
// undo — the same reasoning that put the order-cancel flow behind it
// (OrdersView, TASK-079 Phase 2).
const pendingConfirm = ref<{ referral: ReferralItem; order: ReferralOrder } | null>(null)
const confirmingOrderId = ref<number | null>(null)

function askConfirmOrder(referral: ReferralItem): void {
  // Re-checked here, not just in the template: the affordance and the action
  // must agree even if a refetch changed the row underneath. Same
  // belt-and-braces shape as advance()'s `next_stage` guard above.
  if (!canConfirmOrder(referral) || !referral.order) return
  errorMessage.value = ''
  pendingConfirm.value = { referral, order: referral.order }
}

async function confirmOrderPayment(): Promise<void> {
  const pending = pendingConfirm.value
  if (!pending) return
  confirmingOrderId.value = pending.order.id
  errorMessage.value = ''
  try {
    // BR-3 — NO BODY. The amount lives server-side on the order; echoing a
    // baht figure back would be the one place a float could reach the API.
    await api.post(`/orders/${pending.order.id}/confirm`)
    pendingConfirm.value = null
    await loadAll()
    toast.success('ยืนยันการชำระเงินแล้ว')
  } catch (e) {
    // Close the dialog before surfacing the failure: it covers the row the
    // banner belongs to, and a dialog left open over an error reads as if
    // the action is still in flight.
    pendingConfirm.value = null
    errorMessage.value = apiErrorMessage(e, 'ยืนยันการชำระเงินไม่สำเร็จ')
  } finally {
    confirmingOrderId.value = null
  }
}

// ── TASK-191 §3.3/§3.4 — share the voucher/payment link ──────────────────
//
// Exact same shape as OrdersView.vue's openShare(order): set url + heading,
// open the one ShareLinkModal. Additive to the confirm/advance chain above,
// not part of it — see the template's comment at the button itself.
const showShareModal = ref(false)
const shareUrl = ref('')
const shareHeading = ref('')
function openShare(order: ReferralOrder): void {
  shareUrl.value = order.public_pay_url
  shareHeading.value = `ชำระเงิน ${order.order_number}`
  showShareModal.value = true
}

/**
 * Re-checks `order.status === 'paid'` here too, same belt-and-braces shape
 * as `askConfirmOrder()` above — the affordance and the action must agree
 * even if a refetch changed the row underneath — and it is what lets the
 * button pass the whole referral rather than `r.order` directly, so a
 * template-level null narrowing is never load-bearing.
 */
function shareOrderLink(r: ReferralItem): void {
  if (r.order?.status !== 'paid') return
  openShare(r.order)
}

// --- Audit trail drawer ---
const selectedReferralId = ref<number | null>(null)
const selectedReferral = computed(() => referrals.value.find((r) => r.id === selectedReferralId.value) ?? null)
const stageLogs = ref<StageLogItem[]>([])
const loadingLogs = ref(false)

async function openTrail(referral: ReferralItem) {
  selectedReferralId.value = referral.id
  stageLogs.value = []
  loadingLogs.value = true
  try {
    const res = await api.get<{ data: StageLogItem[] }>(`/referrals/${referral.id}/stage-logs`)
    stageLogs.value = res.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดประวัติไม่สำเร็จ')
  } finally {
    loadingLogs.value = false
  }
}
function closeDrawer() {
  selectedReferralId.value = null
  stageLogs.value = []
}

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <div>
    <!-- The filters. They carry their own card because TabFilterBar is
         deliberately transparent and takes its ink from the parent surface
         (see its docblock) — dropped straight onto the page they would be
         unreadable on a themed background. The HOST's page header carries
         the view-mode switch; these two bars are the finer axes.

         TASK-169 Phase 3b / TASK-171 — TWO ROWS, not one, and the second
         is the FIRST's sub-menu. Status is always exactly 3 tabs, so
         TabFilterBar renders it as a SEGMENTED control (its ≤3 layout):
         equal widths, no scrolling, nothing to clip at 375px, and visibly
         a different KIND of control from the stage row under it. Appending
         status to the stage row would have pushed it past the right edge
         on a phone AND implied the two are mutually exclusive — they
         compose.

         TASK-171 §4 (layout shift): under ทั้งหมด the sub-menu is gone and
         the content moves up ~45px. The row therefore collapses through a
         grid 1fr→0fr transition instead of vanishing between two frames,
         so the movement reads as this control folding away rather than as
         the page jumping. Its divider is a border-TOP on the row itself —
         a border-bottom on the status row would survive as a rule
         underlining nothing once the sub-menu is gone.

         Both bars stay at the DEFAULT size. `size="sm"` would have bought
         back 4px of height at the cost of a 40px tap target, and 44px is
         the HIG/Material minimum TASK-084 raised these to after a human
         complaint — not something to spend on a new control. -->
    <div
      class="rounded-2xl bg-surface-card/95 border border-line-card shadow-sm overflow-hidden"
      style="font-family: var(--app-font)"
    >
      <div class="px-4">
        <TabFilterBar v-model="activeStatus" :tabs="statusTabs" accent-color="brand" />
      </div>
      <Transition name="stage-row">
        <div v-if="hasStageRow" class="stage-row">
          <div class="stage-row-inner">
            <div class="px-4 border-t border-line-card">
              <TabFilterBar v-model="activeTab" :tabs="stageTabs" accent-color="brand" />
            </div>
          </div>
        </div>
      </Transition>
    </div>

    <!-- TASK-079 Phase 2 (UX audit): dead-end error banner — retry lets the
         agent recover without reloading the whole SPA. -->
    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3">
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="loadAll"
      >
        ลองใหม่
      </button>
    </div>

    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone.
         .content-fade lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s. -->
    <Transition name="content-fade">
      <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
      <div v-else>
        <!-- Two different KINDS of empty state on purpose. "No deals at
             all" is a new agent who needs to be told where deals come from;
             "nothing under this filter" is something the agent themselves
             applied and can undo — and Phase 3b's copy names WHICH of the
             two axes to relax. One message for both used to send the second
             case looking for a bug. -->
        <EmptyState
          v-if="!referrals.length"
          icon="pipeline"
          title="ยังไม่มีดีลในกระบวนการขาย"
          message="ดีลเกิดจาก “+ เพิ่มสินค้าที่สนใจ” ในข้อมูลลูกค้า — commission (BR-4) จะ trigger ที่ขั้น Complete Payment"
          class="mt-4"
        />
        <EmptyState
          v-else-if="!filteredReferrals.length"
          icon="pipeline"
          :title="emptyFilterTitle"
          :message="emptyFilterMessage"
          class="mt-4"
        />
        <!-- TASK-082 (UX audit): no per-row card. Referrals in a pipeline
             are homogeneous, comparable items — Material says list, not
             card, for exactly this — so the rows butt together in one
             <AppList> surface (no space-y-2) and the stage becomes a group
             header. Grouping, not colour, is what makes this read
             differently from the other lists: the human rejected per-page
             accents (2026-08-03), so structure carries it.

             ADR-026 — two levels: journey, then stage within that journey.
             The journey heading only appears when more than one is on
             screen; with a single template the list looks exactly as it did
             before. -->
        <div v-else class="mt-4">
          <template v-for="journey in journeyGroups" :key="journey.signature">
            <div
              v-if="hasMixedJourneys"
              class="mt-4 first:mt-0 px-4 py-2 rounded-xl bg-surface-chip flex items-start gap-2"
            >
              <Icon name="pipeline" :size="14" class="text-ink-card-subtle mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-[11px] font-bold text-ink-card-subtle uppercase tracking-wider">เส้นทางการขาย</p>
                <p class="text-xs font-bold" :class="journey.stages.length ? 'text-ink-card-muted' : 'text-ink-danger'">
                  {{ journey.label }}
                </p>
              </div>
            </div>
            <template v-for="group in journey.stageGroups" :key="journey.signature + group.key">
              <AppListGroupHeader :label="group.label" :count="group.items.length" />
              <AppList>
                <!-- No `tag` on TransitionGroup: it renders as a fragment so
                     the rows stay DIRECT children of AppList, which is what
                     its `[&>*:last-child]:border-b-0` rule needs. -->
                <TransitionGroup name="list-fade">
                  <!-- `interactive` on a flat row is a background tint rather
                       than a scale — scaling a full-bleed row looks broken
                       against the rows it now touches (TASK-082). -->
                  <AppCard
                    v-for="r in group.items"
                    :key="r.id"
                    variant="flat"
                    interactive
                    class="flex flex-col gap-2"
                    data-test="referral-card"
                    @click="openTrail(r)"
                  >
                    <!-- Flex-squeeze fix (human-reported at 768px: the client
                         name wrapped to ONE CHARACTER PER LINE). The text
                         column needs `flex-1 min-w-0` and the action column
                         `shrink-0`, or the `whitespace-nowrap` stage chip plus
                         the advance button win the width and crush the name.
                         Stacking below `sm` too, mobile-first: at 375px a long
                         stage label next to a button leaves no usable room for
                         a name on the same line.
                         TASK-081: the client name is what the agent tracks
                         through the stages — it is the hero line; the stage
                         chip stays a chip, deliberately NOT enlarged. -->
                    <div class="flex items-start gap-3 min-w-0 flex-1">
                      <Icon name="pipeline" :size="18" class="text-ink-brand mt-1 shrink-0" />
                      <div class="min-w-0">
                        <p class="text-lg font-bold text-ink-card leading-tight">{{ r.client?.name }}</p>
                        <!-- TASK-134a — a NULL branch means the sale did not
                             happen at a branch (a self-serve checkout through
                             a shared link). Rendering that as Thai copy is a
                             UI decision; the column stays NULL so a real
                             branch can never be confused with one. -->
                        <p class="text-xs text-ink-card-muted mt-0.5">
                          {{ r.product?.name }} · {{ r.branch ?? 'ไม่ระบุสาขา' }}
                          <span v-if="r.current_stage.key === 'ongoing_next_meeting' && r.meeting_number"> · นัดหมายครั้งที่ {{ r.meeting_number }}</span>
                        </p>
                        <!-- TASK-177 §4.5 — the order behind this deal, so the
                             agent can see WHAT they are about to confirm (and
                             that there is a slip to check first) without
                             opening anything. BR-3: satang → baht happens in
                             formatBaht, here, and nowhere else. -->
                        <p v-if="r.order" class="text-xs text-ink-card-subtle mt-0.5">
                          {{ r.order.order_number }} · {{ formatBaht(r.order.amount_satang) }} บาท ·
                          {{ r.order.status_label }}<span v-if="r.order.has_slip"> · มีสลิปแนบ</span>
                        </p>
                        <!-- §4.5 — "ยืนยันโดย …" only on a closed bill, and
                             never blank (see verifiedByLine). -->
                        <p v-if="r.order && r.order.status === 'paid'" class="text-xs text-ink-card-subtle">
                          {{ verifiedByLine(r.order) }}
                        </p>
                      </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 pl-8">
                      <span class="text-xs font-bold text-brand-700 bg-brand-50 px-2 py-1 rounded-lg whitespace-nowrap">{{ stageLabelTh(r.current_stage) }}</span>
                      <!--
                        TASK-177 §4.3 — ONE DOOR, NEVER TWO.

                        A SINGLE v-if / v-else-if chain on purpose: exactly one
                        branch can ever render, so "the confirm button REPLACES
                        the advance button" is guaranteed by the compiler
                        rather than by two conditions that have to be kept each
                        other's inverse. Splitting this into two independent
                        `v-if`s is the change that breaks it — an agent must
                        never be shown two buttons and left to work out which
                        one books commission (BR-4).

                        Order matters: confirm WINS over advance, because when
                        both are possible the advance is the one that leaves
                        the customer's public /pay/{token} page open forever
                        (TASK-176 §2).

                        THIS BOARD HAS NO DRAG. Unlike the admin Kanban — where
                        the drag gesture had to be closed as a second door to
                        the same endpoint (TASK-176 §4.1 follow-up) — the only
                        affordance that can reach POST /referrals/{id}/advance
                        here is this button. `draggable` appears nowhere in
                        this file; verified, and asserted in the spec so it
                        stays that way.
                      -->
                      <AppButton
                        v-if="canConfirmOrder(r)"
                        size="sm"
                        :loading="confirmingOrderId === r.order?.id"
                        :disabled="confirmingOrderId !== null"
                        title="ยืนยันว่าได้รับเงินสำหรับคำสั่งซื้อนี้แล้ว"
                        data-test="confirm-order"
                        @click.stop="askConfirmOrder(r)"
                      >
                        รับชำระเงินแล้ว
                      </AppButton>
                      <!-- `loading` makes the button inert: this advances the
                           §4.3 state machine and a double tap is a real risk.

                           ADR-026 — the button exists only when THIS
                           referral's OWN template offers a next stage, and it
                           names that stage. It used to be always on, so a
                           terminal referral learned the rule from a 422. The
                           two "no move" cases are told apart deliberately: an
                           empty journey is a misconfiguration someone must
                           fix, not a finished sale.

                           It keeps the app's themed `primary` variant rather
                           than borrowing the admin's emerald: AppButton's
                           colours come from the brand ramp so per-company
                           theming (ADR-018) keeps applying, and with the two
                           buttons structurally exclusive there is no pair of
                           blues for hue to disambiguate. -->
                      <AppButton
                        v-else-if="r.pipeline.next_stage"
                        size="sm"
                        :loading="advancing === r.id"
                        :title="`เลื่อนไปขั้น ${stageLabelTh(r.pipeline.next_stage)}`"
                        data-test="advance"
                        @click.stop="advance(r)"
                      >
                        ไป: {{ stageLabelTh(r.pipeline.next_stage) }}
                      </AppButton>
                      <span
                        v-else-if="!r.pipeline.stages.length"
                        class="text-xs font-bold text-ink-danger whitespace-nowrap"
                      >
                        เส้นทางไม่ถูกต้อง
                      </span>
                      <span v-else class="text-xs font-bold text-ink-card-subtle whitespace-nowrap">จบเส้นทางแล้ว</span>
                      <!--
                        TASK-191 §3.3 — ADDITIVE, deliberately OUTSIDE the
                        v-if/v-else-if chain above. That chain is about "what
                        can this row's PIPELINE do next" and stays mutually
                        exclusive for BR-4's one-door reason (see the block
                        comment above it). This button is about the VOUCHER on
                        an already-paid order, which is a completely
                        independent question from what stage comes next — a
                        paid order can still have delivery/follow_up stages
                        ahead of it (ADR-026), so it shows whenever
                        `order.status === 'paid'`, regardless of which (if
                        any) of the four branches above rendered.
                      -->
                      <AppButton
                        v-if="r.order?.status === 'paid'"
                        variant="secondary"
                        size="sm"
                        data-test="share-voucher"
                        @click.stop="shareOrderLink(r)"
                      >
                        <Icon name="share" :size="14" />
                        แชร์ลิงก์
                      </AppButton>
                    </div>
                  </AppCard>
                </TransitionGroup>
              </AppList>
            </template>
          </template>
        </div>
      </div>
    </Transition>

    <!-- Audit trail drawer -->
    <Transition name="drawer">
      <div v-if="selectedReferral" class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-slate-900/30" @click="closeDrawer" />
        <div class="drawer-panel relative w-full max-w-md bg-surface-card h-full shadow-xl p-5 overflow-y-auto">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-ink-card">{{ selectedReferral.client?.name }}</h2>
            <button class="min-h-[44px] min-w-[44px] -mr-2 inline-flex items-center justify-center text-ink-card-subtle hover:text-ink-card-muted active:scale-90 transition-transform" @click="closeDrawer"><Icon name="close" :size="20" /></button>
          </div>

          <p class="text-sm text-ink-card-muted">
            {{ selectedReferral.product?.name }} · {{ selectedReferral.branch ?? 'ไม่ระบุสาขา' }}
          </p>
          <p class="text-xs text-ink-card-subtle mt-1">สถานะปัจจุบัน: {{ stageLabelTh(selectedReferral.current_stage) }}</p>
          <!-- ADR-026 — the whole journey this referral was stamped with, so
               the agent can see where the customer is going, not just where
               they are. Snapshotted at creation and never re-resolved
               (§4.3), so it is stable for this customer even if an admin
               edits the template later. -->
          <div v-if="selectedReferral.pipeline.stages.length" class="mt-3 flex flex-wrap items-center gap-1.5">
            <template v-for="(stage, idx) in selectedReferral.pipeline.stages" :key="stage.key">
              <span v-if="idx > 0" class="text-ink-card-subtle text-xs">→</span>
              <span
                class="px-2 py-0.5 rounded-lg text-[11px] font-bold"
                :class="stage.key === selectedReferral.current_stage.key
                  ? 'bg-brand-50 text-brand-700'
                  : 'bg-surface-chip text-ink-card-subtle'"
              >
                {{ stageLabelTh(stage) }}
              </span>
            </template>
          </div>
          <p v-else class="mt-3 text-xs font-bold text-ink-danger">
            อ่านเส้นทางการขายของรายการนี้ไม่ได้ — กรุณาแจ้งผู้ดูแลระบบ
          </p>

          <h3 class="mt-5 mb-2 text-sm font-bold text-ink-card flex items-center gap-2">
            <Icon name="list" :size="16" /> ประวัติการเปลี่ยนสถานะ (audit log)
          </h3>

          <EmptyState v-if="!loadingLogs && !stageLogs.length" icon="list" title="ยังไม่มีประวัติ" />
          <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
            <div v-for="log in stageLogs" :key="log.id" class="p-3 rounded-lg border border-line-card text-sm">
              <p class="font-bold text-ink-card">
                <span v-if="log.from_stage">{{ stageLabelTh(log.from_stage) }} →</span>
                {{ stageLabelTh(log.to_stage) }}
              </p>
              <p class="text-xs text-ink-card-subtle mt-0.5">
                โดย {{ log.changed_by?.name ?? '—' }} · {{ formatDateTime(log.changed_at) }}
              </p>
            </div>
          </TransitionGroup>
        </div>
      </div>
    </Transition>

    <!--
      TASK-177 §4.4. INSIDE the board's single root <div> deliberately: a
      sibling at the top level would make this component multi-root, which
      breaks the <Transition mode="out-in"> its hosts render it under (the
      same fix applied across 8 views in this app).

      Wording is TASK-176 §4.2's, verbatim — it names the amount and the
      order, and says plainly that the commission is written immediately and
      cannot be edited afterwards (BR-4). "warning", not "danger": nothing is
      being destroyed, but there is no undo.
    -->
    <ConfirmDialog
      :show="pendingConfirm !== null"
      variant="warning"
      :busy="confirmingOrderId !== null"
      :title="
        pendingConfirm
          ? `ยืนยันว่าได้รับเงิน ${formatBaht(pendingConfirm.order.amount_satang)} บาท สำหรับ ${pendingConfirm.order.order_number} แล้ว?`
          : ''
      "
      body="ระบบจะบันทึกคอมมิชชั่นทันทีและแก้ไขภายหลังไม่ได้ (BR-4)"
      @confirm="confirmOrderPayment"
      @update:show="
        (v: boolean) => {
          if (!v) pendingConfirm = null
        }
      "
    />

    <!-- TASK-191 §3.3/§3.4 — the same generic share sheet every other spot
         in the app uses. Also inside the single root <div> for the same
         multi-root/Transition reason as ConfirmDialog above. -->
    <ShareLinkModal v-model:show="showShareModal" :url="shareUrl" :heading="shareHeading" />
  </div>
</template>

<style scoped>
/* TASK-171 — the sub-menu row folds instead of blinking out of existence.
   grid-template-rows 1fr→0fr is the height-agnostic collapse (no magic
   pixel height to drift when the tab font or tap target changes), and the
   inner wrapper's overflow:hidden is what actually clips it — including
   its own border-top, so no 1px rule is left behind at the end of the
   leave.
   Verified on the live page at a 375px column (TASK-171): the settled row
   measures grid-template-rows 43.67px / opacity 1, i.e. the enter really
   does land on the tab bar's own height rather than on a guess. jsdom has
   no layout engine, so vitest cannot check that — it was measured in
   Chrome, and should be re-measured by hand if this block is touched.
   Not registered in assets/main.css like .content-fade / .drawer: those
   are used by a dozen views, this is one control in one component.
   prefers-reduced-motion is already handled — main.css's wildcard rule
   collapses every transition-duration to 0.01ms, which turns this into an
   instant swap rather than a merely fast one. */
.stage-row {
  display: grid;
  grid-template-rows: 1fr;
}
.stage-row-inner {
  overflow: hidden;
}
.stage-row-enter-active,
.stage-row-leave-active {
  transition:
    grid-template-rows 0.2s ease,
    opacity 0.2s ease;
}
.stage-row-enter-from,
.stage-row-leave-to {
  grid-template-rows: 0fr;
  opacity: 0;
}
</style>
