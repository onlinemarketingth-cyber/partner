<script setup lang="ts">
/**
 * AgentEditModal — the ONE "แก้ไขข้อมูลตัวแทน" form.
 *
 * ═══ WHERE THIS CAME FROM ═══
 * TASK-128 replaced seven inline panels on an agent row with a single modal,
 * but that modal was written INSIDE AgentManagementView, so only that page
 * could show it. TASK-129 (human, 2026-08-05: "ผมต้องการกดแก้ไข หน้านี้เปิด
 * Modal และไม่ใช้หน้าในรูปที่ 2 อีก") lifts it out unchanged so the ทีมขาย
 * card's pencil opens it IN PLACE instead of navigating to จัดการตัวแทน.
 * This is an extraction, not a redesign: every rule below travelled with the
 * code it belongs to.
 *
 * ═══ WHY THIS COMPONENT LOADS ITS OWN AGENT ═══
 * The two hosts know very different amounts about the person being edited.
 * AgentManagementView holds full UserResource rows (/users). SalesTeamView
 * holds /sales-team-overview rows — name, email, manager_id, KPIs — with NONE
 * of the fields this form edits (no first/last name, no bank details, no
 * identity document, no role). Being handed a "current agent" object would
 * therefore mean the form silently renders blanks on one of the two pages.
 * So the subject is fetched here, from GET /users/{user}, and the hosts only
 * ever pass an ID.
 *
 * Everything else the modal needs (cert tiers, certifications, the company
 * list, the roster the upline dropdown is built from) is OPTIONAL props:
 * AgentManagementView already has all of it loaded and hands it over, so
 * opening the modal there costs no extra round trips; SalesTeamView has none
 * of it, so the component fetches it for itself.
 */
import { computed, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import Icon from '@/design-system/components/Icon.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import {
  type AgentItem,
  type IdDocumentTypeChoice,
  ID_DOCUMENT_TYPE_OPTIONS,
  fetchAllPages,
  idDocumentTypeLabel,
  idNumberInputMode,
  idNumberMaxLength,
  idNumberPlaceholder,
  normalizeIdNumber,
  digitsOnly,
  sanitizeDigitsInput,
  sanitizeIdNumberInput,
} from './agentEdit'

interface CompanyOption {
  id: number
  name: string
}
// TASK-058/061 — the "grant cert without exam" panel in section 5.
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
/**
 * Only what the "ลิงก์ชวนเข้าทีมที่สร้างไว้" line needs — deliberately not the
 * full AgentInviteLink shape, so this component never grows an opinion about
 * links it neither creates nor revokes.
 */
interface AgentInviteLinkRef {
  agent_id: number
}

const props = defineProps<{
  /** Non-null = the modal is open, on this agent. */
  agentId: number | null
  /**
   * The host's own roster of UserResource rows, when it has one. Used for
   * two things: the upline dropdown's candidate list, and re-pointing the
   * modal's read-only halves after the host reloads (see the watcher below).
   * Omitted → this component fetches the roster itself.
   */
  roster?: AgentItem[] | null
  certTiers?: CertTierOption[] | null
  certifications?: UserCertificationItem[] | null
  /** Super-Admin-only "ย้ายบริษัท" picker. Omitted → fetched when needed. */
  companies?: CompanyOption[] | null
  /**
   * Company-wide recruit links, when the host tracks them. ABSENT (not empty)
   * means "this host has no idea", and the links line is hidden rather than
   * rendered as a confident "0 ลิงก์" the data does not support.
   */
  inviteLinks?: AgentInviteLinkRef[] | null
}>()

const emit = defineEmits<{
  close: []
  /**
   * Something was written. `leaderChanged` matters to a host that renders
   * recruit links: turning the flag off makes every link that agent owns stop
   * admitting recruits (RegistrationService::resolveActiveInviter), so those
   * rows need re-reading — see AgentManagementView's handler.
   *
   * `successMessage` is present ONLY on the writes that also close the modal
   * (TASK-210). Those are the ones with no other way to report themselves:
   * the modal carrying the inline "บันทึกแล้ว" line is gone by the time the
   * admin could read it. The host shows it in <SuccessDialog>. Actions that
   * leave the modal open (granting a tier) omit it on purpose — they already
   * report inline, and a dialog on top of the form they are still using would
   * be in the way.
   */
  saved: [payload: { leaderChanged: boolean; successMessage?: string }]
  /** The "ดูในแท็บ ลิงก์ชวนทีม" shortcut — only a host with such a tab acts on it. */
  'show-links': [agentId: number]
}>()

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

// ── The subject ──────────────────────────────────────────────────────
/** Non-null = the agent has resolved and the form is rendered. */
const agent = ref<AgentItem | null>(null)
const subjectLoading = ref(false)
const subjectError = ref('')

// Reference data this component fetched for itself. The computeds below
// prefer the host's copy whenever it was handed over.
const ownRoster = ref<AgentItem[]>([])
const ownCertTiers = ref<CertTierOption[]>([])
const ownCertifications = ref<UserCertificationItem[]>([])
const ownCompanies = ref<CompanyOption[]>([])

const roster = computed<AgentItem[]>(() => props.roster ?? ownRoster.value)
const certTiers = computed<CertTierOption[]>(() => props.certTiers ?? ownCertTiers.value)
const certifications = computed<UserCertificationItem[]>(() => props.certifications ?? ownCertifications.value)
const companies = computed<CompanyOption[]>(() => props.companies ?? ownCompanies.value)

/**
 * How the modal treats one sensitive value that is already on file.
 *
 *   keep    — render what the API gave us as read-only text; send NOTHING.
 *   replace — a blank input; send only what the admin actually types.
 *   clear   — send an explicit null to wipe the value.
 *
 * `keep` is the default, and this tri-state exists precisely because of it.
 * Both `national_id` and `bank_account_number` come back MASKED unless
 * UserResource decided this viewer may see them (UserPolicy::view — the
 * agent's own Company Admin, or a Super Admin). A masked identity document
 * arrives as national_id = null alongside national_id_masked =
 * "*********0708"; a masked bank number arrives IN THE SAME KEY as a real
 * one ("*****1234"), with no second field and no flag saying which of the
 * two you were given.
 *
 * Prefilling either into an editable input and letting a form PUT it back
 * would store the asterisks AS the value: the bank payout destination
 * becomes "*****1234", and national_id — encrypted at rest, with a blind
 * index re-derived on save (User::hashNationalId) — is destroyed beyond
 * recovery. So no sensitive value is ever prefilled, revealed or not.
 * Untouched means nothing is sent for that field; changing one is a
 * deliberate act ("เปลี่ยน") that starts from an empty box.
 */
type SensitiveFieldMode = 'keep' | 'replace' | 'clear'

/** The plain, non-sensitive half of the form — safe to prefill and diff. */
interface AgentEditForm {
  first_name: string
  last_name: string
  email: string
  // TASK-131 — editable as of this task; see the phone rule added to
  // UpdateUserRequest for why it was read-only before.
  phone: string
  role: 'agent' | 'company_admin'
  manager_id: number | null
  is_team_leader: boolean
  bank_name: string
  bank_account_holder_name: string
}

function blankEditForm(): AgentEditForm {
  return {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    role: 'agent',
    manager_id: null,
    is_team_leader: false,
    bank_name: '',
    bank_account_holder_name: '',
  }
}

const editForm = ref<AgentEditForm>(blankEditForm())
/**
 * The agent exactly as the API last described them. Never bound to an
 * input — buildUserPatch() diffs against this, and only differences are
 * sent, so an untouched field cannot be rewritten with its own value.
 */
const editOriginal = ref<AgentEditForm>(blankEditForm())
const editSaving = ref(false)
/** Modal-scoped, for failures that belong to no single field. */
const editFormError = ref('')
const editSavedMessage = ref('')
/** field name → the server's own message, rendered under THAT field. */
const editFieldErrors = ref<Record<string, string>>({})

const idDocMode = ref<SensitiveFieldMode>('keep')
const idDocForm = ref<{ id_document_type: IdDocumentTypeChoice; national_id: string }>({
  id_document_type: '',
  national_id: '',
})
const bankNumberMode = ref<SensitiveFieldMode>('keep')
const bankNumberInput = ref('')

/**
 * PUT /users/{user} has no `withTrashed()` binding (routes/api.php — only
 * the restore route does), so every editable field of a deactivated agent
 * would 404. The modal still opens for them, read-only, because
 * "เปิดใช้งาน" lives in section 5 and that is the one thing an admin needs
 * on this row.
 */
const editIsReadOnly = computed(() => agent.value !== null && !agent.value.is_active)

/**
 * TASK-130 §1 (human, 2026-08-08) — an Agent may not be promoted to Company
 * Admin from this form. The Company Admin option is therefore offered ONLY to
 * someone who already holds that role.
 *
 * Note it is exactly that: an offer withdrawn from the UI. UpdateUserRequest
 * still validates `role` against Rule::in(['agent','company_admin']), so a
 * hand-crafted PUT would still be honoured — deliberately left to ag-lead to
 * decide (a backend `role` restriction changes what StoreUserRequest-created
 * admins and the Super Admin tooling can do, which is outside this task).
 *
 * Driven by the SNAPSHOT, not by the live select: an existing Company Admin
 * keeps their value visible and selected. It is never silently rewritten to
 * 'agent' — that would demote someone as a side effect of opening a modal.
 */
const canBeCompanyAdmin = computed(() => editOriginal.value.role === 'company_admin')

/**
 * TASK-130 §2b (human, 2026-08-08) — a team leader has no Upline.
 *
 * A leader is the top of their own branch, so the control means nothing for
 * them, and an empty dropdown sitting there is an invitation to fill it in.
 *
 * TRANSITION DECISION — toggling leadership ON while a manager is selected
 * LEAVES the stored manager_id alone (the control disappears; nothing is
 * sent — see buildUserPatch). Not cleared, for two reasons:
 *   1. Clearing would make a toggle in the profile form perform a silent
 *      STRUCTURAL edit — detaching the agent from a team — that the admin
 *      neither typed nor confirmed. Re-parenting has its own control on
 *      ทีมขาย, where the tree redraws underneath it and the server's own
 *      422 (same-company / no-cycle, UserService::assertValidManager) has
 *      somewhere to land.
 *   2. "Leader with an upline" is a legitimate state in this system, not a
 *      contradiction to repair: ADR-025 §1 makes is_team_leader a CAPABILITY
 *      rather than a position, and SalesTeamCard already renders nested
 *      sub-leaders inside someone else's downline.
 * Reading the LIVE toggle (not the snapshot) so the control disappears the
 * moment it is switched on, which is also what makes the save guard honest.
 */
const uplineIsEditable = computed(() => !editForm.value.is_team_leader)

// ── Opening ──────────────────────────────────────────────────────────

/**
 * Resolve the person this modal is about.
 *
 * GET /users/{user} is the source of truth — the hosts hand over an id, not a
 * record, precisely so this form always edits a real UserResource (see the
 * file docblock). Two deliberate detours:
 *
 *  1. A DEACTIVATED agent 404s on that route (no withTrashed() binding — only
 *     restore has one). When the host already holds that row — AgentManagement
 *     View loads /users with include_inactive=1 — use it instead of firing a
 *     request we know cannot succeed. The modal must still open for those
 *     agents, read-only, because "เปิดใช้งาน" lives in section 5.
 *  2. If the fetch fails for any other reason but the host does hold the row,
 *     fall back to it rather than showing nothing: same UserResource shape,
 *     just a few seconds older.
 */
async function resolveSubject(id: number): Promise<AgentItem | null> {
  const known = roster.value.find((a) => a.id === id) ?? null
  if (known && !known.is_active) return known

  subjectLoading.value = true
  try {
    const res = await api.get<{ data: AgentItem }>(`/users/${id}`)
    return res.data
  } catch (e) {
    if (known) return known
    subjectError.value =
      e instanceof ApiError ? `โหลดข้อมูลตัวแทนไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลตัวแทนไม่สำเร็จ'
    return null
  } finally {
    subjectLoading.value = false
  }
}

/**
 * Fetch only what the host did NOT hand over. Failures are swallowed on
 * purpose: none of this is the subject of the form, and an upline dropdown
 * that came back empty must not stop an admin fixing a typo in an email.
 * An unloaded dropdown is harmless by construction — manager_id then never
 * differs from its snapshot, so buildUserPatch() sends no manager_id at all.
 */
async function loadReferenceData(): Promise<void> {
  const jobs: Array<Promise<unknown>> = []
  if (!props.roster) {
    // include_inactive=1 to match what AgentManagementView passes: a
    // deactivated agent is still a selectable upline there today.
    jobs.push(fetchAllPages<AgentItem>('/users?include_inactive=1').then((rows) => (ownRoster.value = rows)))
  }
  if (!props.certTiers) {
    jobs.push(api.get<{ data: CertTierOption[] }>('/cert-tiers').then((r) => (ownCertTiers.value = r.data)))
  }
  if (!props.certifications) {
    jobs.push(
      api.get<{ data: UserCertificationItem[] }>('/user-certifications').then((r) => (ownCertifications.value = r.data)),
    )
  }
  // Only Super Admin has a "ย้ายบริษัท" panel to fill, and /companies is
  // Super-Admin-only end to end (CompanyPolicy) — asking as anyone else is a
  // guaranteed 403.
  if (!props.companies && isSuperAdmin.value) {
    jobs.push(api.get<{ data: CompanyOption[] }>('/companies').then((r) => (ownCompanies.value = r.data)))
  }
  try {
    await Promise.all(jobs)
  } catch {
    /* see the docblock — reference data never blocks the form */
  }
}

function applySubject(subject: AgentItem): void {
  const snapshot: AgentEditForm = {
    first_name: subject.first_name ?? '',
    last_name: subject.last_name ?? '',
    email: subject.email,
    phone: subject.phone ?? '',
    role: subject.role,
    manager_id: subject.manager_id ?? null,
    is_team_leader: subject.is_team_leader ?? false,
    bank_name: subject.bank_name ?? '',
    bank_account_holder_name: subject.bank_account_holder_name ?? '',
  }
  editOriginal.value = { ...snapshot }
  editForm.value = { ...snapshot }

  // 'keep' only means something when there IS a value to protect; with
  // nothing on file the field opens as an ordinary empty input.
  idDocMode.value = subject.national_id_masked ? 'keep' : 'replace'
  idDocForm.value = {
    // '' for a row that never recorded a type — the selector opens unset
    // rather than pre-picking a type this record does not claim (TASK-123).
    id_document_type: subject.id_document_type ?? '',
    national_id: '',
  }
  bankNumberMode.value = subject.bank_account_number ? 'keep' : 'replace'
  bankNumberInput.value = ''
}

function resetModalState(): void {
  agent.value = null
  subjectError.value = ''
  editFormError.value = ''
  editSavedMessage.value = ''
  editFieldErrors.value = {}
  editForm.value = blankEditForm()
  editOriginal.value = blankEditForm()
  resetPasswordValue.value = ''
  resetPasswordMessage.value = ''
  resetPasswordError.value = ''
  moveCompanyTarget.value = ''
  moveCompanyError.value = ''
  grantError.value = ''
  pendingGrant.value = null
  pendingDeactivate.value = null
  targetsAll.value = []
  targetsMonthlyForm.value = blankTargetsForm()
  targetsMonthlyOriginal.value = blankTargetsForm()
  targetsYearlyForm.value = blankTargetsForm()
  targetsYearlyOriginal.value = blankTargetsForm()
}

async function openFor(id: number): Promise<void> {
  resetModalState()
  // Reference data first (and in parallel with the subject) so the upline
  // dropdown can also serve as the fallback source for a deactivated agent.
  const [subject] = await Promise.all([resolveSubject(id), loadReferenceData()])
  // The admin may have closed the modal, or opened a different agent, while
  // the requests were in flight — never paint a stale subject over that.
  if (props.agentId !== id) return
  if (!subject) return
  agent.value = subject
  applySubject(subject)
  await loadTargetsFor(subject)
}

/**
 * Reproduces the old resyncEditingAgent(): after ANY reload the host's roster
 * holds NEW objects, and this modal's read-only halves (document on file,
 * bank number on file, cert list, active state) read from `agent` — so it has
 * to be re-pointed at the fresh row or it keeps rendering pre-action data.
 * The FORM is deliberately left alone: it may hold edits the admin has not
 * saved yet.
 */
watch(
  () => props.roster,
  (rows) => {
    const id = props.agentId
    if (!rows || id === null || agent.value === null) return
    const fresh = rows.find((a) => a.id === id)
    if (fresh) agent.value = fresh
  },
)

/** The same re-point for a host that owns no roster — re-read the subject. */
async function resyncSubject(): Promise<void> {
  const id = props.agentId
  if (props.roster || id === null) return
  try {
    const res = await api.get<{ data: AgentItem }>(`/users/${id}`)
    if (props.agentId === id) agent.value = res.data
  } catch {
    /* cosmetic refresh — leave the previous record on screen */
  }
}

function closeModal(): void {
  emit('close')
}

// ── Sales/deal/client targets (TASK-053 / ADR-016 Phase 4) ───────────
// Admin sets an agent's target per metric; the agent's Home hub goal ring
// reads the MONTHLY one back via /me/home. BR-3: money is entered in baht
// but stored as integer satang (×100). BR-7: these are admin data, never
// hardcoded. Section 4 of this modal, prefilled per agent from the
// admin-read endpoint when the modal opens.
//
// ═══ TASK-130 §4+§5 — no month picker, two groups ═══
// Human, 2026-08-08: "เป้าหมายรายเดือน ไม่จำเป็นต้องแยกเป็นรายเดือน" +
// "เพิ่มเป้าหมายรายปี". The section used to open on a <input type="month">
// that an admin had to read and agree with before typing anything — a
// control whose only realistic value was "this month", standing between the
// admin and the three numbers they came to set. It is gone: the monthly
// group always writes THE CURRENT MONTH and the yearly group THE CURRENT
// YEAR, both derived here, neither editable.
//
// The two periods are distinguished by their SHAPE, which is also how the
// backend tells them apart: 'YYYY-MM' = one month, 'YYYY' = one year.
// agent_targets.period is string(7) so a 4-char year fits with no migration,
// and unique(agent_id, period, metric) keeps working — '2026' and '2026-08'
// are simply two different rows.
interface AgentTargetItem {
  id: number
  agent_id: number
  period: string
  metric: string
  target_value: number
}
interface AgentTargetsForm {
  sales_baht: string
  deals: string
  clients: string
}
function blankTargetsForm(): AgentTargetsForm {
  return { sales_baht: '', deals: '', clients: '' }
}
/** 'YYYY-MM' — the month being edited. Always now; never chosen. */
function currentMonthPeriod(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
/** 'YYYY' — a 4-character period IS the yearly target (see the block above). */
function currentYearPeriod(): string {
  return String(new Date().getFullYear())
}
const monthlyPeriod = ref(currentMonthPeriod())
const yearlyPeriod = ref(currentYearPeriod())
const targetsMonthlyForm = ref<AgentTargetsForm>(blankTargetsForm())
const targetsYearlyForm = ref<AgentTargetsForm>(blankTargetsForm())
/** Snapshots of what the server already had, for the same dirty-diff rule. */
const targetsMonthlyOriginal = ref<AgentTargetsForm>(blankTargetsForm())
const targetsYearlyOriginal = ref<AgentTargetsForm>(blankTargetsForm())
const targetsAll = ref<AgentTargetItem[]>([])
const targetsLoading = ref(false)

/** One period's three metrics, out of the flat list the endpoint returns. */
function targetsForPeriod(period: string): AgentTargetsForm {
  const rows = targetsAll.value.filter((t) => t.period === period)
  const sales = rows.find((t) => t.metric === 'sales_satang')
  const deals = rows.find((t) => t.metric === 'deals')
  const clients = rows.find((t) => t.metric === 'clients')
  return {
    // Stored satang → displayed baht. The inverse of the ×100 in
    // buildTargetJobs(), unchanged from TASK-053 (BR-3).
    sales_baht: sales ? String(sales.target_value / 100) : '',
    deals: deals ? String(deals.target_value) : '',
    clients: clients ? String(clients.target_value) : '',
  }
}

function prefillTargets(): void {
  const monthly = targetsForPeriod(monthlyPeriod.value)
  const yearly = targetsForPeriod(yearlyPeriod.value)
  targetsMonthlyForm.value = { ...monthly }
  targetsMonthlyOriginal.value = { ...monthly }
  targetsYearlyForm.value = { ...yearly }
  targetsYearlyOriginal.value = { ...yearly }
}

async function loadTargetsFor(subject: AgentItem): Promise<void> {
  // Re-derived per open rather than once at module load: an admin session
  // left open across midnight on the 1st must not keep writing last month.
  monthlyPeriod.value = currentMonthPeriod()
  yearlyPeriod.value = currentYearPeriod()
  targetsLoading.value = true
  try {
    // Every period the agent has, monthly and yearly alike — the endpoint
    // takes no period filter, and both groups prefill from this one read.
    const res = await api.get<{ data: AgentTargetItem[] }>(`/agent-targets?agent_id=${subject.id}`)
    targetsAll.value = res.data
  } catch {
    // A targets read failing must not block editing the rest of the person —
    // the section simply opens empty (same silent fallback as before).
    targetsAll.value = []
  } finally {
    targetsLoading.value = false
  }
  prefillTargets()
}

// ── Saving ───────────────────────────────────────────────────────────

/** '' → null so clearing an optional text field stores null, not "". */
function trimmedOrNull(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

/**
 * The dirty-diff: every key here is a field the admin actually changed.
 * UpdateUserRequest is `sometimes`-based throughout, so an omitted key is
 * genuinely "leave it alone" server-side — which is what makes it safe for
 * the two masked fields to send nothing at all.
 */
function buildUserPatch(): Record<string, unknown> {
  const patch: Record<string, unknown> = {}
  const form = editForm.value
  const original = editOriginal.value

  if (form.first_name.trim() !== original.first_name.trim()) patch.first_name = form.first_name.trim()
  if (form.last_name.trim() !== original.last_name.trim()) patch.last_name = form.last_name.trim()
  if (form.email.trim() !== original.email.trim()) patch.email = form.email.trim()
  // TASK-131 — nullable on the API, so an emptied field clears the number
  // rather than saving an empty string.
  if (form.phone.trim() !== original.phone.trim()) patch.phone = trimmedOrNull(form.phone)
  if (form.role !== original.role) patch.role = form.role
  // TASK-130 §2b — a team leader has no Upline control (see uplineIsEditable),
  // so they must never carry an upline CHANGE either. Without this guard a
  // manager picked before the toggle was switched on would still be sitting
  // in `form.manager_id` and would be PUT with the rest of the form — an
  // invisible structural edit the admin never confirmed. The value already on
  // file is deliberately left untouched (not nulled): see uplineIsEditable.
  if (uplineIsEditable.value && form.manager_id !== original.manager_id) patch.manager_id = form.manager_id
  if (form.is_team_leader !== original.is_team_leader) patch.is_team_leader = form.is_team_leader
  if (form.bank_name.trim() !== original.bank_name.trim()) patch.bank_name = trimmedOrNull(form.bank_name)
  if (form.bank_account_holder_name.trim() !== original.bank_account_holder_name.trim()) {
    patch.bank_account_holder_name = trimmedOrNull(form.bank_account_holder_name)
  }

  // Bank account number — never diffed against what is on screen (that may
  // be a mask, and we cannot tell). The MODE is the admin's intent.
  if (bankNumberMode.value === 'clear') {
    patch.bank_account_number = null
  } else if (bankNumberMode.value === 'replace' && bankNumberInput.value.trim() !== '') {
    patch.bank_account_number = bankNumberInput.value.trim()
  }

  // Identity document — same rule, and the two keys travel together.
  if (idDocMode.value === 'clear') {
    // Clearing the number clears the type with it: a type describing a
    // document we no longer hold is a claim about this person that nothing
    // backs (TASK-123). UpdateUserRequest accepts null for both.
    patch.national_id = null
    patch.id_document_type = null
  } else if (idDocMode.value === 'replace') {
    const documentNumber = normalizeIdNumber(idDocForm.value.id_document_type, idDocForm.value.national_id)
    if (documentNumber) {
      patch.national_id = documentNumber
      patch.id_document_type = idDocForm.value.id_document_type
    } else if (
      idDocForm.value.id_document_type !== '' &&
      idDocForm.value.id_document_type !== (agent.value?.id_document_type ?? '')
    ) {
      // Correcting the TYPE of a document already on file without retyping
      // its digits — the one legitimate reason to send the type alone.
      // `required_with:national_id` does not fire (no number is sent) and
      // User's saving() hook re-derives the blind index from the stored
      // number under the new type, which is exactly the intended repair.
      patch.id_document_type = idDocForm.value.id_document_type
    }
  }

  return patch
}

/**
 * The upserts for ONE period's three metrics.
 *
 * Same dirty-diff discipline as the user patch, for the same reason: an
 * untouched metric must not be rewritten. Blanking a field does NOT delete
 * the target — /agent-targets has no delete endpoint, and posting 0 would
 * read as a real target of zero on the agent's goal ring. A blanked field
 * is therefore left alone; the copy under the section says so.
 */
function targetJobsForPeriod(
  subject: AgentItem,
  period: string,
  form: AgentTargetsForm,
  original: AgentTargetsForm,
): Array<() => Promise<unknown>> {
  /*
   * TASK-131 QA — these are THUNKS, not promises, and that is the whole point.
   *
   * They used to be `api.post(...)` calls pushed straight into the array,
   * which means every target request FIRED THE MOMENT THE ARRAY WAS BUILT —
   * before submitEdit() had even sent the user PUT. Verified in the browser:
   * a save whose PUT /users/{id} came back 500 had already written all six
   * agent_targets rows, so the targets moved and the person did not. Whoever
   * reads this next: keep the `() =>`.
   */
  const jobs: Array<() => Promise<unknown>> = []
  const companyId = subject.company?.id
  const push = (metric: string, value: number) => {
    jobs.push(() =>
      api.post('/agent-targets', {
        agent_id: subject.id,
        period,
        metric,
        target_value: value,
        ...(companyId ? { company_id: companyId } : {}),
      }),
    )
  }
  if (form.sales_baht !== original.sales_baht && form.sales_baht !== '') {
    // BR-3 — baht in the box, integer satang on the wire. Unchanged from
    // TASK-053: the input is now digits-only (TASK-130 §3), so Number() here
    // is a whole number and ×100 cannot produce a fractional satang.
    push('sales_satang', Math.round(Number(form.sales_baht) * 100))
  }
  if (form.deals !== original.deals && form.deals !== '') push('deals', Math.round(Number(form.deals)))
  if (form.clients !== original.clients && form.clients !== '') push('clients', Math.round(Number(form.clients)))
  return jobs
}

/** Both groups — current month ('YYYY-MM') and current year ('YYYY'). */
function buildTargetJobs(subject: AgentItem): Array<() => Promise<unknown>> {
  return [
    ...targetJobsForPeriod(subject, monthlyPeriod.value, targetsMonthlyForm.value, targetsMonthlyOriginal.value),
    ...targetJobsForPeriod(subject, yearlyPeriod.value, targetsYearlyForm.value, targetsYearlyOriginal.value),
  ]
}

/** Every key the modal has an input for — anything else must not be swallowed. */
const EDIT_FIELD_NAMES = [
  'first_name',
  'last_name',
  'email',
  'phone',
  'role',
  'manager_id',
  'is_team_leader',
  'bank_name',
  'bank_account_number',
  'bank_account_holder_name',
  'national_id',
  'id_document_type',
]

/**
 * Field-level 422s land on the field they are about, not in one banner.
 * Returns false when the error carried no `errors` bag at all, so the caller
 * can fall back to a status-code message.
 */
function applyValidationErrors(e: ApiError): boolean {
  const body = e.body as { errors?: Record<string, string[]> } | undefined
  const errors = body?.errors
  if (!errors) return false
  const mapped: Record<string, string> = {}
  for (const [field, messages] of Object.entries(errors)) {
    if (messages?.[0]) mapped[field] = messages[0]
  }
  editFieldErrors.value = mapped
  // A rejected field with no control in this modal would otherwise fail
  // silently — surface those (and only those) in the modal banner.
  const orphans = Object.keys(mapped).filter((f) => !EDIT_FIELD_NAMES.includes(f))
  editFormError.value = orphans.length ? orphans.map((f) => mapped[f]).join(' · ') : ''
  return true
}

/**
 * Client-side mirror of the server rules an admin can actually fix here.
 * `required_with:national_id` (TASK-122) is the important one: the server's
 * 422 is the backstop, not the primary UX.
 */
function validateEdit(): boolean {
  const errors: Record<string, string> = {}
  if (!editForm.value.first_name.trim()) errors.first_name = 'กรุณากรอกชื่อ'
  if (!editForm.value.last_name.trim()) errors.last_name = 'กรุณากรอกนามสกุล'
  if (!editForm.value.email.trim()) errors.email = 'กรุณากรอกอีเมล'
  if (idDocMode.value === 'replace') {
    const documentNumber = normalizeIdNumber(idDocForm.value.id_document_type, idDocForm.value.national_id)
    if (documentNumber && !idDocForm.value.id_document_type) {
      errors.id_document_type = 'กรอกเลขที่เอกสารแล้ว ต้องเลือกประเภทเอกสารด้วย'
    }
  }
  editFieldErrors.value = errors
  return Object.keys(errors).length === 0
}

/**
 * The name to put in the success dialog. Built from the form because the
 * name is one of the things a save can change; `subject.name` is derived
 * server-side (User::booted()'s saving() hook) and the local copy is still
 * the pre-save one. Falls back to whatever the row already said rather than
 * rendering an empty string.
 */
function savedSubjectName(subject: AgentItem): string {
  const typed = `${editForm.value.first_name.trim()} ${editForm.value.last_name.trim()}`.trim()

  return typed || subject.name
}

async function submitEdit(): Promise<void> {
  const subject = agent.value
  if (!subject || editIsReadOnly.value) return
  editFormError.value = ''
  editSavedMessage.value = ''
  if (!validateEdit()) return

  const patch = buildUserPatch()
  const targetJobs = buildTargetJobs(subject)
  if (!Object.keys(patch).length && !targetJobs.length) {
    editSavedMessage.value = 'ไม่มีการเปลี่ยนแปลง'
    return
  }

  editSaving.value = true
  try {
    // ONE call for the person. Targets are a different resource with its own
    // endpoint (no /users field can carry them), so they follow as their own
    // upserts — still triggered by the same single "บันทึก".
    if (Object.keys(patch).length) await api.put(`/users/${subject.id}`, patch)
    // Only NOW are the target requests started — see targetJobsForPeriod().
    if (targetJobs.length) await Promise.all(targetJobs.map((job) => job()))

    const leaderChanged = Object.prototype.hasOwnProperty.call(patch, 'is_team_leader')
    // The host owns its own lists — it reloads them (and, when the leader
    // flag moved, its recruit links) off this event. It also raises the
    // success dialog: this modal is about to close, so it cannot report its
    // own result (TASK-210).
    emit('saved', {
      leaderChanged,
      // Named from the FORM, not from `subject.name`: the name is exactly
      // what may have just changed, and `subject` is still the pre-save row.
      successMessage: `บันทึกข้อมูลของ ${savedSubjectName(subject)} เรียบร้อยแล้ว`,
    })
    closeModal()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      // Never echo the document/bank number back — PDPA §6. Laravel's own
      // messages name the field, not the value.
      if (!applyValidationErrors(e)) editFormError.value = 'บันทึกไม่สำเร็จ (422)'
    } else {
      editFormError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
    }
  } finally {
    editSaving.value = false
  }
}

// ── Section 5 — account actions. Deliberately NOT part of the form save ──
//
// Each of these hits its OWN endpoint and takes effect the instant it is
// pressed. They are not attributes of a person that a "บันทึก" could commit;
// they are events — a new password now exists, the account is gone, a
// certification was granted, the user belongs to another company — and
// several cannot be undone from this screen. Folding them into the Save
// button would mean an admin who opened the modal to fix a typo in an email
// could deactivate someone by pressing บันทึก. Hence the separate section,
// the separate wording, and the confirms.

const resetPasswordValue = ref('')
const resetPasswordSaving = ref(false)
const resetPasswordMessage = ref('')
const resetPasswordError = ref('')
async function submitResetPassword(): Promise<void> {
  const subject = agent.value
  if (!subject) return
  resetPasswordError.value = ''
  resetPasswordMessage.value = ''
  if (resetPasswordValue.value.length < 8) {
    resetPasswordError.value = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'
    return
  }
  resetPasswordSaving.value = true
  try {
    await api.post(`/users/${subject.id}/reset-password`, { password: resetPasswordValue.value })
    // The typed value stays on screen on purpose: there is no email system
    // anywhere in this codebase, so the admin has to read this password back
    // out to the agent themselves.
    resetPasswordMessage.value = 'ตั้งรหัสผ่านใหม่สำเร็จ — แจ้งรหัสนี้ให้ตัวแทนด้วยตนเอง'
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { errors?: Record<string, string[]> }
      resetPasswordError.value = body.errors?.password?.[0] ?? 'ตั้งรหัสผ่านใหม่ไม่สำเร็จ'
    } else {
      resetPasswordError.value =
        e instanceof ApiError ? `ตั้งรหัสผ่านใหม่ไม่สำเร็จ (${e.status})` : 'ตั้งรหัสผ่านใหม่ไม่สำเร็จ'
    }
  } finally {
    resetPasswordSaving.value = false
  }
}

// Deactivate is a soft-delete (referral/commission/xp history for a
// deactivated agent stays intact) and moves the agent to another tab, so the
// modal closes after it. Confirmed rather than one-click because it now sits
// inside a form the admin came to edit.
const accountActionSaving = ref(false)
const pendingDeactivate = ref<AgentItem | null>(null)
const showDeactivateConfirm = computed({
  get: () => pendingDeactivate.value !== null,
  set: (v: boolean) => {
    if (!v) pendingDeactivate.value = null
  },
})
function askDeactivate(): void {
  pendingDeactivate.value = agent.value
}
async function confirmDeactivate(): Promise<void> {
  const subject = pendingDeactivate.value
  if (!subject) return
  accountActionSaving.value = true
  try {
    await api.delete(`/users/${subject.id}`)
    emit('saved', { leaderChanged: false, successMessage: `ปิดใช้งานบัญชีของ ${subject.name} เรียบร้อยแล้ว` })
    closeModal()
  } catch (e) {
    editFormError.value = e instanceof ApiError ? `ปิดใช้งานไม่สำเร็จ (${e.status})` : 'ปิดใช้งานไม่สำเร็จ'
  } finally {
    accountActionSaving.value = false
    pendingDeactivate.value = null
  }
}
async function restoreAgent(): Promise<void> {
  const subject = agent.value
  if (!subject) return
  accountActionSaving.value = true
  editFormError.value = ''
  try {
    await api.post(`/users/${subject.id}/restore`)
    emit('saved', { leaderChanged: false, successMessage: `กู้คืนบัญชีของ ${subject.name} เรียบร้อยแล้ว` })
    closeModal()
  } catch (e) {
    editFormError.value = e instanceof ApiError ? `กู้คืนไม่สำเร็จ (${e.status})` : 'กู้คืนไม่สำเร็จ'
  } finally {
    accountActionSaving.value = false
  }
}

// Move company (Phase 11, Super Admin only — UserPolicy::move()). Closes the
// modal on success: the agent's company changed, so every company-scoped
// thing the modal was showing (manager options above all) is now stale.
const moveCompanyTarget = ref('')
const moveCompanySaving = ref(false)
const moveCompanyError = ref('')
async function submitMoveCompany(): Promise<void> {
  const subject = agent.value
  if (!subject || !moveCompanyTarget.value) return
  moveCompanySaving.value = true
  moveCompanyError.value = ''
  try {
    const target = companies.value.find((c) => c.id === Number(moveCompanyTarget.value))
    await api.post(`/users/${subject.id}/move-company`, { company_id: Number(moveCompanyTarget.value) })
    moveCompanyTarget.value = ''
    emit('saved', {
      leaderChanged: false,
      successMessage: target
        ? `ย้าย ${subject.name} ไปบริษัท ${target.name} เรียบร้อยแล้ว`
        : `ย้ายบริษัทของ ${subject.name} เรียบร้อยแล้ว`,
    })
    closeModal()
  } catch (e) {
    moveCompanyError.value = e instanceof ApiError ? `ย้ายบริษัทไม่สำเร็จ (${e.status})` : 'ย้ายบริษัทไม่สำเร็จ'
  } finally {
    moveCompanySaving.value = false
  }
}

// ── Grant cert without exam (TASK-058/061) — same logic/UX as
// AcademyManagementView's progress-tab panel, so an Admin can approve a tier
// from the same screen they manage the agent on. No XP awarded — see
// ManualCertificationService's docblock. ──
const passedTiersByUser = computed(() => {
  const map = new Map<number, CertTierOption[]>()
  for (const c of certifications.value) {
    if (!c.cert_tier) continue
    if (!map.has(c.user_id)) map.set(c.user_id, [])
    map.get(c.user_id)!.push(c.cert_tier)
  }
  return map
})
const editCertTiersNotYetPassed = computed<CertTierOption[]>(() => {
  const subject = agent.value
  if (!subject) return []
  const passedIds = new Set((passedTiersByUser.value.get(subject.id) ?? []).map((t) => t.id))
  return certTiers.value.filter((t) => !passedIds.has(t.id))
})
const grantingTierKey = ref<string | null>(null)
const grantError = ref('')
// TASK-066 — native window.confirm() replaced with the ConfirmDialog modal so
// this looks like part of the app, not an unstyled OS popup.
const pendingGrant = ref<{ subject: AgentItem; tier: CertTierOption } | null>(null)
const showGrantConfirm = computed({
  get: () => pendingGrant.value !== null,
  set: (v: boolean) => {
    if (!v) pendingGrant.value = null
  },
})
function grantCertFromModal(tier: CertTierOption): void {
  if (agent.value) pendingGrant.value = { subject: agent.value, tier }
}
async function confirmGrantCertification(): Promise<void> {
  const pending = pendingGrant.value
  if (!pending) return
  const { subject, tier } = pending
  const key = `${subject.id}:${tier.id}`
  grantingTierKey.value = key
  grantError.value = ''
  try {
    await api.post('/user-certifications', { user_id: subject.id, cert_tier_id: tier.id })
    // Granting a tier does NOT close the modal (an admin often grants two in a
    // row), so what it changed has to be re-read: the host's copies via
    // `saved`, and — when this component owns them — its own.
    emit('saved', { leaderChanged: false })
    if (!props.certifications) {
      const res = await api.get<{ data: UserCertificationItem[] }>('/user-certifications')
      ownCertifications.value = res.data
    }
    await resyncSubject()
  } catch (e) {
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

// ── View helpers ─────────────────────────────────────────────────────
// Options come from the roster (same company; same-company enforcement
// happens server-side regardless — BR-6, the server is the guard and this
// dropdown is a convenience).
const editManagerOptions = computed<AgentItem[]>(() => {
  const subject = agent.value
  if (!subject) return []
  return roster.value.filter((a) => a.id !== subject.id && a.company?.id === subject.company?.id)
})
const editAgentLinks = computed<AgentInviteLinkRef[]>(() => {
  const subject = agent.value
  if (!subject || !props.inviteLinks) return []
  return props.inviteLinks.filter((l) => l.agent_id === subject.id)
})
const editMoveCompanyOptions = computed<CompanyOption[]>(() =>
  companies.value.filter((c) => c.id !== agent.value?.company?.id),
)

function requestLinksView(): void {
  if (!agent.value) return
  emit('show-links', agent.value.id)
  closeModal()
}

/** Red border on exactly the field the server (or validateEdit) rejected. */
function inputBorderClass(field: string): string {
  return editFieldErrors.value[field] ? 'border-rose-300' : 'border-slate-200'
}

/**
 * TASK-131 QA — drives the footer's error line.
 *
 * A 422 populates editFieldErrors but leaves editFormError empty (each message
 * is rendered next to its own field, which is correct). The problem is that
 * the offending field can be four sections above the footer: the admin sees
 * the save button do nothing and no explanation anywhere on screen. So the
 * footer says "something below is red" whenever there is a field error but no
 * form-level one.
 */
const hasFieldErrors = computed(() => Object.keys(editFieldErrors.value).length > 0)

/**
 * TASK-130 §3 — the "เลขที่เอกสาร" box serves BOTH document types, and only
 * one of them is numeric: a Thai national ID is 13 digits, a passport is
 * alphanumeric by design. So the guard is conditional (sanitizeIdNumberInput)
 * and the value is re-sanitised when the TYPE changes to บัตรประชาชน — a
 * passport number typed first would otherwise leave letters sitting in a
 * field that now claims to be 13 digits. Never the other way round: digits
 * are perfectly valid passport input.
 */
function onIdNumberInput(event: Event): void {
  idDocForm.value.national_id = sanitizeIdNumberInput(idDocForm.value.id_document_type, event)
}
watch(
  () => idDocForm.value.id_document_type,
  (type) => {
    if (type === 'thai_national_id') idDocForm.value.national_id = digitsOnly(idDocForm.value.national_id)
  },
)

function startReplaceIdDocument(): void {
  idDocMode.value = 'replace'
  // Starts EMPTY on purpose — never seeded from what is on screen, which may
  // be `national_id_masked` (see SensitiveFieldMode). The TYPE is kept: it is
  // not masked, and re-picking it on every correction would be pure noise.
  idDocForm.value.national_id = ''
  delete editFieldErrors.value.national_id
  delete editFieldErrors.value.id_document_type
}
function cancelIdDocumentChange(): void {
  idDocMode.value = agent.value?.national_id_masked ? 'keep' : 'replace'
  idDocForm.value = { id_document_type: agent.value?.id_document_type ?? '', national_id: '' }
  delete editFieldErrors.value.national_id
  delete editFieldErrors.value.id_document_type
}
function startReplaceBankNumber(): void {
  bankNumberMode.value = 'replace'
  // Same rule as the document above: the account number the modal is showing
  // may be "*****1234" and there is no way to tell, so replacement always
  // starts from an empty box.
  bankNumberInput.value = ''
  delete editFieldErrors.value.bank_account_number
}
function cancelBankNumberChange(): void {
  bankNumberMode.value = agent.value?.bank_account_number ? 'keep' : 'replace'
  bankNumberInput.value = ''
  delete editFieldErrors.value.bank_account_number
}

// ── The open/close trigger ───────────────────────────────────────────
// Declared LAST on purpose: it runs immediately (the parent may already be
// mounted with an agent selected, e.g. AgentManagementView's ?edit=<id> deep
// link), and resetModalState() touches refs declared throughout this file —
// running it before those `const`s are initialised would be a TDZ crash.
watch(
  () => props.agentId,
  (id) => {
    if (id === null) {
      resetModalState()
      return
    }
    void openFor(id)
  },
  { immediate: true },
)
</script>

<template>
  <!-- ═══════════════════════════════════════════════════════════════════
       TASK-128/129 — the one edit modal. Same shell as every other
       create/edit modal in this app (AnnouncementsView et al): z-50 so
       ConfirmDialog's z-[1000] still layers above it, 60vw, click-outside
       to close.
       ═══════════════════════════════════════════════════════════════════ -->
  <div
    v-if="agentId !== null"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4"
    @click.self="closeModal"
  >
    <div class="w-[60vw] min-w-[320px] max-w-[60vw] max-h-[90vh] bg-white rounded-2xl shadow-lg flex flex-col">
      <!-- The subject is fetched, not handed over (see the file docblock), so
           there is a brief moment with nothing to show — and a real failure
           mode if the agent cannot be resolved at all. -->
      <template v-if="!agent">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
          <p class="text-sm font-bold text-slate-900">แก้ไขข้อมูลตัวแทน</p>
          <button type="button" class="text-slate-400 hover:text-slate-600 shrink-0" @click="closeModal">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <div class="px-5 py-10 text-center">
          <p v-if="subjectError" class="text-sm text-rose-600">{{ subjectError }}</p>
          <p v-else class="text-sm text-slate-400">
            {{ subjectLoading ? 'กำลังโหลดข้อมูลตัวแทน...' : 'กำลังเตรียมข้อมูล...' }}
          </p>
        </div>
      </template>

      <template v-else>
        <!-- Header -->
        <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-slate-100 shrink-0">
          <div class="min-w-0">
            <p class="text-sm font-bold text-slate-900 truncate">
              แก้ไขข้อมูลตัวแทน — {{ agent.name }}
              <span v-if="isSuperAdmin && agent.company" class="text-xs font-normal text-slate-400">
                · {{ agent.company.name }}
              </span>
            </p>
            <p class="text-xs text-slate-400 truncate">{{ agent.email }}</p>
          </div>
          <button type="button" class="text-slate-400 hover:text-slate-600 shrink-0" @click="closeModal">
            <Icon name="x" :size="18" />
          </button>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-6">
          <!-- PUT /users/{user} has no withTrashed() binding, so nothing in
               sections 1–4 can be written for a deactivated agent — say so
               instead of letting the admin type into a form that will 404. -->
          <div
            v-if="editIsReadOnly"
            class="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-700"
          >
            ตัวแทนรายนี้ถูกปิดใช้งานอยู่ — แก้ไขข้อมูลไม่ได้จนกว่าจะกด "เปิดใช้งาน" ในส่วนที่ 5 ด้านล่าง
          </div>
          <div v-if="editFormError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">
            {{ editFormError }}
          </div>
          <p v-if="editSavedMessage" class="text-xs font-bold text-slate-500">{{ editSavedMessage }}</p>

          <!-- ─────────── 1. ข้อมูลตัวแทน ─────────── -->
          <section>
            <div class="flex items-center gap-2 mb-3">
              <Icon name="user" :size="14" class="text-slate-400" />
              <h3 class="text-sm font-bold text-slate-900">1. ข้อมูลตัวแทน</h3>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-bold text-slate-500">ชื่อ</label>
                <input
                  v-model="editForm.first_name"
                  type="text"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('first_name')"
                />
                <p v-if="editFieldErrors.first_name" class="text-[11px] text-rose-600 mt-1">
                  {{ editFieldErrors.first_name }}
                </p>
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">นามสกุล</label>
                <input
                  v-model="editForm.last_name"
                  type="text"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('last_name')"
                />
                <p v-if="editFieldErrors.last_name" class="text-[11px] text-rose-600 mt-1">
                  {{ editFieldErrors.last_name }}
                </p>
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">อีเมล</label>
                <input
                  v-model="editForm.email"
                  type="email"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('email')"
                />
                <p v-if="editFieldErrors.email" class="text-[11px] text-rose-600 mt-1">{{ editFieldErrors.email }}</p>
              </div>
              <!-- TASK-131 — editable now. It was read-only because
                   UpdateUserRequest had no `phone` rule, so anything typed here
                   would have been dropped by validated() without a word; the
                   rule exists as of TASK-131 and the field follows. -->
              <div>
                <label class="text-xs font-bold text-slate-500">เบอร์โทร</label>
                <input
                  v-model="editForm.phone"
                  type="tel"
                  inputmode="tel"
                  maxlength="32"
                  placeholder="08xxxxxxxx"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('phone')"
                />
                <p v-if="editFieldErrors.phone" class="text-[11px] text-rose-600 mt-1">{{ editFieldErrors.phone }}</p>
              </div>
              <!-- TASK-130 §1 — "Company Admin" is offered ONLY to someone
                   who already is one (see canBeCompanyAdmin): an Agent cannot
                   be promoted from this form. -->
              <div>
                <label class="text-xs font-bold text-slate-500">บทบาท (Role)</label>
                <select
                  v-model="editForm.role"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm bg-white disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('role')"
                >
                  <option value="agent">Agent</option>
                  <option v-if="canBeCompanyAdmin" value="company_admin">Company Admin</option>
                </select>
                <p v-if="editFieldErrors.role" class="text-[11px] text-rose-600 mt-1">{{ editFieldErrors.role }}</p>
                <p v-else-if="!canBeCompanyAdmin" class="text-[11px] text-slate-400 mt-1">
                  เปลี่ยนตัวแทนขายเป็น Company Admin จากหน้านี้ไม่ได้
                </p>
              </div>
              <!-- TASK-130 §2b — hidden for a team leader: they sit at the top
                   of their own branch, so the control has no meaning and an
                   empty dropdown only invites an admin to fill it in. The
                   value already on file is left untouched (uplineIsEditable). -->
              <div v-if="uplineIsEditable">
                <label class="text-xs font-bold text-slate-500">หัวหน้า (Upline)</label>
                <select
                  v-model="editForm.manager_id"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm bg-white disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('manager_id')"
                >
                  <option :value="null">ไม่มีหัวหน้า</option>
                  <option v-for="m in editManagerOptions" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <p v-if="editFieldErrors.manager_id" class="text-[11px] text-rose-600 mt-1">
                  {{ editFieldErrors.manager_id }}
                </p>
              </div>
            </div>

            <!-- ADR-025 §1 — a CAPABILITY, not a fourth role: `role` above
                 stays "Agent". A real toggle now, not a link that opened a
                 panel; the copy stays because an admin must not flip this
                 thinking it is just another profile field. -->
            <div v-if="editForm.role === 'agent'" class="mt-3 rounded-xl border border-amber-200 bg-amber-50/60 p-3">
              <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-2 min-w-0">
                  <Icon name="shield_check" :size="16" class="text-amber-600 mt-0.5 shrink-0" />
                  <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-900">สิทธิ์หัวหน้าทีม</p>
                    <p class="text-xs text-slate-600 mt-0.5">
                      เปิดแล้วจะสร้างลิงก์ชวนคนเข้าทีมได้เอง และ<strong class="font-bold"
                        >กดรับคนที่สมัครผ่านลิงก์ของเขาเข้าทำงานได้เลย</strong
                      >
                      โดยไม่ต้องรอผู้ดูแลอนุมัติ — แต่ยังเห็นข้อมูลเท่าเดิม (ลูกค้า ยอดขาย ค่าคอมของคนอื่นยังดูไม่ได้)
                      และยังเป็นตัวแทนขายเหมือนเดิม
                    </p>
                    <p class="text-[11px] text-slate-500 mt-1">
                      ถ้าปิดสิทธิ์ ลิงก์ที่เขาสร้างไว้จะใช้สมัครไม่ได้ทันที ส่วนลูกทีมที่รับเข้ามาแล้วยังอยู่ในทีมตามเดิม
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  role="switch"
                  :aria-checked="editForm.is_team_leader"
                  :disabled="editIsReadOnly"
                  class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
                  :class="editForm.is_team_leader ? 'bg-amber-500' : 'bg-slate-300'"
                  @click="editForm.is_team_leader = !editForm.is_team_leader"
                >
                  <span
                    class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                    :class="editForm.is_team_leader ? 'translate-x-6' : 'translate-x-1'"
                  />
                </button>
              </div>
              <p v-if="editFieldErrors.is_team_leader" class="text-[11px] text-rose-600 mt-1.5">
                {{ editFieldErrors.is_team_leader }}
              </p>
              <!-- The ADR-025 §7 oversight surface, kept reachable from here.
                   Rendered ONLY for a host that actually tracks recruit links
                   (see the inviteLinks prop): a host that does not know would
                   otherwise state "0 ลิงก์" as fact. -->
              <div
                v-if="inviteLinks"
                class="mt-2 pt-2 border-t border-amber-200 flex items-center justify-between gap-2"
              >
                <p class="text-[11px] text-slate-600">ลิงก์ชวนเข้าทีมที่สร้างไว้: {{ editAgentLinks.length }} ลิงก์</p>
                <button
                  v-if="editAgentLinks.length"
                  type="button"
                  class="text-[11px] font-bold text-brand-600 hover:text-brand-700"
                  @click="requestLinksView"
                >
                  ดูในแท็บ "ลิงก์ชวนทีม"
                </button>
              </div>
            </div>
          </section>

          <!-- ─────────── 2. เอกสารยืนยันตัวตน ─────────── -->
          <section>
            <div class="flex items-center gap-2 mb-3">
              <Icon name="shield" :size="14" class="text-slate-400" />
              <h3 class="text-sm font-bold text-slate-900">2. เอกสารยืนยันตัวตน</h3>
              <span class="text-[11px] text-slate-400">(PDPA — เก็บแบบเข้ารหัส)</span>
            </div>

            <!-- keep: read-only. The value on screen is the REAL number only
                 when the API decided this viewer may see it; otherwise it is
                 national_id_masked. Either way it is never put in an input —
                 saving a mask back would destroy the encrypted record. -->
            <div
              v-if="idDocMode === 'keep'"
              class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 flex items-center justify-between gap-3"
            >
              <div class="min-w-0">
                <p class="text-[11px] text-slate-500">เอกสารที่บันทึกไว้</p>
                <p class="text-sm font-bold text-slate-900 truncate">
                  {{ idDocumentTypeLabel(agent.id_document_type) }} ·
                  {{ agent.national_id || agent.national_id_masked || '-' }}
                </p>
              </div>
              <div class="flex gap-1 shrink-0">
                <button
                  type="button"
                  :disabled="editIsReadOnly"
                  class="text-xs font-bold text-brand-600 hover:text-brand-700 px-2 py-1 disabled:opacity-50"
                  @click="startReplaceIdDocument"
                >
                  เปลี่ยน
                </button>
                <button
                  type="button"
                  :disabled="editIsReadOnly"
                  class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1 disabled:opacity-50"
                  @click="idDocMode = 'clear'"
                >
                  ล้างข้อมูล
                </button>
              </div>
            </div>

            <div
              v-else-if="idDocMode === 'clear'"
              class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 flex items-center justify-between gap-3"
            >
              <p class="text-xs text-rose-700">
                จะล้างข้อมูลเอกสารของตัวแทนรายนี้เมื่อกด "บันทึก" (ประเภทเอกสารจะถูกล้างตามไปด้วย)
              </p>
              <button
                type="button"
                class="text-xs font-bold text-slate-600 hover:text-slate-800 px-2 py-1 shrink-0"
                @click="cancelIdDocumentChange"
              >
                ยกเลิก
              </button>
            </div>

            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-bold text-slate-500">ประเภทเอกสาร</label>
                <select
                  v-model="idDocForm.id_document_type"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm bg-white disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('id_document_type')"
                >
                  <option value="">— ไม่ระบุ —</option>
                  <option v-for="opt in ID_DOCUMENT_TYPE_OPTIONS" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
                <p v-if="editFieldErrors.id_document_type" class="text-[11px] text-rose-600 mt-1">
                  {{ editFieldErrors.id_document_type }}
                </p>
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">เลขที่เอกสาร</label>
                <!-- TASK-130 §3 — digits-only WHEN the type is บัตรประชาชน
                     (13 digits); a passport is alphanumeric by design and is
                     left untouched. See sanitizeIdNumberInput. -->
                <input
                  :value="idDocForm.national_id"
                  type="text"
                  :inputmode="idNumberInputMode(idDocForm.id_document_type)"
                  :maxlength="idNumberMaxLength(idDocForm.id_document_type)"
                  :placeholder="idNumberPlaceholder(idDocForm.id_document_type)"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm disabled:bg-slate-50 disabled:text-slate-400"
                  :class="[inputBorderClass('national_id'), idDocForm.id_document_type === 'passport' ? 'uppercase' : '']"
                  @input="onIdNumberInput"
                />
                <p v-if="editFieldErrors.national_id" class="text-[11px] text-rose-600 mt-1">
                  {{ editFieldErrors.national_id }}
                </p>
              </div>
              <p v-if="agent.national_id_masked" class="sm:col-span-2 text-[11px] text-slate-400">
                กรอกเลขใหม่ทั้งหมด — เว้นว่างไว้ = ไม่เปลี่ยนเอกสารเดิม
                <button
                  type="button"
                  class="font-bold text-slate-500 hover:text-slate-700 ml-1"
                  @click="cancelIdDocumentChange"
                >
                  ยกเลิกการเปลี่ยน
                </button>
              </p>
            </div>
          </section>

          <!-- ─────────── 3. บัญชีธนาคาร ─────────── -->
          <section>
            <div class="flex items-center gap-2 mb-3">
              <Icon name="credit_card" :size="14" class="text-slate-400" />
              <h3 class="text-sm font-bold text-slate-900">3. บัญชีธนาคาร</h3>
              <span class="text-[11px] text-slate-400">(ใช้จ่ายค่าคอมมิชชั่น)</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-bold text-slate-500">ธนาคาร</label>
                <input
                  v-model="editForm.bank_name"
                  type="text"
                  placeholder="เช่น กสิกรไทย"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('bank_name')"
                />
                <p v-if="editFieldErrors.bank_name" class="text-[11px] text-rose-600 mt-1">
                  {{ editFieldErrors.bank_name }}
                </p>
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">ชื่อบัญชี</label>
                <input
                  v-model="editForm.bank_account_holder_name"
                  type="text"
                  :disabled="editIsReadOnly"
                  class="mt-1 w-full px-3 py-2 rounded-lg border text-sm disabled:bg-slate-50 disabled:text-slate-400"
                  :class="inputBorderClass('bank_account_holder_name')"
                />
                <p v-if="editFieldErrors.bank_account_holder_name" class="text-[11px] text-rose-600 mt-1">
                  {{ editFieldErrors.bank_account_holder_name }}
                </p>
              </div>

              <!-- Same masked-value rule as the identity document above, and
                   here it is even less negotiable: bank_account_number arrives
                   in ONE key whether it is real or "*****1234", with nothing
                   to tell them apart — so it is never prefilled into an input,
                   ever. -->
              <div class="sm:col-span-2">
                <label class="text-xs font-bold text-slate-500">เลขที่บัญชี</label>
                <div
                  v-if="bankNumberMode === 'keep'"
                  class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 flex items-center justify-between gap-3"
                >
                  <p class="text-sm font-bold text-slate-900 truncate">{{ agent.bank_account_number || '-' }}</p>
                  <div class="flex gap-1 shrink-0">
                    <button
                      type="button"
                      :disabled="editIsReadOnly"
                      class="text-xs font-bold text-brand-600 hover:text-brand-700 px-2 py-1 disabled:opacity-50"
                      @click="startReplaceBankNumber"
                    >
                      เปลี่ยน
                    </button>
                    <button
                      type="button"
                      :disabled="editIsReadOnly"
                      class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1 disabled:opacity-50"
                      @click="bankNumberMode = 'clear'"
                    >
                      ล้างข้อมูล
                    </button>
                  </div>
                </div>
                <div
                  v-else-if="bankNumberMode === 'clear'"
                  class="mt-1 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 flex items-center justify-between gap-3"
                >
                  <p class="text-xs text-rose-700">จะล้างเลขที่บัญชีของตัวแทนรายนี้เมื่อกด "บันทึก"</p>
                  <button
                    type="button"
                    class="text-xs font-bold text-slate-600 hover:text-slate-800 px-2 py-1 shrink-0"
                    @click="cancelBankNumberChange"
                  >
                    ยกเลิก
                  </button>
                </div>
                <template v-else>
                  <!-- TASK-130 §3 — digits only. A Thai bank account number is
                       numeric; `inputmode` alone picks the phone keypad but
                       blocks nothing typed on a desktop keyboard. -->
                  <input
                    :value="bankNumberInput"
                    type="text"
                    inputmode="numeric"
                    placeholder="กรอกเลขที่บัญชีใหม่ทั้งหมด"
                    :disabled="editIsReadOnly"
                    class="mt-1 w-full px-3 py-2 rounded-lg border text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    :class="inputBorderClass('bank_account_number')"
                    @input="bankNumberInput = sanitizeDigitsInput($event)"
                  />
                  <p v-if="editFieldErrors.bank_account_number" class="text-[11px] text-rose-600 mt-1">
                    {{ editFieldErrors.bank_account_number }}
                  </p>
                  <p v-if="agent.bank_account_number" class="text-[11px] text-slate-400 mt-1">
                    เว้นว่างไว้ = ไม่เปลี่ยนเลขบัญชีเดิม
                    <button
                      type="button"
                      class="font-bold text-slate-500 hover:text-slate-700 ml-1"
                      @click="cancelBankNumberChange"
                    >
                      ยกเลิกการเปลี่ยน
                    </button>
                  </p>
                </template>
              </div>
            </div>
          </section>

          <!-- ─────────── 4. เป้าหมาย ───────────
               TASK-130 §4+§5 — no month picker (it only ever meant "this
               month"), and a yearly group beside the monthly one. The two
               write different PERIODS to the same endpoint: 'YYYY-MM' and
               'YYYY'. Both are derived when the modal opens and shown as
               read-only chips, so the admin can see what they are setting
               without being asked to choose it. -->
          <section>
            <div class="flex items-center gap-2 mb-3">
              <Icon name="flag" :size="14" class="text-slate-400" />
              <h3 class="text-sm font-bold text-slate-900">4. เป้าหมาย</h3>
              <span v-if="targetsLoading" class="text-[11px] text-slate-400">กำลังโหลด...</span>
            </div>

            <!-- เป้าหมายรายเดือน — the current month -->
            <div class="rounded-xl border border-slate-200 p-3">
              <div class="flex items-center gap-2">
                <p class="text-xs font-bold text-slate-700">เป้าหมายรายเดือน</p>
                <span class="px-1.5 py-0.5 rounded bg-slate-100 text-[10px] font-bold text-slate-500">
                  เดือนปัจจุบัน · {{ monthlyPeriod }}
                </span>
              </div>
              <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                  <label class="text-xs font-bold text-slate-500">ยอดขาย (บาท)</label>
                  <input
                    :value="targetsMonthlyForm.sales_baht"
                    type="text"
                    inputmode="numeric"
                    placeholder="เช่น 50000"
                    :disabled="editIsReadOnly"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    @input="targetsMonthlyForm.sales_baht = sanitizeDigitsInput($event)"
                  />
                </div>
                <div>
                  <label class="text-xs font-bold text-slate-500">จำนวนดีล</label>
                  <input
                    :value="targetsMonthlyForm.deals"
                    type="text"
                    inputmode="numeric"
                    placeholder="เช่น 10"
                    :disabled="editIsReadOnly"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    @input="targetsMonthlyForm.deals = sanitizeDigitsInput($event)"
                  />
                </div>
                <div>
                  <label class="text-xs font-bold text-slate-500">จำนวนลูกค้า</label>
                  <input
                    :value="targetsMonthlyForm.clients"
                    type="text"
                    inputmode="numeric"
                    placeholder="เช่น 8"
                    :disabled="editIsReadOnly"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    @input="targetsMonthlyForm.clients = sanitizeDigitsInput($event)"
                  />
                </div>
              </div>
              <p class="text-[11px] text-slate-400 mt-1.5">วงเป้าหมายในหน้า Home ของตัวแทนจะอ่านค่ารายเดือนนี้</p>
            </div>

            <!-- เป้าหมายรายปี — the current year -->
            <div class="mt-3 rounded-xl border border-slate-200 p-3">
              <div class="flex items-center gap-2">
                <p class="text-xs font-bold text-slate-700">เป้าหมายรายปี</p>
                <span class="px-1.5 py-0.5 rounded bg-slate-100 text-[10px] font-bold text-slate-500">
                  ปีปัจจุบัน · {{ yearlyPeriod }}
                </span>
              </div>
              <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                  <label class="text-xs font-bold text-slate-500">ยอดขาย (บาท)</label>
                  <input
                    :value="targetsYearlyForm.sales_baht"
                    type="text"
                    inputmode="numeric"
                    placeholder="เช่น 600000"
                    :disabled="editIsReadOnly"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    @input="targetsYearlyForm.sales_baht = sanitizeDigitsInput($event)"
                  />
                </div>
                <div>
                  <label class="text-xs font-bold text-slate-500">จำนวนดีล</label>
                  <input
                    :value="targetsYearlyForm.deals"
                    type="text"
                    inputmode="numeric"
                    placeholder="เช่น 120"
                    :disabled="editIsReadOnly"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    @input="targetsYearlyForm.deals = sanitizeDigitsInput($event)"
                  />
                </div>
                <div>
                  <label class="text-xs font-bold text-slate-500">จำนวนลูกค้า</label>
                  <input
                    :value="targetsYearlyForm.clients"
                    type="text"
                    inputmode="numeric"
                    placeholder="เช่น 96"
                    :disabled="editIsReadOnly"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    @input="targetsYearlyForm.clients = sanitizeDigitsInput($event)"
                  />
                </div>
              </div>
            </div>

            <p class="text-[11px] text-slate-400 mt-1.5">
              เว้นว่างช่องที่ไม่ต้องการตั้ง (เว้นว่างไม่ได้ลบเป้าหมายที่ตั้งไว้แล้ว) · กรอกได้เฉพาะตัวเลข
            </p>
          </section>

          <!-- ─────────── 5. การจัดการบัญชี (actions, ไม่ใช่ฟอร์ม) ─────────── -->
          <section class="rounded-xl border border-slate-300 border-dashed bg-slate-50/80 p-4">
            <div class="flex items-center gap-2">
              <Icon name="settings" :size="14" class="text-slate-500" />
              <h3 class="text-sm font-bold text-slate-900">5. การจัดการบัญชี</h3>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">
              ทุกปุ่มในส่วนนี้<strong class="font-bold">มีผลทันทีที่กด</strong> — ไม่เกี่ยวกับปุ่ม "บันทึก" ด้านล่าง
            </p>

            <!-- Reset password. No email system exists anywhere in this
                 codebase — the admin types a temporary value and communicates
                 it out of band, which is why it stays visible after a
                 successful save. -->
            <div class="mt-3 pt-3 border-t border-slate-200">
              <p class="text-xs font-bold text-slate-700">รีเซ็ตรหัสผ่าน</p>
              <div class="mt-1.5 flex flex-col sm:flex-row gap-2">
                <input
                  v-model="resetPasswordValue"
                  type="text"
                  minlength="8"
                  placeholder="รหัสผ่านชั่วคราวใหม่ (8 ตัวขึ้นไป มีพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข)"
                  class="flex-1 px-3 py-2 rounded-lg border border-slate-200 text-sm"
                />
                <button
                  type="button"
                  :disabled="resetPasswordSaving"
                  class="btn-secondary shrink-0"
                  @click="submitResetPassword"
                >
                  {{ resetPasswordSaving ? 'กำลังตั้ง...' : 'ตั้งรหัสผ่านใหม่' }}
                </button>
              </div>
              <p v-if="resetPasswordError" class="text-[11px] text-rose-600 mt-1">{{ resetPasswordError }}</p>
              <p v-else-if="resetPasswordMessage" class="text-[11px] font-bold text-emerald-600 mt-1">
                {{ resetPasswordMessage }}
              </p>
            </div>

            <!-- Grant cert without exam (TASK-058/061). No XP awarded — see
                 ManualCertificationService's docblock. -->
            <div v-if="agent.role === 'agent' && editCertTiersNotYetPassed.length" class="mt-3 pt-3 border-t border-slate-200">
              <p class="text-xs font-bold text-slate-700">มอบใบรับรองโดยไม่ต้องสอบ</p>
              <p class="text-[11px] text-slate-500 mt-0.5">ตัวแทนจะไม่ได้รับ XP จากการอนุมัติแบบนี้ (TASK-058)</p>
              <div class="mt-1.5 flex flex-wrap gap-1.5">
                <button
                  v-for="t in editCertTiersNotYetPassed"
                  :key="t.id"
                  type="button"
                  :disabled="grantingTierKey === `${agent.id}:${t.id}`"
                  class="px-2.5 py-1 rounded-lg border border-slate-200 bg-white text-[11px] font-bold text-slate-600 hover:border-brand-400 hover:text-brand-600 disabled:opacity-50"
                  @click="grantCertFromModal(t)"
                >
                  {{ grantingTierKey === `${agent.id}:${t.id}` ? 'กำลังอนุมัติ...' : `+ อนุมัติ ${t.name}` }}
                </button>
              </div>
              <p v-if="grantError" class="text-[11px] text-rose-600 mt-1.5">{{ grantError }}</p>
            </div>

            <!-- Move company (Phase 11, Super Admin only — UserPolicy::move()) -->
            <div v-if="isSuperAdmin && agent.is_active" class="mt-3 pt-3 border-t border-slate-200">
              <p class="text-xs font-bold text-slate-700">ย้ายบริษัท (Super Admin)</p>
              <p class="text-[11px] text-slate-500 mt-0.5">
                ประวัติคอมมิชชั่น/XP เดิมจะยังผูกกับบริษัทเดิม — การย้ายมีผลกับข้อมูลใหม่ตั้งแต่นี้ไปเท่านั้น
              </p>
              <div class="mt-1.5 flex flex-col sm:flex-row gap-2">
                <select v-model="moveCompanyTarget" class="flex-1 px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option value="" disabled>เลือกบริษัทปลายทาง</option>
                  <option v-for="c in editMoveCompanyOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <button
                  type="button"
                  :disabled="!moveCompanyTarget || moveCompanySaving"
                  class="btn-secondary shrink-0"
                  @click="submitMoveCompany"
                >
                  {{ moveCompanySaving ? 'กำลังย้าย...' : 'ยืนยันย้ายบริษัท' }}
                </button>
              </div>
              <p v-if="moveCompanyError" class="text-[11px] text-rose-600 mt-1">{{ moveCompanyError }}</p>
            </div>

            <!-- Deactivate / restore. Soft-delete: referral/commission/xp
                 history stays intact. -->
            <div class="mt-3 pt-3 border-t border-slate-200">
              <template v-if="agent.is_active">
                <p class="text-xs font-bold text-slate-700">ปิดใช้งานบัญชี</p>
                <p class="text-[11px] text-slate-500 mt-0.5">
                  ตัวแทนจะเข้าสู่ระบบไม่ได้ แต่ประวัติการขาย/ค่าคอม/XP ยังอยู่ครบ และเปิดใช้งานคืนได้ภายหลัง
                </p>
                <button
                  type="button"
                  :disabled="accountActionSaving"
                  class="mt-1.5 px-3 py-2 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 disabled:opacity-50"
                  @click="askDeactivate"
                >
                  ปิดใช้งานตัวแทนรายนี้
                </button>
              </template>
              <template v-else>
                <p class="text-xs font-bold text-slate-700">เปิดใช้งานบัญชีอีกครั้ง</p>
                <button
                  type="button"
                  :disabled="accountActionSaving"
                  class="mt-1.5 px-3 py-2 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700 disabled:opacity-50"
                  @click="restoreAgent"
                >
                  {{ accountActionSaving ? 'กำลังเปิดใช้งาน...' : 'เปิดใช้งาน' }}
                </button>
              </template>
            </div>
          </section>
        </div>

        <!--
          Footer.

          TASK-131 QA — the failure message is repeated HERE, beside the button
          that caused it. It was only rendered at the top of section 1, and this
          form is five sections tall: a save that 500'd left an admin looking at
          a sticky footer with a button that had visibly done nothing, while the
          explanation sat several screens above, out of view. Verified in the
          browser — that is exactly what "บันทึกไม่สำเร็จ" looked like from the
          outside. The top copy stays (it is where a field-level error belongs);
          this one exists so no failure can ever be silent.
        -->
        <div class="flex items-center justify-end gap-2 px-5 py-3 border-t border-slate-100 shrink-0">
          <p
            v-if="editFormError || hasFieldErrors"
            class="flex-1 min-w-0 text-xs font-bold text-rose-600 leading-snug"
          >
            {{ editFormError || 'มีช่องที่กรอกไม่ถูกต้อง — เลื่อนขึ้นไปดูช่องที่ขึ้นสีแดง' }}
          </p>
          <p
            v-else-if="editSavedMessage"
            class="flex-1 min-w-0 text-xs font-bold text-slate-500 leading-snug"
          >
            {{ editSavedMessage }}
          </p>
          <button type="button" class="btn-secondary" @click="closeModal">ยกเลิก</button>
          <button type="button" :disabled="editSaving || editIsReadOnly" class="btn-primary" @click="submitEdit">
            {{ editSaving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </template>
    </div>

    <!-- Both dialogs live INSIDE this overlay (they are `fixed` at z-[1000],
         so they still paint above it) rather than as siblings: this component
         must have exactly ONE root element, or a host that wraps it in a
         <Transition> gets a Fragment it cannot track — the same bug that hit
         every ConfirmDialog view on 2026-08-01. -->

    <!-- TASK-128 — deactivation fires from inside a form the admin came to
         edit, so it is confirmed rather than one-click. -->
    <ConfirmDialog
      v-model:show="showDeactivateConfirm"
      variant="danger"
      title="ปิดใช้งานตัวแทน"
      :body="
        pendingDeactivate
          ? `ปิดใช้งาน ${pendingDeactivate.name} — จะเข้าสู่ระบบไม่ได้ทันที แต่ประวัติการขาย ค่าคอมมิชชั่น และ XP ยังอยู่ครบ และเปิดใช้งานคืนได้ภายหลัง`
          : ''
      "
      :busy="accountActionSaving"
      @confirm="confirmDeactivate"
    />

    <!-- TASK-066 — replaces the old window.confirm() for grant-cert-without-exam. -->
    <ConfirmDialog
      v-model:show="showGrantConfirm"
      variant="primary"
      :title='pendingGrant ? `อนุมัติใบรับรอง "${pendingGrant.tier.name}"` : ""'
      :body='pendingGrant ? `อนุมัติให้ ${pendingGrant.subject.name} ผ่านใบรับรอง "${pendingGrant.tier.name}" โดยไม่ต้องสอบจริง ยืนยันหรือไม่?` : ""'
      :busy="grantingTierKey !== null"
      @confirm="confirmGrantCertification"
    />
  </div>
</template>
