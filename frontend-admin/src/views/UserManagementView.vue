<script setup lang="ts">
/**
 * "จัดการผู้ใช้ระบบ" — who can get in, with what rights (TASK-259, human
 * request 2026-09-05: "ทำหน้าจัดการ user ระบบ และให้ทำงานได้จริงตามสิทธิ์ที่
 * กำหนดไว้").
 *
 * ── WHY A SCREEN, WHEN "จัดการตัวแทน" ALREADY EDITS USERS ──
 *
 * The agent roster is about SELLING: certifications, uplines, commission,
 * recruitment. Account administration lives there only because that is where
 * the rows happened to be, and it shows: a Company Admin is filed among the
 * agents, "who has admin rights?" cannot be asked, and promoting an existing
 * agent was impossible from any form (TASK-130 hid the option, so in practice
 * it meant editing the database by hand).
 *
 * This screen asks a different question — who has access, at what level, and
 * are those accounts still in use — and it lives in ตั้งค่าระบบ where the
 * human asked for it. The roster is untouched; nothing was moved out of it.
 *
 * ── "ให้ทำงานได้จริงตามสิทธิ์ที่กำหนดไว้" ──
 *
 * Every button on every row is rendered from `permissions`, which the SERVER
 * computes per row by asking UserPolicy — the same Policy that will be
 * consulted when the button is pressed. This screen deliberately does not
 * re-derive "super admin, or same company, and never yourself": a second
 * copy of an authorization rule is how a button ends up offered and then
 * refused, or hidden from somebody who was allowed. When the rule changes,
 * this screen changes with it and nobody has to remember it exists.
 *
 * Two rules are worth stating out loud because they are invisible otherwise:
 *   • SUPER ADMINS ARE NOT LISTED, for anybody. UserPolicy::view() refuses
 *     them as targets and /users excludes them — they are managed from the
 *     command line and audited there (`admin:create-super`). The notice says
 *     so, so an empty admin list is never read as "we have no platform
 *     admin".
 *   • YOU CANNOT DEACTIVATE YOURSELF. The server refuses it; here the button
 *     is simply not rendered, because a refusal after the click reads as a
 *     bug rather than a guard rail.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { RouterLink } from 'vue-router'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const activeCompany = useActiveCompanyStore()

interface RowPermissions {
  update: boolean
  deactivate: boolean
  restore: boolean
  move_company: boolean
}
/*
 * 2026-09-10 — `voucher_staff` (human: "การกระจายสิทธิ์ให้ Company Admin และ
 * Admin ที่ได้สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงานคนละหน้าที่กัน").
 *
 * TWO WAYS to hold the redemption right, because they answer two different
 * questions:
 *   • the ROLE, for somebody whose whole job is the counter — they sign in and
 *     see that one screen and nothing else;
 *   • the GRANT, for a Company Admin who also works the counter — they keep
 *     everything else they had.
 *
 * Being a Company Admin no longer carries it by itself. That is the point: it
 * was a permission nobody chose to give and nobody could take away.
 */
/*
 * 2026-09-17 — `company_partner` is LISTED here but NOT CREATED here.
 *
 * The three above are people inside a tenant, looking at their own company's
 * data. A partner is a SUPPLIER's login: it carries `supplier_id` and NO
 * `company_id`, and it reads orders belonging to tenants other than the
 * creator's, reached through `products.supplier_id`.
 *
 * Two consequences for this screen:
 *
 *   1. It needs a SUPPLIER, which this screen has no picker for and should
 *      not grow one — a supplier is not a kind of user, it is a counterparty
 *      with a deal, a GP and a bank account.
 *   2. The server now refuses a partner account from a Company Admin
 *      entirely, because issuing one would hand somebody a window onto every
 *      company that sells that supplier's products.
 *
 * So the account is created on the supplier's own page (ตั้งค่าระบบ →
 * จัดการคู่ค้า → บัญชีผู้ใช้), where the supplier is already in front of the
 * person making it. The role stays in this union so existing partner accounts
 * still render and can still be filtered for.
 *
 * It was briefly creatable here, against a company flagged `is_supplier`. That
 * whole model was rejected — see CompanyManagementView's note.
 */
/*
 * 2026-09-18 — `super_admin` joins (human: "เพิ่มสิทธิ์ Super Admin ในการเพิ่ม
 * และแก้ไข user"). It is the one role in this union that belongs to NO
 * company, and the one that only a Super Admin can see, grant or take away
 * (UserPolicy::view()'s own note says why the old blanket refusal changed).
 *
 * `agent` stays in the union and LEAVES the screen. Same day, same request:
 * "ระบบตอนนี้เอา User ทุกคนทั้ง Agent และมารวมกัน แต่ในการตั้งค่า ความต้องการ
 * จริงคือ Admin เท่านั้น". The rows are filtered out server-side
 * (`?admins_only=1`), not hidden here — this list paginates, so filtering in
 * the browser would show page 1 of everybody and call it the admins. The
 * type keeps the case because a demotion still produces one, and the row has
 * to render for the instant before it disappears from this list.
 */
type ManageableRole = 'agent' | 'company_admin' | 'voucher_staff' | 'company_partner' | 'super_admin'

/** Ability::VoucherRedeem — the only ability this screen may hand out today
 *  (UserAbilityController::GRANTABLE holds the authoritative list). */
const VOUCHER_REDEEM = 'voucher.redeem'

interface UserRow {
  id: number
  name: string
  // TASK-246 — the EDITABLE halves. `name` is the server's joined display
  // string; a form that wrote back into it would have to guess where the
  // first name ends, which is a guess that gets somebody's name wrong.
  first_name: string
  last_name: string
  phone: string | null
  email: string
  role: ManageableRole
  // Abilities granted to this PERSON by name, separate from what the role
  // holds — see the type comment above. Optional because only a caller that
  // asks for them (`with_abilities=1`) receives the key at all.
  granted_abilities?: string[]
  company: { id: number; name: string } | null
  is_active: boolean
  is_team_leader: boolean
  /**
   * 2026-09-15 — this row is the COMPANY's own seat in its hierarchy, not a
   * person (ขั้นตอนที่ 4.2 of the commission screen).
   *
   * Every action button on this screen is already gated on
   * `permissions.*`, and UserPolicy answers false for this row on all three
   * — so the flag is here for ONE reason: to say what the row is. A row with
   * a company's name and no buttons, and no explanation, reads as a
   * permission bug.
   */
  is_commission_house_account?: boolean
  last_login_at: string | null
  created_at: string
  permissions: RowPermissions
}

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback

  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}

const rows = ref<UserRow[]>([])
const loading = ref(false)
const loadedOnce = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const filters = ref({ role: '' as '' | ManageableRole, q: '', include_inactive: true })

/**
 * `include_inactive` defaults ON, unlike the agent roster.
 *
 * The question this screen exists for is "who can get into the system" — and
 * a closed account is part of that answer ("was it closed? when? by whom?").
 * Hiding them by default would make a deactivated admin invisible on the one
 * screen meant to account for admins.
 */
function queryString(): string {
  const params = new URLSearchParams()
  params.set('with_permissions', '1')
  params.set('with_last_login', '1')
  // "Who may redeem a voucher?" has to be answerable at a glance. One extra
  // eager load for the page, not one query per row — see UserController.
  params.set('with_abilities', '1')
  /*
   * 2026-09-18 — the screen's whole subject, stated to the server.
   *
   * Not a client-side `.filter()`: this endpoint paginates at 15, so
   * narrowing here would mean "page 1 of everybody, minus its agents",
   * which is not page 1 of the admins and is not what the count below
   * would be describing either.
   */
  params.set('admins_only', '1')
  if (filters.value.include_inactive) params.set('include_inactive', '1')
  if (filters.value.role) params.set('role', filters.value.role)
  if (filters.value.q.trim()) params.set('q', filters.value.q.trim())
  if (isSuperAdmin.value && activeCompany.companyId) params.set('company_id', String(activeCompany.companyId))

  return params.toString()
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: UserRow[] }>(`/users?${queryString()}`)
    rows.value = res.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดรายชื่อผู้ใช้ไม่สำเร็จ')
  } finally {
    loading.value = false
    loadedOnce.value = true
  }
}

// ── Role change ────────────────────────────────────────────────────────
const pendingRoleChange = ref<{ user: UserRow; role: ManageableRole } | null>(null)

function askRoleChange(user: UserRow, role: ManageableRole) {
  pendingRoleChange.value = { user, role }
}

/** What the confirm dialog has to say BEFORE the click, per target role. */
function roleChangeWarning(user: UserRow, role: ManageableRole): string {
  if (role === 'company_admin') {
    return `${user.name} จะเข้าถึงข้อมูลทั้งบริษัทได้ — ลูกค้า คำสั่งซื้อ ค่าแนะนำ และการจัดการผู้ใช้ · ระบบจะถอนการเข้าใช้งานเดิมของเขาทั้งหมด เขาต้องเข้าสู่ระบบใหม่`
  }
  if (role === 'voucher_staff') {
    return `${user.name} จะเข้าได้เฉพาะหน้า "ตัดสิทธิ์บัตรกำนัล" หน้าเดียว — คำสั่งซื้อ ลูกค้า ค่าแนะนำ และรายชื่อผู้ใช้จะเข้าไม่ได้ทั้งหมด · ระบบจะถอนการเข้าใช้งานเดิมของเขาทั้งหมด`
  }
  if (role === 'super_admin') {
    return `${user.name} จะกลายเป็น Super Admin — เห็นและแก้ไขข้อมูลได้ทุกบริษัทบนแพลตฟอร์ม รวมถึงสร้างและถอดสิทธิ์ Super Admin คนอื่น\nบัญชีนี้จะไม่สังกัดบริษัทใดอีกต่อไป (ตอนนี้อยู่ ${user.company?.name ?? '—'})\nระบบจะถอนการเข้าใช้งานเดิมของเขาทั้งหมด เขาต้องเข้าสู่ระบบใหม่`
  }
  if (role === 'agent') {
    return `${user.name} จะกลับไปเห็นเฉพาะข้อมูลของตัวเอง และจะถูกถอนการเข้าใช้งานเดิมทั้งหมดทันที\nหลังบันทึก แถวนี้จะหายจากหน้านี้ — บัญชีสมาชิกจัดการที่หน้า จัดการตัวแทน`
  }
  if (role === 'company_partner') {
    return `${user.name} จะกลายเป็นบัญชีคู่ค้า — เห็นเฉพาะคำสั่งซื้อของสินค้าที่คู่ค้ารายนี้เป็นผู้จัดหา (รวมชื่อและที่อยู่ลูกค้าสำหรับสินค้าที่ต้องจัดส่ง) ยอดค้างรับ และหน้าตัดสิทธิ์บัตรกำนัล · ระบบจะถอนการเข้าใช้งานเดิมของเขาทั้งหมด`
  }

  return `${user.name} จะกลับไปเห็นเฉพาะข้อมูลของตัวเอง และจะถูกถอนการเข้าใช้งานเดิมทั้งหมดทันที`
}

/**
 * Confirmed, never immediate — and the wording says what it costs.
 *
 * Changing a role revokes every token that person is holding (TASK-238), so
 * a promotion signs them out of whatever they had open. That is correct, and
 * it is also the kind of thing an admin should be told BEFORE clicking, not
 * discover from a colleague.
 */
async function confirmRoleChange() {
  const pending = pendingRoleChange.value
  if (!pending) return

  try {
    await api.put(`/users/${pending.user.id}`, { role: pending.role })
    successMessage.value = `${pending.user.name} เป็น${roleLabel(pending.role)}แล้ว`
    await load()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'เปลี่ยนบทบาทไม่สำเร็จ')
  } finally {
    pendingRoleChange.value = null
  }
}

// ── Super Admin, granted ───────────────────────────────────────────────
/*
 * 2026-09-18. Its own flow rather than another askRoleChange() target, for
 * two reasons that both come from the owner's answer on the guards:
 *
 *   1. It is TYPE-TO-CONFIRM. ConfirmDialog's `confirmPhrase` turns the
 *      dialog into one that cannot be dismissed by a click landing in the
 *      wrong place — the same treatment the platform-wide catalogue hide
 *      already gets, for a click with a larger blast radius.
 *   2. The phrase is the person's EMAIL, not a fixed word. "ยืนยัน" typed
 *      into the wrong row's dialog still grants the platform to the wrong
 *      person; an address does not transfer between rows.
 */
const pendingSuperAdminGrant = ref<UserRow | null>(null)
const grantingSuperAdmin = ref(false)

async function confirmSuperAdminGrant(): Promise<void> {
  const user = pendingSuperAdminGrant.value
  if (!user) return

  grantingSuperAdmin.value = true
  errorMessage.value = ''
  try {
    await api.put(`/users/${user.id}`, { role: 'super_admin' })
    successMessage.value = `${user.name} เป็น Super Admin แล้ว — ไม่สังกัดบริษัทใดอีกต่อไป และถูกถอนการเข้าใช้งานเดิมทั้งหมด`
    pendingSuperAdminGrant.value = null
    await load()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ให้สิทธิ์ Super Admin ไม่สำเร็จ')
    pendingSuperAdminGrant.value = null
  } finally {
    grantingSuperAdmin.value = false
  }
}

// ── Super Admin, taken back ────────────────────────────────────────────
/*
 * A panel rather than a dialog, because this one has to ASK something:
 * WHICH COMPANY the account lands in.
 *
 * A Super Admin has no company_id. Take the role away without naming a
 * company and the column stays null — and TenantScope is fail-closed, so the
 * demoted admin logs in perfectly well and finds an empty system with
 * nothing on screen to explain it. The server refuses that request
 * (UpdateUserRequest), and this is the form that makes the refusal
 * unreachable rather than something to run into.
 *
 * The typed confirmation is here too, inline — ConfirmDialog has no slot for
 * a picker, and splitting the question across two modals would mean
 * confirming before knowing what was being confirmed.
 */
const demoting = ref<UserRow | null>(null)
const demoteCompanyId = ref<number | ''>('')
const demoteTyped = ref('')
const demoteError = ref('')
const savingDemote = ref(false)

function openDemote(user: UserRow): void {
  demoting.value = user
  demoteCompanyId.value = ''
  demoteTyped.value = ''
  demoteError.value = ''
  activeCompany.loadCompanies()
}

/** Trimmed — a trailing space from copy-paste is not a different answer. */
const demotePhraseSatisfied = computed(
  () => demoteTyped.value.trim() !== '' && demoteTyped.value.trim() === demoting.value?.email.trim(),
)

async function submitDemote(): Promise<void> {
  const user = demoting.value
  if (!user || demoteCompanyId.value === '' || !demotePhraseSatisfied.value) return

  savingDemote.value = true
  demoteError.value = ''
  try {
    // `company_id` travels WITH the role. The server requires it on exactly
    // this path and prohibits it everywhere else — see UpdateUserRequest.
    await api.put(`/users/${user.id}`, { role: 'company_admin', company_id: demoteCompanyId.value })
    const target = activeCompany.companies.find((c) => c.id === demoteCompanyId.value)
    successMessage.value = `ถอดสิทธิ์ Super Admin ของ ${user.name} แล้ว — เป็นผู้ดูแลบริษัท ${target?.name ?? `#${demoteCompanyId.value}`}`
    demoting.value = null
    await load()
  } catch (e) {
    demoteError.value = apiErrorMessage(e, 'ถอดสิทธิ์ไม่สำเร็จ')
  } finally {
    savingDemote.value = false
  }
}

// ── Deactivate / restore ───────────────────────────────────────────────
const pendingDeactivate = ref<UserRow | null>(null)

async function confirmDeactivate() {
  const user = pendingDeactivate.value
  if (!user) return

  try {
    await api.delete(`/users/${user.id}`)
    successMessage.value = `ปิดบัญชี ${user.name} แล้ว — เข้าระบบไม่ได้ทันที`
    await load()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ปิดบัญชีไม่สำเร็จ')
  } finally {
    pendingDeactivate.value = null
  }
}

async function restore(user: UserRow) {
  try {
    await api.post(`/users/${user.id}/restore`)
    successMessage.value = `กู้คืนบัญชี ${user.name} แล้ว`
    await load()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'กู้คืนบัญชีไม่สำเร็จ')
  }
}

// ── Reset password ─────────────────────────────────────────────────────
const resetting = ref<UserRow | null>(null)
const newPassword = ref('')
const resetError = ref('')

function askReset(user: UserRow) {
  resetting.value = user
  newPassword.value = ''
  resetError.value = ''
}

/**
 * The admin types the password; it is never generated here and never shown
 * back afterwards. There is no email system in this product (see
 * StoreUserRequest), so a temporary password is handed over in person or by
 * chat — and a value this screen invented would be one nobody chose and one
 * the admin would have to copy from a toast before it disappeared.
 */
async function submitReset() {
  const user = resetting.value
  if (!user) return
  if (newPassword.value.length < 8) {
    resetError.value = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัว มีพิมพ์ใหญ่ พิมพ์เล็ก และตัวเลข'

    return
  }

  try {
    await api.post(`/users/${user.id}/reset-password`, { password: newPassword.value })
    successMessage.value = `ตั้งรหัสผ่านใหม่ให้ ${user.name} แล้ว — ระบบถอนการเข้าใช้งานเดิมทั้งหมดของบัญชีนี้`
    resetting.value = null
    newPassword.value = ''
  } catch (e) {
    resetError.value = apiErrorMessage(e, 'ตั้งรหัสผ่านใหม่ไม่สำเร็จ')
  }
}

// ── Create a system user ───────────────────────────────────────────────
/*
 * TASK-246 — the gap that made this screen odd to use: a page called
 * "จัดการผู้ใช้ระบบ" that could change and close accounts but not open one.
 * Adding an admin meant the agent roster's own form (which hides the
 * company_admin option, TASK-130) or the database by hand.
 *
 * The password is TYPED BY THE ADMIN and never generated here, for the same
 * reason as the reset form below: there is no email in this product, so a
 * temporary password is handed over in person — and one this screen invented
 * would be a value nobody chose, shown once in a toast and gone.
 */
const showCreate = ref(false)
const createForm = ref({ first_name: '', last_name: '', email: '', password: '', role: 'company_admin' as ManageableRole })
const createError = ref('')
const creating = ref(false)
/*
 * 2026-09-18 — the create form's own type-to-confirm step, for one role.
 *
 * Creating a Company Admin is an everyday act and stays a single click.
 * Creating a Super Admin hands somebody every company on the platform, so it
 * gets the same second gate the row action does — and the phrase is the
 * address just typed into the form, which is the one thing on screen that
 * identifies the account being made.
 */
const pendingCreateSuperAdmin = ref(false)
const creatingSuperAdmin = computed(() => createForm.value.role === 'super_admin')

/**
 * StoreUserRequest REQUIRES company_id from a Super Admin and PROHIBITS it
 * from anybody else — there is nothing to infer for someone who belongs to no
 * company. So in "ทุกบริษัท" the answer is genuinely missing, and the form
 * says which company it is about to create in rather than picking one.
 */
/*
 * 2026-09-18 — …unless the account being created is a Super Admin, who
 * belongs to no company at all. StoreUserRequest PROHIBITS `company_id` on
 * that path, so "pick a company first" would be demanding a value the server
 * would then reject.
 */
const createCompanyBlocked = computed(
  () => isSuperAdmin.value && activeCompany.companyId === null && !creatingSuperAdmin.value,
)
const createCompanyName = computed(() => activeCompany.companyName ?? auth.user?.company?.name ?? '')

function openCreate(): void {
  createForm.value = { first_name: '', last_name: '', email: '', password: '', role: 'company_admin' }
  createError.value = ''
  pendingCreateSuperAdmin.value = false
  showCreate.value = true
}

/** The gate in front of doCreate() — one role has to be typed for. */
function submitCreate(): void {
  if (creatingSuperAdmin.value) {
    if (createForm.value.email.trim() === '') {
      createError.value = 'กรอกอีเมลก่อน — ต้องพิมพ์อีเมลนี้ยืนยันอีกครั้งก่อนสร้าง Super Admin'

      return
    }
    createError.value = ''
    pendingCreateSuperAdmin.value = true

    return
  }

  void doCreate()
}

async function doCreate(): Promise<void> {
  pendingCreateSuperAdmin.value = false

  if (createCompanyBlocked.value) {
    createError.value = 'เลือกบริษัทที่แถบด้านบนก่อน จึงจะสร้างผู้ใช้ได้'

    return
  }
  if (createForm.value.password.length < 8) {
    createError.value = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัว มีพิมพ์ใหญ่ พิมพ์เล็ก และตัวเลข'

    return
  }

  creating.value = true
  createError.value = ''
  try {
    const created = await api.post<{ data: UserRow }>('/users', {
      ...createForm.value,
      // Only a Super Admin may send it, and must; a Company Admin sending it
      // is a 422 by rule, not an oversight.
      //
      // 2026-09-18 — and a Super Admin being CREATED takes none: the role
      // has no tenant, and StoreUserRequest prohibits the key rather than
      // ignoring it, so sending null here would be a 422 too.
      ...(isSuperAdmin.value && !creatingSuperAdmin.value ? { company_id: activeCompany.companyId } : {}),
    })
    // The password is deliberately absent from this sentence — it was typed a
    // second ago by the person reading it, and repeating a live credential on
    // screen is how it ends up in a screenshot.
    successMessage.value = `สร้างบัญชี ${created.data.name} (${roleLabel(created.data.role)}) แล้ว — ส่งรหัสผ่านให้เจ้าตัวโดยตรง`
    showCreate.value = false
    createForm.value.password = ''
    await load()
  } catch (e) {
    createError.value = apiErrorMessage(e, 'สร้างผู้ใช้ไม่สำเร็จ')
  } finally {
    creating.value = false
  }
}

// ── Edit name / email / phone ──────────────────────────────────────────
/*
 * TASK-246 — the screen could change somebody's ROLE but not their name, so a
 * typo in an email (the thing they log in with) had no fix here at all.
 *
 * Only these four fields. Everything else UpdateUserRequest accepts —
 * uplines, team-leader flag, bank details, identity documents — belongs to
 * the agent roster, which is about selling; duplicating them here would make
 * two screens that disagree about who owns a field.
 */
const editing = ref<UserRow | null>(null)
const editForm = ref({ first_name: '', last_name: '', email: '', phone: '' })
const editError = ref('')
const savingEdit = ref(false)

function openEdit(user: UserRow): void {
  editing.value = user
  editForm.value = {
    first_name: user.first_name,
    last_name: user.last_name,
    email: user.email,
    phone: user.phone ?? '',
  }
  editError.value = ''
}

async function submitEdit(): Promise<void> {
  const user = editing.value
  if (!user) return

  savingEdit.value = true
  editError.value = ''
  try {
    await api.put(`/users/${user.id}`, {
      first_name: editForm.value.first_name,
      last_name: editForm.value.last_name,
      email: editForm.value.email,
      // Empty means "no phone", which is a value; '' would fail the string
      // rule, so it is sent as the null the column actually holds.
      phone: editForm.value.phone.trim() === '' ? null : editForm.value.phone.trim(),
    })
    successMessage.value = `แก้ไขข้อมูลของ ${user.name} แล้ว`
    editing.value = null
    await load()
  } catch (e) {
    editError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingEdit.value = false
  }
}

// ── Move to another company ────────────────────────────────────────────
/*
 * TASK-246 — `move_company` has been in the permissions payload since
 * TASK-259 and nothing on this screen read it. It is Super-Admin-only and
 * refused against another Super Admin (UserPolicy::move).
 *
 * It is a heavier action than it looks: the account's whole tenant changes,
 * so what they can see changes with it. Hence a confirm step that names both
 * companies rather than a bare dropdown that saves on change.
 */
const moving = ref<UserRow | null>(null)
const moveCompanyId = ref<number | ''>('')
const moveError = ref('')
const savingMove = ref(false)

function openMove(user: UserRow): void {
  moving.value = user
  moveCompanyId.value = ''
  moveError.value = ''
  activeCompany.loadCompanies()
}

/** Every company except the one they are already in. */
const moveTargets = computed(() => activeCompany.companies.filter((c) => c.id !== moving.value?.company?.id))

async function submitMove(): Promise<void> {
  const user = moving.value
  if (!user || moveCompanyId.value === '') return

  savingMove.value = true
  moveError.value = ''
  try {
    await api.post(`/users/${user.id}/move-company`, { company_id: moveCompanyId.value })
    const target = activeCompany.companies.find((c) => c.id === moveCompanyId.value)
    successMessage.value = `ย้าย ${user.name} ไปบริษัท ${target?.name ?? `#${moveCompanyId.value}`} แล้ว`
    moving.value = null
    await load()
  } catch (e) {
    moveError.value = apiErrorMessage(e, 'ย้ายบริษัทไม่สำเร็จ')
  } finally {
    savingMove.value = false
  }
}

function formatDateTime(iso: string | null): string {
  if (iso === null) return '—'

  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

function roleLabel(role: string): string {
  if (role === 'super_admin') return 'Super Admin'
  if (role === 'company_admin') return 'ผู้ดูแลบริษัท'
  if (role === 'voucher_staff') return 'พนักงานหน้าร้าน'
  if (role === 'company_partner') return 'คู่ค้า'

  return 'สมาชิก'
}

// ── Redemption right, granted per person ───────────────────────────────
/*
 * 2026-09-10. The grant is edited as the WHOLE set the person holds, not as
 * "add this one": the endpoint replaces it, so two admins toggling at once
 * cannot leave somebody with a combination neither of them chose.
 *
 * The role's own abilities are deliberately not folded in here. A front-desk
 * account holds the right by role and has no grant row — showing it as a
 * ticked box would offer to revoke something this screen cannot revoke.
 */
function holdsRedemption(user: UserRow): boolean {
  return user.role === 'voucher_staff' || (user.granted_abilities ?? []).includes(VOUCHER_REDEEM)
}

const grantingTo = ref<UserRow | null>(null)
const grantRedeem = ref(false)
const grantError = ref('')
const savingGrant = ref(false)

function openGrants(user: UserRow): void {
  grantingTo.value = user
  grantRedeem.value = (user.granted_abilities ?? []).includes(VOUCHER_REDEEM)
  grantError.value = ''
}

async function submitGrants(): Promise<void> {
  const user = grantingTo.value
  if (!user) return

  savingGrant.value = true
  grantError.value = ''
  try {
    await api.put(`/users/${user.id}/abilities`, {
      abilities: grantRedeem.value ? [VOUCHER_REDEEM] : [],
    })
    successMessage.value = grantRedeem.value
      ? `${user.name} ตัดสิทธิ์บัตรกำนัลได้แล้ว`
      : `ยกเลิกสิทธิ์ตัดบัตรกำนัลของ ${user.name} แล้ว`
    grantingTo.value = null
    await load()
  } catch (e) {
    grantError.value = apiErrorMessage(e, 'บันทึกสิทธิ์ไม่สำเร็จ')
  } finally {
    savingGrant.value = false
  }
}

const adminCount = computed(() => rows.value.filter((r) => r.role === 'company_admin' && r.is_active).length)
// 2026-09-18 — countable for the same reason the redemption right is: a
// platform-wide privilege nobody can count is one nobody audits.
const superAdminCount = computed(() => rows.value.filter((r) => r.role === 'super_admin' && r.is_active).length)
// A right nobody can count is a right nobody audits — see holdsRedemption().
const redeemerCount = computed(() => rows.value.filter((r) => r.is_active && holdsRedemption(r)).length)

watch(() => activeCompany.companyId, () => load())
onMounted(() => {
  load()
  if (isSuperAdmin.value) activeCompany.loadCompanies()
})
</script>

<template>
  <div class="px-4 lg:px-6 pb-4 lg:pb-6 w-full" style="font-family: Kanit, sans-serif;">
    <HeroHeader
      icon="users"
      title="จัดการผู้ใช้ระบบ"
      subtitle="ใครเข้าระบบได้บ้าง สิทธิ์ระดับไหน และยังใช้งานอยู่หรือไม่"
      accent-color="brand"
      storage-key="user-management"
    />

    <!-- The invisible rules, said once, where they are read.
         2026-09-18 — rewritten end to end. It used to say Super Admin was
         command-line-only and that this page also managed สมาชิก; both
         stopped being true on the same day and a note that lies about the
         screen is worse than no note. -->
    <div class="mt-4 mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500" data-test="screen-note">
      หน้านี้จัดการ<strong>ผู้ดูแลระบบเท่านั้น</strong> — Super Admin, ผู้ดูแลบริษัท, พนักงานหน้าร้าน และคู่ค้า ·
      <strong>ไม่แสดงสมาชิก (Agent)</strong> เพราะจัดการที่หน้า
      <RouterLink :to="{ name: 'agent-roster' }" class="text-brand-700 font-bold hover:underline">จัดการตัวแทน</RouterLink>
      แทน · ปุ่มแต่ละแถวขึ้นตาม<strong>สิทธิ์จริงของคุณ</strong> ที่เซิร์ฟเวอร์เป็นคนตอบ
      คุณจะปิดบัญชีหรือถอดสิทธิ์ตัวเองไม่ได้ และ <strong>Super Admin คนสุดท้ายจะถูกถอดหรือปิดบัญชีไม่ได้</strong>
    </div>

    <div class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-xs font-bold text-slate-500 mb-1">บทบาท</label>
        <select v-model="filters.role" data-test="role-filter" class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white min-w-[12rem]" @change="load">
          <option value="">ทุกบทบาท</option>
          <!-- 2026-09-18 — สมาชิก is gone from this list, not merely
               unselected: the endpoint no longer returns agents here
               (?admins_only=1), so the option would filter an empty set and
               read as "there are no agents". Super Admin takes its place,
               and only a Super Admin is offered it — nobody else can see
               such a row at all. -->
          <option v-if="isSuperAdmin" value="super_admin">Super Admin</option>
          <option value="company_admin">ผู้ดูแลบริษัท</option>
          <option value="voucher_staff">พนักงานหน้าร้าน</option>
          <option value="company_partner">คู่ค้า</option>
        </select>
      </div>
      <div class="flex-1 min-w-[16rem]">
        <label class="block text-xs font-bold text-slate-500 mb-1">ค้นหา (ชื่อ / อีเมล / เบอร์)</label>
        <input v-model="filters.q" data-test="user-search" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" @keyup.enter="load" />
      </div>
      <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer pb-2">
        <input v-model="filters.include_inactive" type="checkbox" class="rounded border-slate-300" @change="load" />
        แสดงบัญชีที่ปิดแล้วด้วย
      </label>
      <button class="btn-secondary" @click="load">
        ค้นหา
      </button>
      <!-- TASK-246 — the action a screen called "จัดการผู้ใช้ระบบ" has to
           have. Until now adding an admin meant the agent roster's form
           (which hides the ผู้ดูแลบริษัท option) or the database by hand. -->
      <button class="btn-primary" data-test="open-create" @click="openCreate">
        + เพิ่มผู้ใช้
      </button>
    </div>

    <div v-if="successMessage" class="mb-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-800" data-test="success">{{ successMessage }}</div>
    <div v-if="errorMessage" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700" data-test="error">{{ errorMessage }}</div>

    <LoadingSkeleton v-if="loading && !loadedOnce" type="list" :rows="5" />
    <template v-else>
      <EmptyState v-if="!rows.length" icon="users" title="ไม่พบผู้ใช้ตามเงื่อนไขนี้" />
      <div v-else>
        <p class="mb-2 text-xs text-slate-500">
          <span v-if="isSuperAdmin" data-test="super-admin-count">Super Admin ที่ใช้งานอยู่ <strong>{{ superAdminCount }}</strong> คน · </span>
          ผู้ดูแลบริษัทที่ใช้งานอยู่ <strong>{{ adminCount }}</strong> คน ·
          <span data-test="redeemer-count">ตัดสิทธิ์บัตรกำนัลได้ <strong>{{ redeemerCount }}</strong> คน</span> ·
          ทั้งหมด {{ rows.length }} รายการ
        </p>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white/95">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left">
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">ชื่อ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">อีเมล</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">บทบาท</th>
                <th v-if="isSuperAdmin" class="px-4 py-2.5 text-xs font-bold text-slate-500">บริษัท</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 whitespace-nowrap">เข้าระบบล่าสุด</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">สถานะ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in rows" :key="user.id" class="border-b border-slate-50 last:border-0" :class="user.is_active ? '' : 'bg-slate-50/60'">
                <td class="px-4 py-2.5 font-bold text-slate-900">
                  {{ user.name }}
                  <span v-if="user.id === auth.user?.id" class="ml-1 text-[10px] font-bold text-slate-400">(คุณ)</span>
                  <span v-if="user.is_team_leader" class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-500">หัวหน้าทีม</span>
                  <!-- Says what the row IS. The buttons are already gone
                       (UserPolicy refuses all three), and a row with a
                       company's name, no controls and no label reads as a
                       permission bug rather than as an answer. -->
                  <span
                    v-if="user.is_commission_house_account"
                    class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800"
                    :data-test="`house-account-badge-${user.id}`"
                    title="บัญชีที่บริษัทใช้รับค่าแนะนำหัวหน้าทีม — ตั้งค่าที่ขั้นตอนที่ 4.2 ของหน้าค่าแนะนำ"
                  >
                    บัญชีบริษัท
                  </span>
                </td>
                <td class="px-4 py-2.5 text-slate-600">{{ user.email }}</td>
                <td class="px-4 py-2.5">
                  <span
                    class="text-[11px] font-bold px-2 py-0.5 rounded-full"
                    :class="user.role === 'super_admin' ? 'bg-violet-100 text-violet-800' : user.role === 'company_admin' ? 'bg-brand-50 text-brand-700' : user.role === 'voucher_staff' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'"
                  >
                    {{ roleLabel(user.role) }}
                  </span>
                  <!-- The right that is GIVEN, shown next to the role that is
                       held — so "who can redeem?" is one glance down a column
                       rather than opening every person in turn. -->
                  <span
                    v-if="holdsRedemption(user)"
                    class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700"
                    data-test="redeem-badge"
                  >
                    ตัดบัตรกำนัลได้
                  </span>
                </td>
                <!-- 2026-09-18 — a Super Admin's blank company is a FACT
                     about the role, not missing data. "—" alone reads as
                     "nobody filled this in". -->
                <td v-if="isSuperAdmin" class="px-4 py-2.5 text-slate-500 text-xs">
                  <span v-if="user.role === 'super_admin'" class="text-slate-400">ทุกบริษัท (ไม่สังกัด)</span>
                  <span v-else>{{ user.company?.name ?? '—' }}</span>
                </td>
                <!-- Null is spelled out. "—" alone would be read as "never",
                     and the true statement is narrower than that. -->
                <td class="px-4 py-2.5 text-xs whitespace-nowrap" :class="user.last_login_at ? 'text-slate-500' : 'text-slate-400'">
                  {{ user.last_login_at ? formatDateTime(user.last_login_at) : 'ไม่พบบันทึกการเข้าระบบ' }}
                </td>
                <td class="px-4 py-2.5">
                  <span class="text-xs font-bold" :class="user.is_active ? 'text-emerald-600' : 'text-slate-400'">
                    {{ user.is_active ? 'ใช้งาน' : 'ปิดบัญชี' }}
                  </span>
                </td>
                <td class="px-4 py-2.5">
                  <div class="flex items-center justify-end gap-1.5 flex-wrap">
                    <!-- Every button below is gated on the SERVER's answer for
                         this row, never on a rule re-derived here. -->
                    <!-- TASK-246 — a typo in the address somebody logs in
                         with had no fix on this screen at all. -->
                    <button
                      v-if="user.permissions.update"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="edit-user"
                      @click="openEdit(user)"
                    >
                      แก้ไขข้อมูล
                    </button>
                    <!-- 2026-09-18 — ให้เป็นผู้ดูแล is gone from this screen.
                         It only ever appeared on an `agent` row, and agents
                         are no longer listed here; promoting a salesperson
                         to admin now starts where that person is, on
                         จัดการตัวแทน. A button that can never render is dead
                         code that reads as a missing feature. -->
                    <button
                      v-if="user.permissions.update && user.is_active && user.role === 'company_admin'"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="demote"
                      @click="askRoleChange(user, 'agent')"
                    >
                      ถอดสิทธิ์ผู้ดูแล
                    </button>
                    <!-- 2026-09-18 — the platform-owner role, both
                         directions. Offered only to a Super Admin, because
                         nobody else can see such a row in the first place
                         (UserPolicy::view) — `permissions.update` alone
                         would be the server's answer about a DIFFERENT
                         question, so the role check is explicit. -->
                    <button
                      v-if="isSuperAdmin && user.permissions.update && user.is_active && user.role !== 'super_admin'"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-violet-200 text-violet-700 hover:bg-violet-50"
                      data-test="make-super-admin"
                      @click="pendingSuperAdminGrant = user"
                    >
                      ให้เป็น Super Admin
                    </button>
                    <button
                      v-if="user.permissions.update && user.is_active && user.role === 'super_admin' && user.id !== auth.user?.id"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-violet-200 text-violet-700 hover:bg-violet-50"
                      data-test="unmake-super-admin"
                      @click="openDemote(user)"
                    >
                      ถอดสิทธิ์ Super Admin
                    </button>
                    <!-- 2026-09-10 — the two ways to hold the redemption
                         right, one button each (see ManageableRole above).
                         The ROLE, for somebody whose whole job is the
                         counter. -->
                    <!-- …and never on a Super Admin row: that change is a
                         demotion, which the server refuses without a company
                         to land in (UpdateUserRequest). ถอดสิทธิ์ Super Admin
                         above is the path that asks for one. -->
                    <button
                      v-if="user.permissions.update && user.is_active && user.role !== 'voucher_staff' && user.role !== 'super_admin'"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="make-voucher-staff"
                      @click="askRoleChange(user, 'voucher_staff')"
                    >
                      ให้เป็นพนักงานหน้าร้าน
                    </button>
                    <button
                      v-if="user.permissions.update && user.is_active && user.role === 'voucher_staff'"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="unmake-voucher-staff"
                      @click="askRoleChange(user, 'agent')"
                    >
                      เลิกเป็นพนักงานหน้าร้าน
                    </button>
                    <!-- The GRANT, for a Company Admin who also works the
                         counter and keeps everything else they had. -->
                    <button
                      v-if="user.permissions.update && user.is_active && user.role === 'company_admin'"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-emerald-200 text-emerald-700 hover:bg-emerald-50"
                      data-test="edit-abilities"
                      @click="openGrants(user)"
                    >
                      สิทธิ์ตัดบัตรกำนัล
                    </button>
                    <button
                      v-if="user.permissions.update && user.is_active"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="reset-password"
                      @click="askReset(user)"
                    >
                      ตั้งรหัสผ่านใหม่
                    </button>
                    <!-- TASK-246 — `move_company` has been in the payload
                         since this screen was built and nothing read it. -->
                    <button
                      v-if="user.permissions.move_company && user.is_active"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="move-company"
                      @click="openMove(user)"
                    >
                      ย้ายบริษัท
                    </button>
                    <RouterLink
                      :to="{ name: 'activity-log', query: { actor: user.id } }"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="view-activity"
                    >
                      ดูกิจกรรม
                    </RouterLink>
                    <button
                      v-if="user.permissions.deactivate && user.is_active"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50"
                      data-test="deactivate"
                      @click="pendingDeactivate = user"
                    >
                      ปิดบัญชี
                    </button>
                    <button
                      v-if="user.permissions.restore && !user.is_active"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-emerald-200 text-emerald-700 hover:bg-emerald-50"
                      data-test="restore"
                      @click="restore(user)"
                    >
                      กู้คืนบัญชี
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Reset password. Inline rather than a modal component: it is one field
         and one button, and the sentence above it is the important part. -->
    <div v-if="resetting" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" data-test="reset-dialog">
      <div class="bg-white rounded-2xl p-5 w-full max-w-md">
        <p class="text-sm font-bold text-slate-900">ตั้งรหัสผ่านใหม่ให้ {{ resetting.name }}</p>
        <p class="mt-1 text-xs text-slate-500">
          ระบบนี้ไม่มีการส่งอีเมล — คุณต้องแจ้งรหัสผ่านนี้ให้เจ้าตัวเอง
          และเมื่อบันทึกแล้ว <strong>การเข้าใช้งานเดิมทั้งหมดของบัญชีนี้จะถูกถอนทันที</strong>
        </p>
        <input
          v-model="newPassword"
          type="text"
          data-test="new-password"
          placeholder="อย่างน้อย 8 ตัว มีพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข"
          class="mt-3 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
        />
        <p v-if="resetError" class="mt-2 text-xs font-bold text-rose-600">{{ resetError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-secondary" @click="resetting = null">ยกเลิก</button>
          <button class="btn-primary" data-test="submit-reset" @click="submitReset">บันทึกรหัสผ่าน</button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :show="pendingRoleChange !== null"
      :title="`เปลี่ยนบทบาทเป็น${roleLabel(pendingRoleChange?.role ?? 'agent')}`"
      :body="pendingRoleChange ? roleChangeWarning(pendingRoleChange.user, pendingRoleChange.role) : ''"
      confirm-label="ยืนยัน"
      variant="primary"
      @confirm="confirmRoleChange"
      @cancel="pendingRoleChange = null"
    />

    <ConfirmDialog
      :show="pendingDeactivate !== null"
      title="ปิดบัญชีผู้ใช้"
      :body="`${pendingDeactivate?.name} จะเข้าระบบไม่ได้ทันที และการเข้าใช้งานที่ค้างอยู่จะถูกถอนทั้งหมด · ข้อมูลเดิมทั้งหมดยังอยู่ครบ และกู้คืนบัญชีได้ภายหลัง`"
      confirm-label="ปิดบัญชี"
      variant="danger"
      @confirm="confirmDeactivate"
      @cancel="pendingDeactivate = null"
    />

    <!-- 2026-09-18 — granting the platform. Type-to-confirm, and the phrase
         is the person's own address: "ยืนยัน" typed into the wrong row's
         dialog would still hand the platform to the wrong person. -->
    <ConfirmDialog
      :show="pendingSuperAdminGrant !== null"
      title="ให้สิทธิ์ Super Admin"
      :body="pendingSuperAdminGrant ? roleChangeWarning(pendingSuperAdminGrant, 'super_admin') : ''"
      :confirm-phrase="pendingSuperAdminGrant?.email ?? ''"
      :busy="grantingSuperAdmin"
      confirm-label="ให้สิทธิ์ Super Admin"
      cancel-label="ยกเลิก"
      variant="warning"
      size="md"
      @confirm="confirmSuperAdminGrant"
      @cancel="pendingSuperAdminGrant = null"
    />

    <!-- 2026-09-18 — creating one. Same gate as the row action above, on the
         address just typed into the form. -->
    <ConfirmDialog
      :show="pendingCreateSuperAdmin"
      title="สร้างบัญชี Super Admin"
      :body="`บัญชีใหม่นี้จะเห็นและแก้ไขข้อมูลได้ทุกบริษัทบนแพลตฟอร์ม และสร้างหรือถอดสิทธิ์ Super Admin คนอื่นได้\nบัญชีนี้จะไม่สังกัดบริษัทใด\nรหัสผ่านที่คุณพิมพ์ไว้จะไม่ถูกแสดงอีกหลังบันทึก — ส่งให้เจ้าตัวโดยตรง`"
      :confirm-phrase="createForm.email.trim()"
      :busy="creating"
      confirm-label="สร้าง Super Admin"
      cancel-label="กลับไปแก้ไข"
      variant="warning"
      size="md"
      @confirm="doCreate"
      @cancel="pendingCreateSuperAdmin = false"
    />

    <!-- 2026-09-18 — taking it back. A panel, not a dialog, because it has to
         ASK which company the account lands in: a demoted Super Admin with no
         company_id is filtered out of every query by fail-closed TenantScope
         — signs in fine, sees an empty system, is told nothing. -->
    <div v-if="demoting" class="fixed inset-0 z-50 bg-slate-900/40 flex items-center justify-center p-4" data-test="demote-dialog" @click.self="demoting = null">
      <div class="w-full max-w-md bg-white rounded-2xl p-5 shadow-xl">
        <p class="text-sm font-bold text-slate-900">ถอดสิทธิ์ Super Admin</p>
        <p class="mt-0.5 text-xs text-slate-400">{{ demoting.name }} · {{ demoting.email }}</p>
        <p class="mt-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-700">
          บัญชีนี้จะกลายเป็น <strong>ผู้ดูแลบริษัท</strong> ของบริษัทที่เลือกด้านล่าง
          เห็นเฉพาะข้อมูลของบริษัทนั้นบริษัทเดียว และจะถูกถอนการเข้าใช้งานเดิมทั้งหมดทันที
        </p>

        <label class="mt-3 block text-xs font-bold text-slate-500">บริษัทปลายทาง</label>
        <select v-model="demoteCompanyId" data-test="demote-target" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
          <option value="" disabled>เลือกบริษัท</option>
          <option v-for="c in activeCompany.companies" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <p class="mt-1 text-[11px] text-slate-400">
          จำเป็นต้องเลือก — Super Admin ไม่สังกัดบริษัท ถ้าถอดสิทธิ์โดยไม่ระบุบริษัท บัญชีนี้จะเข้าระบบได้แต่มองไม่เห็นอะไรเลย
        </p>

        <label class="mt-3 block text-xs font-bold text-slate-500">
          พิมพ์ <span class="font-mono text-slate-800">{{ demoting.email }}</span> เพื่อยืนยัน
        </label>
        <input
          v-model="demoteTyped"
          type="text"
          autocomplete="off"
          data-test="demote-phrase"
          class="mt-1 w-full px-3 py-2 rounded-lg border text-sm"
          :class="demotePhraseSatisfied ? 'border-emerald-300 bg-emerald-50/40' : 'border-slate-200'"
        />

        <p v-if="demoteError" class="mt-2 text-xs font-bold text-rose-600" data-test="demote-error">{{ demoteError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-secondary" :disabled="savingDemote" @click="demoting = null">ยกเลิก</button>
          <button
            class="btn-primary"
            :disabled="savingDemote || demoteCompanyId === '' || !demotePhraseSatisfied"
            data-test="submit-demote"
            @click="submitDemote"
          >
            {{ savingDemote ? 'กำลังบันทึก…' : 'ถอดสิทธิ์' }}
          </button>
        </div>
      </div>
    </div>

    <!-- TASK-246 — create. Its own modal rather than an inline row so the
         password field is never sitting open on a screen somebody walked
         away from. -->
    <div v-if="showCreate" class="fixed inset-0 z-50 bg-slate-900/40 flex items-center justify-center p-4" @click.self="showCreate = false">
      <div class="w-full max-w-md bg-white rounded-2xl p-5 shadow-xl">
        <p class="text-sm font-bold text-slate-900">เพิ่มผู้ใช้เข้าระบบ</p>
        <p v-if="creatingSuperAdmin" class="mt-0.5 text-xs text-slate-400" data-test="create-no-company">ไม่สังกัดบริษัท — Super Admin เห็นทุกบริษัท</p>
        <p v-else-if="!createCompanyBlocked" class="mt-0.5 text-xs text-slate-400">บริษัท: <strong>{{ createCompanyName }}</strong></p>
        <!-- The server REQUIRES a company from a Super Admin and has nothing
             to infer one from. Said here, before the form is filled in. -->
        <p v-else-if="createCompanyBlocked" class="mt-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-700" data-test="create-needs-company">
          ตอนนี้เลือก "ทุกบริษัท" อยู่ — เลือกบริษัทที่แถบด้านบนก่อน จึงจะสร้างผู้ใช้ได้ว่าอยู่บริษัทไหน
        </p>

        <div class="mt-3 grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-bold text-slate-500">ชื่อ</label>
            <input v-model="createForm.first_name" data-test="create-first-name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">นามสกุล</label>
            <input v-model="createForm.last_name" data-test="create-last-name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
        </div>
        <div class="mt-3">
          <label class="text-xs font-bold text-slate-500">อีเมล (ใช้เข้าสู่ระบบ)</label>
          <input v-model="createForm.email" type="email" data-test="create-email" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <div class="mt-3">
          <label class="text-xs font-bold text-slate-500">บทบาท</label>
          <select v-model="createForm.role" data-test="create-role" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
            <option value="company_admin">ผู้ดูแลบริษัท</option>
            <!-- 2026-09-18 — สมาชิก is gone: this screen no longer lists
                 agents, and creating one here would make an account that
                 vanishes the moment it is saved. They are created on
                 จัดการตัวแทน, where they are then visible. -->
            <option value="voucher_staff">พนักงานหน้าร้าน (ตัดสิทธิ์บัตรกำนัลอย่างเดียว)</option>
            <!-- Super-Admin-only, and last in the list rather than first:
                 the everyday choice should be the one under the cursor. -->
            <option v-if="isSuperAdmin" value="super_admin">Super Admin (ทุกบริษัท)</option>
          </select>
          <!-- Said at the moment the choice is made, not discovered after the
               account is handed over. -->
          <!-- The partner role is deliberately absent from the list above: it
               needs a SUPPLIER, not a company, and the place that knows which
               supplier is the supplier's own page. Pointing at it here is
               cheaper than a validation error after a password has been typed
               — and cheaper still than a reader concluding the role no longer
               exists. -->
          <p class="mt-1 text-[11px] text-slate-500" data-test="company-partner-pointer">
            ต้องการสร้าง <strong>บัญชีคู่ค้า</strong>? สร้างที่
            <RouterLink :to="{ name: 'supplier-management' }" class="text-brand-700 font-bold hover:underline">ตั้งค่าระบบ → จัดการคู่ค้า</RouterLink>
            แล้วเปิดแท็บ "บัญชีผู้ใช้" ของคู่ค้ารายนั้น
          </p>
          <p v-if="creatingSuperAdmin" class="mt-1 text-[11px] text-violet-700" data-test="super-admin-hint">
            บัญชีนี้จะ<strong>ไม่สังกัดบริษัทใด</strong> และเห็นข้อมูลได้ทุกบริษัทบนแพลตฟอร์ม
            รวมถึงสร้างและถอดสิทธิ์ Super Admin คนอื่น — ต้องพิมพ์อีเมลยืนยันอีกครั้งก่อนสร้าง
          </p>
          <p v-if="createForm.role === 'voucher_staff'" class="mt-1 text-[11px] text-slate-500" data-test="voucher-staff-hint">
            บัญชีนี้จะเข้าได้เฉพาะหน้า <strong>ตัดสิทธิ์บัตรกำนัล</strong> หน้าเดียว — เห็นคำสั่งซื้อ ลูกค้า หรือค่าแนะนำไม่ได้เลย
          </p>
        </div>
        <div class="mt-3">
          <label class="text-xs font-bold text-slate-500">รหัสผ่านเริ่มต้น</label>
          <input
            v-model="createForm.password"
            type="text"
            data-test="create-password"
            placeholder="อย่างน้อย 8 ตัว มีพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข"
            class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
          />
          <!-- Typed by the admin, never generated here and never shown again
               afterwards — same rule as ตั้งรหัสผ่านใหม่ below. -->
          <p class="mt-1 text-[11px] text-slate-400">คุณเป็นคนกำหนดเอง แล้วส่งให้เจ้าตัวโดยตรง — ระบบไม่มีอีเมลแจ้ง และจะไม่แสดงรหัสนี้อีกหลังบันทึก</p>
        </div>

        <p v-if="createError" class="mt-2 text-xs font-bold text-rose-600" data-test="create-error">{{ createError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-secondary" :disabled="creating" @click="showCreate = false">ยกเลิก</button>
          <button class="btn-primary" :disabled="creating || createCompanyBlocked" data-test="submit-create" @click="submitCreate">
            {{ creating ? 'กำลังสร้าง…' : 'สร้างบัญชี' }}
          </button>
        </div>
      </div>
    </div>

    <!-- TASK-246 — edit. Four fields only; everything else about an agent
         belongs to จัดการตัวแทน, and two screens owning one field is how they
         start disagreeing. -->
    <div v-if="editing" class="fixed inset-0 z-50 bg-slate-900/40 flex items-center justify-center p-4" @click.self="editing = null">
      <div class="w-full max-w-md bg-white rounded-2xl p-5 shadow-xl">
        <p class="text-sm font-bold text-slate-900">แก้ไขข้อมูลผู้ใช้</p>
        <p class="mt-0.5 text-xs text-slate-400">{{ editing.name }} · {{ roleLabel(editing.role) }}</p>

        <div class="mt-3 grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-bold text-slate-500">ชื่อ</label>
            <input v-model="editForm.first_name" data-test="edit-first-name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">นามสกุล</label>
            <input v-model="editForm.last_name" data-test="edit-last-name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
        </div>
        <div class="mt-3">
          <label class="text-xs font-bold text-slate-500">อีเมล (ใช้เข้าสู่ระบบ)</label>
          <input v-model="editForm.email" type="email" data-test="edit-email" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          <p class="mt-1 text-[11px] text-slate-400">เปลี่ยนอีเมลแล้ว เจ้าตัวต้องใช้อีเมลใหม่เข้าสู่ระบบครั้งถัดไป</p>
        </div>
        <div class="mt-3">
          <label class="text-xs font-bold text-slate-500">เบอร์โทร (ไม่บังคับ)</label>
          <input v-model="editForm.phone" data-test="edit-phone" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>

        <p v-if="editError" class="mt-2 text-xs font-bold text-rose-600" data-test="edit-error">{{ editError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-secondary" :disabled="savingEdit" @click="editing = null">ยกเลิก</button>
          <button class="btn-primary" :disabled="savingEdit" data-test="submit-edit" @click="submitEdit">
            {{ savingEdit ? 'กำลังบันทึก…' : 'บันทึก' }}
          </button>
        </div>
      </div>
    </div>

    <!-- 2026-09-10 — the per-person grant. One checkbox today, and a list
         shaped for more: the endpoint replaces the WHOLE set a person holds,
         so what is saved is what this form shows, never a diff. -->
    <div v-if="grantingTo" class="fixed inset-0 z-50 bg-slate-900/40 flex items-center justify-center p-4" data-test="abilities-dialog" @click.self="grantingTo = null">
      <div class="w-full max-w-md bg-white rounded-2xl p-5 shadow-xl">
        <p class="text-sm font-bold text-slate-900">สิทธิ์เพิ่มเติมของ {{ grantingTo.name }}</p>
        <p class="mt-0.5 text-xs text-slate-400">{{ roleLabel(grantingTo.role) }}</p>

        <label class="mt-3 flex items-start gap-2.5 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50">
          <input v-model="grantRedeem" type="checkbox" data-test="grant-voucher-redeem" class="mt-0.5 rounded border-slate-300" />
          <span>
            <span class="block text-sm font-bold text-slate-800">ตัดสิทธิ์บัตรกำนัลได้</span>
            <span class="block mt-0.5 text-[11px] text-slate-500">
              เปิดเมนู "ตัดสิทธิ์บัตรกำนัล" ให้บัญชีนี้ — ใช้กับผู้ดูแลที่ต้องยืนหน้าเคาน์เตอร์ด้วย
              ผู้ดูแลบริษัทไม่ได้สิทธิ์นี้มาโดยอัตโนมัติอีกต่อไป ต้องมอบให้เป็นรายคน
            </span>
          </span>
        </label>
        <!-- Why it is worth a deliberate act: redemption spends something the
             customer paid for, and it cannot be undone from this screen. -->
        <p class="mt-2 text-[11px] text-slate-400">
          การตัดสิทธิ์คือการใช้บริการที่ลูกค้าจ่ายเงินมาแล้ว ระบบบันทึกทุกครั้งว่าใครเป็นคนตัด และใครเป็นคนมอบสิทธิ์นี้ให้
        </p>

        <p v-if="grantError" class="mt-2 text-xs font-bold text-rose-600" data-test="grant-error">{{ grantError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-secondary" :disabled="savingGrant" @click="grantingTo = null">ยกเลิก</button>
          <button class="btn-primary" :disabled="savingGrant" data-test="submit-abilities" @click="submitGrants">
            {{ savingGrant ? 'กำลังบันทึก…' : 'บันทึกสิทธิ์' }}
          </button>
        </div>
      </div>
    </div>

    <!-- TASK-246 — move company. A confirm step that names both companies,
         because the account's whole tenant changes and what they can see
         changes with it. -->
    <div v-if="moving" class="fixed inset-0 z-50 bg-slate-900/40 flex items-center justify-center p-4" @click.self="moving = null">
      <div class="w-full max-w-md bg-white rounded-2xl p-5 shadow-xl">
        <p class="text-sm font-bold text-slate-900">ย้ายผู้ใช้ไปบริษัทอื่น</p>
        <p class="mt-0.5 text-xs text-slate-400">{{ moving.name }} · ตอนนี้อยู่ {{ moving.company?.name ?? '—' }}</p>
        <p class="mt-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-700">
          ย้ายแล้วบัญชีนี้จะเห็นข้อมูลของบริษัทใหม่แทนบริษัทเดิมทั้งหมด — ลูกค้า ดีล และค่าแนะนำที่เกิดขึ้นแล้วยังอยู่กับบริษัทเดิมตามเดิม
        </p>

        <label class="mt-3 block text-xs font-bold text-slate-500">บริษัทปลายทาง</label>
        <select v-model="moveCompanyId" data-test="move-target" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
          <option value="" disabled>เลือกบริษัท</option>
          <option v-for="c in moveTargets" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>

        <p v-if="moveError" class="mt-2 text-xs font-bold text-rose-600" data-test="move-error">{{ moveError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-secondary" :disabled="savingMove" @click="moving = null">ยกเลิก</button>
          <button class="btn-primary" :disabled="savingMove || moveCompanyId === ''" data-test="submit-move" @click="submitMove">
            {{ savingMove ? 'กำลังย้าย…' : 'ย้ายบริษัท' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
