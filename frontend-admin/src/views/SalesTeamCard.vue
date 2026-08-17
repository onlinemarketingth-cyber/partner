<script setup lang="ts">
/**
 * SalesTeamCard — one agent card in the "ทีมขาย" 3-column grid (TASK-050
 * redesign r2, human request 2026-07-23). A leader card lists its downline
 * as compact summary ROWS in order, plus a "ขยายดูลูกทีม" toggle that opens
 * the 60vw team modal, so the 3-column grid never shifts.
 *
 * TASK-125 / ADR-025 §2 — the card now renders TWO INDEPENDENT FACTS with
 * two separate affordances, because they are genuinely different things and
 * an admin has to be able to tell them apart:
 *
 *   • `is_team_leader` — the DESIGNATION an admin granted (the capability to
 *     mint recruit links / approve own recruits). Shown as the GOLD chrome:
 *     top bar, gold avatar, gold border and the gold "หัวหน้าทีม" badge.
 *   • `children.length > 0` — the emergent FACT that people report to them.
 *     Shown as the "ดูแลลูกทีม N คน" line + the ลูกทีม downline block.
 *
 * They used to be one thing (`isLeader = children.length > 0`). Merging them
 * again would make two real states unreadable: a freshly designated leader
 * with nobody under them yet would look like a plain agent (so an admin
 * could not see the designation took effect), and an agent who quietly
 * accumulated reports without ever being designated would look like they
 * hold a capability they do not have.
 */
import { computed, inject, ref } from 'vue'
import Icon from '@/design-system/components/Icon.vue'
// TASK-179 §4.1 — the ONE renderer of a server-sent deals_by_stage map.
// This card used to read five named keys off a five-key interface; the
// three post-sale stages ADR-026 added were therefore invisible here, so
// the per-stage strip could not add up to the ดีลทั้งหมด shown above it.
import { stageCounts } from '@/utils/pipelineStages'
import {
  type CertTierOption,
  type TeamNode,
  initial,
  formatPct,
  formatBaht,
  isPendingApproval,
  CERT_TIERS,
  GRANT_CERTIFICATION,
  GRANTING_TIER_KEY,
  GRANT_ERROR,
  GRANT_ERROR_AGENT_ID,
  OPEN_AGENT_CLIENTS,
  OPEN_AGENT_EDITOR,
  OPEN_TEAM_MODAL,
  PASSED_TIER_IDS_BY_AGENT,
  ALL_AGENTS,
  SET_TEAM_LEADER,
  CHANGE_MANAGER,
  STRUCTURE_SAVING_AGENT_ID,
  STRUCTURE_ERROR,
  STRUCTURE_ERROR_AGENT_ID,
  APPROVE_AGENT,
  REJECT_AGENT,
  APPROVAL_SAVING_AGENT_ID,
  APPROVAL_ERROR,
  APPROVAL_ERROR_AGENT_ID,
} from './salesTeam'

const props = defineProps<{
  node: TeamNode
  /**
   * TASK-127 (human, 2026-08-05): "ในหน้าหัวหน้าทีมไม่ควรซ้ำซ้อนว่าเป็น
   * หัวหน้าทีมของคนนี้อีก".
   *
   * Inside the tab titled หัวหน้าทีม every top-level card is a leader by the
   * tab's own definition, so the gold badge + gold chrome say nothing the tab
   * header has not already said — and once most agents are granted the flag,
   * a wall of gold stops distinguishing anything at all.
   *
   * What still carries information THERE is the EXCEPTION: an agent who has
   * people reporting to them but has not been granted the permission. So in
   * this tab only the grey "ยังไม่ได้ให้สิทธิ์" chip renders.
   *
   * Everywhere else the badge stays: in ตัวแทนอิสระ a designated leader with
   * no reports yet is genuinely notable, and inside the downline modal a
   * nested sub-leader must still be distinguishable from a plain member.
   *
   * 2026-08-17 (human-reported bug) — this suppression assumed "every card
   * in this tab is a tree root", which is only true in the default
   * hierarchy render. See `flat` below for the case that breaks it.
   */
  inLeadersTab?: boolean
  /**
   * 2026-08-17 (human-reported bug) — SalesTeamView flattens the "หัวหน้าทีม"
   * tab's tree during a search/sort (its own `isFlat`/`searchPool`
   * comments explain why: "a nested team member is still findable by name
   * from the leader tab"). When that happens, THIS card can be a plain
   * nested member who only surfaced because their name matched — not a
   * tree root — even though `inLeadersTab` is still true. Two things on
   * this card assumed `inLeadersTab` meant "definitely a root" and need to
   * un-assume that when `flat` is also true:
   *   - `showLeaderBadge` — a flattened result can mix real leaders with
   *     plain members, so the badge on an actual leader's card is no
   *     longer "restating the tab", it is the only thing telling the two
   *     apart in a list that no longer says "everyone here leads a team".
   *   - the "อยู่ใต้" (manager) select below — TASK-127 r2 hid it in this
   *     tab because a root's manager is always "— ไม่มีหัวหน้า —"; a
   *     flattened member is not a root and DOES have a real manager, and
   *     hiding it was exactly what made this bug report look like the
   *     agent had been misplaced.
   * Forwarded from SalesTeamGrid's own `preSorted` prop — see that
   * component's docblock for the same reasoning one level up.
   */
  flat?: boolean
  /**
   * TASK-203 — true only when this card is being rendered on the
   * "รออนุมัติเข้าทีม" tab. The amber "รออนุมัติ" chip below is NOT gated by
   * this prop (a pending agent can be nested inside a leader's card on
   * หัวหน้าทีม, or sit in ลูกทีม, and must still be visibly flagged there) —
   * only the Approve/Reject BUTTONS are, so the mutation trigger exists in
   * exactly one place instead of duplicated across every tab a pending
   * agent might incidentally appear on. Forwarded from SalesTeamGrid, same
   * pass-through pattern as `inLeadersTab`.
   */
  showApprovalActions?: boolean
}>()

// TASK-125 — the admin-granted DESIGNATION (ADR-025 §1). Drives the gold
// chrome + the "หัวหน้าทีม" badge, and nothing else.
const isDesignatedLeader = computed(() => props.node.is_team_leader)
/**
 * TASK-127 r3 (human: "เมื่อตั้งค่าหัวหน้าทีม นำแถบสีทองแบบเดิมกลับมา").
 *
 * The gold COLOUR and the gold WORDS were removed together in r1; only the
 * words were the redundancy. Inside a tab titled หัวหน้าทีม, a chip reading
 * "หัวหน้าทีม" repeats the tab heading on every card — but the bar is not a
 * label, it is a scan signal: it answers "who actually holds the permission"
 * across a grid at a glance, which is exactly the question the tab cannot
 * answer on its own (the tab also contains agents who merely have reports).
 *
 * So they are two decisions now, not one:
 *   showLeaderChrome — the gold bar / border / avatar. Follows the flag
 *                      EVERYWHERE, including the leaders tab.
 *   showLeaderBadge  — the "หัวหน้าทีม" text chip. Suppressed in that tab.
 */
const showLeaderChrome = computed(() => isDesignatedLeader.value)
// 2026-08-17 — suppressed only in the TRUE root view of the leaders tab
// (inLeadersTab && !flat). A flattened search/sort result can mix leaders
// with plain members, so the badge is real information there again — see
// the `flat` prop's own docblock above.
const showLeaderBadge = computed(() => isDesignatedLeader.value && !(props.inLeadersTab && !props.flat))
// TASK-125 — the emergent FACT that people report to them. Drives the
// "ดูแลลูกทีม N คน" line, the downline list and the expand control.
const hasReports = computed(() => props.node.children.length > 0)
// A designated leader who has recruited nobody yet. Rendered EXPLICITLY (an
// "ยังไม่มีลูกทีม" line) rather than hidden, because "I flagged them and
// nothing visibly changed" is indistinguishable from a bug.
const isDesignatedWithoutReports = computed(() => isDesignatedLeader.value && !hasReports.value)

const openAgentClients = inject(OPEN_AGENT_CLIENTS)
function viewClients() {
  openAgentClients?.(props.node)
}

// "ขยายดูลูกทีม" opens the 60vw team modal (provided by SalesTeamView).
const openTeamModal = inject(OPEN_TEAM_MODAL)
function expandTeam() {
  openTeamModal?.(props.node)
}

// TASK-062 — "grant cert without exam" (TASK-058), same UX already
// built on AcademyManagementView.vue's progress tab and
// AgentManagementView.vue — data + mutation come from SalesTeamView via
// provide/inject (see salesTeam.ts) rather than prop-drilling through
// SalesTeamGrid.
const certTiers = inject(CERT_TIERS)
const passedTierIdsByAgent = inject(PASSED_TIER_IDS_BY_AGENT)
const grantCertificationFn = inject(GRANT_CERTIFICATION)
const grantingTierKey = inject(GRANTING_TIER_KEY)
const grantError = inject(GRANT_ERROR)
const grantErrorAgentId = inject(GRANT_ERROR_AGENT_ID)
// Scoped so only the card whose grant actually failed shows the error —
// grantError itself is a single shared ref (see salesTeam.ts docblock).
const grantErrorForThisAgent = computed(() =>
  grantErrorAgentId?.value === props.node.agent_id ? (grantError?.value ?? '') : '',
)
const notYetPassedTiers = computed<CertTierOption[]>(() => {
  const passed = passedTierIdsByAgent?.value.get(props.node.agent_id) ?? new Set<number>()
  return (certTiers?.value ?? []).filter((t) => !passed.has(t.id))
})
function grantCertification(tier: CertTierOption) {
  grantCertificationFn?.(props.node.agent_id, props.node.agent_name, tier)
}

// ═══ TASK-126 — the two STRUCTURAL edits (human request 2026-08-05) ═══
// Until now this card had three actions and only one of them wrote anything
// (the cert grant); nothing about the agent themself could be changed from
// the page whose entire subject is who reports to whom. The two facts the
// card already RENDERED read-only — the gold "หัวหน้าทีม" badge and the
// downline — are now editable, and nothing else is: identity/PDPA/bank
// fields stay on จัดการตัวแทน (see salesTeam.ts's ALL_AGENTS docblock), which
// the link at the bottom of this block routes to.
const allAgents = inject(ALL_AGENTS)
const setTeamLeaderFn = inject(SET_TEAM_LEADER)
const changeManagerFn = inject(CHANGE_MANAGER)
const structureSavingAgentId = inject(STRUCTURE_SAVING_AGENT_ID)
const structureError = inject(STRUCTURE_ERROR)
const structureErrorAgentId = inject(STRUCTURE_ERROR_AGENT_ID)

// Both refs are shared across every card (the mutations live in
// SalesTeamView), so both are scoped by agent id before rendering —
// otherwise one failed save would paint an error on every card at once.
const isSavingStructure = computed(() => structureSavingAgentId?.value === props.node.agent_id)
const structureErrorForThisAgent = computed(() =>
  structureErrorAgentId?.value === props.node.agent_id ? (structureError?.value ?? '') : '',
)

/**
 * Manager candidates: every other agent the page loaded, plus the
 * "no manager" option rendered in the template.
 *
 * "Same company" is not filtered here because it cannot be, honestly:
 * /sales-team-overview returns no company_id per row. For a Company Admin
 * the whole roster IS one company (the endpoint scopes to their own
 * company_id), so the list is already correct. A Super Admin browsing
 * without a ?company_id filter can see several companies at once and could
 * pick a cross-company manager — UserService::assertValidManager rejects
 * exactly that, and the 422 lands inline under this control (BR-6: the
 * server is the guard, this dropdown is a convenience). Inventing a
 * client-side company filter out of data we do not have would be worse than
 * letting the real check speak.
 */
const managerOptions = computed(() =>
  (allAgents?.value ?? [])
    .filter((a) => a.agent_id !== props.node.agent_id)
    .slice()
    .sort((a, b) => (a.agent_name ?? '').localeCompare(b.agent_name ?? '')),
)

/**
 * TASK-130 §2a — GRANT only, never revoke (the button is not rendered once
 * the flag is on; see the template). Hard-codes `true` rather than negating
 * the current value so this cannot become a toggle again by accident: the
 * card must never be able to ask for a revoke, which is a modal-only action.
 */
function grantTeamLeader() {
  setTeamLeaderFn?.(props.node.agent_id, props.node.agent_name, true)
}

// Not v-model: the source of truth is the prop, which is re-read from the
// server after every save (the tree re-shapes). Binding v-model to a local
// copy would leave the select showing a value the server rejected.
function onManagerChange(event: Event) {
  const raw = (event.target as HTMLSelectElement).value
  const next = raw === '' ? null : Number(raw)
  if (next === (props.node.manager_id ?? null)) return
  changeManagerFn?.(props.node.agent_id, next)
}

/**
 * TASK-129 (human, 2026-08-05: "ผมต้องการกดแก้ไข หน้านี้เปิด Modal และไม่ใช้
 * หน้าในรูปที่ 2 อีก") — the pencil opens the full agent editor IN PLACE.
 *
 * It used to be a RouterLink to จัดการตัวแทน carrying `?q=` + `?edit=`, for
 * one reason only: the edit form existed nowhere but inside that view, so a
 * navigation was the only way to reach it without writing a second copy of
 * it. TASK-129 extracted it into <AgentEditModal>, which removes that
 * constraint entirely — SalesTeamView mounts one, and this button only has to
 * name an agent. The old rule still holds and is now better served: exactly
 * ONE implementation of identity/PDPA/bank editing, and no page change.
 *
 * Routed through inject like every other action on this card, because a card
 * can sit inside a SalesTeamGrid, including the one in the ขยายดูลูกทีม modal.
 */
const openAgentEditor = inject(OPEN_AGENT_EDITOR)
function editAgent() {
  openAgentEditor?.(props.node.agent_id)
}

// ═══ TASK-203 — approve/reject a pending agent from the card ═══
// Same per-card busy/error scoping as the structural edits above (one
// shared ref, scoped by agent id) — see STRUCTURE_SAVING_AGENT_ID's own
// comment for why a shared ref rather than local state: the mutation
// itself runs in SalesTeamView so the roster reload after success is
// guaranteed, not something each card would have to remember to trigger.
const isPending = computed(() => isPendingApproval(props.node))
const approveAgentFn = inject(APPROVE_AGENT)
const rejectAgentFn = inject(REJECT_AGENT)
const approvalSavingAgentId = inject(APPROVAL_SAVING_AGENT_ID)
const approvalError = inject(APPROVAL_ERROR)
const approvalErrorAgentId = inject(APPROVAL_ERROR_AGENT_ID)
const isSavingApproval = computed(() => approvalSavingAgentId?.value === props.node.agent_id)
const approvalErrorForThisAgent = computed(() =>
  approvalErrorAgentId?.value === props.node.agent_id ? (approvalError?.value ?? '') : '',
)

function approveThisAgent() {
  approveAgentFn?.(props.node.agent_id, props.node.agent_name)
}

// Inline reason box, same shape as AgentManagementView's "รออนุมัติ" tab
// (a text input revealed by "ปฏิเสธ", not a ConfirmDialog — see the
// SalesTeamView docblock next to its approve/reject functions for why that
// UX was replicated rather than redesigned). Local to this card, not
// shared state: only one card's box needs to be open at a time and nothing
// else on the page depends on which one that is.
const showRejectBox = ref(false)
const rejectReasonLocal = ref('')
function toggleRejectBox() {
  showRejectBox.value = !showRejectBox.value
}
function submitReject() {
  rejectAgentFn?.(props.node.agent_id, props.node.agent_name, rejectReasonLocal.value)
}

/**
 * TASK-179 §4.1 — whatever stages the server sent for THIS agent, in the
 * order it sent them. The grid below is `grid-cols-4`, which wraps to as
 * many rows as the stage set needs, so adding or removing a stage
 * server-side changes nothing here.
 */
const stages = computed(() => stageCounts(props.node.deals_by_stage))
</script>

<template>
  <div
    class="bg-white/95 rounded-2xl flex flex-col overflow-hidden hover:shadow-sm transition-shadow border"
    :class="showLeaderChrome ? 'border-amber-200' : 'border-slate-200'"
  >
    <!-- Gold top bar marks the admin-granted DESIGNATION (is_team_leader),
         not "has people" — see the docblock. Rendered in EVERY context
         including the หัวหน้าทีม tab: that tab holds both granted and
         not-yet-granted agents, so the bar is the fastest way to tell them
         apart across a grid (TASK-127 r3, human request). -->
    <div v-if="showLeaderChrome" class="h-1.5 bg-amber-400"></div>

    <div class="p-4 flex flex-col gap-3">
      <!-- Header -->
      <div class="flex items-center gap-3">
        <img v-if="node.avatar_url" :src="node.avatar_url" alt="" class="w-11 h-11 rounded-full object-cover shrink-0" />
        <div
          v-else
          class="w-11 h-11 rounded-full flex items-center justify-center text-base font-bold shrink-0 text-white"
          :class="showLeaderChrome ? 'bg-amber-500' : 'bg-brand-500'"
        >
          {{ initial(node.agent_name) }}
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2">
            <p class="text-sm font-bold text-slate-900 truncate">{{ node.agent_name ?? '—' }}</p>
            <!-- FACT 1 — the designation. Badge ⇔ is_team_leader, 1:1,
                 EXCEPT inside the หัวหน้าทีม tab where the WORDS restate the
                 tab title on every card (TASK-127). The gold bar above still
                 renders there — see showLeaderChrome vs showLeaderBadge. -->
            <span
              v-if="showLeaderBadge"
              class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-600 shrink-0"
            >
              <Icon name="star" :size="10" /> หัวหน้าทีม
            </span>
            <!--
              Human-reported 2026-08-05: "ทำไม Thai Life ขึ้นแถบทองอยู่คนเดียว
              ทั้งที่ card อื่นก็หัวหน้าทีม". It was correct — only that agent
              has been granted the flag — but the OTHER state was rendered as
              NOTHING, and an absent badge is indistinguishable from a bug.
              An admin cannot tell "not granted" from "we forgot to draw it".
              So the not-granted case is now stated out loud, in neutral grey
              so it reads as information rather than as a warning.

              TASK-126 — the tooltip used to send the admin to จัดการตัวแทน as
              the only place to grant the flag. It is now grantable from the
              โครงสร้างทีม block at the bottom of this very card, so the copy
              points there instead of off-page.
            -->
            <span
              v-else-if="hasReports"
              class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-500 shrink-0"
              title="ยังไม่ได้รับสิทธิ์หัวหน้าทีม — ให้สิทธิ์ได้ที่ ‘โครงสร้างทีม’ ด้านล่างของการ์ดนี้"
            >
              ยังไม่ได้ให้สิทธิ์
            </span>
            <!-- TASK-203 — visible on ANY tab a pending agent happens to
                 appear on (nested in หัวหน้าทีม, flat in ลูกทีม, standalone in
                 รออนุมัติเข้าทีม), not just the dedicated tab: this is the fix
                 for "pending agents render indistinguishably from active
                 ones" — SalesTeamOverviewService always included them, this
                 card just never said so. The Approve/Reject ACTIONS below are
                 still scoped to `showApprovalActions` only. -->
            <span
              v-if="isPending"
              class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 shrink-0"
              title="สมัครเข้าทีมแล้วแต่ยังไม่ได้รับการอนุมัติ"
            >
              <Icon name="clock" :size="10" /> รออนุมัติ
            </span>
          </div>
          <!-- FACT 2 — the reports. Line ⇔ children.length, 1:1. An agent
               with people but no badge is exactly "has a team, was never
               designated"; a badge with no line is "designated, no team yet". -->
          <p class="text-xs text-slate-400 truncate">
            <template v-if="hasReports">ดูแลลูกทีม {{ node.children.length }} คน</template>
            <template v-else>ตัวแทนขาย</template>
          </p>
        </div>

        <!--
          TASK-127 (human, 2026-08-05): "ย้ายปุ่ม ลูกค้าของตัวแทนนี้ ปุ่มแก้ไข
          ไปอยู่ด้านขวา".

          These two OPEN something over the card — a drawer and, since
          TASK-129, the agent editor modal — while everything below is state
          you change in place. Stacking them at the bottom put them in the same
          visual queue as the mutations, so the card read as five unrelated
          stacked strips. On the header row they sit where a row's "open this"
          controls are expected, and the body below is left as one column of
          things you can change.

          Icon-only with a `title`, because at three columns a labelled pair
          would wrap the name; both are ≥32px and sit outside the truncating
          name block so they never get squeezed.
        -->
        <div class="flex items-center gap-1 shrink-0">
          <button
            type="button"
            class="w-8 h-8 rounded-lg border border-slate-200 text-slate-500 hover:text-brand-600 hover:border-brand-300 flex items-center justify-center"
            title="ดูลูกค้าของตัวแทนนี้"
            @click="viewClients"
          >
            <Icon name="user" :size="14" />
          </button>
          <!-- TASK-129 — a button, not a RouterLink: this opens the edit
               modal on THIS page (see editAgent above). -->
          <button
            type="button"
            class="w-8 h-8 rounded-lg border border-slate-200 text-slate-500 hover:text-brand-600 hover:border-brand-300 flex items-center justify-center"
            title="แก้ไขข้อมูลตัวแทน"
            @click="editAgent"
          >
            <Icon name="edit" :size="14" />
          </button>
        </div>
      </div>

      <!-- 3 headline KPIs -->
      <div class="grid grid-cols-3 gap-2 text-center py-2 border-y border-slate-100">
        <div>
          <p class="text-lg font-bold text-slate-900 leading-none">{{ node.client_count }}</p>
          <p class="text-[10px] text-slate-400 font-bold mt-1">ลูกค้า</p>
        </div>
        <div>
          <p class="text-lg font-bold text-slate-900 leading-none">{{ node.total_deals }}</p>
          <p class="text-[10px] text-slate-400 font-bold mt-1">ดีลทั้งหมด</p>
        </div>
        <div>
          <p class="text-lg font-bold text-slate-900 leading-none">{{ formatPct(node.conversion) }}</p>
          <p class="text-[10px] text-slate-400 font-bold mt-1">อัตราปิด</p>
        </div>
      </div>

      <!--
        TASK-179 (D1/D2) — TWO DIFFERENT AXES, each under its own label:

          ยอดขาย  = money the CUSTOMER paid (paid orders). The "(จ่ายแล้ว)"
                    suffix is gone: on a money figure it reads as "we have
                    paid this out", which is the OTHER card. The number
                    itself also changed source — see salesTeam.ts.
          ค่าคอม (จ่ายแล้ว) = money the company DISBURSED to the agent. It
                    keeps its suffix because for that figure it is true and
                    load-bearing (pending commission is excluded).
      -->
      <div class="grid grid-cols-2 gap-2 text-center">
        <div class="rounded-lg bg-slate-50 py-1.5">
          <p class="text-sm font-bold text-slate-900 leading-none">฿{{ formatBaht(node.total_sales_satang) }}</p>
          <p class="text-[10px] text-slate-400 font-bold mt-1">ยอดขาย</p>
        </div>
        <div class="rounded-lg bg-slate-50 py-1.5">
          <p class="text-sm font-bold text-slate-900 leading-none">฿{{ formatBaht(node.total_commission_satang) }}</p>
          <p class="text-[10px] text-slate-400 font-bold mt-1">ค่าคอม (จ่ายแล้ว)</p>
        </div>
      </div>

      <!--
        §4.2 (D2) — what the ยอดขาย above could NOT count, stated plainly and
        ONLY when there is something to state. Same sentence as the dashboard
        so one figure reads the same way wherever it appears. When it is 0 the
        card says nothing: a caveat that is always on screen is a caveat
        nobody reads.
      -->
      <p v-if="node.closed_deals_without_order > 0" class="text-[10px] text-slate-500 leading-snug">
        อีก {{ node.closed_deals_without_order.toLocaleString('th-TH') }} ดีลปิดแล้วแต่ยังไม่มีคำสั่งซื้อ ยอดนี้จึงยังไม่รวม
      </p>

      <!-- Per-stage spread — every stage the server sent, in its order
           (TASK-179 §4.1). Wraps at 4 per row, so the layout does not
           assume a stage count either. -->
      <div class="grid grid-cols-4 gap-1">
        <div
          v-for="stage in stages"
          :key="stage.key"
          class="text-center rounded-lg py-1.5"
          :class="stage.count > 0 ? 'bg-brand-50' : 'bg-slate-50'"
        >
          <p class="text-sm font-bold leading-none" :class="stage.count > 0 ? 'text-brand-700' : 'text-slate-400'">
            {{ stage.count }}
          </p>
          <p class="text-[9px] text-slate-400 mt-1 leading-tight">{{ stage.label }}</p>
        </div>
      </div>

      <!-- TASK-125 — a designated leader who has recruited nobody yet. Stated
           outright so an admin can SEE the designation took effect instead of
           wondering whether the flag saved. No expand control: there is
           nothing to expand. -->
      <div v-if="isDesignatedWithoutReports" class="border-t border-slate-100 pt-2">
        <p class="text-[11px] font-bold text-slate-500 mb-0.5">ลูกทีม</p>
        <p class="text-xs text-amber-600 flex items-center gap-1">
          <Icon name="users" :size="12" />
          ยังไม่มีลูกทีม — ได้รับสิทธิ์หัวหน้าทีมแล้ว รอสมาชิกคนแรก
        </p>
      </div>

      <!-- Downline summary rows (agents who actually have reports) — compact, in order -->
      <div v-if="hasReports" class="border-t border-slate-100 pt-2">
        <div class="flex items-center justify-between mb-1.5">
          <p class="text-[11px] font-bold text-slate-500">ลูกทีม</p>
          <button type="button" class="text-[11px] font-bold text-amber-600 hover:text-amber-700 flex items-center gap-0.5" @click="expandTeam">
            <Icon name="users" :size="12" />
            ขยายดูลูกทีม ({{ node.children.length }})
          </button>
        </div>
        <div class="space-y-1">
          <div v-for="child in node.children" :key="child.agent_id" class="flex items-center gap-2 text-xs">
            <div class="w-5 h-5 rounded-full bg-brand-500 text-white flex items-center justify-center text-[9px] font-bold shrink-0">
              {{ initial(child.agent_name) }}
            </div>
            <span class="text-slate-700 truncate flex-1">{{ child.agent_name ?? '—' }}</span>
            <span class="text-slate-400 shrink-0">{{ child.total_deals }} ดีล</span>
          </div>
        </div>
      </div>

      <!--
        TASK-127 (human, 2026-08-05): "ปรับปุ่มการต่างๆ ให้อยู่ในแถวเดียวกัน …
        แถวที่ การอนุมัติ: อนุมัติรับรองไม่ต้องสอบ / ให้สิทธิ์หัวหน้าทีม".

        The cert grant (TASK-062) and the team-leader grant (TASK-126) arrived
        in different sprints and each built its own titled strip, so the card
        ended up with two headings for what an admin does in one motion:
        deciding what this person is allowed to do. They are one ROW now,
        under one heading, wrapping as needed.

        Both are still separate writes to different endpoints — merging the
        layout does not merge the permissions.
      -->
      <!-- TASK-130 §2a — with the revoke button gone, a leader who has also
           passed every tier has nothing left to approve here, and a heading
           over an empty row reads as a rendering bug. Drop the whole block in
           exactly that case. -->
      <div v-if="!isDesignatedLeader || notYetPassedTiers.length" class="border-t border-slate-100 pt-2">
        <p class="text-[11px] font-bold text-slate-500 mb-1.5">การอนุมัติ</p>
        <div class="flex flex-wrap items-center gap-1.5">
          <!-- The team-leader capability — the affordance the gold badge and
               the grey "ยังไม่ได้ให้สิทธิ์" chip above were missing.

               TASK-130 §2a (human, 2026-08-08) — GRANTING ONLY. This used to
               be one button that flipped to "ยกเลิกสิทธิ์หัวหน้าทีม" once the
               flag was on; now nothing renders here at all for an agent who
               already holds it. The two directions are not symmetrical:
               granting adds a capability and is undone by one click, while
               revoking silently stops every recruit link that agent already
               handed out from admitting anyone (RegistrationService::
               resolveActiveInviter) — a consequence a 10px button on a card
               cannot state. Revoking therefore lives ONLY in the edit modal,
               which is the one place that spells that out next to the toggle.
               The gold chrome and the grey chip above are untouched. -->
          <button
            v-if="!isDesignatedLeader"
            type="button"
            :disabled="isSavingStructure"
            class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border text-[10px] font-bold disabled:opacity-50 border-amber-500 bg-amber-500 text-white hover:bg-amber-600 hover:border-amber-600"
            @click="grantTeamLeader"
          >
            <Icon name="star" :size="11" />
            <span v-if="isSavingStructure">กำลังบันทึก...</span>
            <span v-else>ให้สิทธิ์หัวหน้าทีม</span>
          </button>

          <!-- TASK-062: grant-without-exam (BR-1 admin override, TASK-058) -->
          <button
            v-for="t in notYetPassedTiers"
            :key="t.id"
            type="button"
            :disabled="grantingTierKey === `${node.agent_id}:${t.id}`"
            class="inline-flex items-center px-2 py-1 rounded-lg border border-slate-200 text-[10px] font-bold text-slate-600 hover:border-brand-400 hover:text-brand-600 disabled:opacity-50"
            @click="grantCertification(t)"
          >
            {{ grantingTierKey === `${node.agent_id}:${t.id}` ? 'กำลังอนุมัติ...' : `+ ใบรับรอง ${t.name}` }}
          </button>
        </div>
        <p v-if="notYetPassedTiers.length" class="text-[10px] text-slate-400 mt-1 leading-snug">
          ใบรับรองที่อนุมัติจากตรงนี้ไม่ต้องสอบและไม่ได้รับ XP · สิทธิ์หัวหน้าทีม = สร้างลิงก์ชวนคนเข้าทีมและอนุมัติคนที่ตัวเองชวนมาได้เอง
        </p>
        <p v-if="grantErrorForThisAgent" class="text-[10px] text-rose-600 mt-1">{{ grantErrorForThisAgent }}</p>
      </div>

      <!-- Re-parent this agent. Changing it re-shapes the tree the admin is
           looking at, so SalesTeamView re-reads the roster on success (see
           reloadRoster there) rather than patching one row.

           TASK-127 — the label was "หัวหน้าของตัวแทนคนนี้", which repeated
           "หัวหน้า…ตัวแทน" on a card that is already about one agent inside a
           tab about team leaders. It is a select with an obvious subject; the
           short label is enough.

           TASK-127 r2 (human: "ทำไม อยู่ใต้ ใน tab หัวหน้าทีมยังอยู่ใต้ card")
           — HIDDEN in the หัวหน้าทีม tab. Every top-level card there is a tree
           ROOT, and buildTree defines a root as "has no effective manager", so
           the select could only ever read "— ไม่มีหัวหน้า —": a control whose
           value is determined by the tab you are standing in. Putting a leader
           underneath someone else is a rare structural move and belongs in the
           full editor, not on a card in the tab that lists them as tops.

           It STAYS in ตัวแทนอิสระ, where attaching an unattached agent to a
           team is the whole point of the tab, and inside the downline modal,
           where a member genuinely has a manager to change.

           2026-08-17 (human-reported bug) — also STAYS when `flat` is true,
           even inside หัวหน้าทีม: a search/sort result there can be a plain
           nested member (not a root), and hiding this was the whole
           mechanism behind "ค้นหาเจอที่หัวหน้าทีม" reading as if the agent
           had been mis-placed instead of just found by name where they
           already live. See the `flat` prop's own docblock above. -->
      <div v-if="!inLeadersTab || flat" class="border-t border-slate-100 pt-2">
        <label class="block text-[11px] font-bold text-slate-500 mb-1">อยู่ใต้</label>
        <select
          :value="node.manager_id ?? ''"
          :disabled="isSavingStructure"
          class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-[11px] text-slate-700 focus:outline-none focus:border-brand-400 disabled:opacity-50"
          @change="onManagerChange"
        >
          <option value="">— ไม่มีหัวหน้า —</option>
          <option v-for="m in managerOptions" :key="m.agent_id" :value="m.agent_id">
            {{ m.agent_name ?? `ตัวแทน #${m.agent_id}` }}
          </option>
        </select>

        <!-- Scoped to THIS card (see structureErrorForThisAgent). On a 422 this
             is the server's own wording — "cannot be their own manager",
             "must belong to the same company", "would create a management
             cycle" — not a bare status code. -->
        <p v-if="structureErrorForThisAgent" class="text-[10px] text-rose-600 mt-1 leading-snug">
          {{ structureErrorForThisAgent }}
        </p>
      </div>

      <!-- TASK-203 — Approve/Reject, ONLY on the "รออนุมัติเข้าทีม" tab
           (showApprovalActions), and only while the agent is actually still
           pending (a card can briefly hold stale props during the
           reloadRoster() round-trip after a sibling's action). The reason
           box below mirrors AgentManagementView's "รออนุมัติ" tab exactly —
           an inline optional-reason text input revealed by "ปฏิเสธ", not a
           ConfirmDialog — see this component's script for why that UX was
           replicated rather than reinvented for the same action. -->
      <div v-if="showApprovalActions && isPending" class="border-t border-slate-100 pt-2">
        <p class="text-[11px] font-bold text-slate-500 mb-1.5">คำขอเข้าทีม</p>
        <div class="flex flex-wrap items-center gap-1.5">
          <button
            type="button"
            :disabled="isSavingApproval"
            class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-emerald-500 bg-emerald-500 text-white text-[10px] font-bold hover:bg-emerald-600 hover:border-emerald-600 disabled:opacity-50"
            @click="approveThisAgent"
          >
            <Icon name="check" :size="11" />
            <span v-if="isSavingApproval">กำลังบันทึก...</span>
            <span v-else>อนุมัติ</span>
          </button>
          <button
            type="button"
            :disabled="isSavingApproval"
            class="inline-flex items-center px-2 py-1 rounded-lg border border-rose-200 text-[10px] font-bold text-rose-600 hover:border-rose-400 hover:bg-rose-50 disabled:opacity-50"
            @click="toggleRejectBox"
          >
            ปฏิเสธ
          </button>
        </div>
        <div v-if="showRejectBox" class="mt-2 flex gap-1.5 items-center">
          <input
            v-model="rejectReasonLocal"
            type="text"
            placeholder="เหตุผล (ไม่บังคับ)"
            class="flex-1 px-2 py-1.5 rounded-lg border border-slate-200 text-[11px]"
          />
          <button
            type="button"
            :disabled="isSavingApproval"
            class="px-2 py-1.5 rounded-lg bg-rose-600 text-white text-[10px] font-bold hover:bg-rose-700 disabled:opacity-50 whitespace-nowrap"
            @click="submitReject"
          >
            ยืนยันปฏิเสธ
          </button>
        </div>
        <p v-if="approvalErrorForThisAgent" class="text-[10px] text-rose-600 mt-1 leading-snug">
          {{ approvalErrorForThisAgent }}
        </p>
      </div>
    </div>
  </div>
</template>
