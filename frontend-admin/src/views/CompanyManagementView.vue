<script setup lang="ts">
/**
 * CompanyManagementView — "จัดการบริษัท" (Phase 7). Super Admin only —
 * the route guard + AdminHomeView's card both already gate this on
 * auth.user.role, and the backend's CompanyPolicy is the real
 * enforcement either way (Section 5).
 *
 * A Company is the tenant boundary itself (CLAUDE.md §2). No cascading
 * actions on deactivate/delete are implemented here since none are defined
 * anywhere in CLAUDE.md yet (see CompanyService's own flagged note).
 *
 * ── 2026-09-17: IT CAN NOW BE EDITED ──
 *
 * Owner: "การจัดการบริษัท เพิ่ม edit". Until now a company could be created
 * and switched off and nothing in between — a name typed wrong stayed wrong,
 * and the only fix was the database.
 *
 * The panel covers the two identity fields and the payout account, on the
 * owner's choice of scope. What it deliberately does NOT cover is
 * `commission_plan_type`: that already has its own control on the row and
 * goes through a different endpoint behind a different Ability (see
 * changePlanType). Putting it in the form too would be two doors onto one
 * column on one screen.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
// TASK-209 P4 — this screen ignores the header company scope on purpose.
import PlatformScopeBadge from '@/design-system/components/PlatformScopeBadge.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'

// ADR-006 Round 3/4 → ADR-011 (TASK-034 update): one commission plan
// type per company. All 6 enum values now have a working
// CommissionService engine (Unilevel: original; Binary: TASK-029;
// Matrix: TASK-030; StairstepBreakaway/Generation: TASK-031; Affiliate:
// TASK-032/033) — the earlier "ไบนารี (อยู่ระหว่างพัฒนา)" framing here
// predates TASK-029 and is now stale for all 5 non-Unilevel types, not
// just Binary; removed rather than left half-corrected. A company still
// needs at least one config row (agent_ranks, commission_binary_settings,
// etc. — see the new "แผนคอมมิชชั่น" screen, CommissionPlansView.vue)
// before its chosen plan type actually calculates anything — that's a
// config-completeness concern, not an "under development" one, so it's
// surfaced as a warning banner there instead of blocking selection here.
type CommissionPlanType = 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'

/**
 * 2026-09-19 — one currency option, as the server offers it.
 *
 * Fetched rather than hardcoded for the reason every other closed vocabulary
 * on this app is: two copies of a list are two lists, and the one in the
 * browser keeps a removed option on screen until somebody redeploys. The
 * server's list is also the one that enforces the restriction — it holds only
 * currencies with two decimal places, because BR-3 stores hundredths
 * (satang) and every formatter here divides by 100.
 */
interface CurrencyOption {
  code: string
  name: string
  symbol: string
}

interface CompanyItem {
  id: number
  name: string
  slug: string
  /*
   * 2026-09-19 — ISO 4217, plus the symbol to print with it. A LABEL on the
   * amounts this tenant already stores, never a conversion: nothing in this
   * system converts between currencies and no exchange rate exists anywhere.
   */
  currency_code: string
  currency_symbol: string
  is_active: boolean
  commission_plan_type: CommissionPlanType
  user_count: number
  created_at: string
  /*
   * ADR-017 — where this company RECEIVES customer payments: the details
   * printed on the public /pay/{token} page for a manual transfer.
   *
   * Not to be confused with the supplier payout account, which points the
   * other way (we transfer OUT to a คู่ค้า) and lives on its own table and
   * its own screen. They were briefly the same four columns; see this file's
   * note about the panel that was removed.
   */
  payment_promptpay_id: string | null
  payment_bank_name: string | null
  payment_bank_account_number: string | null
  payment_bank_account_name: string | null
}

/*
 * 2026-09-17 — THE SUPPLIER PANEL THAT USED TO BE HERE IS GONE.
 *
 * For one day this screen carried a collapsible "ตั้งค่าคู่ค้า" panel per row:
 * an is_supplier switch, a GP mode and value, a release trigger, a withdrawal
 * floor, a withholding rate and three payout bank fields. The owner rejected
 * it outright:
 *
 *   "Company Partner = Supplier ต้องแยกจาก Company เดิม แต่คุณเอา UI ไปใส่ที่
 *    company เดิมที่เป็นค่าคอม ผิดทั้งหมดเลย"
 *
 * He is right, and the reason is worth keeping where the mistake was made. A
 * บริษัท on this screen is a TENANT: it sells through us and we pay COMMISSION
 * to its agents, which is what every other control on this row is about. A
 * คู่ค้า is a COUNTERPARTY: it supplies goods and we pay it the sale price less
 * commission less our GP. The money goes the other way and not one field means
 * the same thing in both.
 *
 * Putting the second inside the first taught everybody the wrong model of the
 * business — the misplaced form was only the symptom. Suppliers now have their
 * own table, their own endpoints and their own screen: ตั้งค่าระบบ →
 * จัดการคู่ค้า, directly below this one.
 *
 * Do not re-add a supplier control here. If a company of ours also supplies
 * goods, that is a second row in the suppliers table, deliberately — two
 * contracts, two bank accounts, two logins.
 */

const planTypeLabels: Record<CommissionPlanType, string> = {
  unilevel: 'มาตรฐาน (Unilevel)',
  binary: 'ไบนารี (Binary)',
  matrix: 'เมทริกซ์ (Matrix)',
  stairstep_breakaway: 'Stairstep/Breakaway',
  generation: 'Generation',
  affiliate: 'พันธมิตร (Affiliate)',
}
const planTypeOptions = Object.keys(planTypeLabels) as CommissionPlanType[]

/**
 * 2026-09-17 — this screen is the ONE place company names and slugs change,
 * so it is the one place that has to tell the header switcher.
 *
 * The switcher keeps its own copy of the list, loaded once and cached
 * (deliberately — it is read on every view's mount). Nothing invalidated it,
 * so a rename here left the control at the top of every page naming a company
 * that no longer exists, until somebody pressed F5.
 *
 * Owner: "แก้ไขให้เมื่อมีการเปลี่ยนชื่อ ให้เปลี่ยนที่ list ตรง Menu ทันที".
 */
const activeCompany = useActiveCompanyStore()

/**
 * Reload the list, then refresh the switcher from the same truth.
 *
 * One function rather than two calls at four sites: the create path, the edit
 * path and the two toggles all change what the switcher shows, and the one
 * that gets forgotten is the one that reintroduces the bug.
 */
async function reloadAll(): Promise<void> {
  await loadCompanies()
  await activeCompany.reloadCompanies()
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const companies = ref<CompanyItem[]>([])

async function loadCompanies() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: CompanyItem[] }>('/companies')
    companies.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(() => {
  void loadCompanies()
  void loadCurrencies()
})

function slugify(name: string): string {
  return name
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}

/* ── The currencies a tenant may be denominated in ──────────────────────────
 *
 * Loaded once for the screen. A failure is swallowed and leaves the list
 * empty, which collapses the picker to a read-only line saying the current
 * code: a dropdown that could not load its options must not offer a shorter
 * list, because picking from it would re-denominate a company to whichever
 * option happened to survive.
 */
const currencies = ref<CurrencyOption[]>([])
const defaultCurrency = ref('THB')

async function loadCurrencies(): Promise<void> {
  try {
    const r = await api.get<{ data: CurrencyOption[]; default: string }>('/currencies')
    currencies.value = r.data ?? []
    defaultCurrency.value = r.default ?? 'THB'
    // The create form is seeded with a literal so it is never bound to an
    // empty <select>; once the server has answered, its default is the one
    // that stands. Preselecting the wrong code would let an admin provision a
    // tenant in a currency they never chose by not touching the field.
    createForm.value.currency_code = defaultCurrency.value
  } catch {
    currencies.value = []
  }
}

// ── Create form ──
const showCreateForm = ref(false)
const createForm = ref<{ name: string; slug: string; currency_code: string; commission_plan_type: CommissionPlanType }>({
  name: '',
  slug: '',
  // Seeded from the server's own default rather than a literal 'THB': the
  // company this system was built for is Thai, but the default is a server
  // fact and only one place should assert it.
  currency_code: 'THB',
  commission_plan_type: 'unilevel',
})
const creating = ref(false)
async function submitCreate() {
  creating.value = true
  errorMessage.value = ''
  try {
    await api.post('/companies', {
      name: createForm.value.name,
      slug: createForm.value.slug || slugify(createForm.value.name),
      currency_code: createForm.value.currency_code,
      commission_plan_type: createForm.value.commission_plan_type,
    })
    createForm.value = { name: '', slug: '', currency_code: defaultCurrency.value, commission_plan_type: 'unilevel' }
    showCreateForm.value = false
    await reloadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `สร้างไม่สำเร็จ (${e.status})` : 'สร้างไม่สำเร็จ'
  } finally {
    creating.value = false
  }
}

// ── Edit one company ──
/**
 * 2026-09-17 — the row can be edited in place.
 *
 * Inline rather than a modal, matching the create form above it: an admin
 * comparing three tenants should not lose sight of the list to rename one.
 * One row open at a time, because two half-edited forms is a way to save the
 * wrong one.
 */
const editingId = ref<number | null>(null)
const editForm = ref<{
  name: string
  slug: string
  currency_code: string
  payment_promptpay_id: string
  payment_bank_name: string
  payment_bank_account_number: string
  payment_bank_account_name: string
} | null>(null)
const savingEdit = ref(false)
/** The slug as it was when the panel opened — what the warning compares to. */
const originalSlug = ref('')

function startEdit(company: CompanyItem) {
  if (editingId.value === company.id) {
    editingId.value = null
    editForm.value = null

    return
  }

  editingId.value = company.id
  originalSlug.value = company.slug
  // Nulls become empty strings for the inputs and are turned back on save —
  // an <input> bound to null renders the string "null".
  editForm.value = {
    name: company.name,
    slug: company.slug,
    currency_code: company.currency_code,
    payment_promptpay_id: company.payment_promptpay_id ?? '',
    payment_bank_name: company.payment_bank_name ?? '',
    payment_bank_account_number: company.payment_bank_account_number ?? '',
    payment_bank_account_name: company.payment_bank_account_name ?? '',
  }
}

/**
 * Has the slug actually changed? Drives the warning, and the confirm.
 *
 * `slug` is not a label. It is the company's recruit and login link
 * (`/login?company=<slug>`) and the default invite code, so changing it
 * invalidates every link already handed out — a QR on a printed card, a
 * message in a LINE group, a bookmark. Nothing errors; people simply cannot
 * sign up any more and nobody finds out until somebody complains.
 *
 * The server allows it, which is right: a company genuinely does get renamed.
 * What is not right is doing it without being told.
 */
const slugChanged = computed(
  () => editForm.value !== null && editForm.value.slug.trim() !== originalSlug.value,
)

/**
 * The confirm is a ConfirmDialog, NOT window.confirm.
 *
 * 2026-09-17 — shipped with the native one and the owner sent back a
 * screenshot of it: an unstyled OS box titled "admin.partner.syncvision.io
 * says", sitting on top of the app it is supposedly part of. ConfirmDialog
 * exists precisely for this ("Replace native window.confirm() ทั่วระบบ",
 * TASK-066, after the same complaint about the cert-grant action) and I did
 * not reach for it.
 *
 * It is not only cosmetic. `window.confirm` blocks the whole page — nothing
 * else renders or responds while it is up — and it cannot show a busy state,
 * so a slow save after OK leaves the screen looking frozen with no
 * explanation.
 *
 * Holding the COMPANY rather than a boolean: the dialog needs to know which
 * row it is about in order to save it, and a separate `showDialog` flag next
 * to a separate `target` is two things that can disagree.
 */
const pendingSlugChange = ref<CompanyItem | null>(null)

/** What the dialog says. Built here so the wording lives beside the rule. */
const slugChangeBody = computed(() => {
  const form = editForm.value
  if (!form) return ''

  return `ลิงก์บริษัทจะเปลี่ยนจาก /${originalSlug.value} เป็น /${form.slug.trim()}\n\n`
    + 'สิ่งที่จะใช้ไม่ได้ทันที:\n'
    + '· ลิงก์สมัครที่แจกให้ตัวแทนไปแล้ว\n'
    + '· ลิงก์เข้าสู่ระบบและ QR ที่พิมพ์ไปแล้ว\n'
    + '· รหัสเชิญเริ่มต้นของบริษัท (จะเปลี่ยนตามลิงก์ใหม่)\n\n'
    + 'ระบบจะไม่แจ้งเตือนอะไรเลยเมื่อมีคนกดลิงก์เก่า — เขาจะสมัครไม่ได้เฉย ๆ'
})

/**
 * Pressing บันทึก. Asks first only when there is something to ask about.
 *
 * A confirm on every save teaches people to dismiss confirms, which is what
 * would make the slug one worthless on the day it matters.
 */
function submitEdit(company: CompanyItem) {
  if (!editForm.value) return

  if (slugChanged.value) {
    pendingSlugChange.value = company

    return
  }

  void saveEdit(company)
}

async function saveEdit(company: CompanyItem) {
  const form = editForm.value
  if (!form) return

  savingEdit.value = true
  errorMessage.value = ''
  try {
    await api.put(`/companies/${company.id}`, {
      name: form.name.trim(),
      slug: form.slug.trim(),
      /*
       * Sent on every save, including one that did not touch it. It is not
       * nullable and has no "unset" state, so the round-trip is lossless —
       * and omitting it conditionally would be one more branch to get wrong
       * on the one field that decides what every amount in this tenant means.
       */
      currency_code: form.currency_code,
      // Empty means "not recorded", which is a real answer on the public
      // payment page — it simply omits that line. Sending '' would store a
      // blank string that reads as configured and prints as nothing.
      payment_promptpay_id: form.payment_promptpay_id.trim() || null,
      payment_bank_name: form.payment_bank_name.trim() || null,
      payment_bank_account_number: form.payment_bank_account_number.trim() || null,
      payment_bank_account_name: form.payment_bank_account_name.trim() || null,
    })
    editingId.value = null
    editForm.value = null
    await reloadAll()
  } catch (e) {
    // In full: the server refuses a duplicate slug by name, and "แก้ไขไม่
    // สำเร็จ (422)" would leave somebody guessing which field it meant.
    errorMessage.value = e instanceof ApiError ? `แก้ไขไม่สำเร็จ: ${e.message}` : 'แก้ไขไม่สำเร็จ'
  } finally {
    savingEdit.value = false
    // Closed whichever way it went. Left open on a failure, the dialog would
    // cover the error message it caused.
    pendingSlugChange.value = null
  }
}

async function toggleActive(company: CompanyItem) {
  try {
    await api.put(`/companies/${company.id}`, { is_active: !company.is_active })
    await reloadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `อัปเดตไม่สำเร็จ (${e.status})` : 'อัปเดตไม่สำเร็จ'
  }
}

/**
 * 2026-09-12 — writes through PUT /commission-settings, not PUT /companies.
 *
 * `commission_plan_type` left the companies resource that day. Two reasons,
 * both recorded in full on CommissionSettingService: it is not the same
 * question as "may you administer this company" (and borrowing that gate would
 * hand the power to change who gets paid to anybody ever granted company
 * administration), and while the write lived there, step 2 of the commission
 * screen could only LINK here to change a plan — a bounce the owner reported
 * as "ทำให้ UI สับสน".
 *
 * This screen keeps its dropdown: a platform owner looking at every tenant at
 * once is a real view, and it is the same actor behind the same Ability. What
 * changed is which door it knocks on, so there is exactly one.
 */
async function changePlanType(company: CompanyItem, planType: CommissionPlanType) {
  if (planType === company.commission_plan_type) return
  try {
    // company_id is required here (the endpoint scopes a Super Admin by it),
    // where PUT /companies/{id} carried the company in the path.
    await api.put('/commission-settings', { company_id: company.id, commission_plan_type: planType })
    // The switcher shows neither the plan nor the active flag, but this goes
    // through the same door so that "a write on this screen refreshes the
    // header" has no exceptions to remember.
    await reloadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `อัปเดตไม่สำเร็จ (${e.status})` : 'อัปเดตไม่สำเร็จ'
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="building"
      title="จัดการบริษัท"
      subtitle="รายชื่อบริษัท (Tenant) ทั้งแพลตฟอร์ม"
      description="มองเห็นได้เฉพาะ Super Admin — ข้ามบริษัททั้งแพลตฟอร์ม (Section 5)"
      accent-color="brand"
      storage-key="company-management"
    >
      <template #actions>
        <button
          class="btn-primary"
          data-test="toggle-create"
          @click="showCreateForm = !showCreateForm"
        >
          + เพิ่มบริษัท
        </button>
      </template>
    </HeroHeader>

    <PlatformScopeBadge reason="จัดการบริษัททั้งหมดในระบบ" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <form
      v-if="showCreateForm"
      class="mt-4 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3"
      @submit.prevent="submitCreate"
    >
      <div>
        <label class="text-xs font-bold text-slate-500">ชื่อบริษัท</label>
        <input v-model="createForm.name" required data-test="create-name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">Slug (ไม่บังคับ — สร้างอัตโนมัติจากชื่อ)</label>
        <input v-model="createForm.slug" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <!-- Chosen at provisioning so a non-Thai tenant never has a day of
           amounts labelled ฿. Omitted entirely when the list did not load —
           the server's own default applies, which is the honest fallback. -->
      <div v-if="currencies.length" class="col-span-2">
        <label class="text-xs font-bold text-slate-500">สกุลเงิน</label>
        <select v-model="createForm.currency_code" data-test="create-currency" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
          <option v-for="cur in currencies" :key="`create-${cur.code}`" :value="cur.code">
            {{ cur.code }} — {{ cur.name }} ({{ cur.symbol }})
          </option>
        </select>
      </div>
      <div class="col-span-2">
        <label class="text-xs font-bold text-slate-500">รูปแบบค่าแนะนำ (เลือกได้ 1 แบบต่อบริษัท)</label>
        <select v-model="createForm.commission_plan_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
          <option v-for="pt in planTypeOptions" :key="pt" :value="pt">{{ planTypeLabels[pt] }}</option>
        </select>
        <p v-if="createForm.commission_plan_type !== 'unilevel'" class="mt-1 text-xs text-slate-400">
          ต้องตั้งค่าที่หน้า "แผนค่าแนะนำ" ก่อน ระบบจึงจะคำนวณค่าแนะนำตามรูปแบบนี้ได้
        </p>
      </div>
      <div class="col-span-2 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="showCreateForm = false">ยกเลิก</button>
        <button type="submit" :disabled="creating" data-test="submit-create" class="btn-primary">
          {{ creating ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
    </form>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="3" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!companies.length" icon="building" title="ยังไม่มีบริษัทในระบบ" class="mt-4" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="c in companies" :key="c.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-start gap-3 min-w-0">
            <Icon name="building" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900">{{ c.name }}</p>
              <p class="text-xs text-slate-400">/{{ c.slug }} · {{ c.user_count }} ผู้ใช้งาน</p>
              <div class="mt-1.5 flex items-center gap-1.5">
                <select
                  :value="c.commission_plan_type"
                  class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 bg-white text-slate-600"
                  @change="changePlanType(c, ($event.target as HTMLSelectElement).value as CommissionPlanType)"
                >
                  <option v-for="pt in planTypeOptions" :key="pt" :value="pt">{{ planTypeLabels[pt] }}</option>
                </select>
                <RouterLink
                  v-if="c.commission_plan_type !== 'unilevel'"
                  :to="{ name: 'commission-plan-settings' }"
                  class="text-[11px] font-bold px-1.5 py-0.5 rounded bg-slate-50 text-slate-500 hover:bg-slate-100 hover:text-brand-600"
                >
                  ตั้งค่าแผนค่าแนะนำ
                </RouterLink>
              </div>
            </div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button
              type="button"
              data-test="edit-company"
              class="text-xs font-bold px-2 py-1 rounded-lg inline-flex items-center gap-1 text-slate-600 bg-slate-100 hover:bg-slate-200"
              @click="startEdit(c)"
            >
              <Icon name="edit" :size="13" />
              {{ editingId === c.id ? 'ปิด' : 'แก้ไข' }}
            </button>
            <button
              class="text-xs font-bold px-2 py-1 rounded-lg"
              :class="c.is_active ? 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' : 'text-slate-400 bg-slate-100 hover:bg-slate-200'"
              @click="toggleActive(c)"
            >
              {{ c.is_active ? 'ใช้งานอยู่' : 'ปิดใช้งาน' }}
            </button>
          </div>
        </div>

        <!-- ── EDIT ─────────────────────────────────────────────────────── -->
        <div
          v-if="editingId === c.id && editForm"
          data-test="edit-panel"
          class="mt-4 pt-4 border-t border-slate-200"
        >
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="text-xs font-bold text-slate-500">ชื่อบริษัท</label>
              <input
                v-model="editForm.name"
                data-test="edit-name"
                class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
              />
            </div>

            <div>
              <label class="text-xs font-bold text-slate-500">ลิงก์บริษัท (slug)</label>
              <input
                v-model="editForm.slug"
                data-test="edit-slug"
                class="mt-1 w-full px-3 py-2 rounded-lg border text-sm"
                :class="slugChanged ? 'border-amber-300 bg-amber-50' : 'border-slate-200'"
              />
              <!--
                Shown the MOMENT the field changes, not after saving. `slug` is
                the recruit/login link and the default invite code, so every
                link already handed out stops working — and nothing errors, so
                the first sign of trouble is somebody who cannot sign up.
              -->
              <p
                v-if="slugChanged"
                data-test="slug-warning"
                class="mt-1 text-xs text-amber-700 font-bold"
              >
                ลิงก์สมัคร / เข้าสู่ระบบ / QR ที่แจกไปแล้วจะใช้ไม่ได้ทันที และรหัสเชิญเริ่มต้นจะเปลี่ยนตาม
              </p>
              <p v-else class="mt-1 text-xs text-slate-400">
                ใช้เป็นลิงก์สมัครและเข้าสู่ระบบของบริษัทนี้
              </p>
            </div>

            <!--
              ═══ 2026-09-19 — THE TENANT'S CURRENCY ═══

              A LABEL on the amounts this company already stores. Changing it
              does NOT convert anything and no exchange rate exists anywhere in
              this system — the same stored numbers simply start being printed
              with a different symbol. Said in as many words under the control,
              because the opposite assumption is the obvious one and it is the
              kind of mistake that is only noticed at a payout.

              Only currencies with two decimal places are offered. BR-3 stores
              satang — hundredths — and every formatter in both apps divides by
              100, so a 0-decimal currency would make every figure a hundred
              times the real one, silently. The list comes from the server so
              there is exactly one copy of that restriction.

              A failed load collapses this to a read-only line: a dropdown that
              lost its options must not let somebody pick from the remainder.
            -->
            <div class="sm:col-span-2">
              <label class="text-xs font-bold text-slate-500">สกุลเงินของบริษัทนี้</label>
              <select
                v-if="currencies.length"
                v-model="editForm.currency_code"
                data-test="edit-currency"
                class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white"
              >
                <option v-for="cur in currencies" :key="cur.code" :value="cur.code">
                  {{ cur.code }} — {{ cur.name }} ({{ cur.symbol }})
                </option>
              </select>
              <p v-else class="mt-1 text-sm font-bold text-slate-700" data-test="edit-currency-readonly">
                {{ c.currency_code }} ({{ c.currency_symbol }})
                <span class="ml-1 text-xs font-normal text-rose-600">— โหลดรายการสกุลเงินไม่สำเร็จ จึงยังเปลี่ยนไม่ได้</span>
              </p>
              <p class="mt-1 text-xs text-slate-400">
                เปลี่ยนแล้ว<b>ไม่แปลงค่าเงิน</b> — ตัวเลขเดิมทั้งหมดคงเดิม เปลี่ยนแค่สัญลักษณ์ที่แสดง ·
                ระบบไม่มีอัตราแลกเปลี่ยน
              </p>
            </div>

            <div class="sm:col-span-2 p-3 rounded-lg bg-slate-50 border border-slate-200">
              <p class="text-xs font-bold text-slate-600">บัญชีที่บริษัทนี้ใช้รับเงินจากลูกค้า</p>
              <!--
                RECEIVES, stated on the label. The mirror-image account — the
                one WE transfer out to a คู่ค้า — lives on the suppliers table
                and its own screen. Two "bank account" fields pointing in
                opposite directions is how a transfer lands in the wrong place.
              -->
              <p class="text-xs text-slate-400 mt-0.5">
                แสดงบนหน้าชำระเงินของลูกค้า · คนละบัญชีกับบัญชีที่เราโอนคืนให้คู่ค้า
              </p>

              <div class="grid gap-3 sm:grid-cols-2 mt-2">
                <input
                  v-model="editForm.payment_promptpay_id"
                  data-test="edit-promptpay"
                  placeholder="พร้อมเพย์ (เบอร์โทร / เลขผู้เสียภาษี)"
                  class="px-3 py-2 rounded-lg border border-slate-200 text-sm"
                />
                <input
                  v-model="editForm.payment_bank_name"
                  data-test="edit-bank-name"
                  placeholder="ธนาคาร"
                  class="px-3 py-2 rounded-lg border border-slate-200 text-sm"
                />
                <input
                  v-model="editForm.payment_bank_account_number"
                  data-test="edit-bank-number"
                  placeholder="เลขที่บัญชี"
                  class="px-3 py-2 rounded-lg border border-slate-200 text-sm"
                />
                <input
                  v-model="editForm.payment_bank_account_name"
                  data-test="edit-bank-holder"
                  placeholder="ชื่อบัญชี"
                  class="px-3 py-2 rounded-lg border border-slate-200 text-sm"
                />
              </div>

              <!-- The same four columns are editable on ช่องทางรับชำระเงิน,
                   which edits the ACTIVE company only. Saying so beats letting
                   somebody find two forms and wonder which one counts. -->
              <p class="mt-2 text-xs text-slate-400">
                แก้ได้จากหน้า <RouterLink :to="{ name: 'payment-gateways' }" class="font-bold text-brand-600 hover:underline">ช่องทางรับชำระเงิน</RouterLink> เช่นกัน — ที่นั่นแก้ได้เฉพาะบริษัทที่เลือกอยู่
              </p>
            </div>
          </div>

          <div class="mt-4 flex items-center gap-2">
            <button
              type="button"
              data-test="save-edit"
              :disabled="savingEdit || !editForm.name.trim() || !editForm.slug.trim()"
              class="btn-primary"
              @click="submitEdit(c)"
            >
              {{ savingEdit ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
            <button type="button" class="btn-secondary" @click="startEdit(c)">ยกเลิก</button>
          </div>
        </div>

        </div>
      </TransitionGroup>
    </template>
    <!--
      The slug confirm. `warning` rather than `danger`: nothing is destroyed,
      a working link stops working — which is amber, not red, and the
      difference is what keeps red meaningful elsewhere.

      The buttons say what they DO rather than ยืนยัน/ยกเลิก, following the
      precedent ConfirmDialog's own docblock sets: "ยืนยัน" leaves somebody
      guessing which thing they are confirming, the change or the staying.
    -->
    <ConfirmDialog
      :show="pendingSlugChange !== null"
      variant="warning"
      size="md"
      title="เปลี่ยนลิงก์บริษัท"
      :body="slugChangeBody"
      :busy="savingEdit"
      confirm-label="เปลี่ยนลิงก์และบันทึก"
      cancel-label="แก้ไขต่อ"
      @confirm="pendingSlugChange && saveEdit(pendingSlugChange)"
      @cancel="pendingSlugChange = null"
      @update:show="(v: boolean) => { if (!v) pendingSlugChange = null }"
    />
  </main>
</template>
