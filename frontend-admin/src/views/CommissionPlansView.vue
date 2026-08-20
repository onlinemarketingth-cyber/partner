<script setup lang="ts">
/**
 * CommissionPlansView — "แผนคอมมิชชั่น" (ADR-011 / TASK-034).
 *
 * Human decision (2026-07-20, AskUserQuestion): consolidate every new
 * plan-type's admin configuration into ONE route with a tab bar, rather
 * than 5+ flat top-nav items — same tab pattern as GamificationConfigView.vue
 * (rules/badges/levels). Tabs here: Commission Rules (BR-2/BR-7, TASK-028
 * category+company-default scoping), Binary (TASK-029), Matrix (TASK-030),
 * Agent Ranks / Stairstep-Breakaway (TASK-031), Generation (TASK-031),
 * Affiliate (TASK-032/033's attribution window).
 *
 * This screen is UI-only (TASK-034's own "Out of scope: any new backend
 * logic") — every endpoint it calls already shipped and was already
 * tested in its own task. Company scoping: a Company Admin never sees a
 * company selector (their own company_id is resolved server-side on
 * every call, same as everywhere else in this app); a Super Admin picks
 * a company first — every list below is then client-side filtered to
 * that company_id (every Resource used here already returns company_id),
 * and every singleton GET/create call explicitly threads it through
 * (`?company_id=` / `company_id` in the body), same convention as
 * ProductEditView.vue's own selectedCompanyId.
 *
 * BR-3: money in/out of every form here is satang server-side, THB only
 * at this display/input layer. BR-7: no rate/threshold/window value is
 * ever defaulted to something meaningful — every numeric input starts
 * blank, never pre-filled with a guessed business value.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
// TASK-208 / ADR-038 — one company scope, chosen in the header.
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
// TASK-196 §3 — live commission-rate-cap guard, one shared implementation
// with ProductEditView.vue and ProductCatalogView.vue.
import { useCommissionRateCapGuard } from '@/composables/useCommissionRateCap'
// TASK-199 — พ.ศ. calendar consistency: same component ProductEditView.vue
// already uses for effective_from, swapped in here for every date input in
// this file (was plain native <input type="date">, ค.ศ. only).
import BuddhistDateInput from '@/design-system/components/BuddhistDateInput.vue'
// Same combo as voucher validity (TASK-189 follow-up v3) / ProductEditView's
// commission-rule dates (2026-08-17 follow-up) — a real clickable calendar
// alongside the dropdowns, sharing one v-model. Native <input type="date">
// used to give a browser calendar icon for free; swapping to
// BuddhistDateInput (TASK-199) dropped that affordance, so this restores it
// consistently everywhere in this file.
import CalendarDatePicker from '@/design-system/components/CalendarDatePicker.vue'

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}
function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}
type RateType = 'percentage' | 'fixed_satang'
function formatRate(rateType: RateType, rateValue: number): string {
  return rateType === 'percentage' ? (rateValue / 100).toFixed(2) + '%' : formatSatang(rateValue)
}
// TASK-197 §3.1/§3.4 — same labels the Commission Rules tab's rate_type
// selector already used (kept verbatim). Only used by the Commission
// Rules tab's product-scope path below (the "จะบันทึกเป็น: ..." readout
// once the selector is hidden) — Binary/Matrix/Ranks/Generation tabs
// further down keep their own independent rate_type selects, untouched.
const rateTypeLabels: Record<RateType, string> = {
  percentage: '% ของยอดขาย',
  fixed_satang: 'จำนวนคงที่ (บาท)',
}
// Product/company "effective plan type" (TASK-027) — exact enum strings
// confirmed against app/Enums/CommissionPlanType.php, do not guess new ones.
type CommissionPlanType = 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'
// UAT gap-fill (found by clicking through the real UI, not caught by
// vue-tsc/eslint): effective_from/effective_to were rendered raw
// ("2026-01-01T00:00:00.000000Z") instead of a human date — same
// formatDate() pattern as CommissionManagementView.vue's ledger date
// column, reused here for consistency rather than inventing a new one.
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

// ── Company scoping (Super Admin only — see file header) ──
// TASK-208 — the header switcher replaced this page's own selector; the
// alias keeps every downstream helper below unchanged.
const activeCompany = useActiveCompanyStore()
const selectedCompanyId = computed(() => activeCompany.companyId)
/** Identical to the store's companyId — kept as a name the rest of the file already uses. */
const effectiveCompanyId = computed<number | null>(() => activeCompany.companyId)
function companyQuery(): string {
  return isSuperAdmin.value && selectedCompanyId.value ? `?company_id=${selectedCompanyId.value}` : ''
}
function withCompanyBody<T extends Record<string, unknown>>(body: T): T & { company_id?: number } {
  return isSuperAdmin.value && selectedCompanyId.value ? { ...body, company_id: selectedCompanyId.value } : body
}
function byCompany<T extends { company_id: number }>(items: T[]): T[] {
  return isSuperAdmin.value && selectedCompanyId.value ? items.filter((i) => i.company_id === selectedCompanyId.value) : items
}

type Tab = 'rules' | 'binary' | 'matrix' | 'ranks' | 'generation' | 'affiliate'
const activeTab = ref<Tab>('rules')
const tabDefs: { key: Tab; label: string; icon: string }[] = [
  { key: 'rules', label: 'กฎคอมมิชชั่น', icon: 'money' },
  { key: 'binary', label: 'Binary', icon: 'branch' },
  { key: 'matrix', label: 'Matrix', icon: 'layers' },
  { key: 'ranks', label: 'อันดับ (Stairstep)', icon: 'trophy' },
  { key: 'generation', label: 'Generation', icon: 'users' },
  { key: 'affiliate', label: 'พันธมิตร (Affiliate)', icon: 'link' },
]

// ── UI redesign (2026-07-22, human-approved "B ผสม C"): the flat 6-tab
// layout tested fine functionally (UAT-012) but the human found it
// confusing to use as a starting point. Rather than rewrite/risk the
// already-verified tab sections below, this adds a NEW default
// "ภาพรวมสินค้า" (product-driven) overview layer on top, additive only:
//  - Option B: browse/configure primarily by product — see the resolved
//    plan type per product, jump into the right company-wide section
//    only when that plan type actually needs one.
//  - Option C: visibility (which products use which plan type — small
//    count badges next to each company-wide tab) + a client-side
//    "ทดสอบคำนวณ" preview of the DIRECT commission rate that resolves
//    for a given product+cert-tier. Deliberately does NOT attempt to
//    simulate Override/Matrix/Rank/Generation payouts client-side —
//    that logic is genuinely multi-record/multi-level (see UAT-012 §4)
//    and reproducing it here risks showing a wrong number as if it were
//    real; the preview says so explicitly instead of guessing.
// The original tab bar + all 6 sections are unchanged and still reachable
// under "การตั้งค่าทั้งหมด" — zero regression risk on what already passed
// live UAT.
type ViewMode = 'overview' | 'settings'
const viewMode = ref<ViewMode>('overview')
const planTypeLabels: Record<CommissionPlanType, string> = {
  unilevel: 'Unilevel',
  binary: 'Binary',
  matrix: 'Matrix',
  stairstep_breakaway: 'อันดับ (Stairstep)',
  generation: 'Generation',
  affiliate: 'พันธมิตร (Affiliate)',
}
// Only plan types with their own company-wide structural settings tab
// need a "ไปตั้งค่า" jump link; Unilevel is pure rate-rule-driven (no
// dedicated tab exists for it, by design — see tabDefs above).
const planTypeToTab: Partial<Record<CommissionPlanType, Tab>> = {
  binary: 'binary',
  matrix: 'matrix',
  stairstep_breakaway: 'ranks',
  generation: 'generation',
  affiliate: 'affiliate',
}
const tabToPlanType: Partial<Record<Tab, CommissionPlanType>> = {
  binary: 'binary',
  matrix: 'matrix',
  ranks: 'stairstep_breakaway',
  generation: 'generation',
  affiliate: 'affiliate',
}
function goToSettingsTab(tab: Tab) {
  viewMode.value = 'settings'
  activeTab.value = tab
  if (!loadedTabs.value.has(tab)) loadTab(tab)
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

// ══════════════════════════ Commission Rules (TASK-028) ══════════════════════════
interface CertTierOption { id: number; key: string; name: string }
interface ProductOption {
  id: number
  company_id: number
  name: string
  category?: { id: number; name: string } | null
  price_satang?: number
  commission_plan_type?: CommissionPlanType | null
  effective_plan_type?: CommissionPlanType
  // TASK-197 §2.1/§3.4 — this product's locked-in commission rate FORMAT
  // (null = not configured yet, the first product-scoped rule decides it
  // server-side). Only relevant to the Commission Rules tab's product
  // scope below; category/company-wide scope never reads this.
  commission_rate_type?: RateType | null
}
interface ProductCategoryOption { id: number; name: string }
interface CommissionRuleItem {
  id: number
  company_id: number
  cert_tier: CertTierOption | null
  product: { id: number; name: string } | null
  product_category: { id: number; name: string } | null
  rate_type: RateType
  rate_value: number
  effective_from: string
  effective_to: string | null
  renewal_rate_type: RateType | null
  renewal_rate_value: number | null
  renewal_recurs: boolean
}
const commissionRules = ref<CommissionRuleItem[]>([])
const products = ref<ProductOption[]>([])
const productCategories = ref<ProductCategoryOption[]>([])

/**
 * TASK-213 Phase 2 — the TEAM-LEADER rate (`commission_override_rules`),
 * pulled onto this screen.
 *
 * It used to be editable in exactly one place: a tab inside
 * /product-catalog. That is the wrong building. An admin asking "how much
 * does the leader get" opens แผนคอมมิชชั่น, finds six tabs, and none of
 * them is it — the tab literally named "พันธมิตร (Affiliate)" has no rate
 * field at all, because the Affiliate override reads THIS table.
 *
 * Same table, same endpoint, same Policy — only the address changes.
 */
interface CommissionOverrideRuleItem {
  id: number
  company_id: number
  // TASK-214 — the leader rate now carries the SAME scope pair as the
  // agent rate, resolved in the same order (product > category > company),
  // on the human's ruling of 2026-08-19.
  product: { id: number; name: string } | null
  product_category: { id: number; name: string } | null
  // Legacy annotation only. Resolution stopped reading it in TASK-214
  // ("ไม่ต้องผูก") — kept so a pre-TASK-214 row can still explain itself
  // in this list while an operator collapses it.
  manager_cert_tier: CertTierOption | null
  rate_type: RateType
  rate_value: number
  effective_from: string
  effective_to: string | null
}
const commissionOverrideRules = ref<CommissionOverrideRuleItem[]>([])

// TASK-028 shipped this scoping server-side (CommissionRuleResource
// already returns product/product_category, mutually exclusive —
// both null = company-wide default) but no admin UI ever authored a
// category- or company-wide row before this screen; ProductEditView.vue's
// own commission-rules tab only ever sends product_id.
function ruleScopeLabel(r: CommissionRuleItem): string {
  if (r.product) return `สินค้า: ${r.product.name}`
  if (r.product_category) return `หมวดหมู่: ${r.product_category.name}`
  return 'ค่าเริ่มต้นทั้งบริษัท'
}
// Resolution order (most to least specific) — documented here since
// this is the one place an admin can see every scope at once; the
// actual resolution happens server-side (CommissionService), this is
// just an honest UI explanation of that existing order.
const RESOLUTION_ORDER_NOTE = 'ลำดับการใช้ค่า: สินค้าเฉพาะ > หมวดหมู่ > ค่าเริ่มต้นทั้งบริษัท (ใช้อันที่เจาะจงที่สุดที่ตรงเงื่อนไข)'
// Human request (2026-07-22): show this explanation as a dismissible
// modal instead of a permanent inline line — "เข้าใจแล้ว" dismisses it
// for this viewing only; the checkbox persists the dismissal for good.
// Same pure-UI-nag/localStorage pattern already used in
// AcademyManagementView.vue's HIDE_INCOMPLETE_WARNING_KEY (not business
// data, no backend needed).
const HIDE_RESOLUTION_ORDER_NOTE_KEY = 'commission-rules-hide-resolution-order-note'
const hideResolutionOrderNote = ref(localStorage.getItem(HIDE_RESOLUTION_ORDER_NOTE_KEY) === '1')
const showResolutionOrderModal = ref(false)
const dontShowResolutionOrderAgain = ref(false)

function openResolutionOrderModal() {
  showResolutionOrderModal.value = true
}

function closeResolutionOrderModal() {
  if (dontShowResolutionOrderAgain.value) {
    hideResolutionOrderNote.value = true
    localStorage.setItem(HIDE_RESOLUTION_ORDER_NOTE_KEY, '1')
  }
  showResolutionOrderModal.value = false
}

type RuleScope = 'company' | 'category' | 'product'
const ruleForm = ref({
  scope: 'company' as RuleScope,
  product_id: '' as string | number,
  product_category_id: '' as string | number,
  rate_type: 'percentage' as RateType,
  rate_value_input: '' as string | number, // % if percentage, THB if fixed_satang
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
  renewal_rate_type: '' as RateType | '',
  renewal_rate_value_input: '' as string | number,
  renewal_recurs: false,
})
const showRuleForm = ref(false)
const editingRuleId = ref<number | null>(null)
const savingRule = ref(false)
const ruleFormError = ref('')

function resetRuleForm() {
  // TASK-200 — effective_from/effective_to are deliberately NOT reset here:
  // a post-submit reset carries the admin's last-used dates forward as the
  // new default for the next tier's rule (the product-scope "+ เพิ่มอัตราคอมตาม
  // tier" flow adds several cert-tier rules back-to-back for the same start
  // date, mirrors ProductEditView.vue's identical fix). Only the fresh-
  // page-load ref() declaration above still defaults to today's date / blank.
  ruleForm.value = {
    scope: 'company',
    product_id: '',
    product_category_id: '',
    rate_type: 'percentage',
    rate_value_input: '',
    effective_from: ruleForm.value.effective_from,
    effective_to: ruleForm.value.effective_to,
    renewal_rate_type: '',
    renewal_rate_value_input: '',
    renewal_recurs: false,
  }
  editingRuleId.value = null
  showRuleForm.value = false
  ruleFormError.value = ''
  ruleCapGuard.reset()
}
function openCreateRuleForm() {
  resetRuleForm()
  showRuleForm.value = true
}
function openEditRuleForm(r: CommissionRuleItem) {
  ruleCapGuard.reset()
  editingRuleId.value = r.id
  ruleForm.value = {
    scope: r.product ? 'product' : r.product_category ? 'category' : 'company',
    product_id: r.product?.id ?? '',
    product_category_id: r.product_category?.id ?? '',
    rate_type: r.rate_type,
    rate_value_input: r.rate_type === 'percentage' ? r.rate_value / 100 : r.rate_value / 100,
    effective_from: r.effective_from,
    effective_to: r.effective_to ?? '',
    renewal_rate_type: r.renewal_rate_type ?? '',
    renewal_rate_value_input: r.renewal_rate_type ? (r.renewal_rate_value ?? 0) / 100 : '',
    renewal_recurs: r.renewal_recurs,
  }
  showRuleForm.value = true
}
function rateValueToBasisOrSatang(rateType: RateType, input: string | number): number {
  // percentage: THB-style "5" -> 500 basis points. fixed_satang: THB "50" -> 5000 satang.
  return Math.round(Number(input) * 100)
}

// TASK-196 §3.2/§3.3 — this form is shared by create AND edit (editingRuleId
// toggles which), so one guard instance covers both. Price only exists to
// check against when the rule is scoped to a single product (scope ===
// 'product') — company-wide/category rules have no single price, same
// no-op reasoning as the backend's own ValidatesCommissionRateCap trait
// (see that file's docblock for why product_id === null is a no-op there).
const ruleCapGuard = useCommissionRateCapGuard()
const ruleFormProductPriceSatang = computed<number | null>(() => {
  if (ruleForm.value.scope !== 'product' || ruleForm.value.product_id === '') return null
  return products.value.find((p) => p.id === Number(ruleForm.value.product_id))?.price_satang ?? null
})
// TASK-197 §3.4 — the currently-selected product in the "ตามสินค้า" scope
// (only meaningful when scope === 'product'). Category/company-wide scope
// never reads this — those keep their own freely-chosen rate_type (§1).
const ruleFormSelectedProduct = computed<ProductOption | null>(() => {
  if (ruleForm.value.scope !== 'product' || ruleForm.value.product_id === '') return null
  return products.value.find((p) => p.id === Number(ruleForm.value.product_id)) ?? null
})
// null = this product has no commission_rate_type locked in yet (either it
// has never had a product-scoped rule, or none was ever picked on
// ProductEditView's settings block) — the selector below still needs to
// show ONCE so the admin can choose the format for this first rule.
const ruleFormProductRateTypeLocked = computed<RateType | null>(() => ruleFormSelectedProduct.value?.commission_rate_type ?? null)
const showRuleFormRateTypeSelector = computed(() => ruleForm.value.scope !== 'product' || ruleFormProductRateTypeLocked.value === null)
// TASK-197 §3.4 — the FORMAT this submission actually uses: the product's
// locked-in type when scope is 'product' and one exists, otherwise
// whatever the (visible) selector currently holds. Category/company-wide
// rows always just use the selector, unchanged from before this task.
const effectiveRuleFormRateType = computed<RateType>(() => ruleFormProductRateTypeLocked.value ?? ruleForm.value.rate_type)
function recheckRuleCap(): void {
  ruleCapGuard.recheck(effectiveRuleFormRateType.value, rateValueToBasisOrSatang(effectiveRuleFormRateType.value, ruleForm.value.rate_value_input), ruleFormProductPriceSatang.value)
}
function recheckRuleCapDebounced(): void {
  ruleCapGuard.recheckDebounced(effectiveRuleFormRateType.value, rateValueToBasisOrSatang(effectiveRuleFormRateType.value, ruleForm.value.rate_value_input), ruleFormProductPriceSatang.value)
}

async function submitRule() {
  // TASK-196 §3.2 — defensive re-check alongside the disabled Save button
  // (e.g. an Enter-to-submit keypress bypassing a disabled button).
  recheckRuleCap()
  if (ruleCapGuard.isOverCap.value) return
  savingRule.value = true
  ruleFormError.value = ''
  // TASK-197 §3.4 — captured once so the payload and the cap-check below
  // use the exact same value even though effectiveRuleFormRateType is
  // reactive (it depends on ruleForm.value.product_id, which resetRuleForm()
  // clears right after this request resolves).
  const submittedRateType = effectiveRuleFormRateType.value
  try {
    const payload = withCompanyBody({
      product_id: ruleForm.value.scope === 'product' ? Number(ruleForm.value.product_id) : null,
      product_category_id: ruleForm.value.scope === 'category' ? Number(ruleForm.value.product_category_id) : null,
      rate_type: submittedRateType,
      rate_value: rateValueToBasisOrSatang(submittedRateType, ruleForm.value.rate_value_input),
      effective_from: ruleForm.value.effective_from,
      effective_to: ruleForm.value.effective_to || null,
      ...(ruleForm.value.renewal_rate_type
        ? {
            renewal_rate_type: ruleForm.value.renewal_rate_type,
            renewal_rate_value: rateValueToBasisOrSatang(ruleForm.value.renewal_rate_type, ruleForm.value.renewal_rate_value_input),
            renewal_recurs: ruleForm.value.renewal_recurs,
          }
        : {}),
    })
    if (editingRuleId.value) {
      await api.put(`/commission-rules/${editingRuleId.value}`, payload)
    } else {
      await api.post('/commission-rules', payload)
    }
    resetRuleForm()
    // TASK-197 §2.2's server-side side effect (a product's FIRST rule
    // locks in its commission_rate_type) is picked up here: this reload
    // re-fetches products, so the selector correctly disappears on the
    // next "+ เพิ่มกฎคอมมิชชั่น" open for the same product.
    await loadRulesTabData()
  } catch (e) {
    ruleFormError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingRule.value = false
  }
}
async function deleteRule(r: CommissionRuleItem) {
  try {
    await api.delete(`/commission-rules/${r.id}`)
    commissionRules.value = commissionRules.value.filter((x) => x.id !== r.id)
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}
async function loadRulesTabData() {
  const [r, p, pc, o] = await Promise.all([
    api.get<{ data: CommissionRuleItem[] }>('/commission-rules'),
    api.get<{ data: ProductOption[] }>('/products'),
    api.get<{ data: ProductCategoryOption[] }>('/product-categories'),
    api.get<{ data: CommissionOverrideRuleItem[] }>('/commission-override-rules'),
  ])
  commissionRules.value = r.data
  products.value = p.data
  productCategories.value = pc.data
  commissionOverrideRules.value = o.data
  // Readiness needs the structural settings too, but only for plan types
  // some product actually uses — see loadReadinessProbe().
  await loadReadinessProbe()
}

/**
 * TASK-213 Phase 2 — create/edit/delete a TEAM-LEADER rate from this
 * screen. Same endpoint, same Policy, same table as the tab that used to
 * live in /product-catalog; only the address changed.
 *
 * ONE capability is genuinely new: a รูปแบบอัตรา selector. The old form
 * hard-coded `rate_type: 'percentage'` with no way to see or change it,
 * even though StoreCommissionOverrideRuleRequest has always accepted
 * `fixed_satang` and CommissionRateCalculator has always computed it — so
 * "จ่ายหัวหน้าทีมเป็นจำนวนเงินคงที่" was a supported business case that
 * simply had no button.
 */
type RateRecipient = 'agent' | 'leader'
const rateRecipientFilter = ref<'all' | RateRecipient>('all')

const showOverrideForm = ref(false)
const editingOverrideId = ref<number | null>(null)
const savingOverride = ref(false)
const overrideFormError = ref('')
const overrideForm = ref({
  scope: 'company' as RuleScope,
  product_id: '' as number | '',
  product_category_id: '' as number | '',
  rate_type: 'percentage' as RateType,
  rate_value_input: '' as string | number,
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
})

function resetOverrideForm(): void {
  showOverrideForm.value = false
  editingOverrideId.value = null
  overrideFormError.value = ''
  overrideForm.value = {
    scope: 'company',
    product_id: '',
    product_category_id: '',
    rate_type: 'percentage',
    rate_value_input: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_to: '',
  }
}

function openCreateOverrideForm(): void {
  resetOverrideForm()
  showOverrideForm.value = true
}

function openEditOverrideForm(r: CommissionOverrideRuleItem): void {
  editingOverrideId.value = r.id
  overrideFormError.value = ''
  overrideForm.value = {
    scope: r.product ? 'product' : r.product_category ? 'category' : 'company',
    product_id: r.product?.id ?? '',
    product_category_id: r.product_category?.id ?? '',
    rate_type: r.rate_type,
    // Both units are stored ×100 (basis points / satang), so one inverse
    // covers both — same asymmetry rateValueToBasisOrSatang() relies on.
    rate_value_input: r.rate_value / 100,
    effective_from: r.effective_from.slice(0, 10),
    effective_to: r.effective_to?.slice(0, 10) ?? '',
  }
  showOverrideForm.value = true
}

async function submitOverrideRule(): Promise<void> {
  const scope = overrideForm.value.scope
  if (scope === 'product' && !overrideForm.value.product_id) {
    overrideFormError.value = 'กรุณาเลือกสินค้า'

    return
  }
  if (scope === 'category' && !overrideForm.value.product_category_id) {
    overrideFormError.value = 'กรุณาเลือกหมวดหมู่'

    return
  }
  savingOverride.value = true
  overrideFormError.value = ''
  try {
    const body = {
      // Explicit nulls, not omitted keys: an UPDATE that moves a rule from
      // product scope back to the company default has to CLEAR the old
      // column, and an absent key would leave it in place.
      product_id: scope === 'product' ? Number(overrideForm.value.product_id) : null,
      product_category_id: scope === 'category' ? Number(overrideForm.value.product_category_id) : null,
      rate_type: overrideForm.value.rate_type,
      rate_value: rateValueToBasisOrSatang(overrideForm.value.rate_type, overrideForm.value.rate_value_input),
      effective_from: overrideForm.value.effective_from,
      effective_to: overrideForm.value.effective_to || null,
    }
    if (editingOverrideId.value) {
      await api.put(`/commission-override-rules/${editingOverrideId.value}`, body)
    } else {
      await api.post('/commission-override-rules', withCompanyBody(body))
    }
    resetOverrideForm()
    await loadRulesTabData()
  } catch (e) {
    overrideFormError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingOverride.value = false
  }
}

/** 'ทุกสินค้าในบริษัท' / 'หมวดหมู่: X' / 'สินค้า: Y' — same vocabulary as ruleScopeLabel(). */
function overrideScopeLabel(r: CommissionOverrideRuleItem): string {
  if (r.product) return `สินค้า: ${r.product.name}`
  if (r.product_category) return `หมวดหมู่: ${r.product_category.name}`

  return 'ทุกสินค้าในบริษัท'
}

async function deleteOverrideRule(r: CommissionOverrideRuleItem): Promise<void> {
  if (!window.confirm(`ลบอัตราหัวหน้าทีม "${overrideScopeLabel(r)}"?`)) return
  try {
    await api.delete(`/commission-override-rules/${r.id}`)
    commissionOverrideRules.value = commissionOverrideRules.value.filter((x) => x.id !== r.id)
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}

/**
 * TASK-213 Phase 1 — "is this company's config actually able to pay?"
 *
 * Reading the commission services turned up thirteen paths where a
 * misconfiguration means NOBODY IS PAID and the only evidence is a line in
 * the log. Every one of them is deliberate — the sale must never be
 * blocked by a config gap — but until now no screen said so, which meant
 * the first person to notice was an agent asking where their money went.
 *
 * This probe is READ-ONLY and asks only about plan types that at least one
 * product resolves to, so a company using nothing but Unilevel makes no
 * extra requests at all. Failures are swallowed: a probe that cannot
 * answer must not break the page it is only annotating.
 */
const structureReady = ref<Partial<Record<CommissionPlanType, boolean>>>({})

async function loadReadinessProbe(): Promise<void> {
  if (!effectiveCompanyId.value) { structureReady.value = {}; return }
  const inUse = new Set(byCompany(products.value).map((p) => p.effective_plan_type).filter(Boolean) as CommissionPlanType[])
  const next: Partial<Record<CommissionPlanType, boolean>> = {}

  const probes: Promise<void>[] = []
  const probe = async (key: CommissionPlanType, run: () => Promise<boolean>) => {
    try { next[key] = await run() } catch { /* leave undefined = "unknown", never a false alarm */ }
  }

  if (inUse.has('binary')) {
    probes.push(probe('binary', async () => {
      const r = await api.get<{ data: BinarySettings } | ''>(`/commission-binary-settings${companyQuery()}`)

      return r !== ''
    }))
  }
  if (inUse.has('matrix')) {
    probes.push(probe('matrix', async () => {
      const r = await api.get<{ data: MatrixSettings } | ''>(`/commission-matrix-settings${companyQuery()}`)

      return r !== ''
    }))
  }
  if (inUse.has('generation')) {
    probes.push(probe('generation', async () => {
      const [s, rules] = await Promise.all([
        api.get<{ data: GenerationSettingsData } | ''>(`/commission-generation-settings${companyQuery()}`),
        api.get<{ data: GenerationRuleItem[] }>('/commission-generation-rules'),
      ])

      // Depth alone pays nobody — a generation slot with no rate row is
      // consumed silently by GenerationCommissionService.
      return s !== '' && byCompany(rules.data).length > 0
    }))
  }
  if (inUse.has('stairstep_breakaway')) {
    probes.push(probe('stairstep_breakaway', async () => {
      const r = await api.get<{ data: AgentRankItem[] }>('/agent-ranks')

      return byCompany(r.data).length > 0
    }))
  }

  await Promise.all(probes)
  structureReady.value = next
}

// ── Overview helpers (Option B/C — see viewMode block above) ──
function openRuleFormForProduct(p: ProductOption) {
  viewMode.value = 'settings'
  activeTab.value = 'rules'
  resetRuleForm()
  ruleForm.value.scope = 'product'
  ruleForm.value.product_id = p.id
  showRuleForm.value = true
}
const productPlanTypeCounts = computed<Partial<Record<CommissionPlanType, number>>>(() => {
  const counts: Partial<Record<CommissionPlanType, number>> = {}
  for (const p of byCompany(products.value)) {
    if (!p.effective_plan_type) continue
    counts[p.effective_plan_type] = (counts[p.effective_plan_type] ?? 0) + 1
  }
  return counts
})
function isRuleActiveOn(r: CommissionRuleItem, date: Date): boolean {
  if (date < new Date(r.effective_from)) return false
  if (r.effective_to && date > new Date(r.effective_to)) return false
  return true
}
// Mirrors CommissionService's resolution order (server-side, unchanged
// by this UI work): product-specific > category > company-wide default,
// most-specific match wins — same order already documented above as
// RESOLUTION_ORDER_NOTE. Pure read-only preview of already-loaded data,
// no new backend call.
function resolveRuleFor(product: ProductOption): CommissionRuleItem | null {
  const now = new Date()
  const candidates = commissionRules.value.filter((r) => isRuleActiveOn(r, now))
  const categoryId = product.category?.id
  return (
    candidates.find((r) => r.product?.id === product.id) ??
    (categoryId ? candidates.find((r) => r.product_category?.id === categoryId) : undefined) ??
    candidates.find((r) => !r.product && !r.product_category) ??
    null
  )
}

/**
 * TASK-216 — every add/edit form on this page says WHAT IT IS EDITING.
 *
 * Human report, 2026-08-20: "แบบนี้ผมดูไม่ออกเลยว่าผมกำลังแก้ไขตัวไหนอยู่".
 *
 * The forms open INLINE AT THE TOP of the page while the row you clicked
 * แก้ไข on can be several rows further down and scrolled off. The agent
 * rate form had no heading at all — it opened as a bare row of inputs. The
 * only clue to which of five rules you were about to overwrite was the
 * product name buried in a <select> that looks exactly like the one on the
 * create form.
 *
 * These labels read from the FORM, not from the record being edited, so
 * they are also useful while creating: the moment a product is picked the
 * heading names it, and if the wrong one was picked that is visible before
 * บันทึก rather than after.
 */
// Widened to string|number because the two forms declare their id fields
// differently (ruleForm keeps `string | number`, overrideForm `number | ''`)
// — Number() handles both, and narrowing here would only force a cast at
// one of the two call sites.
function scopeTargetLabel(scope: RuleScope, productId: string | number, categoryId: string | number): string {
  if (scope === 'product') {
    const name = products.value.find((p) => p.id === Number(productId))?.name

    return name ? `สินค้า: ${name}` : 'สินค้า: ยังไม่ได้เลือก'
  }
  if (scope === 'category') {
    const name = productCategories.value.find((c) => c.id === Number(categoryId))?.name

    return name ? `หมวดหมู่: ${name}` : 'หมวดหมู่: ยังไม่ได้เลือก'
  }

  return 'ค่าเริ่มต้นทั้งบริษัท'
}

const ruleFormTargetLabel = computed(() =>
  scopeTargetLabel(ruleForm.value.scope, ruleForm.value.product_id, ruleForm.value.product_category_id))

const overrideFormTargetLabel = computed(() =>
  scopeTargetLabel(overrideForm.value.scope, overrideForm.value.product_id, overrideForm.value.product_category_id))

/**
 * TASK-213 r2 — rows that are ACTIVE AT THE SAME TIME IN THE SAME SCOPE.
 *
 * Human report, 2026-08-19: one product carried three live rules —
 * 100 / 150 / 180 บาท, all starting on the same day. `resolveCommissionRule`
 * orders by `effective_from` DESC and takes `->first()`, so with the dates
 * tied the winner is whatever the database happens to return first. Three
 * different payouts, no way to predict which, and the ledger is immutable
 * once written (BR-4).
 *
 * Both services already forbid this (`assertNoOverlap` in
 * CommissionRuleService and CommissionOverrideRuleService), so nothing can
 * create it today — but ADR-035 dropped `cert_tier_id` from the rule scope
 * on 2026-08-18, which retroactively turned rows that were legitimately
 * distinct (one per tier) into rows that collide. The guard cannot see
 * that; it only runs on write.
 *
 * So the check has to live where the existing data is read. "No rule at
 * all" was already surfaced; "too many rules to know which one" is the
 * same class of money bug and was not.
 *
 * Scope key mirrors the server's resolution levels exactly — product,
 * category and company-default never collide with each other.
 */
function ruleScopeKey(r: CommissionRuleItem): string {
  if (r.product) return `product:${r.product.id}`
  if (r.product_category) return `category:${r.product_category.id}`

  return 'company'
}

const conflictingRuleIds = computed<Set<number>>(() => {
  const now = new Date()
  const byScope = new Map<string, CommissionRuleItem[]>()
  for (const r of byCompany(commissionRules.value)) {
    if (!isRuleActiveOn(r, now)) continue
    const key = ruleScopeKey(r)
    byScope.set(key, [...(byScope.get(key) ?? []), r])
  }
  const ids = new Set<number>()
  for (const rows of byScope.values()) {
    if (rows.length > 1) rows.forEach((r) => ids.add(r.id))
  }

  return ids
})

/** Same invariant on the leader side, keyed by the manager's cert tier. */
const conflictingOverrideIds = computed<Set<number>>(() => {
  // TASK-214 — keyed by SCOPE, not by cert tier, because that is what the
  // server now resolves on. Rows that were legitimately distinct per tier
  // become a collision under the new key, which is exactly the situation
  // commission:collapse-override-tiers exists to clean up — so this is the
  // screen that has to show them.
  const byScope = new Map<string, CommissionOverrideRuleItem[]>()
  for (const r of activeOverrideRules.value) {
    const key = r.product ? `product:${r.product.id}` : r.product_category ? `category:${r.product_category.id}` : 'company'
    byScope.set(key, [...(byScope.get(key) ?? []), r])
  }
  const ids = new Set<number>()
  for (const rows of byScope.values()) {
    if (rows.length > 1) rows.forEach((r) => ids.add(r.id))
  }

  return ids
})

const totalConflicts = computed(() => conflictingRuleIds.value.size + conflictingOverrideIds.value.size)

/** How many live rules share the scope this product actually resolves at. */
function conflictCountFor(p: ProductOption): number {
  const resolved = resolveRuleFor(p)
  if (!resolved || !conflictingRuleIds.value.has(resolved.id)) return 0
  const key = ruleScopeKey(resolved)
  const now = new Date()

  return byCompany(commissionRules.value).filter((r) => isRuleActiveOn(r, now) && ruleScopeKey(r) === key).length
}

/**
 * TASK-213 — the leader rows that are live today, most-recent first.
 *
 * Deliberately returns a LIST, not one rate: `commission_override_rules`
 * is keyed by the MANAGER'S OWN cert tier, so "how much does the leader
 * get" has as many answers as there are tiers configured. Collapsing that
 * to a single headline number would be a comfortable lie — the overview
 * says "N อัตรา" instead when they differ.
 */
const activeOverrideRules = computed<CommissionOverrideRuleItem[]>(() => {
  const now = new Date()

  return byCompany(commissionOverrideRules.value)
    .filter((r) => isOverrideActiveOn(r, now))
    .sort((a, b) => b.effective_from.localeCompare(a.effective_from))
})

function isOverrideActiveOn(r: CommissionOverrideRuleItem, on: Date): boolean {
  if (new Date(r.effective_from) > on) return false

  return !r.effective_to || new Date(r.effective_to) >= on
}

/**
 * TASK-214 — the leader rate FOR THIS PRODUCT, resolved with the same
 * order the server uses (product > category > company).
 *
 * Before scoping existed this could only answer "there are N rates, keyed
 * by something this card cannot see", so it printed a count. Now there is
 * one right answer per product and the card can simply say it.
 */
function resolveOverrideFor(product: ProductOption): CommissionOverrideRuleItem | null {
  const rows = activeOverrideRules.value
  const categoryId = product.category?.id

  return (
    rows.find((r) => r.product?.id === product.id) ??
    (categoryId ? rows.find((r) => !r.product && r.product_category?.id === categoryId) : undefined) ??
    rows.find((r) => !r.product && !r.product_category) ??
    null
  )
}

/** '—' / '2.50% · สินค้า: X' for the overview card. */
function leaderRateLabel(product: ProductOption): string {
  const rule = resolveOverrideFor(product)

  return rule ? formatRate(rule.rate_type, rule.rate_value) : '—'
}

/**
 * TASK-213 Phase 1 — can this product actually pay, today?
 *
 * Ordered worst-first: a product with no base rate pays NOBODY, which
 * makes every other observation about it irrelevant. Each level maps to a
 * real code path in the commission services, not to a guess — see the
 * plan doc's §3.4 table for the full list of thirteen.
 */
type ReadinessLevel = 'ok' | 'warn' | 'bad'
function productReadiness(p: ProductOption): { level: ReadinessLevel; message: string } {
  if (!resolveRuleFor(p)) {
    return { level: 'bad', message: 'ยังไม่มีอัตราค่าคอม (ทั้งสินค้า/หมวดหมู่/บริษัท) — ดีลที่ปิดได้จะไม่มีใครได้เงินเลย' }
  }

  // Ranked right below "no rule": having several is not safer than having
  // none — the money still moves, just at an amount nobody chose.
  const clash = conflictCountFor(p)
  if (clash > 1) {
    return {
      level: 'bad',
      message: `มีอัตราซ้อนทับกัน ${clash} รายการในขอบเขตเดียวกัน — ระบบจะหยิบอันไหนก็ได้ ทำนายไม่ได้ และแก้ย้อนหลังไม่ได้เมื่อลงบัญชีแล้ว`,
    }
  }

  const plan = p.effective_plan_type
  if (plan && structureReady.value[plan] === false) {
    return { level: 'bad', message: `บริษัทยังไม่ได้ตั้งค่าโครงสร้าง ${planTypeLabels[plan]} — ตัวแทนผู้ขายได้ แต่ชั้นบนจะไม่ได้อะไร` }
  }

  // Unilevel and Affiliate are the two plans that pay the upline out of
  // commission_override_rules. No row = the leader is skipped silently.
  if (plan === 'unilevel' || plan === 'affiliate') {
    if (!resolveOverrideFor(p)) {
      return { level: 'warn', message: 'ยังไม่มีอัตราหัวหน้าทีมที่ใช้กับสินค้านี้ — หัวหน้าจะไม่ได้ส่วนแบ่งจากดีลนี้' }
    }
    if (conflictingOverrideIds.value.size) {
      return { level: 'bad', message: 'อัตราหัวหน้าทีมซ้อนทับกันใน cert tier เดียวกัน — จำนวนที่หัวหน้าได้ทำนายไม่ได้' }
    }
  }

  return { level: 'ok', message: 'ตั้งค่าครบ พร้อมจ่าย' }
}

const readinessCounts = computed(() => {
  const c = { ok: 0, warn: 0, bad: 0 }
  for (const p of byCompany(products.value)) c[productReadiness(p).level]++

  return c
})

// ── "ทดสอบคำนวณ" simulate modal — direct commission preview only, see
// the caveat text rendered alongside it in the template. ──
const simulateProduct = ref<ProductOption | null>(null)
const simulateAmountThb = ref<string | number>('')
function openSimulate(p: ProductOption) {
  simulateProduct.value = p
  simulateAmountThb.value = p.price_satang ? p.price_satang / 100 : ''
}
function closeSimulate() {
  simulateProduct.value = null
}
const simulateResult = computed<{ rule: CommissionRuleItem | null; amountSatang: number } | null>(() => {
  if (!simulateProduct.value) return null
  const rule = resolveRuleFor(simulateProduct.value)
  if (!rule) return { rule: null, amountSatang: 0 }
  const saleSatang = Math.round(Number(simulateAmountThb.value || 0) * 100)
  const amountSatang = rule.rate_type === 'percentage' ? Math.round((saleSatang * rule.rate_value) / 10000) : rule.rate_value
  return { rule, amountSatang }
})

// ══════════════════════════ Setup Wizard (TASK-037, human-approved 2026-07-22) ══════════════════════════
// Human's mumong 3.1: "การ Setup ค่าคอมแยกสินค้า แบบ wizard" — modeled on the
// CaptivateIQ "Guided Plan Builder" pattern researched earlier this session
// (step-by-step, one decision per screen). Deliberately reuses every
// existing form/submit function already defined above (matrixForm,
// binaryForm, rankSettingsForm, generationSettingsForm, affiliateForm,
// their submit*Settings() functions, and the same /commission-rules
// endpoint the Commission Rules tab already uses) — no new backend calls,
// this is purely a guided sequencing layer over what already shipped and
// passed UAT-012. Scope-controlled deliberately (ag-lead guardrail —
// control scope): the wizard only offers % rates per cert tier (not fixed
// THB) and only the company-wide *settings* singletons (not level-rate /
// rank-ladder / generation-rule list items) — those stay in "การตั้งค่า
// ทั้งหมด" as before; the wizard's summary step links there for anything
// beyond this simplified path.
type WizardStep = 1 | 2 | 3 | 4
const wizardOpen = ref(false)
const wizardStep = ref<WizardStep>(1)
const wizardProductId = ref<number | ''>('')
const wizardPlanChoice = ref<'inherit' | CommissionPlanType>('inherit')
const wizardRateInput = ref<string | number>('')
const wizardSavingPlanType = ref(false)
const wizardPlanTypeError = ref('')
const wizardSavingRates = ref(false)
const wizardRateError = ref('')
const wizardSavingStructure = ref(false)
const wizardStructureError = ref('')
const wizardStructureEditMode = ref(false)

const wizardProduct = computed<ProductOption | null>(() => byCompany(products.value).find((p) => p.id === wizardProductId.value) ?? null)
// TASK-198 — same lookup pattern as ruleFormProductRateTypeLocked (§3.4)
// above, applied at the wizard's own product-selection point (step 1).
// null/'percentage' → wizard proceeds exactly as before (its rate-entry
// step, TASK-037, only ever submits 'percentage' by original design).
// 'fixed_satang' → this product's rate format is already locked to fixed
// THB by another form (TASK-197 §2.2 enforcement) — letting the user
// continue to step 2 would only 422 at wizardSaveRates() after they've
// filled in % values, so block right here instead.
const wizardProductRateTypeLocked = computed<RateType | null>(() => wizardProduct.value?.commission_rate_type ?? null)
const wizardProductBlockedFixedSatang = computed(() => wizardProductRateTypeLocked.value === 'fixed_satang')
const wizardEffectivePlanType = computed<CommissionPlanType | null>(() => {
  if (!wizardProduct.value) return null
  return wizardPlanChoice.value === 'inherit' ? (wizardProduct.value.effective_plan_type ?? null) : wizardPlanChoice.value
})
function wizardNeedsStructureStep(): boolean {
  const pt = wizardEffectivePlanType.value
  return !!pt && !!planTypeToTab[pt]
}
function wizardStructureAlreadySet(): boolean {
  const pt = wizardEffectivePlanType.value
  if (pt === 'matrix') return !!matrixSettings.value
  if (pt === 'binary') return !!binarySettings.value
  if (pt === 'stairstep_breakaway') return !!agentRankSettings.value
  if (pt === 'generation') return !!generationSettings.value
  if (pt === 'affiliate') return !!affiliateSettings.value
  return true
}
// Bug found + fixed 2026-07-22 (live-testing): wizardPlanChoice used to
// default to the literal 'inherit' every time the wizard opened, even for
// a product that already had its OWN explicit override set. Clicking
// "ถัดไป" on step 1 without touching the dropdown then wrote
// commission_plan_type: null back to that product — silently reverting a
// real per-product override to "inherit from company" (corrupted QA
// Stairstep Package's plan type from stairstep_breakaway to unilevel
// during testing; repaired via direct API call, see chat). Fix: always
// initialize the dropdown from the SELECTED product's actual current
// commission_plan_type, so "ถัดไป" without touching the field is a no-op.
watch(wizardProductId, (id) => {
  const p = byCompany(products.value).find((pp) => pp.id === id)
  wizardPlanChoice.value = p?.commission_plan_type ?? 'inherit'
})
function openWizard(preselectProductId?: number) {
  wizardOpen.value = true
  wizardStep.value = 1
  wizardRateInput.value = ''
  wizardPlanTypeError.value = ''
  wizardRateError.value = ''
  wizardStructureError.value = ''
  wizardStructureEditMode.value = false
  wizardProductId.value = preselectProductId ?? ''
  // Set directly (don't rely solely on the watch above, which won't fire
  // if the id happens to be unchanged from a previous open) — see bug
  // note above for why this must reflect the product's REAL current value.
  const preselected = preselectProductId ? byCompany(products.value).find((pp) => pp.id === preselectProductId) : null
  wizardPlanChoice.value = preselected?.commission_plan_type ?? 'inherit'
}
function closeWizard() {
  wizardOpen.value = false
}
// TASK-198 — the redirect target for a product already locked to
// fixed_satang: same "+ เพิ่มอัตราคอมตาม tier" flow the product card's own
// button uses (openRuleFormForProduct, TASK-197 §3.1), just triggered from
// inside the blocked wizard instead. Closes the wizard first so the admin
// lands on the real form with nothing else competing for attention.
function wizardGoToProductRateForm() {
  if (!wizardProduct.value) return
  const p = wizardProduct.value
  closeWizard()
  openRuleFormForProduct(p)
}
function findExactProductRule(productId: number): CommissionRuleItem | undefined {
  return commissionRules.value.find((r) => r.product?.id === productId)
}
async function wizardConfirmProductAndPlan() {
  if (!wizardProduct.value) {
    wizardPlanTypeError.value = 'กรุณาเลือกสินค้า'
    return
  }
  wizardSavingPlanType.value = true
  wizardPlanTypeError.value = ''
  try {
    const desired = wizardPlanChoice.value === 'inherit' ? null : wizardPlanChoice.value
    if (desired !== (wizardProduct.value.commission_plan_type ?? null)) {
      await api.put(`/products/${wizardProduct.value.id}`, withCompanyBody({ commission_plan_type: desired }))
      await loadRulesTabData() // refresh products' effective_plan_type
    }
    const existing = wizardProduct.value ? findExactProductRule(wizardProduct.value.id) : undefined
    wizardRateInput.value = existing ? existing.rate_value / 100 : ''
    wizardStep.value = 2
  } catch (e) {
    wizardPlanTypeError.value = apiErrorMessage(e, 'บันทึกรูปแบบแผนไม่สำเร็จ')
  } finally {
    wizardSavingPlanType.value = false
  }
}
async function wizardEnsureStructureLoaded() {
  const tab = wizardEffectivePlanType.value ? planTypeToTab[wizardEffectivePlanType.value] : undefined
  if (tab && !loadedTabs.value.has(tab)) await loadTab(tab)
}
function wizardPrefillStructureForm() {
  const pt = wizardEffectivePlanType.value
  if (pt === 'matrix') syncMatrixForm(matrixSettings.value)
  else if (pt === 'binary') syncBinaryForm(binarySettings.value)
  else if (pt === 'stairstep_breakaway') syncRankSettingsForm(agentRankSettings.value)
  else if (pt === 'generation') syncGenerationSettingsForm(generationSettings.value)
  else if (pt === 'affiliate') syncAffiliateForm(affiliateSettings.value)
}
async function wizardSaveRates() {
  if (!wizardProduct.value) return
  wizardSavingRates.value = true
  wizardRateError.value = ''
  try {
    const input = wizardRateInput.value
    if (input !== '' && input !== undefined && input !== null) {
      const existing = findExactProductRule(wizardProduct.value.id)
      const payload = withCompanyBody({
        product_id: wizardProduct.value.id,
        product_category_id: null,
        rate_type: 'percentage' as RateType,
        rate_value: rateValueToBasisOrSatang('percentage', input),
        effective_from: existing?.effective_from ?? new Date().toISOString().slice(0, 10),
        effective_to: null,
      })
      if (existing) await api.put(`/commission-rules/${existing.id}`, payload)
      else await api.post('/commission-rules', payload)
    }
    await loadRulesTabData()
    if (wizardNeedsStructureStep()) {
      await wizardEnsureStructureLoaded()
      wizardPrefillStructureForm()
      wizardStructureEditMode.value = !wizardStructureAlreadySet()
      wizardStep.value = 3
    } else {
      wizardStep.value = 4
    }
  } catch (e) {
    wizardRateError.value = apiErrorMessage(e, 'บันทึกอัตราไม่สำเร็จ')
  } finally {
    wizardSavingRates.value = false
  }
}
async function wizardSaveStructure() {
  wizardSavingStructure.value = true
  wizardStructureError.value = ''
  try {
    const pt = wizardEffectivePlanType.value
    if (pt === 'matrix') await submitMatrixSettings()
    else if (pt === 'binary') await submitBinarySettings()
    else if (pt === 'stairstep_breakaway') await submitRankSettings()
    else if (pt === 'generation') await submitGenerationSettings()
    else if (pt === 'affiliate') await submitAffiliateSettings()
    wizardStep.value = 4
  } catch (e) {
    wizardStructureError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    wizardSavingStructure.value = false
  }
}
function wizardSkipStructure() {
  wizardStep.value = 4
}
const wizardSummaryRate = computed<CommissionRuleItem | null>(() => {
  if (!wizardProduct.value) return null
  return findExactProductRule(wizardProduct.value.id) ?? null
})

// ══════════════════════════ Binary (TASK-029) ══════════════════════════
type CycleFrequency = 'weekly' | 'biweekly' | 'monthly'
interface BinarySettings {
  matched_rate_type: RateType
  matched_rate_value: number
  cycle_frequency: CycleFrequency
  payout_cap_satang: number | null
  carry_over_unmatched: boolean
}
interface BinaryMatchingCycleItem {
  id: number
  agent_id: number
  period_start: string
  period_end: string
  left_volume_satang: number
  right_volume_satang: number
  matched_volume_satang: number
  unmatched_carried_satang: number
  commission_ledger_id: number | null
  created_at: string
}
const binarySettings = ref<BinarySettings | null>(null)
const binaryCycles = ref<BinaryMatchingCycleItem[]>([])
const binaryForm = ref({
  matched_rate_type: 'percentage' as RateType,
  matched_rate_value_input: '' as string | number,
  cycle_frequency: 'weekly' as CycleFrequency,
  payout_cap_thb: '' as string | number, // '' = uncapped
  carry_over_unmatched: false,
})
const savingBinary = ref(false)
const binaryError = ref('')
function syncBinaryForm(s: BinarySettings | null) {
  if (!s) return
  binaryForm.value = {
    matched_rate_type: s.matched_rate_type,
    matched_rate_value_input: s.matched_rate_type === 'percentage' ? s.matched_rate_value / 100 : s.matched_rate_value / 100,
    cycle_frequency: s.cycle_frequency,
    payout_cap_thb: s.payout_cap_satang ? s.payout_cap_satang / 100 : '',
    carry_over_unmatched: s.carry_over_unmatched,
  }
}
async function submitBinarySettings() {
  savingBinary.value = true
  binaryError.value = ''
  try {
    const payload = withCompanyBody({
      matched_rate_type: binaryForm.value.matched_rate_type,
      matched_rate_value: rateValueToBasisOrSatang(binaryForm.value.matched_rate_type, binaryForm.value.matched_rate_value_input),
      cycle_frequency: binaryForm.value.cycle_frequency,
      payout_cap_satang: binaryForm.value.payout_cap_thb === '' ? null : Math.round(Number(binaryForm.value.payout_cap_thb) * 100),
      carry_over_unmatched: binaryForm.value.carry_over_unmatched,
    })
    const res = await api.put<{ data: BinarySettings }>(`/commission-binary-settings${companyQuery()}`, payload)
    binarySettings.value = res.data
  } catch (e) {
    binaryError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingBinary.value = false
  }
}
async function loadBinaryTabData() {
  if (!effectiveCompanyId.value) return
  const [s, c] = await Promise.all([
    api.get<{ data: BinarySettings } | ''>(`/commission-binary-settings${companyQuery()}`),
    api.get<{ data: BinaryMatchingCycleItem[] }>('/binary-matching-cycles').catch(() => ({ data: [] as BinaryMatchingCycleItem[] })),
  ])
  binarySettings.value = s === '' ? null : s.data
  binaryCycles.value = c.data
  syncBinaryForm(binarySettings.value)
}

// ══════════════════════════ Matrix (TASK-030) ══════════════════════════
interface MatrixSettings { width: number; depth: number; spillover_rule: string }
interface MatrixLevelRateItem {
  id: number
  company_id: number
  level: number
  rate_type: RateType
  rate_value: number
  effective_from: string
  effective_to: string | null
}
const matrixSettings = ref<MatrixSettings | null>(null)
const matrixLevelRates = ref<MatrixLevelRateItem[]>([])
const matrixForm = ref({ width: '' as string | number, depth: '' as string | number, spillover_rule: 'breadth' })
const savingMatrix = ref(false)
const matrixError = ref('')
function syncMatrixForm(s: MatrixSettings | null) {
  if (!s) return
  matrixForm.value = { width: s.width, depth: s.depth, spillover_rule: s.spillover_rule }
}
async function submitMatrixSettings() {
  savingMatrix.value = true
  matrixError.value = ''
  try {
    const payload = withCompanyBody({
      width: Number(matrixForm.value.width),
      depth: Number(matrixForm.value.depth),
      spillover_rule: matrixForm.value.spillover_rule,
    })
    const res = await api.put<{ data: MatrixSettings }>(`/commission-matrix-settings${companyQuery()}`, payload)
    matrixSettings.value = res.data
  } catch (e) {
    matrixError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingMatrix.value = false
  }
}
const levelRateForm = ref({
  level: '' as string | number,
  rate_type: 'percentage' as RateType,
  rate_value_input: '' as string | number,
  effective_from: new Date().toISOString().slice(0, 10),
})
const showLevelRateForm = ref(false)
const savingLevelRate = ref(false)
async function submitLevelRate() {
  savingLevelRate.value = true
  matrixError.value = ''
  try {
    await api.post(
      '/commission-matrix-level-rates',
      withCompanyBody({
        level: Number(levelRateForm.value.level),
        rate_type: levelRateForm.value.rate_type,
        rate_value: rateValueToBasisOrSatang(levelRateForm.value.rate_type, levelRateForm.value.rate_value_input),
        effective_from: levelRateForm.value.effective_from,
      }),
    )
    levelRateForm.value = { level: '', rate_type: 'percentage', rate_value_input: '', effective_from: new Date().toISOString().slice(0, 10) }
    showLevelRateForm.value = false
    await loadMatrixTabData()
  } catch (e) {
    matrixError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ — level นี้อาจมีอยู่แล้ว')
  } finally {
    savingLevelRate.value = false
  }
}
async function deleteLevelRate(item: MatrixLevelRateItem) {
  try {
    await api.delete(`/commission-matrix-level-rates/${item.id}`)
    matrixLevelRates.value = matrixLevelRates.value.filter((x) => x.id !== item.id)
  } catch (e) {
    matrixError.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}
async function loadMatrixTabData() {
  if (!effectiveCompanyId.value) return
  const [s, lr] = await Promise.all([
    api.get<{ data: MatrixSettings } | ''>(`/commission-matrix-settings${companyQuery()}`),
    api.get<{ data: MatrixLevelRateItem[] }>('/commission-matrix-level-rates'),
  ])
  matrixSettings.value = s === '' ? null : s.data
  matrixLevelRates.value = byCompany(lr.data).sort((a, b) => a.level - b.level)
  syncMatrixForm(matrixSettings.value)
}
// Simple visual grid of the configured width x depth (capped for
// legibility — a 100x100 config just shows a truncated preview, not a
// literal 10,000-cell render).
const matrixPreviewGrid = computed(() => {
  const w = Math.min(Number(matrixSettings.value?.width ?? 0), 8)
  const d = Math.min(Number(matrixSettings.value?.depth ?? 0), 5)
  return { w, d, truncatedWidth: Number(matrixSettings.value?.width ?? 0) > 8, truncatedDepth: Number(matrixSettings.value?.depth ?? 0) > 5 }
})

// ══════════════════════════ Agent Ranks / Stairstep-Breakaway (TASK-031) ══════════════════════════
type RecalcFrequency = 'daily' | 'weekly' | 'monthly'
interface AgentRankSettingsData { trailing_window_days: number; recalculation_frequency: RecalcFrequency }
interface AgentRankItem {
  id: number
  company_id: number
  name: string
  volume_threshold: number
  sort_order: number
  rate_type: RateType
  rate_value: number
  is_breakaway_rank: boolean
}
const agentRankSettings = ref<AgentRankSettingsData | null>(null)
const agentRanks = ref<AgentRankItem[]>([])
const rankSettingsForm = ref({ trailing_window_days: '' as string | number, recalculation_frequency: 'monthly' as RecalcFrequency })
const savingRankSettings = ref(false)
const rankError = ref('')
function syncRankSettingsForm(s: AgentRankSettingsData | null) {
  if (!s) return
  rankSettingsForm.value = { trailing_window_days: s.trailing_window_days, recalculation_frequency: s.recalculation_frequency }
}
async function submitRankSettings() {
  savingRankSettings.value = true
  rankError.value = ''
  try {
    const res = await api.put<{ data: AgentRankSettingsData }>(
      `/agent-rank-settings${companyQuery()}`,
      withCompanyBody({
        trailing_window_days: Number(rankSettingsForm.value.trailing_window_days),
        recalculation_frequency: rankSettingsForm.value.recalculation_frequency,
      }),
    )
    agentRankSettings.value = res.data
  } catch (e) {
    rankError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingRankSettings.value = false
  }
}
const rankForm = ref({
  name: '',
  volume_threshold_thb: '' as string | number,
  sort_order: '' as string | number,
  rate_type: 'percentage' as RateType,
  rate_value_input: '' as string | number,
  is_breakaway_rank: false,
})
const showRankForm = ref(false)
const editingRankId = ref<number | null>(null)
const savingRank = ref(false)
function resetRankForm() {
  rankForm.value = { name: '', volume_threshold_thb: '', sort_order: '', rate_type: 'percentage', rate_value_input: '', is_breakaway_rank: false }
  editingRankId.value = null
  showRankForm.value = false
}
function openEditRank(r: AgentRankItem) {
  editingRankId.value = r.id
  rankForm.value = {
    name: r.name,
    volume_threshold_thb: r.volume_threshold / 100,
    sort_order: r.sort_order,
    rate_type: r.rate_type,
    rate_value_input: r.rate_value / 100,
    is_breakaway_rank: r.is_breakaway_rank,
  }
  showRankForm.value = true
}
async function submitRank() {
  savingRank.value = true
  rankError.value = ''
  try {
    const payload = withCompanyBody({
      name: rankForm.value.name,
      volume_threshold: Math.round(Number(rankForm.value.volume_threshold_thb) * 100),
      sort_order: Number(rankForm.value.sort_order),
      rate_type: rankForm.value.rate_type,
      rate_value: rateValueToBasisOrSatang(rankForm.value.rate_type, rankForm.value.rate_value_input),
      is_breakaway_rank: rankForm.value.is_breakaway_rank,
    })
    if (editingRankId.value) {
      await api.put(`/agent-ranks/${editingRankId.value}`, payload)
    } else {
      await api.post('/agent-ranks', payload)
    }
    resetRankForm()
    await loadRanksTabData()
  } catch (e) {
    rankError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingRank.value = false
  }
}
async function deleteRank(r: AgentRankItem) {
  try {
    await api.delete(`/agent-ranks/${r.id}`)
    agentRanks.value = agentRanks.value.filter((x) => x.id !== r.id)
  } catch (e) {
    rankError.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}
async function loadRanksTabData() {
  if (!effectiveCompanyId.value) return
  const [s, r] = await Promise.all([
    api.get<{ data: AgentRankSettingsData } | ''>(`/agent-rank-settings${companyQuery()}`),
    api.get<{ data: AgentRankItem[] }>('/agent-ranks'),
  ])
  agentRankSettings.value = s === '' ? null : s.data
  agentRanks.value = byCompany(r.data).sort((a, b) => a.sort_order - b.sort_order)
  syncRankSettingsForm(agentRankSettings.value)
}

// ══════════════════════════ Generation (TASK-031) ══════════════════════════
interface GenerationSettingsData { max_generation_depth: number }
interface GenerationRuleItem {
  id: number
  company_id: number
  generation_number: number
  rate_type: RateType
  rate_value: number
  effective_from: string
  effective_to: string | null
}
const generationSettings = ref<GenerationSettingsData | null>(null)
const generationRules = ref<GenerationRuleItem[]>([])
const generationSettingsForm = ref({ max_generation_depth: '' as string | number })
const savingGenerationSettings = ref(false)
const generationError = ref('')
function syncGenerationSettingsForm(s: GenerationSettingsData | null) {
  if (!s) return
  generationSettingsForm.value = { max_generation_depth: s.max_generation_depth }
}
async function submitGenerationSettings() {
  savingGenerationSettings.value = true
  generationError.value = ''
  try {
    const res = await api.put<{ data: GenerationSettingsData }>(
      `/commission-generation-settings${companyQuery()}`,
      withCompanyBody({ max_generation_depth: Number(generationSettingsForm.value.max_generation_depth) }),
    )
    generationSettings.value = res.data
  } catch (e) {
    generationError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingGenerationSettings.value = false
  }
}
const generationRuleForm = ref({
  generation_number: '' as string | number,
  rate_type: 'percentage' as RateType,
  rate_value_input: '' as string | number,
  effective_from: new Date().toISOString().slice(0, 10),
})
const showGenerationRuleForm = ref(false)
const savingGenerationRule = ref(false)
async function submitGenerationRule() {
  savingGenerationRule.value = true
  generationError.value = ''
  try {
    await api.post(
      '/commission-generation-rules',
      withCompanyBody({
        generation_number: Number(generationRuleForm.value.generation_number),
        rate_type: generationRuleForm.value.rate_type,
        rate_value: rateValueToBasisOrSatang(generationRuleForm.value.rate_type, generationRuleForm.value.rate_value_input),
        effective_from: generationRuleForm.value.effective_from,
      }),
    )
    generationRuleForm.value = { generation_number: '', rate_type: 'percentage', rate_value_input: '', effective_from: new Date().toISOString().slice(0, 10) }
    showGenerationRuleForm.value = false
    await loadGenerationTabData()
  } catch (e) {
    generationError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ — generation นี้อาจมีอยู่แล้ว')
  } finally {
    savingGenerationRule.value = false
  }
}
async function deleteGenerationRule(item: GenerationRuleItem) {
  try {
    await api.delete(`/commission-generation-rules/${item.id}`)
    generationRules.value = generationRules.value.filter((x) => x.id !== item.id)
  } catch (e) {
    generationError.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}
async function loadGenerationTabData() {
  if (!effectiveCompanyId.value) return
  const [s, r] = await Promise.all([
    api.get<{ data: GenerationSettingsData } | ''>(`/commission-generation-settings${companyQuery()}`),
    api.get<{ data: GenerationRuleItem[] }>('/commission-generation-rules'),
  ])
  generationSettings.value = s === '' ? null : s.data
  generationRules.value = byCompany(r.data).sort((a, b) => a.generation_number - b.generation_number)
  syncGenerationSettingsForm(generationSettings.value)
}

// ══════════════════════════ Affiliate (TASK-032/033) ══════════════════════════
interface AffiliateAttributionSettingsData { attribution_window_days: number; new_vs_returning_rate_differential_enabled: boolean }
const affiliateSettings = ref<AffiliateAttributionSettingsData | null>(null)
const affiliateForm = ref({ attribution_window_days: '' as string | number, new_vs_returning_rate_differential_enabled: false })
const savingAffiliate = ref(false)
const affiliateError = ref('')
function syncAffiliateForm(s: AffiliateAttributionSettingsData | null) {
  if (!s) return
  affiliateForm.value = { attribution_window_days: s.attribution_window_days, new_vs_returning_rate_differential_enabled: s.new_vs_returning_rate_differential_enabled }
}
async function submitAffiliateSettings() {
  savingAffiliate.value = true
  affiliateError.value = ''
  try {
    const res = await api.put<{ data: AffiliateAttributionSettingsData }>(
      `/affiliate-attribution-settings${companyQuery()}`,
      withCompanyBody({
        attribution_window_days: Number(affiliateForm.value.attribution_window_days),
        new_vs_returning_rate_differential_enabled: affiliateForm.value.new_vs_returning_rate_differential_enabled,
      }),
    )
    affiliateSettings.value = res.data
  } catch (e) {
    affiliateError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingAffiliate.value = false
  }
}
async function loadAffiliateTabData() {
  if (!effectiveCompanyId.value) return
  const s = await api.get<{ data: AffiliateAttributionSettingsData } | ''>(`/affiliate-attribution-settings${companyQuery()}`)
  affiliateSettings.value = s === '' ? null : s.data
  syncAffiliateForm(affiliateSettings.value)
}

// ── Tab-lazy loading — avoid firing all 6 tabs' worth of requests on
// mount; each tab loads its own data the first time it's opened, and
// again whenever the Super Admin's selected company changes. ──
const loadedTabs = ref<Set<Tab>>(new Set())
async function loadTab(tab: Tab) {
  loading.value = true
  errorMessage.value = ''
  try {
    if (tab === 'rules') await loadRulesTabData()
    else if (tab === 'binary') await loadBinaryTabData()
    else if (tab === 'matrix') await loadMatrixTabData()
    else if (tab === 'ranks') await loadRanksTabData()
    else if (tab === 'generation') await loadGenerationTabData()
    else if (tab === 'affiliate') await loadAffiliateTabData()
    loadedTabs.value.add(tab)
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
watch(activeTab, (tab) => {
  if (!loadedTabs.value.has(tab)) loadTab(tab)
  if (tab === 'rules' && !hideResolutionOrderNote.value) openResolutionOrderModal()
})
watch(() => activeCompany.companyId, () => {
  // Company changed (Super Admin) — every tab's cached data is now
  // stale, force a reload next time each is viewed.
  loadedTabs.value.clear()
  if (activeTab.value !== 'rules') loadTab(activeTab.value)
  // The rules tab is deliberately NOT refetched: it loads every company's
  // rows once and narrows them with byCompany(). The readiness probe is
  // the exception — it asks per-company endpoints (companyQuery()), so
  // leaving it alone would keep showing the previous company's verdict on
  // this company's products, which is worse than showing nothing.
  else void loadReadinessProbe()
})
onMounted(async () => {
  await activeCompany.loadCompanies()
  await loadTab('rules')
  if (!hideResolutionOrderNote.value) openResolutionOrderModal()
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="money"
      title="แผนคอมมิชชั่น"
      subtitle="ตั้งค่ารูปแบบคอมมิชชั่นทุกแบบของบริษัท"
      description="Unilevel/Binary/Matrix/Stairstep-Breakaway/Generation/Affiliate (ADR-011) — ตั้งค่าที่นี่หลังเลือกรูปแบบที่หน้าจัดการบริษัท"
      accent-color="brand"
      storage-key="commission-plans"
    >
      <template #tabs>
        <div class="flex gap-1 px-4 py-2">
          <button
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold whitespace-nowrap transition-colors"
            :class="viewMode === 'overview' ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="viewMode = 'overview'"
          >
            <Icon name="box" :size="16" />
            ภาพรวมสินค้า
          </button>
          <button
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold whitespace-nowrap transition-colors"
            :class="viewMode === 'settings' ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="viewMode = 'settings'"
          >
            <Icon name="list" :size="16" />
            การตั้งค่าทั้งหมด
          </button>
        </div>
        <div v-if="viewMode === 'settings'" class="flex gap-1 px-4 py-2 overflow-x-auto border-t border-slate-100">
          <button
            v-for="t in tabDefs"
            :key="t.key"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key"
          >
            <Icon :name="t.icon" :size="16" />
            {{ t.label }}
            <span v-if="tabToPlanType[t.key] && productPlanTypeCounts[tabToPlanType[t.key]!]" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-slate-200 text-slate-600">
              {{ productPlanTypeCounts[tabToPlanType[t.key]!] }}
            </span>
          </button>
        </div>
      </template>
    </HeroHeader>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <EmptyState
      v-if="activeCompany.requiresCompanyPick"
      icon="building"
      title="กรุณาเลือกบริษัทก่อน"
      message="กดปุ่ม “ทุกบริษัท” มุมขวาบนของหน้าจอ แล้วเลือกบริษัท เพื่อดูและตั้งค่าแผนคอมมิชชั่น"
      class="mt-4"
    />

    <template v-else>
      <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />

      <template v-else>
        <!-- ═══════════ ภาพรวมสินค้า (Option B/C — new, additive default view) ═══════════ -->
        <section v-if="viewMode === 'overview'" class="mt-4">
          <!-- Setup hub (มุมมองที่ 3, human-approved 2026-07-22): จุดเดียวที่รวมทาง
               เข้าสู่การ Setup ทั้ง 3 อย่าง — Wizard ต่อสินค้า (ในหน้านี้เอง),
               แผนมาตรฐานบริษัท (CompanyManagementView), Gamification
               (GamificationConfigView). ไม่ได้ย้าย route จริงมารวมกัน (ความเสี่ยง
               สูงกว่า/นอกขอบเขตที่ตกลงไว้) — เป็นแค่ทางลัดเข้าออกจุดเดียว. -->
          <div class="mb-4 p-4 rounded-xl bg-white/95 border border-slate-200">
            <p class="text-sm font-bold text-slate-900 mb-2">การ Setup</p>
            <div class="flex flex-wrap gap-2">
              <button class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 flex items-center gap-1.5" @click="openWizard()">
                <Icon name="sparkles" :size="14" />
                เริ่ม Wizard ตั้งค่าคอมมิชชั่นสินค้า
              </button>
              <RouterLink :to="{ name: 'company-management' }" class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 text-xs font-bold hover:bg-slate-50 flex items-center gap-1.5">
                <Icon name="building" :size="14" />
                แผนมาตรฐานบริษัท
              </RouterLink>
              <RouterLink :to="{ name: 'gamification-config' }" class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 text-xs font-bold hover:bg-slate-50 flex items-center gap-1.5">
                <Icon name="trophy" :size="14" />
                ตั้งค่า Gamification
              </RouterLink>
            </div>
          </div>

          <!-- TASK-213 Phase 1 — the headline. Thirteen code paths can
               leave a closed deal paying nobody, every one of them
               silently and by design (the sale must not be blocked by a
               config gap). This is the first screen that says so BEFORE a
               real deal hits one of them. -->
          <div v-if="byCompany(products).length" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 flex items-center gap-4 flex-wrap">
            <div>
              <p class="text-sm font-bold text-slate-900">ความพร้อมจ่ายค่าคอม</p>
              <p class="text-xs text-slate-400 mt-0.5">ตรวจจากอัตราและโครงสร้างที่ตั้งไว้จริง ณ วันนี้</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap ml-auto">
              <span class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-xs font-bold">พร้อมจ่าย {{ readinessCounts.ok }}</span>
              <span v-if="readinessCounts.warn" class="px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 text-xs font-bold">ควรตรวจสอบ {{ readinessCounts.warn }}</span>
              <span v-if="readinessCounts.bad" class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs font-bold">ต้องแก้ไข {{ readinessCounts.bad }}</span>
              <!-- Shown even when no product currently RESOLVES to the
                   clashing scope (e.g. two live company-default rows): the
                   collision is real and will bite the first product that
                   falls through to it. -->
              <button
                v-if="totalConflicts"
                class="px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700"
                @click="goToSettingsTab('rules')"
              >
                อัตราซ้อนทับ {{ totalConflicts }} → ดูรายการ
              </button>
            </div>
          </div>

          <p class="text-xs text-slate-400 mb-3">เลือกสินค้าเพื่อดูอัตราคอมมิชชั่นที่ใช้งานจริง และทดสอบคำนวณตัวอย่าง (ไม่ใช่การขายจริง)</p>
          <EmptyState v-if="!byCompany(products).length" icon="money" title="ยังไม่มีสินค้า" />
          <div v-else class="space-y-3">
            <div v-for="p in byCompany(products)" :key="p.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
              <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                  <p class="text-sm font-bold text-slate-900">
                    {{ p.name }}
                    <span class="ml-1 text-xs font-bold px-2 py-0.5 rounded-lg bg-brand-50 text-brand-700">{{ p.effective_plan_type ? planTypeLabels[p.effective_plan_type] : '—' }}</span>
                    <span v-if="!p.commission_plan_type" class="ml-1 text-xs font-normal text-slate-400">(สืบทอดจากบริษัท)</span>
                  </p>
                  <p class="text-xs text-slate-400 mt-0.5">{{ p.category?.name ?? 'ไม่มีหมวดหมู่' }}<span v-if="p.price_satang"> · {{ formatSatang(p.price_satang) }}</span></p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                  <button class="px-3 py-1.5 rounded-lg text-slate-600 border border-slate-200 text-xs font-bold hover:bg-slate-50" @click="openSimulate(p)">
                    ทดสอบคำนวณ
                  </button>
                  <button class="px-3 py-1.5 rounded-lg text-brand-700 border border-brand-200 bg-brand-50 text-xs font-bold hover:bg-brand-100 flex items-center gap-1" @click="openWizard(p.id)">
                    <Icon name="sparkles" :size="12" />
                    Wizard
                  </button>
                  <!-- ADR-035 — Unilevel is now flat-rate (one rate per
                       product/category/company scope, no cert-tier
                       dimension). Button label no longer says "ตาม tier";
                       wording flips to "แก้ไข" once a rule already resolves
                       for this product (openRuleFormForProduct still pins
                       scope='product' + this product's id). -->
                  <button class="btn-primary" @click="openRuleFormForProduct(p)">
                    {{ resolveRuleFor(p) ? 'แก้ไขอัตราคอมมิชชั่น' : '+ ตั้งอัตราคอมมิชชั่น' }}
                  </button>
                </div>
              </div>

              <!-- TASK-213 Phase 1 — who gets what, on one line each. The
                   leader column used to exist nowhere on this screen. -->
              <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div class="px-3 py-2 rounded-lg bg-slate-50 border border-slate-100">
                  <p class="text-[11px] font-bold text-slate-400">ตัวแทนผู้ขายได้</p>
                  <p class="text-sm font-bold" :class="resolveRuleFor(p) ? 'text-slate-900' : 'text-rose-600'">
                    {{ resolveRuleFor(p) ? formatRate(resolveRuleFor(p)!.rate_type, resolveRuleFor(p)!.rate_value) : 'ยังไม่ได้ตั้ง' }}
                    <span v-if="resolveRuleFor(p)" class="text-[11px] font-normal text-slate-400">· {{ ruleScopeLabel(resolveRuleFor(p)!) }}</span>
                  </p>
                </div>
                <div class="px-3 py-2 rounded-lg bg-slate-50 border border-slate-100">
                  <p class="text-[11px] font-bold text-slate-400">
                    หัวหน้าทีมได้
                    <!-- Only these two plans pay the upline from
                         commission_override_rules; for the others the
                         upline is paid by that plan's own structure, so
                         showing this number there would be wrong. -->
                    <span v-if="p.effective_plan_type && p.effective_plan_type !== 'unilevel' && p.effective_plan_type !== 'affiliate'" class="font-normal">
                      (ตามโครงสร้าง {{ planTypeLabels[p.effective_plan_type] }})
                    </span>
                  </p>
                  <p class="text-sm font-bold text-slate-900">
                    <template v-if="p.effective_plan_type === 'unilevel' || p.effective_plan_type === 'affiliate'">
                      <span :class="resolveOverrideFor(p) ? '' : 'text-amber-600'">{{ leaderRateLabel(p) }}</span>
                      <span v-if="resolveOverrideFor(p)" class="text-[11px] font-normal text-slate-400"> · {{ overrideScopeLabel(resolveOverrideFor(p)!) }}</span>
                      <span v-if="p.effective_plan_type === 'affiliate'" class="text-[11px] font-normal text-slate-400"> · จ่ายชั้นเดียว</span>
                      <span v-else class="text-[11px] font-normal text-slate-400"> · จ่ายทั้งสาย</span>
                    </template>
                    <span v-else class="text-slate-400 font-normal text-xs">ดูที่แท็บ {{ p.effective_plan_type ? planTypeLabels[p.effective_plan_type] : '—' }}</span>
                  </p>
                </div>
              </div>

              <!-- Replaces the old blanket amber banner, which fired for
                   EVERY product on a structured plan whether or not the
                   structure was actually missing — so it said nothing and
                   was learned to be ignored. This one is silent when the
                   config is fine. -->
              <div
                v-if="productReadiness(p).level !== 'ok'"
                class="mt-3 px-3 py-2 rounded-lg text-xs flex items-center justify-between gap-2 flex-wrap"
                :class="productReadiness(p).level === 'bad' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'"
              >
                <span class="font-bold">{{ productReadiness(p).level === 'bad' ? '●' : '!' }} {{ productReadiness(p).message }}</span>
                <button
                  v-if="p.effective_plan_type && planTypeToTab[p.effective_plan_type] && structureReady[p.effective_plan_type] === false"
                  class="font-bold whitespace-nowrap hover:underline"
                  @click="goToSettingsTab(planTypeToTab[p.effective_plan_type]!)"
                >
                  ไปตั้งค่า →
                </button>
                <button v-else class="font-bold whitespace-nowrap hover:underline" @click="goToSettingsTab('rules')">
                  ไปตั้งอัตรา →
                </button>
              </div>
              <p v-else class="mt-3 text-xs font-bold text-emerald-700">✓ ตั้งค่าครบ พร้อมจ่าย</p>
            </div>
          </div>

          <div class="mt-6">
            <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2 px-1">ตั้งค่าระดับบริษัท</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
              <button
                v-for="t in tabDefs.filter((x) => x.key !== 'rules')"
                :key="t.key"
                class="p-3 rounded-xl border border-slate-200 bg-white/95 text-left hover:bg-slate-50"
                @click="goToSettingsTab(t.key)"
              >
                <div class="flex items-center gap-1.5 text-slate-700">
                  <Icon :name="t.icon" :size="16" />
                  <span class="text-sm font-bold">{{ t.label }}</span>
                </div>
                <p class="text-xs text-slate-400 mt-1">ใช้กับ {{ (tabToPlanType[t.key] && productPlanTypeCounts[tabToPlanType[t.key]!]) || 0 }} สินค้า</p>
              </button>
            </div>
          </div>
        </section>

        <!-- ═══════════ Commission Rules ═══════════ -->
        <section v-if="viewMode === 'settings' && activeTab === 'rules'" class="mt-4">
          <!-- TASK-213 Phase 2 — one list, filtered by WHO GETS PAID.
               An admin thinks "ตัวแทนได้เท่าไหร่ / หัวหน้าได้เท่าไหร่",
               not "commission_rules vs commission_override_rules" — and
               the leader half used to live in a different route entirely
               (/product-catalog), which is why nobody could find it. -->
          <div class="flex flex-wrap items-center gap-2 mb-3">
            <button
              v-for="f in ([{ k: 'all', l: 'ทั้งหมด' }, { k: 'agent', l: 'ตัวแทนผู้ขาย' }, { k: 'leader', l: 'หัวหน้าทีม' }] as const)"
              :key="f.k"
              class="px-3 py-1.5 rounded-full border text-xs font-bold"
              :class="rateRecipientFilter === f.k ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'"
              @click="rateRecipientFilter = f.k"
            >
              {{ f.l }}
            </button>
            <div class="ml-auto flex flex-wrap gap-2">
              <button v-if="rateRecipientFilter !== 'leader'" class="btn-primary" @click="openCreateRuleForm">
                + เพิ่มอัตราตัวแทนผู้ขาย
              </button>
              <button v-if="rateRecipientFilter !== 'agent'" class="px-3 py-1.5 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 text-xs font-bold hover:bg-amber-100" @click="openCreateOverrideForm">
                + เพิ่มอัตราหัวหน้าทีม
              </button>
            </div>
          </div>

          <!-- TASK-213 r2 — name the collisions where they can be deleted.
               A count on the ภาพรวม tab tells an admin something is wrong;
               only this list can tell them WHICH ROW to remove. -->
          <div v-if="totalConflicts" class="mb-3 p-4 rounded-xl bg-rose-50 border border-rose-200">
            <p class="text-sm font-bold text-rose-800">พบอัตราซ้อนทับกัน {{ totalConflicts }} รายการ</p>
            <p class="mt-1 text-xs text-rose-700 leading-relaxed">
              แถวที่ติดป้าย <b>ซ้อนทับ</b> ด้านล่างมีผลพร้อมกันในขอบเขตเดียวกัน — ระบบเรียงตามวันที่เริ่มมีผลแล้วหยิบอันแรก
              <b>เมื่อวันเริ่มเท่ากันจึงหยิบอันไหนก็ได้ ทำนายไม่ได้</b> · ค่าคอมที่ลงบัญชีไปแล้วแก้ย้อนหลังไม่ได้ (BR-4)
              จึงควรลบให้เหลือรายการเดียวก่อนจะมีดีลปิดเพิ่ม
            </p>
            <p class="mt-1 text-xs text-rose-600">
              ระบบไม่ยอมให้สร้างแบบนี้แล้วตั้งแต่ต้น — รายการเหล่านี้มักเป็นข้อมูลเก่าที่เคยแยกด้วย cert tier ก่อน ADR-035 (18 ส.ค. 2569)
            </p>
          </div>

          <!-- Leader-rate form. Kept separate from the agent-rate form
               above for an honest reason, not a lazy one: the two ask
               different questions today — an agent rate is scoped by
               product/หมวดหมู่/บริษัท and can carry a renewal rate, while
               a leader rate is keyed by the MANAGER'S cert tier and has no
               product dimension at all. Merging them into one form before
               commission_override_rules gains product scope (Phase 4)
               would mean a form whose fields lie about what the row can
               express. -->
          <!-- TASK-216 r2 — a real modal (human, 2026-08-20: "ทำไมไม่เป็น
               modal backgroud สีดำ"). Inline forms opened at the TOP of
               the page while the row being edited sat further down and
               often off-screen; the overlay removes the question by
               removing everything else. -->
          <div v-if="showOverrideForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="resetOverrideForm">
            <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitOverrideRule">
            <div class="shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
              <div class="min-w-0">
                <p class="text-xs font-bold tracking-wide text-amber-700">{{ editingOverrideId ? 'แก้ไข' : 'เพิ่ม' }}อัตราค่าคอมหัวหน้าทีม</p>
                <h1 class="mt-0.5 text-xl font-bold text-slate-900 break-words leading-snug">{{ overrideFormTargetLabel }}</h1>
              </div>
              <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600" @click="resetOverrideForm">
                <Icon name="x" :size="20" />
              </button>
            </div>
              <div class="flex-1 min-h-0 overflow-y-auto py-3 -mx-1 px-1 space-y-3">
              <p class="text-xs text-amber-800">
                จ่ายให้ "หัวหน้าทีม" ตาม cert tier ของหัวหน้าเอง ทุกครั้งที่ลูกทีมปิดการขาย ·
                แผน <b>มาตรฐาน (Unilevel)</b> จ่ายขึ้นไปทั้งสาย · แผน <b>พันธมิตร (Affiliate)</b> จ่ายชั้นเดียว
              </p>
              <p class="text-xs text-amber-800">
                <b>อัตราแยกรายสินค้าได้แล้ว</b> · ลำดับการใช้ค่าเหมือนอัตราตัวแทนเป๊ะ ๆ — สินค้าเฉพาะ > หมวดหมู่ > ค่าเริ่มต้นทั้งบริษัท
              </p>
              <div v-if="overrideFormError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ overrideFormError }}</div>
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <!-- TASK-214 — the cert-tier picker that used to be here is
                     gone: the rate no longer depends on the manager's tier
                     (human ruling 2026-08-19). This is the scope selector
                     that replaced it, deliberately identical to the agent
                     rate's above so both read the same way. -->
                <div class="col-span-2">
                  <label class="text-sm font-bold text-slate-500">ขอบเขต</label>
                  <select v-model="overrideForm.scope" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                    <option value="company">ค่าเริ่มต้นทั้งบริษัท</option>
                    <option value="category">ตามหมวดหมู่สินค้า</option>
                    <option value="product">ตามสินค้า</option>
                  </select>
                </div>
                <div v-if="overrideForm.scope === 'product'" class="col-span-2">
                  <label class="text-sm font-bold text-slate-500">สินค้า</label>
                  <select v-model="overrideForm.product_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                    <option value="" disabled>เลือกสินค้า</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                  </select>
                </div>
                <div v-if="overrideForm.scope === 'category'" class="col-span-2">
                  <label class="text-sm font-bold text-slate-500">หมวดหมู่</label>
                  <select v-model="overrideForm.product_category_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                    <option value="" disabled>เลือกหมวดหมู่</option>
                    <option v-for="c in productCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                </div>
                <!-- TASK-213 — the field that did not exist. The old form
                     sent rate_type: 'percentage' unconditionally. -->
                <div>
                  <label class="text-sm font-bold text-slate-500">รูปแบบอัตรา</label>
                  <select v-model="overrideForm.rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                    <option value="percentage">% ของยอดขาย</option>
                    <option value="fixed_satang">จำนวนคงที่ (บาท)</option>
                  </select>
                </div>
                <div>
                  <label class="text-sm font-bold text-slate-500">{{ overrideForm.rate_type === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
                  <input v-model="overrideForm.rate_value_input" type="number" min="0" step="0.01" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
                </div>
                <div>
                  <label class="text-sm font-bold text-slate-500">มีผลตั้งแต่</label>
                  <div class="mt-1 flex flex-wrap items-start gap-2">
                    <BuddhistDateInput v-model="overrideForm.effective_from" required />
                    <CalendarDatePicker v-model="overrideForm.effective_from" />
                  </div>
                </div>
                <div>
                  <label class="text-sm font-bold text-slate-500">มีผลถึง (ไม่บังคับ)</label>
                  <div class="mt-1 flex flex-wrap items-start gap-2">
                    <BuddhistDateInput v-model="overrideForm.effective_to" />
                    <CalendarDatePicker v-model="overrideForm.effective_to" />
                  </div>
                </div>
              </div>
              </div>
            <div class="shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
              <button type="button" class="btn-secondary" @click="resetOverrideForm">ยกเลิก</button>
              <button type="submit" :disabled="savingOverride" class="btn-primary">{{ savingOverride ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
            </div>
            </form>
          </div>
          <!-- TASK-216 r2 — a real modal (human, 2026-08-20: "ทำไมไม่เป็น
               modal backgroud สีดำ"). Inline forms opened at the TOP of
               the page while the row being edited sat further down and
               often off-screen; the overlay removes the question by
               removing everything else. -->
          <div v-if="showRuleForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="resetRuleForm">
            <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitRule">
            <div class="shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
              <div class="min-w-0">
                <p class="text-xs font-bold tracking-wide text-brand-700">{{ editingRuleId ? 'แก้ไข' : 'เพิ่ม' }}อัตราค่าคอมตัวแทนผู้ขาย</p>
                <h1 class="mt-0.5 text-xl font-bold text-slate-900 break-words leading-snug">{{ ruleFormTargetLabel }}</h1>
              </div>
              <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600" @click="resetRuleForm">
                <Icon name="x" :size="20" />
              </button>
            </div>
              <div class="flex-1 min-h-0 overflow-y-auto py-3 -mx-1 px-1 space-y-3">
              <div v-if="ruleFormError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ ruleFormError }}</div>
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="col-span-2">
                  <label class="text-sm font-bold text-slate-500">ขอบเขต</label>
                  <select v-model="ruleForm.scope" :disabled="!!editingRuleId" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                    <option value="company">ค่าเริ่มต้นทั้งบริษัท</option>
                    <option value="category">ตามหมวดหมู่สินค้า</option>
                    <option value="product">ตามสินค้า</option>
                  </select>
                </div>
                <div v-if="ruleForm.scope === 'product'" class="col-span-2">
                  <label class="text-sm font-bold text-slate-500">สินค้า</label>
                  <select v-model="ruleForm.product_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white" @change="recheckRuleCap">
                    <option value="" disabled>เลือกสินค้า</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                  </select>
                </div>
                <div v-if="ruleForm.scope === 'category'" class="col-span-2">
                  <label class="text-sm font-bold text-slate-500">หมวดหมู่</label>
                  <select v-model="ruleForm.product_category_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                    <option value="" disabled>เลือกหมวดหมู่</option>
                    <option v-for="c in productCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                </div>
                <!-- TASK-197 §3.4 — product-scope rules use the PRODUCT's
                     locked-in commission_rate_type once it has one
                     (server-enforced, §2.2): the selector only shows for
                     company-wide/category rules (which keep their own free
                     choice, §1 unchanged) OR the very first rule a product
                     ever gets (nothing to inherit from yet). -->
                <div v-if="showRuleFormRateTypeSelector">
                  <label class="text-sm font-bold text-slate-500">รูปแบบอัตรา</label>
                  <select v-model="ruleForm.rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white" @change="recheckRuleCap">
                    <option value="percentage">% ของยอดขาย</option>
                    <option value="fixed_satang">จำนวนคงที่ (บาท)</option>
                  </select>
                </div>
                <div>
                  <label class="text-sm font-bold text-slate-500">{{ effectiveRuleFormRateType === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
                  <input
                    v-model="ruleForm.rate_value_input"
                    type="number"
                    min="0"
                    step="0.01"
                    required
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
                    @input="recheckRuleCapDebounced"
                    @blur="recheckRuleCap"
                  />
                  <!-- TASK-197 §3.4 — when the selector above is hidden
                       (locked-in product format), tell the admin which
                       unit their number means instead of leaving them to
                       guess. -->
                  <p v-if="!showRuleFormRateTypeSelector" class="mt-1 text-xs text-slate-400">จะบันทึกเป็น: {{ rateTypeLabels[effectiveRuleFormRateType] }}</p>
                  <p v-if="ruleCapGuard.isOverCap.value" class="mt-1 text-xs font-bold text-rose-600">เกินเพดานคอมมิชชั่นที่กำหนด</p>
                </div>
                <div>
                  <label class="text-sm font-bold text-slate-500">มีผลตั้งแต่</label>
                  <div class="mt-1 flex flex-wrap items-start gap-2">
                    <BuddhistDateInput v-model="ruleForm.effective_from" required />
                    <CalendarDatePicker v-model="ruleForm.effective_from" />
                  </div>
                </div>
                <div>
                  <label class="text-sm font-bold text-slate-500">มีผลถึง (ไม่บังคับ)</label>
                  <div class="mt-1 flex flex-wrap items-start gap-2">
                    <BuddhistDateInput v-model="ruleForm.effective_to" />
                    <CalendarDatePicker v-model="ruleForm.effective_to" />
                  </div>
                </div>
              </div>
              </div>
            <div class="shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
              <button type="button" class="btn-secondary" @click="resetRuleForm">ยกเลิก</button>
              <button type="submit" :disabled="savingRule || ruleCapGuard.isOverCap.value" class="btn-primary">
                {{ savingRule ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
            </form>
          </div>

          <!-- TASK-196 §3.3 — same blocking-alert shape as the resolution-order
               info modal below (this file's own closest existing pattern for a
               single-button informational modal). -->
          <div v-if="ruleCapGuard.modalOpen.value" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="ruleCapGuard.closeModal">
            <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-5">
              <div class="flex items-center gap-2 mb-2">
                <Icon name="alert" :size="18" class="text-rose-600 shrink-0" />
                <p class="text-sm font-bold text-slate-900">เกินเพดานคอมมิชชั่นที่กำหนด</p>
              </div>
              <p class="text-xs text-slate-500 mb-4">{{ ruleCapGuard.violationMessage.value }}</p>
              <div class="flex justify-end">
                <button class="btn-primary" @click="ruleCapGuard.closeModal">เข้าใจแล้ว</button>
              </div>
            </div>
          </div>

          <EmptyState
            v-if="!byCompany(commissionRules).length && !activeOverrideRules.length"
            icon="money"
            title="ยังไม่มีอัตราค่าคอม"
            message="เพิ่มอัตราของตัวแทนผู้ขายก่อน — ถ้าไม่มี ดีลที่ปิดได้จะไม่มีใครได้เงินเลย"
            class="mt-2"
          />
          <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
            <!-- ตัวแทนผู้ขาย -->
            <div
              v-for="r in (rateRecipientFilter === 'leader' ? [] : byCompany(commissionRules))"
              :key="`agent-${r.id}`"
              class="bg-white/95 rounded-xl p-4 flex items-center justify-between gap-3 border"
              :class="conflictingRuleIds.has(r.id) ? 'border-rose-300 bg-rose-50/40' : 'border-slate-200'"
            >
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900">
                  <span class="mr-2 px-2 py-0.5 rounded-md bg-brand-50 text-brand-700 text-[11px] align-middle">ตัวแทนผู้ขาย</span>
                  <span v-if="conflictingRuleIds.has(r.id)" class="mr-2 px-2 py-0.5 rounded-md bg-rose-100 text-rose-700 text-[11px] align-middle">ซ้อนทับ</span>
                  {{ ruleScopeLabel(r) }}
                </p>
                <p class="text-xs text-slate-400">
                  อัตรา {{ formatRate(r.rate_type, r.rate_value) }} · มีผล {{ formatDate(r.effective_from) }}{{ r.effective_to ? ` ถึง ${formatDate(r.effective_to)}` : '' }}
                  <span v-if="r.renewal_rate_type"> · ต่ออายุ {{ formatRate(r.renewal_rate_type, r.renewal_rate_value!) }}</span>
                </p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <button class="text-sm font-bold text-slate-500 hover:text-slate-700" @click="openEditRuleForm(r)">แก้ไข</button>
                <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteRule(r)">ลบ</button>
              </div>
            </div>

            <!-- หัวหน้าทีม (TASK-213 — moved here from /product-catalog) -->
            <div
              v-for="r in (rateRecipientFilter === 'agent' ? [] : byCompany(commissionOverrideRules))"
              :key="`leader-${r.id}`"
              class="bg-white/95 rounded-xl p-4 flex items-center justify-between gap-3 border"
              :class="conflictingOverrideIds.has(r.id) ? 'border-rose-300 bg-rose-50/40' : 'border-amber-200'"
            >
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900">
                  <span class="mr-2 px-2 py-0.5 rounded-md bg-amber-100 text-amber-800 text-[11px] align-middle">หัวหน้าทีม</span>
                  <span v-if="conflictingOverrideIds.has(r.id)" class="mr-2 px-2 py-0.5 rounded-md bg-rose-100 text-rose-700 text-[11px] align-middle">ซ้อนทับ</span>
                  {{ overrideScopeLabel(r) }}
                  <!-- Shown only on legacy rows. A row created after
                       TASK-214 has no tier, and saying "ทุก tier" on it
                       would imply a dimension that no longer exists. -->
                  <span v-if="r.manager_cert_tier" class="ml-1 text-[11px] font-normal text-slate-400">
                    (เดิมตั้งไว้ที่ tier {{ r.manager_cert_tier.name }} — ไม่ถูกใช้แล้ว)
                  </span>
                </p>
                <p class="text-xs text-slate-400">
                  อัตรา {{ formatRate(r.rate_type, r.rate_value) }} · มีผล {{ formatDate(r.effective_from) }}{{ r.effective_to ? ` ถึง ${formatDate(r.effective_to)}` : '' }}
                </p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <button class="text-sm font-bold text-slate-500 hover:text-slate-700" @click="openEditOverrideForm(r)">แก้ไข</button>
                <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteOverrideRule(r)">ลบ</button>
              </div>
            </div>
          </TransitionGroup>
        </section>

        <!-- ═══════════ Binary ═══════════ -->
        <section v-if="viewMode === 'settings' && activeTab === 'binary'" class="mt-4">
          <div v-if="binaryError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ binaryError }}</div>
          <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 sm:grid-cols-4 gap-3" @submit.prevent="submitBinarySettings">
            <div>
              <label class="text-sm font-bold text-slate-500">รูปแบบอัตรา Matched</label>
              <select v-model="binaryForm.matched_rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="percentage">% ของยอด Matched</option>
                <option value="fixed_satang">จำนวนคงที่ (บาท)</option>
              </select>
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">{{ binaryForm.matched_rate_type === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
              <input v-model="binaryForm.matched_rate_value_input" type="number" min="0" step="0.01" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">รอบคำนวณ</label>
              <select v-model="binaryForm.cycle_frequency" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="weekly">รายสัปดาห์</option>
                <option value="biweekly">ทุก 2 สัปดาห์</option>
                <option value="monthly">รายเดือน</option>
              </select>
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">เพดานจ่าย/รอบ (บาท, ว่าง = ไม่จำกัด)</label>
              <input v-model="binaryForm.payout_cap_thb" type="number" min="0" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div class="col-span-2 sm:col-span-4 flex items-center gap-2">
              <input id="carry_over" v-model="binaryForm.carry_over_unmatched" type="checkbox" />
              <label for="carry_over" class="text-sm font-bold text-slate-500">ยกยอดที่ไม่ Matched ไปรอบถัดไป</label>
            </div>
            <div class="col-span-2 sm:col-span-4 flex justify-end">
              <button type="submit" :disabled="savingBinary" class="btn-primary">
                {{ savingBinary ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
          </form>

          <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2 px-1 mt-4">ประวัติรอบ Matching (อ่านอย่างเดียว)</h3>
          <EmptyState v-if="!binaryCycles.length" icon="branch" title="ยังไม่มีรอบ Matching" />
          <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
            <div v-for="c in binaryCycles" :key="c.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
              <div>
                <p class="text-sm font-bold text-slate-900">Agent #{{ c.agent_id }} · {{ c.period_start }} – {{ c.period_end }}</p>
                <p class="text-xs text-slate-400">
                  ซ้าย {{ formatSatang(c.left_volume_satang) }} · ขวา {{ formatSatang(c.right_volume_satang) }} ·
                  Matched {{ formatSatang(c.matched_volume_satang) }} · ยกยอด {{ formatSatang(c.unmatched_carried_satang) }}
                </p>
              </div>
              <span class="text-xs font-bold px-2 py-0.5 rounded-lg whitespace-nowrap" :class="c.commission_ledger_id ? 'text-emerald-600 bg-emerald-50' : 'text-slate-400 bg-slate-100'">
                {{ c.commission_ledger_id ? 'จ่ายแล้ว' : 'ไม่มีคอมมิชชั่น' }}
              </span>
            </div>
          </TransitionGroup>
        </section>

        <!-- ═══════════ Matrix ═══════════ -->
        <section v-if="viewMode === 'settings' && activeTab === 'matrix'" class="mt-4">
          <div v-if="matrixError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ matrixError }}</div>
          <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 sm:grid-cols-3 gap-3" @submit.prevent="submitMatrixSettings">
            <div>
              <label class="text-sm font-bold text-slate-500">ความกว้าง (Width)</label>
              <input v-model="matrixForm.width" type="number" min="1" max="100" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">ความลึก (Depth)</label>
              <input v-model="matrixForm.depth" type="number" min="1" max="100" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">กฎ Spillover</label>
              <select v-model="matrixForm.spillover_rule" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="breadth">Breadth-first (กว้างก่อน)</option>
              </select>
            </div>
            <div class="col-span-2 sm:col-span-3 flex justify-end">
              <button type="submit" :disabled="savingMatrix" class="btn-primary">
                {{ savingMatrix ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
          </form>

          <!-- Visual preview grid -->
          <div v-if="matrixSettings" class="mt-3 p-4 rounded-xl bg-white/95 border border-slate-200">
            <p class="text-sm font-bold text-slate-500 mb-2">
              ตัวอย่างโครงสร้าง {{ matrixSettings.width }} x {{ matrixSettings.depth }}
              <span v-if="matrixPreviewGrid.truncatedWidth || matrixPreviewGrid.truncatedDepth" class="font-normal text-slate-400">(ย่อแสดงบางส่วน)</span>
            </p>
            <div class="space-y-2">
              <div v-for="d in matrixPreviewGrid.d" :key="'row-' + d" class="flex gap-1.5 items-center" :style="{ paddingLeft: (d - 1) * 12 + 'px' }">
                <div v-for="w in matrixPreviewGrid.w" :key="'cell-' + d + '-' + w" class="w-6 h-6 rounded bg-brand-50 border border-brand-100 flex items-center justify-center text-[9px] text-brand-600 font-bold">
                  {{ d }}.{{ w }}
                </div>
              </div>
            </div>
          </div>

          <div class="flex justify-between items-center mt-4 mb-2 px-1">
            <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider">อัตราคอมมิชชั่นตาม Level</h3>
            <button class="btn-primary" @click="showLevelRateForm = !showLevelRateForm">
              + เพิ่ม Level
            </button>
          </div>
          <!-- TASK-216 r2 — a real modal (human, 2026-08-20: "ทำไมไม่เป็น
               modal backgroud สีดำ"). Inline forms opened at the TOP of
               the page while the row being edited sat further down and
               often off-screen; the overlay removes the question by
               removing everything else. -->
          <div v-if="showLevelRateForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="showLevelRateForm = false">
            <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitLevelRate">
            <div class="col-span-2 sm:col-span-4 shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
              <div class="min-w-0">
                <p class="text-xs font-bold tracking-wide text-slate-400">อัตราคอมมิชชั่นรายชั้น (Matrix)</p>
                <h1 class="mt-0.5 text-xl font-bold text-slate-900 break-words leading-snug">{{ levelRateForm.level === '' ? 'ยังไม่ได้ระบุชั้น' : `ชั้นที่ ${levelRateForm.level}` }}</h1>
              </div>
              <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600" @click="showLevelRateForm = false">
                <Icon name="x" :size="20" />
              </button>
            </div>
              <div class="flex-1 min-h-0 overflow-y-auto py-3 -mx-1 px-1 content-start grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">Level</label>
                <input v-model="levelRateForm.level" type="number" min="1" max="50" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">รูปแบบอัตรา</label>
                <select v-model="levelRateForm.rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option value="percentage">%</option>
                  <option value="fixed_satang">จำนวนคงที่ (บาท)</option>
                </select>
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">{{ levelRateForm.rate_type === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
                <input v-model="levelRateForm.rate_value_input" type="number" min="0" step="0.01" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">มีผลตั้งแต่</label>
                <div class="mt-1 flex flex-wrap items-start gap-2">
                  <BuddhistDateInput v-model="levelRateForm.effective_from" required />
                  <CalendarDatePicker v-model="levelRateForm.effective_from" />
                </div>
              </div>
              </div>
            <div class="col-span-2 sm:col-span-4 shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
              <!-- TASK-216 r2 — added with the modal conversion: an inline
                   panel could be abandoned by scrolling past it, a modal
                   cannot. -->
              <button type="button" class="btn-secondary" @click="showLevelRateForm = false">ยกเลิก</button>
              <button type="submit" :disabled="savingLevelRate" class="btn-primary">
                {{ savingLevelRate ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
            </form>
          </div>
          <EmptyState v-if="!matrixLevelRates.length" icon="layers" title="ยังไม่มีอัตราตาม Level" />
          <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
            <div v-for="lr in matrixLevelRates" :key="lr.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
              <p class="text-sm font-bold text-slate-900">Level {{ lr.level }} — {{ formatRate(lr.rate_type, lr.rate_value) }}</p>
              <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteLevelRate(lr)">ลบ</button>
            </div>
          </TransitionGroup>
        </section>

        <!-- ═══════════ Agent Ranks / Stairstep-Breakaway ═══════════ -->
        <section v-if="viewMode === 'settings' && activeTab === 'ranks'" class="mt-4">
          <div v-if="rankError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ rankError }}</div>
          <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitRankSettings">
            <div>
              <label class="text-sm font-bold text-slate-500">หน้าต่างคำนวณยอดย้อนหลัง (วัน)</label>
              <input v-model="rankSettingsForm.trailing_window_days" type="number" min="1" max="3650" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">ความถี่คำนวณอันดับใหม่</label>
              <select v-model="rankSettingsForm.recalculation_frequency" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="daily">รายวัน</option>
                <option value="weekly">รายสัปดาห์</option>
                <option value="monthly">รายเดือน</option>
              </select>
            </div>
            <div class="col-span-2 flex justify-end">
              <button type="submit" :disabled="savingRankSettings" class="btn-primary">
                {{ savingRankSettings ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
          </form>

          <div class="flex justify-between items-center mt-4 mb-2 px-1">
            <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider">บันไดอันดับ (เรียงจาก sort order น้อยไปมาก)</h3>
            <button class="btn-primary" @click="showRankForm = !showRankForm">
              + เพิ่มอันดับ
            </button>
          </div>
          <!-- TASK-216 r2 — a real modal (human, 2026-08-20: "ทำไมไม่เป็น
               modal backgroud สีดำ"). Inline forms opened at the TOP of
               the page while the row being edited sat further down and
               often off-screen; the overlay removes the question by
               removing everything else. -->
          <div v-if="showRankForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="resetRankForm">
            <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitRank">
            <div class="col-span-2 sm:col-span-3 shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
              <div class="min-w-0">
                <p class="text-xs font-bold tracking-wide text-slate-400">{{ editingRankId ? 'แก้ไข' : 'เพิ่ม' }}ขั้นอันดับ (Stairstep)</p>
                <h1 class="mt-0.5 text-xl font-bold text-slate-900 break-words leading-snug">{{ rankForm.name ? `อันดับ: ${rankForm.name}` : 'ยังไม่ได้ตั้งชื่ออันดับ' }}</h1>
              </div>
              <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600" @click="resetRankForm">
                <Icon name="x" :size="20" />
              </button>
            </div>
              <div class="flex-1 min-h-0 overflow-y-auto py-3 -mx-1 px-1 content-start grid grid-cols-2 sm:grid-cols-3 gap-3">
              <div class="col-span-2 sm:col-span-1">
                <label class="text-sm font-bold text-slate-500">ชื่ออันดับ</label>
                <input v-model="rankForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">ยอดขั้นต่ำ (บาท)</label>
                <input v-model="rankForm.volume_threshold_thb" type="number" min="0" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">ลำดับ (sort order)</label>
                <input v-model="rankForm.sort_order" type="number" min="0" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">รูปแบบอัตรา</label>
                <select v-model="rankForm.rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option value="percentage">%</option>
                  <option value="fixed_satang">จำนวนคงที่ (บาท)</option>
                </select>
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">{{ rankForm.rate_type === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
                <input v-model="rankForm.rate_value_input" type="number" min="0" step="0.01" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div class="flex items-center gap-2 self-end pb-2">
                <input id="is_breakaway" v-model="rankForm.is_breakaway_rank" type="checkbox" />
                <label for="is_breakaway" class="text-sm font-bold text-slate-500">เป็นอันดับ Breakaway (ตัดสายบน)</label>
              </div>
              </div>
            <div class="col-span-2 sm:col-span-3 shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
              <button type="button" class="btn-secondary" @click="resetRankForm">ยกเลิก</button>
              <button type="submit" :disabled="savingRank" class="btn-primary">
                {{ savingRank ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
            </form>
          </div>
          <EmptyState v-if="!agentRanks.length" icon="trophy" title="ยังไม่มีอันดับ" />
          <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
            <div v-for="r in agentRanks" :key="r.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
              <div>
                <p class="text-sm font-bold text-slate-900">
                  {{ r.sort_order }}. {{ r.name }}
                  <span v-if="r.is_breakaway_rank" class="text-xs font-bold text-amber-600">(Breakaway)</span>
                </p>
                <p class="text-xs text-slate-400">ยอดขั้นต่ำ {{ formatSatang(r.volume_threshold) }} · อัตรา {{ formatRate(r.rate_type, r.rate_value) }}</p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <button class="text-sm font-bold text-slate-500 hover:text-slate-700" @click="openEditRank(r)">แก้ไข</button>
                <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteRank(r)">ลบ</button>
              </div>
            </div>
          </TransitionGroup>
        </section>

        <!-- ═══════════ Generation ═══════════ -->
        <section v-if="viewMode === 'settings' && activeTab === 'generation'" class="mt-4">
          <div v-if="generationError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ generationError }}</div>
          <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitGenerationSettings">
            <div>
              <label class="text-sm font-bold text-slate-500">ความลึกสูงสุด (จำนวน Generation)</label>
              <input v-model="generationSettingsForm.max_generation_depth" type="number" min="1" max="50" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div class="flex justify-end items-end">
              <button type="submit" :disabled="savingGenerationSettings" class="btn-primary">
                {{ savingGenerationSettings ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
          </form>

          <div class="flex justify-between items-center mt-4 mb-2 px-1">
            <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider">อัตราคอมมิชชั่นตาม Generation</h3>
            <button class="btn-primary" @click="showGenerationRuleForm = !showGenerationRuleForm">
              + เพิ่ม Generation
            </button>
          </div>
          <!-- TASK-216 r2 — a real modal (human, 2026-08-20: "ทำไมไม่เป็น
               modal backgroud สีดำ"). Inline forms opened at the TOP of
               the page while the row being edited sat further down and
               often off-screen; the overlay removes the question by
               removing everything else. -->
          <div v-if="showGenerationRuleForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="showGenerationRuleForm = false">
            <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitGenerationRule">
            <div class="col-span-2 sm:col-span-4 shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
              <div class="min-w-0">
                <p class="text-xs font-bold tracking-wide text-slate-400">อัตราคอมมิชชั่นราย Generation</p>
                <h1 class="mt-0.5 text-xl font-bold text-slate-900 break-words leading-snug">{{ generationRuleForm.generation_number === '' ? 'ยังไม่ได้ระบุ Generation' : `Generation ที่ ${generationRuleForm.generation_number}` }}</h1>
              </div>
              <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600" @click="showGenerationRuleForm = false">
                <Icon name="x" :size="20" />
              </button>
            </div>
              <div class="flex-1 min-h-0 overflow-y-auto py-3 -mx-1 px-1 content-start grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">Generation ที่</label>
                <input v-model="generationRuleForm.generation_number" type="number" min="1" max="50" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">รูปแบบอัตรา</label>
                <select v-model="generationRuleForm.rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option value="percentage">%</option>
                  <option value="fixed_satang">จำนวนคงที่ (บาท)</option>
                </select>
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">{{ generationRuleForm.rate_type === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
                <input v-model="generationRuleForm.rate_value_input" type="number" min="0" step="0.01" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">มีผลตั้งแต่</label>
                <div class="mt-1 flex flex-wrap items-start gap-2">
                  <BuddhistDateInput v-model="generationRuleForm.effective_from" required />
                  <CalendarDatePicker v-model="generationRuleForm.effective_from" />
                </div>
              </div>
              </div>
            <div class="col-span-2 sm:col-span-4 shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
              <button type="button" class="btn-secondary" @click="showGenerationRuleForm = false">ยกเลิก</button>
              <button type="submit" :disabled="savingGenerationRule" class="btn-primary">
                {{ savingGenerationRule ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
            </form>
          </div>
          <EmptyState v-if="!generationRules.length" icon="users" title="ยังไม่มีอัตราตาม Generation" />
          <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
            <div v-for="gr in generationRules" :key="gr.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
              <p class="text-sm font-bold text-slate-900">Generation {{ gr.generation_number }} — {{ formatRate(gr.rate_type, gr.rate_value) }}</p>
              <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteGenerationRule(gr)">ลบ</button>
            </div>
          </TransitionGroup>
        </section>

        <!-- ═══════════ Affiliate ═══════════ -->
        <section v-if="viewMode === 'settings' && activeTab === 'affiliate'" class="mt-4">
          <div v-if="affiliateError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ affiliateError }}</div>
          <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitAffiliateSettings">
            <div>
              <label class="text-sm font-bold text-slate-500">หน้าต่างนับเครดิต (วัน)</label>
              <input v-model="affiliateForm.attribution_window_days" type="number" min="1" max="3650" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              <p class="mt-1 text-xs text-slate-400">คลิกล่าสุดต้องอยู่ในช่วงเวลานี้จึงจะนับเป็นการแปลงที่มาจากลิงก์พันธมิตร (last-click)</p>
            </div>
            <div class="flex items-start gap-2 pt-6">
              <input id="differential" v-model="affiliateForm.new_vs_returning_rate_differential_enabled" type="checkbox" />
              <label for="differential" class="text-sm font-bold text-slate-500">แยกอัตราลูกค้าใหม่/ลูกค้าเก่า (ยังไม่รองรับการคำนวณจริง)</label>
            </div>
            <div class="col-span-2 flex justify-end">
              <button type="submit" :disabled="savingAffiliate" class="btn-primary">
                {{ savingAffiliate ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
          </form>
        </section>
      </template>
    </template>

    <div v-if="showResolutionOrderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeResolutionOrderModal">
      <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-5">
        <div class="flex items-center gap-2 mb-2">
          <Icon name="info" :size="18" class="text-brand-600 shrink-0" />
          <p class="text-sm font-bold text-slate-900">ลำดับการใช้ค่าคอมมิชชั่น</p>
        </div>
        <p class="text-xs text-slate-500 mb-4">{{ RESOLUTION_ORDER_NOTE }}</p>
        <label class="flex items-center gap-1.5 text-xs text-slate-500 mb-4">
          <input v-model="dontShowResolutionOrderAgain" type="checkbox" />
          ไม่ต้องแสดงข้อความนี้อีก
        </label>
        <div class="flex justify-end">
          <button class="btn-primary" @click="closeResolutionOrderModal">
            เข้าใจแล้ว
          </button>
        </div>
      </div>
    </div>

    <!-- ทดสอบคำนวณ — direct-commission preview only, see caveat text inside -->
    <div v-if="simulateProduct" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeSimulate">
      <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-5">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-bold text-slate-900">ทดสอบคำนวณ — {{ simulateProduct.name }}</p>
          <button class="text-slate-400 hover:text-slate-600" @click="closeSimulate">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <div class="space-y-3">
          <div>
            <label class="text-sm font-bold text-slate-500">ยอดขายสมมติ (บาท)</label>
            <input v-model="simulateAmountThb" type="number" min="0" step="0.01" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
            <template v-if="simulateResult?.rule">
              <p class="text-sm font-bold text-slate-900">คอมมิชชั่นทางตรง: {{ formatSatang(simulateResult.amountSatang) }}</p>
              <p class="text-xs text-slate-400 mt-1">
                อิงตามกฎ: {{ ruleScopeLabel(simulateResult.rule) }} · {{ formatRate(simulateResult.rule.rate_type, simulateResult.rule.rate_value) }}
              </p>
            </template>
            <p v-else class="text-xs text-rose-600">ยังไม่มีกฎคอมมิชชั่นที่ใช้ได้กับสินค้านี้</p>
            <p class="text-xs text-slate-400 mt-2">
              * ตัวอย่างนี้แสดงเฉพาะคอมมิชชั่นทางตรงจากยอดขาย ไม่รวมโครงสร้าง Override/Matrix/Generation/อันดับ ซึ่งคำนวณจริงที่ฝั่งเซิร์ฟเวอร์เมื่อมีการขายจริงเท่านั้น
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- ตัวช่วยตั้งค่าคอมมิชชั่นสินค้า (Setup Wizard, Task-037) -->
    <div v-if="wizardOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeWizard">
      <div class="w-full max-w-lg bg-white rounded-2xl shadow-lg p-5 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-bold text-slate-900">ตัวช่วยตั้งค่าคอมมิชชั่นสินค้า</p>
          <button class="text-slate-400 hover:text-slate-600" @click="closeWizard">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <div class="flex items-center gap-1.5 mb-4">
          <div v-for="s in [1, 2, 3, 4]" :key="s" class="h-1.5 flex-1 rounded-full" :class="s <= wizardStep ? 'bg-brand-600' : 'bg-slate-200'"></div>
        </div>

        <!-- Step 1: เลือกสินค้า + ยืนยันรูปแบบแผน -->
        <div v-if="wizardStep === 1" class="space-y-3">
          <div>
            <label class="text-sm font-bold text-slate-500">สินค้า</label>
            <select v-model="wizardProductId" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="" disabled>เลือกสินค้า</option>
              <option v-for="p in byCompany(products)" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <!-- TASK-198 — this product's commission_rate_type is already
               locked to fixed_satang by another form (TASK-197 §2.2); the
               wizard's rate-entry step only ever submits 'percentage', so
               letting the admin continue would just 422 at the end. Block
               here instead of at submit time. -->
          <div v-if="wizardProduct && wizardProductBlockedFixedSatang" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700 space-y-2">
            <p>สินค้านี้ตั้งค่าคอมมิชชั่นเป็นแบบจำนวนเงินคงที่ (บาท) แล้ว ใช้ Wizard นี้ไม่ได้ กรุณาไปที่ "+ เพิ่มอัตราคอมตาม tier" แทน</p>
            <button class="font-bold hover:underline" @click="wizardGoToProductRateForm">ไปที่ + เพิ่มอัตราคอมตาม tier →</button>
          </div>
          <div v-else-if="wizardProduct">
            <label class="text-sm font-bold text-slate-500">รูปแบบแผนคอมมิชชั่น</label>
            <select v-model="wizardPlanChoice" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="inherit">สืบทอดจากบริษัท{{ wizardProduct.effective_plan_type ? ' (' + planTypeLabels[wizardProduct.effective_plan_type] + ')' : '' }}</option>
              <option v-for="(label, pt) in planTypeLabels" :key="pt" :value="pt">{{ label }} (กำหนดเฉพาะสินค้านี้)</option>
            </select>
          </div>
          <div v-if="wizardPlanTypeError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ wizardPlanTypeError }}</div>
          <div v-if="!wizardProductBlockedFixedSatang" class="flex justify-end">
            <button :disabled="!wizardProduct || wizardSavingPlanType" class="btn-primary" @click="wizardConfirmProductAndPlan">
              {{ wizardSavingPlanType ? 'กำลังบันทึก...' : 'ถัดไป' }}
            </button>
          </div>
        </div>

        <!-- Step 2: อัตราคอมมิชชั่น (flat-rate ต่อสินค้า, ADR-035) -->
        <div v-else-if="wizardStep === 2" class="space-y-3">
          <p class="text-xs text-slate-400">
            ใส่อัตรา % สำหรับสินค้านี้ (เว้นว่างได้ถ้ายังไม่ตั้งตอนนี้) — ต้องการอัตราคงที่ (บาท) แทน % ให้ไปตั้งค่าที่ "การตั้งค่าทั้งหมด → กฎคอมมิชชั่น" แทน
          </p>
          <div class="flex items-center gap-3">
            <span class="text-sm font-bold text-slate-700 w-28 shrink-0">อัตราคอมมิชชั่น</span>
            <input v-model="wizardRateInput" type="number" min="0" step="0.01" placeholder="% เช่น 10" class="flex-1 px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div v-if="wizardRateError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ wizardRateError }}</div>
          <div class="flex justify-between">
            <button class="btn-secondary" @click="wizardStep = 1">ย้อนกลับ</button>
            <button :disabled="wizardSavingRates" class="btn-primary" @click="wizardSaveRates">
              {{ wizardSavingRates ? 'กำลังบันทึก...' : 'ถัดไป' }}
            </button>
          </div>
        </div>

        <!-- Step 3: การตั้งค่าระดับบริษัท (เฉพาะแผนที่ต้องการ) -->
        <div v-else-if="wizardStep === 3" class="space-y-3">
          <div class="px-3 py-2 rounded-lg bg-amber-50 text-amber-700 text-xs">
            {{ wizardEffectivePlanType ? planTypeLabels[wizardEffectivePlanType] : '' }} ต้องมีค่าตั้งไว้ระดับบริษัท — การแก้ไขนี้จะมีผลกับสินค้าอื่นที่ใช้แผนเดียวกันด้วย
          </div>

          <template v-if="!wizardStructureEditMode">
            <p class="text-sm text-slate-600">มีค่าตั้งไว้อยู่แล้วในระดับบริษัท ใช้ค่าเดิมต่อได้เลย หรือแก้ไขถ้าต้องการ</p>
            <button class="text-xs font-bold text-brand-600 hover:underline" @click="wizardStructureEditMode = true">แก้ไขค่า</button>
          </template>

          <template v-else>
            <div v-if="wizardEffectivePlanType === 'matrix'" class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">ความกว้าง (Width)</label>
                <input v-model="matrixForm.width" type="number" min="1" max="100" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">ความลึก (Depth)</label>
                <input v-model="matrixForm.depth" type="number" min="1" max="100" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
            </div>
            <div v-else-if="wizardEffectivePlanType === 'binary'" class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">อัตรา Matched (%)</label>
                <input v-model="binaryForm.matched_rate_value_input" type="number" min="0" step="0.01" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">รอบคำนวณ</label>
                <select v-model="binaryForm.cycle_frequency" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option value="weekly">รายสัปดาห์</option>
                  <option value="biweekly">ทุก 2 สัปดาห์</option>
                  <option value="monthly">รายเดือน</option>
                </select>
              </div>
            </div>
            <div v-else-if="wizardEffectivePlanType === 'stairstep_breakaway'" class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">หน้าต่างคำนวณยอดย้อนหลัง (วัน)</label>
                <input v-model="rankSettingsForm.trailing_window_days" type="number" min="1" max="3650" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">ความถี่คำนวณอันดับใหม่</label>
                <select v-model="rankSettingsForm.recalculation_frequency" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option value="daily">รายวัน</option>
                  <option value="weekly">รายสัปดาห์</option>
                  <option value="monthly">รายเดือน</option>
                </select>
              </div>
              <p class="col-span-2 text-xs text-slate-400">อันดับ (rank ladder) แต่ละขั้นตั้งเพิ่มเติมได้ที่ "การตั้งค่าทั้งหมด → อันดับ (Stairstep)"</p>
            </div>
            <div v-else-if="wizardEffectivePlanType === 'generation'" class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">ความลึกสูงสุด (Generation)</label>
                <input v-model="generationSettingsForm.max_generation_depth" type="number" min="1" max="50" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <p class="col-span-2 text-xs text-slate-400">อัตราตาม Generation แต่ละขั้นตั้งเพิ่มเติมได้ที่ "การตั้งค่าทั้งหมด → Generation"</p>
            </div>
            <div v-else-if="wizardEffectivePlanType === 'affiliate'" class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">หน้าต่างนับเครดิต (วัน)</label>
                <input v-model="affiliateForm.attribution_window_days" type="number" min="1" max="3650" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
            </div>
          </template>

          <div v-if="wizardStructureError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ wizardStructureError }}</div>
          <div class="flex justify-between">
            <button class="btn-secondary" @click="wizardStep = 2">ย้อนกลับ</button>
            <div class="flex gap-2">
              <button v-if="!wizardStructureEditMode" class="btn-secondary" @click="wizardSkipStructure">ใช้ค่าเดิม</button>
              <button v-else :disabled="wizardSavingStructure" class="btn-primary" @click="wizardSaveStructure">
                {{ wizardSavingStructure ? 'กำลังบันทึก...' : 'บันทึกและถัดไป' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Step 4: สรุป -->
        <div v-else-if="wizardStep === 4" class="space-y-3">
          <div class="flex items-center gap-2 text-emerald-600">
            <Icon name="check_circle" :size="20" />
            <p class="text-sm font-bold">ตั้งค่าเสร็จเรียบร้อย</p>
          </div>
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm">
            <p class="font-bold text-slate-900">{{ wizardProduct?.name }} — {{ wizardEffectivePlanType ? planTypeLabels[wizardEffectivePlanType] : '' }}</p>
            <p v-if="wizardSummaryRate" class="mt-2 text-xs text-slate-500">อัตราคอมมิชชั่น: {{ formatRate(wizardSummaryRate.rate_type, wizardSummaryRate.rate_value) }}</p>
            <p v-else class="text-xs text-slate-400 mt-2">ยังไม่ได้ตั้งอัตราคอมมิชชั่นสำหรับสินค้านี้</p>
          </div>
          <div class="flex justify-end">
            <button class="btn-primary" @click="closeWizard">เสร็จสิ้น</button>
          </div>
        </div>
      </div>
    </div>
  </main>
</template>
