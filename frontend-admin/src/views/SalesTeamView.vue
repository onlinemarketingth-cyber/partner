<script setup lang="ts">
/**
 * SalesTeamView — "ทีมขาย" leadership cockpit (TASK-050 + redesign).
 * Fetches GET /sales-team-overview and renders the sales force as cards
 * assembled into the manager_id reporting hierarchy.
 *
 * Clicking a card's "ดูลูกค้า" no longer navigates — it opens a right-side
 * DRAWER (provided via OPEN_AGENT_CLIENTS) that fetches GET
 * /clients?agent_id= in-page; only the per-client "ดูรายละเอียด" button
 * links out to the full Client File.
 *
 * ═══ TASK-125 — the two-tab split (human request 2026-08-05) ═══
 * The page used to be ONE flat grid where every card looked alike and a
 * leader was distinguishable only by a small gold badge, so an admin could
 * not answer "who leads a team" or "who is unattached" at a glance. It is
 * now split in two:
 *
 *   • Tab "หัวหน้าทีม"   — leaders, rendered as the SAME tree as before.
 *   • Tab "ตัวแทนอิสระ"  — genuinely unattached agents, flat, no expand.
 *
 * TASK-125 explicitly rejected a third tab for plain team members, on the
 * grounds that they already live inside tab 1, nested under their leader.
 *
 * ═══ TASK-203 (human-requested, 2026-08-17) — that decision is now
 * DELIBERATELY REVERSED, on purpose, not an oversight ═══
 * Two more tabs, added from the SAME `partitionRoots()` call:
 *
 *   • Tab "ลูกทีม"          — a FLAT duplicate view of every plain team
 *     member. They ALSO still appear nested inside "หัวหน้าทีม" — that
 *     duplication is an accepted trade-off, not a bug.
 *   • Tab "รออนุมัติเข้าทีม" — every agent anywhere in the roster whose
 *     agent_approval_status is pending, with inline Approve/Reject
 *     (reusing the existing /agent-approvals endpoints) so a Company/Super
 *     Admin does not have to leave this page to approve someone who signed
 *     up under one of their team leaders.
 *
 * The two-way COMPLETE partition ("หัวหน้าทีม" / "ตัวแทนอิสระ") is otherwise
 * untouched. The two new tabs are NOT part of that partition — they overlap
 * with the first two and with each other. Full reasoning on
 * `SalesTeamPartition` and `partitionRoots()` in salesTeam.ts — read that
 * before changing this.
 */
import { computed, onMounted, provide, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import Icon from '@/design-system/components/Icon.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import SuccessDialog from '@/design-system/components/SuccessDialog.vue'
import SalesTeamGrid from './SalesTeamGrid.vue'
// TASK-129 — the SAME edit form จัดการตัวแทน uses, mounted here so the card's
// pencil opens it in place (human request 2026-08-05). It loads the agent
// itself from GET /users/{id}: this page's rows come from
// /sales-team-overview and carry none of the fields that form edits.
import AgentEditModal from './AgentEditModal.vue'
import {
  type CertTierOption,
  type SalesAgent,
  type TeamNode,
  buildTree,
  flattenNodes,
  formatPct,
  initial,
  isLeaderNode,
  partitionRoots,
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
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

const router = useRouter()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const agents = ref<SalesAgent[]>([])
/**
 * TASK-179 §3.6 (F-15) — the company-level DISTINCT client count, from
 * `meta.clients_total`. The header KPI used to SUM data[].client_count,
 * which double-counts any client referred by two agents — a first-class
 * scenario here (TASK-049 exists for it), so the header could report more
 * clients than the company has. No amount of adding the cards up can
 * produce this number; it has to come from the server.
 */
const clientsTotal = ref(0)

// Roots (top-level agents: team leaders + agents with no manager). Their
// downline is revealed via the team modal.
const roots = computed(() => buildTree(agents.value))

// ── TASK-125 — the two-tab partition ────────────────────────────────
// partitionRoots() splits the tree ROOTS; everything below a root travels
// with it. See its docblock in salesTeam.ts for why plain team members do
// not get a tab of their own (short version: they are nested inside tab 1
// under their leader, and this was a complete partition — every agent
// rendered exactly once across the two tabs).
//
// TASK-203 (human-requested, on purpose — TASK-125's "no third tab"
// decision is EXPLICITLY reversed here) adds two more tabs from the SAME
// partitionRoots() call:
//   • "ลูกทีม"          — a flat duplicate view of every plain (non-leader)
//     team member, ALSO still nested inside "หัวหน้าทีม" — see
//     SalesTeamPartition's docblock for why that duplication is accepted.
//   • "รออนุมัติเข้าทีม" — every agent anywhere in the roster whose approval
//     is pending, with inline Approve/Reject so a Company/Super Admin does
//     not have to leave this page. Reuses the existing /agent-approvals
//     endpoints (see the approveAgent/rejectAgent functions below).
// Neither new tab is part of the original complete partition; both can
// overlap with "หัวหน้าทีม"/"ตัวแทนอิสระ" and with each other.
type TeamTab = 'leaders' | 'independent' | 'members' | 'pending'
const activeTab = ref<TeamTab>('leaders')

const partition = computed(() => partitionRoots(roots.value))
const leaderRoots = computed(() => partition.value.leaderRoots)
const independents = computed(() => partition.value.independents)
const members = computed(() => partition.value.members)
const pendingNodes = computed(() => partition.value.pending)

// Tab COUNTS deliberately count what each tab is NAMED for, not how many
// cards it paints:
//   • "หัวหน้าทีม (N)"  = how many leaders exist — flagged OR with reports —
//     including sub-leaders nested deeper in the tree, which is the number
//     an admin actually wants ("how many people lead a team here?").
//   • "ตัวแทนอิสระ (M)" = how many unattached agents exist.
//   • "ลูกทีม (K)"       = how many plain team members exist (also counted
//     inside "หัวหน้าทีม" — TASK-203, not part of that complete partition).
//   • "รออนุมัติเข้าทีม (P)" = how many agents anywhere are pending.
// N + M is therefore NOT the roster size: plain team members are rendered
// inside tab 1 but are not leaders, so they are counted in neither label.
const leaderCount = computed(() => flattenNodes(roots.value).filter(isLeaderNode).length)
const independentCount = computed(() => independents.value.length)
const memberCount = computed(() => members.value.length)
const pendingCount = computed(() => pendingNodes.value.length)

const tabs = computed<{ key: TeamTab; label: string; icon: string; count: number }[]>(() => [
  { key: 'leaders', label: 'หัวหน้าทีม', icon: 'star', count: leaderCount.value },
  { key: 'independent', label: 'ตัวแทนอิสระ', icon: 'user', count: independentCount.value },
  { key: 'members', label: 'ลูกทีม', icon: 'users', count: memberCount.value },
  { key: 'pending', label: 'รออนุมัติเข้าทีม', icon: 'clock', count: pendingCount.value },
])

// ── Search + sort (TASK-051) ─────────────────────────────────────────
// Default view = the hierarchy (leaders, expand-to-modal). As soon as a
// search term OR a non-default sort is chosen, the view FLATTENS to a
// single grid of every matching agent, ordered by the chosen sort — so a
// downline member can be found/ranked without opening a modal.
// TASK-125 — search/sort now operate WITHIN the active tab only (see
// searchPool below); the controls themselves are shared, and the term is
// intentionally kept when switching tabs so "find this person" can be
// re-aimed at the other tab without retyping.
// Sort is 3 toggle buttons (human request): ลูกทีม / ยอดขาย / ค่าคอม.
// Clicking a button activates that field (desc); clicking the active one
// again flips desc ↔ asc. No field active = default hierarchy view.
type SortField = '' | 'team' | 'sales' | 'commission'
const q = ref('')
const sortField = ref<SortField>('')
const sortDir = ref<'desc' | 'asc'>('desc')

function setSort(field: Exclude<SortField, ''>) {
  if (sortField.value === field) {
    sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc'
  } else {
    sortField.value = field
    sortDir.value = 'desc'
  }
}
function clearSort() {
  sortField.value = ''
}
function sortKey(n: TeamNode): number {
  switch (sortField.value) {
    case 'team':
      return n.children.length
    case 'sales':
      // TASK-179 (D1) — the "ยอดขาย" button ranks by money the CUSTOMER paid
      // (paid orders), which is what the card under it now shows. It already
      // carried no "(จ่ายแล้ว)" suffix, so only the underlying number moved.
      return n.total_sales_satang
    case 'commission':
      return n.total_commission_satang
    default:
      return 0
  }
}

const isFlat = computed(() => q.value.trim() !== '' || sortField.value !== '')

// TASK-125 / TASK-203 — what search/sort may reach, scoped to the active
// tab. Tab 1's pool is the FLATTENED tree, so a nested team member is still
// findable by name from the leader tab (that is where they live now). Tabs
// 2-4 are leaves by definition (independents have no reports; members/
// pending are already flat buckets from partitionRoots()), so their lists
// are already flat.
const searchPool = computed<TeamNode[]>(() => {
  switch (activeTab.value) {
    case 'leaders':
      return flattenNodes(leaderRoots.value)
    case 'independent':
      return independents.value
    case 'members':
      return members.value
    case 'pending':
      return pendingNodes.value
  }
})

const displayNodes = computed(() => {
  const term = q.value.trim().toLowerCase()
  let list = searchPool.value
  if (term) {
    list = list.filter(
      (n) =>
        (n.agent_name ?? '').toLowerCase().includes(term) ||
        (n.agent_email ?? '').toLowerCase().includes(term) ||
        (n.agent_phone ?? '').toLowerCase().includes(term),
    )
  }
  const arr = [...list]
  if (sortField.value) {
    const dir = sortDir.value === 'desc' ? -1 : 1
    arr.sort((a, b) => dir * (sortKey(a) - sortKey(b)) || b.total_deals - a.total_deals)
  } else {
    // flat only because of a search term — keep leaders-first + deals.
    // TASK-125 — "leader" here is the same OR rule the tabs use, so a
    // designated-but-empty leader still floats to the top of a search.
    arr.sort((a, b) => Number(isLeaderNode(b)) - Number(isLeaderNode(a)) || b.total_deals - a.total_deals)
  }
  return arr
})

// What the grid actually renders for the tab the admin is looking at.
// Search/sort → the filtered+ordered flat list; otherwise tab 1 gets the
// TREE (roots only, downline nested inside each card) and tabs 2-4 get
// their already-flat lists (unattached agents / plain members / pending
// agents respectively — none of the three ever has anything to expand).
const listForActiveTab = computed<TeamNode[]>(() => {
  if (isFlat.value) return displayNodes.value
  switch (activeTab.value) {
    case 'leaders':
      return leaderRoots.value
    case 'independent':
      return independents.value
    case 'members':
      return members.value
    case 'pending':
      return pendingNodes.value
  }
})

const kpis = computed(() => {
  const totalDeals = agents.value.reduce((sum, a) => sum + a.total_deals, 0)
  const totalClosed = agents.value.reduce((sum, a) => sum + a.closed_deals, 0)
  return [
    // TASK-179 §3.5 (F-8) — /sales-team-overview lists ACTIVE agents only
    // (soft-deleted ones are excluded server-side), so "ทั้งหมด" named a
    // set this number does not contain. Same wording as the dashboard KPI,
    // which now counts exactly the same thing.
    { label: 'ตัวแทนที่ใช้งานอยู่', value: agents.value.length },
    // TASK-179 §3.6 (F-15) — the server's DISTINCT count, NOT a sum of the
    // cards: a client referred by two agents appears on both cards and is
    // one client.
    { label: 'ลูกค้ารวม', value: clientsTotal.value },
    // D4 — "ปิด" = reached Complete Payment, post-sale stages included.
    { label: 'ปิดการขายรวม', value: totalClosed },
    // §4.4 — with no deals there is no ratio; 0.0% would be a number
    // nobody computed.
    { label: 'อัตราปิดรวม', value: totalDeals > 0 ? formatPct((totalClosed / totalDeals) * 100) : 'ยังไม่มีข้อมูล' },
  ]
})

// TASK-062 (human-requested 2026-07-30) — "grant cert without exam"
// (TASK-058) surfaced here too. certTiers/certifications are loaded
// alongside the roster; passedTierIdsByAgent + the grant mutation are
// provided down via provide/inject (see salesTeam.ts) so the deeply
// nested SalesTeamCard doesn't need them threaded through
// SalesTeamGrid as props.
const certTiers = ref<CertTierOption[]>([])
interface CertificationRow {
  id: number
  user_id: number
  cert_tier: CertTierOption | null
}
const certifications = ref<CertificationRow[]>([])
const passedTierIdsByAgent = computed(() => {
  const map = new Map<number, Set<number>>()
  for (const c of certifications.value) {
    if (!c.cert_tier) continue
    if (!map.has(c.user_id)) map.set(c.user_id, new Set())
    map.get(c.user_id)!.add(c.cert_tier.id)
  }
  return map
})

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [a, t, c] = await Promise.all([
      // §3.6 — `meta.clients_total` is the company-level DISTINCT client
      // count; `data` is unchanged (one row per agent).
      api.get<{ data: SalesAgent[]; meta?: { clients_total: number } }>(activeCompany.scopedPath('/sales-team-overview')),
      api.get<{ data: CertTierOption[] }>('/cert-tiers'),
      api.get<{ data: CertificationRow[] }>(activeCompany.scopedPath('/user-certifications')),
    ])
    agents.value = a.data
    clientsTotal.value = a.meta?.clients_total ?? 0
    certTiers.value = t.data
    certifications.value = c.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

const grantingTierKey = ref<string | null>(null)
const grantError = ref('')
const grantErrorAgentId = ref<number | null>(null)
// TASK-066 (human-reported 2026-07-31) — native window.confirm() replaced
// with the ConfirmDialog modal. grantCertification() (injected into
// SalesTeamCard via GRANT_CERTIFICATION) now just opens the dialog;
// confirmGrantCertification() (wired to @confirm below) runs the actual
// API call that used to sit right after the confirm() guard.
const pendingGrant = ref<{ agentId: number; agentName: string | null; tier: CertTierOption } | null>(null)
function grantCertification(agentId: number, agentName: string | null, tier: CertTierOption) {
  pendingGrant.value = { agentId, agentName, tier }
}
async function confirmGrantCertification() {
  const pending = pendingGrant.value
  if (!pending) return
  const { agentId, tier } = pending
  const key = `${agentId}:${tier.id}`
  grantingTierKey.value = key
  grantError.value = ''
  grantErrorAgentId.value = null
  try {
    await api.post('/user-certifications', { user_id: agentId, cert_tier_id: tier.id })
    const res = await api.get<{ data: CertificationRow[] }>(activeCompany.scopedPath('/user-certifications'))
    certifications.value = res.data
  } catch (e) {
    grantErrorAgentId.value = agentId
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { errors?: Record<string, string[]> }
      grantError.value = body.errors?.user_id?.[0] ?? body.errors?.cert_tier_id?.[0] ?? 'อนุมัติไม่สำเร็จ'
    } else {
      grantError.value = e instanceof ApiError ? `อนุมัติไม่สำเร็จ (${e.status})` : 'อนุมัติไม่สำเร็จ'
    }
  } finally {
    grantingTierKey.value = null
    pendingGrant.value = null
  }
}
provide(CERT_TIERS, certTiers)
provide(PASSED_TIER_IDS_BY_AGENT, passedTierIdsByAgent)
provide(GRANT_CERTIFICATION, grantCertification)
provide(GRANTING_TIER_KEY, grantingTierKey)
provide(GRANT_ERROR, grantError)
provide(GRANT_ERROR_AGENT_ID, grantErrorAgentId)

// ── Clients drawer (per-agent, in-page) ─────────────────────────────
interface DrawerReferral {
  id: number
  product: { id: number; name: string } | null
  current_stage: { key: string; label: string }
}
interface DrawerClient {
  id: number
  name: string
  phone: string
  status: { key: string; label: string }
  referrals: DrawerReferral[]
}

const drawerOpen = ref(false)
const drawerAgent = ref<SalesAgent | null>(null)
const drawerClients = ref<DrawerClient[]>([])
const drawerLoading = ref(false)
const drawerError = ref('')

async function openAgentClients(agent: SalesAgent) {
  drawerAgent.value = agent
  drawerOpen.value = true
  drawerClients.value = []
  drawerError.value = ''
  drawerLoading.value = true
  try {
    const res = await api.get<{ data: DrawerClient[] }>(`/clients?agent_id=${agent.agent_id}`)
    drawerClients.value = res.data
  } catch (e) {
    drawerError.value = e instanceof ApiError ? `โหลดลูกค้าไม่สำเร็จ (${e.status})` : 'โหลดลูกค้าไม่สำเร็จ'
  } finally {
    drawerLoading.value = false
  }
}
function closeDrawer() {
  drawerOpen.value = false
}
function viewClientFile(clientId: number) {
  router.push({ name: 'client-file', params: { id: clientId } })
}
// Deeply-nested cards call this via inject rather than emitting up.
provide(OPEN_AGENT_CLIENTS, openAgentClients)

// ── Team modal (expand a leader's downline as a 60vw 3-col grid) ──────
const teamModalNode = ref<TeamNode | null>(null)
function openTeamModal(node: TeamNode) {
  teamModalNode.value = node
}
function closeTeamModal() {
  teamModalNode.value = null
}
provide(OPEN_TEAM_MODAL, openTeamModal)

// ═══════════ TASK-126 — structural edits from the card ═══════════
// The two writes the card can make: grant/revoke the team-leader capability
// and re-parent the agent. Both ride the ORDINARY PUT /users/{id} endpoint
// (UpdateUserRequest already accepts `is_team_leader` and `manager_id`), so
// nothing new was needed server-side — and the server, not this screen, is
// still the guard: UserPolicy::update() + the Request's own
// Rule::prohibitedIf() restrict the leader flag to Company/Super Admin
// (BR-6), and UserService::assertValidManager() owns the same-company /
// no-self / no-cycle rules.
//
// Why only these two and not the whole agent record: see the ALL_AGENTS
// docblock in salesTeam.ts — structure belongs on the structure page,
// identity/PDPA/bank fields stay on จัดการตัวแทน.
provide(ALL_AGENTS, agents)

const structureSavingAgentId = ref<number | null>(null)
const structureError = ref('')
const structureErrorAgentId = ref<number | null>(null)
provide(STRUCTURE_SAVING_AGENT_ID, structureSavingAgentId)
provide(STRUCTURE_ERROR, structureError)
provide(STRUCTURE_ERROR_AGENT_ID, structureErrorAgentId)

/**
 * Re-read the roster after a structural write.
 *
 * Mandatory rather than a nicety for the manager change: `roots` /
 * `partitionRoots` / every card's ลูกทีม list are all derived from
 * `agents`, so re-parenting an agent literally re-shapes the view the admin
 * is looking at. Patching the one row locally would leave the tree lying.
 *
 * Only /sales-team-overview is refetched — cert tiers and certifications are
 * untouched by either mutation.
 */
async function reloadRoster(): Promise<void> {
  const res = await api.get<{ data: SalesAgent[]; meta?: { clients_total: number } }>(activeCompany.scopedPath('/sales-team-overview'))
  agents.value = res.data
  // Re-parenting an agent does not change the company's client count, but a
  // stale header next to a re-shaped tree is exactly the kind of drift §3.6
  // is about — read it back from the same response the rows came from.
  clientsTotal.value = res.meta?.clients_total ?? 0

  // The open ขยายดูลูกทีม modal holds a TeamNode built from the PREVIOUS
  // tree, which is now stale (a manager change moves cards between
  // downlines). Re-resolve it by agent_id against the rebuilt tree; if that
  // agent no longer has a downline at all, close rather than show an empty
  // modal claiming to be someone's team.
  const openNodeId = teamModalNode.value?.agent_id
  if (openNodeId !== undefined) {
    const rebuilt = flattenNodes(roots.value).find((n) => n.agent_id === openNodeId)
    teamModalNode.value = rebuilt && rebuilt.children.length ? rebuilt : null
  }
}

/**
 * Shared failure handling for both structural writes: scope the message to
 * the card that caused it (same GRANT_ERROR_AGENT_ID trick as the cert
 * grant) and, on a 422, show the SERVER's own wording.
 *
 * The 422s that matter here come from UserService::assertValidManager —
 * self-assignment, cross-company, and "this would create a management
 * cycle". Those are the three cases an admin can actually act on, and
 * "เปลี่ยนไม่สำเร็จ (422)" tells them nothing. The server phrases them in
 * English; they are shown verbatim behind a short Thai lead-in rather than
 * translated here on purpose — a local translation table would be a second
 * copy of a backend rule that silently starts lying the day the backend
 * rewords or adds a case.
 */
function reportStructureError(agentId: number, e: unknown, fallback: string): void {
  structureErrorAgentId.value = agentId
  if (e instanceof ApiError && e.status === 422) {
    const body = e.body as { errors?: Record<string, string[]>; message?: string }
    const detail = body.errors?.manager_id?.[0] ?? body.errors?.is_team_leader?.[0] ?? body.message
    structureError.value = detail ? `${fallback}: ${detail}` : fallback
  } else {
    structureError.value = e instanceof ApiError ? `${fallback} (${e.status})` : fallback
  }
}

// ── Team-leader capability (ADR-025 §1) ──
// TASK-130 §2a — the CARD now only ever asks for a grant (the button is not
// rendered once the flag is on); revoking moved to the edit modal, which is
// the one place that explains the consequence below next to the toggle. The
// revoke branch here is kept, not deleted: SET_TEAM_LEADER is a shared
// contract taking `next: boolean`, and if a host ever asks for a revoke again
// it must still be confirmed rather than silently applied.
// Granting applies immediately; REVOKING is confirmed first, because it has
// an invisible side effect the admin cannot see from this page: every
// recruit link that agent already shared stops admitting anyone
// (RegistrationService::resolveActiveInviter), while the links themselves
// still look "ใช้งานได้" on the จัดการตัวแทน links tab.
const pendingLeaderRevoke = ref<{ agentId: number; agentName: string | null } | null>(null)

function setTeamLeader(agentId: number, agentName: string | null, next: boolean): void {
  if (!next) {
    pendingLeaderRevoke.value = { agentId, agentName }
    return
  }
  void applyTeamLeader(agentId, true)
}
async function applyTeamLeader(agentId: number, next: boolean): Promise<void> {
  structureSavingAgentId.value = agentId
  structureError.value = ''
  structureErrorAgentId.value = null
  try {
    await api.put(`/users/${agentId}`, { is_team_leader: next })
    await reloadRoster()
  } catch (e) {
    reportStructureError(agentId, e, next ? 'ให้สิทธิ์หัวหน้าทีมไม่สำเร็จ' : 'ยกเลิกสิทธิ์หัวหน้าทีมไม่สำเร็จ')
  } finally {
    structureSavingAgentId.value = null
  }
}
async function confirmRevokeTeamLeader(): Promise<void> {
  const pending = pendingLeaderRevoke.value
  if (!pending) return
  await applyTeamLeader(pending.agentId, false)
  pendingLeaderRevoke.value = null
}
provide(SET_TEAM_LEADER, setTeamLeader)

// ── Manager (ADR-006 upline) ──
// No confirm dialog: this is reversible from the same control in one click,
// and the tree redraws underneath it so the result is immediately visible.
async function changeManager(agentId: number, managerId: number | null): Promise<void> {
  structureSavingAgentId.value = agentId
  structureError.value = ''
  structureErrorAgentId.value = null
  try {
    await api.put(`/users/${agentId}`, { manager_id: managerId })
    await reloadRoster()
  } catch (e) {
    reportStructureError(agentId, e, 'เปลี่ยนหัวหน้าไม่สำเร็จ')
  } finally {
    structureSavingAgentId.value = null
  }
}
provide(CHANGE_MANAGER, changeManager)

// ═══════════ TASK-203 — approve/reject a pending agent from the card ══════
// Reuses the EXISTING approval endpoints (PUT /agent-approvals/{user}/
// approve|reject) — the same two AgentManagementView's "รออนุมัติ" tab
// already calls; no new backend write endpoint for this feature. Same
// shared-ref busy/error scoping as the structural edits above, and the same
// mandatory reloadRoster() after success: approving/rejecting changes
// agent_approval_status, which moves the row out of (or into) the
// "รออนุมัติเข้าทีม" tab and the chip on every OTHER tab that agent might be
// nested in — patching the one row locally would leave those stale.
const approvalSavingAgentId = ref<number | null>(null)
const approvalError = ref('')
const approvalErrorAgentId = ref<number | null>(null)
provide(APPROVAL_SAVING_AGENT_ID, approvalSavingAgentId)
provide(APPROVAL_ERROR, approvalError)
provide(APPROVAL_ERROR_AGENT_ID, approvalErrorAgentId)

async function approveAgent(agentId: number): Promise<void> {
  approvalSavingAgentId.value = agentId
  approvalError.value = ''
  approvalErrorAgentId.value = null
  try {
    await api.put(`/agent-approvals/${agentId}/approve`)
    await reloadRoster()
  } catch (e) {
    approvalErrorAgentId.value = agentId
    approvalError.value = e instanceof ApiError ? `อนุมัติไม่สำเร็จ (${e.status})` : 'อนุมัติไม่สำเร็จ'
  } finally {
    approvalSavingAgentId.value = null
  }
}
provide(APPROVE_AGENT, approveAgent)

// Reject reason is free-text and OPTIONAL — same as AgentManagementView's
// "รออนุมัติ" tab (submitReject()) — sent as `undefined` rather than an
// empty string so the Form Request's `nullable` rule reads it the same way
// an admin who left the box blank there would produce.
async function rejectAgent(agentId: number, _agentName: string | null, reason: string): Promise<void> {
  approvalSavingAgentId.value = agentId
  approvalError.value = ''
  approvalErrorAgentId.value = null
  try {
    await api.put(`/agent-approvals/${agentId}/reject`, { reason: reason || undefined })
    await reloadRoster()
  } catch (e) {
    approvalErrorAgentId.value = agentId
    approvalError.value = e instanceof ApiError ? `ปฏิเสธไม่สำเร็จ (${e.status})` : 'ปฏิเสธไม่สำเร็จ'
  } finally {
    approvalSavingAgentId.value = null
  }
}
provide(REJECT_AGENT, rejectAgent)

// ═══════════ TASK-129 — the full agent editor, opened in place ═══════════
// The card's pencil used to navigate to จัดการตัวแทน because the edit form
// lived only inside that view. It is a component now (<AgentEditModal>), so
// this page mounts one and the card just names an agent through the same
// inject bridge every other card action uses.
//
// Nothing is passed but the id, deliberately: this page's rows come from
// /sales-team-overview and carry none of the fields that form edits (no
// first/last name, no bank details, no identity document, no role), and it
// holds no cert-tier / company / roster lists either. The modal fetches all
// of it — that is exactly why it was built to.
const editingAgentId = ref<number | null>(null)
function openAgentEditor(agentId: number): void {
  editingAgentId.value = agentId
}
provide(OPEN_AGENT_EDITOR, openAgentEditor)

/**
 * A save in the modal can change the shape of the tree in front of the admin
 * — the upline dropdown and the team-leader toggle are both in that form — so
 * this is the same mandatory re-read as the card's own structural writes (see
 * reloadRoster). A changed name/manager/leader flag re-shapes the grid
 * immediately rather than after a manual refresh.
 */
// TASK-210 — the modal closes itself on a successful write, so the "it
// worked" confirmation has to be raised by the host that outlives it.
const savedMessage = ref('')
const showSavedDialog = ref(false)

function onAgentEditorSaved(payload: { leaderChanged: boolean; successMessage?: string }): void {
  if (payload.successMessage) {
    savedMessage.value = payload.successMessage
    showSavedDialog.value = true
  }
  void reloadRoster()
}

function statusBadgeClasses(statusKey: string): string {
  switch (statusKey) {
    case 'interested':
      return 'bg-emerald-50 text-emerald-700'
    case 'not_interested':
      return 'bg-rose-50 text-rose-600'
    case 'contacted':
      return 'bg-brand-50 text-brand-700'
    default:
      return 'bg-slate-100 text-slate-600'
  }
}

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAll() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="trophy"
      title="ทีมขาย"
      subtitle="ภาพรวมทีมขาย — จำนวนลูกค้าและดีลของแต่ละตัวแทน"
      description="แท็บ ‘หัวหน้าทีม’ = หัวหน้าทีมพร้อมลูกทีมที่ซ้อนอยู่ข้างใน · แท็บ ‘ตัวแทนอิสระ’ = ตัวแทนที่ไม่มีสังกัดและไม่มีลูกทีม · แท็บ ‘ลูกทีม’ = รายชื่อลูกทีมแบบแบนราบ (คนเดียวกับที่ซ้อนอยู่ในหัวหน้าทีม) · แท็บ ‘รออนุมัติเข้าทีม’ = อนุมัติ/ปฏิเสธได้ทันทีจากที่นี่ — แถบ/avatar สีทอง = ได้รับสิทธิ์หัวหน้าทีมจากแอดมิน, บรรทัด ‘ดูแลลูกทีม N คน’ = มีลูกทีมจริง, ป้ายสีเหลือง ‘รออนุมัติ’ = สมัครแล้วรออนุมัติ"
      :kpis="kpis"
      accent-color="brand"
      storage-key="admin-sales-team"
    >
      <!-- TASK-125 — same tab markup as ProductCatalogView's HeroHeader
           #tabs slot (single flat card, no separate filter row). Each label
           carries a live count; see the `tabs` computed for what those
           counts count and why they do not add up to the roster size. -->
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in tabs"
            :key="t.key"
            type="button"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key"
          >
            <Icon :name="t.icon" :size="14" />

    <CompanyScopeNotice action="ดูทีมขาย" />
            {{ t.label }} ({{ t.count }})
          </button>
        </div>
      </template>
    </HeroHeader>

    <!-- Search + sort bar (TASK-051) -->
    <div class="bg-white/95 border border-slate-200 rounded-xl p-4 mt-4 flex flex-col md:flex-row gap-3">
      <div class="relative flex-1">
        <Icon name="search" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input
          v-model="q"
          type="text"
          placeholder="ค้นหาตัวแทน — ชื่อ / เบอร์ / อีเมล"
          class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-brand-400"
        />
      </div>
      <!-- 3 sort toggle buttons — each: click to sort by that field (มาก→น้อย),
           click again to flip (น้อย→มาก). Arrow icon shows current direction. -->
      <div class="flex items-center gap-2 shrink-0">
        <span class="text-xs font-bold text-slate-500 whitespace-nowrap">เรียงตาม</span>
        <div class="flex items-center gap-1">
          <button
            v-for="opt in [
              { field: 'team', label: 'ลูกทีม' },
              { field: 'sales', label: 'ยอดขาย' },
              { field: 'commission', label: 'ค่าคอม' },
            ]"
            :key="opt.field"
            type="button"
            class="flex items-center gap-1 px-3 py-2 rounded-lg border text-sm font-bold transition-colors"
            :class="sortField === opt.field
              ? 'border-brand-400 bg-brand-50 text-brand-700'
              : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
            :title="sortField === opt.field ? (sortDir === 'desc' ? 'มากไปน้อย (กดเพื่อสลับ)' : 'น้อยไปมาก (กดเพื่อสลับ)') : 'เรียงตาม' + opt.label"
            @click="setSort(opt.field as 'team' | 'sales' | 'commission')"
          >
            {{ opt.label }}
            <Icon
              name="chevron_down"
              :size="14"
              :class="[sortField === opt.field ? 'text-brand-600' : 'text-slate-300', sortField === opt.field && sortDir === 'asc' ? 'rotate-180' : '']"
            />
          </button>
          <button
            v-if="sortField"
            type="button"
            class="px-2 py-2 rounded-lg border border-slate-200 text-slate-400 hover:text-slate-600 hover:bg-slate-50"
            title="ล้างการเรียง"
            @click="clearSort"
          >
            <Icon name="x" :size="14" />
          </button>
        </div>
      </div>
    </div>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!agents.length" icon="users" title="ยังไม่มีตัวแทนในทีม" class="mt-4" />
      <template v-else>
        <!-- TASK-125 / TASK-203 — ONE grid, fed by whichever tab is active.
             In the "หัวหน้าทีม" tab the nodes are tree ROOTS, so each leader
             card carries its own downline (list + ขยายดูลูกทีม modal) exactly
             as before the split. In "ตัวแทนอิสระ"/"ลูกทีม"/"รออนุมัติเข้าทีม"
             the nodes have no children, so SalesTeamCard renders no team
             block and no expand control — there is nothing to expand. -->
        <EmptyState
          v-if="!listForActiveTab.length"
          icon="users"
          :title="
            isFlat
              ? 'ไม่พบตัวแทนที่ค้นหา'
              : activeTab === 'leaders'
                ? 'ยังไม่มีหัวหน้าทีม'
                : activeTab === 'independent'
                  ? 'ไม่มีตัวแทนอิสระ — ทุกคนอยู่ในทีมแล้ว'
                  : activeTab === 'members'
                    ? 'ยังไม่มีลูกทีม'
                    : 'ไม่มีคำขอเข้าทีมที่รออนุมัติ'
          "
          class="mt-4"
        />
        <!-- TASK-127 — `in-leaders-tab` suppresses the gold "หัวหน้าทีม"
             badge/chrome inside the tab of the same name, where it would
             only restate the tab title on every card. The downline modal
             below deliberately does NOT pass it: a nested sub-leader there
             must still stand out from a plain member.
             TASK-203 — `show-approval-actions` renders Approve/Reject ONLY
             on cards painted from the "รออนุมัติเข้าทีม" tab (see
             SalesTeamCard's own docblock for why the buttons are scoped to
             one tab even though the amber chip itself is not). -->
        <SalesTeamGrid
          v-else
          :nodes="listForActiveTab"
          :pre-sorted="isFlat"
          :in-leaders-tab="activeTab === 'leaders'"
          :show-approval-actions="activeTab === 'pending'"
          class="mt-4"
        />
      </template>
    </template>

    <!-- ═══════════ Team expand modal (60vw, 3-col sub-cards) ═══════════ -->
    <div
      v-if="teamModalNode"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4"
      @click.self="closeTeamModal"
    >
      <div class="w-[60vw] min-w-[320px] max-w-[60vw] bg-white rounded-2xl shadow-lg p-5 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-bold text-slate-900 flex items-center gap-1.5">
            <Icon name="star" :size="14" class="text-amber-500" /> ลูกทีมของ {{ teamModalNode.agent_name ?? '—' }}
          </p>
          <button class="text-slate-400 hover:text-slate-600" @click="closeTeamModal"><Icon name="close" :size="18" /></button>
        </div>
        <SalesTeamGrid :nodes="teamModalNode.children" />
      </div>
    </div>

    <!-- ═══════════ Per-agent clients drawer ═══════════ -->
    <Transition name="drawer">
      <div v-if="drawerOpen" class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-slate-900/30" @click="closeDrawer" />
        <div class="drawer-panel relative w-[60vw] min-w-[320px] max-w-[60vw] bg-white h-full shadow-xl p-5 overflow-y-auto">
          <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-bold text-slate-900 truncate">ลูกค้าของ {{ drawerAgent?.agent_name ?? '—' }}</h2>
            <button class="text-slate-400 hover:text-slate-600" @click="closeDrawer"><Icon name="close" :size="20" /></button>
          </div>
          <p class="text-xs text-slate-400 mb-4">รายชื่อลูกค้าที่ตัวแทนคนนี้ดูแล — กด ‘ดูรายละเอียด’ เพื่อเปิดแฟ้มลูกค้า</p>

          <p v-if="drawerLoading" class="text-sm text-slate-400">กำลังโหลด...</p>
          <div v-else-if="drawerError" class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ drawerError }}</div>
          <EmptyState v-else-if="!drawerClients.length" icon="users" title="ยังไม่มีลูกค้า" />
          <div v-else class="space-y-2">
            <div v-for="c in drawerClients" :key="c.id" class="border border-slate-200 rounded-xl p-3">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-brand-500 text-white flex items-center justify-center text-xs font-bold shrink-0">
                      {{ initial(c.name) }}
                    </div>
                    <div class="min-w-0">
                      <p class="text-sm font-bold text-slate-900 truncate">{{ c.name }}</p>
                      <p class="text-xs text-slate-400 truncate">{{ c.phone }}</p>
                    </div>
                  </div>
                  <div class="flex flex-wrap items-center gap-1.5 mt-2">
                    <span :class="['text-[10px] font-bold px-2 py-0.5 rounded-lg', statusBadgeClasses(c.status.key)]">{{ c.status.label }}</span>
                    <span
                      v-for="r in c.referrals"
                      :key="r.id"
                      class="text-[10px] font-bold px-2 py-0.5 rounded-lg bg-brand-50 text-brand-700"
                    >
                      {{ r.product?.name ?? 'ไม่ระบุสินค้า' }} · {{ r.current_stage.label }}
                    </span>
                  </div>
                </div>
                <button
                  type="button"
                  class="shrink-0 flex items-center gap-1 px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 whitespace-nowrap"
                  @click="viewClientFile(c.id)"
                >
                  <Icon name="user" :size="14" /> ดูรายละเอียด
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- TASK-066 — replaces native window.confirm() for grant-cert-without-exam.
         Bug fix (2026-08-01, human-reported: sub-menu nav needed a hard
         refresh to render) — this was a SIBLING of <main>, making the
         template a multi-root Fragment, which breaks App.vue's
         <Transition mode="out-in"> around <RouterView> (see
         AgentManagementView.vue's identical fix for the full
         explanation). Moved inside <main>. -->
    <ConfirmDialog
      :show="pendingGrant !== null"
      variant="primary"
      :title='pendingGrant ? `อนุมัติใบรับรอง "${pendingGrant.tier.name}"` : ""'
      :body='pendingGrant ? `อนุมัติให้ ${pendingGrant.agentName ?? "ตัวแทน"} ผ่านใบรับรอง "${pendingGrant.tier.name}" โดยไม่ต้องสอบจริง ยืนยันหรือไม่?` : ""'
      :busy="grantingTierKey !== null"
      @confirm="confirmGrantCertification"
      @update:show="(v) => { if (!v) pendingGrant = null }"
    />

    <!-- TASK-126 — confirming ONLY the removal of the team-leader
         capability. Granting it is immediate (it adds an ability and is
         undone by the same control), but removing it silently kills every
         recruit link that agent already handed out — an effect with no
         visible trace on this page — so it gets a stop.
         `variant="danger"` for the same reason. Inside <main> like the
         dialog above: a sibling would make this template a multi-root
         Fragment and break App.vue's <Transition mode="out-in">. -->
    <ConfirmDialog
      :show="pendingLeaderRevoke !== null"
      variant="danger"
      title="ยกเลิกสิทธิ์หัวหน้าทีม"
      :body='pendingLeaderRevoke
        ? `ยกเลิกสิทธิ์หัวหน้าทีมของ ${pendingLeaderRevoke.agentName ?? "ตัวแทน"} — ลิงก์ชวนทีมที่แจกไปแล้วทั้งหมดจะรับสมัครใครไม่ได้อีก และจะอนุมัติตัวแทนที่ตัวเองชวนมาเองไม่ได้ ยืนยันหรือไม่?`
        : ""'
      :busy="structureSavingAgentId !== null"
      @confirm="confirmRevokeTeamLeader"
      @update:show="(v) => { if (!v) pendingLeaderRevoke = null }"
    />

    <!-- TASK-129 — the SAME edit form จัดการตัวแทน uses. Inside <main> like
         the dialogs above: a sibling would make this template a multi-root
         Fragment and break App.vue's <Transition mode="out-in">. -->
    <AgentEditModal
      :agent-id="editingAgentId"
      @close="editingAgentId = null"
      @saved="onAgentEditorSaved"
    />

    <!-- TASK-210 — shown after <AgentEditModal> has closed itself. -->
    <SuccessDialog v-model:show="showSavedDialog" :body="savedMessage" />
  </main>
</template>
