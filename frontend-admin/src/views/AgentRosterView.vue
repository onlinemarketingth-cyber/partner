<script setup lang="ts">
/**
 * AgentRosterView — "รายชื่อตัวแทน" sub-page of "จัดการตัวแทน" (TASK-204).
 *
 * Split out of AgentManagementView.vue, which used to hold 5 tabs behind one
 * `activeTab` ref sharing a single roster fetch. This page owns the two that
 * ag-lead ruled should MERGE rather than become 2 separate routes — "ใช้งาน
 * อยู่" and "ปิดใช้งาน" are the SAME roster (one `loadAgents()` call), filtered
 * client-side by `rosterFilter` exactly like the old page's `filteredAgents`
 * computed already did. A second fetch just to show the same data filtered
 * the other way would be wasteful and was explicitly rejected.
 *
 * Everything else that lived only inside the roster's per-row "แก้ไข" flow
 * travelled here too: the create-agent form, <AgentEditModal> (and therefore
 * the "grant cert without exam" panel, which only ever rendered inside that
 * modal), and the `?q=`/`?edit=` deep-link handling (TASK-126/128 — kept as a
 * capability even though nothing currently links to it, per its own original
 * comment).
 *
 * `certTiers`/`certifications` also live here and only here: their one
 * consumer, the modal's "grant cert without exam" panel (section 5), only
 * ever opens from this page.
 *
 * `inviteLinks` is loaded HERE TOO (a second copy of the same
 * /agent-invite-links fetch AgentInviteLinksView.vue also makes) — a
 * deliberate judgment call, not an oversight. <AgentEditModal> renders a
 * "ลิงก์ชวนเข้าทีมที่สร้างไว้: N ลิงก์" line + a "ดูในแท็บ ลิงก์ชวนทีม" shortcut
 * whenever it is HANDED a company-wide link list at all (`v-if="inviteLinks"`
 * — see that component's own props doc: ABSENT means "this host has no
 * idea" and the line hides). Leaving it unset here would silently drop that
 * panel from the modal. This is a different endpoint from /users
 * (roster/active/inactive), so it is not the "fetch the same list twice"
 * case ag-lead ruled out above.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import SuccessDialog from '@/design-system/components/SuccessDialog.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import RowActions, { type RowAction, type RowPrimaryAction } from '@/design-system/components/RowActions.vue'
import AgentEditModal from './AgentEditModal.vue'
import {
  type AgentItem,
  type IdDocumentTypeChoice,
  ID_DOCUMENT_TYPE_OPTIONS,
  fetchAllPages,
  idNumberInputMode,
  idNumberMaxLength,
  idNumberPlaceholder,
  normalizeIdNumber,
} from './agentEdit'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

interface CompanyOption {
  id: number
  name: string
}
// TASK-058/061 — reused for the "grant cert without exam" panel inside
// <AgentEditModal>.
interface CertTierOption {
  id: number
  key: string
  name: string
}
interface UserCertificationItem {
  id: number
  user_id: number
  cert_tier: CertTierOption | null
}
/** Only what <AgentEditModal>'s `inviteLinks` prop needs — see file docblock. */
interface AgentInviteLinkRef {
  agent_id: number
}

const auth = useAuthStore()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()
const route = useRoute()
const router = useRouter()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const agents = ref<AgentItem[]>([])
const companies = ref<CompanyOption[]>([])
const certTiers = ref<CertTierOption[]>([])
const certifications = ref<UserCertificationItem[]>([])

// TASK-060 — search (name/phone/email partial; national_id exact via
// blind-index hash), same query-param contract as ClientController's
// /clients search (TASK-049) — see UserController::index.
const q = ref('')
const nationalIdSearch = ref('')
const searching = ref(false)

// TASK-060 — mirrors ClientManagementView's buildClientsPath(): empty
// search params are simply omitted, so an empty search box still loads
// the full (unfiltered) roster.
function buildUsersPath(): string {
  const params = new URLSearchParams()
  params.set('include_inactive', '1')
  /*
   * 2026-09-08 — the roster draws row actions now (ลบผู้สมัคร / กู้คืน), so it
   * has to ask the server what this admin may do to each row. Without this the
   * only honest options are hiding the buttons or rendering them and letting
   * the 403 be the answer, which TASK-245 already ruled out.
   */
  params.set('with_permissions', '1')
  /*
   * 2026-09-18 — "has this person ever done anything?", per row.
   *
   * It decides whether ลบสมาชิก is offered at all and, when it is not, what
   * the disabled button says is in the way. Computed server-side for the
   * same reason `with_permissions` is: the answer spans seven relations and
   * a screen that re-derived it would be guessing about somebody's money.
   */
  params.set('with_activity', '1')
  if (q.value.trim()) params.set('q', q.value.trim())
  if (nationalIdSearch.value.trim()) params.set('national_id', nationalIdSearch.value.trim())
  return `/users?${params.toString()}`
}

async function loadAgents() {
  loading.value = true
  errorMessage.value = ''
  try {
    const requests: Promise<unknown>[] = [
      fetchAllPages<AgentItem>(buildUsersPath()),
      api.get<{ data: CertTierOption[] }>('/cert-tiers'),
      api.get<{ data: UserCertificationItem[] }>('/user-certifications'),
    ]
    // TASK-209 P4 — the company list for the create form comes from the
    // global store now (one fetch for the whole app, idempotent), not a
    // second /companies call per screen.
    if (isSuperAdmin.value) requests.push(activeCompany.loadCompanies())
    const [res, tiersRes, certsRes] = await Promise.all(requests)
    agents.value = res as AgentItem[]
    certTiers.value = (tiersRes as { data: CertTierOption[] }).data
    certifications.value = (certsRes as { data: UserCertificationItem[] }).data
    companies.value = activeCompany.companies
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

async function search() {
  searching.value = true
  try {
    await loadAgents()
  } finally {
    searching.value = false
  }
}
function clearSearch() {
  q.value = ''
  nationalIdSearch.value = ''
  search()
}

// ── ใช้งานอยู่ / ปิดใช้งาน — merged into one filter (ag-lead ruling, TASK-204) ──
type RosterFilter = 'active' | 'inactive'
const rosterFilter = ref<RosterFilter>('active')
const filterTabs: Array<{ id: RosterFilter; label: string }> = [
  { id: 'active', label: 'ใช้งานอยู่' },
  { id: 'inactive', label: 'ปิดใช้งาน' },
]
const filteredAgents = computed(() => agents.value.filter((a) => (rosterFilter.value === 'active' ? a.is_active : !a.is_active)))
const activeCount = computed(() => agents.value.filter((a) => a.is_active).length)
const inactiveCount = computed(() => agents.value.filter((a) => !a.is_active).length)

function registeredViaLabel(via?: AgentItem['registered_via']): string {
  const labels: Record<string, string> = { email: 'อีเมล', facebook: 'Facebook', line: 'LINE', google: 'Google' }
  return via ? (labels[via] ?? '-') : '-'
}

// ── Create form ──
// Backend expects first_name/last_name (not a single `name`) — see
// StoreUserRequest.
const showCreateForm = ref(false)
const createForm = ref({
  first_name: '',
  last_name: '',
  email: '',
  password: '',
  role: 'agent' as 'agent' | 'company_admin',
  // TASK-123 — NOT defaulted to 'thai_national_id' the way the public
  // register form is. Here an admin is typing on someone else's behalf.
  id_document_type: '' as IdDocumentTypeChoice,
  national_id: '',
})
const creating = ref(false)
const createNeedsIdDocumentType = computed(
  () => createForm.value.national_id.trim() !== '' && createForm.value.id_document_type === '',
)
// UserService::create() reads $data['company_id'] directly when the actor
// is Super Admin — omitting it isn't just a 422, it's an undefined-array-key
// error server-side.
const createCompanyId = ref<number | null>(null)
async function submitCreate() {
  if (isSuperAdmin.value && !createCompanyId.value) {
    errorMessage.value = 'กรุณาเลือกบริษัทก่อนบันทึก'
    return
  }
  if (createNeedsIdDocumentType.value) {
    errorMessage.value = 'กรุณาเลือกประเภทเอกสาร (บัตรประชาชน หรือ หนังสือเดินทาง) เมื่อกรอกเลขที่เอกสาร'
    return
  }
  creating.value = true
  errorMessage.value = ''
  const documentNumber = normalizeIdNumber(createForm.value.id_document_type, createForm.value.national_id)
  try {
    await api.post('/users', {
      first_name: createForm.value.first_name,
      last_name: createForm.value.last_name,
      email: createForm.value.email,
      password: createForm.value.password,
      role: createForm.value.role,
      ...(documentNumber
        ? { id_document_type: createForm.value.id_document_type, national_id: documentNumber }
        : {}),
      ...(isSuperAdmin.value ? { company_id: createCompanyId.value } : {}),
    })
    createForm.value = {
      first_name: '',
      last_name: '',
      email: '',
      password: '',
      role: 'agent',
      id_document_type: '',
      national_id: '',
    }
    showCreateForm.value = false
    await loadAgents()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { errors?: Record<string, string[]> }
      errorMessage.value =
        body.errors?.national_id?.[0] ?? body.errors?.id_document_type?.[0] ?? 'สร้างไม่สำเร็จ (422)'
    } else {
      errorMessage.value = e instanceof ApiError ? `สร้างไม่สำเร็จ (${e.status})` : 'สร้างไม่สำเร็จ'
    }
  } finally {
    creating.value = false
  }
}

// ── Recruit links — loaded here too, only to feed <AgentEditModal>'s
// `inviteLinks` prop (see file docblock). No revoke/list UI on this page. ──
const inviteLinks = ref<AgentInviteLinkRef[]>([])
async function loadInviteLinks() {
  try {
    inviteLinks.value = await fetchAllPages<AgentInviteLinkRef>('/agent-invite-links')
  } catch {
    /* the modal's link-count line simply stays hidden — see its own v-if */
  }
}

/** <AgentEditModal>'s "ดูในแท็บ ลิงก์ชวนทีม" shortcut — now a real navigation
 *  since the links list lives on its own route (AgentInviteLinksView.vue). */
function showLinksForAgent(agentId: number) {
  // The links page is one page with three tabs now; this jump aims at the
  // team one. `agent` still filters it — AgentInviteLinksView reads the same
  // query it always did, from the same route object.
  router.push({ name: 'links-hub', query: { tab: 'team', agent: String(agentId) } })
}

// ═══ <AgentEditModal> (TASK-129) ═══
const editingAgentId = ref<number | null>(null)

// TASK-210 (human, 2026-08-19: "กดบันทึก หากบันทึกสำเร็จให้ขึ้นปิดหน้าจอ modal
// นี้ และขึ้น modal ใหม่ว่าบันทึกสำเร็จ"). The confirmation is raised HERE and
// not inside the edit modal because the edit modal closes itself on success —
// anything it rendered goes with it.
const savedMessage = ref('')
const showSavedDialog = ref(false)

async function onAgentEditorSaved(payload: { leaderChanged: boolean; successMessage?: string }) {
  if (payload.successMessage) {
    savedMessage.value = payload.successMessage
    showSavedDialog.value = true
  }
  await loadAgents()
  if (payload.leaderChanged) await loadInviteLinks()
}

/*
 * 2026-09-08 (human: "อยากทำ soft delete ในการลบผู้สมัคร ที่ยังไม่ยืนยัน
 * ด้วยสิทธิ์ Super Admin และ Admin Company").
 *
 * The soft delete already existed — DELETE /users/{id} has moved `deleted_at`,
 * revoked tokens and written an audit row since TASK-183. What did not exist
 * was a way to reach it from the screen the junk sign-ups are actually ON: the
 * roster row offered "แก้ไข" and nothing else, and the deactivate control sat
 * five sections down inside the edit modal, among controls written for a
 * trading agent.
 *
 * So this is the SAME endpoint, offered in a second place, under a second name
 * — and only over rows where that name is true. `is_unconfirmed_applicant` is
 * the server's answer to "did this sign-up ever complete"; it is never
 * reassembled here from approval status and a verification flag, because a
 * mistake in that arithmetic puts a delete button over a working agent.
 *
 * Both halves of the promise are on this screen: the row goes to the
 * "ปิดใช้งาน" tab wearing a badge that says what it was, with กู้คืน beside
 * it. A soft delete the screen gives no way to undo is a hard delete with
 * better paperwork.
 */
const pendingRemoveMember = ref<AgentItem | null>(null)
const rowActionBusy = ref(false)
/** Which row is mid-request, so its buttons can say so and refuse a second click. */
const decidingId = ref<number | null>(null)

/*
 * 2026-09-08 (human: "หน้ารายชื่อตัวแทน ผมจะอนุมัติ ไม่อนุมัติ หรือ soft delete
 * ไม่มี UI ให้ใช้ในหน้านี้เลย").
 *
 * All three decisions existed; none of them was reachable from the list where
 * the people are. Approve and reject lived on a separate queue screen, and the
 * off-switch was five sections down inside the edit modal. So an admin looking
 * at "รออนุมัติ (สมัครผ่าน อีเมล)" on a roster row had to leave the page to act
 * on the very thing the row was telling them about.
 *
 * Same endpoints, same guards, offered where the information is.
 */
const rejectingId = ref<number | null>(null)
const rejectReason = ref('')

/** The server's own sentence when it wrote one; the code only as a fallback. */
function decisionError(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback

  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}

function isPendingApplicant(a: AgentItem): boolean {
  return a.is_active && a.agent_approval_status === 'pending'
}

function canApprove(a: AgentItem): boolean {
  return isPendingApplicant(a) && a.permissions?.approve_registration === true
}

function canReject(a: AgentItem): boolean {
  return isPendingApplicant(a) && a.permissions?.reject_registration === true
}

/*
 * The guard the approvals queue was missing until this morning, applied here
 * from the start: the row does not change until the reload lands, so the
 * natural response to "nothing happened yet" is a second click — and the
 * server refuses a decision made twice, which reads as the first one having
 * failed.
 */
async function decide(a: AgentItem, run: () => Promise<void>, fallback: string, done: string): Promise<void> {
  if (decidingId.value !== null) return
  decidingId.value = a.id
  errorMessage.value = ''
  try {
    await run()
    await loadAgents()
    savedMessage.value = done
    showSavedDialog.value = true
  } catch (e) {
    errorMessage.value = decisionError(e, fallback)
    // A refusal here almost always means the decision was already made, so the
    // row on screen is the stale half of the contradiction.
    await loadAgents()
  } finally {
    decidingId.value = null
  }
}

async function approveApplicant(a: AgentItem): Promise<void> {
  await decide(
    a,
    () => api.put(`/agent-approvals/${a.id}/approve`),
    'อนุมัติไม่สำเร็จ',
    `อนุมัติ ${a.name} แล้ว — เข้าใช้งานได้เมื่อยืนยันอีเมลเรียบร้อย`,
  )
}

async function submitRejectApplicant(a: AgentItem): Promise<void> {
  const reason = rejectReason.value.trim()
  await decide(
    a,
    () => api.put(`/agent-approvals/${a.id}/reject`, { reason: reason || undefined }),
    'ไม่อนุมัติไม่สำเร็จ',
    `บันทึกว่าไม่อนุมัติ ${a.name} แล้ว`,
  )
  rejectingId.value = null
  rejectReason.value = ''
}

/** Only where the server would allow it, and only for an unfinished sign-up. */
/*
 * 2026-09-18 — was `canRemoveApplicant`, and only ever true for a sign-up
 * that never completed (human: "หากไม่มีกิจกรรม การซื้อขายอะไร ให้สามารถลบ
 * รายชื่อสมาชิก แบบ Soft Delete ได้").
 *
 * The button is now OFFERED on every row this admin may remove, and
 * DISABLED with a reason on the rows that have history — the owner's answer
 * on what a blocked row should look like ("แสดงแต่กดไม่ได้ + บอกเหตุผล").
 * Hiding it was the other option and it is the worse one: an admin who
 * cannot see the control concludes the feature does not exist, where one
 * who sees it greyed out with "มีลูกค้า 3 คน" has learned something true
 * about the account.
 *
 * `removal_blockers === undefined` means the server was not asked, which is
 * not the same as "nothing is in the way" — so the button stays hidden
 * rather than becoming enabled by an omission.
 */
function canRemoveMember(a: AgentItem): boolean {
  return a.is_active && a.permissions?.deactivate === true && a.removal_blockers !== undefined
}

/** Empty = removable. Undefined (never asked) is deliberately NOT empty. */
function removalBlockers(a: AgentItem): { key: string; count: number }[] {
  return a.removal_blockers ?? []
}

function isRemovable(a: AgentItem): boolean {
  return canRemoveMember(a) && removalBlockers(a).length === 0
}

/**
 * The Thai wording for each blocker key. Lives here, not in the API, so it
 * sits beside every other label on this screen it has to agree with — an
 * endpoint that shipped copy would make the two drift the first time
 * somebody reworded one of them.
 */
function removalBlockerLabel(blocker: { key: string; count: number }): string {
  const labels: Record<string, string> = {
    clients: `ลูกค้าที่แนะนำ ${blocker.count} ราย`,
    referrals: `ดีล/ใบแนะนำ ${blocker.count} รายการ`,
    commission: `ค่าแนะนำ ${blocker.count} รายการ`,
    downline: `ลูกทีม ${blocker.count} คน`,
    learning: `ใบรับรอง/บทเรียนที่เรียนจบ ${blocker.count} รายการ`,
    rewards: `XP และเหรียญรางวัล ${blocker.count} รายการ`,
    links: `ลิงก์ชวน/ลิงก์แนะนำ ${blocker.count} ลิงก์`,
  }

  return labels[blocker.key] ?? `ข้อมูลที่ผูกอยู่ ${blocker.count} รายการ`
}

/** One sentence for the disabled menu item and the note under the row. */
function removalBlockedReason(a: AgentItem): string {
  const blockers = removalBlockers(a)
  if (blockers.length === 0) return ''

  return `ลบไม่ได้ เพราะมี${blockers.map(removalBlockerLabel).join(' · ')} — ใช้ปิดใช้งานแทน`
}

/*
 * 2026-09-18 — the row's actions, as data rather than as five buttons.
 *
 * THE PROMINENT ONE IS THE DECISION THE ROW IS ALREADY ASKING FOR. A row
 * that says "รออนุมัติ" is asking to be approved, so อนุมัติ is the filled
 * button; a row in the ปิดใช้งาน tab is asking to be restored. Every other
 * row has no such question and gets no filled button at all — which is the
 * rule RowActions exists to hold (see its docblock).
 *
 * ไม่อนุมัติ is NOT the prominent one even though it appears on the same
 * rows: rejection writes a permanent negative record the registrant is
 * shown by name, and that is not the thing to put under a hurried cursor.
 */
function rowPrimary(a: AgentItem): RowPrimaryAction | null {
  if (canApprove(a)) {
    return {
      label: 'อนุมัติ',
      icon: 'check',
      test: 'approve-applicant',
      tone: 'positive',
      busy: decidingId.value === a.id,
      busyLabel: 'กำลังอนุมัติ…',
      disabled: decidingId.value !== null,
      onSelect: () => approveApplicant(a),
    }
  }

  if (canRestoreApplicant(a)) {
    return {
      label: 'กู้คืน',
      icon: 'refresh',
      test: 'restore-applicant',
      disabled: rowActionBusy.value,
      onSelect: () => restoreApplicant(a),
    }
  }

  return null
}

/**
 * Everything else, in the order an admin thinks about it: the everyday edit
 * first, the heavier decisions after, and anything destructive last —
 * RowActions sinks those below a separator whatever order they arrive in.
 */
function rowMenu(a: AgentItem): RowAction[] {
  const items: RowAction[] = [
    { label: 'แก้ไขข้อมูล', icon: 'pencil', test: 'edit-agent', onSelect: () => { editingAgentId.value = a.id } },
  ]

  if (canReject(a)) {
    items.push({
      label: 'ไม่อนุมัติ',
      icon: 'x',
      test: 'reject-applicant',
      disabled: decidingId.value !== null,
      onSelect: () => { rejectingId.value = rejectingId.value === a.id ? null : a.id; rejectReason.value = '' },
    })
  }

  if (canRemoveMember(a)) {
    items.push({
      label: a.is_unconfirmed_applicant ? 'ลบผู้สมัคร' : 'ลบสมาชิก',
      icon: 'trash',
      test: 'remove-member',
      destructive: true,
      disabled: rowActionBusy.value || !isRemovable(a),
      disabledReason: removalBlockedReason(a),
      onSelect: () => { pendingRemoveMember.value = a },
    })
  }

  return items
}

/** The mirror. Offered on the same rows, so the undo is where the delete was. */
function canRestoreApplicant(a: AgentItem): boolean {
  // 2026-09-18 — broadened with the delete above: anything this screen can
  // remove, it has to be able to put back. Leaving กู้คืน applicant-only
  // would have made a removed member unrecoverable from the screen that
  // removed them.
  return !a.is_active && a.permissions?.restore === true
}

async function confirmRemoveMember(): Promise<void> {
  const target = pendingRemoveMember.value
  if (!target) return
  rowActionBusy.value = true
  errorMessage.value = ''
  try {
    // `release_email` says this was a REMOVAL, not the switch-off that the
    // edit modal's ปิดใช้งาน performs against the same endpoint. The server
    // re-checks that the account is untouched before honouring it, so this
    // flag cannot widen anything on its own — see UserService::deactivate.
    await api.delete(`/users/${target.id}`, { release_email: true })
    pendingRemoveMember.value = null
    await loadAgents()
    // The email release is decided by the SERVER at the moment of deletion
    // (UserService::deactivate), from the same activity check this screen
    // read a moment ago — so the sentence is written for the case the
    // button was enabled for, and never promises a release it did not see.
    savedMessage.value = `ลบ ${target.name} แล้ว — อีเมล ${target.email} ว่างให้สมัครใหม่ได้ และยังกู้คืนบัญชีนี้ได้ที่แท็บ "ปิดใช้งาน"`
    showSavedDialog.value = true
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ลบไม่สำเร็จ (${e.status})` : 'ลบไม่สำเร็จ'
    pendingRemoveMember.value = null
  } finally {
    rowActionBusy.value = false
  }
}

async function restoreApplicant(a: AgentItem): Promise<void> {
  rowActionBusy.value = true
  errorMessage.value = ''
  try {
    await api.post(`/users/${a.id}/restore`, {})
    await loadAgents()
    savedMessage.value = `กู้คืน ${a.name} แล้ว — กลับไปอยู่ในแท็บ "ใช้งานอยู่"`
    showSavedDialog.value = true
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `กู้คืนไม่สำเร็จ (${e.status})` : 'กู้คืนไม่สำเร็จ'
  } finally {
    rowActionBusy.value = false
  }
}

onMounted(() => {
  // TASK-126 — arriving from a ทีมขาย card's "แก้ไขข้อมูลตัวแทน" link, via this
  // page's OWN search parameter (`q`, forwarded to UserController::index's
  // name/phone/email LIKE). Setting it BEFORE loadAgents() means the first
  // fetch is already narrowed.
  const handoffQuery = typeof route.query.q === 'string' ? route.query.q.trim() : ''
  if (handoffQuery) {
    q.value = handoffQuery
    rosterFilter.value = 'active'
  }
  void loadAgents().then(() => {
    // TASK-128 r2 — `?edit=<agent_id>` deep link. Kept even though nothing
    // in this codebase currently links to it (confirmed via grep) — a
    // linkable "open this agent's form" URL is genuinely useful on its own
    // and costs nothing to keep. Opened AFTER the roster resolves, and
    // silently ignored when the id isn't in the loaded set (deactivated /
    // re-scoped agent, or a stale link).
    const editId = Number(route.query.edit)
    if (!Number.isFinite(editId) || editId <= 0) return
    if (agents.value.some((a) => a.id === editId)) editingAgentId.value = editId
  })
  loadInviteLinks()
})

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAgents() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="list"
      title="รายชื่อสมาชิก"
      subtitle="รายชื่อ, บทบาท, สถานะใบรับรองของสมาชิก"
      description="ไม่มีระบบส่งอีเมล — ตั้งรหัสผ่านชั่วคราวแล้วแจ้ง agent เอง (ยืนยันจากมนุษย์แล้ว)"
      accent-color="brand"
      storage-key="agent-roster"
    >
      <template #actions>
        <button
          class="btn-primary"
          @click="showCreateForm = !showCreateForm"
        >
          + เพิ่มสมาชิก
        </button>
      </template>
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in filterTabs"
            :key="t.id"
            type="button"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="rosterFilter === t.id ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="rosterFilter = t.id"
          >
            {{ t.label }} ({{ t.id === 'active' ? activeCount : inactiveCount }})
          </button>
        </div>
      </template>
    </HeroHeader>

    <CompanyScopeNotice action="จัดการรายชื่อสมาชิก" />

    <div class="bg-white/95 border border-slate-200 rounded-xl p-4 mt-4">
      <div class="flex flex-col md:flex-row gap-3">
        <div class="relative flex-1">
          <Icon name="search" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            v-model="q"
            type="text"
            placeholder="ค้นหา ชื่อ / เบอร์ / อีเมล"
            class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-brand-400"
            @keyup.enter="search"
          />
        </div>
        <div class="relative flex-1">
          <Icon name="shield" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            v-model="nationalIdSearch"
            type="text"
            maxlength="13"
            placeholder="เลขที่เอกสาร (บัตรประชาชน 13 หลัก หรือหนังสือเดินทาง)"
            class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-brand-400"
            @keyup.enter="search"
          />
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <button
            :disabled="searching"
            class="btn-primary"
            @click="search"
          >
            {{ searching ? 'กำลังค้นหา...' : 'ค้นหา' }}
          </button>
          <button
            class="btn-secondary"
            @click="clearSearch"
          >
            ล้าง
          </button>
        </div>
      </div>
      <p class="text-xs text-slate-400 mt-2">
        <Icon name="shield" :size="12" class="inline mr-0.5" />
        ค้นด้วยเลขที่เอกสาร (บัตรประชาชน หรือ หนังสือเดินทาง) ต้องกรอกครบทั้งหมดและตรงทุกตัวอักษร (exact match)
      </p>
    </div>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <form
      v-if="showCreateForm"
      class="mt-4 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3"
      @submit.prevent="submitCreate"
    >
      <div v-if="isSuperAdmin" class="col-span-2">
        <label class="text-xs font-bold text-slate-500">บริษัท (Super Admin)</label>
        <select v-model.number="createCompanyId" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
          <option :value="null">— เลือกบริษัท —</option>
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">ชื่อ</label>
        <input v-model="createForm.first_name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">นามสกุล</label>
        <input v-model="createForm.last_name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">อีเมล</label>
        <input v-model="createForm.email" type="email" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">รหัสผ่านชั่วคราว (8 ตัวขึ้นไป มีพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข)</label>
        <input v-model="createForm.password" type="text" minlength="8" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">บทบาท</label>
        <select v-model="createForm.role" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
          <option value="agent">Member</option>
          <option value="company_admin">Company Admin</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">ประเภทเอกสารยืนยันตัวตน</label>
        <select
          v-model="createForm.id_document_type"
          :required="createNeedsIdDocumentType"
          class="mt-1 w-full px-3 py-2 rounded-lg border text-sm"
          :class="createNeedsIdDocumentType ? 'border-rose-300' : 'border-slate-200'"
        >
          <option value="">— ไม่ระบุ —</option>
          <option v-for="opt in ID_DOCUMENT_TYPE_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
        <p v-if="createNeedsIdDocumentType" class="text-[11px] text-rose-600 mt-1">
          กรอกเลขที่เอกสารแล้ว ต้องเลือกประเภทเอกสารด้วย
        </p>
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">เลขที่เอกสาร (ไม่บังคับ)</label>
        <input
          v-model="createForm.national_id"
          type="text"
          :inputmode="idNumberInputMode(createForm.id_document_type)"
          :maxlength="idNumberMaxLength(createForm.id_document_type)"
          :placeholder="idNumberPlaceholder(createForm.id_document_type)"
          class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
          :class="createForm.id_document_type === 'passport' ? 'uppercase' : ''"
        />
      </div>
      <div class="col-span-2 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="showCreateForm = false">ยกเลิก</button>
        <button type="submit" :disabled="creating" class="btn-primary">
          {{ creating ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
    </form>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!filteredAgents.length" icon="users" title="ไม่มีรายชื่อในหมวดนี้" class="mt-4" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="a in filteredAgents" :key="a.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
              <Icon name="user" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 truncate">
                  {{ a.name }}
                  <span v-if="isSuperAdmin && a.company" class="text-xs font-normal text-slate-400">· {{ a.company.name }}</span>
                  <span
                    v-if="a.is_team_leader"
                    class="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-amber-50 text-amber-700 text-[11px] font-bold align-middle"
                  >
                    <Icon name="shield_check" :size="12" />
                    หัวหน้าทีม
                  </span>
                </p>
                <p class="text-xs text-slate-400 truncate">{{ a.email }}</p>
                <p v-if="a.role === 'agent'" class="text-xs mt-1" :class="a.has_passed_basic_cert ? 'text-emerald-600' : 'text-amber-600'">
                  {{ a.has_passed_basic_cert ? 'ผ่าน Basic แล้ว' : 'ยังไม่ผ่าน Basic (BR-1)' }}
                </p>
                <p v-if="a.agent_approval_status === 'pending'" class="text-xs text-amber-600 mt-1">รออนุมัติ (สมัครผ่าน {{ registeredViaLabel(a.registered_via) }})</p>
                <p v-else-if="a.agent_approval_status === 'rejected'" class="text-xs text-rose-600 mt-1">
                  ถูกปฏิเสธ<span v-if="a.approval_rejection_reason"> — {{ a.approval_rejection_reason }}</span>
                </p>
                <!-- 2026-09-08 — in the ปิดใช้งาน tab, WHY this row is there.
                     A removed junk sign-up and a switched-off trading agent
                     land in the same list wearing the same grey; without this
                     the tab reads as "agents we let go" and nobody dares
                     empty it. -->
                <p
                  v-if="!a.is_active && a.is_unconfirmed_applicant"
                  class="text-xs text-slate-400 mt-1"
                  data-test="removed-applicant-note"
                >
                  ผู้สมัครที่ถูกลบ — สมัครไว้แต่ไม่เคยยืนยัน กู้คืนได้ตลอด
                </p>
                <!-- 2026-09-18 — why the ลบ button next to this row is grey.
                     Visible text, not only a tooltip: a disabled control with
                     no explanation is the thing that gets reported as a bug,
                     and on touch there is no hover to reveal one. -->
                <p
                  v-if="a.is_active && canRemoveMember(a) && !isRemovable(a)"
                  class="text-xs text-slate-400 mt-1"
                  data-test="removal-blocked-reason"
                >
                  {{ removalBlockedReason(a) }}
                </p>
              </div>
            </div>
            <!-- 2026-09-18 — was five buttons in identical 2px navy borders
                 (human, on a screenshot: "ตอนนี้ดูยากไปหน่อยเรื่องปุ่ม เพราะ
                 สีมันไม่ชัดเจน"). One prominent action, the rest behind ⋯ —
                 see RowActions for why the colours were never the problem. -->
            <RowActions
              :primary="rowPrimary(a)"
              :items="rowMenu(a)"
              :menu-label="`ตัวเลือกสำหรับ ${a.name}`"
            />
          </div>
          <!-- 2026-09-08 — the reason box, inline rather than in a dialog: the
               text is optional but it is shown to the applicant verbatim at the
               login screen, so it is written where the person's name and email
               are still visible. Same pattern as the approvals queue. -->
          <div v-if="rejectingId === a.id" class="mt-3 pt-3 border-t border-slate-100">
            <p class="text-xs text-slate-500 mb-2">
              เขาจะเข้าใช้งานไม่ได้ และจะเห็นเหตุผลนี้ตอนพยายามเข้าสู่ระบบ — เว้นว่างได้ถ้าไม่อยากบอกเหตุผล
            </p>
            <div class="flex gap-2 items-center">
              <input
                v-model="rejectReason"
                type="text"
                maxlength="1000"
                placeholder="เหตุผล (ไม่บังคับ) เช่น ข้อมูลไม่ครบ"
                data-test="reject-reason"
                class="flex-1 px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
              />
              <button
                type="button"
                class="btn-secondary text-rose-600 hover:bg-rose-50 disabled:opacity-50"
                data-test="reject-confirm"
                :disabled="decidingId !== null"
                @click="submitRejectApplicant(a)"
              >
                ยืนยันไม่อนุมัติ
              </button>
              <button
                type="button"
                class="btn-secondary"
                @click="rejectingId = null; rejectReason = ''"
              >
                ยกเลิก
              </button>
            </div>
          </div>
        </div>
      </TransitionGroup>
    </template>

    <AgentEditModal
      :agent-id="editingAgentId"
      :roster="agents"
      :cert-tiers="certTiers"
      :certifications="certifications"
      :companies="companies"
      :invite-links="inviteLinks"
      @close="editingAgentId = null"
      @saved="onAgentEditorSaved"
      @show-links="showLinksForAgent"
    />

    <!-- TASK-210 — shown after <AgentEditModal> has closed itself. -->
    <SuccessDialog v-model:show="showSavedDialog" :body="savedMessage" />

    <!-- 2026-09-08 (human: "soft delete เองต้องแจ้งเตือนผู้ใช้ถึงผลกระทบเป็น
         ภาษาคนเข้าใจ ไม่เอาภาษาระบบ").

         So this says what HAPPENS TO THE PERSON, in the order an admin worries
         about it, and it names no column, no tab id, no status enum.

         2026-09-18 — THE THIRD LINE IS NOW THE OPPOSITE OF WHAT IT SAID, and
         that is the point. It used to warn that the address stayed reserved
         forever ("สมัครใหม่ด้วยอีเมลเดิมไม่ได้") — the thing admins
         discovered the hard way after telling somebody to just sign up again.
         The owner asked for the address to be released, so now it is, and the
         line says so.

         It is released ONLY because this button is only enabled on an account
         with no history at all; a trading agent switched off from
         แก้ไข → การจัดการบัญชี still keeps its address, because their name
         is how a human recognises them in the ledger and the audit trail.

         The last line is the honest cost of releasing it: กู้คืน can fail
         later if somebody else took the address in the meantime, and an
         admin should hear that here rather than from a refusal weeks on. -->
    <ConfirmDialog
      :show="pendingRemoveMember !== null"
      variant="danger"
      :busy="rowActionBusy"
      :title="pendingRemoveMember?.is_unconfirmed_applicant ? 'ลบผู้สมัครรายนี้?' : 'ลบสมาชิกรายนี้?'"
      :confirm-label="pendingRemoveMember?.is_unconfirmed_applicant ? 'ลบผู้สมัคร' : 'ลบสมาชิก'"
      size="md"
      :body="pendingRemoveMember
        ? `จะเกิดอะไรขึ้นกับ ${pendingRemoveMember.name}\n\n`
          + `• ชื่อจะหายไปจากรายชื่อสมาชิกและจากคิวรออนุมัติ\n`
          + `• เขาเข้าใช้งานไม่ได้ทันที และการเข้าใช้งานที่ค้างอยู่จะถูกถอนทั้งหมด\n`
          + `• อีเมล ${pendingRemoveMember.email} จะถูกปลด ใช้สมัครใหม่ได้เลย\n`
          + `• ข้อมูลไม่ได้ถูกลบถาวร กดกู้คืนได้ที่หัวข้อ “ปิดใช้งาน” ด้านบน\n`
          + `• แต่ถ้ามีคนเอาอีเมลนี้ไปสมัครก่อน จะกู้คืนบัญชีนี้ไม่ได้จนกว่าจะตั้งอีเมลใหม่ให้`
        : ''"
      @confirm="confirmRemoveMember"
      @update:show="(v) => { if (!v) pendingRemoveMember = null }"
    />
  </main>
</template>
