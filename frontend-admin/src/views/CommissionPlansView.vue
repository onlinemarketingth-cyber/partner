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
 *
 * ── 2026-09-11 — THE 4-STEP FLOW (owner-approved mockups) ──
 *
 * Owner's complaint, verbatim: "ผู้ใช้ไม่รู้ว่าต้องกรอกอะไรหลัง". The screen
 * described above was six peer tabs plus an overview/settings toggle, and
 * every one of those tabs looked equally urgent and equally optional. An
 * admin who finished one had no way to learn what the next one was, or
 * whether they were done — and "done" here means the difference between a
 * closed deal paying somebody and a closed deal paying nobody.
 *
 * So the six tabs became FOUR ORDERED STEPS, named after what the admin
 * came to do rather than after the table behind them:
 *
 *   1 เลือกบริษัท              — which company's money are we setting
 *   2 เลือกแผนคอมมิชชั่น        — Unilevel/Binary/Matrix/Stairstep/Generation/
 *                                Affiliate + that plan's own structural form
 *   3 ตั้งอัตราตัวแทนผู้ขาย      — the company default, then the per-product
 *                                exceptions (the one step that cannot be skipped)
 *   4 ส่วนเพิ่มเติม             — leader rate, co-agent split, withdrawal minimum
 *
 * WHAT WAS KEPT, DELIBERATELY. Every structural section, form, endpoint and
 * modal below is the one that already shipped and passed UAT-012 — only its
 * `v-if` moved. The redesign is a re-addressing of working parts, not a
 * rewrite of them, because the parts were never what the owner complained
 * about.
 *
 * ── 2026-09-12 — THE ORDER IS NOW ENFORCED, NOT SUGGESTED ──
 *
 * The version above shipped with the steps FREELY CLICKABLE and said so in
 * this docblock: "an order the UI shows but does not enforce is a hint, and a
 * hint cannot strand an admin who genuinely needs step 4 first." The owner
 * looked at the deployed page and rejected exactly that: "หน้านี้มี tab แล้ว
 * แต่ยังคลิ๊กเลือกได้ทุก tab เลย ตามที่คุยไว้ต้องทำทีละขั้นตอน".
 *
 * So a step is reachable only when every step BEFORE it is finished — see
 * `stepReachable` for the rule and `goToStep` for the single place that
 * enforces it. The bet the old text made (that the pills would be read) lost
 * against the thing it was protecting: an admin who lands on step 4 and fills
 * in a leader rate while step 3 has products with no agent rate has spent
 * their attention on the upline's share of a commission nobody is being paid.
 *
 * WHAT IT COSTS, honestly, because the old comment's worry was not wrong:
 *   - The admin who came back only to fix step 4 DOES have to satisfy 1–3
 *     first. That is now the intended cost, not a regression: steps 1–3 being
 *     satisfied is the precondition for step 4 meaning anything.
 *   - A locked tab therefore has to explain itself or it is the same dead end
 *     this redesign exists to remove. It renders a padlock, mutes its colours,
 *     carries aria-disabled + a title, and prints the blocking step's NAME on
 *     the tab. It is deliberately NOT natively `disabled`: a disabled button
 *     suppresses its own tooltip and drops out of the tab order, so the one
 *     control that most needs to explain itself would be the one that cannot.
 *   - A READ-ONLY viewer is never gated. See `stepReachable`.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
// TASK-208 / ADR-038 — one company scope, chosen in the header.
import { useActiveCompanyStore } from '@/stores/activeCompany'
// 2026-09-11 — the banner above this screen and the banner above every other
// page are now the same answer, fetched once per session. See the block at
// `blockingStep` for why this screen stopped computing its own.
import { useCommissionReadinessStore } from '@/stores/commissionReadiness'
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
// 2026-09-12 — moved in from its own route; see the step-4 block for why.
import CommissionSplitSettingCard from '@/design-system/components/CommissionSplitSettingCard.vue'

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
/*
 * TASK-245 / ADR-040 — a PLATFORM row (company_id null) belongs to the scoped
 * company as much as to any other, so narrowing must keep it. Dropping it here
 * would hide every shared product from the screen that sets its commission —
 * and commission on a shared product is precisely what stays per company.
 */
function byCompany<T extends { company_id: number | null }>(items: T[]): T[] {
  return isSuperAdmin.value && selectedCompanyId.value
    ? items.filter((i) => i.company_id === null || i.company_id === selectedCompanyId.value)
    : items
}

const commissionReadiness = useCommissionReadinessStore()

/**
 * EVERY WRITE ON THIS SCREEN GOES THROUGH HERE, so the readiness verdict is
 * invalidated in ONE place instead of twenty-one.
 *
 * The alternative — a `refresh()` line after each save — was written first and
 * thrown away: this file has seventeen save/delete functions across five plan
 * types, and the one somebody forgets is the one that leaves an admin looking
 * at "ไม่มีใครได้เงิน" seconds after they fixed it. Worse, the next write added
 * to this screen would be uncovered by default. Wrapping the verb makes the
 * default correct.
 *
 * Reads deliberately still use `api` directly: re-asking the server whether
 * commission is configured after fetching a list would be a request per
 * request.
 *
 * Fire-and-forget on purpose — the save's own reload is what the admin is
 * waiting for, and the banner catching up a tick later costs nobody anything.
 */
const commissionApi = {
  post: async <T,>(path: string, data?: unknown): Promise<T> => {
    const result = await api.post<T>(path, data)
    void commissionReadiness.refresh()

    return result
  },
  put: async <T,>(path: string, data?: unknown): Promise<T> => {
    const result = await api.put<T>(path, data)
    void commissionReadiness.refresh()

    return result
  },
  delete: async <T,>(path: string, data?: unknown): Promise<T> => {
    const result = await api.delete<T>(path, data)
    void commissionReadiness.refresh()

    return result
  },
}


/**
 * TASK-245 — may THIS viewer set a commission rule on this product?
 *
 * Missing reads as NO, for the same reason as everywhere else: a permission
 * that defaults to "allowed" when the answer is absent is how a 403 gets put
 * in front of somebody who was told they could.
 */
function canSetCommission(product: ProductOption): boolean {
  return product.permissions?.set_commission_rule === true
}

/*
 * `canEditPlanType()` LIVED HERE AND WAS DELETED ON 2026-09-12 (human decision).
 *
 * It gated the only control on this screen that wrote `commission_plan_type`
 * onto a product row (PUT /products/{id}, inside the Setup Wizard's first
 * step). Both are gone: a real company does not run unilevel on one product
 * and binary on another — it runs ONE plan and varies the PERCENTAGES. So the
 * plan type is a company-level answer, decided in step 2, and this screen now
 * only ever reads `effective_plan_type` to tell the admin which plan a product
 * falls under.
 *
 * The per-product override still EXISTS in the database and the API, and its
 * Super-Admin-only editor still lives on ProductEditView — that is the one
 * door, deliberately. Do not re-add a second one here.
 */

/**
 * 2026-09-11 — may THIS viewer change ANY commission number on this screen?
 *
 * The owner narrowed every commission write to Super Admin on the same day
 * this screen was restructured: a commission rate is money, and the decision
 * was that one person owns those numbers rather than every tenant admin
 * holding them by virtue of their role. Reads were left completely alone —
 * a Company Admin still has to see the rates their agents are earning under.
 *
 * WHY A BARE ROLE CHECK IS THE HONEST EXPRESSION HERE, AND NOT THE MISTAKE
 * TASK-245 REMOVED.
 *
 * TASK-245 deleted role checks from this file's neighbours because those
 * questions were NOT role questions: "may I set a commission rule on this
 * product" depended on the PRODUCT (shared? catalog-linked? ADR-036 §5/§6),
 * so a copied `role === 'super_admin'` was a guess that happened to agree
 * with the server often enough to look right. The fix was to ask the server
 * per row — `permissions.set_commission_rule`, still read by
 * canSetCommission() above.
 *
 * This question is different in kind. The new rule has NO row dimension at
 * all: five policies now answer create/update/delete with nothing but
 * `$user->isSuperAdmin()` —
 *
 *   CommissionRulePolicy, CommissionOverrideRulePolicy, AgentRankPolicy,
 *   CommissionGenerationRulePolicy, CommissionMatrixLevelRatePolicy
 *
 * — and the six structural-settings singletons (binary / matrix /
 * generation / agent-rank / affiliate-attribution / split) enforce it by
 * their *Update abilities having been taken out of the Company Admin row in
 * PermissionResolver::ROLE_ABILITIES, leaving them only in the Super Admin
 * row. So the role IS the rule, and mirroring it is reporting the server's
 * answer rather than guessing at it.
 *
 * WHAT THIS COSTS, and what breaks it. The day any of those eleven grows a
 * per-company or per-row condition, this computed becomes the TASK-245 bug
 * again — silently, because a role check never errors, it just shows the
 * wrong buttons. If that day comes, the replacement is a server-sent
 * capability (the `permissions` block on a Resource), not a longer boolean
 * here.
 *
 * House rule (owner): "อันไหนสิทธิ์ company admin ทำไม่ได้ต้องซ่อน ไม่ใช่ให้
 * error 403" — so every control this gates is HIDDEN, never disabled-with-a-
 * tooltip and never left clickable to fail at the server.
 */
const canEditCommissionConfig = computed(() => isSuperAdmin.value)

/*
 * `Tab` survives the 4-step redesign on purpose, in a narrower job.
 *
 * It is no longer an address the admin navigates to — it is the LAZY-LOAD
 * KEY for one plan type's data (loadTab / loadedTabs at the bottom of this
 * file), and in step 2 it doubles as "which plan's structural form is on
 * screen". Every `v-if` that used to read it now reads `activeStep`, but the
 * fetching machinery underneath is untouched: rewriting six working loaders
 * to be keyed by something else would have been risk paid for nothing.
 */
type Tab = 'rules' | 'binary' | 'matrix' | 'ranks' | 'generation' | 'affiliate'
const activeTab = ref<Tab>('rules')

/*
 * ── WHAT REPLACED `viewMode` (2026-09-11) ──
 *
 * The 2026-07-22 redesign added a "ภาพรวมสินค้า" / "การตั้งค่าทั้งหมด" toggle
 * on top of the six tabs — an extra axis whose Option B idea (browse by
 * PRODUCT, see the rate that actually resolves, jump to the section that is
 * actually missing) was right and is kept: it is now step 3's "3.2
 * แยกเฉพาะสินค้าที่มาร์จิ้นต่างจริง" list, where it is on the path instead of
 * beside it. Option C's parts likewise survive — the per-plan product counts
 * annotate the step-2 chips, and "ทดสอบคำนวณ" is still on every product row,
 * still deliberately previewing ONLY the direct commission (simulating
 * Override/Matrix/Rank/Generation client-side would risk showing a wrong
 * number as if it were real — see UAT-012 §4; the modal still says so).
 *
 * The TOGGLE itself is gone rather than mapped onto the steps, because a
 * `viewMode` that can only ever hold one value is state that lies about
 * having a choice. `activeStep` is the only mode this screen has now.
 */
type Step = 1 | 2 | 3 | 4
const activeStep = ref<Step>(1)
const stepDefs: { step: Step; label: string }[] = [
  { step: 1, label: 'เลือกบริษัท' },
  { step: 2, label: 'เลือกแผนคอมมิชชั่น' },
  { step: 3, label: 'ตั้งอัตราตัวแทนผู้ขาย' },
  { step: 4, label: 'ส่วนเพิ่มเติม' },
]
/**
 * 'optional' is not a softer 'incomplete'. Step 4 holds three genuinely
 * skippable settings, and telling an admin they are "ยังไม่ครบ" for skipping
 * something skippable is how a warning stops being read — which is the same
 * failure the blanket amber banner had before TASK-213.
 */
type StepStatus = 'done' | 'incomplete' | 'optional'
const stepStatusLabels: Record<StepStatus, string> = {
  done: 'เสร็จแล้ว',
  incomplete: 'ยังไม่ครบ',
  optional: 'ข้ามได้',
}
const planTypeLabels: Record<CommissionPlanType, string> = {
  unilevel: 'Unilevel',
  binary: 'Binary',
  matrix: 'Matrix',
  stairstep_breakaway: 'อันดับ (Stairstep)',
  generation: 'Generation',
  affiliate: 'พันธมิตร (Affiliate)',
}
/*
 * Which plan types have a company-wide structural form to fill in at all.
 *
 * Unilevel is deliberately absent and always has been: it is pure
 * rate-rule-driven, so there is nothing to configure beyond step 3's rates
 * and step 4's leader rate. This map is what step 2 consults to decide
 * whether a chip reveals a form or just an explanation, and what the
 * readiness probe consults to decide whether "no structure" is even a
 * question worth asking about a product.
 *
 * The inverse map (`tabToPlanType`) was deleted with the tab bar — its only
 * two readers were the per-tab product-count badge and the overview's
 * "ตั้งค่าระดับบริษัท" grid. The count badge survives on the step-2 chips,
 * which are keyed by plan type already and so need no translation.
 */
const planTypeToTab: Partial<Record<CommissionPlanType, Tab>> = {
  binary: 'binary',
  matrix: 'matrix',
  stairstep_breakaway: 'ranks',
  generation: 'generation',
  affiliate: 'affiliate',
}
/**
 * The ONE lazy-load entry point (was five: goToSettingsTab, the activeTab
 * watcher, the company watcher, onMounted and the Setup Wizard's own structure
 * loader — since deleted with the wizard — each with its own copy of the
 * `loadedTabs.has()` test).
 *
 * Scattering it was survivable while a section could only be reached by
 * clicking its own tab. It stops being survivable now that a step can render
 * a section the admin never "navigated" to — such a section would show an
 * empty list and never fetch, which reads exactly like "this company has
 * nothing configured".
 */
const inFlightTabLoads = new Map<Tab, Promise<void>>()

async function ensureTabLoaded(tab: Tab): Promise<void> {
  if (loadedTabs.value.has(tab)) return
  /*
   * Deduped by the IN-FLIGHT promise, not just by loadedTabs: `loadedTabs`
   * only gains the key after the request resolves, so two callers arriving in
   * the same tick (a step change that sets activeTab AND asks for its data)
   * would both start the same fetch. Harmless with GETs, but it doubles the
   * traffic on every navigation and makes the readiness probe race itself.
   */
  const existing = inFlightTabLoads.get(tab)
  if (existing) return existing

  const run = loadTab(tab).finally(() => inFlightTabLoads.delete(tab))
  inFlightTabLoads.set(tab, run)

  return run
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

// ══════════════════════════ Commission Rules (TASK-028) ══════════════════════════
interface CertTierOption { id: number; key: string; name: string }
interface ProductOption {
  id: number
  /**
   * TASK-245 / ADR-040 — null for a PLATFORM-owned product: one row every
   * company sells. It is not "missing", and byCompany() must not drop it.
   */
  company_id: number | null
  /**
   * TASK-245 — the Policy's own answer, per row.
   *
   * `set_commission_rule` is deliberately its own question and NOT derived
   * from `update`: ADR-040 keeps commission per company, so a Company Admin
   * may set their own rate on a shared product whose identity is not theirs
   * to edit. Only a catalog-LINKED product refuses both (ADR-036 §5/§6), and
   * that one is invisible from here — its company_id is still this company.
   */
  permissions?: { update: boolean, delete: boolean, set_commission_rule: boolean }
  /*
   * TASK-256 / ADR-040 — read by step 3's on/off switch, and the flag that
   * decides WHICH ENDPOINT that switch writes to.
   *
   * A shared product's on-sale state belongs to the company
   * (company_product_settings); a company-owned product's IS its own
   * `is_active`. Two routes, two policies — see toggleSelling().
   *
   * Optional because it is a field this screen did not use until 2026-09-12,
   * and absent has to read as "not shared": that sends the write to the
   * per-row-permission branch, which is the one that fails closed.
   */
  is_shared?: boolean
  /**
   * Whether the SCOPED company sells this product. Never inherited, and
   * resolved server-side against `?company_id=`
   * (CompanyScopeFilter::contextCompanyId) — which is why loadRulesTabData()
   * now sends one.
   */
  is_sellable_here?: boolean
  name: string
  category?: { id: number; name: string } | null
  price_satang?: number
  /*
   * READ-ONLY on this screen since 2026-09-12. `commission_plan_type` is the
   * product's own override and is only consulted here to say "(สืบทอดจาก
   * บริษัท)" beside the effective plan, and to infer the company's plan in
   * `companyPlanType`. Writing it is ProductEditView's job (Super Admin only)
   * — see the deleted-canEditPlanType note near the top of this file.
   */
  commission_plan_type?: CommissionPlanType | null
  effective_plan_type?: CommissionPlanType
  // TASK-197 §2.1/§3.4 — this product's locked-in commission rate FORMAT
  // (null = not configured yet, the first product-scoped rule decides it
  // server-side). Only relevant to the Commission Rules tab's product
  // scope below; category/company-wide scope never reads this.
  commission_rate_type?: RateType | null
  /*
   * 2026-09-12 — PV / commissionable value in satang, or null when nobody has
   * set one. Exposed raw, with no fallback folded in, because this screen has
   * to tell "worth 0 PV" (a decision) from "no PV yet" (a warning) — only one
   * of them is a gap. The fallback that does exist lives server-side in
   * CommissionBasisResolver, where the money is.
   */
  pv_satang?: number | null
}
/*
 * 2026-09-09 — `company_id` is here so byCompany() can narrow the category
 * picker the way it already narrows the product one. Without it a Super Admin
 * scoped to AIA was offered every company's "Anti Aging" as three identical
 * options, and attaching a rule to the wrong company's category makes a rule
 * that can never match — a commission that silently falls back to the company
 * default rate and lands in an immutable ledger (BR-2/BR-4).
 */
interface ProductCategoryOption { id: number; name: string; company_id: number | null }
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
/*
 * ── THE NAG IS GONE (2026-09-11), AND SO IS ITS DISMISSAL FLAG ──
 *
 * This used to auto-open on every entry to the rules tab, with a
 * "ไม่ต้องแสดงข้อความนี้อีก" checkbox persisted through safeStorage (the
 * AcademyManagementView HIDE_INCOMPLETE_WARNING_KEY pattern). The human
 * asked for that in 2026-07-22 for a good reason: the resolution order is
 * the single most load-bearing fact on the screen, and it was one grey line
 * nobody read.
 *
 * Step 3 now draws that order as a permanent ladder at the top of the panel
 * — สินค้า → หมวดหมู่ → ค่าเริ่มต้นทั้งบริษัท — so the fact is on screen
 * every time, unconditionally, which is strictly more than the modal ever
 * achieved. A modal that interrupts you to repeat what is already drawn
 * behind it is the definition of a nag, so the auto-open went.
 *
 * The persisted dismissal went WITH it, deliberately: a "don't show again"
 * flag for something that is never shown unless you ask for it is dead state
 * that reads as if a suppression is still in force. The modal itself stays,
 * reachable from a "ลำดับการใช้ค่า" link beside the ladder, because the full
 * sentence is longer than the diagram can carry.
 */
const showResolutionOrderModal = ref(false)

function openResolutionOrderModal() {
  showResolutionOrderModal.value = true
}

function closeResolutionOrderModal() {
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
      await commissionApi.put(`/commission-rules/${editingRuleId.value}`, payload)
    } else {
      await commissionApi.post('/commission-rules', payload)
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
    await commissionApi.delete(`/commission-rules/${r.id}`)
    commissionRules.value = commissionRules.value.filter((x) => x.id !== r.id)
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}
async function loadRulesTabData() {
  const [r, p, pc, o] = await Promise.all([
    api.get<{ data: CommissionRuleItem[] }>('/commission-rules'),
    /*
     * The ONE scoped read on this screen, and only since 2026-09-12.
     *
     * `is_sellable_here` has no answer without a company to resolve it
     * against: CompanyScopeFilter::contextCompanyId() reads `?company_id=`
     * for a Super Admin, who belongs to no company, so an unscoped fetch
     * reports every shared product as "not for sale" no matter which company
     * the header is on — the exact state step 3's new switch exists to show
     * and change. A Company Admin was always fine; the Super Admin, the only
     * actor who may work that switch on a shared row, was not.
     *
     * Narrowing loses nothing byCompany() was keeping: ProductController's
     * filter runs with `includePlatformWide: true`, so the shared rows come
     * back alongside the company's own. byCompany() below is left in place
     * over the result — it is still the truth for the "ทุกบริษัท" view, where
     * companyQuery() is empty.
     */
    api.get<{ data: ProductOption[] }>(`/products${companyQuery()}`),
    // Unscoped on purpose, like every other list on this screen: byCompany()
    // does the narrowing client-side because it must KEEP the platform rows,
    // and that needs each row's own company_id.
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
/*
 * ── `rateRecipientFilter` WAS DELETED BY THE 4-STEP FLOW (2026-09-11) ──
 *
 * TASK-213 put agent rates and leader rates in ONE list behind a
 * ทั้งหมด/ตัวแทนผู้ขาย/หัวหน้าทีม chip row, because "an admin thinks
 * ตัวแทนได้เท่าไหร่ / หัวหน้าได้เท่าไหร่, not commission_rules vs
 * commission_override_rules".
 *
 * That reasoning did not go away — it got a stronger expression. The two
 * recipients are now two STEPS: agent rates are step 3 (the one that cannot
 * be skipped, because without it nobody is paid at all) and leader rates are
 * step 4 (an addition on top of a working payout). A filter chip that hides
 * half a list is a weaker version of a step that names why the half exists,
 * and keeping both would have meant three consumers of one ref — the chips,
 * which add button exists, and which rows render — disagreeing about which
 * step they were on.
 */
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
      await commissionApi.put(`/commission-override-rules/${editingOverrideId.value}`, body)
    } else {
      await commissionApi.post('/commission-override-rules', withCompanyBody(body))
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
    await commissionApi.delete(`/commission-override-rules/${r.id}`)
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

// ── Product-row helpers (the Option B idea, now living in step 3.2) ──
/*
 * Sets the STEP as well as the form. It used to set viewMode + activeTab +
 * form state together for exactly this reason: the form opens as an overlay
 * over whatever is behind it, and closing it must not drop the admin back on
 * a panel that has nothing to do with what they just saved. Step 3 is where
 * the row they clicked lives, so step 3 is where they land.
 */
function openRuleFormForProduct(p: ProductOption) {
  /*
   * Assigns `activeStep` directly rather than calling goToStep(), and that is
   * safe for one reason only: its caller (the product card in step 3.2) is
   * already ON step 3, so this re-asserts the current step rather than
   * navigating to another one — and the current step is reachable by
   * definition (see `stepReachable`).
   * Anything that starts calling this from elsewhere must go through
   * goToStep() instead, or it will have found a way around the 2026-09-12
   * gate.
   */
  activeStep.value = 3
  activeTab.value = 'rules'
  void ensureTabLoaded('rules')
  resetRuleForm()
  ruleForm.value.scope = 'product'
  ruleForm.value.product_id = p.id
  showRuleForm.value = true
}
/** Step 3's two add buttons ("+ เพิ่มอัตราของสินค้า" / "…ของหมวดหมู่"). */
function openCreateRuleFormWithScope(scope: RuleScope) {
  resetRuleForm()
  ruleForm.value.scope = scope
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
  /*
   * byCompany() — ADDED 2026-09-12, AND IT IS NOT COSMETIC.
   *
   * Owner: "ผมทดสอบ Almod Chips ปรับค่าคอมให้เป็น 5% แล้วเปลี่ยนบริษัทดู 5%
   * ทุกบริษัท คือที่ตั้งใจคือ thai life อย่างเดียว".
   *
   * This list is loaded UNSCOPED on purpose — every company's rows arrive in
   * one request and each reader narrows them (see loadRulesTabData). Every
   * other reader on this screen already did: conflictingRuleIds,
   * activeOverrideRules, productsMissingAgentRate. This one did not, so a rate
   * belonging to Thai Life was resolved, displayed and counted as AIA's. The
   * products are shared (ADR-040), so the collision is not an edge case — it
   * is what the data normally looks like.
   *
   * The same defect existed one layer down, in CommissionService, where it
   * paid real money at another company's rate; that is fixed and pinned by
   * CrossCompanyRateIsolationTest. Do not "simplify" this call away — the two
   * fixes have to stay in step, or the screen resumes disagreeing with the
   * ledger.
   */
  const candidates = byCompany(commissionRules.value).filter((r) => isRuleActiveOn(r, now))
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

/*
 * `totalConflicts` (the two sets added together) was deleted with the tab
 * bar. It existed because the overview and the rules tab were different
 * places and the overview could only afford ONE number to send an admin to
 * the other one. The steps split the two kinds of collision back apart —
 * agent-rate overlaps are step 3's and leader-rate overlaps are step 4's —
 * and each is now counted where it can actually be deleted, which is the
 * only place the count is useful.
 */

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

/*
 * `leaderRateLabel` was deleted with the overview card it formatted. Step 4
 * shows the leader rows themselves rather than one summarised number per
 * product, so there is nothing left to abbreviate to '—' — and '—' was
 * always the weakest part of that card: it could not distinguish "no leader
 * rate" from "this plan does not pay the leader from this table at all".
 * leaderRateGaps() answers that question properly instead.
 */

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

// ══════════════════════════ Commission basis / PV (2026-09-12) ══════════════════════════
/*
 * Owner, 2026-09-12: "ทำแผน PV กับการตั้งค่าแบบคอม ขายตรง เก็บการคิดแบบ % และ
 * Fix จำนวนเงิน ไว้กับค่าคอมปรกติ".
 *
 * WHAT THIS IS. Not a third rate type — % and จำนวนคงที่ are untouched. One
 * level up: what a percentage is a percentage OF. Either the sale price
 * (today's behaviour, every existing company) or the product's PV.
 *
 * WHY IT SITS IN STEP 2 AND NOWHERE ELSE. It is the same sentence as the plan
 * type — "how does this company pay" — and the owner's whole complaint about
 * the old screen was not knowing what to answer first. Two halves of one
 * question asked on two screens would rebuild exactly that.
 *
 * WHY THE PV FIGURES ARE EDITED HERE TOO, rather than one product at a time in
 * the catalogue. Switching a company to PV makes every product's PV load-
 * bearing at once, and a company with eleven products would otherwise have to
 * visit eleven screens to finish one decision — with no page anywhere telling
 * them how many were left. The same table that asks the question counts the
 * answers.
 */
type CommissionBasis = 'price' | 'pv'

const commissionBasis = ref<CommissionBasis>('price')
/**
 * `companies.commission_plan_type`, straight from the company row.
 *
 * null means "not read yet / could not be read", never "this company has no
 * plan" — every company has one (the column defaults to unilevel). See
 * companyPlanType for what the screen does with that distinction.
 */
const companyPlanTypeFromServer = ref<CommissionPlanType | null>(null)
/**
 * The read failed, so the screen does not know which base this company pays
 * on — and says so, instead of showing the default as though it were the
 * answer.
 *
 * This flag is the whole defence. `commissionBasis` must hold SOMETHING for
 * the template to bind to, and a screen that rendered that as the SELECTED
 * option would state a wrong answer about somebody's money with no way for
 * the reader to tell. While this is true, step 2 shows the error and neither
 * option is marked chosen.
 */
const basisUnknown = ref(false)
const basisSaving = ref(false)
const basisError = ref('')

const basisLabels: Record<CommissionBasis, string> = {
  price: 'ราคาขาย',
  pv: 'PV (คะแนนสินค้า)',
}

/**
 * What "%" is a percentage OF, in the rate form's own words.
 *
 * `rateTypeLabels.percentage` stays '% ของยอดขาย' because it is also read by
 * places that have no company context. This is the one the FORM uses, and it
 * has to follow the basis: an admin typing 5 into a field labelled "% ของ
 * ยอดขาย" on a PV company has been told the wrong thing at the exact moment
 * it matters, and what they produce is a rate that pays a number they never
 * intended into a ledger nobody may correct.
 */
const percentageOptionLabel = computed(() => commissionBasis.value === 'pv' ? '% ของ PV' : '% ของยอดขาย')

/**
 * GET /commission-settings — commission's OWN endpoint, not the platform
 * companies resource.
 *
 * ── WHY NOT /companies/{id}, WHICH ALSO CARRIES BOTH FIELDS ──
 *
 * It did, for a few hours on 2026-09-12, and it worked by leaning on an
 * exception: `routes/api.php` described the companies resource as "Super Admin
 * only end to end", which is true of index/store/update/destroy and NOT of
 * show, where CompanyPolicy::view also allows a user to read their OWN
 * company. This whole screen balanced on that one clause.
 *
 * The owner's point: somebody tightening that clause — on the strength of the
 * comment right above it — would break nothing visible. No exception, no
 * failing test near the change. This function would catch the 403 and the
 * screen would render its defaults, showing 'ราคาขาย' as the selected basis at
 * a company that pays on PV. A confident, wrong answer about how somebody's
 * agents are paid.
 *
 * ── AND WHY FAILURE IS NOW LOUD ──
 *
 * Moving the endpoint removes the likely cause; `basisUnknown` removes the
 * BEHAVIOUR that made it dangerous. Whatever goes wrong — a 403, a 500, a
 * dropped connection — this function no longer converts "I could not find
 * out" into "it is ราคาขาย". Step 2 renders the question instead of an
 * answer.
 *
 * The plan type is still read here rather than inferred from the products:
 * see companyPlanType for the case the inference cannot answer.
 */
async function loadCompanySettings(): Promise<void> {
  const id = effectiveCompanyId.value
  if (!id) {
    /*
     * No company asked about is not a failed read. A Super Admin on
     * "ทุกบริษัท" has no single answer and step 2 already refuses to render
     * for them, so this is deliberately NOT `basisUnknown` — a red error
     * over a state whose only problem is "pick a company first" is the kind
     * of warning that teaches people to ignore warnings.
     */
    commissionBasis.value = 'price'
    companyPlanTypeFromServer.value = null
    basisUnknown.value = false

    return
  }
  try {
    const r = await api.get<{ data: { commission_basis?: CommissionBasis; commission_plan_type?: CommissionPlanType | null } }>(
      `/commission-settings${companyQuery()}`,
    )
    commissionBasis.value = r.data.commission_basis ?? 'price'
    companyPlanTypeFromServer.value = r.data.commission_plan_type ?? null
    basisUnknown.value = false
  } catch {
    // Left NULL, not defaulted: the screen does not know the plan, and
    // companyPlanType falls back to the inference rather than asserting one
    // nobody confirmed.
    companyPlanTypeFromServer.value = null
    basisUnknown.value = true
  }
}

/**
 * The switch itself — PUT /companies/{id}, which is CompanyPolicy::update and
 * therefore Super Admin, the same gate `canEditCommissionConfig` already
 * expresses on every other control here.
 *
 * Optimistic assignment is deliberately NOT done: this decides what every
 * future payout is computed from, and a control that shows the new answer
 * before the server has taken it would let an admin walk away from a switch
 * that never happened.
 */
const planSwitching = ref(false)
const planSwitchError = ref('')

/**
 * Switch the company onto the plan currently being VIEWED (step 2's chips).
 *
 * 2026-09-12 — replaces the link-out to /companies. See the button's own
 * comment in the template for the owner's report that produced it.
 *
 * Writes through the same endpoint and the same Ability as the basis
 * (PUT /commission-settings), which is what let this become a button at all:
 * the plan type used to be CompanyPolicy::update and therefore somebody
 * else's screen.
 *
 * `companyPlanTypeFromServer` is assigned from the RESPONSE rather than from
 * `viewingPlanType`. They should be the same value and the difference matters
 * anyway: what the screen goes on to draw — which structural section step 2
 * shows, which plan step 4 says pays the upline — has to be what the server
 * actually stored, not what this function asked for.
 */
async function useViewedPlan(): Promise<void> {
  const id = effectiveCompanyId.value
  if (!id || planSwitching.value || viewingPlanType.value === companyPlanType.value) return

  planSwitching.value = true
  planSwitchError.value = ''
  try {
    const r = await commissionApi.put<{ data: { commission_plan_type?: CommissionPlanType | null } }>(
      '/commission-settings',
      withCompanyBody({ commission_plan_type: viewingPlanType.value }),
    )
    companyPlanTypeFromServer.value = r.data.commission_plan_type ?? null
    /*
     * The new plan's structural settings (Binary's cycle, Matrix's width,
     * Generation's depth …) have never been fetched for this company, and
     * step 2 is about to render that section. Without this the admin switches
     * to Matrix and is shown an empty form that looks configured.
     */
    await loadReadinessProbe()
    const tab = planTypeToTab[viewingPlanType.value]
    if (tab) await ensureTabLoaded(tab)
  } catch (e) {
    planSwitchError.value = apiErrorMessage(e, 'เปลี่ยนแผนไม่สำเร็จ')
  } finally {
    planSwitching.value = false
  }
}

async function setCommissionBasis(next: CommissionBasis): Promise<void> {
  const id = effectiveCompanyId.value
  if (!id || next === commissionBasis.value || basisSaving.value) return

  basisSaving.value = true
  basisError.value = ''
  try {
    await commissionApi.put('/commission-settings', withCompanyBody({ commission_basis: next }))
    commissionBasis.value = next
    // A successful write is also a successful read: whatever made the load
    // fail, the screen now knows the answer, because it just set it.
    basisUnknown.value = false
  } catch (e) {
    basisError.value = apiErrorMessage(e, 'เปลี่ยนฐานการคำนวณไม่สำเร็จ')
  } finally {
    basisSaving.value = false
  }
}

/**
 * Products this company sells that have no PV yet — the gap that makes the
 * whole feature survivable.
 *
 * The server falls back to the sale price when a PV company sells a product
 * with no PV (CommissionBasisResolver), deliberately: a 0-satang row, or a
 * refusal to pay, would both be worse. But that fallback is SILENT where it
 * happens — the rate resolves, the gates pass, a row is written at a number
 * the rate never meant, into a ledger BR-4 forbids correcting. This list, the
 * step-2 lock below, and the server's own `point_value_missing` issue are the
 * three places that make it loud.
 *
 * `pv_satang === 0` is NOT in this list. Zero PV is a decision — a bundled
 * item that pays nobody — and the server honours it (`??`, never `?:`).
 */
const productsMissingPointValue = computed<ProductOption[]>(() =>
  commissionBasis.value === 'pv'
    ? byCompany(products.value).filter((p) => p.pv_satang === null || p.pv_satang === undefined)
    : [])

// One draft string per product id, so a half-typed number never touches the
// row it came from and an abandoned edit costs nothing.
const pvDrafts = ref<Record<number, string>>({})
const pvSavingId = ref<number | null>(null)
const pvError = ref('')

function pvDraftFor(p: ProductOption): string {
  // A draft of '' is a REAL draft — it is how an admin asks for the PV to be
  // cleared — so this checks for the key's presence, never for truthiness.
  const draft = pvDrafts.value[p.id]
  if (draft !== undefined) return draft

  return p.pv_satang === null || p.pv_satang === undefined ? '' : String(p.pv_satang / 100)
}

/**
 * Saves one product's PV. Super-Admin-only server-side — UpdateProductRequest
 * PROHIBITS the field for anybody else rather than dropping it, so a Company
 * Admin who somehow reached this control gets a 422 and not a form that
 * appeared to save. The control is hidden from them anyway
 * (`canEditCommissionConfig`); the two agree on purpose.
 *
 * An empty input clears the PV back to null — a real edit, not a no-op. A PV
 * entered by mistake has to be removable, and null is what the warning is
 * about, which makes "clear it" an honest thing to be able to do.
 */
async function savePointValue(p: ProductOption): Promise<void> {
  const raw = pvDraftFor(p).trim()
  const next = raw === '' ? null : Math.round(Number(raw) * 100)

  if (next !== null && (!Number.isFinite(next) || next < 0)) {
    pvError.value = 'PV ต้องเป็นตัวเลขไม่ติดลบ'

    return
  }

  pvSavingId.value = p.id
  pvError.value = ''
  try {
    await commissionApi.put(`/products/${p.id}`, { pv_satang: next })
    // Patch the loaded row rather than refetching the whole catalogue: the
    // list is the only reader, and a reload here would blank every other
    // draft the admin has in progress.
    const row = products.value.find((x) => x.id === p.id)
    if (row) row.pv_satang = next
    delete pvDrafts.value[p.id]
  } catch (e) {
    pvError.value = apiErrorMessage(e, 'บันทึก PV ไม่สำเร็จ')
  } finally {
    pvSavingId.value = null
  }
}

// ═══════════ Step 3 · เปิด/ปิดขายสินค้า, in the row (2026-09-12, owner) ═══════════
/*
 * "ให้แสดงผลเหมือนหน้าสินค้า … เพิ่มการเปิดปิดสินค้าได้เลย จะได้ทำหน้าเดียวจบ
 * แต่ทำแยกบริษัทได้".
 *
 * Step 3 already puts every product in front of the admin, one row each, to
 * decide what it pays. Sending them to /product-catalog to switch one off and
 * back here to rate it is the same detour the 4-step flow was built to delete.
 *
 * The look is deliberately ProductCatalogView's, not a second dialect of it:
 * same greyed row, same sort, same switch with the same two words. These are
 * one control in two places, and an admin should not have to learn it twice.
 */

/**
 * Selling first, then by name — SORTED, never filtered.
 *
 * A closed product still needs a rate: it is the one somebody is about to
 * switch back on, and the owner said so outright ("ที่ปิดไว้ก็แก้ไขได้เหมือน
 * เดิม"). What is on sale is today's work; what is closed is reference.
 */
const sellingFirstProducts = computed<ProductOption[]>(() =>
  /*
   * `.slice()` BEFORE `.sort()`: byCompany() hands back the SOURCE array
   * untouched whenever there is nothing to narrow (any Company Admin, and the
   * "ทุกบริษัท" view), so sorting in place would reorder `products.value`
   * itself and make each render depend on the last one.
   *
   * `localeCompare(…, 'th')` so each half is ordered the way a Thai reader
   * expects rather than by insertion.
   */
  byCompany(products.value).slice().sort((a, b) => {
    const aSelling = a.is_sellable_here === true
    const bSelling = b.is_sellable_here === true
    if (aSelling !== bSelling) return aSelling ? -1 : 1

    return a.name.localeCompare(b.name, 'th')
  }))

/**
 * May THIS viewer work THIS row's on/off switch — two different questions,
 * because the switch writes to two different endpoints.
 *
 * SHARED row → PUT /products/{id}/company-settings, whose FormRequest
 * authorizes on `$user->isSuperAdmin()` and nothing else
 * (UpdateCompanyProductSettingRequest). There is no row dimension to ask
 * about, so mirroring the role IS reporting the server's answer — the same
 * reasoning canEditCommissionConfig sets out at the top of this file.
 *
 * COMPANY-OWNED row → PUT /products/{id} under ProductPolicy::update, which
 * is per row (ADR-036 §5/§6 refuses a catalog-LINKED product that this
 * company nonetheless owns). The server already answered it in
 * `permissions.update`; asking the role here instead would be the TASK-245
 * bug again, silently, because a role check never errors.
 *
 * Deliberately NOT gated on `canEditCommissionConfig`: opening a product for
 * sale is a catalogue decision, not a commission number, and a Company Admin
 * who owns the product owns that decision.
 */
function canToggleSelling(p: ProductOption): boolean {
  return p.is_shared === true ? isSuperAdmin.value : p.permissions?.update === true
}

/** The one row whose switch is mid-flight, so only that row goes inert. */
const sellingSavingId = ref<number | null>(null)
const sellingError = ref('')

/**
 * Open or close this product for the company in the header.
 *
 * `commissionApi.put`, not `api.put`: this changes WHICH products a company
 * sells, and the readiness verdict counts products against rates
 * (`products_total` / `products_covered`). A bare api.put would leave the
 * banner quoting a count that no longer exists — see commissionApi's docblock.
 */
async function toggleSelling(p: ProductOption): Promise<void> {
  if (sellingSavingId.value === p.id || !canToggleSelling(p)) return

  const next = p.is_sellable_here !== true
  sellingSavingId.value = p.id
  sellingError.value = ''
  try {
    if (p.is_shared === true) {
      /*
       * `company_id` is REQUIRED and cannot be inferred server-side — the only
       * actor who reaches this branch is a Super Admin, who has no company of
       * their own to infer from, and guessing would open the wrong tenant's
       * catalogue. Step 3 renders an EmptyState until one is picked, so it is
       * never null here in practice; the guard is what makes that true rather
       * than assumed.
       *
       * `is_active` alone, with no `price_satang` key: omitting it means
       * "leave the price alone", and opening a product for sale must not also
       * decide what it costs.
       */
      if (effectiveCompanyId.value === null) return
      await commissionApi.put(`/products/${p.id}/company-settings`, {
        company_id: effectiveCompanyId.value,
        is_active: next,
      })
    } else {
      /*
       * A company-owned product has no per-company settings row, and the
       * endpoint above 422s for it on purpose (ProductController::
       * updateCompanySetting) — its price and its on/off switch live on the
       * product itself, and a second place to set them is how the two
       * disagree. Selling it IS `is_active` (Product::isSellableBy).
       */
      await commissionApi.put(`/products/${p.id}`, { is_active: next })
    }
    /*
     * Patch the loaded row, never refetch. Step 2 holds a PV draft per product
     * id while the admin types, and reloading the catalogue from here would
     * blank every one of them mid-edit — the same reason savePointValue()
     * gives, one step over.
     */
    const row = products.value.find((x) => x.id === p.id)
    if (row) row.is_sellable_here = next
  } catch (e) {
    sellingError.value = apiErrorMessage(e, 'เปิด/ปิดขายสินค้าไม่สำเร็จ')
  } finally {
    sellingSavingId.value = null
  }
}

// ══════════════════════════ The 4-step flow (2026-09-11) ══════════════════════════
/*
 * Everything below re-cuts facts productReadiness() already establishes, per
 * STEP instead of per PRODUCT. Both views are needed and neither replaces
 * the other: a product card has to say what is wrong with that product, and
 * a step tab has to say whether that step is finished — and one product with
 * no rate makes step 3 unfinished no matter how many others are fine.
 *
 * The cut lines match the server's, not the screen's convenience:
 *   step 2 owns the STRUCTURAL singletons (binary/matrix/rank/generation),
 *   step 3 owns commission_rules,
 *   step 4 owns commission_override_rules and the two other routes.
 */

/** In-use plan types whose company-wide structure is confirmed MISSING (step 2). */
const unsetStructuralPlans = computed<CommissionPlanType[]>(() => {
  const inUse = new Set(byCompany(products.value).map((p) => p.effective_plan_type).filter(Boolean) as CommissionPlanType[])

  // `=== false` and not `!`: loadReadinessProbe() leaves a key UNDEFINED when
  // its probe could not answer, and "unknown" must never raise an alarm.
  return [...inUse].filter((pt) => structureReady.value[pt] === false)
})

/** Products with no rate that resolves TODAY — the money-losing set (step 3). */
const productsMissingAgentRate = computed<ProductOption[]>(() =>
  byCompany(products.value).filter((p) => !resolveRuleFor(p)))

/*
 * Step 3 is complete when every product resolves to exactly ONE live rate.
 * Overlaps count as incomplete for the same reason productReadiness ranks
 * them beside "no rule at all": the money still moves, at an amount nobody
 * chose and nobody can predict, into a ledger that cannot be corrected (BR-4).
 */
const step3Complete = computed(() =>
  productsMissingAgentRate.value.length === 0 && conflictingRuleIds.value.size === 0)

/**
 * Unilevel/Affiliate products with nobody set to pay the upline (step 4).
 *
 * Only those two plans pay the leader out of commission_override_rules; on
 * the others the upline is paid by that plan's own structure, so counting
 * them here would invent a gap that does not exist.
 */
const leaderRateGaps = computed<ProductOption[]>(() =>
  byCompany(products.value).filter((p) =>
    (p.effective_plan_type === 'unilevel' || p.effective_plan_type === 'affiliate') && !resolveOverrideFor(p)))

const stepStatuses = computed<Record<Step, StepStatus>>(() => ({
  1: effectiveCompanyId.value ? 'done' : 'incomplete',
  /*
   * 2026-09-12 — a missing PV blocks step 2 exactly as a missing structural
   * setting does, and for the same reason: both let step 3's rates be
   * written against a base that is not the one the admin thinks they are
   * configuring. Entering "5%" while three products silently pay on their
   * price instead of their PV is the precise mistake this step order exists
   * to make impossible.
   */
  2: unsetStructuralPlans.value.length || productsMissingPointValue.value.length ? 'incomplete' : 'done',
  3: step3Complete.value ? 'done' : 'incomplete',
  // Always skippable — see StepStatus above. The banner still says what
  // skipping it costs, which is the honest half of "ข้ามได้".
  4: 'optional',
}))

// ── Step gating (2026-09-12): "ต้องทำทีละขั้นตอน" ──
/**
 * The earliest step that is not finished — the far edge of what may be reached.
 *
 * Only 'incomplete' blocks. 'optional' does NOT, which matters for exactly one
 * step and is worth stating: step 4 is ข้ามได้, so if it ever gained a step 5
 * that step would not sit behind it. The converse is not true and is the
 * owner's point — step 4 being optional does not put it in FRONT of step 3.
 * Optional means "you may leave this empty", never "you may arrive here
 * early"; step 3 is where the agent's own rate is set, and a leader rate
 * configured on top of a product that pays nobody is a split of zero.
 *
 * null = nothing is incomplete, so nothing is behind a lock.
 */
const firstIncompleteStep = computed<Step | null>(
  () => stepDefs.find((s) => stepStatuses.value[s.step] === 'incomplete')?.step ?? null)

/**
 * Which of the four tabs may be clicked.
 *
 * THE READ-ONLY EXCEPTION, AND WHY IT MUST SURVIVE FUTURE TIDYING.
 * `canEditCommissionConfig` is Super Admin only (commission config became
 * Super-Admin-only-to-WRITE on 2026-09-11; reading it was deliberately left
 * open, because a Company Admin has to be able to see the rates their agents
 * are paid under). A Company Admin therefore cannot COMPLETE any step here —
 * every control that would finish one is hidden from them by the house rule
 * "อันไหนสิทธิ์ company admin ทำไม่ได้ต้องซ่อน". Gating them by completion
 * would leave the whole screen behind a lock they have no way to open, and
 * for a reason that has nothing to do with them: the gate exists to stop an
 * EDITOR configuring step 4 before step 3, and they are not editing anything.
 * So: no edit rights, no gate. Do not "simplify" this clause away — deleting
 * it reads as a tightening and lands as a lockout of every Company Admin.
 *
 * `s === activeStep` is the other clause that looks redundant and is not: a
 * step can go incomplete UNDER the admin (a rate expires at midnight, another
 * admin deletes the company default, the company switcher changes company),
 * and locking the tab somebody is standing on would leave the bar with no
 * selected tab and no way back to it.
 */
const stepReachable = computed<Record<Step, boolean>>(() => {
  const edge = firstIncompleteStep.value
  const open = (s: Step): boolean =>
    !canEditCommissionConfig.value || edge === null || s <= edge || s === activeStep.value

  return { 1: open(1), 2: open(2), 3: open(3), 4: open(4) }
})

/**
 * What a locked tab SAYS. A refusal without a reason is the dead end the
 * whole 4-step redesign was built to remove, so every lock names the step
 * that has to be finished first, by number AND by name — "ขั้นที่ 3" alone
 * would make the admin count the tabs to find out which one that is.
 *
 * Returns '' for a reachable step so the template can use it as the truthiness
 * test too, rather than asking the same question twice in two ways.
 */
function stepLockHint(step: Step): string {
  if (stepReachable.value[step]) return ''
  const blocker = firstIncompleteStep.value
  if (blocker === null) return ''

  return `ทำขั้นที่ ${blocker} ${stepLabel(blocker)} ให้เสร็จก่อน`
}

/**
 * The ONE step the admin should go to next, worst-first.
 *
 * The order is productReadiness()'s, lifted: no rate at all beats a missing
 * structure (a product with no rate pays NOBODY, which makes every other
 * observation about it irrelevant), and both beat a missing leader rate
 * (where the agent is still paid).
 *
 * null = nothing is blocking; the banner turns green.
 */
/*
 * ── 2026-09-11 — THE BANNER NOW READS THE SERVER, NOT THIS FILE ──
 *
 * The four computeds below used to derive the banner from the rows this
 * screen had already loaded, and that was defensible while this was the only
 * screen that showed it. It stopped being defensible the day the owner asked
 * for the same warning on EVERY page ("หากยังไม่ได้มีการ setup ค่าคอม
 * ให้แจ้งเตือนในทุกหน้า"): the app shell's banner asks
 * GET /commission-readiness, this one derived its own answer, and two answers
 * about whether anybody is being paid WILL diverge — over a rate that expired
 * at midnight, a product another admin added in the next tab, a category rule
 * this screen filters client-side. They would diverge, of all places, in
 * front of the person who opened this screen to fix it.
 *
 * So `commissionReadiness` is the single source, and the server is where the
 * rate-resolution rule lives (mirroring CommissionService exactly — see
 * CommissionReadinessService's docblock).
 *
 * WHAT STAYED LOCAL, AND WHY THAT IS NOT THE SAME MISTAKE. The step PILLS and
 * the per-product cards below still compute from loaded rows, because they
 * answer a different question: "what is wrong with THIS row / THIS step",
 * which the endpoint deliberately does not carry (it is fetched by every
 * admin session and must stay small). The banner's verdict is the thing that
 * had to be one answer, and now is.
 */
const blockingStep = computed<Step | null>(() => commissionReadiness.blockingStep)

/**
 * Where the banner's jump button actually goes — which is not always the step
 * the banner NAMES, and that is the whole reason this computed exists.
 *
 * In the ordinary case they are identical: the server's blocking step is the
 * earliest thing that is wrong, and the earliest thing that is wrong is by
 * definition reachable. But the two answers come from two places on purpose
 * (see the block above: the banner is the SERVER's verdict, the pills and
 * therefore the locks are computed from the rows this screen loaded), and two
 * sources that are allowed to disagree eventually will — over a rate that
 * expired at midnight, a product another admin added, a category rule this
 * screen narrows client-side. When they do, the server can name step 4 while
 * this screen still counts step 3 as incomplete, and a jump button that
 * landed on a locked tab would do nothing at all in front of the one person
 * who opened this screen to fix something.
 *
 * So the jump is clamped to the local edge, which `stepReachable` guarantees
 * is open. It cannot land on a locked step under ANY combination of the two
 * answers, and it never has to guess which of them is right: the step it
 * falls back to is the one the admin has to clear before the server's step
 * could be worked on anyway.
 */
const jumpStep = computed<Step | null>(() => {
  const target = blockingStep.value
  if (target === null) return null

  return stepReachable.value[target] ? target : (firstIncompleteStep.value ?? target)
})

/**
 * 'bad' = closed deals pay nobody · 'warn' = they pay, but not everybody.
 *
 * Note what moved with the verdict: a missing plan STRUCTURE, and two live
 * rates fighting over one scope, used to paint this red. The server calls
 * both 'incomplete', per the owner's own definition of the amber state — in
 * each case somebody IS paid. Red is now reserved for the one sentence it
 * claims: nobody is.
 *
 * An unknown state (first paint, before the fetch lands, or a failed fetch)
 * reads as 'ok' — the same thing this screen did before, when its empty
 * arrays resolved to "nothing missing". A banner must not shout about a
 * verdict it does not have yet.
 */
const readinessLevel = computed<ReadinessLevel>(() => {
  if (commissionReadiness.state === 'missing') return 'bad'
  if (commissionReadiness.state === 'incomplete') return 'warn'

  return 'ok'
})

const readinessHeadline = computed(() => {
  if (commissionReadiness.state === null) return 'กำลังตรวจสอบสถานะการตั้งค่าค่าคอม…'
  if (readinessLevel.value === 'ok') return 'พร้อมจ่ายค่าคอมแล้ว — ทุกสินค้ามีอัตราที่ใช้ได้'
  if (readinessLevel.value === 'warn') return 'จ่ายตัวแทนผู้ขายได้แล้ว แต่หัวหน้าทีมยังไม่ได้ส่วนแบ่ง'

  return 'ยังไม่พร้อมจ่ายค่าคอม — ดีลที่ปิดได้จะไม่มีใครได้เงิน'
})

/**
 * The second line: which step it is stuck on, and the number that proves it.
 *
 * The numbers come from the server's `issues`, already in Thai and already
 * carrying their own counts — re-phrasing them here would put a second copy
 * of a sentence about money in a second file, which is the whole thing this
 * change removes. Every issue is joined rather than only the first, because
 * this is the screen where they get fixed: an admin about to clear one gap
 * should see the next without hunting for it.
 */
const readinessDetail = computed(() => {
  const step = blockingStep.value
  if (step === null) return 'ตรวจจากอัตราและโครงสร้างที่ตั้งไว้จริง ณ วันนี้ (ตรวจโดยเซิร์ฟเวอร์)'

  const stuck = `ติดอยู่ที่ขั้นที่ ${step} · `
  if (step === 1) return `${stuck}ยังไม่ได้เลือกบริษัท — เลือกบริษัทก่อนจึงจะดูและตั้งอัตราได้`

  const labels = commissionReadiness.issues.map((i) => i.label).join(' · ')

  return labels ? `${stuck}${labels}` : stuck.replace(/ · $/, '')
})

// ── Step 2: which plan, and what that plan means ──
const planChipOrder: CommissionPlanType[] = ['unilevel', 'binary', 'matrix', 'stairstep_breakaway', 'generation', 'affiliate']

/**
 * The plan THIS COMPANY is on.
 *
 * 2026-09-12 — THE SERVER'S ANSWER FIRST, THE INFERENCE ONLY AS A FALLBACK.
 *
 * This used to be inferred alone: a product with no `commission_plan_type` of
 * its own inherits the company's, so that product's `effective_plan_type` IS
 * the company's answer, and reading it cost no extra request. The inference is
 * sound, and it has one hole — a company where EVERY product carries its own
 * override answers `null`, and step 1 then reports "ยังไม่ทราบ" about a value
 * the company definitely has. The owner hit exactly that ("ระบบต้องดึงข้อมูลที่
 * ถูกต้องมาแสดงในทุกขั้นตอนให้ถูกต้อง", 2026-09-12) on a company showing
 * ยังไม่ทราบ while steps 1–3 all read เสร็จแล้ว.
 *
 * The fix costs nothing new: this screen already fetches GET /companies/{id}
 * for `commission_basis`, and `commission_plan_type` is on the same row. So
 * the authoritative value is used when it is known, and the inference stays
 * underneath it for the one moment it still matters — the first render, before
 * that request lands, where the products are already in hand.
 *
 * Still null rather than defaulted to Unilevel when neither can answer: BR-7's
 * rule against guessing a business value applies to what the screen ASSERTS as
 * much as to what it submits.
 */
const companyPlanType = computed<CommissionPlanType | null>(() =>
  companyPlanTypeFromServer.value
  ?? byCompany(products.value).find((p) => !p.commission_plan_type && p.effective_plan_type)?.effective_plan_type
  ?? null)

/** Which plan's details are on screen in step 2 — not necessarily the one in use. */
const viewingPlanType = ref<CommissionPlanType>('unilevel')

/**
 * What each plan actually does, in the words an admin uses.
 *
 * `affects` is the part the six tabs could never say: it names the step this
 * choice changes, which is the whole reason the choice is step 2 rather than
 * a dropdown on some other screen.
 */
const planExplainers: Record<CommissionPlanType, { title: string; how: string; affects: string }> = {
  unilevel: {
    title: 'Unilevel — ขายตรง + ส่วนแบ่งหัวหน้าสาย',
    how: 'ตัวแทนได้จากยอดที่ตัวเองปิด และหัวหน้าสายได้ส่วนแบ่งจากยอดลูกทีม',
    affects: 'แผนนี้ทำให้ขั้นที่ 4 มี "อัตราหัวหน้าทีม" ให้ตั้ง',
  },
  binary: {
    title: 'Binary — จ่ายจากยอดขาที่น้อยกว่า',
    how: 'ตัวแทนมีสายซ้าย/ขวา ระบบจับคู่ยอดสองขาแล้วจ่ายจากขาที่น้อยกว่าตามรอบที่ตั้งไว้',
    affects: 'แผนนี้ต้องตั้งอัตรา Matched และรอบคำนวณในขั้นนี้ก่อน ไม่งั้นไม่มีรอบไหนถูกประมวลผลเลย',
  },
  matrix: {
    title: 'Matrix — จำกัดความกว้างและความลึกของสาย',
    how: 'แต่ละคนมีลูกทีมได้ไม่เกินความกว้างที่กำหนด คนที่เกินจะไหลลง (spillover) และจ่ายตามชั้น',
    affects: 'แผนนี้ต้องตั้งความกว้าง/ความลึก และอัตราของแต่ละชั้นในขั้นนี้',
  },
  stairstep_breakaway: {
    title: 'อันดับ (Stairstep) — เลื่อนขั้นตามยอดสะสม',
    how: 'ตัวแทนเลื่อนอันดับเมื่อยอดถึงเกณฑ์ และได้อัตราของอันดับนั้น อันดับ Breakaway จะตัดออกจากสายบน',
    affects: 'แผนนี้ต้องตั้งบันไดอันดับในขั้นนี้ — ถ้าไม่มีอันดับเลย จะไม่มีใครเลื่อนขั้นได้',
  },
  generation: {
    title: 'Generation — จ่ายเป็นรุ่นลึกลงไป',
    how: 'นับรุ่น (generation) ลงไปจากคนที่ปิดการขาย และจ่ายตามอัตราของแต่ละรุ่น',
    affects: 'แผนนี้ต้องตั้งความลึกและอัตราของแต่ละรุ่นในขั้นนี้ — ความลึกอย่างเดียวไม่จ่ายใคร',
  },
  affiliate: {
    title: 'พันธมิตร (Affiliate) — จ่ายจากลิงก์แนะนำ',
    how: 'นับเครดิตให้ลิงก์ที่ถูกคลิกล่าสุดภายในช่วงเวลาที่กำหนด และจ่ายขึ้นไปชั้นเดียว',
    affects: 'แผนนี้ทำให้ขั้นที่ 4 มี "อัตราหัวหน้าทีม" ให้ตั้ง (จ่ายชั้นเดียว)',
  },
}

/**
 * Pick a plan to LOOK AT in step 2 — which is not the same as switching to it.
 *
 * Switching the company onto a plan is CompanyManagementView's job (and
 * CompanyPolicy::update, Super Admin only). All this does is reveal that
 * plan's structural form, which is exactly what the dimmed chips promise:
 * "กดดูรายละเอียดได้ แต่ยังไม่มีผลจนกว่าจะสลับมาใช้".
 */
function viewPlan(pt: CommissionPlanType): void {
  viewingPlanType.value = pt
  const tab = planTypeToTab[pt]
  // Unilevel has no structural tab by design (it is pure rate-rule-driven),
  // so there is nothing to load and nothing to render below the chips.
  if (tab) {
    activeTab.value = tab
    void ensureTabLoaded(tab)
  }
}

// ── Step 3: which layer a product's rate actually comes from ──
/** The company-wide default rows that are live today (step 3.1). */
const companyDefaultRules = computed<CommissionRuleItem[]>(() => {
  const now = new Date()

  return byCompany(commissionRules.value).filter((r) => !r.product && !r.product_category && isRuleActiveOn(r, now))
})

/** 'ตั้งเฉพาะสินค้านี้' / 'ใช้อัตราหมวดหมู่' / 'ใช้ค่าเริ่มต้นบริษัท' — the badge in 3.2. */
function rateLayerLabel(p: ProductOption): string {
  const r = resolveRuleFor(p)
  if (!r) return 'ยังไม่มีอัตรา'
  if (r.product) return 'ตั้งเฉพาะสินค้านี้'
  if (r.product_category) return 'ใช้อัตราหมวดหมู่'

  return 'ใช้ค่าเริ่มต้นบริษัท'
}

/**
 * The rate this product USED to have, when it has none now.
 *
 * An expired `effective_to` is the cruellest version of "no rate": the admin
 * set one, saw it work, and it stopped on a date nobody was reminded of. The
 * generic "ยังไม่มีอัตรา" makes that look like an omission and sends them to
 * create a duplicate; naming the end date sends them to the row that needs
 * one field changed.
 */
function expiredRuleFor(p: ProductOption): CommissionRuleItem | null {
  const now = new Date()
  const categoryId = p.category?.id
  const expired = byCompany(commissionRules.value)
    .filter((r) => !!r.effective_to && new Date(r.effective_to) < now)
    .filter((r) => r.product?.id === p.id || (!!categoryId && r.product_category?.id === categoryId) || (!r.product && !r.product_category))
    .sort((a, b) => (b.effective_to ?? '').localeCompare(a.effective_to ?? ''))

  return expired[0] ?? null
}

/**
 * ── Step 3.1: COPY another company's rates (2026-09-13) ──
 *
 * The owner's question, verbatim in substance: a company that has been live
 * for a year opens step 3 and sees its rates; a company created this morning
 * opens the same step and sees the red "ยังไม่มีค่าเริ่มต้นทั้งบริษัท" panel.
 * Why does the system not just put a default there?
 *
 * BECAUSE A GUESSED RATE AND A DECIDED RATE ARE THE SAME ROW (BR-7). The
 * moment a seeded 3% resolves against a closed deal it becomes a
 * commission_ledger entry, and a ledger entry cannot be corrected after the
 * fact (BR-4) — so a number nobody chose would be indistinguishable, both on
 * this screen and in the payout, from a number somebody argued about. The red
 * panel is not a gap in the product; it is the system refusing to invent the
 * one value on this screen that is money.
 *
 * What was actually wrong is that the only cure was typing every rate again
 * for a company whose sibling already has them. So the answer is COPYING,
 * which is a human deciding "the same as that company" — a decision, with a
 * source — and never a default. It is one click plus a confirmation, and the
 * confirmation is the point: the admin reads exactly which rows will be
 * created before any of them is.
 *
 * The preview and the write are the SAME endpoint and differ only by
 * `dry_run`, so what the admin approved and what the server does cannot drift
 * apart the way a client-side "what would happen" list would.
 */
interface CopyRateEntry {
  scope: 'company' | 'category' | 'product'
  label: string
  rate_type: RateType
  rate_value: number
  product_id: number | null
  product_category_id: number | null
  /** Thai, server-written, and only ever present on a skipped row. */
  reason?: string
}
interface CopyRatesResult {
  dry_run: boolean
  from_company: { id: number; name: string | null }
  to_company: { id: number; name: string | null }
  total_to_copy: number
  agent_rates: { copied: CopyRateEntry[]; skipped: CopyRateEntry[] }
  leader_rates: { copied: CopyRateEntry[]; skipped: CopyRateEntry[] }
}

const showCopyRatesModal = ref(false)
const copyRatesSourceId = ref<number | ''>('')
const copyRatesPreview = ref<CopyRatesResult | null>(null)
// Two flags, not one: the preview re-runs every time the source changes, and
// a single `busy` would leave the confirm button reading "กำลังคัดลอก..."
// while nothing is being copied yet.
const copyRatesPreviewing = ref(false)
const copyRatesCopying = ref(false)
const copyRatesError = ref('')

/**
 * Everything except the company being configured — copying onto itself is a
 * 422, and an option that can only fail is not an option.
 */
const copyRatesSourceOptions = computed(() => activeCompany.companies.filter((c) => c.id !== effectiveCompanyId.value))

/**
 * Agent and leader skips in ONE list. The admin's question about a skipped row
 * is "why is that rate not coming across", which the `reason` answers on its
 * own; which table it would have landed in adds nothing to that.
 */
const copyRatesSkipped = computed<CopyRateEntry[]>(() =>
  copyRatesPreview.value ? [...copyRatesPreview.value.agent_rates.skipped, ...copyRatesPreview.value.leader_rates.skipped] : [])

function openCopyRatesModal() {
  copyRatesSourceId.value = ''
  copyRatesPreview.value = null
  copyRatesError.value = ''
  showCopyRatesModal.value = true
}
function closeCopyRatesModal() {
  showCopyRatesModal.value = false
}

/**
 * Fired by picking a source, never by a button: a preview that has to be
 * asked for separately is a step an admin can skip, and the confirmation is
 * only worth having if it is already on screen when they reach the button.
 *
 * Plain `api.post`, NOT commissionApi — this writes nothing, and re-asking
 * the server whether commission is configured after a read is the request-per-
 * request cost commissionApi's docblock declines to pay for every other read
 * on this screen.
 */
async function previewCopyRates() {
  const from = copyRatesSourceId.value
  const to = effectiveCompanyId.value
  copyRatesPreview.value = null
  copyRatesError.value = ''
  if (from === '' || to === null) return
  copyRatesPreviewing.value = true
  try {
    const res = await api.post<{ data: CopyRatesResult }>('/commission-rules/copy', {
      from_company_id: from,
      to_company_id: to,
      dry_run: true,
    })
    copyRatesPreview.value = res.data
  } catch (e) {
    copyRatesError.value = apiErrorMessage(e, 'ดูตัวอย่างไม่สำเร็จ')
  } finally {
    copyRatesPreviewing.value = false
  }
}

/**
 * The same call with `dry_run: false`. Through `commissionApi` because this
 * one creates rate rows — the readiness banner is very likely still saying
 * "ไม่มีใครได้เงิน" at the top of this very screen, and it is about to be
 * wrong (see commissionApi's docblock).
 */
async function confirmCopyRates() {
  const from = copyRatesSourceId.value
  const to = effectiveCompanyId.value
  if (from === '' || to === null) return
  copyRatesCopying.value = true
  copyRatesError.value = ''
  try {
    await commissionApi.post('/commission-rules/copy', {
      from_company_id: from,
      to_company_id: to,
      dry_run: false,
    })
    // Reload before closing: the modal disappearing is the admin's signal that
    // the rows behind it are the new ones, so it must not outrun them.
    await loadRulesTabData()
    closeCopyRatesModal()
  } catch (e) {
    copyRatesError.value = apiErrorMessage(e, 'คัดลอกไม่สำเร็จ')
  } finally {
    copyRatesCopying.value = false
  }
}

// ── Step navigation ──
function stepLabel(step: Step): string {
  return stepDefs.find((s) => s.step === step)?.label ?? ''
}
const prevStep = computed<Step | null>(() => (activeStep.value > 1 ? ((activeStep.value - 1) as Step) : null))
const nextStep = computed<Step | null>(() => (activeStep.value < 4 ? ((activeStep.value + 1) as Step) : null))
/**
 * Why the next button is refusing, or '' when it is not.
 *
 * `prevStep` gets no equivalent and never will: going BACK to change an
 * answer is the thing the gate must never interfere with. A step behind the
 * current one is complete (or the current one would not have been reachable),
 * so it is always open — but the asymmetry is stated here rather than left to
 * be rediscovered, because "the buttons should match" is a tempting tidy-up.
 *
 * The button keeps its name — "ขั้นที่ 3 ตั้งอัตราตัวแทนผู้ขาย →" — while
 * disabled. Swapping the label for the refusal would delete the one thing
 * TASK-034 added to answer "ผู้ใช้ไม่รู้ว่าต้องกรอกอะไรหลัง"; the refusal is
 * printed BESIDE it instead, where it adds a reason without removing the
 * answer.
 */
const nextStepBlockedReason = computed<string>(() =>
  nextStep.value === null ? '' : stepLockHint(nextStep.value))

/**
 * Step 1's own company picker.
 *
 * Writes through activeCompany.requestCompany() — the same action the header
 * switcher uses — rather than setting `selectedId`, so the unsaved-work guard
 * (ADR-038's 2026-09-04 addition) still gets its say and the header stays in
 * step. A second write path here would be a second place for the two to
 * disagree about which company is on screen, which is the exact bug ADR-038
 * was created to end.
 */
function pickCompany(event: Event): void {
  const value = (event.target as HTMLSelectElement).value

  void activeCompany.requestCompany(value === '' ? null : Number(value))
  // 2026-09-12 — the picker closes itself once it has an answer. Leaving it
  // open would make "which company am I configuring" a question with two
  // controls showing at once, which is the state this change removes.
  editingCompany.value = false
}

/**
 * 2026-09-12 (owner): the company is READ by default and changed on purpose.
 *
 * Step 1 used to carry a bare <select> pinned to the far right of the card,
 * as far from the company name as the row allowed — so the page's single most
 * load-bearing fact ("everything in steps 2–4 belongs to THIS company") sat at
 * one end and the control that changes it at the other. Worse, a select is
 * always armed: a stray scroll or an arrow key on a focused control silently
 * re-points every step behind it.
 *
 * Now the name is a name, with "เปลี่ยนบริษัท" beside it; the picker appears
 * under the name, where the value it changes is, and only after somebody asked
 * for it.
 */
const editingCompany = ref(false)

/**
 * A Super Admin on "ทุกบริษัท" has nothing to read, so there is nothing to put
 * behind a button — the picker is the content of the card, open from the
 * start. Without this clause the first thing they would see is
 * "ยังไม่ได้เลือกบริษัท" and a button they have to find.
 */
const showCompanyPicker = computed(() => isSuperAdmin.value && (editingCompany.value || activeCompany.requiresCompanyPick))

/**
 * Moving between steps also arranges for the panel's data to exist.
 *
 * Step 3 and step 4 both read the rules payload (agent rates, leader rates,
 * products, categories and the readiness probe all arrive together in
 * loadRulesTabData), and step 2 reads whichever structural tab the viewed
 * plan maps to. Routing every move through here is what stops a step from
 * rendering an empty list it never asked the server about.
 */
function goToStep(step: Step): void {
  /*
   * THE ENFORCEMENT, in one place (2026-09-12).
   *
   * Every way into a step goes through here — the tabs, the footer's two
   * buttons, the banner's jump, the product rows' two "ไปขั้นที่ …" links —
   * so the gate is one line rather than a condition repeated at six call
   * sites, where the seventh caller added later would be the one that forgets.
   * The template still hides or disables the controls that would land here
   * illegally; this is the backstop that makes that cosmetic rather than
   * load-bearing.
   *
   * Returning silently is safe ONLY because nothing reaches this point without
   * a visible reason already on screen: a locked tab carries its padlock and
   * its "ทำขั้นที่ N … ให้เสร็จก่อน" line, and the next button carries the
   * same sentence beside it.
   */
  if (!stepReachable.value[step]) return

  activeStep.value = step
  if (step === 2) viewPlan(viewingPlanType.value)
  else if (step === 3 || step === 4) {
    activeTab.value = 'rules'
    void ensureTabLoaded('rules')
  }
}

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
const simulateResult = computed<{ rule: CommissionRuleItem | null; amountSatang: number; baseSatang: number; basis: CommissionBasis } | null>(() => {
  if (!simulateProduct.value) return null
  const rule = resolveRuleFor(simulateProduct.value)
  const saleSatang = Math.round(Number(simulateAmountThb.value || 0) * 100)
  /*
   * 2026-09-12 — THE SIMULATOR HAS TO MIRROR CommissionBasisResolver, OR IT
   * LIES ABOUT THE ONE THING IT EXISTS TO ANSWER.
   *
   * On a PV company the entered sale amount is not what the rate is applied
   * to; the product's PV is, and a discount does not move it. A simulator
   * that kept multiplying the typed figure would show an admin a number the
   * server will never produce — worse than having no simulator, because they
   * would go on to set the rate from it.
   *
   * The missing-PV fallback to the sale price is mirrored too, and the
   * readout below names which base was used, so a product that silently
   * falls back is visible HERE as well as in the step-2 list.
   */
  const pv = simulateProduct.value.pv_satang
  const baseSatang = commissionBasis.value === 'pv' && pv !== null && pv !== undefined ? pv : saleSatang

  if (!rule) return { rule: null, amountSatang: 0, baseSatang, basis: commissionBasis.value }

  const amountSatang = rule.rate_type === 'percentage' ? Math.round((baseSatang * rule.rate_value) / 10000) : rule.rate_value

  return { rule, amountSatang, baseSatang, basis: commissionBasis.value }
})

// ══════════════════════════ Setup Wizard — REMOVED 2026-09-12 ══════════════════════════
// TASK-037's per-product "set this product up step by step" modal used to live
// here (product → plan type → rate → company structure → summary). It was
// deleted by human decision once this whole screen became a step flow: the
// wizard asked the same four questions the four steps now ask, and answered
// them through a SECOND set of writes to the same endpoints — two code paths
// onto one set of money numbers, only one of which anybody was still
// maintaining. Its first step was also the last place that wrote
// `commission_plan_type` onto a product, which is no longer a per-product
// decision at all (see the note where canEditPlanType() used to be).
//
// Anything it could do is reachable on the path: the plan type in step 2, the
// per-product rate via "+ ตั้งอัตราคอมมิชชั่น" on the row in step 3, and the
// company-wide structural settings in step 2's plan chip. Do not re-add it.

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
    const res = await commissionApi.put<{ data: BinarySettings }>(`/commission-binary-settings${companyQuery()}`, payload)
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
    const res = await commissionApi.put<{ data: MatrixSettings }>(`/commission-matrix-settings${companyQuery()}`, payload)
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
    await commissionApi.post(
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
    await commissionApi.delete(`/commission-matrix-level-rates/${item.id}`)
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
    const res = await commissionApi.put<{ data: AgentRankSettingsData }>(
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
      await commissionApi.put(`/agent-ranks/${editingRankId.value}`, payload)
    } else {
      await commissionApi.post('/agent-ranks', payload)
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
    await commissionApi.delete(`/agent-ranks/${r.id}`)
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
    const res = await commissionApi.put<{ data: GenerationSettingsData }>(
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
    await commissionApi.post(
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
    await commissionApi.delete(`/commission-generation-rules/${item.id}`)
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
    const res = await commissionApi.put<{ data: AffiliateAttributionSettingsData }>(
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
/*
 * The resolution-order modal is NO LONGER auto-opened here — see the
 * RESOLUTION_ORDER_NOTE block for why the nag went and what replaced it.
 */
watch(activeTab, (tab) => {
  void ensureTabLoaded(tab)
})
watch(() => activeCompany.companyId, () => {
  // Company changed — from the header switcher OR from step 1's own picker,
  // which goes through the same store action, so this keeps working without
  // a second code path. Every tab's cached data is now stale, force a reload
  // next time each is viewed.
  loadedTabs.value.clear()
  // The banner's verdict is per company too — the store caches by company id,
  // so switching back to one already seen this session costs no request.
  void commissionReadiness.ensureLoaded()
  // 2026-09-12 — the basis belongs to the COMPANY, so it is the one value on
  // this screen that is guaranteed wrong the instant the switcher moves. Any
  // half-typed PV belongs to the company that just left, too.
  pvDrafts.value = {}
  pvError.value = ''
  void loadCompanySettings()
  if (activeTab.value !== 'rules') void ensureTabLoaded(activeTab.value)
  // The rules tab is deliberately NOT refetched: it loads every company's
  // rows once and narrows them with byCompany(). The readiness probe is
  // the exception — it asks per-company endpoints (companyQuery()), so
  // leaving it alone would keep showing the previous company's verdict on
  // this company's products, which is worse than showing nothing.
  else void loadReadinessProbe()
})
/*
 * ONE place sets the opening state, because the three pieces of it have to
 * agree and used to be set in three places that could not see each other:
 * `activeTab` defaulted to 'rules', `viewMode` defaulted to 'overview' (a
 * combination no `v-if` matched), and onMounted hard-coded loadTab('rules')
 * regardless of either.
 *
 * The screen opens on STEP 1 every time rather than jumping to
 * `blockingStep`. Landing somewhere different on each visit is exactly the
 * disorientation the redesign exists to remove — and the banner above the
 * tabs already carries a one-click jump for the admin who only came to fix
 * the blockage.
 */
onMounted(async () => {
  activeStep.value = 1
  activeTab.value = 'rules'
  await activeCompany.loadCompanies()
  // Before the tab data, and awaited: the banner is the first thing above the
  // steps, and an admin who opened this screen because of the warning should
  // not watch it appear after the rate table has already rendered.
  await commissionReadiness.ensureLoaded()
  await loadCompanySettings()
  await ensureTabLoaded('rules')
})
/*
 * Step 2 opens on the plan the company is ACTUALLY on, not on the first chip
 * — and re-points when the company changes underneath it. Watching the
 * derived value rather than setting it once after load covers both moments
 * with one rule; `companyPlanType` is only knowable after products arrive,
 * so there is no earlier point at which this could be assigned.
 */
watch(companyPlanType, (pt) => {
  if (pt) viewingPlanType.value = pt
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <!-- storage-key unchanged: an admin who collapsed this header keeps it
         collapsed across the redesign. The #tabs slot is gone because the
         step bar is no longer a filter on one page — it IS the page, and it
         has to stay visible when the header is collapsed. -->
    <HeroHeader
      icon="money"
      title="ตั้งค่าคอมมิชชั่น"
      :subtitle="`กดทีละขั้น 1 → 4 · ตอนนี้อยู่ขั้นที่ ${activeStep} จาก 4`"
      description="Unilevel/Binary/Matrix/Stairstep-Breakaway/Generation/Affiliate (ADR-011) — ตั้งครบทั้ง 4 ขั้นแล้วระบบถึงจะจ่ายค่าคอมได้"
      accent-color="brand"
      storage-key="commission-plans"
    />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <!--
      THE READINESS BANNER, PROMOTED ABOVE THE STEPS (2026-09-11).

      TASK-213 Phase 1 put this on the ภาพรวม tab, where it could only be
      seen by an admin who happened to be on that tab — which is nobody in
      the middle of configuring something. Thirteen code paths can leave a
      closed deal paying nobody, every one of them silently and by design
      (the sale must never be blocked by a config gap), so the one thing
      this screen must never do is let somebody leave it believing they are
      finished. It now sits outside the step card, on every step, and names
      the step that is blocking.
    -->
    <div
      class="mt-4 flex items-start gap-3.5 rounded-2xl border px-4 py-4"
      :class="readinessLevel === 'ok' ? 'bg-emerald-50 border-emerald-200' : readinessLevel === 'warn' ? 'bg-amber-50 border-amber-200' : 'bg-rose-50 border-rose-200'"
      data-test="readiness-banner"
    >
      <Icon
        :name="readinessLevel === 'ok' ? 'check_circle' : 'alert'"
        :size="21"
        class="shrink-0 mt-0.5"
        :class="readinessLevel === 'ok' ? 'text-emerald-600' : readinessLevel === 'warn' ? 'text-amber-700' : 'text-rose-700'"
      />
      <div class="flex-1 min-w-0">
        <p
          class="text-[15px] font-extrabold"
          :class="readinessLevel === 'ok' ? 'text-emerald-800' : readinessLevel === 'warn' ? 'text-amber-800' : 'text-rose-800'"
          data-test="readiness-headline"
        >
          {{ readinessHeadline }}
        </p>
        <p
          class="mt-0.5 text-[13px]"
          :class="readinessLevel === 'ok' ? 'text-emerald-700' : readinessLevel === 'warn' ? 'text-amber-700' : 'text-rose-700'"
          data-test="readiness-detail"
        >
          {{ readinessDetail }}
        </p>
      </div>
      <!-- Targets `jumpStep`, not `blockingStep` — see that computed for why
           the two can differ and why this one can never land on a locked
           tab. The label follows the target rather than the verdict: a button
           that says "ไปที่ขั้นที่ 4" and opens step 3 is worse than either. -->
      <button
        v-if="jumpStep"
        type="button"
        class="btn-primary shrink-0 h-9"
        :class="readinessLevel === 'warn' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-rose-600 hover:bg-rose-700'"
        data-test="readiness-jump"
        @click="goToStep(jumpStep!)"
      >
        ไปที่ขั้นที่ {{ jumpStep }}
      </button>
    </div>

    <div class="mt-4 rounded-2xl border border-slate-200 bg-white/95 overflow-hidden">
      <!--
        The four step tabs, GATED (2026-09-12) — owner: "ยังคลิ๊กเลือกได้ทุก
        tab เลย ตามที่คุยไว้ต้องทำทีละขั้นตอน". A step opens only once every
        step before it is finished; see `stepReachable`, which also carries the
        read-only exception (a Company Admin cannot complete a step, so they
        are never gated by completion).

        A locked tab is `aria-disabled`, NOT natively `disabled`, on purpose.
        The native attribute would suppress the tab's own `title` tooltip and
        drop it out of the keyboard order — so the one control on this screen
        that most needs to explain itself would become the one that cannot be
        reached to read. It stays focusable and clickable, and goToStep()
        refuses the move, while the padlock, the muted colours and the
        "ทำขั้นที่ N … ให้เสร็จก่อน" line under the name say why.
      -->
      <div class="flex flex-col sm:flex-row bg-slate-50 border-b border-slate-200" role="tablist">
        <button
          v-for="s in stepDefs"
          :key="s.step"
          type="button"
          role="tab"
          :aria-selected="activeStep === s.step"
          :aria-disabled="!stepReachable[s.step]"
          :title="stepLockHint(s.step) || undefined"
          class="flex-1 flex items-center gap-2.5 px-4 py-3.5 text-left border-b-[3px] transition-colors"
          :class="[
            activeStep === s.step ? 'bg-white border-brand-600' : 'border-transparent',
            stepReachable[s.step] ? (activeStep === s.step ? '' : 'hover:bg-slate-100') : 'cursor-not-allowed bg-slate-100/70',
          ]"
          :data-test="`step-tab-${s.step}`"
          @click="goToStep(s.step)"
        >
          <span
            class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[13px] font-extrabold"
            :class="!stepReachable[s.step] ? 'bg-slate-200 text-slate-400' : stepStatuses[s.step] === 'done' ? 'bg-brand-600 text-white' : activeStep === s.step ? 'bg-gold-600 text-white' : 'bg-slate-200 text-slate-500'"
          >
            <!-- The padlock outranks the tick and the number: a locked step's
                 first question is "why can I not click this", not "which
                 number is it". A locked step can never be 'done' anyway. -->
            <Icon v-if="!stepReachable[s.step]" name="lock" :size="14" :data-test="`step-lock-icon-${s.step}`" />
            <Icon v-else-if="stepStatuses[s.step] === 'done'" name="check" :size="14" />
            <template v-else>{{ s.step }}</template>
          </span>
          <span class="flex-1 min-w-0">
            <span class="block text-[11px] font-bold" :class="stepReachable[s.step] ? 'text-slate-400' : 'text-slate-300'">ขั้นที่ {{ s.step }}</span>
            <span
              class="block text-[13.5px] font-extrabold"
              :class="!stepReachable[s.step] ? 'text-slate-400' : activeStep === s.step ? 'text-slate-900' : 'text-slate-600'"
            >{{ s.label }}</span>
            <!-- The reason, in the tab itself and not only in the tooltip: a
                 `title` needs a hover that a touch screen does not have, and
                 this is the sentence that turns a refusal into an instruction. -->
            <span
              v-if="!stepReachable[s.step]"
              class="block mt-0.5 text-[11px] font-bold text-amber-700"
              :data-test="`step-lock-hint-${s.step}`"
            >
              🔒 {{ stepLockHint(s.step) }}
            </span>
          </span>
          <span
            class="shrink-0 text-[11px] font-bold rounded-full px-2.5 py-1"
            :class="!stepReachable[s.step] ? 'bg-slate-200 text-slate-400' : stepStatuses[s.step] === 'done' ? 'bg-emerald-100 text-emerald-700' : stepStatuses[s.step] === 'incomplete' ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-500'"
            :data-test="`step-pill-${s.step}`"
          >
            {{ stepStatusLabels[stepStatuses[s.step]] }}
          </span>
        </button>
      </div>

      <div class="p-5 sm:p-6">
        <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" />

        <template v-else>
          <!-- ═══════════ ขั้นที่ 1 · เลือกบริษัท ═══════════ -->
          <section v-if="activeStep === 1" class="space-y-4" data-test="step-panel-1">
            <div>
              <p class="text-[17px] font-extrabold text-slate-900">ตั้งค่าคอมมิชชั่นของบริษัทไหน</p>
              <p class="mt-1 text-[13px] text-slate-500">ทุกอย่างในขั้นที่ 2–4 เป็นของบริษัทที่เลือกไว้ตรงนี้เท่านั้น</p>
            </div>

            <!--
              The company used to be reachable ONLY from the header switcher
              (TASK-208 / ADR-038), and the screen's response to "no company
              picked" was an EmptyState telling the admin to go look up
              there. That is a correct instruction and a bad first step: the
              answer to "which company" belongs on the step that asks it.
              The picker still writes through the SAME store action, so the
              header stays in sync and the company-change watcher below
              keeps working unchanged.
            -->
            <div class="rounded-xl border border-slate-200 p-4">
              <p class="text-[11px] font-bold text-slate-400">บริษัทที่กำลังตั้งค่า</p>

              <!-- READING. The name, and one button that says what it does. -->
              <div v-if="!showCompanyPicker" class="mt-0.5 flex flex-wrap items-center gap-3">
                <p class="text-lg font-extrabold text-slate-900" data-test="step1-company-name">
                  {{ activeCompany.companyName ?? 'ยังไม่ได้เลือกบริษัท' }}
                </p>
                <button
                  v-if="isSuperAdmin"
                  type="button"
                  class="btn-secondary h-8 inline-flex items-center gap-1.5"
                  data-test="step1-company-edit"
                  @click="editingCompany = true"
                >
                  <Icon name="edit" :size="13" /> เปลี่ยนบริษัท
                </button>
              </div>

              <!-- CHANGING. Directly UNDER the name it replaces, not across
                   the card from it: the control and the value it changes are
                   the same fact, and an admin re-pointing four steps of
                   configuration should be looking at one thing. -->
              <div v-else class="mt-1.5 max-w-sm">
                <label class="text-[12.5px] font-bold text-slate-500" for="step1-company">เลือกบริษัทที่จะตั้งค่า</label>
                <div class="mt-1 flex items-center gap-2">
                  <select
                    id="step1-company"
                    class="flex-1 px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white"
                    :value="activeCompany.companyId ?? ''"
                    data-test="step1-company-select"
                    @change="pickCompany"
                  >
                    <option value="" disabled>เลือกบริษัท</option>
                    <option v-for="c in activeCompany.companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <!-- No cancel while nothing is picked: there is no previous
                       answer to go back to, and a button that closes onto
                       "ยังไม่ได้เลือกบริษัท" only hides the one thing left to do. -->
                  <button
                    v-if="!activeCompany.requiresCompanyPick"
                    type="button"
                    class="text-[12.5px] font-bold text-slate-500 hover:text-slate-700 shrink-0"
                    data-test="step1-company-cancel"
                    @click="editingCompany = false"
                  >
                    ยกเลิก
                  </button>
                </div>
                <p class="mt-1.5 text-[12px] text-slate-400">เปลี่ยนแล้วขั้นที่ 2–4 จะโหลดข้อมูลของบริษัทใหม่ทันที</p>
              </div>

              <p v-if="!isSuperAdmin" class="mt-1 text-[12.5px] text-slate-400">บริษัทของคุณถูกกำหนดจากบัญชีผู้ใช้ เปลี่ยนที่นี่ไม่ได้</p>
            </div>

            <!--
              2026-09-12 (owner, logged in as Super Admin): "ไม่ต้องขึ้นคำเตือนนี้".
              This used to render for everyone, on the argument that a Super
              Admin needs to know what the person they are configuring for will
              be able to do. That argument was wrong in the way permission
              notices usually are: it is addressed in the second person to
              somebody it does not describe. A Super Admin reading "แก้ไขได้
              เฉพาะ Super Admin" on a screen they are actively editing learns
              nothing and, for a moment, wonders whether something is blocked.

              It now appears only for the reader it is ABOUT, and says so in
              the second person. Not deleted outright: a Company Admin who is
              simply shown a screen with no buttons has no way to tell
              "read-only by design" from "broken".
            -->
            <div
              v-if="!canEditCommissionConfig"
              class="flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"
              data-test="commission-lock-note"
            >
              <Icon name="eye" :size="16" class="shrink-0 mt-0.5 text-slate-400" />
              <p class="text-[12.5px] text-slate-500">คุณเปิดดูได้ทุกขั้นตอนแต่แก้ไขไม่ได้ — การตั้งค่าคอมมิชชั่นแก้ไขได้เฉพาะ Super Admin · ติดต่อผู้ดูแลระบบหากต้องการเปลี่ยน</p>
            </div>

            <div v-if="effectiveCompanyId" class="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <div class="px-3 py-2.5 rounded-lg bg-slate-50 border border-slate-100">
                <p class="text-[11px] font-bold text-slate-400">สินค้าที่ต้องมีอัตรา</p>
                <p class="text-sm font-extrabold text-slate-900">{{ byCompany(products).length }} รายการ</p>
              </div>
              <div class="px-3 py-2.5 rounded-lg bg-slate-50 border border-slate-100">
                <p class="text-[11px] font-bold text-slate-400">แผนคอมมิชชั่นของบริษัท</p>
                <p class="text-sm font-extrabold text-slate-900">{{ companyPlanType ? planTypeLabels[companyPlanType] : 'ยังไม่ทราบ' }}</p>
              </div>
              <!-- TASK-213 Phase 1's per-product verdict, kept as a breakdown.
                   The banner above reduces it to one blocking step, which is
                   the right thing for a call to action and the wrong thing for
                   "how much work is left" — 1 bad out of 40 and 40 out of 40
                   produce the same banner. -->
              <div class="px-3 py-2.5 rounded-lg bg-slate-50 border border-slate-100">
                <p class="text-[11px] font-bold text-slate-400">ความพร้อมจ่ายรายสินค้า</p>
                <p class="text-sm font-extrabold text-slate-900" data-test="step1-readiness-counts">
                  <span class="text-emerald-700">พร้อม {{ readinessCounts.ok }}</span>
                  <span v-if="readinessCounts.warn" class="text-amber-700"> · ควรตรวจ {{ readinessCounts.warn }}</span>
                  <span v-if="readinessCounts.bad" class="text-rose-700"> · ต้องแก้ {{ readinessCounts.bad }}</span>
                </p>
              </div>
            </div>
          </section>

          <!-- ═══════════ ขั้นที่ 2 · เลือกแผนคอมมิชชั่น ═══════════ -->
          <section v-else-if="activeStep === 2" class="space-y-4" data-test="step-panel-2">
            <div>
              <p class="text-[17px] font-extrabold text-slate-900">บริษัทนี้ใช้แผนคอมมิชชั่นแบบไหน</p>
              <p class="mt-1 text-[13px] text-slate-500">เลือกได้แผนเดียว — แผนที่เลือกจะเป็นตัวกำหนดว่าขั้นที่ 3 และ 4 มีอะไรให้ตั้งบ้าง</p>
            </div>

            <EmptyState
              v-if="activeCompany.requiresCompanyPick"
              icon="building"
              title="กรุณาเลือกบริษัทก่อน"
              message="กลับไปที่ขั้นที่ 1 แล้วเลือกบริษัท เพื่อดูและตั้งค่าแผนคอมมิชชั่น"
            />
            <template v-else>
              <!-- px-9 (36px side padding), owner 2026-09-12. The six labels
                   run from "Binary" to "พันธมิตร (Affiliate)", and at the old
                   18px the long ones read as a cramped block of text rather
                   than a pill. Vertical padding is unchanged — the complaint
                   was about the sides — and the row still wraps, so wider
                   chips cost layout nothing. -->
              <div class="flex flex-wrap gap-2.5">
                <button
                  v-for="pt in planChipOrder"
                  :key="pt"
                  type="button"
                  class="inline-flex items-center gap-2 text-[13.5px] rounded-full px-9 py-2.5 border transition-colors"
                  :class="pt === companyPlanType
                    ? 'font-extrabold text-white bg-brand-600 border-brand-600'
                    : pt === viewingPlanType
                      ? 'font-bold text-slate-700 bg-white border-slate-300'
                      : 'font-semibold text-slate-400 bg-slate-50 border border-dashed border-slate-200 hover:text-slate-600'"
                  :data-test="`plan-chip-${pt}`"
                  @click="viewPlan(pt)"
                >
                  <Icon v-if="pt === companyPlanType" name="check" :size="14" />
                  {{ planTypeLabels[pt] }}
                  <span
                    v-if="productPlanTypeCounts[pt]"
                    class="text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                    :class="pt === companyPlanType ? 'bg-white/25 text-white' : 'bg-slate-200 text-slate-600'"
                  >
                    {{ productPlanTypeCounts[pt] }}
                  </span>
                </button>
              </div>
              <p class="text-[12.5px] text-slate-500">แผนที่จางคือแผนที่บริษัทนี้ไม่ได้ใช้ — กดดูรายละเอียดได้ แต่ยังไม่มีผลจนกว่าจะสลับมาใช้</p>

              <div class="flex flex-wrap items-start gap-3.5 rounded-2xl border border-brand-200 bg-brand-50 px-4 py-4" data-test="plan-explainer">
                <div class="flex-1 min-w-0">
                  <p class="text-[15px] font-extrabold text-brand-700">{{ planExplainers[viewingPlanType].title }}</p>
                  <p class="mt-1 text-[13px] text-slate-500">{{ planExplainers[viewingPlanType].how }}</p>
                  <p class="mt-2 text-[12.5px] font-bold text-brand-600">{{ planExplainers[viewingPlanType].affects }}</p>
                </div>
                <!--
                  2026-09-12 (owner): "ผมกดเปลี่ยนแผน แล้วเด้งไปรูปที่ 1 ทำให้
                  UI สับสน".

                  This was a RouterLink to /companies, because switching a
                  company onto a plan was CompanyPolicy::update and this screen
                  could not perform it. That is a dead end dressed as a button:
                  step 2's entire job is "which plan does this company run",
                  and pressing its only action threw the admin onto a different
                  screen to finish.

                  The write moved to PUT /commission-settings
                  (Ability::SettingsCommissionPlanUpdate), so the link is now
                  the switch. It appears only on a plan the company is NOT on —
                  on the current plan there is nothing to apply, and a button
                  that re-saves the status quo is just a way to be unsure
                  whether you pressed it.
                -->
                <button
                  v-if="canEditCommissionConfig && viewingPlanType !== companyPlanType"
                  type="button"
                  class="btn-primary shrink-0 h-9"
                  :disabled="planSwitching"
                  data-test="use-this-plan"
                  @click="useViewedPlan"
                >
                  {{ planSwitching ? 'กำลังเปลี่ยน…' : `ใช้แผน ${planTypeLabels[viewingPlanType]}` }}
                </button>
                <span
                  v-else-if="viewingPlanType === companyPlanType"
                  class="shrink-0 text-[12.5px] font-extrabold text-brand-600"
                  data-test="plan-in-use"
                >
                  แผนที่ใช้อยู่
                </span>
              </div>

              <p v-if="planSwitchError" class="text-[12.5px] font-bold text-rose-600" data-test="plan-switch-error">{{ planSwitchError }}</p>

              <div class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5">
                <Icon name="alert" :size="15" class="shrink-0 mt-0.5 text-amber-700" />
                <span class="text-[12.5px] font-bold text-amber-800">
                  การสลับแผนมีผลกับการขายครั้งถัดไปเท่านั้น — ค่าคอมที่ลงบัญชีไปแล้วไม่เปลี่ยนตาม
                </span>
              </div>

              <!--
                ═══ ฐานการคำนวณ / PV (2026-09-12) ═══

                The second half of "how does this company pay", and it sits
                directly under the plan chips because it is the same question:
                the plan says WHO is paid, this says what the % is a % OF.
                Splitting them across two screens is what the 4-step redesign
                exists to undo.

                Both cards state the consequence in the sentence an admin
                actually cares about — what happens when you discount —
                because that is the only difference they will ever notice,
                and the reason a company picks one over the other.
              -->
              <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4" data-test="basis-card">
                <p class="text-[15px] font-extrabold text-slate-900">ค่าคอมคิดจากอะไร</p>
                <p class="mt-1 text-[12.5px] text-slate-500">
                  เลือกได้อย่างเดียวทั้งบริษัท — ส่วน % และจำนวนคงที่ยังตั้งได้รายสินค้าเหมือนเดิมในขั้นที่ 3
                </p>

                <!--
                  2026-09-12 — WHEN THE READ FAILED, THE SCREEN SAYS SO.
                  Rendering the fallback as the selected option would state a
                  wrong answer about somebody's money in exactly the same
                  typeface as a right one; there is no way for a reader to
                  tell the two apart, which is what makes it worse than an
                  error. Neither option is marked, and the switch is locked
                  until a real value arrives.
                -->
                <div v-if="basisUnknown" class="mt-3 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-3" data-test="basis-unknown">
                  <Icon name="alert" :size="16" class="shrink-0 mt-0.5 text-rose-600" />
                  <div class="min-w-0">
                    <p class="text-[13px] font-extrabold text-rose-700">อ่านค่าฐานการคำนวณไม่สำเร็จ</p>
                    <p class="mt-0.5 text-[12.5px] text-rose-700">ระบบยังไม่ทราบว่าบริษัทนี้คิดค่าคอมจากราคาขายหรือ PV จึงยังไม่แสดงค่าที่เลือกไว้ — โหลดหน้านี้ใหม่อีกครั้ง หากยังไม่หายให้แจ้งผู้ดูแลระบบ</p>
                  </div>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                  <button
                    v-for="b in (['price', 'pv'] as CommissionBasis[])"
                    :key="b"
                    type="button"
                    class="text-left rounded-xl border px-4 py-3.5 transition-colors"
                    :class="!basisUnknown && b === commissionBasis
                      ? 'border-brand-600 bg-brand-50'
                      : canEditCommissionConfig && !basisUnknown
                        ? 'border-slate-200 bg-white hover:border-slate-300'
                        : 'border-slate-200 bg-slate-50 cursor-default'"
                    :disabled="!canEditCommissionConfig || basisSaving || basisUnknown"
                    :data-test="`basis-option-${b}`"
                    @click="setCommissionBasis(b)"
                  >
                    <span class="flex items-center gap-2">
                      <Icon v-if="!basisUnknown && b === commissionBasis" name="check" :size="14" class="text-brand-600" />
                      <span class="text-[14px] font-extrabold" :class="b === commissionBasis ? 'text-brand-700' : 'text-slate-700'">
                        {{ basisLabels[b] }}
                      </span>
                      <span v-if="b === 'price'" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-slate-200 text-slate-600">ค่าเริ่มต้น</span>
                    </span>
                    <span class="mt-1.5 block text-[12.5px] text-slate-500">
                      <template v-if="b === 'price'">% คิดจากยอดที่ลูกค้าจ่ายจริง — ลดราคาเมื่อไร ค่าคอมลดตาม</template>
                      <template v-else>% คิดจาก PV ที่กำหนดไว้ให้สินค้าแต่ละตัว — ลดราคาแล้วค่าคอมไม่ลดตาม</template>
                    </span>
                  </button>
                </div>

                <p v-if="basisError" class="mt-2 text-[12.5px] font-bold text-rose-600">{{ basisError }}</p>
                <p v-if="!canEditCommissionConfig" class="mt-2 text-[12.5px] text-slate-400">
                  เปลี่ยนได้เฉพาะผู้ดูแลระบบ — ติดต่อผู้ดูแลระบบหากต้องการแก้ไข
                </p>
                <p class="mt-2 text-[12.5px] text-slate-400">
                  การเปลี่ยนฐานมีผลกับการขายครั้งถัดไปเท่านั้น — ค่าคอมที่ลงบัญชีไปแล้วไม่เปลี่ยนตาม
                </p>
              </div>

              <!--
                ═══ THE PV TABLE ═══

                Only rendered on the PV basis, and that is the whole design:
                a company on ราคาขาย never meets PV at all, so nothing about
                this feature is opt-out.

                Every sellable product is listed, not just the ones missing a
                PV. A list of gaps shrinks to nothing and then vanishes, which
                is exactly when an admin needs to see what the numbers ARE —
                and comparing PV against price across the whole catalogue is
                how anybody notices they typed 100 where they meant 1,000.
              -->
              <div v-if="commissionBasis === 'pv'" class="rounded-2xl border border-slate-200 bg-white px-4 py-4" data-test="pv-table">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p class="text-[15px] font-extrabold text-slate-900">PV ของแต่ละสินค้า</p>
                    <p class="mt-1 text-[12.5px] text-slate-500">
                      PV คือ "มูลค่าที่ใช้คิดค่าคอม" ของสินค้า ตั้งเป็นบาทเหมือนราคา เช่น ราคา 8,900 แต่ให้ PV 1,000
                    </p>
                  </div>
                  <span
                    v-if="productsMissingPointValue.length"
                    class="shrink-0 text-[12px] font-extrabold px-2.5 py-1 rounded-full bg-amber-100 text-amber-800"
                    data-test="pv-missing-count"
                  >
                    ยังไม่ได้กำหนด {{ productsMissingPointValue.length }} รายการ
                  </span>
                </div>

                <p v-if="pvError" class="mt-2 text-[12.5px] font-bold text-rose-600">{{ pvError }}</p>

                <div class="mt-3 divide-y divide-slate-100">
                  <div
                    v-for="p in byCompany(products)"
                    :key="p.id"
                    class="flex flex-wrap items-center gap-3 py-2.5"
                    :data-test="`pv-row-${p.id}`"
                  >
                    <div class="flex-1 min-w-0">
                      <p class="text-[13.5px] font-bold text-slate-800 truncate">{{ p.name }}</p>
                      <p class="text-[12px] text-slate-400">
                        ราคาขาย {{ p.price_satang === undefined ? '—' : formatSatang(p.price_satang) }}
                        <span
                          v-if="p.pv_satang === null || p.pv_satang === undefined"
                          class="font-bold text-amber-700"
                        >· ยังไม่ได้กำหนด PV ระบบจะคิดจากราคาขายไปก่อน</span>
                      </p>
                    </div>

                    <template v-if="canEditCommissionConfig">
                      <input
                        :value="pvDraftFor(p)"
                        type="number"
                        min="0"
                        step="0.01"
                        placeholder="PV"
                        class="w-28 px-3 py-1.5 rounded-lg border text-sm"
                        :class="p.pv_satang === null || p.pv_satang === undefined ? 'border-amber-300 bg-amber-50' : 'border-slate-200'"
                        :data-test="`pv-input-${p.id}`"
                        @input="pvDrafts[p.id] = ($event.target as HTMLInputElement).value"
                        @keyup.enter="savePointValue(p)"
                      />
                      <button
                        type="button"
                        class="btn-secondary h-9"
                        :disabled="pvSavingId === p.id"
                        :data-test="`pv-save-${p.id}`"
                        @click="savePointValue(p)"
                      >
                        {{ pvSavingId === p.id ? 'กำลังบันทึก…' : 'บันทึก' }}
                      </button>
                    </template>
                    <!-- Read-only viewer: the number, never the input. Same
                         house rule as every other write control here. -->
                    <span v-else class="text-[13.5px] font-bold text-slate-700">
                      {{ p.pv_satang === null || p.pv_satang === undefined ? '—' : formatSatang(p.pv_satang) }}
                    </span>
                  </div>
                </div>

                <p class="mt-3 text-[12.5px] text-slate-400">
                  เว้นว่างไว้แล้วกดบันทึก = ล้างค่า PV ของสินค้านั้น
                </p>
              </div>

              <!--
                ═══ THE STRUCTURAL SECTIONS, RE-POINTED ═══

                Binary / Matrix / อันดับ / Generation / Affiliate below are
                byte-for-byte the sections that shipped with TASK-029..033 and
                passed UAT-012 — the same forms, the same submit functions,
                the same endpoints. Only the `v-if` changed: they used to be
                `viewMode === 'settings' && activeTab === '<key>'`, five peer
                tabs an admin had to know to open; they are now revealed by
                the plan chip that needs them, which is the only moment they
                mean anything.

                Each also carried `&& !wizardOpen` until 2026-09-12, guarding
                against the Setup Wizard's structure step binding the SAME refs
                (binaryForm, matrixForm, rankSettingsForm,
                generationSettingsForm, affiliateForm) in a second live editor.
                The wizard is gone, so these forms are once again the only
                editors of those refs and the guard had nothing left to guard.
              -->
              <div v-if="viewingPlanType === 'unilevel'" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[12.5px] text-slate-500">
                Unilevel ไม่มีค่าตั้งระดับบริษัทให้กรอกในขั้นนี้ — ทุกอย่างมาจากอัตราในขั้นที่ 3 และอัตราหัวหน้าทีมในขั้นที่ 4
              </div>

              <!-- ═══════════ Binary ═══════════ -->
              <div v-else-if="viewingPlanType === 'binary'" class="pt-2 border-t border-slate-100" data-test="plan-structure-binary">
                <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2 px-1 mt-2">ค่าตั้งระดับบริษัทของแผน Binary</h3>
                <div v-if="binaryError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ binaryError }}</div>
                <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 sm:grid-cols-4 gap-3" @submit.prevent="submitBinarySettings">
                  <!-- One `disabled` for the whole input block (display:contents
                       keeps the grid intact): a read-only Company Admin sees
                       every configured value, and cannot change any of it. -->
                  <fieldset class="contents" :disabled="!canEditCommissionConfig">
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
                  </fieldset>
                  <div v-if="canEditCommissionConfig" class="col-span-2 sm:col-span-4 flex justify-end">
                    <button type="submit" :disabled="savingBinary" class="btn-primary" data-test="save-binary">
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
              </div>

              <!-- ═══════════ Matrix ═══════════ -->
              <div v-else-if="viewingPlanType === 'matrix'" class="pt-2 border-t border-slate-100" data-test="plan-structure-matrix">
                <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2 px-1 mt-2">ค่าตั้งระดับบริษัทของแผน Matrix</h3>
                <div v-if="matrixError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ matrixError }}</div>
                <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 sm:grid-cols-3 gap-3" @submit.prevent="submitMatrixSettings">
                  <fieldset class="contents" :disabled="!canEditCommissionConfig">
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
                  </fieldset>
                  <div v-if="canEditCommissionConfig" class="col-span-2 sm:col-span-3 flex justify-end">
                    <button type="submit" :disabled="savingMatrix" class="btn-primary" data-test="save-matrix">
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
                  <button v-if="canEditCommissionConfig" class="btn-primary" data-test="add-level-rate" @click="showLevelRateForm = !showLevelRateForm">
                    + เพิ่ม Level
                  </button>
                </div>
                <EmptyState v-if="!matrixLevelRates.length" icon="layers" title="ยังไม่มีอัตราตาม Level" />
                <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
                  <div v-for="lr in matrixLevelRates" :key="lr.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
                    <p class="text-sm font-bold text-slate-900">Level {{ lr.level }} — {{ formatRate(lr.rate_type, lr.rate_value) }}</p>
                    <button v-if="canEditCommissionConfig" class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteLevelRate(lr)">ลบ</button>
                  </div>
                </TransitionGroup>
              </div>

              <!-- ═══════════ Agent Ranks / Stairstep-Breakaway ═══════════ -->
              <div v-else-if="viewingPlanType === 'stairstep_breakaway'" class="pt-2 border-t border-slate-100" data-test="plan-structure-ranks">
                <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2 px-1 mt-2">ค่าตั้งระดับบริษัทของแผนอันดับ (Stairstep)</h3>
                <div v-if="rankError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ rankError }}</div>
                <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitRankSettings">
                  <fieldset class="contents" :disabled="!canEditCommissionConfig">
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
                  </fieldset>
                  <div v-if="canEditCommissionConfig" class="col-span-2 flex justify-end">
                    <button type="submit" :disabled="savingRankSettings" class="btn-primary" data-test="save-rank-settings">
                      {{ savingRankSettings ? 'กำลังบันทึก...' : 'บันทึก' }}
                    </button>
                  </div>
                </form>

                <div class="flex justify-between items-center mt-4 mb-2 px-1">
                  <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider">บันไดอันดับ (เรียงจาก sort order น้อยไปมาก)</h3>
                  <button v-if="canEditCommissionConfig" class="btn-primary" data-test="add-rank" @click="showRankForm = !showRankForm">
                    + เพิ่มอันดับ
                  </button>
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
                    <div v-if="canEditCommissionConfig" class="flex items-center gap-2 shrink-0">
                      <button class="text-sm font-bold text-slate-500 hover:text-slate-700" @click="openEditRank(r)">แก้ไข</button>
                      <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteRank(r)">ลบ</button>
                    </div>
                  </div>
                </TransitionGroup>
              </div>

              <!-- ═══════════ Generation ═══════════ -->
              <div v-else-if="viewingPlanType === 'generation'" class="pt-2 border-t border-slate-100" data-test="plan-structure-generation">
                <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2 px-1 mt-2">ค่าตั้งระดับบริษัทของแผน Generation</h3>
                <div v-if="generationError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ generationError }}</div>
                <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitGenerationSettings">
                  <fieldset class="contents" :disabled="!canEditCommissionConfig">
                    <div>
                      <label class="text-sm font-bold text-slate-500">ความลึกสูงสุด (จำนวน Generation)</label>
                      <input v-model="generationSettingsForm.max_generation_depth" type="number" min="1" max="50" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
                    </div>
                  </fieldset>
                  <div v-if="canEditCommissionConfig" class="flex justify-end items-end">
                    <button type="submit" :disabled="savingGenerationSettings" class="btn-primary" data-test="save-generation-settings">
                      {{ savingGenerationSettings ? 'กำลังบันทึก...' : 'บันทึก' }}
                    </button>
                  </div>
                </form>

                <div class="flex justify-between items-center mt-4 mb-2 px-1">
                  <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider">อัตราคอมมิชชั่นตาม Generation</h3>
                  <button v-if="canEditCommissionConfig" class="btn-primary" data-test="add-generation-rule" @click="showGenerationRuleForm = !showGenerationRuleForm">
                    + เพิ่ม Generation
                  </button>
                </div>
                <EmptyState v-if="!generationRules.length" icon="users" title="ยังไม่มีอัตราตาม Generation" />
                <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
                  <div v-for="gr in generationRules" :key="gr.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
                    <p class="text-sm font-bold text-slate-900">Generation {{ gr.generation_number }} — {{ formatRate(gr.rate_type, gr.rate_value) }}</p>
                    <button v-if="canEditCommissionConfig" class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteGenerationRule(gr)">ลบ</button>
                  </div>
                </TransitionGroup>
              </div>

              <!-- ═══════════ Affiliate ═══════════ -->
              <div v-else-if="viewingPlanType === 'affiliate'" class="pt-2 border-t border-slate-100" data-test="plan-structure-affiliate">
                <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2 px-1 mt-2">ค่าตั้งระดับบริษัทของแผนพันธมิตร (Affiliate)</h3>
                <div v-if="affiliateError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ affiliateError }}</div>
                <form class="p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitAffiliateSettings">
                  <fieldset class="contents" :disabled="!canEditCommissionConfig">
                    <div>
                      <label class="text-sm font-bold text-slate-500">หน้าต่างนับเครดิต (วัน)</label>
                      <input v-model="affiliateForm.attribution_window_days" type="number" min="1" max="3650" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
                      <p class="mt-1 text-xs text-slate-400">คลิกล่าสุดต้องอยู่ในช่วงเวลานี้จึงจะนับเป็นการแปลงที่มาจากลิงก์พันธมิตร (last-click)</p>
                    </div>
                    <div class="flex items-start gap-2 pt-6">
                      <input id="differential" v-model="affiliateForm.new_vs_returning_rate_differential_enabled" type="checkbox" />
                      <label for="differential" class="text-sm font-bold text-slate-500">แยกอัตราลูกค้าใหม่/ลูกค้าเก่า (ยังไม่รองรับการคำนวณจริง)</label>
                    </div>
                  </fieldset>
                  <div v-if="canEditCommissionConfig" class="col-span-2 flex justify-end">
                    <button type="submit" :disabled="savingAffiliate" class="btn-primary" data-test="save-affiliate">
                      {{ savingAffiliate ? 'กำลังบันทึก...' : 'บันทึก' }}
                    </button>
                  </div>
                </form>
              </div>
            </template>
          </section>

          <!-- ═══════════ ขั้นที่ 3 · ตั้งอัตราตัวแทนผู้ขาย ═══════════ -->
          <section v-else-if="activeStep === 3" class="space-y-4" data-test="step-panel-3">
            <div>
              <p class="text-[17px] font-extrabold text-slate-900">ตัวแทนผู้ขายได้กี่เปอร์เซ็นต์</p>
              <p class="mt-1 text-[13px] text-slate-500">ขั้นเดียวในหน้านี้ที่ขาดไม่ได้ — ถ้าไม่มีอัตราที่ใช้ได้ ดีลที่ปิดได้จะไม่มีใครได้เงิน</p>
              <!--
                2026-09-12 — "5%" means two different amounts of money
                depending on the answer given one step earlier, and this is
                the screen where somebody types it. Stating the base here is
                what stops a rate meant as "5% of PV" being entered by
                somebody picturing 5% of the price. Only shown on PV, where
                it is news; on ราคาขาย it is what every label already says.
              -->
              <p v-if="commissionBasis === 'pv'" class="mt-2 inline-flex items-center gap-1.5 text-[12.5px] font-extrabold text-brand-700 bg-brand-50 border border-brand-200 rounded-lg px-2.5 py-1.5" data-test="step3-basis-note">
                <Icon name="info" :size="14" />
                บริษัทนี้คิด % จาก PV ของสินค้า ไม่ใช่จากราคาขาย (ตั้งไว้ในขั้นที่ 2)
              </p>
            </div>

            <EmptyState
              v-if="activeCompany.requiresCompanyPick"
              icon="building"
              title="กรุณาเลือกบริษัทก่อน"
              message="กลับไปที่ขั้นที่ 1 แล้วเลือกบริษัท เพื่อดูและตั้งอัตราค่าคอม"
            />
            <template v-else>
              <!--
                The resolution ladder, INLINE and permanent. This is the
                RESOLUTION_ORDER_NOTE that used to interrupt as a modal on
                every entry to the rules tab — drawn instead of announced, so
                it is on screen while the admin is reading the very rows it
                explains.
              -->
              <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5" data-test="resolution-ladder">
                <div class="flex flex-wrap items-center gap-2 mb-2.5">
                  <p class="text-xs font-bold text-slate-500">ระบบหาอัตราจากบนลงล่าง — เจอชั้นไหนก่อน หยุดที่ชั้นนั้น ไม่บวกกัน</p>
                  <button type="button" class="ml-auto text-xs font-bold text-brand-600 hover:underline" data-test="open-resolution-order" @click="openResolutionOrderModal">
                    ลำดับการใช้ค่า
                  </button>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                  <span class="text-[13px] font-bold bg-white border border-slate-300 rounded-lg px-3 py-1.5">1 · อัตราของสินค้านั้น</span>
                  <Icon name="arrow_right" :size="16" class="text-slate-300" />
                  <span class="text-[13px] font-bold bg-white border border-slate-300 rounded-lg px-3 py-1.5">2 · อัตราของหมวดหมู่</span>
                  <Icon name="arrow_right" :size="16" class="text-slate-300" />
                  <span class="text-[13px] font-extrabold bg-brand-50 border-2 border-brand-600 text-brand-700 rounded-lg px-3 py-1.5">3 · ค่าเริ่มต้นทั้งบริษัท</span>
                </div>
              </div>

              <!-- TASK-213 r2 — name the collisions where they can be deleted.
                   A count elsewhere tells an admin something is wrong; only
                   this list can tell them WHICH ROW to remove. -->
              <div v-if="conflictingRuleIds.size" class="p-4 rounded-xl bg-rose-50 border border-rose-200" data-test="agent-rate-conflicts">
                <p class="text-sm font-bold text-rose-800">พบอัตราตัวแทนซ้อนทับกัน {{ conflictingRuleIds.size }} รายการ</p>
                <p class="mt-1 text-xs text-rose-700 leading-relaxed">
                  แถวที่ติดป้าย <b>ซ้อนทับ</b> ด้านล่างมีผลพร้อมกันในขอบเขตเดียวกัน — ระบบเรียงตามวันที่เริ่มมีผลแล้วหยิบอันแรก
                  <b>เมื่อวันเริ่มเท่ากันจึงหยิบอันไหนก็ได้ ทำนายไม่ได้</b> · ค่าคอมที่ลงบัญชีไปแล้วแก้ย้อนหลังไม่ได้ (BR-4)
                  จึงควรลบให้เหลือรายการเดียวก่อนจะมีดีลปิดเพิ่ม
                </p>
                <p class="mt-1 text-xs text-rose-600">
                  ระบบไม่ยอมให้สร้างแบบนี้แล้วตั้งแต่ต้น — รายการเหล่านี้มักเป็นข้อมูลเก่าที่เคยแยกด้วย cert tier ก่อน ADR-035 (18 ส.ค. 2569)
                </p>
              </div>

              <!-- ── 3.1 ค่าเริ่มต้นทั้งบริษัท ── -->
              <div data-test="step3-company-default">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                  <span class="text-[13.5px] font-extrabold text-slate-900">3.1 ตั้งค่าเริ่มต้นทั้งบริษัทก่อน</span>
                  <span class="text-[11.5px] text-slate-400">ตาข่ายกันพลาด — สินค้าที่ยังไม่ได้ตั้งเรตจะตกลงมาใช้ค่านี้</span>
                  <span
                    class="ml-auto text-[11px] font-bold rounded-full px-2.5 py-1"
                    :class="companyDefaultRules.length ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'"
                    data-test="company-default-pill"
                  >
                    {{ companyDefaultRules.length ? 'เรียบร้อย' : 'ยังไม่มี' }}
                  </span>
                </div>

                <div v-if="companyDefaultRules.length" class="space-y-2">
                  <div
                    v-for="r in companyDefaultRules"
                    :key="r.id"
                    class="flex flex-wrap items-center gap-3.5 rounded-xl border px-4 py-3"
                    :class="conflictingRuleIds.has(r.id) ? 'border-rose-300 bg-rose-50/40' : 'border-slate-200'"
                    :data-test="`company-default-rule-${r.id}`"
                  >
                    <span class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-brand-50 text-brand-700">ตัวแทนผู้ขาย</span>
                    <span v-if="conflictingRuleIds.has(r.id)" class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-rose-100 text-rose-700">ซ้อนทับ</span>
                    <span class="text-sm font-extrabold text-slate-900">ค่าเริ่มต้นทั้งบริษัท</span>
                    <span class="text-sm font-extrabold text-brand-700">{{ formatRate(r.rate_type, r.rate_value) }}</span>
                    <span class="text-xs text-slate-400">
                      มีผล {{ formatDate(r.effective_from) }}{{ r.effective_to ? ` ถึง ${formatDate(r.effective_to)}` : ' — ไม่มีวันสิ้นสุด' }}
                      <span v-if="r.renewal_rate_type"> · ต่ออายุ {{ formatRate(r.renewal_rate_type, r.renewal_rate_value!) }}</span>
                    </span>
                    <span v-if="canEditCommissionConfig" class="ml-auto flex items-center gap-3">
                      <button class="text-sm font-bold text-brand-700 hover:underline" @click="openEditRuleForm(r)">แก้ไข</button>
                      <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteRule(r)">ลบ</button>
                    </span>
                  </div>
                </div>
                <div v-else class="flex flex-wrap items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                  <p class="flex-1 min-w-0 text-[13px] font-bold text-rose-700">
                    ยังไม่มีค่าเริ่มต้นทั้งบริษัท — สินค้าที่ไม่ได้ตั้งเรตของตัวเองจะไม่มีใครได้เงินเลย
                  </p>
                  <button
                    v-if="canEditCommissionConfig"
                    type="button"
                    class="btn-primary shrink-0"
                    data-test="add-company-default"
                    @click="openCreateRuleFormWithScope('company')"
                  >
                    + ตั้งค่าเริ่มต้นทั้งบริษัท
                  </button>
                  <!-- 2026-09-13 — the second way out of this panel, for the
                       brand-new company whose sibling already has every rate.
                       SECONDARY on purpose: typing the number is still the
                       decision this screen is for, and copying is the shortcut
                       to a decision somebody already made elsewhere. Needs a
                       company to copy INTO, so it is gated on
                       effectiveCompanyId as well as on the Super Admin role
                       the endpoint itself requires. -->
                  <button
                    v-if="canEditCommissionConfig && effectiveCompanyId"
                    type="button"
                    class="btn-secondary shrink-0"
                    data-test="copy-rates-open"
                    @click="openCopyRatesModal"
                  >
                    คัดลอกจากบริษัทอื่น
                  </button>
                </div>
              </div>

              <!-- ── 3.2 สินค้าที่มาร์จิ้นต่างจริง ── -->
              <div data-test="step3-products">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                  <span class="text-[13.5px] font-extrabold text-slate-900">3.2 แยกเฉพาะสินค้าที่มาร์จิ้นต่างจริง</span>
                  <span class="text-[11.5px] text-slate-400">
                    ไม่ต้องแยกทุกตัว — ที่ไม่แยกจะใช้{{ companyDefaultRules[0] ? ` ${formatRate(companyDefaultRules[0].rate_type, companyDefaultRules[0].rate_value)} ` : 'ค่าเริ่มต้น' }}ข้างบน
                  </span>
                </div>

                <!-- A failed switch has to say so where the switch is. Same
                     shape as pvError one step over — a visible line, never a
                     silently reverted row. -->
                <p v-if="sellingError" class="mb-2 text-[12.5px] font-bold text-rose-600" data-test="selling-error">{{ sellingError }}</p>

                <EmptyState v-if="!byCompany(products).length" icon="money" title="ยังไม่มีสินค้า" />
                <div v-else class="space-y-2">
                  <!--
                    The Option B product card from the 2026-07-22 overview,
                    folded onto the path. It keeps what that card was FOR —
                    the rate that actually resolves, and the layer it came
                    from — and drops the separate view mode that made it a
                    detour.

                    2026-09-12 — and it now says whether the company SELLS the
                    product, greyed exactly as ProductCatalogView greys it.
                    Not hidden and not disabled: the reason to look at a closed
                    row here is to rate it, and the owner asked for that
                    outright ("ที่ปิดไว้ก็แก้ไขได้เหมือนเดิม"). It is only
                    visibly not part of today's shop, which the eye sorts far
                    faster than it reads a status word.

                    A missing rate still wins the row's colour: "nobody gets
                    paid" outranks "not on sale today" — a closed product can
                    be reopened in one click, an immutable ledger entry cannot
                    (BR-4).
                  -->
                  <div
                    v-for="p in sellingFirstProducts"
                    :key="p.id"
                    class="rounded-xl border px-4 py-3 transition-colors"
                    :class="productReadiness(p).level === 'bad'
                      ? 'border-rose-200 bg-rose-50'
                      : (p.is_sellable_here ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50/60')"
                    :data-test="`product-row-${p.id}`"
                    :data-selling="p.is_sellable_here ? 'open' : 'closed'"
                  >
                    <div class="flex flex-wrap items-center gap-3.5">
                      <div class="flex-1 min-w-[200px]">
                        <p
                          class="text-sm font-bold"
                          :class="productReadiness(p).level === 'bad'
                            ? 'text-rose-800'
                            : (p.is_sellable_here ? 'text-slate-900' : 'text-slate-400')"
                        >{{ p.name }}</p>
                        <p class="text-xs" :class="p.is_sellable_here ? 'text-slate-400' : 'text-slate-300'">
                          {{ p.category?.name ?? 'ไม่มีหมวดหมู่' }}<span v-if="p.price_satang"> · {{ formatSatang(p.price_satang) }}</span>
                          · แผน {{ p.effective_plan_type ? planTypeLabels[p.effective_plan_type] : '—' }}<span v-if="!p.commission_plan_type"> (สืบทอดจากบริษัท)</span>
                        </p>
                      </div>
                      <span class="text-sm font-extrabold" :class="resolveRuleFor(p) ? 'text-brand-700' : 'text-rose-600'" :data-test="`product-rate-${p.id}`">
                        {{ resolveRuleFor(p) ? formatRate(resolveRuleFor(p)!.rate_type, resolveRuleFor(p)!.rate_value) : 'ยังไม่มีอัตรา' }}
                      </span>
                      <!-- WHICH LAYER the number came from. Without it, "3.00%"
                           on a product row is indistinguishable from a rate
                           somebody chose for that product, and deleting the
                           company default silently changes it. -->
                      <span
                        class="text-[11px] font-bold rounded-full px-2.5 py-1"
                        :class="resolveRuleFor(p) ? (resolveRuleFor(p)!.product ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-500') : 'bg-rose-100 text-rose-700'"
                        :data-test="`product-layer-${p.id}`"
                      >
                        {{ rateLayerLabel(p) }}
                      </span>
                      <div class="flex items-center gap-2 shrink-0">
                        <!--
                          2026-09-12 (owner: "เพิ่มการเปิดปิดสินค้าได้เลย").

                          A switch, not a button labelled "ปิดขาย": a button
                          states the ACTION it performs, a switch states the
                          STATE it is in — and the state is what somebody
                          scanning twenty rows is reading for. It is also the
                          one control in this row that takes effect the moment
                          it is touched, with no dialog, which is the shape
                          people already read a switch as.

                          Shown only where it will actually work — see
                          canToggleSelling() for why that is a per-row question
                          on one branch and a role on the other.
                        -->
                        <button
                          v-if="canToggleSelling(p)"
                          type="button"
                          class="shrink-0 inline-flex items-center gap-2 disabled:opacity-50"
                          :disabled="sellingSavingId === p.id"
                          :data-test="`selling-switch-${p.id}`"
                          :title="p.is_sellable_here ? 'เปิดขายอยู่ — แตะเพื่อปิดขาย' : 'ปิดขายอยู่ — แตะเพื่อเปิดขาย'"
                          @click="toggleSelling(p)"
                        >
                          <!-- The colour and the WORD say the same thing, which
                               is what keeps it readable at a glance and still
                               readable to someone who does not separate these
                               two blues. -->
                          <span
                            class="text-xs font-bold whitespace-nowrap"
                            :class="p.is_sellable_here ? 'text-brand-700' : 'text-slate-400'"
                            :data-test="`selling-label-${p.id}`"
                          >
                            {{ sellingSavingId === p.id ? 'กำลังบันทึก…' : (p.is_sellable_here ? 'เปิดขาย' : 'ปิดขาย') }}
                          </span>
                          <!-- The knob is a FLEX CHILD pushed to one end, never
                               an absolutely positioned span shifted by an
                               arbitrary Tailwind translate: an arbitrary value
                               the scanner never saw is absent from the compiled
                               CSS, so the knob carries the right classes and
                               simply never moves. That shipped once already —
                               ProductCatalogView's toggleSellHere() tells the
                               whole story. -->
                          <span
                            class="w-11 h-6 rounded-full border flex items-center p-0.5 transition-colors"
                            :class="p.is_sellable_here ? 'bg-brand-600 border-brand-600 justify-end' : 'bg-white border-slate-300 justify-start'"
                          >
                            <span
                              class="w-5 h-5 rounded-full shadow-sm transition-colors"
                              :class="p.is_sellable_here ? 'bg-white' : 'bg-slate-300'"
                            ></span>
                          </span>
                        </button>
                        <!-- House rule since 2026-09-11: a control somebody
                             cannot use is not shown — a switch you can see and
                             cannot move reads as broken, not as forbidden. The
                             STATE is still news to them (it is why the row is
                             grey), so it stays as a pill that was never a
                             control in the first place. -->
                        <span
                          v-else
                          class="shrink-0 text-[11px] font-bold rounded-full px-2.5 py-1 whitespace-nowrap"
                          :class="p.is_sellable_here ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                          :data-test="`selling-state-${p.id}`"
                          :title="p.is_shared
                            ? 'สินค้ากลาง — เปิด/ปิดขายตั้งโดย Super Admin'
                            : 'เปิด/ปิดขายสินค้านี้ต้องมีสิทธิ์แก้ไขสินค้า'"
                        >
                          {{ p.is_sellable_here ? 'เปิดขาย' : 'ปิดขาย' }}
                        </span>
                        <button type="button" class="px-3 py-1.5 rounded-lg text-slate-600 border border-slate-200 text-xs font-bold hover:bg-slate-50" @click="openSimulate(p)">
                          ทดสอบคำนวณ
                        </button>
                        <!-- TASK-245's per-row question AND the 2026-09-11 role
                             rule, asked together: `canSetCommission` still
                             answers for the PRODUCT (a catalog-linked row is
                             refused, ADR-036 §5/§6) while
                             `canEditCommissionConfig` answers for the VIEWER.
                             Either one refusing means the server would, so the
                             button must not be there to click.

                             The row's "Wizard" button sat beside this one under
                             the same condition until 2026-09-12; the `template`
                             that grouped the pair went with it, so the test
                             now sits on the one button that is left. -->
                        <button
                          v-if="canEditCommissionConfig && canSetCommission(p)"
                          type="button"
                          class="btn-primary"
                          @click="openRuleFormForProduct(p)"
                        >
                          {{ resolveRuleFor(p) ? 'แก้ไขอัตราคอมมิชชั่น' : '+ ตั้งอัตราคอมมิชชั่น' }}
                        </button>
                        <span v-else class="text-[11px] text-slate-400 whitespace-nowrap" title="การตั้งอัตราค่าคอมเป็นสิทธิ์ของ Super Admin">
                          ตั้งค่าโดย Super Admin
                        </span>
                      </div>
                    </div>

                    <!-- An expired rate is not the same gap as a missing one;
                         see expiredRuleFor()'s docblock. -->
                    <p
                      v-if="!resolveRuleFor(p) && expiredRuleFor(p)"
                      class="mt-2 text-[12.5px] font-bold text-rose-700"
                      :data-test="`product-expired-${p.id}`"
                    >
                      อัตราหมดอายุ {{ formatDate(expiredRuleFor(p)!.effective_to!) }} — ปิดการขายแล้วไม่มีใครได้เงิน
                    </p>
                    <div
                      v-else-if="productReadiness(p).level !== 'ok'"
                      class="mt-2 px-3 py-2 rounded-lg text-xs flex items-center justify-between gap-2 flex-wrap"
                      :class="productReadiness(p).level === 'bad' ? 'bg-rose-100/60 text-rose-700' : 'bg-amber-50 text-amber-700'"
                    >
                      <span class="font-bold">{{ productReadiness(p).level === 'bad' ? '●' : '!' }} {{ productReadiness(p).message }}</span>
                      <!-- Points at the STEP that owns the gap, which is the
                           whole reason the steps exist: a structural gap is
                           step 2's, a leader-rate gap is step 4's, and
                           neither is fixable from here.

                           2026-09-12 — both are now also conditioned on the
                           target being REACHABLE, and this is the one place
                           where the gate bites something real: this panel IS
                           step 3, so "ไปขั้นที่ 4 ตั้งอัตราหัวหน้าทีม" is
                           offered from inside a step that may itself be
                           incomplete (this product has a rate; another one in
                           the list may not). goToStep() would refuse it, so
                           the button is not drawn rather than drawn dead. The
                           readiness message it sits beside still names the
                           gap, and the banner above still names the step to
                           clear first — so nothing is hidden, only the false
                           promise of a one-click fix that is not available
                           yet. -->
                      <button
                        v-if="p.effective_plan_type && planTypeToTab[p.effective_plan_type] && structureReady[p.effective_plan_type] === false && stepReachable[2]"
                        type="button"
                        class="font-bold whitespace-nowrap hover:underline"
                        :data-test="`jump-structure-${p.id}`"
                        @click="viewPlan(p.effective_plan_type!); goToStep(2)"
                      >
                        ไปขั้นที่ 2 ตั้งโครงสร้าง →
                      </button>
                      <button
                        v-else-if="productReadiness(p).level === 'warn' && stepReachable[4]"
                        type="button"
                        class="font-bold whitespace-nowrap hover:underline"
                        :data-test="`jump-leader-${p.id}`"
                        @click="goToStep(4)"
                      >
                        ไปขั้นที่ 4 ตั้งอัตราหัวหน้าทีม →
                      </button>
                    </div>
                    <p v-else class="mt-2 text-xs font-bold text-emerald-700">✓ ตั้งค่าครบ พร้อมจ่าย</p>
                  </div>
                </div>

                <div v-if="canEditCommissionConfig" class="flex flex-wrap gap-2.5 mt-3">
                  <button type="button" class="btn-primary" data-test="add-product-rate" @click="openCreateRuleFormWithScope('product')">
                    + เพิ่มอัตราของสินค้า
                  </button>
                  <button type="button" class="btn-secondary" data-test="add-category-rate" @click="openCreateRuleFormWithScope('category')">
                    + เพิ่มอัตราของหมวดหมู่
                  </button>
                  <!-- TASK-037's "เปิดตัวช่วยตั้งค่า (Wizard)" stood here and
                       was removed 2026-09-12: these two buttons and the row
                       buttons above ARE the guided path now. -->
                </div>
              </div>
            </template>
          </section>

          <!-- ═══════════ ขั้นที่ 4 · ส่วนเพิ่มเติม ═══════════ -->
          <section v-else class="space-y-4" data-test="step-panel-4">
            <div>
              <p class="text-[17px] font-extrabold text-slate-900">ส่วนเพิ่มเติม</p>
              <p class="mt-1 text-[13px] text-slate-500">
                ข้ามได้ทั้งหมด — ระบบจ่ายค่าคอมได้แล้วตั้งแต่จบขั้นที่ 3 · สามอย่างนี้คือส่วนที่จ่ายให้คนอื่นนอกจากตัวแทนที่ปิดการขาย
              </p>
            </div>

            <EmptyState
              v-if="activeCompany.requiresCompanyPick"
              icon="building"
              title="กรุณาเลือกบริษัทก่อน"
              message="กลับไปที่ขั้นที่ 1 แล้วเลือกบริษัท เพื่อดูและตั้งค่าส่วนเพิ่มเติม"
            />
            <template v-else>
              <!--
                CARD 1 — the leader rate. TASK-213 Phase 2 moved this table out
                of /product-catalog and onto this screen because "an admin
                asking how much the leader gets opens แผนคอมมิชชั่น and none of
                the six tabs is it". It is now a named card on a named step,
                which is the same fix one level further.
              -->
              <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4" data-test="step4-leader-rates">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <p class="text-[15px] font-extrabold text-slate-900">อัตราหัวหน้าทีม</p>
                  <span
                    class="text-[11px] font-bold rounded-full px-2.5 py-1"
                    :class="activeOverrideRules.length ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                  >
                    {{ activeOverrideRules.length ? `${activeOverrideRules.length} อัตรา` : 'ยังไม่ได้ตั้ง' }}
                  </span>
                  <button
                    v-if="canEditCommissionConfig"
                    type="button"
                    class="ml-auto px-3 py-1.5 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 text-xs font-bold hover:bg-amber-100"
                    data-test="add-leader-rate"
                    @click="openCreateOverrideForm"
                  >
                    + เพิ่มอัตราหัวหน้าทีม
                  </button>
                </div>
                <p class="text-[12.5px] text-slate-500 mb-3">
                  จ่ายให้หัวหน้าทีมทุกครั้งที่ลูกทีมปิดการขาย · แผน <b>Unilevel</b> จ่ายขึ้นไปทั้งสาย · แผน <b>พันธมิตร (Affiliate)</b> จ่ายชั้นเดียว ·
                  ลำดับการใช้ค่าเหมือนอัตราตัวแทนเป๊ะ ๆ — สินค้าเฉพาะ &gt; หมวดหมู่ &gt; ค่าเริ่มต้นทั้งบริษัท
                </p>

                <div v-if="leaderRateGaps.length" class="mb-3 px-3 py-2 rounded-lg bg-amber-100/70 text-xs font-bold text-amber-800">
                  สินค้า {{ leaderRateGaps.length }} รายการยังไม่มีอัตราหัวหน้าทีมที่ใช้ได้ — ตัวแทนได้ตามปกติ แต่หัวหน้าจะไม่ได้ส่วนแบ่งจากดีลนั้น
                </div>

                <EmptyState v-if="!byCompany(commissionOverrideRules).length" icon="users" title="ยังไม่มีอัตราหัวหน้าทีม" />
                <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
                  <div
                    v-for="r in byCompany(commissionOverrideRules)"
                    :key="`leader-${r.id}`"
                    class="bg-white/95 rounded-xl p-4 flex items-center justify-between gap-3 border"
                    :class="conflictingOverrideIds.has(r.id) ? 'border-rose-300 bg-rose-50/40' : 'border-amber-200'"
                    :data-test="`leader-rule-${r.id}`"
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
                    <div v-if="canEditCommissionConfig" class="flex items-center gap-2 shrink-0">
                      <button class="text-sm font-bold text-slate-500 hover:text-slate-700" @click="openEditOverrideForm(r)">แก้ไข</button>
                      <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteOverrideRule(r)">ลบ</button>
                    </div>
                  </div>
                </TransitionGroup>
              </div>

              <!--
                ═══ CARD 2 — EMBEDDED, NOT LINKED (2026-09-12) ═══

                Owner: "ยังจำเป็นต้องใช้หน้านี้ไหม เพราะเรานำไปรวมกันแล้ว" — asked
                about /commission-split-settings, and the honest answer was
                that it had NOT been merged: this spot held a link card, and
                the switch still lived on a 59-line page that was nothing but
                a shell around CommissionSplitSettingCard. A route, a menu
                entry and a full page for one boolean is exactly what the step
                redesign exists to remove, so the card moved here and the page
                is gone (its URL now redirects to this screen).

                Embedded rather than linked because the old reason for linking
                does not apply to it: the warning about leaving mid-step was
                written for screens carrying unsaved state of their own, and
                this card's entire state is one toggle it loads and saves by
                itself.

                `readOnly` rather than letting the card render its own toggle:
                Ability::SettingsCommissionSplitUpdate is Super-Admin-only, and
                this screen's rule is that a control a Company Admin cannot use
                is not shown at all.
              -->
              <div data-test="split-setting-embedded">
                <CommissionSplitSettingCard
                  :key="effectiveCompanyId ?? 'own'"
                  :company-id="effectiveCompanyId"
                  :is-super-admin="isSuperAdmin"
                  :read-only="!canEditCommissionConfig"
                />
              </div>

              <!--
                CARD 3 STAYS A LINK, and the difference from card 2 is the
                point: "คำขอเบิกค่าคอม" is a working screen an admin uses to
                approve real withdrawals — the minimum is one field on it, not
                the whole of it. Pulling that field over here would leave two
                places to change one number. Said plainly on the card rather
                than discovered by clicking.
              -->
              <RouterLink
                :to="{ name: 'commission-withdrawals' }"
                class="block rounded-2xl border border-slate-200 p-4 hover:bg-slate-50"
                data-test="link-withdrawal-settings"
              >
                <p class="text-[15px] font-extrabold text-slate-900">ยอดขั้นต่ำในการเบิก</p>
                <p class="mt-1 text-[12.5px] text-slate-500">ตัวแทนต้องมียอดสะสมถึงเท่าไหร่จึงจะกดขอเบิกค่าคอมได้</p>
                <p class="mt-2 text-[12.5px] font-bold text-brand-600">ตั้งค่าที่หน้าจออื่น — กดแล้วจะออกจากหน้านี้ไปหน้า "คำขอเบิกค่าคอม" →</p>
              </RouterLink>

              <!-- Carried over from the setup hub the overview tab used to
                   host, so the entry point does not disappear with the tab. -->
              <p class="text-xs text-slate-400">
                อยากให้ตัวแทนได้แต้ม/เหรียญ/ภารกิจเพิ่มด้วย?
                <RouterLink :to="{ name: 'gamification-config' }" class="font-bold text-brand-600 hover:underline" data-test="link-gamification">
                  ตั้งค่า Gamification →
                </RouterLink>
              </p>
            </template>
          </section>
        </template>
      </div>

      <!--
        THE FOOTER THAT NAMES THE NEXT STEP.

        This row is the point of the whole redesign. The owner's complaint was
        "ผู้ใช้ไม่รู้ว่าต้องกรอกอะไรหลัง" — six peer tabs answered "what next?"
        with silence, so the answer is now written on the button itself, with
        the step's real name and not just an arrow.
      -->
      <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3.5">
        <button
          v-if="prevStep"
          type="button"
          class="btn-secondary"
          data-test="step-back"
          @click="goToStep(prevStep!)"
        >
          ← ขั้นที่ {{ prevStep }} {{ stepLabel(prevStep!) }}
        </button>
        <div v-if="nextStep" class="ml-auto flex items-center gap-3.5">
          <!-- Disabled, and SAYING SO in words, rather than silently doing
               nothing: "the button did nothing" is the same complaint as
               "ผู้ใช้ไม่รู้ว่าต้องกรอกอะไรหลัง" in a different costume. The
               button keeps the next step's NAME — that is the answer this
               footer exists to give — and the reason is printed beside it.

               Natively `disabled` here, unlike the step tabs above, and the
               difference is not an oversight. This button is a SHORTCUT to a
               move the tab bar also offers; the tab is the move itself. A
               dropped shortcut costs a keyboard user nothing, because the tab
               is still there to focus and still announces its own lock — so
               here the strongest refusal the platform has is the right one,
               and up there it would hide the explanation. -->
          <span
            v-if="nextStepBlockedReason"
            class="text-[12.5px] font-bold text-amber-700"
            data-test="step-next-blocked"
          >
            🔒 {{ nextStepBlockedReason }}
          </span>
          <span v-else class="text-[12.5px] text-slate-400">ขั้นถัดไป:</span>
          <button
            type="button"
            class="btn-primary"
            :class="nextStepBlockedReason ? 'opacity-50 cursor-not-allowed' : ''"
            :disabled="!!nextStepBlockedReason"
            :title="nextStepBlockedReason || undefined"
            data-test="step-next"
            @click="goToStep(nextStep!)"
          >
            ขั้นที่ {{ nextStep }} {{ stepLabel(nextStep!) }} →
          </button>
        </div>
        <p v-else class="ml-auto text-[12.5px] font-bold" :class="blockingStep ? 'text-amber-700' : 'text-emerald-700'">
          {{ blockingStep ? `ครบทุกขั้นแล้ว แต่ยังติดอยู่ที่ขั้นที่ ${blockingStep}` : 'ครบทุกขั้นแล้ว — พร้อมจ่ายค่าคอม' }}
        </p>
      </div>
    </div>

    <!--
      ═══════════ PAGE-LEVEL MODALS ═══════════

      All ten of them live HERE, outside every step panel, and that is a
      deliberate change: six of these used to be nested inside the section
      that owned them (three in the rules tab, one each in matrix / อันดับ /
      generation). A `fixed inset-0` overlay is not visually part of its
      panel, but it WAS part of its lifetime — so anything that unmounted the
      section while a form was open took the half-typed form with it, without
      a word. Steps unmount far more readily than tabs did, so the hazard went
      from theoretical to one click away.

      One rule, applied to all of them: a modal is page-level, its opener
      lives in the step that owns it, and only `canEditCommissionConfig`
      renders that opener. That is also why none of the write forms below
      carry their own permission check — a Company Admin has no way to open
      one, and a second check here would be a second thing to keep in step.
    -->

    <!-- อัตราหัวหน้าทีม — opened from step 4 -->
    <!-- TASK-216 r2 — a real modal (human, 2026-08-20: "ทำไมไม่เป็น modal
         backgroud สีดำ"). Inline forms opened at the TOP of the page while
         the row being edited sat further down and often off-screen; the
         overlay removes the question by removing everything else. -->
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
            จ่ายให้ "หัวหน้าทีม" ทุกครั้งที่ลูกทีมปิดการขาย ·
            แผน <b>มาตรฐาน (Unilevel)</b> จ่ายขึ้นไปทั้งสาย · แผน <b>พันธมิตร (Affiliate)</b> จ่ายชั้นเดียว
          </p>
          <p class="text-xs text-amber-800">
            <b>อัตราแยกรายสินค้าได้แล้ว</b> · ลำดับการใช้ค่าเหมือนอัตราตัวแทนเป๊ะ ๆ — สินค้าเฉพาะ &gt; หมวดหมู่ &gt; ค่าเริ่มต้นทั้งบริษัท
          </p>
          <div v-if="overrideFormError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ overrideFormError }}</div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <!-- TASK-214 — the cert-tier picker that used to be here is gone:
                 the rate no longer depends on the manager's tier (human
                 ruling 2026-08-19). This is the scope selector that replaced
                 it, deliberately identical to the agent rate's so both read
                 the same way. -->
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
                <option v-for="c in byCompany(productCategories)" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
            <!-- TASK-213 — the field that did not exist. The old form sent
                 rate_type: 'percentage' unconditionally. -->
            <div>
              <label class="text-sm font-bold text-slate-500">รูปแบบอัตรา</label>
              <select v-model="overrideForm.rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="percentage">{{ percentageOptionLabel }}</option>
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

    <!-- อัตราตัวแทนผู้ขาย — opened from step 3 -->
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
                <option v-for="c in byCompany(productCategories)" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
            <!-- TASK-197 §3.4 — product-scope rules use the PRODUCT's
                 locked-in commission_rate_type once it has one
                 (server-enforced, §2.2): the selector only shows for
                 company-wide/category rules (which keep their own free
                 choice, §1 unchanged) OR the very first rule a product ever
                 gets (nothing to inherit from yet). -->
            <div v-if="showRuleFormRateTypeSelector">
              <label class="text-sm font-bold text-slate-500">รูปแบบอัตรา</label>
              <select v-model="ruleForm.rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white" @change="recheckRuleCap">
                <option value="percentage">{{ percentageOptionLabel }}</option>
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
              <!-- TASK-197 §3.4 — when the selector above is hidden (locked-in
                   product format), tell the admin which unit their number
                   means instead of leaving them to guess. -->
              <p v-if="!showRuleFormRateTypeSelector" class="mt-1 text-xs text-slate-400">จะบันทึกเป็น: {{ effectiveRuleFormRateType === 'percentage' ? percentageOptionLabel : rateTypeLabels.fixed_satang }}</p>
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

    <!-- คัดลอกอัตราจากบริษัทอื่น — opened from step 3.1 -->
    <!-- 2026-09-13 — see the `copyRatesPreview` block in the script for why
         this screen copies on request instead of seeding a default. The modal
         exists to make the preview UNSKIPPABLE: the rows it lists are about to
         become commission rates, and a rate that reaches a ledger row cannot
         be taken back (BR-4). -->
    <div v-if="showCopyRatesModal" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" data-test="copy-rates-modal" @click.self="closeCopyRatesModal">
      <div class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col">
        <div class="shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
          <div class="min-w-0">
            <p class="text-xs font-bold tracking-wide text-brand-700">คัดลอกอัตราค่าคอม</p>
            <h1 class="mt-0.5 text-xl font-bold text-slate-900 break-words leading-snug">
              คัดลอกมาที่ {{ activeCompany.companyName ?? 'บริษัทนี้' }}
            </h1>
          </div>
          <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600" @click="closeCopyRatesModal">
            <Icon name="x" :size="20" />
          </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto py-3 -mx-1 px-1 space-y-3">
          <!-- The owner's question, answered where it gets asked. -->
          <p class="text-xs text-slate-500 leading-relaxed">
            ระบบไม่ตั้งอัตราเริ่มต้นให้เองเด็ดขาด — อัตราที่ระบบเดาให้กับอัตราที่คนตัดสินใจเองหน้าตาเหมือนกันทุกประการ
            และเมื่อลงบัญชีค่าคอมไปแล้วแก้ย้อนหลังไม่ได้ (BR-4) · การคัดลอกไม่ใช่การเดา เพราะมี "บริษัทต้นทาง" ที่คนเลือกเอง
          </p>

          <div>
            <label class="text-sm font-bold text-slate-500">คัดลอกจากบริษัท</label>
            <select
              v-model="copyRatesSourceId"
              class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white"
              data-test="copy-rates-source"
              @change="previewCopyRates"
            >
              <option value="" disabled>เลือกบริษัทต้นทาง</option>
              <option v-for="c in copyRatesSourceOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>

          <!-- Never a silent failure: both the preview and the copy report
               here, in the one place the admin is looking. -->
          <div v-if="copyRatesError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700" data-test="copy-rates-error">
            {{ copyRatesError }}
          </div>

          <p v-if="copyRatesPreviewing" class="text-xs text-slate-400" data-test="copy-rates-loading">กำลังดูตัวอย่าง...</p>

          <div v-else-if="copyRatesPreview" class="space-y-3" data-test="copy-rates-summary">
            <p class="text-[13px] font-extrabold text-slate-900">
              จะสร้างใหม่ {{ copyRatesPreview.total_to_copy }} รายการ —
              ตัวแทนผู้ขาย {{ copyRatesPreview.agent_rates.copied.length }} · หัวหน้าทีม {{ copyRatesPreview.leader_rates.copied.length }}
            </p>
            <!-- Every copy starts today and has no end date, and that is not
                 guessable from the list — an admin reading "3.00%" here would
                 otherwise assume the source row's dates came with it. -->
            <p class="text-xs text-slate-400">ทุกรายการจะเริ่มมีผลวันนี้ และไม่มีวันสิ้นสุด · อัตราที่หมดอายุแล้วในบริษัทต้นทางจะไม่ถูกคัดลอก</p>

            <div v-if="copyRatesPreview.agent_rates.copied.length">
              <p class="text-xs font-bold text-slate-500 mb-1">อัตราตัวแทนผู้ขาย</p>
              <div class="space-y-1">
                <div
                  v-for="(e, i) in copyRatesPreview.agent_rates.copied"
                  :key="`agent-${i}`"
                  class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5"
                  :data-test="`copy-rates-agent-${i}`"
                >
                  <span class="text-[13px] font-bold text-slate-900">{{ e.label }}</span>
                  <span class="ml-auto text-[13px] font-extrabold text-brand-700">{{ formatRate(e.rate_type, e.rate_value) }}</span>
                </div>
              </div>
            </div>

            <div v-if="copyRatesPreview.leader_rates.copied.length">
              <p class="text-xs font-bold text-slate-500 mb-1">อัตราหัวหน้าทีม</p>
              <div class="space-y-1">
                <div
                  v-for="(e, i) in copyRatesPreview.leader_rates.copied"
                  :key="`leader-${i}`"
                  class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5"
                  :data-test="`copy-rates-leader-${i}`"
                >
                  <span class="text-[13px] font-bold text-slate-900">{{ e.label }}</span>
                  <span class="ml-auto text-[13px] font-extrabold text-amber-700">{{ formatRate(e.rate_type, e.rate_value) }}</span>
                </div>
              </div>
            </div>

            <!-- Skipped rows are listed, not counted. "ข้าม 4 รายการ" tells an
                 admin something is missing; only the row and its reason tell
                 them whether they still have to go and set it by hand. -->
            <div v-if="copyRatesSkipped.length" data-test="copy-rates-skipped">
              <p class="text-xs font-bold text-amber-700 mb-1">ไม่ได้คัดลอก {{ copyRatesSkipped.length }} รายการ</p>
              <div class="space-y-1">
                <div
                  v-for="(e, i) in copyRatesSkipped"
                  :key="`skipped-${i}`"
                  class="flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5"
                  :data-test="`copy-rates-skipped-${i}`"
                >
                  <span class="text-[13px] font-bold text-amber-900">{{ e.label }}</span>
                  <span class="text-[12.5px] text-amber-700">{{ e.reason }}</span>
                  <span class="ml-auto text-[12.5px] font-bold text-amber-600">{{ formatRate(e.rate_type, e.rate_value) }}</span>
                </div>
              </div>
            </div>

            <!-- A disabled button with no sentence beside it is the dead end
                 this whole screen exists to remove. -->
            <p v-if="copyRatesPreview.total_to_copy === 0" class="text-[12.5px] font-bold text-slate-500" data-test="copy-rates-empty">
              ไม่มีอะไรให้คัดลอก — บริษัทต้นทางไม่มีอัตราที่ยังใช้ได้ หรืออัตราทุกตัวถูกข้ามตามเหตุผลด้านบน (ของเดิมที่นี่จะไม่ถูกทับ)
            </p>
          </div>
        </div>

        <div class="shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="closeCopyRatesModal">ยกเลิก</button>
          <!-- The count is IN the label: "ยืนยัน" would make the admin trust
               a summary they may have scrolled past, while "คัดลอก 5 รายการ"
               restates what they are approving at the moment they approve it. -->
          <button
            type="button"
            class="btn-primary"
            :disabled="!copyRatesPreview || copyRatesPreview.total_to_copy === 0 || copyRatesCopying || copyRatesPreviewing"
            data-test="copy-rates-confirm"
            @click="confirmCopyRates"
          >
            {{ copyRatesCopying ? 'กำลังคัดลอก...' : `คัดลอก ${copyRatesPreview?.total_to_copy ?? 0} รายการ` }}
          </button>
        </div>
      </div>
    </div>

    <!-- Matrix level rate — opened from step 2's Matrix structure -->
    <div v-if="showLevelRateForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="showLevelRateForm = false">
      <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitLevelRate">
        <div class="shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
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
        <div class="shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
          <!-- TASK-216 r2 — added with the modal conversion: an inline panel
               could be abandoned by scrolling past it, a modal cannot. -->
          <button type="button" class="btn-secondary" @click="showLevelRateForm = false">ยกเลิก</button>
          <button type="submit" :disabled="savingLevelRate" class="btn-primary">
            {{ savingLevelRate ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>
    </div>

    <!-- ขั้นอันดับ (Stairstep) — opened from step 2's อันดับ structure -->
    <div v-if="showRankForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="resetRankForm">
      <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitRank">
        <div class="shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
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
        <div class="shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="resetRankForm">ยกเลิก</button>
          <button type="submit" :disabled="savingRank" class="btn-primary">
            {{ savingRank ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>
    </div>

    <!-- อัตรา Generation — opened from step 2's Generation structure -->
    <div v-if="showGenerationRuleForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="showGenerationRuleForm = false">
      <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitGenerationRule">
        <div class="shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
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
        <div class="shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showGenerationRuleForm = false">ยกเลิก</button>
          <button type="submit" :disabled="savingGenerationRule" class="btn-primary">
            {{ savingGenerationRule ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>
    </div>

    <!-- ลำดับการใช้ค่า — now opened ONLY on request, from step 3's ladder -->
    <div v-if="showResolutionOrderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeResolutionOrderModal">
      <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-5">
        <div class="flex items-center gap-2 mb-2">
          <Icon name="info" :size="18" class="text-brand-600 shrink-0" />
          <p class="text-sm font-bold text-slate-900">ลำดับการใช้ค่าคอมมิชชั่น</p>
        </div>
        <p class="text-xs text-slate-500 mb-4">{{ RESOLUTION_ORDER_NOTE }}</p>
        <div class="flex justify-end">
          <button class="btn-primary" data-test="close-resolution-order" @click="closeResolutionOrderModal">
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
              <!-- Only shown on a PV company: on 'price' the base IS the
                   figure the admin just typed, and repeating it back would be
                   noise. -->
              <p v-if="simulateResult.basis === 'pv'" class="text-xs font-bold text-brand-600 mt-1" data-test="simulate-basis">
                คิดจาก {{ formatSatang(simulateResult.baseSatang) }}
                <span v-if="simulateProduct.pv_satang === null || simulateProduct.pv_satang === undefined" class="text-amber-700">
                  (สินค้านี้ยังไม่ได้กำหนด PV — ระบบใช้ราคาขายไปก่อน)
                </span>
                <span v-else>(PV ของสินค้า)</span>
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
  </main>
</template>
