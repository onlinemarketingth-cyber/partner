/**
 * TASK-050 — shared types + helpers for the "ทีมขาย" leadership cockpit
 * (SalesTeamView.vue + SalesTeamGrid/SalesTeamCard). Kept in a plain TS
 * module so the page and the cards import the SAME tree-builder and
 * provide/inject keys without duplicating them or creating a circular
 * Vue-component import.
 *
 * TASK-179 §4.1 — this file used to hold the SECOND copy of a hardcoded
 * five-stage list (STAGE_ORDER / STAGE_LABELS / STAGE_SHORT_LABELS). All
 * three are gone: the stage vocabulary is config-driven business data
 * (BR-7, ADR-026), the server sends every stage it knows about in its own
 * order, and the ONE place that turns that into something renderable is
 * `stageCounts()` in `@/utils/pipelineStages` — which is also where the
 * Thai labels live, shared with the Pipeline board so a stage reads
 * identically on both screens.
 */

import type { ComputedRef, InjectionKey, Ref } from 'vue'

// TASK-050 (redesign) — provide/inject bridge so a deeply-nested
// SalesTeamCard (inside a SalesTeamGrid, including the one inside the
// ขยายดูลูกทีม modal) can ask the top-level SalesTeamView to open its
// right-side "clients" drawer, without threading an emit up every level.
export const OPEN_AGENT_CLIENTS: InjectionKey<(agent: SalesAgent) => void> = Symbol('openAgentClients')

// TASK-050 (redesign r3) — "ขยายดูลูกทีม" now opens a centred 60vw MODAL
// (instead of an inline panel) showing the leader's downline as a full
// 3-column grid of sub-cards. Provided by SalesTeamView, called from a
// (possibly nested) SalesTeamCard via inject.
export const OPEN_TEAM_MODAL: InjectionKey<(node: TeamNode) => void> = Symbol('openTeamModal')

// TASK-062 (human-requested 2026-07-30) — "grant cert without exam"
// (TASK-058) surfaced on the ทีมขาย cockpit too, same provide/inject
// bridge shape as OPEN_AGENT_CLIENTS/OPEN_TEAM_MODAL above so a deeply
// nested SalesTeamCard doesn't need cert data/mutation threaded through
// every SalesTeamGrid level as props.
export interface CertTierOption {
  id: number
  key: string
  name: string
}
export const CERT_TIERS: InjectionKey<Ref<CertTierOption[]>> = Symbol('certTiers')
export const PASSED_TIER_IDS_BY_AGENT: InjectionKey<ComputedRef<Map<number, Set<number>>>> = Symbol('passedTierIdsByAgent')
export const GRANT_CERTIFICATION: InjectionKey<(agentId: number, agentName: string | null, tier: CertTierOption) => void> =
  Symbol('grantCertification')
// Card reads these to know whether ITS OWN grant is in flight / whether
// an error belongs to IT specifically — kept as shared refs (not
// per-card local state) since the mutation itself runs in
// SalesTeamView. grantErrorAgentId scopes grantError to one card only
// (otherwise every card with an ungranted tier would show the same
// error message after any single grant fails).
export const GRANTING_TIER_KEY: InjectionKey<Ref<string | null>> = Symbol('grantingTierKey')
export const GRANT_ERROR: InjectionKey<Ref<string>> = Symbol('grantError')
export const GRANT_ERROR_AGENT_ID: InjectionKey<Ref<number | null>> = Symbol('grantErrorAgentId')

/**
 * ═══ TASK-126 — STRUCTURAL edits from the card (human request 2026-08-05:
 * "ก่อนหน้านี้จะแก้ไขได้ ปัจจุบันหาข้อมูลไม่เจอ") ═══
 *
 * Same provide/inject bridge shape as the cert-grant block above, for the
 * same reason: the card can be nested inside a SalesTeamGrid (including the
 * one inside the ขยายดูลูกทีม modal), so the mutations live once in
 * SalesTeamView instead of being prop-drilled.
 *
 * WHY ONLY THESE TWO FIELDS ARE EDITABLE HERE (ag-lead, TASK-126):
 * this page is about TEAM STRUCTURE, so structural fields belong on the
 * card — `is_team_leader` (who may recruit) and `manager_id` (who reports
 * to whom) are precisely what the tree in front of the admin is made of,
 * and both are already rendered here as read-only facts (the gold badge /
 * the ลูกทีม block). Identity, PDPA and bank fields deliberately STAY on
 * จัดการตัวแทน: national IDs and bank account numbers spread across a wall
 * of cards is a disclosure surface with no upside, since editing them is a
 * one-agent-at-a-time job that the full editor already does properly. The
 * card links out to that editor instead of half-copying it.
 */
export const ALL_AGENTS: InjectionKey<Ref<SalesAgent[]>> = Symbol('allAgents')
/**
 * Grant/revoke `is_team_leader` (PUT /users/{id}). The card only ASKS —
 * SalesTeamView decides whether the request needs confirming first
 * (revoking does, granting does not: revoking silently stops every recruit
 * link that agent already handed out from admitting anyone).
 */
export const SET_TEAM_LEADER: InjectionKey<(agentId: number, agentName: string | null, next: boolean) => void> =
  Symbol('setTeamLeader')
/** Re-parent this agent (PUT /users/{id} with manager_id; null = no manager). */
export const CHANGE_MANAGER: InjectionKey<(agentId: number, managerId: number | null) => void> =
  Symbol('changeManager')
/**
 * TASK-129 (human, 2026-08-05: "ผมต้องการกดแก้ไข หน้านี้เปิด Modal และไม่ใช้
 * หน้าในรูปที่ 2 อีก") — open the FULL agent editor on this page.
 *
 * The card's pencil used to be a RouterLink to จัดการตัวแทน, because when it
 * was written the edit form existed only as markup inside that view. TASK-129
 * extracted it into <AgentEditModal>, so SalesTeamView now mounts one itself
 * and the pencil just names an agent — no navigation, no second copy of the
 * form (the reason the link existed in the first place still holds: there is
 * exactly ONE implementation of identity/PDPA/bank editing).
 *
 * Same bridge shape as everything above, for the same reason: a card can sit
 * inside a SalesTeamGrid and inside the ขยายดูลูกทีม modal, so prop-drilling
 * an id upward through the nesting is exactly what these keys exist to avoid.
 */
export const OPEN_AGENT_EDITOR: InjectionKey<(agentId: number) => void> = Symbol('openAgentEditor')
/**
 * Per-card busy/error state for the two mutations above — deliberately the
 * SAME shape as GRANTING_TIER_KEY / GRANT_ERROR / GRANT_ERROR_AGENT_ID: one
 * shared ref plus an agent id that scopes it, so a failure shows on the one
 * card that caused it instead of on every card at once.
 */
export const STRUCTURE_SAVING_AGENT_ID: InjectionKey<Ref<number | null>> = Symbol('structureSavingAgentId')
export const STRUCTURE_ERROR: InjectionKey<Ref<string>> = Symbol('structureError')
export const STRUCTURE_ERROR_AGENT_ID: InjectionKey<Ref<number | null>> = Symbol('structureErrorAgentId')

/**
 * ═══ TASK-203 — approve/reject a PENDING agent from the card ═══
 *
 * Same provide/inject bridge shape as the structure-edit block above, for
 * the same reason (a card can be nested inside a leader's downline or the
 * ขยายดูลูกทีม modal). This is a DIFFERENT mutation from is_team_leader /
 * manager_id — approving a registration and designating a team leader are
 * unrelated concepts that happen to both live on this page now — so it gets
 * its own keys rather than being folded into SET_TEAM_LEADER/CHANGE_MANAGER.
 *
 * Reuses the EXISTING approval endpoints (PUT /agent-approvals/{user}/
 * approve|reject) — the same ones AgentManagementView's "รออนุมัติ" tab
 * already calls. No new backend write endpoint for this task.
 */
export const APPROVE_AGENT: InjectionKey<(agentId: number, agentName: string | null) => void> = Symbol('approveAgent')
/** Reject takes a free-text reason (optional, same as AgentManagementView's inline reject box). */
export const REJECT_AGENT: InjectionKey<(agentId: number, agentName: string | null, reason: string) => void> =
  Symbol('rejectAgent')
/** Same per-card busy/error scoping shape as STRUCTURE_SAVING_AGENT_ID above. */
export const APPROVAL_SAVING_AGENT_ID: InjectionKey<Ref<number | null>> = Symbol('approvalSavingAgentId')
export const APPROVAL_ERROR: InjectionKey<Ref<string>> = Symbol('approvalError')
export const APPROVAL_ERROR_AGENT_ID: InjectionKey<Ref<number | null>> = Symbol('approvalErrorAgentId')

// Shape returned by GET /sales-team-overview (one row per agent).
export interface SalesAgent {
  agent_id: number
  agent_name: string | null
  agent_email: string | null
  agent_phone: string | null
  manager_id: number | null
  // TASK-125 / ADR-025 §1 — the ADMIN-GRANTED capability flag ("this agent
  // may mint recruit links / approve their own recruits"). Deliberately NOT
  // the same thing as "has direct reports" (ADR-025 §2 keeps the capability
  // and the tree fact apart on purpose): a designated leader may have
  // recruited nobody yet, and an agent may manage people without ever having
  // been designated. Read both separately — never infer one from the other.
  is_team_leader: boolean
  avatar_url: string | null
  client_count: number
  /**
   * TASK-179 §4.1 — a `{ stage_key: count }` map carrying EVERY stage the
   * server knows about (AgentSalesAggregateService::stageKeys() → all of
   * PipelineStage's cases, in declaration order), not a fixed set of five
   * named keys. Since ADR-026 that is eight, and it changes with an ADR
   * (BR-7). Render it with stageCounts() from @/utils/pipelineStages.
   */
  deals_by_stage: Record<string, number>
  total_deals: number
  closed_deals: number
  conversion: number
  /**
   * TASK-179 (D1/D2) — MONEY THE CUSTOMER PAID: the sum of this agent's paid
   * ORDERS, the same definition and the same source the dashboard's
   * `sales_paid_satang` uses. It used to be the commission ledger's
   * sale-price snapshot gated on the COMMISSION's payment status, so the two
   * screens showed two different "ยอดขาย" for one company. Do NOT re-label
   * this "(จ่ายแล้ว)" — that reads as a commission payout, which is the
   * field below. Integer satang (BR-3); divide by 100 for display only.
   */
  total_sales_satang: number
  /** Money the company has DISBURSED to this agent — paid ledger rows only. */
  total_commission_satang: number
  /**
   * TASK-179 §3.2 (D2) — closed deals of this agent with NO paid order, so
   * contributing zero baht to `total_sales_satang`. The uncountable part is
   * DISCLOSED, never estimated. Render it as a plain sentence only when > 0;
   * a permanent caveat trains people to ignore it (§4.2).
   */
  closed_deals_without_order: number
  /**
   * TASK-203 — the same five approval-provenance fields UserResource has
   * always exposed for the /agent-approvals queue (AgentManagementView's
   * `AgentItem`), now mirrored here so the SAME approvalSourceChip()/
   * approvalProvenance()-shaped rendering logic reads this row unmodified.
   * `agent_approval_status` is the enum string ('pending'|'approved'|
   * 'rejected'); the other four are all null together for every row
   * approved before the TASK-115 migration or created directly by an Admin.
   */
  agent_approval_status: 'pending' | 'approved' | 'rejected'
  approval_rejection_reason: string | null
  approval_source: 'admin' | 'team_leader' | null
  approved_by: { id: number; name: string | null; is_team_leader: boolean } | null
  approved_at: string | null
}

// One agent plus its (recursively nested) direct reports.
export interface TeamNode extends SalesAgent {
  children: TeamNode[]
}

/**
 * Build the reporting hierarchy from a flat agent list. An agent is
 * TOP-LEVEL when it has no manager_id, when its manager isn't in the
 * returned set (e.g. a manager in another visibility scope), or when it
 * points at itself. A `visited` set guards against pathological cycles
 * (A→B→A) so the recursive render can never loop forever.
 */
export function buildTree(agents: SalesAgent[]): TeamNode[] {
  const byId = new Map(agents.map((a) => [a.agent_id, a]))
  const childrenOf = new Map<number, SalesAgent[]>()
  const roots: SalesAgent[] = []

  for (const a of agents) {
    const hasParent = a.manager_id != null && a.manager_id !== a.agent_id && byId.has(a.manager_id)
    if (hasParent) {
      const list = childrenOf.get(a.manager_id as number) ?? []
      list.push(a)
      childrenOf.set(a.manager_id as number, list)
    } else {
      roots.push(a)
    }
  }

  const visited = new Set<number>()
  function toNode(a: SalesAgent): TeamNode {
    visited.add(a.agent_id)
    const kids = (childrenOf.get(a.agent_id) ?? []).filter((c) => !visited.has(c.agent_id))
    return { ...a, children: kids.map(toNode) }
  }
  return roots.map(toNode)
}

/**
 * TASK-125 — is this node a "หัวหน้าทีม" for the purposes of the Admin tab
 * split? True when EITHER fact holds:
 *   • `is_team_leader` — the admin GRANTED the capability (ADR-025 §1), or
 *   • `children.length > 0` — the agent actually HAS direct reports.
 *
 * The two are ORed, never merged: ADR-025 §2 keeps the capability and the
 * tree fact deliberately independent, so a person can have either without
 * the other. The card renders each fact with its own affordance (see
 * SalesTeamCard) — this helper only decides WHICH TAB they belong to.
 */
export function isLeaderNode(node: TeamNode): boolean {
  return node.is_team_leader || node.children.length > 0
}

// TASK-203 — is this node sitting in the agent-approval queue right now?
// Plain field check, but named so callers read intent, not a magic string.
export function isPendingApproval(node: TeamNode): boolean {
  return node.agent_approval_status === 'pending'
}

export interface SalesTeamPartition {
  /** Tab 1 "หัวหน้าทีม" — rendered as the tree, exactly as before the split. */
  leaderRoots: TeamNode[]
  /** Tab 2 "ตัวแทนอิสระ" — flat, nothing to expand. */
  independents: TeamNode[]
  /**
   * TASK-203 — Tab 3 "ลูกทีม": every DESCENDANT node (never a root itself,
   * at any nesting depth under a leader root) that is NOT a leader
   * (`isLeaderNode()` false — no admin-granted flag AND no reports of its
   * own). Deliberately overlapping with `leaderRoots`, NOT a fifth slice of
   * the complete partition described below: every node in this bucket is
   * ALSO still rendered nested inside its leader's card in `leaderRoots` —
   * TASK-125's tree is untouched — this is an intentional flat DUPLICATE
   * view of the same people, not a new mutually-exclusive bucket. A node
   * can never appear here from `independents` (an independent has no
   * manager, so it is never anyone's descendant).
   */
  members: TeamNode[]
  /**
   * TASK-203 — Tab 4 "รออนุมัติเข้าทีม": EVERY node anywhere in the full
   * roster — root or nested, independent or under a leader —
   * `isPendingApproval()` true. Also NOT part of the complete partition:
   * approval status is an ORTHOGONAL axis to team structure, so a single
   * pending agent can legitimately appear here AND in `leaderRoots`
   * (nested under their leader) or `independents` at the same time. This is
   * the one bucket that can overlap with literally any of the other three.
   */
  pending: TeamNode[]
}

/**
 * TASK-125 — split the roster into the page's original TWO tabs
 * (`leaderRoots` / `independents`). TASK-203 (below) adds `members` and
 * `pending` to the SAME return value so every tab is computed from one
 * pass over the tree, but read the distinction carefully:
 *
 * ═══ `leaderRoots` / `independents` — a COMPLETE PARTITION ═══
 * Every agent is rendered exactly once between these two:
 *
 *   • "หัวหน้าทีม"  — the leader roots, rendered as the SAME tree as before
 *     this split. A plain team member (has a manager, no reports, no flag)
 *     therefore lives INSIDE this bucket, nested under their leader — in
 *     the card's ลูกทีม list and in the "ขยายดูลูกทีม" modal.
 *   • "ตัวแทนอิสระ" — genuinely unattached agents only: no manager, no
 *     direct reports, no leader flag. Nothing to nest, so no tree.
 *
 * Operating on ROOTS is what makes THIS PART of the partition airtight.
 * `buildTree` already defines a root as "no effective manager" (manager_id
 * null, or a manager outside the visible set, or a self-reference), which
 * is exactly the independent-tab condition — and every NON-root is, by
 * construction, nested under some root that has children and is therefore
 * a leader root. So no agent can fall through both buckets or land in
 * neither.
 *
 * ═══ `members` / `pending` — TASK-203, deliberately NOT part of that ═══
 * partition. See their own docblocks on `SalesTeamPartition` above: both
 * are additive, overlapping views (duplication with `leaderRoots`/
 * `independents`, and with each other, is expected and accepted — a
 * pending plain member can legitimately show up in three of the four
 * buckets at once). A future reader must not assume all four tabs
 * partition the roster the way the original two did.
 */
export function partitionRoots(roots: TeamNode[]): SalesTeamPartition {
  const leaderRoots: TeamNode[] = []
  const independents: TeamNode[] = []
  for (const node of roots) {
    if (isLeaderNode(node)) leaderRoots.push(node)
    else independents.push(node)
  }

  // ลูกทีม — every descendant of a leader root that is not itself a leader.
  // Independents have no children to recurse into, so only leaderRoots'
  // subtrees are walked.
  const members: TeamNode[] = []
  const collectMembers = (nodes: TeamNode[]) => {
    for (const n of nodes) {
      if (!isLeaderNode(n)) members.push(n)
      if (n.children.length) collectMembers(n.children)
    }
  }
  for (const root of leaderRoots) collectMembers(root.children)

  // รออนุมัติเข้าทีม — every node anywhere (root or nested), independent of
  // team structure entirely.
  const pending = flattenNodes(roots).filter(isPendingApproval)

  return { leaderRoots, independents, members, pending }
}

export function initial(name: string | null | undefined): string {
  return (name ?? '?').trim().charAt(0).toUpperCase() || '?'
}

export function formatPct(pct: number): string {
  return `${pct.toFixed(1)}%`
}

// TASK-051 — satang → baht with thousands separators (BR-3: divide by 100
// only at the display layer). e.g. 100000 → "1,000".
export function formatBaht(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { maximumFractionDigits: 0 })
}

// Flatten a hierarchy back to a single list of all nodes (each keeping its
// own children), for the search/sort "flat" mode where the tree grouping is
// dropped and every agent is shown as a card.
export function flattenNodes(tree: TeamNode[]): TeamNode[] {
  const out: TeamNode[] = []
  const walk = (nodes: TeamNode[]) => {
    for (const n of nodes) {
      out.push(n)
      if (n.children.length) walk(n.children)
    }
  }
  walk(tree)
  return out
}
