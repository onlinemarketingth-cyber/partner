<script setup lang="ts">
/**
 * ReferralPipelineManagementView — Admin company-wide view over SWS
 * Referral + Pipeline (Phase 8). Company Admin already sees the full
 * company's referrals via the existing API (ReferralController::index()
 * only narrows to "own" for Agent role) — this is read/advance only,
 * ported from the Agent Portal's PipelineView.vue with an agent-name
 * column added (Admin needs to see WHOSE referral this is).
 *
 * "ขั้นถัดไป" (advance) never lets the UI pick a target stage — same
 * design as the Agent Portal: POST with no body, backend always
 * computes the one allowed next stage (PipelineService).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ADR-026 (2026-08-08) — THE COLUMNS ARE NO LONGER A CONSTANT.
 *
 * This board used to render `STAGE_ORDER`, a hardcoded copy of §4.3's
 * five medical stages, and computed drop validity from a card's INDEX in
 * that array (`colIndex === draggedFromIndex + 1`, with a special case
 * for index 4). Since ADR-026 the vocabulary is eight cases and each
 * referral follows its own template's ordered subset, so a short-journey
 * referral rendered on a five-column board and dragging it to a stage
 * its template does not contain failed with a server error.
 *
 * Two changes fix that at the root:
 *
 *  1. Columns come from a SELECTED JOURNEY's own `stages`, read off the
 *     referrals themselves.
 *  2. Drop validity is `target === this card's own pipeline.next_stage`,
 *     the single legal edge the server volunteers per referral. No index
 *     arithmetic, no self-loop special case (a self-looping
 *     ongoing_next_meeting simply reports itself as its own next stage).
 *
 * MIXED TEMPLATES — this board FILTERS, it does not group. ADR-026 §4
 * allows either; a Kanban must filter, because its columns are ONE
 * shared horizontal axis and two journeys do not share one. Showing the
 * union would produce columns that are unreachable for half the cards —
 * exactly the failure this change exists to remove. The Agent Portal's
 * PipelineView is a vertical list, so it groups instead and hides
 * nothing; the difference is deliberate and noted in both files.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import {
  journeyLabel,
  journeySignature,
  stageLabelTh,
  PAYMENT_STAGE_KEY,
  type PipelineStageRef,
} from '@/utils/pipelineStages'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

/**
 * TASK-176 §1.2 — the ONE order this card may act on, as
 * ReferralResource sends it. A deliberate subset of OrderResource: no
 * public token and no pay URL (an admin board must not publish a live
 * payment link).
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
}

interface ReferralItem {
  id: number
  client: { id: number; name: string; phone: string } | null
  agent: { id: number; name: string } | null
  product: { id: number; name: string; price_satang: number } | null
  // TASK-134a — nullable: NULL means the sale did not happen at a branch
  // (a self-serve checkout). The Thai rendering is a UI decision.
  branch: string | null
  current_stage: PipelineStageRef
  meeting_number: number | null
  // ADR-026 §3.6 — this referral's OWN journey. `stages` is ordered and
  // EMPTY when the journey cannot be read; `next_stage` is the one legal
  // forward move, or null at the end.
  pipeline: {
    stages: PipelineStageRef[]
    next_stage: PipelineStageRef | null
  }
  // TASK-176 §1.2 — OPTIONAL, not merely nullable: the key is ABSENT
  // when the backend did not eager-load orders (the nested uses of
  // ReferralResource), and null when it did but there is no order this
  // board may act on. Both mean exactly the same thing to this screen —
  // no order — so every read below goes through `r.order` with `?.`.
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

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const referrals = ref<ReferralItem[]>([])

/**
 * BR-4 reporting KPI, made journey-aware. `complete_payment` sits at a
 * different POSITION on every template, so "has this deal's money
 * landed?" has to be answered against the referral's own ordered
 * journey (whose index IS the position — ReferralResource says so).
 * Fails closed: an unreadable journey is not counted.
 */
function isAtOrPastPayment(r: ReferralItem): boolean {
  const current = r.pipeline.stages.findIndex((s) => s.key === r.current_stage.key)
  const payment = r.pipeline.stages.findIndex((s) => s.key === PAYMENT_STAGE_KEY)
  return current !== -1 && payment !== -1 && current >= payment
}

/**
 * TASK-176 §4.1 — THE ONE-DOOR PREDICATE.
 *
 * True when this card must offer "รับชำระเงินแล้ว" INSTEAD OF the
 * advance button (the template renders them as one v-if/v-else-if
 * chain, so "instead of" is structural, not a convention).
 *
 * Deliberately built from the two things that already exist rather than
 * from a new notion of stage order:
 *
 *  - `PAYMENT_STAGE_KEY` for the one stage key with a rule attached
 *    (BR-4 fires at Complete Payment and nowhere else, on every
 *    template — ADR-026 leaves that unchanged);
 *  - `isAtOrPastPayment()` above, which answers "has this deal's money
 *    stage already been reached?" against the referral's OWN ordered
 *    journey.
 *
 * There is no stage array here and there must never be one: a hardcoded
 * copy of §4.3's five stages has been added to this codebase and removed
 * again three times since ADR-026, and each time it broke the referrals
 * whose template is a different subset.
 *
 * The gate mirrors the server's (CLAUDE.md §4.3: "Order payment may be
 * confirmed once the referral's NEXT stage under its own template is
 * Complete Payment, or it is already at/past it"), so the admin never
 * learns that rule from a 422.
 */
function canConfirmOrder(r: ReferralItem): boolean {
  const order = r.order
  if (!order) return false
  if (order.status === 'cancelled' || order.status === 'paid') return false
  return r.pipeline.next_stage?.key === PAYMENT_STAGE_KEY || isAtOrPastPayment(r)
}

/**
 * BR-3 — satang is an integer everywhere except right here, at the
 * display layer. Thousands-separated, two decimals, and the result is a
 * string that is only ever rendered: no computed baht value is stored on
 * a ref or sent back to the API.
 */
function formatBaht(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/**
 * TASK-176 §4.3 — requirement #2, "ยืนยันด้วย Admin". `verified_by` is
 * null both when nobody has confirmed and when the confirming user has
 * since been deleted; either way the line says ไม่ทราบ. Never blank, and
 * never a fabricated fallback name.
 */
function verifiedByLine(order: ReferralOrder): string {
  if (!order.verified_by) return 'ยืนยันโดย: ไม่ทราบ'
  return order.paid_at
    ? `ยืนยันโดย ${order.verified_by.name} · ${formatDateTime(order.paid_at)}`
    : `ยืนยันโดย ${order.verified_by.name}`
}

const kpis = computed(() => [
  { label: 'Referral ทั้งหมด', value: referrals.value.length },
  { label: 'รอดำเนินการต่อ', value: referrals.value.filter((r) => r.pipeline.next_stage !== null).length },
  { label: 'ชำระเงินแล้ว', value: referrals.value.filter(isAtOrPastPayment).length },
])

const route = useRoute()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()
const router = useRouter()

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: ReferralItem[] }>(activeCompany.scopedPath('/referrals'))
    referrals.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(async () => {
  await loadAll()
  // TASK-048 cross-link — arriving from the Client drawer's "ดูใน
  // Pipeline" link (?open=<referralId>): auto-open that referral's
  // stage-history drawer, then strip the query.
  const openId = Number(route.query.open)
  if (openId) {
    const target = referrals.value.find((r) => r.id === openId)
    if (target) openTrail(target)
    router.replace({ query: {} })
  }
})

// ── ADR-026 §4 — one board per journey ──────────────────────────
interface JourneyBoard {
  signature: string
  label: string
  stages: PipelineStageRef[]
  items: ReferralItem[]
}

/**
 * Every distinct journey among the loaded referrals, largest first.
 * Identity is the ordered stage-key list, not the template id — see
 * journeySignature()'s own reasoning (legacy NULL-template referrals,
 * and two templates with identical sequences).
 */
const journeyBoards = computed<JourneyBoard[]>(() => {
  const map = new Map<string, JourneyBoard>()
  for (const r of referrals.value) {
    const signature = journeySignature(r.pipeline.stages)
    const board = map.get(signature) ?? {
      signature,
      label: journeyLabel(r.pipeline.stages),
      stages: r.pipeline.stages,
      items: [],
    }
    board.items.push(r)
    map.set(signature, board)
  }
  return [...map.values()].sort((a, b) => b.items.length - a.items.length)
})

const activeJourney = ref<string | null>(null)
// Keep the selection valid across reloads: default to (and fall back to)
// the busiest journey rather than rendering an empty board for a
// signature that no longer has any referrals.
watch(
  journeyBoards,
  (boards) => {
    if (!boards.some((b) => b.signature === activeJourney.value)) {
      activeJourney.value = boards[0]?.signature ?? null
    }
  },
  { immediate: true },
)
const activeBoard = computed<JourneyBoard | null>(
  () => journeyBoards.value.find((b) => b.signature === activeJourney.value) ?? null,
)

/**
 * Kanban columns: one per stage OF THE SELECTED JOURNEY, in that
 * journey's order. `label` comes from the journey's own stage list (not
 * from a card), so a column still renders its Thai heading when it holds
 * zero cards — which was the reason the old STAGE_LABELS map existed.
 */
const columns = computed(() =>
  (activeBoard.value?.stages ?? []).map((stage) => ({
    key: stage.key,
    label: stageLabelTh(stage),
    items: (activeBoard.value?.items ?? []).filter((r) => r.current_stage.key === stage.key),
  })),
)

/**
 * Referrals sitting at a stage their OWN journey does not contain — an
 * admin removed that stage from the template after they were stamped
 * (ADR-026 §3.4 prevents re-routing, so they stay put and strand).
 *
 * They have no column to land in, and a card silently vanishing off an
 * admin board is worse than an ugly one, so they are counted and
 * surfaced rather than filtered away.
 */
const orphanedReferrals = computed(() =>
  (activeBoard.value?.items ?? []).filter(
    (r) => r.pipeline.stages.length > 0 && !r.pipeline.stages.some((s) => s.key === r.current_stage.key),
  ),
)

// ── Drag-to-advance ─────────────────────────────────────────────
// §4.3 "Sequential Transitions Only", now read PER REFERRAL rather than
// from a shared index. A card may be dropped on exactly one column: the
// one matching its own `pipeline.next_stage`, which the server computed
// from that referral's template. This is not a client-side reimplementation
// of the state machine — it is the server's answer, echoed.
//
// The old index arithmetic is gone with it: `+1` assumed every referral
// shared one five-stage sequence, and the `index === 4` self-loop special
// case is now just next_stage pointing at the current stage.
//
// The per-card button remains the always-works path (touch devices where
// native drag is unreliable), and is likewise only rendered when there
// IS a legal move.
//
// ── TASK-176 §4.1 follow-up (ag-lead ruling, 2026-08-13) ────────────
//
// DRAG IS A DOOR TOO. §4.1's rule is "one door to the ledger", not "one
// button": a button and a drag gesture that both POST to /advance are
// the same door twice. On a card where the confirm button has REPLACED
// the advance button, dragging it to the next column would still reach
// `POST /referrals/{id}/advance` — booking BR-4 commission while the
// order stays `pending` and the customer's public /pay/{token} page
// stays open forever, which is exactly the §2 defect this whole task
// exists to prevent.
//
// So: where `canConfirmOrder(r)` is true, the card is NOT DRAGGABLE.
// Nothing is lost — payment on that card is settled by confirming the
// order, which advances the referral as part of the same transaction
// (`OrderService::confirmPayment()`), so the admin reaches the same end
// state by the route that also closes the bill.
//
// It is the SAME predicate as the button chain, used twice — never a
// second condition kept as its inverse.
const draggedId = ref<number | null>(null)
const dragOverKey = ref<string | null>(null)
// Non-reactive guard so the click-to-open-drawer handler doesn't also
// fire at the end of a drag gesture.
let dragOccurred = false

const draggedReferral = computed(() => referrals.value.find((r) => r.id === draggedId.value) ?? null)

function isValidDropTarget(stageKey: string): boolean {
  return draggedReferral.value?.pipeline.next_stage?.key === stageKey
}
function onDragStart(r: ReferralItem): void {
  draggedId.value = r.id
  dragOccurred = true
}
function onDragOver(stageKey: string, e: DragEvent): void {
  // preventDefault on EVERY column so @drop always fires — that lets us
  // show an explanatory message on an invalid drop instead of the
  // browser silently rejecting it. Only a valid target gets highlighted.
  e.preventDefault()
  dragOverKey.value = isValidDropTarget(stageKey) ? stageKey : null
}
function onDragLeave(stageKey: string): void {
  if (dragOverKey.value === stageKey) dragOverKey.value = null
}
function onDrop(stageKey: string): void {
  const valid = isValidDropTarget(stageKey)
  const r = draggedReferral.value
  clearDrag()
  if (!r) return
  // TASK-176 §4.1 follow-up — the second half of the ruling above. The
  // card is already non-draggable in this state, exactly as advance()
  // below re-checks `next_stage` that the template already hid: the UI
  // withholding an affordance and the handler refusing the action are
  // the pattern this file uses for every write. A drag that reaches here
  // (synthetic event, a stale card, a browser that ignores the
  // attribute) must not become a commission row.
  if (canConfirmOrder(r)) {
    errorMessage.value =
      'รายการนี้มีบิลที่ยังไม่ปิด — กดปุ่ม “รับชำระเงินแล้ว” บนการ์ดเพื่อปิดบิลและเลื่อนสถานะพร้อมกัน (ลากเลื่อนไม่ได้)'
    return
  }
  if (!valid) {
    // Name the ONE stage this referral may move to, instead of restating
    // the general rule — with per-template journeys, "the next stage"
    // differs per card and a generic sentence no longer tells the admin
    // which column to aim at.
    errorMessage.value = r.pipeline.next_stage
      ? `เลื่อนได้ทีละขั้นเท่านั้น — รายการนี้ไปได้เฉพาะขั้น “${stageLabelTh(r.pipeline.next_stage)}”`
      : 'รายการนี้ไม่มีขั้นถัดไปตามเส้นทางการขายของตัวเอง'
    return
  }
  errorMessage.value = ''
  advance(r)
}
function onDragEnd(): void {
  clearDrag()
  // Clear the click-guard on the next tick, after any (suppressed) click.
  setTimeout(() => {
    dragOccurred = false
  }, 0)
}
function clearDrag(): void {
  draggedId.value = null
  dragOverKey.value = null
}
function onCardClick(r: ReferralItem): void {
  if (dragOccurred) return
  openTrail(r)
}

const advancing = ref<number | null>(null)
async function advance(referral: ReferralItem) {
  // ADR-026 — never send a move this referral's own template does not
  // allow. The UI already hides the affordance; this is the second half,
  // so the admin never learns the rule from a 422.
  if (!referral.pipeline.next_stage) return
  advancing.value = referral.id
  errorMessage.value = ''
  try {
    await api.post(`/referrals/${referral.id}/advance`)
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ดำเนินการไม่สำเร็จ (${e.status})` : 'ดำเนินการไม่สำเร็จ'
  } finally {
    advancing.value = null
  }
}

// ── TASK-176 §4.2 — confirm the ORDER, not the stage ──────────────
//
// The other half of §4.1's one door. `advance()` above moves the
// referral (and fires BR-4 on the way past Complete Payment) but leaves
// the order `pending` forever — which leaves a live public /pay/{token}
// page for a sale that is already booked and already paid commission
// on. This closes the bill instead: the server marks the order paid,
// stamps `paid_at` + `verified_by_user_id`, and advances the referral
// itself. That is why the two buttons are mutually exclusive rather
// than merely ordered.
//
// Routed through ConfirmDialog (TASK-066 — no window.confirm() in this
// app) because it writes an IMMUTABLE ledger row (BR-4) and closes a
// customer's bill. It is not a toggle and there is no undo.
const pendingConfirm = ref<{ referral: ReferralItem; order: ReferralOrder } | null>(null)
const confirmingOrderId = ref<number | null>(null)

function askConfirmOrder(referral: ReferralItem): void {
  // Re-checked here, not just in the template: the affordance and the
  // action must agree even if a reload changed the card underneath.
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
    await api.post(`/orders/${pending.order.id}/confirm`)
    pendingConfirm.value = null
    await loadAll()
  } catch (e) {
    pendingConfirm.value = null
    errorMessage.value =
      e instanceof ApiError ? `ยืนยันการชำระเงินไม่สำเร็จ (${e.status})` : 'ยืนยันการชำระเงินไม่สำเร็จ'
  } finally {
    confirmingOrderId.value = null
  }
}

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
    errorMessage.value = e instanceof ApiError ? `โหลดประวัติไม่สำเร็จ (${e.status})` : 'โหลดประวัติไม่สำเร็จ'
  } finally {
    loadingLogs.value = false
  }
}
function closeDrawer() {
  selectedReferralId.value = null
  stageLogs.value = []
}
// TASK-048 cross-link — jump to this deal's customer on the Clients page,
// auto-opening that client's drawer there (?open=<clientId>).
function goToClient(clientId: number) {
  router.push({ name: 'client-management', query: { open: String(clientId) } })
}
function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAll() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="pipeline"
      title="Referral & Pipeline"
      subtitle="ภาพรวม Referral ทั้งบริษัท"
      description="กระดาน Pipeline — ลากการ์ดไปคอลัมน์ถัดไปเพื่อเลื่อนสถานะ (เลื่อนได้ทีละขั้นตามเส้นทางการขายของรายการนั้น ห้ามข้าม/ย้อน) ทุกการเปลี่ยนบันทึก audit log"
      :kpis="kpis"
      accent-color="brand"
      storage-key="admin-pipeline"
    />

    <CompanyScopeNotice action="จัดการ pipeline" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!referrals.length" icon="pipeline" title="ยังไม่มี Referral" class="mt-4" />
      <template v-else>
        <!-- ADR-026 §4 — journey picker. A Kanban's columns are ONE
             shared axis, so referrals on different templates cannot be
             on the same board; this switches between them instead of
             merging them into columns half the cards can never reach.
             Hidden entirely when every referral shares one journey, so
             a single-template company sees no new chrome. -->
        <div v-if="journeyBoards.length > 1" class="mt-4 flex flex-wrap items-center gap-2">
          <span class="text-xs font-bold text-slate-500">เส้นทางการขาย:</span>
          <button
            v-for="board in journeyBoards"
            :key="board.signature"
            type="button"
            class="px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors"
            :class="board.signature === activeJourney
              ? 'bg-brand-600 border-brand-600 text-white'
              : 'bg-white border-slate-200 text-slate-600 hover:border-brand-300'"
            @click="activeJourney = board.signature"
          >
            {{ board.label }}
            <span class="ml-1 opacity-70">({{ board.items.length }})</span>
          </button>
        </div>

        <!-- Fail-closed: a journey whose stages could not be read has no
             columns to draw. Say so rather than rendering an empty board
             that looks like "no referrals". -->
        <div
          v-if="activeBoard && !activeBoard.stages.length"
          class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700"
        >
          อ่านเส้นทางการขายของ {{ activeBoard.items.length }} รายการนี้ไม่ได้ (pipeline template ถูกลบหรือไม่มีขั้นตอน) —
          ต้องแก้ไขการตั้งค่าก่อนจึงจะเลื่อนสถานะได้
        </div>

        <!-- Stranded cards: at a stage their own journey no longer
             contains, so no column can hold them. Named explicitly so
             they are never silently missing from the board. -->
        <div
          v-if="orphanedReferrals.length"
          class="mt-4 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-sm text-amber-700"
        >
          มี {{ orphanedReferrals.length }} รายการค้างอยู่ที่ขั้นซึ่งไม่มีในเส้นทางการขายของตัวเองแล้ว
          ({{ orphanedReferrals.map((r) => r.client?.name ?? '—').join(', ') }}) — ไม่แสดงบนกระดานและเลื่อนสถานะไม่ได้
          จนกว่าจะแก้ไข pipeline template
        </div>

        <!-- Kanban board (TASK-048 Phase 2, made template-aware by
             ADR-026). Columns = the SELECTED journey's own stages, in its
             order. Horizontal scroll on narrow viewports; the per-card
             button is the touch-friendly fallback for drag. -->
        <div v-if="activeBoard && activeBoard.stages.length" class="mt-4 flex gap-3 overflow-x-auto pb-2">
          <div
            v-for="col in columns"
            :key="col.key"
            class="shrink-0 w-72 flex flex-col rounded-xl border transition-colors"
            :class="dragOverKey === col.key ? 'border-brand-400 bg-brand-50/60' : 'border-slate-200 bg-slate-50/60'"
            data-test="column"
            :data-stage-key="col.key"
            @dragover="onDragOver(col.key, $event)"
            @dragleave="onDragLeave(col.key)"
            @drop="onDrop(col.key)"
          >
            <div class="px-3 py-2 border-b border-slate-200 flex items-center justify-between sticky top-0">
              <span class="text-xs font-bold text-slate-700 truncate">{{ col.label }}</span>
              <span class="text-xs font-bold text-slate-400 shrink-0 ml-2">{{ col.items.length }}</span>
            </div>
            <div class="p-2 space-y-2 min-h-[90px]">
              <p v-if="!col.items.length" class="text-xs text-slate-300 text-center py-6">— ว่าง —</p>
              <!--
                TASK-176 §4.1 follow-up (ag-lead ruling, 2026-08-13) —
                one door, counting the drag gesture as a door.

                `draggable` is the NEGATION OF THE SAME PREDICATE that
                picks the button below; there is deliberately no second
                condition here to keep as its inverse. A card offering
                "รับชำระเงินแล้ว" cannot be dragged to the next column,
                because that drag would POST /advance and book BR-4
                commission while the bill stays open (§2).

                The cursor carries the state so the card does not read as
                broken: `cursor-move` when it can be dragged,
                `cursor-pointer` when the only gesture left is the click
                that opens the audit-trail drawer.
              -->
              <div
                v-for="r in col.items"
                :key="r.id"
                :draggable="!canConfirmOrder(r)"
                class="bg-white border border-slate-200 rounded-lg p-3 hover:shadow-sm transition-all"
                :class="[
                  draggedId === r.id ? 'opacity-40' : '',
                  canConfirmOrder(r) ? 'cursor-pointer' : 'cursor-move',
                ]"
                data-test="referral-card"
                @dragstart="onDragStart(r)"
                @dragend="onDragEnd"
                @click="onCardClick(r)"
              >
                <p class="text-sm font-bold text-slate-900 truncate">{{ r.client?.name }}</p>
                <!-- TASK-134a — NULL branch = sold through a shared link. -->
                <p class="text-xs text-slate-400 truncate mt-0.5">
                  {{ r.product?.name }} · {{ r.branch ?? 'ไม่ระบุสาขา' }}
                </p>
                <p class="text-xs text-slate-400 truncate">
                  Agent: {{ r.agent?.name ?? '—' }}
                  <span v-if="r.current_stage.key === 'ongoing_next_meeting' && r.meeting_number"> · นัดครั้งที่ {{ r.meeting_number }}</span>
                </p>
                <!-- TASK-176 §1.2/§4.4 — the order behind this deal, so
                     an admin can see WHAT they are about to confirm (and
                     that there is a slip to check first) without opening
                     anything. BR-3: satang → baht happens in formatBaht,
                     here, and nowhere else. -->
                <p v-if="r.order" class="text-xs text-slate-400 truncate mt-0.5">
                  {{ r.order.order_number }} · {{ formatBaht(r.order.amount_satang) }} บาท ·
                  {{ r.order.status_label }}<span v-if="r.order.has_slip"> · มีสลิปแนบ</span>
                </p>
                <!-- §4.3 requirement #2 — "ยืนยันด้วย Admin". Only on a
                     closed bill, and never blank (see verifiedByLine). -->
                <p v-if="r.order && r.order.status === 'paid'" class="text-xs text-slate-400 truncate">
                  {{ verifiedByLine(r.order) }}
                </p>

                <!--
                  TASK-176 §4.1 — ONE DOOR, NEVER TWO.

                  This is a single v-if / v-else-if / v-else chain on
                  purpose: exactly one of the three branches can ever
                  render, so "the confirm button replaces the advance
                  button" is guaranteed by the compiler rather than by two
                  conditions that have to be kept each other's inverse.
                  Splitting it into two independent `v-if`s is the change
                  that would break it — an admin must never be shown two
                  buttons and left to work out which one books commission.

                  Order matters: confirm WINS over advance, because when
                  both are possible the advance is the one that leaves the
                  customer's public payment page open forever (§2).
                -->
                <button
                  v-if="canConfirmOrder(r)"
                  :disabled="confirmingOrderId !== null"
                  class="mt-2 w-full px-2 py-1 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700 disabled:opacity-50 truncate"
                  data-test="confirm-order"
                  @click.stop="askConfirmOrder(r)"
                >
                  {{ confirmingOrderId === r.order?.id ? 'กำลังดำเนินการ...' : 'รับชำระเงินแล้ว' }}
                </button>
                <!-- ADR-026 — only offered when this referral's own
                     template has a next stage, and it names that stage so
                     the admin knows which column to drag to. -->
                <button
                  v-else-if="r.pipeline.next_stage"
                  :disabled="advancing === r.id"
                  class="mt-2 w-full px-2 py-1 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 disabled:opacity-50 truncate"
                  data-test="advance"
                  @click.stop="advance(r)"
                >
                  {{ advancing === r.id ? 'กำลังดำเนินการ...' : `ไป: ${stageLabelTh(r.pipeline.next_stage)}` }}
                </button>
                <p v-else class="mt-2 text-center text-xs font-bold text-slate-400">จบเส้นทางแล้ว</p>
              </div>
            </div>
          </div>
        </div>
      </template>
    </template>

    <Transition name="drawer">
      <div v-if="selectedReferral" class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-slate-900/30" @click="closeDrawer" />
        <!-- Human request (2026-07-23): detail drawers widened to 60% of the
             viewport, consistent with the 60vw add/edit modals. -->
        <div class="drawer-panel relative w-[60vw] min-w-[320px] max-w-[60vw] bg-white h-full shadow-xl p-5 overflow-y-auto">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-slate-900">{{ selectedReferral.client?.name }}</h2>
            <button class="text-slate-400 hover:text-slate-600" @click="closeDrawer"><Icon name="close" :size="20" /></button>
          </div>
          <!-- TASK-048 cross-link — jump to this deal's customer profile. -->
          <button
            v-if="selectedReferral.client"
            type="button"
            class="mb-2 text-xs font-bold text-brand-600 hover:text-brand-700 flex items-center gap-1"
            @click="goToClient(selectedReferral.client.id)"
          >
            <Icon name="user" :size="12" /> ดูโปรไฟล์ลูกค้า
          </button>
          <p class="text-sm text-slate-600">
            {{ selectedReferral.product?.name }} · {{ selectedReferral.branch ?? 'ไม่ระบุสาขา' }}
          </p>
          <p class="text-xs text-slate-400 mt-1">
            Agent: {{ selectedReferral.agent?.name ?? '—' }} · สถานะปัจจุบัน: {{ stageLabelTh(selectedReferral.current_stage) }}
          </p>
          <!-- ADR-026 §3.4 — the journey snapshotted onto THIS referral at
               creation and never re-resolved, so an admin editing a
               template later cannot reroute a customer already mid-way. -->
          <div v-if="selectedReferral.pipeline.stages.length" class="mt-3 flex flex-wrap items-center gap-1.5">
            <template v-for="(stage, idx) in selectedReferral.pipeline.stages" :key="stage.key">
              <span v-if="idx > 0" class="text-slate-300 text-xs">→</span>
              <span
                class="px-2 py-0.5 rounded-lg text-[11px] font-bold"
                :class="stage.key === selectedReferral.current_stage.key
                  ? 'bg-brand-50 text-brand-700'
                  : 'bg-slate-50 text-slate-500 border border-slate-200'"
              >
                {{ stageLabelTh(stage) }}
              </span>
            </template>
          </div>
          <p v-else class="mt-3 text-xs font-bold text-rose-600">
            อ่านเส้นทางการขายของรายการนี้ไม่ได้ — pipeline template ถูกลบหรือไม่มีขั้นตอน
          </p>

          <h3 class="mt-5 mb-2 text-sm font-bold text-slate-700 flex items-center gap-2">
            <Icon name="list" :size="16" /> ประวัติการเปลี่ยนสถานะ (audit log)
          </h3>
          <EmptyState v-if="!loadingLogs && !stageLogs.length" icon="list" title="ยังไม่มีประวัติ" />
          <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
            <div v-for="log in stageLogs" :key="log.id" class="p-3 rounded-lg border border-slate-200 text-sm">
              <p class="font-bold text-slate-800">
                <span v-if="log.from_stage">{{ stageLabelTh(log.from_stage) }} →</span>
                {{ stageLabelTh(log.to_stage) }}
              </p>
              <p class="text-xs text-slate-400 mt-0.5">โดย {{ log.changed_by?.name ?? '—' }} · {{ formatDateTime(log.changed_at) }}</p>
            </div>
          </TransitionGroup>
        </div>
      </div>
    </Transition>

    <!--
      TASK-176 §4.2. Inside <main> deliberately: a sibling at the root of
      the template makes this component multi-root, which breaks App.vue's
      <Transition mode="out-in"> around <RouterView> (the fix applied
      across 8 views in this app).

      Wording is the spec's, verbatim — it names the amount and the order,
      and says plainly that the commission is written immediately and
      cannot be edited afterwards (BR-4). "warning", not "danger": nothing
      is being destroyed, but there is no undo.
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
  </main>
</template>
