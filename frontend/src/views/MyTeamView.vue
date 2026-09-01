<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * MyTeamView — TASK-109 / ADR-024. The team leader's monitoring screen.
 *
 * READ-ONLY BY CONSTRUCTION (ADR-024 §7). There is deliberately no edit,
 * advance-stage, grant-certification or mark-paid control anywhere in this
 * file, and the only verbs it issues are GETs. That is not merely a UI
 * choice: `/me/team` exposes no write route at all, so a button here would
 * have nothing to call. Anyone adding one must go back to ADR-024 first.
 *
 * WHAT IT SHOWS
 *  - Header KPIs rolled up over the leader's WHOLE subtree (the API does
 *    that rollup; this view never sums a tree client-side).
 *  - A vertically nested list of the downline, expanded ONE LEVEL AT A TIME
 *    (ADR-024 §3) — the deeper levels are fetched only when a leader asks
 *    for them, so a wide organisation costs one request per node opened
 *    instead of one enormous tree on load.
 *  - A per-member client drill-down, gated server-side by the company's
 *    configured visibility level (ADR-024 §5).
 *
 * BR-3 — every money figure crossing the wire is an INTEGER in satang. The
 * division by 100 happens in formatBaht() and nowhere else, at render time.
 * No arithmetic in this file ever produces a fractional baht value.
 *
 * BR-4 — the three money figures per member (sales / their commission / my
 * override from them) are READ from the commission ledger by the API. This
 * view never recomputes, re-rates or re-totals a commission.
 *
 * ADR-023 — colours come from the surface/ink/line token layer only. No
 * `text-slate-*`, `text-white` or `bg-white` appears below.
 *
 * ADR-021 — on a phone HeroHeader publishes its identity row into the app
 * top bar and renders only the tabs row here, so this screen spends ~45px
 * of the 15% header budget. See the mobile summary card in the body for
 * why the KPIs are also repeated there.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * TASK-116 / ADR-025 — THE ONE WRITE SURFACE ON THIS SCREEN.
 *
 * The read-only rule above still governs everything about CLIENT AND SALES
 * data. ADR-025 §7 carves out exactly one exception, and this file now
 * carries it: a designated team leader may mint/revoke their own recruit
 * links and admit their own pending recruits into the company. Nothing else
 * became writable — there is still no edit, advance-stage, grant-cert or
 * mark-paid control anywhere below, and the recruit endpoints touch only
 * `agent_invite_links` and the recruit's own `agent_approval_status`.
 *
 * THE DISTINCTION THAT DRIVES THE WHOLE GATE (ADR-025 §2) — two capabilities
 * that look alike and are deliberately NOT merged:
 *
 *   `isLeader`      (from GET /me/team) = "I have direct reports".
 *                   Gates the MONITOR: the tabs, the roster, the KPIs.
 *   `isTeamLeader`  (from /me → users.is_team_leader) = "an admin designated
 *                   me a team leader". Gates RECRUITING: the "ชวนเข้าทีม"
 *                   action, the links list, the approval queue.
 *
 * They disagree in both directions, and both disagreements are intentional:
 *   • Flag but no reports — a brand-new leader who has not recruited anyone
 *     yet. They MUST still see the invite affordance, otherwise the feature
 *     is unreachable for exactly the person it was granted to. This is why
 *     the recruit block sits OUTSIDE the `v-else-if="!isLeader"` branch
 *     below rather than inside the roster.
 *   • Reports but no flag — an ex-leader, or a manager an admin never
 *     flagged. They keep their monitor and get no recruiting controls.
 * Gating both on one boolean would silently break one of those two people.
 *
 * The flag is a UI affordance only. Every action it reveals is re-authorised
 * server-side (AgentInviteLinkService's is_team_leader guard →  422,
 * UserPolicy::approveRegistration → 403), so a tampered store buys nothing;
 * see the 403 handling in loadRecruitData().
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { ApiError, api } from '@/api/client'
// TASK-079 Phase 2/4 — the one shared error normalizer (never a raw HTTP
// status in front of an agent) plus the abort guard, so leaving the screen
// mid-load never paints a failure for a page nobody is on.
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import { initials } from '@/utils/initials'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import TabFilterBar from '@/design-system/components/TabFilterBar.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import AppList from '@/design-system/components/AppList.vue'
import AppListGroupHeader from '@/design-system/components/AppListGroupHeader.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import Icon from '@/design-system/components/Icon.vue'
// TASK-116 — reused wholesale, nothing new built: ShareLinkModal is the
// SAME QR + copy + LINE/email sheet product sharing and order payment links
// already use, ConfirmDialog is the app-wide replacement for
// window.confirm(), and BuddhistDateInput is how every date in this app is
// keyed (พ.ศ. on screen, ค.ศ. on the wire).
import ShareLinkModal from '@/design-system/components/ShareLinkModal.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import BuddhistDateInput from '@/design-system/components/BuddhistDateInput.vue'

// ── API contract (backend: MeTeamController + TeamNodeResource) ─────────

type VisibilityLevel = 'counts_only' | 'names' | 'full_file'

interface CertTier {
  id: number
  key: string
  name: string
}

interface TeamNode {
  agent_id: number
  name: string
  avatar_url: string | null
  cert_tier: CertTier | null
  has_children: boolean
  client_count: number
  deals_by_stage: Record<string, number>
  total_deals: number
  closed_deals: number
  sales_satang: number
  commission_satang: number
  my_override_satang: number
}

interface TeamTotals {
  member_count: number
  client_count: number
  deals_by_stage: Record<string, number>
  total_deals: number
  closed_deals: number
  sales_satang: number
  commission_satang: number
  my_override_satang: number
}

interface TeamPayload {
  is_leader: boolean
  visibility_level: VisibilityLevel
  parent_id: number | null
  totals: TeamTotals
  nodes: TeamNode[]
}

/**
 * The drill-down item. Only `id` is guaranteed at every level — at `names`
 * the API sends exactly { id, name, current_stage }, and at `full_file` it
 * sends the whole Client File. Everything past `name` is therefore
 * optional here, because a missing key is the level doing its job, not a
 * payload defect (ADR-024 §5: a field the level forbids is ABSENT, not
 * null). This view renders only the handful of fields a monitoring screen
 * needs, so it stays correct at both levels without branching.
 */
interface TeamClient {
  id: number
  name?: string
  current_stage?: { key: string; label: string } | null
  status?: { key: string; label: string } | null
  referrals?: Array<{ id: number; current_stage?: { key: string; label: string } | null }>
}

interface TeamClientsResponse {
  data: TeamClient[]
  meta: {
    agent_id: number
    visibility_level: VisibilityLevel
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

/**
 * TASK-113 — AgentInviteLinkResource, field for field.
 *
 * `max_uses` and `expires_at` are NULLABLE and null means UNLIMITED
 * (ADR-025 §3). They are typed `| null` rather than defaulted to 0/'' on
 * purpose: coercing either to a falsy number here would turn "ไม่จำกัด"
 * into "0 คน" / "หมดอายุแล้ว", i.e. the UI would report the exact opposite
 * of the truth. Every render path below branches on `=== null` explicitly.
 */
interface AgentInviteLink {
  id: number
  company_id: number
  agent_id: number
  label: string | null
  token: string
  public_url: string
  /** TASK-235 — /j/<code>. Null before the feature; fall back, never swap. */
  short_url: string | null
  used_count: number
  max_uses: number | null
  expires_at: string | null
  revoked_at: string | null
  /**
   * The server's verdict from AgentInviteLink::isUsable() — the single
   * source of truth for "not revoked AND not expired AND quota left". This
   * view NEVER recomputes it from the three fields above: a second copy of
   * that rule in Vue is precisely how it drifts from the backend's (which
   * also knows about the inviter's own state — deactivated, de-flagged, or
   * moved company — none of which is in this payload at all).
   */
  is_usable: boolean
  created_at: string
}

/** TASK-115 — PendingRecruitResource. Deliberately thin: a leader is not
 *  entitled to a teammate's email/phone/national ID, so name + avatar +
 *  signup date + verification state is all that crosses the wire. */
interface PendingRecruit {
  id: number
  name: string
  avatar_url: string | null
  registered_at: string
  email_verified: boolean
  // `whenLoaded` on the backend, so the key can legitimately be absent.
  invite_link?: { id: number; label: string | null } | null
}

// ── state ──────────────────────────────────────────────────────────────

const toast = useToastStore()
const auth = useAuthStore()

const loading = ref(true)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

const isLeader = ref(false)
const visibilityLevel = ref<VisibilityLevel>('counts_only')
const totals = ref<TeamTotals | null>(null)
const rootNodes = ref<TeamNode[]>([])

/**
 * THE LAZY-EXPANSION CACHE.
 *
 * `childrenByParent[agentId]` holds the level fetched for that node the
 * first time it was opened. It is never invalidated while the screen is
 * mounted, which is the whole point: collapsing a node only removes its id
 * from `expandedIds`, so re-opening it re-renders from this map and issues
 * NO second request. Expanding a 20-person branch three times in a row
 * therefore costs one request, not three — which matters on the phone
 * connection this portal is actually used on.
 *
 * Keyed by agent id rather than nested inside the node objects so that the
 * nodes stay exactly what the API returned; nothing in this file mutates a
 * server payload.
 *
 * The cache is deliberately per-visit (plain component state, not a store):
 * team figures are money and pipeline counts, and a stale rollup surviving
 * a navigation would be worse than a re-fetch.
 */
const childrenByParent = ref<Record<number, TeamNode[]>>({})
const expandedIds = ref<Set<number>>(new Set())
const expandingIds = ref<Set<number>>(new Set())

// One controller for the screen's lifetime — see the import note above.
const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

// ── load ───────────────────────────────────────────────────────────────

async function loadTeam() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: TeamPayload }>('/me/team', pageAbort.signal)
    isLeader.value = res.data.is_leader
    visibilityLevel.value = res.data.visibility_level
    totals.value = res.data.totals
    rootNodes.value = res.data.nodes
    // A reload must not leave a cached level pointing at data that is no
    // longer on screen.
    childrenByParent.value = {}
    expandedIds.value = new Set()
  } catch (e) {
    if (isAbortError(e)) return
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลทีมไม่สำเร็จ')
    toast.error(errorMessage.value)
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
// Both loads are independent and neither blocks the other: a leader with a
// broken roster must still be able to approve a recruit, and vice versa.
onMounted(() => {
  void loadTeam()
  void loadRecruitData()
})

/**
 * Expand / collapse one node.
 *
 * Collapse is pure local state (no request, see the cache note above).
 * Expand fetches only on a cache MISS, and re-authorises server-side: the
 * `has_children` flag on the node is a rendering hint, never a permission
 * — `?parent_id=` is validated against the caller's own subtree by the API
 * and answers 404 for anything outside it (ADR-024 §3, the feature's
 * primary IDOR surface).
 */
async function toggleExpand(node: TeamNode) {
  const id = node.agent_id

  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id)
    return
  }

  expandedIds.value.add(id)

  if (childrenByParent.value[id]) return
  if (expandingIds.value.has(id)) return

  expandingIds.value.add(id)
  try {
    const res = await api.get<{ data: TeamPayload }>(`/me/team?parent_id=${id}`, pageAbort.signal)
    childrenByParent.value = { ...childrenByParent.value, [id]: res.data.nodes }
  } catch (e) {
    if (isAbortError(e)) return
    // Roll the row back to collapsed: an open-but-empty node reads as
    // "this person has no team", which would be a lie about their data.
    expandedIds.value.delete(id)
    toast.error(apiErrorMessage(e, 'เปิดดูลูกทีมของสมาชิกคนนี้ไม่สำเร็จ'))
  } finally {
    expandingIds.value.delete(id)
  }
}

// ── flattening (a nested list rendered as one v-for) ────────────────────

interface FlatRow {
  node: TeamNode
  depth: number
}

/**
 * Depth cap. The server already bounds the walk (DownlineService
 * MAX_DEPTH), so this is a rendering backstop only — it guarantees this
 * loop terminates even if a payload ever contained a cycle, rather than
 * locking up the phone while we find out.
 */
const MAX_RENDER_DEPTH = 20

/**
 * The visible tree, flattened into rows carrying their own depth.
 *
 * A flat array plus an indent is used instead of a recursive component on
 * purpose: expansion state, filtering and the empty state all then operate
 * on ONE list, and there is no component that can recurse into itself if a
 * payload is malformed.
 */
const flatRows = computed<FlatRow[]>(() => {
  const rows: FlatRow[] = []

  const walk = (nodes: TeamNode[], depth: number) => {
    if (depth > MAX_RENDER_DEPTH) return
    for (const node of nodes) {
      rows.push({ node, depth })
      if (!expandedIds.value.has(node.agent_id)) continue
      const children = childrenByParent.value[node.agent_id]
      if (children?.length) walk(children, depth + 1)
    }
  }

  walk(rootNodes.value, 0)
  return rows
})

/** Every node fetched so far, at any depth — what the filters can see. */
const loadedNodes = computed<TeamNode[]>(() => [
  ...rootNodes.value,
  ...Object.values(childrenByParent.value).flat(),
])

function openDeals(node: TeamNode): number {
  // total_deals and closed_deals are both server-side counts; this is a
  // presentation subtraction, not a business rule.
  return Math.max(0, node.total_deals - node.closed_deals)
}

type TabId = 'all' | 'no_deals' | 'follow_up'

const activeTab = ref<TabId>('all')

function matchesTab(node: TeamNode, tab: TabId): boolean {
  if (tab === 'no_deals') return node.total_deals === 0
  if (tab === 'follow_up') return openDeals(node) > 0
  return true
}

const tabs = computed(() => [
  { id: 'all', label: 'ทั้งหมด', count: loadedNodes.value.length },
  { id: 'no_deals', label: 'ยังไม่มีดีล', count: loadedNodes.value.filter((n) => matchesTab(n, 'no_deals')).length },
  { id: 'follow_up', label: 'ต้องตาม', count: loadedNodes.value.filter((n) => matchesTab(n, 'follow_up')).length },
])

/**
 * Filtering flattens the hierarchy deliberately.
 *
 * In "ทั้งหมด" the list is the tree, indented. Under a filter a node's
 * manager may not match while the node does, so keeping the indent would
 * draw children under a parent that is not on screen. The filtered views
 * are a lens ("who needs attention"), not a browse mode, so they render as
 * one flat list at depth 0 and hide the expand controls; "ทั้งหมด" remains
 * the way to walk the structure.
 *
 * A filter can only see members already fetched — that is inherent to
 * per-level lazy loading (ADR-024 §3), and the hint under the list says so
 * rather than letting a leader believe an unexpanded branch was checked.
 */
const visibleRows = computed<FlatRow[]>(() => {
  if (activeTab.value === 'all') return flatRows.value
  return loadedNodes.value
    .filter((node) => matchesTab(node, activeTab.value))
    .map((node) => ({ node, depth: 0 }))
})

const showsHierarchy = computed(() => activeTab.value === 'all')

/**
 * Indent per level, capped.
 *
 * The row already spends 44px on the expand gutter and 48px aligning the
 * money strip under the name; on a 375px screen an uncapped 16px-per-level
 * indent starts squeezing the three money columns at about the fifth
 * level. Past that depth the indent has stopped carrying information
 * anyway — the leader knows which node they opened — so it flattens rather
 * than eating the numbers this screen exists to show.
 */
const MAX_INDENT_LEVELS = 4

function indentPx(depth: number): string {
  if (!showsHierarchy.value) return '0px'
  return Math.min(depth, MAX_INDENT_LEVELS) * 16 + 'px'
}

// ── formatting ─────────────────────────────────────────────────────────

/** BR-3 — the ONLY place satang becomes baht, and only for the eye. */
function formatBaht(satang: number): string {
  return '฿' + (satang / 100).toLocaleString('th-TH')
}

function formatCount(value: number): string {
  return value.toLocaleString('th-TH')
}

const kpis = computed(() => {
  const t = totals.value
  if (!t) return []
  return [
    { label: 'ลูกทีมทั้งสาย', value: formatCount(t.member_count) + ' คน' },
    { label: 'ยอดขายรวม', value: formatBaht(t.sales_satang) },
    { label: 'ดีลที่ปิดได้', value: formatCount(t.closed_deals) },
  ]
})

// ── member drill-down (bottom sheet) ────────────────────────────────────

const sheetMember = ref<TeamNode | null>(null)
const sheetLoading = ref(false)
const sheetError = ref('')
const sheetClients = ref<TeamClient[]>([])
const sheetTotal = ref(0)
/** True when the company's level withholds client identity (see below). */
const sheetRestricted = ref(false)

/**
 * THE 403 CASE (ADR-024 §5).
 *
 * At `counts_only` the drill-down endpoint answers 403 — the endpoint
 * effectively does not exist for that tenant, and that is also the
 * fail-closed answer for a company that never configured the feature. A
 * 403 here is therefore a CONFIGURED POLICY, not a failure, so it must not
 * surface as the generic "คุณไม่มีสิทธิ์เข้าถึงส่วนนี้" error the
 * normalizer produces for a real permission problem: the leader did
 * nothing wrong and has no action to take.
 *
 * Handled in two places on purpose:
 *  1. We skip the request entirely when the payload already told us the
 *     level is counts_only — one fewer round trip, and a card that opens
 *     instantly instead of flashing a spinner to reach a foregone answer.
 *  2. We still map an actual 403 to the same restricted card, because the
 *     server is the enforcer: an admin can change the level (or switch the
 *     feature off) between the list load and this tap, and the UI must
 *     follow the server rather than its own cached copy.
 * The member's counts stay on screen in both cases — they are the part
 * this level does permit, so the sheet is still worth opening.
 */
async function openMember(node: TeamNode) {
  sheetMember.value = node
  sheetClients.value = []
  sheetTotal.value = 0
  sheetError.value = ''
  sheetRestricted.value = false

  if (visibilityLevel.value === 'counts_only') {
    sheetRestricted.value = true
    return
  }

  sheetLoading.value = true
  try {
    const res = await api.get<TeamClientsResponse>(
      `/me/team/${node.agent_id}/clients`,
      pageAbort.signal,
    )
    sheetClients.value = res.data
    sheetTotal.value = res.meta.total
    // Trust the echoed level over our cached one — see (2) above.
    visibilityLevel.value = res.meta.visibility_level
  } catch (e) {
    if (isAbortError(e)) return
    if (e instanceof ApiError && e.status === 403) {
      sheetRestricted.value = true
      visibilityLevel.value = 'counts_only'
      return
    }
    sheetError.value = apiErrorMessage(e, 'โหลดรายชื่อลูกค้าของสมาชิกคนนี้ไม่สำเร็จ')
    toast.error(sheetError.value)
  } finally {
    sheetLoading.value = false
  }
}

function closeSheet() {
  sheetMember.value = null
}

/**
 * The client's stage as this subordinate sees it. At `names` the API sends
 * `current_stage` directly; at `full_file` the shape is the full Client
 * File, whose stage lives on its referrals. Labels always come from the
 * API — this screen never invents a pipeline stage name (§4.3 vocabulary
 * is the backend's to word).
 */
function clientStageLabel(client: TeamClient): string | null {
  if (client.current_stage?.label) return client.current_stage.label
  const fromReferral = client.referrals?.find((r) => r.current_stage?.label)
  return fromReferral?.current_stage?.label ?? null
}

// Lock the page behind the sheet, same reason FilterSheet does: dragging
// on a sheet that scrolls the list underneath is the clearest "this is a
// web page, not an app" tell there is.
watch(sheetMember, (member) => {
  document.body.style.overflow = member ? 'hidden' : ''
})
onUnmounted(() => {
  document.body.style.overflow = ''
})

// ═══ TASK-116 / ADR-025 — recruiting (links + pending recruits) ═════════

/**
 * THE GATE. `users.is_team_leader`, read from the authenticated user — NOT
 * `isLeader` above, which only means "has direct reports". See the file
 * docblock for why merging the two would break a real person in each
 * direction.
 */
const isTeamLeader = computed(() => auth.user?.is_team_leader === true)

/**
 * Set when the server disagrees with the flag we read from /me.
 *
 * An admin can revoke `is_team_leader` at any moment, and ADR-025 §2 says
 * that must stop recruiting immediately — but this SPA holds the user
 * object for the whole session, so our copy can be minutes stale.
 * `/agent-approvals/my-recruits` answers 403 for a non-leader (deliberately,
 * rather than an empty list), which is the earliest honest signal we get.
 * On that signal the whole block collapses to one explanatory line: the
 * server is the enforcer and the UI follows it, never the other way round.
 */
const leaderCapabilityRevoked = ref(false)

/** What actually renders the recruit block: flagged AND not contradicted. */
const showRecruiting = computed(() => isTeamLeader.value && !leaderCapabilityRevoked.value)

const inviteLinks = ref<AgentInviteLink[]>([])
const pendingRecruits = ref<PendingRecruit[]>([])
const recruitLoading = ref(false)
const recruitError = ref('')

async function loadRecruitData() {
  if (!isTeamLeader.value) return

  recruitLoading.value = true
  recruitError.value = ''
  try {
    const [linksRes, recruitsRes] = await Promise.all([
      api.get<{ data: AgentInviteLink[] }>('/agent-invite-links', pageAbort.signal),
      api.get<{ data: PendingRecruit[] }>('/agent-approvals/my-recruits', pageAbort.signal),
    ])
    inviteLinks.value = linksRes.data
    pendingRecruits.value = recruitsRes.data
  } catch (e) {
    if (isAbortError(e)) return
    // See leaderCapabilityRevoked's docblock — a 403 here is a state change,
    // not a failure, so it must not surface as the normalizer's generic
    // "คุณไม่มีสิทธิ์เข้าถึงส่วนนี้": the leader did nothing wrong.
    if (e instanceof ApiError && e.status === 403) {
      leaderCapabilityRevoked.value = true
      return
    }
    recruitError.value = apiErrorMessage(e, 'โหลดข้อมูลการชวนเข้าทีมไม่สำเร็จ')
  } finally {
    recruitLoading.value = false
  }
}

/** Re-read the links only. Used after a revoke, because DELETE answers 204
 *  with no body and `is_usable` is the server's to decide, not ours. */
async function reloadLinks() {
  try {
    const res = await api.get<{ data: AgentInviteLink[] }>('/agent-invite-links', pageAbort.signal)
    inviteLinks.value = res.data
  } catch (e) {
    if (isAbortError(e)) return
    recruitError.value = apiErrorMessage(e, 'โหลดรายการลิงก์ไม่สำเร็จ')
  }
}

// ── create a link ──────────────────────────────────────────────────────

const showCreateSheet = ref(false)
const creating = ref(false)
const createError = ref('')
const createForm = ref({ label: '', expiresOn: '', maxUses: '' })

function openCreateSheet() {
  createError.value = ''
  createForm.value = { label: '', expiresOn: '', maxUses: '' }
  showCreateSheet.value = true
}

/**
 * All three fields are optional (ADR-025 §3 — the human chose "ตั้งค่าได้
 * ทั้งวันหมดอายุ และจำนวนคน หรือไม่ limit"), so an empty form is a valid
 * request that mints an unlimited, never-expiring link. Empty inputs are
 * OMITTED from the body rather than sent as '' — the Form Request's
 * `nullable|date` / `nullable|integer` would 422 on an empty string.
 */
async function submitCreateLink() {
  if (creating.value) return
  creating.value = true
  createError.value = ''
  try {
    const payload: Record<string, string | number> = {}
    if (createForm.value.label.trim()) payload.label = createForm.value.label.trim()
    if (createForm.value.maxUses) payload.max_uses = Number(createForm.value.maxUses)
    if (createForm.value.expiresOn) {
      // BuddhistDateInput gives a DATE ('YYYY-MM-DD'), which parses to
      // MIDNIGHT — so picking today would fail the backend's `after:now`
      // and picking tomorrow would kill the link at 00:00, hours before
      // the leader expects. To a human an expiry DATE means "usable
      // through the end of that day", so that is what we send.
      payload.expires_at = `${createForm.value.expiresOn}T23:59`
    }

    const res = await api.post<{ data: AgentInviteLink }>('/agent-invite-links', payload)
    inviteLinks.value = [res.data, ...inviteLinks.value]
    showCreateSheet.value = false
    toast.success('สร้างลิงก์ชวนเข้าทีมแล้ว')
    // Straight into the share sheet: minting a link nobody ever sends is a
    // dead end, and this is the one moment the leader definitely wants it.
    openShare(res.data)
  } catch (e) {
    if (isAbortError(e)) return
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { errors?: Record<string, string[]> }
      const errors = body.errors ?? {}
      // ADR-025 §1 — a non-leader minting comes back as a VALIDATION error
      // keyed `is_team_leader`, not a 403: "you are not a team leader" is a
      // business-rule outcome to explain, not an auth failure to scold
      // about. Reached when the flag was revoked between page load and this
      // tap, so the block is collapsed too.
      if (errors.is_team_leader?.[0]) {
        leaderCapabilityRevoked.value = true
        showCreateSheet.value = false
        toast.error(errors.is_team_leader[0])
        return
      }
      createError.value =
        errors.expires_at?.[0] ?? errors.max_uses?.[0] ?? errors.label?.[0] ?? apiErrorMessage(e, 'สร้างลิงก์ไม่สำเร็จ')
      return
    }
    createError.value = apiErrorMessage(e, 'สร้างลิงก์ไม่สำเร็จ')
  } finally {
    creating.value = false
  }
}

// ── share ──────────────────────────────────────────────────────────────

const showShare = ref(false)
const shareUrl = ref('')
const shareHeading = ref('')
// TASK-212 — the id the share sheet posts to /share-emails.
const shareLinkId = ref<number | null>(null)

function openShare(link: AgentInviteLink) {
  // Order matters: ShareLinkModal watches `show` and renders the QR from
  // `url` at that moment, so the url must already be in place.
  shareUrl.value = link.short_url ?? link.public_url
  shareLinkId.value = link.id
  shareHeading.value = link.label || 'ลิงก์ชวนเข้าทีม'
  showShare.value = true
}

// ── revoke a link ──────────────────────────────────────────────────────

const revokeTarget = ref<AgentInviteLink | null>(null)
const showRevokeConfirm = ref(false)
const revoking = ref(false)

function askRevoke(link: AgentInviteLink) {
  revokeTarget.value = link
  showRevokeConfirm.value = true
}

async function confirmRevoke() {
  const link = revokeTarget.value
  if (!link || revoking.value) return
  revoking.value = true
  try {
    await api.delete(`/agent-invite-links/${link.id}`)
    showRevokeConfirm.value = false
    revokeTarget.value = null
    toast.success('ยกเลิกลิงก์แล้ว')
    // Soft revoke (ADR-025 §3): the row survives so every recruit's
    // attribution keeps pointing at a real link. So it must NOT be dropped
    // from the list — refetch and let the server say what it is now.
    await reloadLinks()
  } catch (e) {
    if (isAbortError(e)) return
    // Toast as well as the banner: the dialog stays open over the page.
    const message = apiErrorMessage(e, 'ยกเลิกลิงก์ไม่สำเร็จ')
    recruitError.value = message
    toast.error(message)
  } finally {
    revoking.value = false
  }
}

// ── approve a recruit ──────────────────────────────────────────────────
//
// APPROVE ONLY. There is deliberately no reject control here and no copy
// anywhere in this file that mentions rejection: ADR-025 §7 scopes a leader
// to admitting their own recruits and nothing else, and the backend agrees
// — AgentApprovalController::reject() authorises against UserPolicy::update(),
// i.e. admins only. A reject button would 403 every time it was pressed.

const approveTarget = ref<PendingRecruit | null>(null)
const approving = ref(false)

/**
 * READ FIRST, THEN APPROVE (human request, 2026-08-21: "ปุ่มอนุมัติด้านบน
 * ต้องคลิ๊กดูรายละเอียดผู้สมัครถึงอนุมัติได้").
 *
 * The queue row used to carry the button itself, so admitting somebody into
 * the COMPANY — not merely into a roster; ADR-025 §7 accepts that a leader
 * can now do that with no admin looking — was one tap from a list, followed
 * by a confirm dialog that showed nothing the list had not already shown.
 * A dialog that adds no information is a dialog people learn to dismiss.
 *
 * So the row opens this sheet and the button lives inside it. The sheet
 * carries everything the dialog said PLUS the record: when they signed up,
 * to the minute, and which of the leader's links brought them. That makes
 * the extra tap buy something, which is the only thing that justifies it.
 *
 * The old ConfirmDialog is gone rather than stacked in front of this: three
 * steps to approve one recruit is how a leader stops reading any of them.
 */
function openRecruit(recruit: PendingRecruit) {
  approveTarget.value = recruit
}

function closeRecruit() {
  if (approving.value) return
  approveTarget.value = null
}

async function confirmApprove() {
  const recruit = approveTarget.value
  if (!recruit || approving.value) return
  approving.value = true
  try {
    await api.put(`/agent-approvals/${recruit.id}/approve`)
    pendingRecruits.value = pendingRecruits.value.filter((r) => r.id !== recruit.id)
    approveTarget.value = null
    toast.success(`รับ ${recruit.name} เข้าทีมแล้ว`)
    // The approved recruit is now a direct report, so the roster and its
    // rollups are stale — re-read them rather than patching a tree
    // client-side (this screen never sums a team itself).
    await loadTeam()
  } catch (e) {
    if (isAbortError(e)) return
    const message = apiErrorMessage(e, 'อนุมัติไม่สำเร็จ')
    recruitError.value = message
    toast.error(message)
    if (e instanceof ApiError && e.status === 403) leaderCapabilityRevoked.value = true
  } finally {
    approving.value = false
  }
}

// ── formatting for the recruit block ───────────────────────────────────

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

/**
 * Date AND time, for the recruit sheet only.
 *
 * The list row keeps the short date — several recruits from one campaign all
 * read "21 ส.ค. 2569" there and that is fine, because the row is a summary.
 * The sheet is where a leader decides, and "which of the two people who
 * signed up yesterday is this" is exactly the question the minute answers.
 */
function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

/** null max_uses = unlimited (ADR-025 §3) — never rendered as 0. */
function usageLabel(link: AgentInviteLink): string {
  return link.max_uses === null
    ? `ใช้ไปแล้ว ${formatCount(link.used_count)} คน · ไม่จำกัดจำนวน`
    : `ใช้ไปแล้ว ${formatCount(link.used_count)} / ${formatCount(link.max_uses)} คน`
}

/** null expires_at = never expires (ADR-025 §3) — never rendered as a date. */
function expiryLabel(link: AgentInviteLink): string {
  return link.expires_at ? `หมดอายุ ${formatDate(link.expires_at)}` : 'ไม่มีวันหมดอายุ'
}

/**
 * Status chip. `is_usable` is read, never derived (see the interface). The
 * two dead states are separated only by `revoked_at`, which is a plain fact
 * on the row — "ยกเลิกแล้ว" is something the leader DID and is worth
 * distinguishing from a link that simply ran out.
 */
function linkStatus(link: AgentInviteLink): { label: string; usable: boolean } {
  if (link.is_usable) return { label: 'ใช้งานได้', usable: true }
  if (link.revoked_at) return { label: 'ยกเลิกแล้ว', usable: false }
  return { label: 'หมดอายุ / ครบจำนวน', usable: false }
}

// Same body-scroll lock the member sheet uses, for the same reason.
watch(showCreateSheet, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8" style="font-family: var(--app-font);">
    <HeroHeader
      icon="users"
      :title="td('nav.my_team2')"
      :subtitle="td('team.subtitle')"
      :description="td('team.description')"
      :kpis="kpis"
      accent-color="brand"
      storage-key="my-team"
      back-page="/"
      :back-label="td('nav.home2')"
    >
      <!-- TASK-116 — gated on the FLAG, never on `isLeader` (= has direct
           reports). A newly designated leader has no reports yet and would
           otherwise never see the affordance they were just granted; see
           the file docblock. ADR-021: on a phone HeroHeader teleports this
           slot into the app top bar, so the label has to stay short —
           "ชวนเข้าทีม" fits beside the bell and avatar at 375px. -->
      <template v-if="showRecruiting" #actions>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 min-h-[44px] px-3.5 rounded-xl bg-brand-600 text-ink-primary text-sm font-bold shadow-sm active:scale-95 transition"
          @click="openCreateSheet"
        >
          <Icon name="user_plus" :size="16" />
          <span>{{ td('team.invite') }}</span>
        </button>
      </template>

      <template v-if="isLeader" #tabs>
        <div class="px-4">
          <TabFilterBar v-model="activeTab" :tabs="tabs" accent-color="brand" />
        </div>
      </template>
    </HeroHeader>

    <!-- Retry lives inside the banner: TASK-079 Phase 2 found failed loads
         left the agent with nothing to tap but the browser reload. -->
    <div
      v-if="errorMessage"
      class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3"
    >
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-surface-chip active:scale-95 transition"
        @click="loadTeam"
      >
        {{ td('common.retry') }}
      </button>
    </div>

    <!-- ══ TASK-116 / ADR-025 — recruiting ═══════════════════════════════
         Sits ABOVE the roster and OUTSIDE the `!isLeader` branch below, on
         purpose: (a) "รออนุมัติ" is the only action-required thing on this
         screen and must not be scrolled past, (b) the create action lives
         in the header, so the links it produces belong next to it, and
         (c) a flagged leader with no reports yet still gets the whole block
         even though the roster underneath shows "คุณยังไม่มีลูกทีม". -->
    <section v-if="showRecruiting" class="mt-4">
      <div
        v-if="recruitError"
        class="px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3"
      >
        <span>{{ recruitError }}</span>
        <button
          type="button"
          class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-surface-chip active:scale-95 transition"
          @click="loadRecruitData"
        >
          {{ td('common.retry') }}
        </button>
      </div>

      <!-- ── รออนุมัติ ──────────────────────────────────────────────────
           Rendered only when someone is actually waiting. This is an action
           queue, not a status panel: an always-present "ไม่มีผู้รออนุมัติ"
           card would spend a permanent block of a 375px screen saying
           nothing. The links section below is the stable home for the
           feature, so nothing becomes undiscoverable by hiding this. -->
      <template v-if="pendingRecruits.length">
        <AppListGroupHeader :label="td('team.pending_tab')" :count="pendingRecruits.length" />
        <AppList>
          <AppCard v-for="recruit in pendingRecruits" :key="recruit.id" variant="flat">
            <!-- THE WHOLE ROW OPENS THE DETAIL SHEET, and the approve button
                 lives inside it — see openRecruit() for why the decision
                 moved off the list. A <button> rather than a div so it is
                 reachable by keyboard and announced as activatable;
                 w-full/text-left because a button centres and shrinks its
                 content by default. -->
            <button
              type="button"
              class="flex items-center gap-3 w-full text-left"
              @click="openRecruit(recruit)"
            >
              <img
                v-if="recruit.avatar_url"
                :src="recruit.avatar_url"
                alt=""
                class="w-9 h-9 rounded-full object-cover border border-line-card shrink-0"
              />
              <span
                v-else
                class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center shrink-0"
              >
                {{ initials(recruit.name) }}
              </span>

              <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-ink-card truncate">{{ recruit.name }}</p>
                <p class="text-[11px] text-ink-card-subtle mt-0.5">
                  สมัครเมื่อ {{ formatDate(recruit.registered_at) }}
                  <template v-if="recruit.invite_link?.label"> · จากลิงก์ “{{ recruit.invite_link.label }}”</template>
                </p>
                <!-- TASK-115: approving an unverified person does not let
                     them in — the login gate still blocks them. Said here
                     as well as in the sheet so it is visible before the
                     leader ever taps. -->
                <p v-if="!recruit.email_verified" class="text-[11px] text-ink-warning mt-0.5">
                  {{ td('team.unverified_email') }}
                </p>
              </div>

              <!-- A chevron, not an approve button. The row's job is now to
                   say "there is more to read here". -->
              <Icon name="chevron_right" :size="18" class="shrink-0 text-ink-card-subtle" />
            </button>
          </AppCard>
        </AppList>
      </template>

      <!-- ── ลิงก์ชวนเข้าทีม ─────────────────────────────────────────── -->
      <AppListGroupHeader :label="td('team.my_invites')" :count="inviteLinks.length" />

      <LoadingSkeleton v-if="recruitLoading && !inviteLinks.length" type="list" :rows="2" />

      <!-- Compact inline empty state with a live CTA (not EmptyState.vue's
           default disabled one — this create flow is real). -->
      <div
        v-else-if="!inviteLinks.length"
        class="flex items-center gap-4 py-5 px-5 rounded-xl bg-surface-card/95 border border-dashed border-line-card"
      >
        <Icon name="link" :size="24" class="text-ink-card-subtle shrink-0" />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-bold text-ink-card-muted">{{ td('team.no_invites') }}</p>
          <p class="text-xs text-ink-card-subtle mt-0.5">{{ td('team.invite_help') }}</p>
        </div>
        <button
          type="button"
          class="shrink-0 min-h-[44px] px-3.5 rounded-xl bg-brand-600 text-ink-primary text-xs font-bold active:scale-95 transition"
          @click="openCreateSheet"
        >
          {{ td('link.create_first') }}
        </button>
      </div>

      <AppList v-else>
        <AppCard v-for="link in inviteLinks" :key="link.id" variant="flat">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2 min-w-0">
                <p class="text-sm font-bold text-ink-card truncate">{{ link.label || 'ลิงก์ชวนเข้าทีม' }}</p>
                <span
                  class="shrink-0 text-[11px] font-bold px-2 py-0.5 rounded-full"
                  :class="linkStatus(link).usable ? 'bg-surface-success text-ink-success' : 'bg-surface-chip text-ink-chip'"
                >
                  {{ linkStatus(link).label }}
                </span>
              </div>
              <p class="text-[11px] text-ink-card-muted mt-1 tabular-nums">{{ usageLabel(link) }}</p>
              <p class="text-[11px] text-ink-card-subtle mt-0.5">{{ expiryLabel(link) }}</p>
            </div>

            <div class="flex items-center gap-1 shrink-0">
              <!-- Share stays available on a dead link: the leader may want
                   to re-read what they sent. The recruit-side flow is the
                   thing that stops working, and the backend says so. -->
              <button
                type="button"
                class="w-11 h-11 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-chip hover:text-ink-brand transition-all active:scale-90"
                :aria-label="td('link.share')"
                :title="td('link.share')"
                @click="openShare(link)"
              >
                <Icon name="share" :size="16" />
              </button>
              <button
                v-if="!link.revoked_at"
                type="button"
                class="w-11 h-11 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-danger hover:text-ink-danger transition-all active:scale-90"
                :aria-label="td('link.revoke')"
                :title="td('link.revoke')"
                @click="askRevoke(link)"
              >
                <Icon name="trash" :size="16" />
              </button>
            </div>
          </div>
        </AppCard>
      </AppList>
    </section>

    <!-- The flag was revoked while this session was open (ADR-025 §2 —
         losing it stops recruiting at once). Explained rather than silently
         disappeared, so a leader mid-recruiting-drive knows why. -->
    <div
      v-else-if="isTeamLeader && leaderCapabilityRevoked"
      class="mt-4 flex items-start gap-3 px-4 py-3 rounded-xl bg-surface-warning border border-line-card text-sm text-ink-warning"
    >
      <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
      <span>{{ td('team.invite_disabled') }}</span>
    </div>

    <!-- Single-rooted per branch: App.vue wraps <RouterView> in a
         <Transition mode="out-in">, which breaks on a multi-root fragment. -->
    <Transition name="content-fade">
      <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="5" class="mt-4" />

      <!-- ADR-024: an agent with no reports must land on a calm, explained
           state — never a 403 screen and never an error. The API answers
           200 with is_leader:false for exactly this case, so typing
           /my-team directly is a supported (if pointless) thing to do. -->
      <div
        v-else-if="!isLeader"
        class="mt-4 flex items-center gap-4 py-6 px-5 rounded-xl bg-surface-card/95 border border-dashed border-line-card"
      >
        <Icon name="users" :size="24" class="text-ink-card-subtle shrink-0" />
        <div class="min-w-0">
          <p class="text-sm font-bold text-ink-card-muted">{{ td('team.no_downline') }}</p>
          <p class="text-xs text-ink-card-subtle mt-0.5">
            {{ td('team.no_downline_help') }}
          </p>
        </div>
      </div>

      <div v-else class="mt-4">
        <!-- KPI card, mobile only.
             ADR-021 moves the identity row (and with it the KPI strip) out
             of the page body on phones, so HeroHeader's `kpis` render on
             desktop only. That ADR's own follow-up says a screen that needs
             KPIs on a phone must put them in the body as a normal content
             card — which this screen does need: "ทีมปิดดีลไปกี่ดีล" is the
             question it exists to answer. `sm:hidden` keeps it from
             duplicating the header strip on desktop. -->
        <AppCard v-if="totals" variant="raised" class="sm:hidden grid grid-cols-3 gap-2">
          <div>
            <p class="text-[11px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ td('team.whole_downline') }}</p>
            <p class="text-lg font-bold text-ink-card leading-tight tabular-nums">{{ formatCount(totals.member_count) }}</p>
          </div>
          <div>
            <p class="text-[11px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ td('stat.total_sales') }}</p>
            <p class="text-lg font-bold text-ink-card leading-tight tabular-nums">{{ formatBaht(totals.sales_satang) }}</p>
          </div>
          <div>
            <p class="text-[11px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ td('stat.closed_deals') }}</p>
            <p class="text-lg font-bold text-ink-card leading-tight tabular-nums">{{ formatCount(totals.closed_deals) }}</p>
          </div>
        </AppCard>

        <AppListGroupHeader
          :label="showsHierarchy ? 'สายงานของฉัน' : 'สมาชิกที่ตรงเงื่อนไข'"
          :count="visibleRows.length"
        />

        <!-- Compact inline empty state, same shape as EmptyState.vue (no
             CTA here — there is nothing a leader may create on this
             screen). -->
        <div
          v-if="!visibleRows.length"
          class="flex items-center gap-4 py-6 px-5 rounded-xl bg-surface-card/95 border border-dashed border-line-card"
        >
          <Icon name="users" :size="24" class="text-ink-card-subtle shrink-0" />
          <div class="min-w-0">
            <p class="text-sm font-bold text-ink-card-muted">{{ td('team.no_match') }}</p>
            <p class="text-xs text-ink-card-subtle mt-0.5">{{ td('team.try_all_tab') }}</p>
          </div>
        </div>

        <AppList v-else>
          <AppCard
            v-for="row in visibleRows"
            :key="row.node.agent_id"
            variant="flat"
            padding="none"
          >
            <div
              class="flex items-stretch gap-1 pr-2"
              :style="{ paddingLeft: indentPx(row.depth) }"
            >
              <!-- Expand control. 44px square (Apple HIG minimum) and
                   `active:` for press feedback — `hover:` never fires on a
                   touchscreen, which is exactly how a control ends up
                   feeling dead on the phone this portal runs on.
                   A node with no children keeps the same 44px of gutter so
                   names stay aligned down the column. -->
              <button
                v-if="showsHierarchy && row.node.has_children"
                type="button"
                class="shrink-0 w-11 min-h-[44px] flex items-center justify-center rounded-lg text-ink-card-muted active:bg-surface-chip active:scale-95 transition"
                :aria-expanded="expandedIds.has(row.node.agent_id)"
                :aria-label="expandedIds.has(row.node.agent_id) ? 'ย่อลูกทีม' : 'กางลูกทีม'"
                @click="toggleExpand(row.node)"
              >
                <Icon
                  v-if="expandingIds.has(row.node.agent_id)"
                  name="refresh"
                  :size="16"
                  class="animate-spin"
                />
                <Icon
                  v-else
                  :name="expandedIds.has(row.node.agent_id) ? 'chevron_down' : 'chevron_right'"
                  :size="18"
                />
              </button>
              <span v-else-if="showsHierarchy" class="shrink-0 w-11" aria-hidden="true"></span>

              <!-- The row body opens the member's client drill-down. -->
              <button
                type="button"
                class="flex-1 min-w-0 text-left py-3 px-1 min-h-[44px] rounded-lg active:bg-surface-chip transition-colors"
                @click="openMember(row.node)"
              >
                <div class="flex items-center gap-3 min-w-0">
                  <img
                    v-if="row.node.avatar_url"
                    :src="row.node.avatar_url"
                    alt=""
                    class="w-9 h-9 rounded-full object-cover border border-line-card shrink-0"
                  />
                  <span
                    v-else
                    class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center shrink-0"
                  >
                    {{ initials(row.node.name) }}
                  </span>

                  <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 min-w-0">
                      <p class="text-sm font-bold text-ink-card truncate">{{ row.node.name }}</p>
                      <span
                        v-if="row.node.cert_tier"
                        class="shrink-0 text-[11px] font-bold px-2 py-0.5 rounded-full bg-surface-chip text-ink-chip"
                      >
                        {{ row.node.cert_tier.name }}
                      </span>
                    </div>
                    <p class="text-xs text-ink-card-muted mt-0.5 tabular-nums">
                      ลูกค้า {{ formatCount(row.node.client_count) }}
                      · ดีลเปิด {{ formatCount(openDeals(row.node)) }}
                      · ปิด {{ formatCount(row.node.closed_deals) }}
                    </p>
                  </div>

                  <Icon name="chevron_right" :size="16" class="shrink-0 text-ink-card-subtle" />
                </div>

                <!-- Money row. BR-3: all three are integer satang until
                     formatBaht() divides for display. BR-4: all three are
                     read from the ledger by the API, never recomputed. -->
                <div class="mt-2 grid grid-cols-3 gap-2 pl-12">
                  <div>
                    <p class="text-[10px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ td('stat.sales') }}</p>
                    <p class="text-xs font-bold text-ink-card tabular-nums">{{ formatBaht(row.node.sales_satang) }}</p>
                  </div>
                  <div>
                    <p class="text-[10px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ td('team.their_commission') }}</p>
                    <p class="text-xs font-bold text-ink-card tabular-nums">{{ formatBaht(row.node.commission_satang) }}</p>
                  </div>
                  <div>
                    <p class="text-[10px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ td('team.my_override') }}</p>
                    <p class="text-xs font-bold text-ink-card tabular-nums">{{ formatBaht(row.node.my_override_satang) }}</p>
                  </div>
                </div>
              </button>
            </div>
          </AppCard>
        </AppList>

        <p v-if="!showsHierarchy && visibleRows.length" class="mt-2 px-1 text-[11px] text-ink-card-subtle">
          {{ td('team.filter_note') }}
        </p>
      </div>
    </Transition>

    <!-- ── Member drill-down sheet ────────────────────────────────────── -->
    <Teleport to="body">
      <!-- The scrim stays a fixed dark wash rather than a theme token, same
           as FilterSheet.vue: a scrim is not a surface — it must darken
           whatever the tenant chose, so deriving it from the card colour
           would make it vanish on a dark theme. (ADR-023 forbids new
           `text-slate-*` / `text-white` / `bg-white`; this is none of
           those.) -->
      <Transition name="sheet-fade">
        <div v-if="sheetMember" class="fixed inset-0 z-[60] bg-slate-900/40" @click="closeSheet"></div>
      </Transition>

      <Transition name="sheet-slide">
        <div
          v-if="sheetMember"
          class="fixed inset-x-0 bottom-0 z-[61] mx-auto w-full max-w-md rounded-t-3xl bg-surface-card shadow-[0_-8px_24px_rgba(0,0,0,0.18)] pb-[env(safe-area-inset-bottom)]"
          role="dialog"
          aria-modal="true"
          :aria-label="sheetMember.name"
        >
          <div class="flex justify-center pt-3 pb-1">
            <div class="h-1 w-10 rounded-full bg-surface-chip"></div>
          </div>

          <div class="flex items-start justify-between gap-2 px-5 py-2">
            <div class="min-w-0">
              <h2 class="text-base font-bold text-ink-card truncate">{{ sheetMember.name }}</h2>
              <p class="text-xs text-ink-card-muted mt-0.5 tabular-nums">
                ลูกค้า {{ formatCount(sheetMember.client_count) }}
                · ดีลเปิด {{ formatCount(openDeals(sheetMember)) }}
                · ปิด {{ formatCount(sheetMember.closed_deals) }}
              </p>
            </div>
            <button
              type="button"
              class="shrink-0 w-11 h-11 -mr-2 flex items-center justify-center rounded-full text-ink-card-subtle active:bg-surface-chip"
              :aria-label="td('common.close2')"
              @click="closeSheet"
            >
              <Icon name="close" :size="18" />
            </button>
          </div>

          <div class="max-h-[55vh] overflow-y-auto px-5 pb-5">
            <p v-if="sheetLoading" class="py-6 text-center text-sm text-ink-card-muted">{{ td('common.loading3') }}</p>

            <!-- The configured-restriction card. NOT an error: the company
                 chose counts_only (or has never configured the feature, whose
                 default is the same), so the leader is shown the counts they
                 ARE entitled to plus one line saying why the names are not
                 here. See the openMember() comment. -->
            <div
              v-else-if="sheetRestricted"
              class="flex items-start gap-3 py-4 px-4 rounded-xl bg-surface-chip"
            >
              <Icon name="shield_check" :size="20" class="shrink-0 text-ink-chip mt-0.5" />
              <!--
                THE OLD COPY SENT THE READER NOWHERE, and was reported as a
                bug on 2026-08-21: "ผมคลิกดูรายละเอียดลูกทีมทำไม ถึง Alert
                แบบนี้ทั้งที่ User นี้เป็นหัวหน้าทีม".

                Nothing is broken. The company's client_visibility_level is
                `counts_only` — which is also what an unconfigured tenant
                resolves to, because TeamVisibilityLevel::default() fails
                closed by ADR-024 §5. But the sentence read as though being a
                team leader ought to have been enough, and then said "contact
                your company's admin" to a person who is very often that
                admin, or the platform owner.

                It now says the two things that were missing: this is a
                SETTING rather than a permission you failed to earn, and here
                is the name of the screen that holds it. "การมองเห็นข้อมูลทีม"
                is the literal menu label in the admin console
                (frontend-admin router, navLabel on /team-visibility-settings)
                — naming it is what turns "ask someone" into something the
                reader can act on.

                Deliberately NOT a link, and the raw level is deliberately not
                printed: this app has no route into the admin console, and an
                agent who genuinely cannot change the setting must not be
                handed a door that 403s.
              -->
              <p class="text-xs text-ink-chip leading-relaxed">
                {{ td('team.visibility_prefix') }}<strong class="font-bold">{{ td('team.count_and_status') }}</strong>ของลูกค้าลูกทีม
                ไม่แสดงรายชื่อ — ไม่ใช่ระบบผิดพลาด และไม่เกี่ยวกับสิทธิ์หัวหน้าทีมของคุณ
                <br />
                {{ td('team.visibility_admin_note') }}
              </p>
            </div>

            <p v-else-if="sheetError" class="py-6 text-center text-sm text-ink-danger">{{ sheetError }}</p>

            <p v-else-if="!sheetClients.length" class="py-6 text-center text-sm text-ink-card-muted">
              {{ td('team.member_no_clients') }}
            </p>

            <template v-else>
              <p class="text-[11px] font-bold uppercase tracking-wide text-ink-card-subtle pb-2">
                ลูกค้า {{ formatCount(sheetTotal) }} ราย
              </p>
              <ul class="divide-y divide-line-card-subtle">
                <li
                  v-for="client in sheetClients"
                  :key="client.id"
                  class="py-3 flex items-center gap-3 min-h-[44px]"
                >
                  <Icon name="contact" :size="16" class="shrink-0 text-ink-card-subtle" />
                  <span class="flex-1 min-w-0 text-sm font-bold text-ink-card truncate">
                    {{ client.name ?? '—' }}
                  </span>
                  <span
                    v-if="clientStageLabel(client)"
                    class="shrink-0 text-[11px] font-bold px-2 py-0.5 rounded-full bg-surface-chip text-ink-chip"
                  >
                    {{ clientStageLabel(client) }}
                  </span>
                </li>
              </ul>
            </template>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── TASK-116: create a recruit link (bottom sheet) ─────────────── -->
    <Teleport to="body">
      <Transition name="sheet-fade">
        <div v-if="showCreateSheet" class="fixed inset-0 z-[60] bg-slate-900/40" @click="showCreateSheet = false"></div>
      </Transition>

      <Transition name="sheet-slide">
        <div
          v-if="showCreateSheet"
          class="fixed inset-x-0 bottom-0 z-[61] mx-auto w-full max-w-md rounded-t-3xl bg-surface-card shadow-[0_-8px_24px_rgba(0,0,0,0.18)] pb-[env(safe-area-inset-bottom)]"
          role="dialog"
          aria-modal="true"
          :aria-label="td('team.create_invite')"
        >
          <div class="flex justify-center pt-3 pb-1">
            <div class="h-1 w-10 rounded-full bg-surface-chip"></div>
          </div>

          <div class="flex items-start justify-between gap-2 px-5 py-2">
            <div class="min-w-0">
              <h2 class="text-base font-bold text-ink-card">{{ td('team.create_invite') }}</h2>
              <p class="text-xs text-ink-card-muted mt-0.5">
                {{ td('team.link_help') }}
              </p>
            </div>
            <button
              type="button"
              class="shrink-0 w-11 h-11 -mr-2 flex items-center justify-center rounded-full text-ink-card-subtle active:bg-surface-chip"
              :aria-label="td('common.close2')"
              @click="showCreateSheet = false"
            >
              <Icon name="close" :size="18" />
            </button>
          </div>

          <form class="max-h-[60vh] overflow-y-auto px-5 pb-5 space-y-4" @submit.prevent="submitCreateLink">
            <div
              v-if="createError"
              class="flex items-start gap-2 rounded-xl bg-surface-danger border border-line-card px-3 py-2.5 text-sm text-ink-danger"
            >
              <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
              <span>{{ createError }}</span>
            </div>

            <!-- ADR-025 §3: all three optional, and "blank = unlimited" is
                 said in plain words next to each field rather than left for
                 the leader to infer from an empty box. -->
            <div>
              <label for="link_label" class="block text-xs font-bold text-ink-card-muted mb-1.5">{{ td('links.label') }}</label>
              <input
                id="link_label"
                v-model="createForm.label"
                type="text"
                maxlength="255"
                class="w-full min-h-[44px] px-3 py-2.5 rounded-xl border border-line-input bg-surface-input text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
                :placeholder="td('team.label_ph')"
              />
              <p class="text-[11px] text-ink-card-subtle mt-1">{{ td('links.label_help') }}</p>
            </div>

            <div>
              <label class="block text-xs font-bold text-ink-card-muted mb-1.5">{{ td('links.expiry') }}</label>
              <BuddhistDateInput v-model="createForm.expiresOn" />
              <p class="text-[11px] text-ink-card-subtle mt-1">{{ td('links.expiry_help') }}</p>
            </div>

            <div>
              <label for="link_max_uses" class="block text-xs font-bold text-ink-card-muted mb-1.5">{{ td('links.max_uses') }}</label>
              <input
                id="link_max_uses"
                v-model="createForm.maxUses"
                type="number"
                min="1"
                inputmode="numeric"
                class="w-full min-h-[44px] px-3 py-2.5 rounded-xl border border-line-input bg-surface-input text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
                :placeholder="td('common.eg_10')"
              />
              <p class="text-[11px] text-ink-card-subtle mt-1">{{ td('links.max_uses_help') }}</p>
            </div>

            <button
              type="submit"
              :disabled="creating"
              class="w-full min-h-[44px] rounded-xl bg-brand-600 text-ink-primary text-sm font-bold shadow-sm active:scale-95 transition disabled:opacity-60 disabled:pointer-events-none"
            >
              {{ creating ? 'กำลังสร้าง…' : 'สร้างลิงก์' }}
            </button>
          </form>
        </div>
      </Transition>
    </Teleport>

    <!-- Same share sheet product sharing and order payment links use — QR,
         copy, LINE, email, native share. It never talks to the API; it is
         handed a plain https:// URL. -->
    <!-- TASK-212 — same broadcast reasoning as the product-share sheet:
         a recruit link has no single intended reader, so the agent types
         the recipient. -->
    <ShareLinkModal
      v-model:show="showShare"
      :url="shareUrl"
      :heading="shareHeading"
      email-type="agent_invite"
      :email-target-id="shareLinkId"
    />

    <!--
      ── THE RECRUIT SHEET ────────────────────────────────────────────────
      Read the applicant, then admit them. Same bottom-sheet shape as the
      member sheet above so the screen has one way of showing a person, not
      two.

      WHAT IS DELIBERATELY NOT IN HERE: email, phone, national ID. The
      backend does not send them — PendingRecruitResource holds ADR-024 §3's
      line ("a team leader is not entitled to a teammate's PII") and its
      docblock says widening it is a human decision, not a field to quietly
      add. So this sheet shows everything a leader IS entitled to and no
      more; if that turns out to be too thin in practice, the answer is that
      decision, not a change here.
    -->
    <Transition name="sheet-fade">
      <div v-if="approveTarget" class="fixed inset-0 z-[60] bg-slate-900/40" @click="closeRecruit"></div>
    </Transition>

    <Transition name="sheet-slide">
      <div
        v-if="approveTarget"
        class="fixed inset-x-0 bottom-0 z-[61] mx-auto w-full max-w-md rounded-t-3xl bg-surface-card shadow-[0_-8px_24px_rgba(0,0,0,0.18)] pb-[env(safe-area-inset-bottom)]"
        role="dialog"
        aria-modal="true"
        :aria-label="approveTarget.name"
      >
        <div class="flex justify-center pt-3 pb-1">
          <div class="h-1 w-10 rounded-full bg-surface-chip"></div>
        </div>

        <div class="flex items-start gap-3 px-5 py-2">
          <img
            v-if="approveTarget.avatar_url"
            :src="approveTarget.avatar_url"
            alt=""
            class="w-11 h-11 rounded-full object-cover border border-line-card shrink-0"
          />
          <span
            v-else
            class="w-11 h-11 rounded-full bg-brand-100 text-brand-700 text-sm font-bold flex items-center justify-center shrink-0"
          >
            {{ initials(approveTarget.name) }}
          </span>

          <div class="min-w-0 flex-1">
            <h2 class="text-base font-bold text-ink-card truncate">{{ approveTarget.name }}</h2>
            <p class="text-xs text-ink-card-muted mt-0.5">{{ td('team.pending_approval') }}</p>
          </div>

          <button
            type="button"
            class="shrink-0 w-11 h-11 -mr-2 flex items-center justify-center rounded-full text-ink-card-subtle active:bg-surface-chip"
            :aria-label="td('common.close2')"
            @click="closeRecruit"
          >
            <Icon name="close" :size="18" />
          </button>
        </div>

        <div class="max-h-[55vh] overflow-y-auto px-5 pb-4 pt-2 space-y-3">
          <div>
            <p class="text-[11px] font-bold text-ink-card-subtle">{{ td('team.applied_on') }}</p>
            <p class="text-sm text-ink-card">{{ formatDateTime(approveTarget.registered_at) }}</p>
          </div>

          <div>
            <p class="text-[11px] font-bold text-ink-card-subtle">{{ td('team.via_link') }}</p>
            <!-- invite_link is whenLoaded on the backend, so absent, null and
                 "present with no label" are three different states and none
                 may render as a blank line. -->
            <p class="text-sm text-ink-card">
              {{ approveTarget.invite_link?.label || 'ลิงก์ชวนเข้าทีมของคุณ (ไม่ได้ตั้งชื่อลิงก์)' }}
            </p>
          </div>

          <!-- The consequence, in the place the decision is made. This used
               to be the confirm dialog's whole body. -->
          <div class="p-3 rounded-xl bg-surface-chip">
            <p class="text-xs text-ink-chip leading-relaxed">
              {{ td('team.approval_will') }} <strong class="font-bold">{{ approveTarget.name }}</strong>
              {{ td('team.approval_effect') }}
            </p>
            <p v-if="!approveTarget.email_verified" class="text-xs text-ink-warning leading-relaxed mt-2">
              {{ td('team.approval_unverified') }}
            </p>
          </div>
        </div>

        <div class="px-5 pb-5 pt-1">
          <button
            type="button"
            class="w-full min-h-[48px] rounded-xl bg-brand-600 text-ink-primary text-sm font-bold active:scale-95 transition disabled:opacity-50"
            :disabled="approving"
            @click="confirmApprove"
          >
            {{ approving ? 'กำลังอนุมัติ…' : 'อนุมัติเข้าทีม' }}
          </button>
        </div>
      </div>
    </Transition>

    <ConfirmDialog
      v-model:show="showRevokeConfirm"
      :title="td('link.confirm_revoke_q')"
      :body="td('team.revoke_body')"
      variant="danger"
      :busy="revoking"
      @confirm="confirmRevoke"
    />

  </main>
</template>

<style scoped>
/* Matches FilterSheet.vue so every bottom sheet in the app moves the same
   way; duplicated rather than extracted because this sheet is screen-
   specific and FilterSheet is a single-select filter, not a container. */
.sheet-fade-enter-active,
.sheet-fade-leave-active { transition: opacity 0.2s ease; }
.sheet-fade-enter-from,
.sheet-fade-leave-to { opacity: 0; }

.sheet-slide-enter-active,
.sheet-slide-leave-active { transition: transform 0.25s cubic-bezier(0.32, 0.72, 0, 1); }
.sheet-slide-enter-from,
.sheet-slide-leave-to { transform: translateY(100%); }

@media (prefers-reduced-motion: reduce) {
  .sheet-slide-enter-active,
  .sheet-slide-leave-active { transition-duration: 0.01ms; }
}
</style>
