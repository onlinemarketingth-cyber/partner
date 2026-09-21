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
/*
 * 2026-09-14 — BuddhistDateInput and CalendarDatePicker are no longer imported
 * here. Every date on this screen now goes through EffectivePeriodField, which
 * owns both of them plus the "ใช้ตลอด" default. Importing them directly again
 * would be a way to add a fifth date control that skips that default — which is
 * the thing the owner asked to remove.
 *
 * (The pair itself is unchanged: a real clickable calendar alongside the Thai
 * dropdowns, sharing one v-model — TASK-189 v3 / TASK-199.)
 */
// 2026-09-12 — moved in from its own route; see the step-4 block for why.
import CommissionSplitSettingCard from '@/design-system/components/CommissionSplitSettingCard.vue'
/*
 * 2026-09-14 — "ใช้ตลอด" is the default and the dates are an option, on every
 * commission form (owner). One component rather than four copies of the same
 * two pickers: the pickers were identical everywhere and the RULE about them
 * (what "always" saves, and that an end date drops the product down the ladder
 * rather than stopping payment) is the part that must not drift between forms.
 */
import EffectivePeriodField from '@/design-system/components/EffectivePeriodField.vue'
/*
 * 2026-09-14 — the product x layer table (owner's ข้อเสนอ 2). Fed entirely by
 * GET /commission-resolution; it computes nothing itself. See the component's
 * own docblock for why that is not an implementation detail.
 */
import RateResolutionMatrix, { type ResolutionRow } from '@/design-system/components/RateResolutionMatrix.vue'
/*
 * 2026-09-14 — owner's ข้อเสนอ 3. Lives INSIDE both rate forms and updates as
 * the admin types; see the component's docblock for why it is not a
 * confirmation step like the copy-rates modal.
 */
import RateImpactPreview from '@/design-system/components/RateImpactPreview.vue'
import PlanShapePreview from '@/design-system/components/PlanShapePreview.vue'
/*
 * §7 — a setting whose consequence is real money does not print a paragraph
 * next to the field; it gets an ⓘ. Three of the controls added on 2026-09-19
 * qualify (the per-level rate, compression, and withholding tax), and each of
 * them changes what a real person is paid.
 */
import InfoPopover from '@/design-system/components/InfoPopover.vue'

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
  { step: 2, label: 'เลือกแผนค่าแนะนำ' },
  { step: 3, label: 'ตั้งอัตราสมาชิกผู้ขาย' },
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
   * 2026-09-13 — what THIS company actually charges: its own price override
   * if it set one, the platform price if it did not, and the active promotion
   * above either (ProductResource). Read by step 4's worked example, which
   * must show the same number the server computes commission from — an
   * example built on `price_satang` would quietly contradict the ledger at
   * every company that has ever set its own price.
   */
  effective_price_satang?: number
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
  /*
   * 2026-09-19 — WHICH LEVEL of the chain this rate prices, or null for
   * "every level nobody priced".
   *
   * Null is the catch-all and is what every rate that exists today is, so a
   * company that never uses levels keeps one rate paid all the way up — see
   * the migration. A levelled row beats the catch-all at its own level only;
   * they are not alternatives and a company may sensibly hold both.
   */
  level: number | null
  rate_type: RateType
  rate_value: number
  /*
   * 2026-09-14 — this rate's OWN deduction mode, or null to follow the
   * company's (ขั้นที่ 4's default).
   *
   * Null is sent as null by the API on purpose and must stay null here: the
   * list has to tell "follows the company" from "was deliberately set to
   * บริษัทจ่ายเพิ่ม", because the first one moves when the company changes its
   * mind and the second one does not. Coalescing it on arrival would make
   * every row unable to explain itself.
   */
  override_mode: CommissionOverrideMode | null
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
  // A NEW rate's "ใช้ตลอด" means "starts today"; the carried-forward dates
  // above only matter once the admin opens the ช่วงเวลา option.
  ruleFormFallbackFrom.value = todayIso()
  ruleFormScopeLocked.value = false
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
    // Sliced for the same reason openEditOverrideForm() has always sliced:
    // the API may send a full ISO timestamp, and every consumer of this field
    // — the date input, the string comparison in EffectivePeriodField — wants
    // the calendar day.
    effective_from: r.effective_from.slice(0, 10),
    effective_to: r.effective_to?.slice(0, 10) ?? '',
    renewal_rate_type: r.renewal_rate_type ?? '',
    renewal_rate_value_input: r.renewal_rate_type ? (r.renewal_rate_value ?? 0) / 100 : '',
    renewal_recurs: r.renewal_recurs,
  }
  // "ใช้ตลอด" on an EXISTING rate keeps the day it actually began — editing
  // the percentage must not quietly restamp when the rate started.
  ruleFormFallbackFrom.value = r.effective_from.slice(0, 10)
  ruleFormScopeLocked.value = true
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
    void loadResolution()
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
    // Deleting a rate is the change most likely to drop a product to a
    // broader rung — or to nobody at all. The table has to say so at once.
    void loadResolution()
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
  /*
   * 2026-09-19 — which level this rate prices. '' = every level (the
   * catch-all, and what every rate that exists today is).
   *
   * A string for the same reason override_mode below is: it is bound to an
   * <input>, whose empty value is '', and mapping it to null happens once on
   * submit. Coalescing early would turn "every level" into level 0, which the
   * server would reject as below min:1 — a refusal with no explanation for a
   * field the admin deliberately left blank.
   */
  level: '' as string | number,
  rate_type: 'percentage' as RateType,
  rate_value_input: '' as string | number,
  // '' = follow the company (the default, and where nearly every rate stays).
  // Kept as '' rather than null because it is bound to a <select>, whose empty
  // option value is a string — mapping it to null happens once, on submit.
  override_mode: '' as CommissionOverrideMode | '',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
})

/*
 * 2026-09-14 — what "ใช้ตลอด" resolves `effective_from` to, per form.
 *
 * TODAY while creating; the rule's OWN stored start date while editing. A
 * single shared "today" would re-stamp an existing rate with the current date
 * every time somebody opened it to change the percentage — quietly rewriting
 * when that rate began, which is a fact reports and audit rows are read
 * against. See EffectivePeriodField's docblock.
 *
 * Also the signal the field watches to re-derive its mode: it changes exactly
 * when the parent swaps which record the modal is editing, and never while
 * somebody is picking a date.
 */
const todayIso = (): string => new Date().toISOString().slice(0, 10)
const overrideFormFallbackFrom = ref(todayIso())
/* ── 2026-09-14 — THE SCOPE IS NOT A QUESTION WHEN IT HAS ALREADY BEEN ANSWERED ──
 *
 * Owner: "ผมคลิกเข้ามาที่หน้าหมวดหมู่แล้ว ขอบเขตยังต้องเลือกซ้ำอีกเหรอ
 * มันไม่ควรแล้วนะครับ".
 *
 * He is right, and the redundancy came from the 3-box redesign rather than
 * surviving it: pressing "+ เพิ่มอัตราของหมวดหมู่" in 3.2, or a cell in the
 * resolution table, ANSWERS the scope question. Re-asking it in the first
 * field of the form does three bad things at once — it takes the most
 * prominent slot for a decision already made, it reads as "you must choose
 * again", and it is the one field that can silently undo the box the admin
 * thought they were working in.
 *
 * So the scope becomes a STATEMENT with a "เปลี่ยน" escape hatch. Not simply
 * hidden: an admin who opened the wrong box must be able to correct it
 * without cancelling and hunting for the right button — a form that locks you
 * out of your own mistake is the dead end this whole screen keeps removing.
 */
/**
 * The deepest level a company may price a rate for.
 *
 * Mirrors StoreCommissionOverrideRuleRequest::MAX_PRICEABLE_LEVEL exactly. A
 * sanity ceiling, NOT a business rule (BR-7 does not apply): no published plan
 * pays a hundred levels, and the number exists so a typo of "1000" is refused
 * as a typo rather than stored as a ladder nobody can read.
 */
const MAX_PRICEABLE_LEVEL = 100

const overrideFormScopeLocked = ref(false)
const ruleFormScopeLocked = ref(false)
const ruleFormFallbackFrom = ref(todayIso())
const levelRateFormFallbackFrom = ref(todayIso())
const generationRuleFormFallbackFrom = ref(todayIso())

function resetOverrideForm(): void {
  showOverrideForm.value = false
  editingOverrideId.value = null
  overrideFormError.value = ''
  overrideForm.value = {
    scope: 'company',
    product_id: '',
    product_category_id: '',
    level: '',
    rate_type: 'percentage',
    rate_value_input: '',
    override_mode: '',
    effective_from: todayIso(),
    effective_to: '',
  }
  overrideFormFallbackFrom.value = todayIso()
  overrideFormScopeLocked.value = false
}

const ruleScopeLabels: Record<RuleScope, string> = {
  company: 'ค่าเริ่มต้นทั้งบริษัท',
  category: 'ตามหมวดหมู่สินค้า',
  product: 'ตามสินค้า',
}

/*
 * 2026-09-14 — openCreateOverrideForm() (no scope) WAS HERE AND IS GONE.
 *
 * It was the last caller that opened a rate form without answering the scope,
 * and it had no caller left of its own: splitting the rate box into three gave
 * every + button a scope, and the resolution table's cells carry one too.
 *
 * Deleted rather than kept "just in case", deliberately. A dead opener that
 * leaves the scope unlocked is exactly what somebody wires a new button to six
 * months from now, quietly reintroducing the thing the owner reported twice in
 * one day — a form whose most prominent field can move a rate out of the box
 * it was opened from.
 */

/**
 * 2026-09-14 — open the leader-rate form already pointed at one scope.
 *
 * Owner: "แยกเป็น 3 กล่อง". Each of 4.3 / 4.4 / 4.5 owns one layer of the
 * ladder and has its own + button, so the scope is answered by WHICH BUTTON
 * was pressed rather than by a dropdown the admin has to notice. Mirrors
 * openCreateRuleFormWithScope() on the agent side exactly.
 */
function openCreateOverrideFormWithScope(scope: RuleScope): void {
  resetOverrideForm()
  overrideForm.value.scope = scope
  overrideFormScopeLocked.value = true
  showOverrideForm.value = true
}

function openEditOverrideForm(r: CommissionOverrideRuleItem): void {
  editingOverrideId.value = r.id
  overrideFormError.value = ''
  overrideForm.value = {
    scope: r.product ? 'product' : r.product_category ? 'category' : 'company',
    product_id: r.product?.id ?? '',
    product_category_id: r.product_category?.id ?? '',
    // null → '' so the field reads "every level" rather than 0, which is the
    // one value the server refuses (min:1).
    level: r.level ?? '',
    rate_type: r.rate_type,
    // Both units are stored ×100 (basis points / satang), so one inverse
    // covers both — same asymmetry rateValueToBasisOrSatang() relies on.
    rate_value_input: r.rate_value / 100,
    override_mode: r.override_mode ?? '',
    effective_from: r.effective_from.slice(0, 10),
    effective_to: r.effective_to?.slice(0, 10) ?? '',
  }
  overrideFormFallbackFrom.value = r.effective_from.slice(0, 10)
  overrideFormScopeLocked.value = true
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
  /*
   * 2026-09-19 — the level, validated HERE as well as on the server.
   *
   * The server's min:1/max:100 refusal arrives as a field error under a
   * different name than the one the admin typed into on some browsers, and
   * "0" is the value somebody reaches for when they mean "all levels" — which
   * this form spells as empty. Saying so here, before the request, is the
   * difference between a corrected field and an admin retyping a percentage.
   */
  const levelRaw = String(overrideForm.value.level ?? '').trim()
  let level: number | null = null

  if (levelRaw !== '') {
    const parsed = Number(levelRaw)

    if (!Number.isInteger(parsed) || parsed < 1 || parsed > MAX_PRICEABLE_LEVEL) {
      overrideFormError.value = `ชั้นต้องเป็นจำนวนเต็ม 1–${MAX_PRICEABLE_LEVEL} · เว้นว่าง = ใช้กับทุกชั้น`

      return
    }

    level = parsed
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
      // Same explicit-null rule: an edit that turns a levelled rate back into
      // the catch-all has to clear the column.
      level,
      rate_type: overrideForm.value.rate_type,
      rate_value: rateValueToBasisOrSatang(overrideForm.value.rate_type, overrideForm.value.rate_value_input),
      /*
       * Explicit null for the same reason as the scope columns above: an edit
       * that puts a rate BACK onto the company's setting has to clear the
       * column, and an absent key would leave the old mode in place.
       *
       * 2026-09-19 — and ALWAYS null on a levelled rate, which the server
       * refuses to accept alongside a level (prohibitedIf). One walk up one
       * chain has one funding model; letting level 2 deduct from the seller
       * while level 1 was paid by the company would make the seller's own row
       * depend on how deep their upline happened to go. The control is hidden
       * in that case, but a stale value left in the form state would still be
       * sent, so it is cleared here rather than trusted to be untouched.
       */
      override_mode: level === null ? overrideForm.value.override_mode || null : null,
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
    // The table is the consequence of what was just saved; leaving it stale
    // would make the one screen built to show the outcome show the previous
    // outcome.
    void loadResolution()
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
    void loadResolution()
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

/*
 * 2026-09-14 — productOwnRule() AND openRuleFormForProduct() WERE HERE.
 *
 * They belonged to the per-product card list in 3.3, which the resolution
 * table absorbed on the same day. Their whole job — "open this product's own
 * rate if it has one, otherwise create one with the scope locked to สินค้า" —
 * is now editFromMatrix('agent', …) with `layer: 'product'`, which gets the
 * rule id from the server's own ladder instead of re-resolving it here.
 *
 * That is the point of the deletion, not tidiness: productOwnRule() was a
 * FOURTH copy of the resolution ladder living in the browser, and the reason
 * this screen asks the server for that table at all is that a browser copy
 * once displayed and paid a Thai Life rate on AIA. Do not reintroduce one.
 */
/** Step 3's two add buttons ("+ เพิ่มอัตราของสินค้า" / "…ของหมวดหมู่"). */
function openCreateRuleFormWithScope(scope: RuleScope) {
  resetRuleForm()
  ruleForm.value.scope = scope
  ruleFormScopeLocked.value = true
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

/**
 * Does a company-wide leader rate exist today — the row every product falls
 * back to when nothing narrower matches?
 *
 * Read by the rate form to say what a product- or category-scoped rate leaves
 * uncovered. Deliberately NOT used to disable the narrower scopes: paying the
 * leader on one product and nothing else is a real plan, and step 4 is
 * optional by design.
 */
const hasCompanyWideLeaderRate = computed<boolean>(() =>
  activeOverrideRules.value.some((r) => !r.product && !r.product_category))

/* ── 2026-09-14 — ONE BOX PER LAYER OF THE LADDER (step 4.3 / 4.4 / 4.5) ──
 *
 * Owner: "การตั้งค่าใน Step ที่ 4 ต้องต่างกันทั้งหมด แต่ตอนนี้เป็นตัวเลือกการ
 * ทำงานแบบอย่างเดียว" and "การตั้งค่าแบบหมวดสินค้า ผมแทบไม่เห็นใน UI เลย".
 *
 * Both complaints are the same defect seen from two sides. The three scopes
 * are a LADDER the server walks — product, then category, then company — and
 * the screen rendered them as one undifferentiated list produced by one
 * dropdown. You could not see which layer you had configured, which layer was
 * empty, or which layer a given row belonged to without reading its label.
 *
 * Split into three lists in ladder order (broadest first, because that is the
 * order they are SET in), each with its own + button that pre-picks its scope.
 *
 * Deliberately NOT filtered to active rows: an expired or not-yet-started rate
 * still has to be findable to be edited or deleted, and hiding it is how a
 * company ends up with a rate nobody can see that starts paying next month.
 * The row itself says its dates.
 */
/*
 * 2026-09-21 — the CATCH-ALL rows only, for step 4's box 4.3.
 *
 * The levelled company-wide rows moved to step 2, where they are edited
 * beside the diagram that shows what they pay. Leaving them listed here as
 * well would be two doors onto one set of rows — the thing this screen keeps
 * removing everywhere else — and worse than a duplicate list: the two forms
 * have different shapes (a ladder you save as a whole, versus one row at a
 * time), so the same rate would be editable under two different sets of
 * rules.
 *
 * Under a plan that has no ladder (anything but Unilevel) there is nothing in
 * step 2 to hold them, so they stay here and this filter lets them through.
 */
const companyLeaderRules = computed<CommissionOverrideRuleItem[]>(() => {
  const rows = byCompany(commissionOverrideRules.value).filter((r) => !r.product && !r.product_category)

  return companyPlanType.value === 'unilevel' ? rows.filter(isCatchAllLevel) : rows
})

const categoryLeaderRules = computed<CommissionOverrideRuleItem[]>(() =>
  byCompany(commissionOverrideRules.value).filter((r) => !r.product && !!r.product_category))

const productLeaderRules = computed<CommissionOverrideRuleItem[]>(() =>
  byCompany(commissionOverrideRules.value).filter((r) => !!r.product))

/**
 * What this row's money actually comes from, said in full.
 *
 * A row that FOLLOWS the company says so and names what it is following, so
 * the badge stays true when the company's own setting changes — printing only
 * the resolved mode would make an inherited row look like a decision somebody
 * made about it.
 */
/** The row's own subject, without repeating the box's title around it. */
function leaderRowLabel(r: CommissionOverrideRuleItem): string {
  if (r.product) return r.product.name
  if (r.product_category) return r.product_category.name

  return 'ทุกสินค้าที่ไม่ได้ตั้งอัตราเฉพาะ'
}

/**
 * 2026-09-19 — 'ชั้นที่ 2' / 'ทุกชั้น', for the badge on a leader-rate row.
 *
 * Every row gets one, including the catch-all. A screen that labelled only
 * the levelled rows would leave an admin reading the unlabelled ones as
 * "level 1" — which is the opposite of what a null level means, and the
 * difference between paying one person and paying the whole chain.
 */
function leaderLevelLabel(r: CommissionOverrideRuleItem): string {
  return isCatchAllLevel(r) ? 'ทุกชั้น' : `ชั้นที่ ${r.level}`
}

/**
 * 2026-09-21 — "this rate applies at EVERY level", answered once.
 *
 * `== null`, not `=== null`, and the difference is not style. A row whose
 * payload predates the `level` column — a cached response, an older deploy
 * mid-rollout, any client that has not been reloaded — arrives with the key
 * ABSENT, so `r.level` is `undefined`. Compared with `=== null` that row reads
 * as LEVELLED, and `level ?? 0` then prints it as "ชั้นที่ 0": a catch-all
 * rate, which pays the whole chain, displayed and treated as a rung that
 * pays nobody.
 *
 * It also made the row disappear from step 4's box 4.3 while not appearing in
 * step 2's ladder either — a live rate visible on neither screen, which is how
 * somebody concludes their rates were deleted.
 */
function isCatchAllLevel(r: CommissionOverrideRuleItem): boolean {
  return r.level === null || r.level === undefined
}

/**
 * The three boxes, in the order they are SET in (broadest first) rather than
 * the order the server RESOLVES in (narrowest first).
 *
 * Those orders are opposite and both are correct: resolution has to try the
 * most specific match first, and a human has to lay the floor before laying
 * the exceptions on top of it. The ladder note under the heading says the
 * resolution order out loud so the difference never has to be inferred.
 *
 * A data structure rather than three copies of the same markup: the three
 * differ only in title, hint and which rows they hold, and three hand-written
 * copies is how one of them quietly stops getting a fix the other two got.
 */
const leaderRateGroups = computed(() => [
  {
    scope: 'company' as RuleScope,
    number: '4.3',
    title: 'ค่าเริ่มต้นทั้งบริษัท',
    /*
     * 2026-09-21 — the hint names where the levelled rates went.
     *
     * Under Unilevel this box now lists only the catch-all ("ทุกชั้น") rows;
     * the per-level ladder is edited on step 2 beside the diagram. An admin
     * who set 10/5/3 there and then found this box apparently empty would
     * reasonably conclude their rates had been lost, so the box says where
     * they are rather than leaving the reader to work it out.
     */
    hint: levelLadderApplies.value
      ? 'ใช้กับทุกชั้นที่ยังไม่ได้ตั้งอัตราเฉพาะชั้น — อัตราแยกรายชั้น (10/5/3) ตั้งที่ขั้นที่ 2 ข้างแผนภูมิ'
      : 'ใช้กับสินค้าทุกตัวที่ไม่ได้ตั้งอัตราเฉพาะไว้ — ตั้งอันนี้อันเดียวก็ครอบคลุมทั้งบริษัท',
    empty: levelLadderApplies.value
      ? 'ยังไม่ได้ตั้งอัตราแบบใช้ทุกชั้น — ชั้นที่ตั้งอัตราไว้เฉพาะ (ที่ขั้นที่ 2) ยังจ่ายตามปกติ'
      : 'ยังไม่ได้ตั้ง — หัวหน้าทีมจะได้ค่าแนะนำเฉพาะสินค้า/หมวดหมู่ที่ตั้งไว้ข้างล่างเท่านั้น',
    rows: companyLeaderRules.value,
  },
  {
    scope: 'category' as RuleScope,
    number: '4.4',
    title: 'ตามหมวดหมู่สินค้า',
    hint: 'ใช้กับทุกสินค้าในหมวดนั้น และทับค่าเริ่มต้นทั้งบริษัท',
    empty: 'ยังไม่ได้ตั้ง — ทุกหมวดใช้ค่าเริ่มต้นทั้งบริษัท',
    rows: categoryLeaderRules.value,
  },
  {
    scope: 'product' as RuleScope,
    number: '4.5',
    title: 'ตามสินค้า',
    hint: 'ใช้กับสินค้าตัวนั้นตัวเดียว และทับทั้งหมวดหมู่และค่าเริ่มต้น',
    empty: 'ยังไม่ได้ตั้ง — ทุกสินค้าใช้ค่าของหมวดหมู่หรือค่าเริ่มต้น',
    rows: productLeaderRules.value,
  },
])

function overrideModeLabelFor(r: CommissionOverrideRuleItem): string {
  return r.override_mode === null
    ? `ตามค่าเริ่มต้นบริษัท (${overrideModeLabels[overrideMode.value]})`
    : overrideModeLabels[r.override_mode]
}

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
    return { level: 'bad', message: 'ยังไม่มีอัตราค่าแนะนำ (ทั้งสินค้า/หมวดหมู่/บริษัท) — ดีลที่ปิดได้จะไม่มีใครได้เงินเลย' }
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
    return { level: 'bad', message: `บริษัทยังไม่ได้ตั้งค่าโครงสร้าง ${planTypeLabels[plan]} — สมาชิกผู้ขายได้ แต่ชั้นบนจะไม่ได้อะไร` }
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
  // Sellable only, so this breakdown and the server's banner count the same
  // things — see sellableProducts().
  for (const p of sellableProducts.value) c[productReadiness(p).level]++

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
    overrideMode.value = 'additive'
    overrideModeUnknown.value = false
    deepestManagerChain.value = 0
    maxOverrideDepth.value = ''
    overrideCompression.value = false
    houseAccount.value = null
    uncertifiedLeaders.value = EMPTY_LEADER_WARNING

    return
  }
  try {
    const r = await api.get<{
      data: {
        commission_basis?: CommissionBasis
        commission_plan_type?: CommissionPlanType | null
        commission_override_mode?: CommissionOverrideMode
        deepest_manager_chain?: number
        max_override_depth?: number | null
        override_compression?: boolean
        commission_house_account?: HouseAccount | null
        leaders_missing_certification?: LeaderWarning
      }
    }>(`/commission-settings${companyQuery()}`)
    commissionBasis.value = r.data.commission_basis ?? 'price'
    companyPlanTypeFromServer.value = r.data.commission_plan_type ?? null
    basisUnknown.value = false
    overrideMode.value = r.data.commission_override_mode ?? 'additive'
    // null → '' — "no cap" is a real setting and the field must render empty
    // for it, never a 0 that a later save would send back as "pay nobody".
    maxOverrideDepth.value = r.data.max_override_depth ?? ''
    overrideCompression.value = r.data.override_compression === true
    // Server-computed and never inferred here: the screen shows the maximum
    // leader rate from this number, and a guess would print a ceiling the
    // save-time refusal then disagrees with.
    deepestManagerChain.value = r.data.deepest_manager_chain ?? 0
    houseAccount.value = r.data.commission_house_account ?? null
    uncertifiedLeaders.value = r.data.leaders_missing_certification ?? EMPTY_LEADER_WARNING
    overrideModeUnknown.value = false
  } catch {
    // Left NULL, not defaulted: the screen does not know the plan, and
    // companyPlanType falls back to the inference rather than asserting one
    // nobody confirmed.
    companyPlanTypeFromServer.value = null
    basisUnknown.value = true
    // Same reason, one card further on — see overrideModeUnknown.
    overrideModeUnknown.value = true
    deepestManagerChain.value = 0
    // Cleared, not left stale: an empty depth field means "no cap" on this
    // screen, so leaving the previous company's number would be worse, and
    // keeping this company's unread value would be a claim nobody verified.
    // overrideModeUnknown already puts the whole card into its loud state.
    maxOverrideDepth.value = ''
    overrideCompression.value = false
    houseAccount.value = null
    /*
     * Cleared, never left stale. A warning naming people is a warning an
     * admin acts on, and repeating one from a company they have since
     * navigated away from would send them to certify the wrong person.
     */
    uncertifiedLeaders.value = EMPTY_LEADER_WARNING
  }
}

/* ═══════════════════════════════════════════════════════════════════════
 * 2026-09-14 — WHAT EACH PRODUCT ACTUALLY PAYS, FROM THE SERVER.
 *
 * Owner: "การ setup 3 ระดับ … ส่งผลต่อคำนวณค่าคอมตอน setting ทำได้ไม่ชัดเจน".
 *
 * The three edit boxes say what has been SET. This says what HAPPENS — per
 * product, per rung, in baht. It is a separate request rather than a computed
 * over `commissionRules` for one reason: this screen already owns a JavaScript
 * copy of the resolution ladder (`resolveRuleFor`), and the last time that copy
 * disagreed with the server's, a rate belonging to Thai Life was displayed and
 * PAID on AIA. A table people read to decide what agents earn is answered by
 * the code that pays them.
 * ═══════════════════════════════════════════════════════════════════════ */
const resolution = ref<{
  commission_basis?: CommissionBasis
  max_override_per_level_satang?: number | null
  products: ResolutionRow[]
} | null>(null)
const resolutionLoading = ref(false)
/** Same loud-failure rule as the basis: no table beats a table that guesses. */
const resolutionFailed = ref(false)

async function loadResolution(): Promise<void> {
  if (!effectiveCompanyId.value) {
    resolution.value = null
    resolutionFailed.value = false

    return
  }

  resolutionLoading.value = true
  try {
    const r = await api.get<{ data: typeof resolution.value }>(`/commission-resolution${companyQuery()}`)
    resolution.value = r.data
    resolutionFailed.value = false
  } catch {
    resolution.value = null
    resolutionFailed.value = true
  } finally {
    resolutionLoading.value = false
  }
}

const resolutionRows = computed<ResolutionRow[]>(() => resolution.value?.products ?? [])

/**
 * ── THE BRIDGE BETWEEN THE TWO LISTS OF PRODUCTS (2026-09-14) ──
 *
 * The table's rows come from /commission-resolution, because its numbers must
 * come from the code that pays. The controls that moved INTO those rows — the
 * selling switch, ทดสอบคำนวณ, the readiness note — need the catalogue row from
 * /products instead, because that is where `permissions`, `is_shared` and the
 * effective plan type live.
 *
 * One lookup by id, and every one of those controls goes through it. The
 * alternative — teaching the resolution endpoint to carry permissions too —
 * would give this screen two services answering "may I" and is how the mirror
 * problems in this file started.
 */
const productsById = computed(() => new Map(byCompany(products.value).map((p) => [p.id, p] as const)))

function productForRow(row: ResolutionRow): ProductOption | null {
  return productsById.value.get(row.product_id) ?? null
}

/**
 * Which rows' switches this viewer may move. Sent as ids rather than a
 * predicate so the table stays a design-system component that knows nothing
 * about ADR-040 or ProductPolicy — see canToggleSelling() for what it does
 * know.
 */
const togglableProductIds = computed(() =>
  byCompany(products.value).filter((p) => canToggleSelling(p)).map((p) => p.id))

/**
 * Which rows may carry a product-scoped rate — the server's per-row answer,
 * not the viewer's role (TASK-245: a catalog-LINKED product is rated centrally
 * and the write is refused, ADR-036 §5/§6).
 *
 * This is the same question the old per-row "+ ตั้งอัตราเฉพาะสินค้านี้" button
 * asked before it was drawn. It has to survive the merge or the table offers a
 * 403 once per catalogue-linked product.
 */
const rateableProductIds = computed(() =>
  byCompany(products.value).filter((p) => canSetCommission(p)).map((p) => p.id))

function toggleSellingFromMatrix(row: ResolutionRow): void {
  const product = productForRow(row)
  if (product) void toggleSelling(product)
}

function simulateFromMatrix(row: ResolutionRow): void {
  const product = productForRow(row)
  if (product) openSimulate(product)
}

/*
 * ── THE PER-PRODUCT NOTES, ONE CALL EACH ──
 *
 * These four exist so the opened row's template asks each question once
 * instead of calling productForRow() six times per note. They return null for
 * "nothing to say", which is the state most rows are in most of the time.
 */

/** Which plan structure pays this product, and whether it chose that itself. */
function rowPlanLabel(row: ResolutionRow): string {
  const p = productForRow(row)
  if (!p?.effective_plan_type) return '—'

  return planTypeLabels[p.effective_plan_type] + (p.commission_plan_type ? '' : ' (สืบทอดจากบริษัท)')
}

/**
 * An expired rate is not the same gap as a missing one — it carries a DATE
 * nothing else on this screen knows, and it sends the admin to a row that
 * needs one field changed instead of to a duplicate. Null once a live rate
 * resolves, because then the expiry is history.
 */
function rowExpiredRule(row: ResolutionRow): CommissionRuleItem | null {
  const p = productForRow(row)
  if (!p || resolveRuleFor(p)) return null

  return expiredRuleFor(p)
}

function rowReadiness(row: ResolutionRow): { level: ReadinessLevel; message: string } | null {
  const p = productForRow(row)

  return p ? productReadiness(p) : null
}

/**
 * The plan tab whose STRUCTURE this product is still missing, or null.
 *
 * Also null when step 2 is unreachable: this panel is step 3, so the offer to
 * jump is made from inside a step that may itself be incomplete. goToStep()
 * would refuse it, and a button drawn dead is worse than one not drawn — the
 * readiness message beside it already names the gap.
 */
function rowStructureGap(row: ResolutionRow): CommissionPlanType | null {
  const plan = productForRow(row)?.effective_plan_type ?? null
  if (!plan || !planTypeToTab[plan] || structureReady.value[plan] !== false || !stepReachable.value[2]) return null

  return plan
}

/**
 * Open the right rate form for a cell the admin clicked in the table.
 *
 * Reuses the EXISTING modals rather than editing in place. A rate is not one
 * number — it carries a rate type, a period and (for the leader) a deduction
 * mode, and an inline cell that saved only the percentage would quietly reset
 * the rest. The click's job is to remove the navigation, not the form.
 */
function editFromMatrix(kind: 'agent' | 'leader', payload: { layer: 'company' | 'category' | 'product'; row: ResolutionRow; ruleId: number | null }): void {
  const { layer, row, ruleId } = payload
  const existing = ruleId === null
    ? null
    : (kind === 'agent'
      ? byCompany(commissionRules.value).find((r) => r.id === ruleId)
      : byCompany(commissionOverrideRules.value).find((r) => r.id === ruleId))

  if (kind === 'agent') {
    if (existing) { openEditRuleForm(existing as CommissionRuleItem); return }
    openCreateRuleFormWithScope(layer)
    if (layer === 'product') ruleForm.value.product_id = row.product_id
    if (layer === 'category' && row.category) ruleForm.value.product_category_id = row.category.id

    return
  }

  if (existing) { openEditOverrideForm(existing as CommissionOverrideRuleItem); return }
  openCreateOverrideFormWithScope(layer)
  if (layer === 'product') overrideForm.value.product_id = row.product_id
  if (layer === 'category' && row.category) overrideForm.value.product_category_id = row.category.id
}

/*
 * The two forms' preview inputs, converted exactly as their SAVE converts them.
 *
 * `rateValueToBasisOrSatang` is reused rather than re-derived on purpose: a
 * preview that rounded differently from the write would be wrong about the one
 * number the admin is reading it for, and basis points versus percent is the
 * easiest place in this file to be off by 100.
 *
 * NULL while the field is empty, which is what keeps the panel quiet instead of
 * previewing a rate of zero that nobody typed.
 */
function previewRateValue(rateType: RateType, input: string | number): number | null {
  const trimmed = String(input).trim()
  if (trimmed === '') return null
  const value = rateValueToBasisOrSatang(rateType, input)

  return Number.isFinite(value) ? value : null
}

const ruleFormPreviewValue = computed(() => previewRateValue(effectiveRuleFormRateType.value, ruleForm.value.rate_value_input))
const overrideFormPreviewValue = computed(() => previewRateValue(overrideForm.value.rate_type, overrideForm.value.rate_value_input))

/** A scope that names nothing yet cannot be previewed without inventing one. */
const ruleFormPreviewReady = computed(() =>
  ruleFormPreviewValue.value !== null
  && (ruleForm.value.scope !== 'product' || !!ruleForm.value.product_id)
  && (ruleForm.value.scope !== 'category' || !!ruleForm.value.product_category_id))

const overrideFormPreviewReady = computed(() =>
  overrideFormPreviewValue.value !== null
  && (overrideForm.value.scope !== 'product' || !!overrideForm.value.product_id)
  && (overrideForm.value.scope !== 'category' || !!overrideForm.value.product_category_id))

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

/* ═══════════════════════════════════════════════════════════════════════
 * 2026-09-13 — WHERE THE TEAM LEADER'S MONEY COMES FROM (step 4).
 *
 * Owner, verbatim: "จุดที่คนเข้าใจผิดบ่อยที่สุด — 2% ไม่ได้หักจาก 300 ของ
 * สมชาย · เรื่องนี้ต้องทำให้ชัดเจน และปรับได้ทั้งหักจากสมชายปิดการขาย และ
 * บริษัทจ่ายเพิ่ม [ทำ UI ให้ผู้ใช้เข้าใจก่อนเลือกแบบใดแบบหนึ่ง]".
 *
 * The misunderstanding is real and it is arithmetic, not wording: a 2% leader
 * rate against a 3% seller rate produces THREE different answers depending on
 * what the 2% is 2% OF and who funds it, and two of those answers differ by
 * 33x on identical inputs. Nothing on this screen said which one was in force.
 *
 * So the choice is explicit (App\Enums\CommissionOverrideMode) and the UI
 * shows all three ANSWERS, in baht, computed from this company's own rates,
 * BEFORE anybody picks one. A mode selector that only named the modes would
 * have reproduced the same misunderstanding with more words.
 * ═══════════════════════════════════════════════════════════════════════ */
type CommissionOverrideMode = 'additive' | 'deduct_from_sale' | 'deduct_from_commission'
const overrideMode = ref<CommissionOverrideMode>('additive')
/**
 * Same defence as `basisUnknown`, for the same reason and from the same
 * request: this decides whether a seller's commission is reduced, and a
 * screen that rendered the default as the SELECTED answer after a failed
 * read would state a wrong answer about somebody's pay with no way to tell.
 */
const overrideModeUnknown = ref(false)
/** Server-computed (OverrideDeductionGuard::deepestChain) — never guessed here. */
const deepestManagerChain = ref(0)
const overrideModeSaving = ref(false)
const overrideModeError = ref('')

/* ═══════════════════════════════════════════════════════════════════════
 * 2026-09-15 — 4.2 · WHO RECEIVES THE LEADER'S SHARE.
 *
 * Owner: "หัวหน้าทีมในที่นี้มีได้ 2 ความหมาย คือหัวหน้าทีมที่เป็น user จริงในระบบ
 * กับหัวหน้าทีมที่เป็นตัวบริษัทเองที่ได้ค่าคอมจากการขาย เช่น Thailife".
 *
 * Until now a leader override could only reach a human up the manager chain,
 * so an agent with nobody above them kept the whole commission and the company
 * earned no share of their sales. The company can now take a seat at the TOP
 * of its own hierarchy — a real users row, so the payout walk reaches it
 * without learning a second kind of recipient (see
 * CommissionHouseAccountService for why that shape was chosen over three
 * others).
 *
 * THIS BOX SITS BETWEEN THE MODE AND THE RATES, and that order is the whole
 * reason it is 4.2: "where does the money come from" → "who receives it" →
 * "how much". Put it after the rates and an admin types a percentage before
 * knowing who it is for.
 * ═══════════════════════════════════════════════════════════════════════ */
interface HouseAccount {
  id: number
  name: string
  /** How many agents report directly to the seat — 0 means it earns nothing. */
  agents_under: number
  /** Every satang ever credited to it, paid or not. See the Service. */
  earned_satang: number
  /*
   * 2026-09-15 (ครั้งที่สอง) — where the company's own share is transferred.
   *
   * Owner: "ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย". The seat is a payee now, and
   * these are the only three fields that make it payable — it has no identity
   * document and never will, so hasCompletePayoutDetails() asks it for these
   * alone. Edited HERE rather than on the payout screen because the seat is
   * not a person: PUT /users/{id} refuses that row, and always will.
   */
  bank_name: string | null
  bank_account_number: string | null
  bank_account_holder_name: string | null
  /** The SERVER's answer to "could this be paid right now" — never re-derived. */
  payout_details_complete: boolean
}

/* ═══════════════════════════════════════════════════════════════════════
 * 2026-09-15 — THE LEADERS THIS PLAN WILL PAY NOTHING.
 *
 * ADR-035: a cert tier is a GATE on being paid an override. A manager who has
 * never passed one is skipped by the payout walk — no error, no log line, no
 * ledger row. So an admin can finish every box on this screen, watch a sale
 * complete, and find the leader was not paid, with nothing anywhere saying
 * why.
 *
 * That is the same SHAPE as the bug the owner reported this morning
 * ("ค่าคอมตัวแทนไม่ได้คำนวณการตัดให้หัวหน้าทีม"), which turned out to be a
 * company with no manager chain — a fact this screen already stated, which is
 * why it was answerable in minutes. This cause stated nothing.
 *
 * Server-computed, never derived here: the definition of "has a
 * certification" has to be the payout's definition, and a second one written
 * in TypeScript is a second one to forget.
 * ═══════════════════════════════════════════════════════════════════════ */
interface LeaderWarning {
  /** Everybody affected — the list below is capped, this is not. */
  total: number
  leaders: Array<{ id: number; name: string; agents_under: number }>
}

const EMPTY_LEADER_WARNING: LeaderWarning = { total: 0, leaders: [] }
const uncertifiedLeaders = ref<LeaderWarning>(EMPTY_LEADER_WARNING)
/** How many names are listed before the box stops and gives a count instead. */
const namedLeaders = computed(() => uncertifiedLeaders.value.leaders)
const unnamedLeaderCount = computed(() =>
  Math.max(0, uncertifiedLeaders.value.total - uncertifiedLeaders.value.leaders.length),
)

const houseAccount = ref<HouseAccount | null>(null)
const houseAccountSaving = ref(false)
const houseAccountError = ref('')
const showHouseAccountConfirm = ref(false)
const houseAccountName = ref('')

function openHouseAccountConfirm(): void {
  houseAccountError.value = ''
  // The company's own name is what nine admins in ten want on the row, and
  // pre-filling it means the common case is one button rather than a form.
  houseAccountName.value = activeCompany.companies.find((c) => c.id === effectiveCompanyId.value)?.name ?? ''
  showHouseAccountConfirm.value = true
}

/**
 * Give the company a seat, or take it away.
 *
 * Both answers carry the WHOLE commission setting back, because switching
 * either way moves `deepest_manager_chain` — every agent with no upline gains
 * one — and that number is the ceiling 4.3–4.5 offer as a maximum. Refreshing
 * the resolution table too, since what a leader is paid on each product has
 * just changed from "nobody" to "the company".
 */
async function saveHouseAccount(enable: boolean): Promise<void> {
  if (houseAccountSaving.value || !effectiveCompanyId.value) return

  houseAccountSaving.value = true
  houseAccountError.value = ''
  try {
    const body = withCompanyBody(enable ? { display_name: houseAccountName.value.trim() || null } : {})
    const r = enable
      ? await commissionApi.post<{ data: { commission_house_account?: HouseAccount | null; deepest_manager_chain?: number } }>('/commission-house-account', body)
      : await commissionApi.delete<{ data: { commission_house_account?: HouseAccount | null; deepest_manager_chain?: number } }>('/commission-house-account', body)

    houseAccount.value = r.data.commission_house_account ?? null
    deepestManagerChain.value = r.data.deepest_manager_chain ?? 0
    showHouseAccountConfirm.value = false
    void loadResolution()
  } catch (e) {
    houseAccountError.value = apiErrorMessage(e, enable ? 'เปิดบัญชีบริษัทไม่สำเร็จ' : 'ปิดบัญชีบริษัทไม่สำเร็จ')
  } finally {
    houseAccountSaving.value = false
  }
}

/**
 * 2026-09-15 — RENAMING THE SEAT.
 *
 * Every guard this feature added points the same way: the seat is not a
 * person, so UserPolicy refuses to update it and UserService refuses to reset
 * its password, move it or deactivate it. Those refusals are right, and
 * between them they also closed the one edit that is legitimate — fixing a
 * name typed wrong at setup, which otherwise sits on every payout row
 * forever with no way back except deleting the seat and splitting the
 * company's history across two payees.
 *
 * So there is a door, PUT /commission-house-account, and it is one field
 * wide. Nothing here can change who reports to the seat, what it is paid, or
 * whether anyone can sign in as it.
 */
const renamingHouseAccount = ref(false)
const houseAccountRename = ref('')

/*
 * 2026-09-15 (ครั้งที่สอง) — and the account the company is paid into.
 *
 * The same door, widened by three fields rather than given a second one: they
 * are saved in the same PUT as the name, so the panel has one save button and
 * the screen cannot end up holding a name that was written and a bank account
 * that was not.
 */
const houseAccountBank = ref({ bank_name: '', bank_account_number: '', bank_account_holder_name: '' })

function openHouseAccountRename(): void {
  if (!houseAccount.value) return
  houseAccountError.value = ''
  houseAccountRename.value = houseAccount.value.name
  houseAccountBank.value = {
    bank_name: houseAccount.value.bank_name ?? '',
    bank_account_number: houseAccount.value.bank_account_number ?? '',
    bank_account_holder_name: houseAccount.value.bank_account_holder_name ?? '',
  }
  renamingHouseAccount.value = true
}

async function renameHouseAccount(): Promise<void> {
  if (houseAccountSaving.value || !effectiveCompanyId.value) return

  houseAccountSaving.value = true
  houseAccountError.value = ''
  try {
    const r = await commissionApi.put<{ data: { commission_house_account?: HouseAccount | null; deepest_manager_chain?: number } }>(
      '/commission-house-account',
      withCompanyBody({
        display_name: houseAccountRename.value.trim(),
        // Trimmed to null rather than '' so clearing a field actually clears
        // it — the Service treats a blank string as "remove this", and an
        // account typed into the wrong company has to be removable.
        bank_name: houseAccountBank.value.bank_name.trim() || null,
        bank_account_number: houseAccountBank.value.bank_account_number.trim() || null,
        bank_account_holder_name: houseAccountBank.value.bank_account_holder_name.trim() || null,
      }),
    )
    houseAccount.value = r.data.commission_house_account ?? null
    renamingHouseAccount.value = false
    // The name is what the resolution table and the payout screens print for
    // this payee, so the table below is repainted rather than left showing
    // the old label next to the new one.
    void loadResolution()
  } catch (e) {
    houseAccountError.value = apiErrorMessage(e, 'เปลี่ยนชื่อบัญชีบริษัทไม่สำเร็จ')
  } finally {
    houseAccountSaving.value = false
  }
}

const overrideModeOptions: Array<{
  value: CommissionOverrideMode
  title: string
  oneLine: string
  detail: string
}> = [
  {
    value: 'additive',
    title: 'บริษัทจ่ายเพิ่ม',
    oneLine: 'สมาชิกที่ปิดการขายได้เต็ม · หัวหน้าทีมได้เพิ่มจากบริษัท',
    detail: 'ค่าแนะนำของหัวหน้าทีมเป็นต้นทุนใหม่ของบริษัท ไม่ไปแตะค่าแนะนำของคนปิดการขายเลย — ยิ่งสายลึก บริษัทยิ่งจ่ายรวมมากขึ้น',
  },
  {
    value: 'deduct_from_sale',
    title: 'หักจากสมาชิก — คิด % จากยอดขาย',
    oneLine: 'หัวหน้าทีมได้ % ของยอดขาย แต่เงินนั้นหักออกจากค่าแนะนำของคนปิดการขาย',
    detail: 'ต้นทุนรวมของบริษัทเท่าเดิม แต่เป็นโหมดที่กินโควตาเร็วที่สุด เพราะ % คิดจากยอดขายทั้งก้อนในขณะที่เงินมาจากค่าแนะนำก้อนเล็ก ๆ ของสมาชิกเท่านั้น',
  },
  {
    value: 'deduct_from_commission',
    title: 'หักจากสมาชิก — คิด % จากค่าแนะนำของสมาชิก',
    oneLine: 'หัวหน้าทีมได้ % ของ "ค่าแนะนำที่สมาชิกได้" ไม่ใช่ของยอดขาย',
    detail: 'ต้นทุนรวมของบริษัทเท่าเดิม และหักน้อยกว่าแบบบนมาก เพราะฐานที่คิด % เล็กกว่า — ถ้าตั้งใจว่า "แบ่งกันเองในทีม" ส่วนใหญ่หมายถึงโหมดนี้',
  },
]

const overrideModeLabels: Record<CommissionOverrideMode, string> = {
  additive: 'บริษัทจ่ายเพิ่ม',
  deduct_from_sale: 'หักจากสมาชิก (คิดจากยอดขาย)',
  deduct_from_commission: 'หักจากสมาชิก (คิดจากค่าแนะนำสมาชิก)',
}

/**
 * The same rounding rule the server uses — CommissionRateCalculator::compute().
 * `rate_value` is BASIS POINTS (500 = 5.00%), which is why the divisor is
 * 10,000 and not 100. Getting that wrong here would not move any money, but it
 * would print a worked example that disagrees with the ledger, which on this
 * particular card is the entire failure being fixed.
 */
function computeRateSatang(rateType: RateType, rateValue: number, baseSatang: number): number {
  return rateType === 'percentage' ? Math.round((baseSatang * rateValue) / 10000) : rateValue
}

/** Price or PV, exactly as CommissionBasisResolver::baseSatang() picks it. */
function commissionBaseSatangFor(p: ProductOption): number {
  const price = p.effective_price_satang ?? p.price_satang ?? 0

  // `??` and never `||`: a product deliberately worth 0 PV is a decision the
  // server honours, and collapsing it into the price here would show an
  // example nobody will be paid.
  return commissionBasis.value === 'pv' ? (p.pv_satang ?? price) : price
}

/**
 * The product the example is built on: the one where the deduction BINDS.
 *
 * The cheapest commission is the constraint — a leader rate that is
 * comfortable on a 29,900 package and ruinous on a 590 one is not comfortable.
 * This is the same product OverrideDeductionGuard reports on, deliberately, so
 * the number on screen and the number in a refusal are about the same row.
 */
const overrideExampleProduct = computed<ProductOption | null>(() => {
  let best: { product: ProductOption; seller: number } | null = null

  for (const p of sellableProducts.value) {
    const agentRule = resolveRuleFor(p)
    const leaderRule = resolveOverrideFor(p)
    if (!agentRule || !leaderRule) continue

    const base = commissionBaseSatangFor(p)
    if (base <= 0) continue

    const seller = computeRateSatang(agentRule.rate_type, agentRule.rate_value, base)
    if (seller <= 0) continue

    if (!best || seller < best.seller) best = { product: p, seller }
  }

  return best?.product ?? null
})

interface OverrideModeRow { sellerSatang: number; leaderSatang: number; companyPaysSatang: number }

/**
 * All three answers, in baht, for ONE sale with ONE leader above it.
 *
 * One leader on purpose: it is the owner's own framing ("สมชายปิดการขาย" and
 * his หัวหน้า) and it is the smallest case where the three modes already
 * disagree. The real chain depth is shown next to the table rather than folded
 * into it — multiplying the example by five managers makes the numbers
 * dramatic and the comparison unreadable.
 *
 * ── THE HYPOTHETICAL, AND WHY IT IS NOT A BR-7 VIOLATION ──
 *
 * When no product has BOTH an agent rate and a leader rate yet there is
 * nothing real to compute, and a company in that state is exactly the one that
 * needs to understand the modes before setting anything. So the card falls
 * back to a round illustration, LABELLED as one on screen. BR-7 forbids the
 * system inventing a business value it then acts on — this number is never
 * saved, never sent, and never resolved against; it is the text of an
 * explanation. The moment real rates exist the example switches to them.
 */
const overrideModeExample = computed(() => {
  const product = overrideExampleProduct.value
  const agentRule = product ? resolveRuleFor(product) : null
  const leaderRule = product ? resolveOverrideFor(product) : null
  const hypothetical = !product || !agentRule || !leaderRule

  const base = hypothetical ? 1000000 : commissionBaseSatangFor(product!)
  const sellerRateType: RateType = hypothetical ? 'percentage' : agentRule!.rate_type
  const sellerRateValue = hypothetical ? 300 : agentRule!.rate_value
  const leaderRateType: RateType = hypothetical ? 'percentage' : leaderRule!.rate_type
  const leaderRateValue = hypothetical ? 200 : leaderRule!.rate_value

  const seller = computeRateSatang(sellerRateType, sellerRateValue, base)
  const leaderOnSale = computeRateSatang(leaderRateType, leaderRateValue, base)
  const leaderOnCommission = computeRateSatang(leaderRateType, leaderRateValue, seller)

  // CommissionService's runtime pool cap, mirrored: under a deduct mode the
  // seller's row can never go negative, so a leader rate bigger than the whole
  // commission pays out the pool and no more. Showing an un-capped number here
  // would promise the leader money the calculation refuses to write.
  const capped = (leaderEach: number): number => Math.min(leaderEach, seller)

  const rows: Record<CommissionOverrideMode, OverrideModeRow> = {
    additive: {
      sellerSatang: seller,
      leaderSatang: leaderOnSale,
      companyPaysSatang: seller + leaderOnSale,
    },
    deduct_from_sale: {
      sellerSatang: seller - capped(leaderOnSale),
      leaderSatang: capped(leaderOnSale),
      companyPaysSatang: seller,
    },
    deduct_from_commission: {
      sellerSatang: seller - capped(leaderOnCommission),
      leaderSatang: capped(leaderOnCommission),
      companyPaysSatang: seller,
    },
  }

  return {
    hypothetical,
    productName: hypothetical ? 'สินค้าตัวอย่าง' : product!.name,
    baseSatang: base,
    baseLabel: commissionBasis.value === 'pv' ? 'PV' : 'ยอดขาย',
    sellerRateLabel: formatRate(sellerRateType, sellerRateValue),
    leaderRateLabel: formatRate(leaderRateType, leaderRateValue),
    rows,
  }
})

/**
 * The ceiling a leader rate may not cross under a deduct mode, in baht per
 * level — the same arithmetic OverrideDeductionGuard refuses with, shown
 * BEFORE anybody types instead of after they save.
 *
 * Null when there is no chain (nothing can be exhausted) or when no product
 * has an agent rate to divide (step 3's problem, and saying it twice in two
 * places is how a screen ends up nagging).
 */
const maxOverridePerLevelSatang = computed<number | null>(() => {
  if (deepestManagerChain.value <= 0) return null

  let worst: number | null = null

  for (const p of sellableProducts.value) {
    const agentRule = resolveRuleFor(p)
    if (!agentRule) continue

    const base = commissionBaseSatangFor(p)
    if (base <= 0) continue

    const max = Math.floor(computeRateSatang(agentRule.rate_type, agentRule.rate_value, base) / deepestManagerChain.value)
    if (worst === null || max < worst) worst = max
  }

  return worst
})

/**
 * PUT /commission-settings — the same door, the same Ability
 * (SettingsCommissionPlanUpdate) as the plan type and the basis beside it.
 *
 * Assigned from the RESPONSE, never optimistically: the server refuses a
 * switch that would make an existing leader rate over-deduct
 * (CommissionSettingService::assertModeFitsExistingRules), and a card that
 * showed the new mode before that refusal arrived would leave an admin
 * believing a switch that never happened.
 */
async function setOverrideMode(next: CommissionOverrideMode): Promise<void> {
  if (!effectiveCompanyId.value || overrideModeSaving.value || next === overrideMode.value) return

  overrideModeSaving.value = true
  overrideModeError.value = ''
  try {
    const r = await commissionApi.put<{ data: { commission_override_mode?: CommissionOverrideMode } }>(
      '/commission-settings',
      withCompanyBody({ commission_override_mode: next }),
    )
    overrideMode.value = r.data.commission_override_mode ?? next
    overrideModeUnknown.value = false
  } catch (e) {
    overrideModeError.value = apiErrorMessage(e, 'เปลี่ยนโหมดไม่สำเร็จ')
  } finally {
    overrideModeSaving.value = false
  }
}

/* ═══════════════════════════════════════════════════════════════════════
 * 2026-09-19 — HOW MANY LEVELS, AND WHO INHERITS A SKIPPED ONE.
 *
 * Two settings that only mean anything under Unilevel, because it is the only
 * plan whose payout walks the manager chain level by level
 * (CommissionService::resolveUnilevelOverrides). Affiliate pays one level and
 * has no ladder; Binary, Matrix, Stairstep and Generation each have their own
 * structure tab. Showing these controls on those plans would offer an admin a
 * knob that does nothing, which is the failure this whole screen exists to
 * remove.
 *
 * NEITHER IS DEFAULTED ON THE CLIENT. An empty depth is "as far as the chain
 * goes" — what every company does today — and the field renders empty for it
 * rather than showing a ceiling nobody set.
 * ═══════════════════════════════════════════════════════════════════════ */
const maxOverrideDepth = ref<string | number>('')
const overrideCompression = ref(false)
const depthSaving = ref(false)
const depthMessage = ref('')
const compressionSaving = ref(false)

/** Unilevel only — see the block comment above. */
const levelLadderApplies = computed(() => companyPlanType.value === 'unilevel')

async function saveMaxOverrideDepth(): Promise<void> {
  if (!effectiveCompanyId.value || depthSaving.value) return

  depthMessage.value = ''
  const raw = String(maxOverrideDepth.value ?? '').trim()
  let depth: number | null = null

  if (raw !== '') {
    const parsed = Number(raw)

    /*
     * 0 is refused rather than accepted as "pay nobody". A company that wants
     * to stop paying leaders removes the rates or sets the mode; a cap of zero
     * would achieve the same thing silently, through a field whose empty state
     * already means something else entirely.
     */
    if (!Number.isInteger(parsed) || parsed < 1 || parsed > MAX_PRICEABLE_LEVEL) {
      depthMessage.value = `จำนวนชั้นต้องเป็นจำนวนเต็ม 1–${MAX_PRICEABLE_LEVEL} · เว้นว่าง = จ่ายขึ้นไปทั้งสาย`

      return
    }

    depth = parsed
  }

  depthSaving.value = true
  try {
    // Sent as an explicit null, never omitted: on this endpoint absence means
    // "leave the cap alone" and null means "remove it", and the admin clearing
    // the field means the second.
    await commissionApi.put('/commission-settings', withCompanyBody({ max_override_depth: depth }))
    depthMessage.value = depth === null ? 'บันทึกแล้ว — จ่ายขึ้นไปทั้งสาย' : `บันทึกแล้ว — จ่าย ${depth} ชั้น`
    void loadResolution()
  } catch (e) {
    depthMessage.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    depthSaving.value = false
  }
}

async function setOverrideCompression(next: boolean): Promise<void> {
  if (!effectiveCompanyId.value || compressionSaving.value || next === overrideCompression.value) return

  compressionSaving.value = true
  overrideModeError.value = ''
  try {
    await commissionApi.put('/commission-settings', withCompanyBody({ override_compression: next }))
    overrideCompression.value = next
  } catch (e) {
    overrideModeError.value = apiErrorMessage(e, 'เปลี่ยนการเลื่อนชั้นไม่สำเร็จ')
  } finally {
    compressionSaving.value = false
  }
}

/* ═══════════════════════════════════════════════════════════════════════
 * 2026-09-21 — STEP 2's CHART SHOWS THIS COMPANY'S OWN NUMBERS.
 *
 * Owner, comparing the shipped screen with the prototype they had approved:
 * "ทำไม UI ที่คุยกับไว้ ... กับที่ร่างให้ผมถึงไม่เหมือนกัน". The prototype let
 * them turn real knobs and watch the money move; what shipped was a collapsed
 * card whose own subtitle said its numbers were invented.
 *
 * So the chart is seeded from the company's real configuration:
 *
 *   price / PV — a product this company actually sells. The FIRST sellable
 *     one by id, deliberately not "the most expensive" or an average: the
 *     chart names which product it used, and a figure the admin can go and
 *     look at beats a representative number nobody can trace.
 *   seller rate — the company-wide default agent rate (ค่าเริ่มต้นทั้งบริษัท),
 *     the same row step 3 edits.
 *
 * Nulls travel as nulls. A company with no sellable product or no default
 * rate keeps the component's sandbox figure for that one field, because a
 * chart drawn at 0% teaches that the plan pays nobody — a claim about the
 * plan rather than about the missing setting.
 * ═══════════════════════════════════════════════════════════════════════ */
const planShapeSeedProduct = computed<ProductOption | null>(() => {
  const sellable = sellableProducts.value

  return [...sellable].sort((a, b) => a.id - b.id)[0] ?? null
})

const planShapeSeed = computed(() => {
  /*
   * NULL while browsing a plan this company does not run.
   *
   * The chips exist so somebody can see the SHAPE of a plan they are
   * considering, and the card says which mode it is in. Seeding an unrelated
   * plan with this company's price and agent rate would put "ใช้ค่าจริงของ
   * บริษัท" over a payout structure they have not adopted — a sentence that is
   * half true in the worst way, because the money it draws is nobody's.
   */
  if (viewingPlanType.value !== companyPlanType.value) return null

  const product = planShapeSeedProduct.value
  const rule = companyDefaultRules.value[0] ?? null

  /*
   * Only a PERCENTAGE rate can be drawn as "x% of the base". A company whose
   * default is a fixed satang amount gets null here and keeps the sandbox
   * percentage — the alternative is inventing a percentage that the fixed
   * amount happens to equal for this one product, which would move the moment
   * anybody looked at a different product.
   */
  const sellerRatePct = rule && rule.rate_type === 'percentage' ? rule.rate_value / 100 : null

  return {
    priceSatang: product?.effective_price_satang ?? product?.price_satang ?? null,
    pvSatang: product?.pv_satang ?? null,
    sellerRatePct,
    productName: product?.name ?? null,
  }
})

/**
 * The live ladder handed to the chart, or null when there is nothing live to
 * show.
 *
 * Null — not an empty array — whenever the admin is BROWSING a plan the
 * company does not run, or the plan is not Unilevel. The chart then falls back
 * to its sandbox, which is correct: those chips exist so somebody can see the
 * shape of a plan they are considering, and dressing an unrelated plan in this
 * company's rates would be a lie in both directions.
 *
 * An EMPTY array is a real answer and renders as one: the company runs
 * Unilevel and has priced no level yet, which is exactly the state that most
 * needs a picture of nobody being paid.
 */
const liveLevelRates = computed<number[] | null>(() => {
  if (viewingPlanType.value !== 'unilevel') return null
  if (companyPlanType.value !== 'unilevel') return null

  return levelLadderDraft.value.map((row) => row.percent)
})

/**
 * The per-level rates a company has actually priced, lowest level first.
 *
 * Read off the SAME list the boxes below render, not a second request: a
 * screen that fetched its own copy of the rates would be a second answer to
 * "what is set", and the last time this file kept a private copy of a
 * resolution ladder it displayed one company's rate on another's product.
 */
const levelledOverrideRules = computed<CommissionOverrideRuleItem[]>(() =>
  byCompany(commissionOverrideRules.value)
    .filter((r) => !isCatchAllLevel(r) && r.product === null && r.product_category === null)
    .sort((a, b) => (a.level ?? 0) - (b.level ?? 0)),
)

/**
 * The levels between 1 and the deepest priced one that nobody priced, when
 * there is no catch-all to cover them.
 *
 * A gap is not an error — the server pays the levels above it as normal
 * (UnilevelPerLevelRatesTest pins that) — but it is almost never intended, and
 * nothing else on this screen would say so: the box lists the rows that exist,
 * and a missing level has no row to look at.
 */
/* ═══════════════════════════════════════════════════════════════════════
 * THE LADDER EDITOR THAT SITS BESIDE THE CHART (step 2).
 *
 * A DRAFT, not a live binding to the rows. Typing 5 into level 2 must move
 * the diagram immediately — that is the whole point of putting them side by
 * side — and it must NOT save on every keystroke, because each save is an
 * audited write to a table that decides what people are paid.
 *
 * So the draft is the single source the chart reads (see liveLevelRates), and
 * `saveLevelLadder` is the one place it becomes rows. Reverting is leaving
 * the step without pressing บันทึก.
 * ═══════════════════════════════════════════════════════════════════════ */
interface LevelLadderRow {
  /** The existing rule this row came from, or null for a level just added. */
  id: number | null
  level: number
  percent: number
}

const levelLadderDraft = ref<LevelLadderRow[]>([])
const levelLadderSaving = ref(false)
const levelLadderMessage = ref('')
/** Set while syncing from the server so the watcher does not fight the user. */
const levelLadderDirty = ref(false)

/**
 * Rebuild the draft from what the server actually holds.
 *
 * Skipped while the admin has unsaved edits: a background reload (switching
 * tabs re-fetches this list) must not silently discard a rate somebody is
 * halfway through typing.
 */
function syncLevelLadderDraft(): void {
  if (levelLadderDirty.value) return

  levelLadderDraft.value = levelledOverrideRules.value.map((r) => ({
    id: r.id,
    level: r.level ?? 0,
    // Stored as basis points; shown and typed as a percentage. One inverse,
    // here, so nothing downstream has to remember which unit it holds.
    percent: r.rate_value / 100,
  }))
}

watch(levelledOverrideRules, syncLevelLadderDraft, { immediate: true, deep: true })

function setLevelPercent(index: number, raw: string | number): void {
  const row = levelLadderDraft.value[index]
  if (!row) return

  const parsed = Number(raw)
  row.percent = Number.isFinite(parsed) && parsed >= 0 ? parsed : 0
  levelLadderDirty.value = true
  levelLadderMessage.value = ''
}

/**
 * Add the next rung down.
 *
 * Seeded from the deepest level's current rate rather than from 0 — a ladder
 * is quoted descending (10/5/3) and starting the new rung at the one above it
 * is one keystroke from every shape somebody actually wants. Zero would draw a
 * rung that pays nobody and read as a bug.
 */
function addLevelRung(): void {
  const rows = levelLadderDraft.value
  const deepest = rows[rows.length - 1]

  if (rows.length >= MAX_PRICEABLE_LEVEL) return

  rows.push({ id: null, level: (deepest?.level ?? 0) + 1, percent: deepest?.percent ?? 0 })
  levelLadderDirty.value = true
  levelLadderMessage.value = ''
}

function removeLevelRung(): void {
  if (levelLadderDraft.value.length === 0) return

  levelLadderDraft.value.pop()
  levelLadderDirty.value = true
  levelLadderMessage.value = ''
}

/**
 * Write the draft back: update what moved, create what is new, delete what the
 * admin removed.
 *
 * Sequential rather than Promise.all, deliberately. OverrideDeductionGuard
 * judges each rate against the TOTAL the whole ladder would pay out
 * (projectedWalkCostSatang), so two rows saved concurrently can each look
 * affordable alone and be refused together — or worse, both land and take the
 * seller's commission past what it can fund. One at a time means the guard
 * sees each row against the state the previous one left.
 *
 * Deletions go FIRST for the same reason: a ladder being shortened must free
 * its budget before the remaining rungs are re-priced upward.
 */
async function saveLevelLadder(): Promise<void> {
  if (!effectiveCompanyId.value || levelLadderSaving.value || !canEditCommissionConfig.value) return

  const draft = levelLadderDraft.value
  const invalid = draft.find((row) => !Number.isFinite(row.percent) || row.percent < 0 || row.percent > 100)

  if (invalid) {
    levelLadderMessage.value = `อัตราของชั้นที่ ${invalid.level} ต้องอยู่ระหว่าง 0–100%`

    return
  }

  levelLadderSaving.value = true
  levelLadderMessage.value = ''

  const keptIds = new Set(draft.map((row) => row.id).filter((id): id is number => id !== null))
  const removed = levelledOverrideRules.value.filter((r) => !keptIds.has(r.id))

  try {
    for (const rule of removed) {
      await commissionApi.delete(`/commission-override-rules/${rule.id}`)
    }

    for (const row of draft) {
      const body = {
        product_id: null,
        product_category_id: null,
        level: row.level,
        rate_type: 'percentage' as RateType,
        // Percent → basis points, rounded once. 2.5% is 250, and 2.555%
        // typed by a human is 256, never 255.5.
        rate_value: Math.round(row.percent * 100),
        // Never sent alongside a level — the server refuses the pair, because
        // one walk up one chain has one funding model. See the step-4 form.
        override_mode: null,
        effective_from: todayIso(),
        effective_to: null,
      }

      if (row.id === null) {
        await commissionApi.post('/commission-override-rules', withCompanyBody(body))
      } else {
        await commissionApi.put(`/commission-override-rules/${row.id}`, body)
      }
    }

    levelLadderDirty.value = false
    levelLadderMessage.value = draft.length === 0
      ? 'บันทึกแล้ว — ไม่จ่ายหัวหน้าทีมตามชั้น'
      : `บันทึกแล้ว — จ่าย ${draft.length} ชั้น`

    await loadRulesTabData()
    void loadResolution()
  } catch (e) {
    levelLadderMessage.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    levelLadderSaving.value = false
  }
}

/**
 * How many rungs of the draft ladder the depth cap would never reach.
 *
 * Zero when there is no cap, which is what every company has today. The two
 * settings live on different steps — the cap with the other company-wide
 * payout settings, the rungs beside the diagram that shows what they pay —
 * and this is the one place their disagreement becomes visible.
 */
const ladderRungsBeyondCap = computed<number>(() => {
  const cap = Number(maxOverrideDepth.value)

  if (!Number.isFinite(cap) || cap <= 0) return 0

  return Math.max(0, levelLadderDraft.value.length - cap)
})

const levelGaps = computed<number[]>(() => {
  if (!levelledOverrideRules.value.length) return []
  if (hasCompanyWideLeaderRate.value && companyLeaderRules.value.some(isCatchAllLevel)) return []

  const priced = new Set(levelledOverrideRules.value.map((r) => r.level as number))
  const deepest = Math.max(...priced)
  const gaps: number[] = []

  for (let level = 1; level <= deepest; level++) {
    if (!priced.has(level)) gaps.push(level)
  }

  return gaps
})

/* ═══════════════════════════════════════════════════════════════════════
 * 2026-09-13 — THE WITHDRAWAL MINIMUM, MOVED HERE (step 4).
 *
 * Owner: "ยอดขั้นต่ำในการเบิก ปรับมาเป็น UI หน้านี้หน้าเดียวให้จบ นำของเก่า
 * ออกเลย".
 *
 * It used to be a link card pointing at /commission-withdrawals, and the
 * comment defending that link argued two write doors would be worse. That was
 * the right worry and the wrong conclusion: the answer is ONE door, and the
 * door belongs on the setup flow, not in the middle of an approval queue. The
 * queue keeps a READ-ONLY line saying what the floor is and where it is set —
 * so "why was that request refused" is still answered where it is asked.
 *
 * `/commission-withdrawal-settings` is a different Ability from everything
 * else on this step (SettingsCommissionWithdrawalUpdate, held by Company Admin
 * too), so this control is deliberately NOT gated on canEditCommissionConfig.
 * Hiding it from a Company Admin who is allowed to set it would be the house
 * rule applied backwards.
 * ═══════════════════════════════════════════════════════════════════════ */
const minWithdrawalBaht = ref('')
const minWithdrawalLoading = ref(false)
const minWithdrawalSaving = ref(false)
const minWithdrawalMessage = ref('')
/** Same loud-failure rule as the basis: a floor that would not load is not "no floor". */
const minWithdrawalUnknown = ref(false)

async function loadMinWithdrawal(): Promise<void> {
  if (!effectiveCompanyId.value) {
    minWithdrawalBaht.value = ''
    minWithdrawalUnknown.value = false
    whtRatePercent.value = ''
    whtUnknown.value = false

    return
  }

  minWithdrawalLoading.value = true
  minWithdrawalMessage.value = ''
  try {
    const r = await api.get<{ min_withdrawal_satang: number | null; wht_rate: number | null }>(
      `/commission-withdrawal-settings${companyQuery()}`,
    )
    // One request, two settings — they share an endpoint. Same "empty is a
    // real answer" rule applies to both, for different reasons: no floor, and
    // no withholding.
    whtRatePercent.value = r.wht_rate === null || r.wht_rate === undefined ? '' : (r.wht_rate / 100).toString()
    whtUnknown.value = false
    // EMPTY MEANS NO MINIMUM, and that is a real setting — bound to a string
    // so "" survives as null instead of collapsing into a 0 that would be
    // saved back as a floor of zero baht.
    minWithdrawalBaht.value = r.min_withdrawal_satang === null ? '' : (r.min_withdrawal_satang / 100).toFixed(2)
    minWithdrawalUnknown.value = false
  } catch {
    minWithdrawalUnknown.value = true
    whtUnknown.value = true
  } finally {
    minWithdrawalLoading.value = false
  }
}

/* ═══════════════════════════════════════════════════════════════════════
 * 2026-09-19 — ภาษีหัก ณ ที่จ่าย.
 *
 * Entered as a PERCENT and stored as basis points (3 → 300), because that is
 * how an accountant says it and how every other rate in this system is
 * stored. The one conversion lives here, on submit, so nothing downstream has
 * to remember which unit it is holding.
 *
 * EMPTY MEANS NO WITHHOLDING, and that is every company today. The rate
 * itself is the owner's to supply (BR-7, and here it is also the law — which
 * rate applies depends on the classification of the payment and on the
 * payee), so nothing here defaults it, suggests one, or fills the field in
 * for somebody who left it blank.
 *
 * Shares the endpoint — and therefore the Ability — with the withdrawal
 * minimum above, so it is deliberately NOT gated on canEditCommissionConfig:
 * a Company Admin the server would let save this must not be shown a
 * read-only field.
 * ═══════════════════════════════════════════════════════════════════════ */
const whtRatePercent = ref('')
const whtSaving = ref(false)
const whtMessage = ref('')
/** Same loud-failure rule as the floor: a rate that would not load is not "no tax". */
const whtUnknown = ref(false)

async function saveWithholdingTax(): Promise<void> {
  if (!effectiveCompanyId.value || whtSaving.value) return

  whtMessage.value = ''
  const trimmed = whtRatePercent.value.trim()
  let basisPoints: number | null = null

  if (trimmed !== '') {
    const percent = Number(trimmed)

    if (!Number.isFinite(percent) || percent < 0 || percent > 100) {
      whtMessage.value = 'อัตราภาษีต้องอยู่ระหว่าง 0–100%'

      return
    }

    // Rounded, not truncated: this converts what the admin TYPED into the
    // stored unit, and 3.005% typed is 300 or 301 basis points, never 300.5.
    // The withholding arithmetic itself truncates, on the server, where the
    // supplier flow already truncates the same obligation.
    basisPoints = Math.round(percent * 100)
  }

  /*
   * The floor travels with this save because the endpoint requires it to be
   * present — so a floor the admin has typed but not yet saved, and left
   * invalid, would otherwise be sent as null and DELETE a floor they never
   * touched. Refused instead, naming the other field.
   */
  const floor = minWithdrawalSatangFromForm()

  if (!floor.valid) {
    whtMessage.value = 'ยอดขั้นต่ำในการเบิก (ช่องด้านบน) ไม่ถูกต้อง — แก้ก่อนจึงจะบันทึกอัตราภาษีได้'

    return
  }

  whtSaving.value = true
  try {
    // Explicit null, never omitted: absence means "leave it alone" on this
    // endpoint, and an admin clearing the field means "no withholding".
    await api.put(`/commission-withdrawal-settings${companyQuery()}`, {
      // Sent alongside because the endpoint's own rule is that the floor is
      // `present`-required; sending only the tax would be refused.
      min_withdrawal_satang: floor.satang,
      wht_rate: basisPoints,
    })
    whtMessage.value = basisPoints === null ? 'บันทึกแล้ว — ไม่หักภาษี' : `บันทึกแล้ว — หัก ${(basisPoints / 100).toString()}%`
    whtUnknown.value = false
  } catch (e) {
    whtMessage.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    whtSaving.value = false
  }
}

/**
 * The floor as the form currently holds it, in satang — and whether it is
 * readable at all.
 *
 * Extracted so the tax save can send the floor UNCHANGED rather than omitting
 * it (the endpoint requires `min_withdrawal_satang` to be present). The
 * `valid` flag is the point: an unreadable field must NOT collapse into null,
 * because null is a real instruction on this endpoint — "no minimum" — and
 * obeying it would delete a floor the admin never touched while they were
 * saving a different setting.
 *
 * Empty IS valid, and means exactly that: no minimum.
 */
function minWithdrawalSatangFromForm(): { valid: boolean; satang: number | null } {
  const trimmed = minWithdrawalBaht.value.trim()

  if (trimmed === '') return { valid: true, satang: null }

  const baht = Number(trimmed)

  if (!Number.isFinite(baht) || baht < 0) return { valid: false, satang: null }

  return { valid: true, satang: Math.round(baht * 100) }
}

async function saveMinWithdrawal(): Promise<void> {
  if (!effectiveCompanyId.value || minWithdrawalSaving.value) return

  minWithdrawalMessage.value = ''
  const trimmed = minWithdrawalBaht.value.trim()
  let satang: number | null = null

  if (trimmed !== '') {
    const baht = Number(trimmed)

    if (!Number.isFinite(baht) || baht < 0) {
      minWithdrawalMessage.value = 'ยอดขั้นต่ำไม่ถูกต้อง'

      return
    }

    satang = Math.round(baht * 100)
  }

  minWithdrawalSaving.value = true
  try {
    await api.put(`/commission-withdrawal-settings${companyQuery()}`, { min_withdrawal_satang: satang })
    minWithdrawalMessage.value = satang === null ? 'บันทึกแล้ว — ไม่มีขั้นต่ำ' : 'บันทึกแล้ว'
    minWithdrawalUnknown.value = false
  } catch (e) {
    minWithdrawalMessage.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    minWithdrawalSaving.value = false
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
    // Sellable only: a product this company does not sell cannot pay anybody
    // the wrong amount, so a missing PV on it is not a gap. See
    // sellableProducts() for the lock this prevents.
    ? sellableProducts.value.filter((p) => p.pv_satang === null || p.pv_satang === undefined)
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

/*
 * 2026-09-14 — sellingFirstProducts() WAS HERE.
 *
 * It ordered the per-product card list: on sale first, then by Thai name. The
 * list is gone and the ORDER survived it — CommissionResolutionService::
 * configurableProducts() now sorts the same way, on the server, so the table
 * and the payout logic agree about what "every product" means without this
 * file holding a second opinion.
 */

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
    /*
     * THE TABLE'S COPY OF THIS FLAG IS THE SERVER'S (`is_sellable` on the
     * resolution row), and it is what draws the switch the admin just moved.
     * So it is patched here too — the switch has to move on the click, not one
     * round trip later, or the admin clicks it twice.
     *
     * And then it is REFETCHED anyway, which is not belt and braces: opening a
     * product changes `max_override_per_level_satang`, because the ceiling is
     * the worst SELLABLE product's commission divided by the chain. Leaving
     * that stale would have step 4 offer a leader rate the guard then refuses.
     * The catalogue list above still must not be refetched (it holds step 2's
     * PV drafts); this payload holds nothing anybody is typing into.
     */
    const resolved = resolution.value?.products.find((x) => x.product_id === p.id)
    if (resolved) resolved.is_sellable = next
    void loadResolution()
  } catch (e) {
    sellingError.value = apiErrorMessage(e, 'เปิด/ปิดขายสินค้าไม่สำเร็จ')
  } finally {
    sellingSavingId.value = null
  }
}

/**
 * The products this company ACTUALLY SELLS — the set every readiness verdict
 * on this screen is judged against.
 *
 * ── THE DIVERGENCE THIS CLOSES (2026-09-13) ──
 *
 * CommissionReadinessService::sellableProducts() has always filtered the
 * server's verdict through Product::isSellableBy(). This screen did not: every
 * computed below counted `byCompany(products)` whole. While nothing here could
 * switch selling on or off, that was invisible. The moment the per-company
 * selling toggle landed in step 3 it stopped being invisible — and it is
 * visible in the owner's own screenshot, where the banner says "สินค้า 3 จาก 3"
 * above a list holding four rows, one of them ปิดขาย.
 *
 * The consequence is worse than a wrong number. Step 3 stays ยังไม่ครบ and step
 * 4 stays LOCKED over a product this company does not sell — a lock whose only
 * key is setting a rate for something nobody will ever buy here. That is the
 * dead end the whole 4-step redesign exists to remove.
 *
 * `!== false` and not `=== true`: a payload without the field (an older
 * response, a test fixture) counts as sellable. The safe direction for a
 * warning is to raise one too many, never to go quiet about a product that IS
 * on sale and pays nobody.
 *
 * THE LIST ITSELF IS NOT NARROWED — step 3 still shows every product, closed
 * ones greyed and fully editable, because the owner asked for exactly that
 * ("ที่ปิดไว้ก็แก้ไขได้เหมือนเดิม"). Showing everything and judging only what is
 * sold are different questions; this is the second one.
 */
const sellableProducts = computed<ProductOption[]>(
  () => byCompany(products.value).filter((p) => p.is_sellable_here !== false))

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
  // "In use" means in use for something this company SELLS — see
  // sellableProducts(). loadReadinessProbe() deliberately still probes the
  // wider set: an extra probe costs one request and, because the check below
  // is `=== false`, an unprobed plan can never raise an alarm.
  const inUse = new Set(sellableProducts.value.map((p) => p.effective_plan_type).filter(Boolean) as CommissionPlanType[])

  // `=== false` and not `!`: loadReadinessProbe() leaves a key UNDEFINED when
  // its probe could not answer, and "unknown" must never raise an alarm.
  return [...inUse].filter((pt) => structureReady.value[pt] === false)
})

/** Products with no rate that resolves TODAY — the money-losing set (step 3). */
const productsMissingAgentRate = computed<ProductOption[]>(() =>
  sellableProducts.value.filter((p) => !resolveRuleFor(p)))

/*
 * Step 3 is complete when every product resolves to exactly ONE live rate.
 * Overlaps count as incomplete for the same reason productReadiness ranks
 * them beside "no rule at all": the money still moves, at an amount nobody
 * chose and nobody can predict, into a ledger that cannot be corrected (BR-4).
 *
 * ── AND THE COMPANY DEFAULT IS REQUIRED EVEN WHEN NOTHING IS MISSING TODAY ──
 *
 * 2026-09-13, owner, about a company whose every product happens to carry its
 * own rate: "แดงตลอด ผมยังอยากให้ตั้งค่าบริษัทอยู่ดี".
 *
 * Without this clause such a company reads เสร็จแล้ว while standing one row
 * away from paying nobody: the next product anybody adds — from the catalogue
 * screen, from an import, from another admin in another tab — arrives with no
 * rate of its own and nothing underneath it to fall through to, and the sale
 * that follows is silent (CommissionService logs and returns null rather than
 * blocking the deal). The company default is not a convenience, it is the
 * safety net for the product that does not exist yet.
 *
 * THE SERVER ALREADY SAYS THE SAME THING. CommissionReadinessService reports
 * `company_default_missing` with `blocking_step: 3` as of 2026-09-13, so the
 * app-shell banner, this screen's banner and this pill now give one verdict.
 * Do not re-derive this rule differently here — two answers about whether
 * anybody is being paid is the failure the readiness store was built to end.
 */
const step3Complete = computed(() =>
  subStepThreeOneDone.value && productsMissingAgentRate.value.length === 0 && conflictingRuleIds.value.size === 0)

/**
 * Unilevel/Affiliate products with nobody set to pay the upline (step 4).
 *
 * Only those two plans pay the leader out of commission_override_rules; on
 * the others the upline is paid by that plan's own structure, so counting
 * them here would invent a gap that does not exist.
 */
const leaderRateGaps = computed<ProductOption[]>(() =>
  sellableProducts.value.filter((p) =>
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
  if (commissionReadiness.state === null) return 'กำลังตรวจสอบสถานะการตั้งค่าค่าแนะนำ…'
  if (readinessLevel.value === 'ok') return 'พร้อมจ่ายค่าแนะนำแล้ว — ทุกสินค้ามีอัตราที่ใช้ได้'
  if (readinessLevel.value === 'warn') return 'จ่ายสมาชิกผู้ขายได้แล้ว แต่หัวหน้าทีมยังไม่ได้ส่วนแบ่ง'

  return 'ยังไม่พร้อมจ่ายค่าแนะนำ — ดีลที่ปิดได้จะไม่มีใครได้เงิน'
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

/**
 * ── "เริ่มตรงนี้" — THE ONE MODAL THAT MAY INTERRUPT THIS SCREEN (2026-09-13) ──
 *
 * A nag modal was DELETED from this file on 2026-09-11 (see the
 * RESOLUTION_ORDER_NOTE block): it auto-opened on every entry to explain the
 * resolution order, and it had to go because a modal that appears every visit
 * is dismissed unread — and because the thing it said is now drawn permanently
 * behind it, which is strictly more than interrupting ever achieved.
 *
 * WHY THIS ONE IS NOT THAT ONE, condition by condition:
 *
 *   · IT IS NOT ABOUT SOMETHING ALREADY ON SCREEN. The old modal repeated the
 *     ladder it covered up. This one says a thing the screen behind it cannot:
 *     that RIGHT NOW, a deal closing pays nobody.
 *   · 'missing' ONLY, never 'incomplete'. Amber means somebody is still paid;
 *     interrupting for it would spend the interruption on the cheaper problem
 *     and teach the admin to close this dialog by reflex.
 *   · ONCE PER DAY PER COMPANY, through the readiness store's own dismissal
 *     mechanism (same prefix, same per-company/per-state/per-day rule, its own
 *     surface so closing it never silences the app-shell banner). Every visit
 *     after the first is a visit it does not appear on — which is the exact
 *     property the deleted one lacked.
 *   · ONLY FOR SOMEBODY WHO CAN FIX IT. Commission config is Super Admin's to
 *     write; telling a Company Admin in a dialog they must dismiss that their
 *     company cannot pay anybody is an interruption with no action attached.
 *   · IT IS SHORT, AND ITS PRIMARY BUTTON IS THE PATH. One sentence and a
 *     button that puts them where the work is — the screen behind it now says
 *     what to do first, so there is nothing to explain here.
 */
const START_HERE_SURFACE = 'startHere' as const

/**
 * Closed for THIS visit. The daily flag lives in the store (localStorage);
 * this is the half that has to be a ref because "I just closed it" is not a
 * fact about the day, and re-reading storage would re-open it on the next
 * reactive tick.
 */
const startHereClosed = ref(false)

const showStartHereModal = computed(() =>
  canEditCommissionConfig.value
  && commissionReadiness.state === 'missing'
  && !startHereClosed.value
  && !commissionReadiness.dismissedTodayFor(START_HERE_SURFACE))

/**
 * BOTH buttons write the daily dismissal, and only one of them navigates.
 *
 * "ปิดไว้ก่อน" obviously does. So does the primary, deliberately: an admin who
 * pressed "go and set it up" has acknowledged this at least as firmly as one
 * who waved it away, and re-interrupting them on the next entry — after a
 * company switch, after a reload — would rebuild the every-visit modal this
 * one is careful not to be. The state is still 'missing' until it is fixed, so
 * the banner above every page keeps saying so all day; nothing goes silent.
 */
function dismissStartHereForToday(): void {
  commissionReadiness.dismiss(START_HERE_SURFACE)
  startHereClosed.value = true
}

/**
 * The primary action: close, then land on the step that owns the gap.
 *
 * Clamped exactly as the banner's jump is (see `jumpStep`), because goToStep()
 * refuses an unreachable step and a primary button that visibly does nothing is
 * the dead end this whole screen exists to remove. Step 3 is the destination
 * whenever it is open; when it is not, the local edge is the step that has to
 * be cleared before step 3 could be worked on at all.
 */
function goFixCommission(): void {
  dismissStartHereForToday()
  goToStep(stepReachable.value[3] ? 3 : (firstIncompleteStep.value ?? 3))
}

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
    how: 'สมาชิกได้จากยอดที่ตัวเองปิด และหัวหน้าสายได้ส่วนแบ่งจากยอดลูกทีม',
    affects: 'แผนนี้ทำให้ขั้นที่ 4 มี "อัตราหัวหน้าทีม" ให้ตั้ง',
  },
  binary: {
    title: 'Binary — จ่ายจากยอดขาที่น้อยกว่า',
    how: 'สมาชิกมีสายซ้าย/ขวา ระบบจับคู่ยอดสองขาแล้วจ่ายจากขาที่น้อยกว่าตามรอบที่ตั้งไว้',
    affects: 'แผนนี้ต้องตั้งอัตรา Matched และรอบคำนวณในขั้นนี้ก่อน ไม่งั้นไม่มีรอบไหนถูกประมวลผลเลย',
  },
  matrix: {
    title: 'Matrix — จำกัดความกว้างและความลึกของสาย',
    how: 'แต่ละคนมีลูกทีมได้ไม่เกินความกว้างที่กำหนด คนที่เกินจะไหลลง (spillover) และจ่ายตามชั้น',
    affects: 'แผนนี้ต้องตั้งความกว้าง/ความลึก และอัตราของแต่ละชั้นในขั้นนี้',
  },
  stairstep_breakaway: {
    title: 'อันดับ (Stairstep) — เลื่อนขั้นตามยอดสะสม',
    how: 'สมาชิกเลื่อนอันดับเมื่อยอดถึงเกณฑ์ และได้อัตราของอันดับนั้น อันดับ Breakaway จะตัดออกจากสายบน',
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

/* ── 2026-09-14 — A RATE THAT IS NOT LIVE *TODAY* IS STILL A RATE ──
 *
 * Found while answering "แล้วถ้าผู้ใช้ไม่ตั้งวันเริ่มต้น/หมดอายุสัก 1 อันล่ะ".
 *
 * Every list on step 3 filtered to rows that are live RIGHT NOW, which reads
 * as reasonable and is the same defect as the invisible category scope, one
 * dimension over. A rule dated to start next month, or one that ended
 * yesterday, simply vanished from the screen — while still being a row in the
 * table. And the overlap guard still counts it: the admin is refused with
 * "ขอบเขตนี้มีอัตราครอบคลุมช่วงเวลานี้อยู่แล้ว", pointing at a row the screen
 * will not show them and gives them no way to edit or delete.
 *
 * So the LISTS show every row and mark the ones that are not live; the GATING
 * and the pills keep reading the live-today sets, because "is anybody being
 * paid today" is a different question and a rate that starts in October does
 * not answer it.
 */
const companyDefaultRulesAll = computed<CommissionRuleItem[]>(() =>
  byCompany(commissionRules.value).filter((r) => !r.product && !r.product_category))

const categoryRulesAll = computed<CommissionRuleItem[]>(() =>
  byCompany(commissionRules.value).filter((r) => !r.product && !!r.product_category))

/** null while a rate is live today; otherwise why it is not. */
function ruleDateStatus(r: { effective_from: string; effective_to: string | null }): string | null {
  const now = new Date()
  if (now < new Date(r.effective_from)) return 'ยังไม่เริ่ม'
  if (r.effective_to && now > new Date(r.effective_to)) return 'หมดอายุแล้ว'

  return null
}

/**
 * The CATEGORY-scoped agent rates that are live today (step 3.2).
 *
 * 2026-09-14 — these had no home on this screen at all until the owner went
 * looking for one ("การตั้งค่าแบบหมวดสินค้า ผมแทบไม่เห็นใน UI เลย"). They were
 * creatable and then invisible: 3.1 filters to company-wide rows and the
 * product list shows products, so a category rate surfaced only as a badge on
 * whatever resolved to it.
 */
const categoryRules = computed<CommissionRuleItem[]>(() => {
  const now = new Date()

  return byCompany(commissionRules.value).filter((r) => !r.product && !!r.product_category && isRuleActiveOn(r, now))
})

/**
 * How many of this company's sellable products a category rate actually
 * decides — the number that turns "I set it and nothing happened" into a
 * visible fact instead of a support question.
 */
function productsInCategoryCount(categoryId: number | undefined): number {
  if (!categoryId) return 0

  return sellableProducts.value.filter((p) => p.category?.id === categoryId).length
}

/**
 * ── STEP 3 HAS AN ORDER OF ITS OWN NOW (2026-09-13) ──
 *
 * Owner, looking at a company with nothing configured: "ตอนนี้แดงไปหมด หาไม่เจอ
 * ต้องทำอะไรก่อนหลัง". Six red things at once — the banner, the 3.1 panel, and
 * one warning on each of four product rows in 3.2.
 *
 * WHAT WAS ACTUALLY WRONG. Red was doing two jobs: "this is broken" AND "you
 * are here". A colour that answers two questions answers neither, and the
 * second job was the one the admin needed. Worse, the four red rows were not
 * four problems. They were ONE problem — no company default — quoted four
 * times, because with no default nothing resolves for anything. Repeating a
 * warning once per row about a single cause is exactly what drowns the warning
 * that matters.
 *
 * THE RULE THIS ENCODES: at most ONE red thing on screen at a time, and it
 * belongs to the thing you must do NOW. So red stays "broken", a BRAND-coloured
 * frame plus a ทำตรงนี้ก่อน badge says "you are here", and 3.2 — every one of
 * whose warnings is downstream of 3.1 — is muted and locked until 3.1 exists.
 *
 * The one red thing that is NOT suppressed with them is the overlapping-rules
 * panel at the top of this step: two live rates fighting over one scope is a
 * different cause with a different cure (delete a row), not an echo of 3.1.
 */
const subStepThreeOneDone = computed(() => companyDefaultRules.value.length > 0)

/**
 * 3.2 is readable but inert until 3.1 exists.
 *
 * NOT exempted for a read-only viewer, and that is not the same call as
 * `stepReachable`'s Company-Admin exception. That exception exists because
 * gating a whole SCREEN on completion would lock a reader out of everything
 * they came to read. Nothing is withheld here: every row, its rate, the layer
 * it came from and its selling state all still render while locked. What goes
 * quiet is the four-times-repeated warning and controls a reader never had.
 */
/*
 * 2026-09-14 — THIS STOPPED BEING A LOCK.
 *
 * Owner, on the nine different ways this screen said "you can't": "ผมอยากให้
 * การ Lock disable กดเปิดปิด มันชัดเจนกว่านี้" — and the agreed shape
 * (แนวทาง C) was ONE lock on the whole screen, not five.
 *
 * Disabling 3.2 and 3.3 was always doing two jobs at once, and only one of
 * them was earned. The job worth keeping is SILENCE: with no company default
 * nothing resolves, so every product row was repeating 3.1's single complaint
 * once per product — four red rows for one cause, which is what made the
 * owner say "ตอนนี้แดงไปหมด หาไม่เจอต้องทำอะไรก่อนหลัง". The job that was NOT
 * earned is refusal: a product-scoped rate written before the company default
 * is unusual, not invalid, and the server accepts it.
 *
 * So the warnings stay suppressed and the CONTROLS are open, with one line of
 * advice instead of a padlock. Renamed from `companyDefaultMissing` so no
 * future reader re-derives a lock from the name.
 */
const companyDefaultMissing = computed(() => !subStepThreeOneDone.value)

/**
 * 3.1's one-line summary once it is done — the VALUE, not the explanation.
 *
 * A finished sub-step earns a line, not a panel: the "ตาข่ายกันพลาด" sentence
 * is teaching material for somebody who has not done it yet, and leaving it up
 * afterwards is how a screen ends up shouting all its instructions at once,
 * which is the complaint this whole change answers. More than one live default
 * is a collision, not a rate, so it says the count and lets the ซ้อนทับ badge
 * on the rows below say which ones.
 */
const companyDefaultSummary = computed<string>(() => {
  const rows = companyDefaultRules.value
  if (rows.length > 1) return `${rows.length} อัตราซ้อนทับกัน`

  const only = rows[0]

  return only ? formatRate(only.rate_type, only.rate_value) : ''
})

/*
 * 2026-09-14 — rateLayerLabel() WAS HERE.
 *
 * It was a pill on each product card reading "ใช้อัตราหมวดหมู่" or "ใช้ค่า
 * เริ่มต้นบริษัท" — one word for a whole ladder, computed in the browser. The
 * table says the same thing with three columns and a strikethrough, using the
 * server's answer, and it says WHAT THE LOSING RUNGS WOULD HAVE PAID, which
 * the pill never could.
 */

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
    void loadResolution()
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
/**
 * 2026-09-19 — WHOSE sales decide an agent's rank.
 *
 * 'personal' is today's behaviour and the column default: only what the agent
 * sold themselves. 'group' adds everyone below them in the manager tree.
 *
 * It matters more than it looks under Stairstep, which pays the DIFFERENCE
 * between a manager's rank rate and their downline's: on personal-only, a
 * leader who recruits instead of selling falls behind the people under them,
 * the differential goes to zero, and no ledger row is written at all.
 */
type RankVolumeScope = 'personal' | 'group'

interface AgentRankSettingsData {
  trailing_window_days: number
  volume_scope?: RankVolumeScope
  recalculation_frequency: RecalcFrequency
}
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
const rankSettingsForm = ref({
  trailing_window_days: '' as string | number,
  volume_scope: 'personal' as RankVolumeScope,
  recalculation_frequency: 'monthly' as RecalcFrequency,
})
const savingRankSettings = ref(false)
const rankError = ref('')
function syncRankSettingsForm(s: AgentRankSettingsData | null) {
  if (!s) return
  rankSettingsForm.value = {
    trailing_window_days: s.trailing_window_days,
    // Coalesced to 'personal', which is what the engine does for a row that
    // predates the column — see AgentRankSetting::volumeScope(). The screen
    // must show what WILL happen, not "nothing is set".
    volume_scope: s.volume_scope ?? 'personal',
    recalculation_frequency: s.recalculation_frequency,
  }
}
async function submitRankSettings() {
  savingRankSettings.value = true
  rankError.value = ''
  try {
    const res = await commissionApi.put<{ data: AgentRankSettingsData }>(
      `/agent-rank-settings${companyQuery()}`,
      withCompanyBody({
        trailing_window_days: Number(rankSettingsForm.value.trailing_window_days),
        volume_scope: rankSettingsForm.value.volume_scope,
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
  // Step 4's floor is per company too, and it is the one number on this screen
  // that would be actively misleading if it lagged the switcher — an admin
  // could save the previous company's floor onto this one.
  minWithdrawalMessage.value = ''
  void loadMinWithdrawal()
  void loadResolution()
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
  void loadMinWithdrawal()
  void loadResolution()
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
    <!-- 2026-09-15 — the title matches the menu it now sits under (ตั้งค่าระบบ
         → ตั้งค่าค่าแนะนำ). The page's own body still says "คอมมิชชั่น" in many
         places; renaming that vocabulary everywhere is a separate copy pass
         and was not part of this request. -->
    <HeroHeader
      icon="money"
      title="ตั้งค่าค่าแนะนำ"
      :subtitle="`กดทีละขั้น 1 → 4 · ตอนนี้อยู่ขั้นที่ ${activeStep} จาก 4`"
      description="Unilevel / Binary / Matrix / Stairstep-Breakaway / Generation / Affiliate — ตั้งครบทั้ง 4 ขั้นแล้วระบบถึงจะจ่ายค่าแนะนำได้"
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
              <p class="text-[17px] font-extrabold text-slate-900">ตั้งค่าแนะนำของบริษัทไหน</p>
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
              <p class="text-[12.5px] text-slate-500">คุณเปิดดูได้ทุกขั้นตอนแต่แก้ไขไม่ได้ — การตั้งค่าแนะนำแก้ไขได้เฉพาะ Super Admin · ติดต่อผู้ดูแลระบบหากต้องการเปลี่ยน</p>
            </div>

            <div v-if="effectiveCompanyId" class="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <div class="px-3 py-2.5 rounded-lg bg-slate-50 border border-slate-100">
                <p class="text-[11px] font-bold text-slate-400">สินค้าที่ต้องมีอัตรา</p>
                <p class="text-sm font-extrabold text-slate-900">{{ byCompany(products).length }} รายการ</p>
              </div>
              <div class="px-3 py-2.5 rounded-lg bg-slate-50 border border-slate-100">
                <p class="text-[11px] font-bold text-slate-400">แผนค่าแนะนำของบริษัท</p>
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
              <p class="text-[17px] font-extrabold text-slate-900">บริษัทนี้ใช้แผนค่าแนะนำแบบไหน</p>
              <p class="mt-1 text-[13px] text-slate-500">เลือกได้แผนเดียว — แผนที่เลือกจะเป็นตัวกำหนดว่าขั้นที่ 3 และ 4 มีอะไรให้ตั้งบ้าง</p>
            </div>

            <EmptyState
              v-if="activeCompany.requiresCompanyPick"
              icon="building"
              title="กรุณาเลือกบริษัทก่อน"
              message="กลับไปที่ขั้นที่ 1 แล้วเลือกบริษัท เพื่อดูและตั้งค่าแผนค่าแนะนำ"
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
                  การสลับแผนมีผลกับการขายครั้งถัดไปเท่านั้น — ค่าแนะนำที่ลงบัญชีไปแล้วไม่เปลี่ยนตาม
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
                <p class="text-[15px] font-extrabold text-slate-900">ค่าแนะนำคิดจากอะไร</p>
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
                    <p class="mt-0.5 text-[12.5px] text-rose-700">ระบบยังไม่ทราบว่าบริษัทนี้คิดค่าแนะนำจากราคาขายหรือ PV จึงยังไม่แสดงค่าที่เลือกไว้ — โหลดหน้านี้ใหม่อีกครั้ง หากยังไม่หายให้แจ้งผู้ดูแลระบบ</p>
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
                      <template v-if="b === 'price'">% คิดจากยอดที่ลูกค้าจ่ายจริง — ลดราคาเมื่อไร ค่าแนะนำลดตาม</template>
                      <template v-else>% คิดจาก PV ที่กำหนดไว้ให้สินค้าแต่ละตัว — ลดราคาแล้วค่าแนะนำไม่ลดตาม</template>
                    </span>
                  </button>
                </div>

                <p v-if="basisError" class="mt-2 text-[12.5px] font-bold text-rose-600">{{ basisError }}</p>
                <p v-if="!canEditCommissionConfig" class="mt-2 text-[12.5px] text-slate-400">
                  เปลี่ยนได้เฉพาะผู้ดูแลระบบ — ติดต่อผู้ดูแลระบบหากต้องการแก้ไข
                </p>
                <p class="mt-2 text-[12.5px] text-slate-400">
                  การเปลี่ยนฐานมีผลกับการขายครั้งถัดไปเท่านั้น — ค่าแนะนำที่ลงบัญชีไปแล้วไม่เปลี่ยนตาม
                </p>
              </div>

              <!--
                ═══ ผังและตัวอย่างของแผน (owner, 2026-09-19) ═══

                Owner, on this screen: "มันดูไม่ง่ายเลยในการ Setup ในแต่ละแผน
                ผมอยากได้แบบแผนภูมิ ที่เป็นตัวอย่างในแต่ละแบบ".

                It sits HERE, below both choices, because the example needs
                both of them: the plan decides who is in the picture, the
                basis decides what number the percentages are a percentage
                OF. Placed above the basis card it would have to guess one.

                2026-09-21 — OPEN by default, seeded with this company's own
                numbers, and carrying the real per-level rate form.

                The comment that used to sit here defended collapsing it:
                "an admin who already knows the plan they run should not have
                to scroll past a sandbox". That was the wrong trade. The
                owner's request was a chart AND "Setup ค่าคอมในแต่ละชั้นพร้อม
                ตัวอย่าง" — the setting and its consequence together — and
                collapsing it, seeding it with invented numbers and moving the
                real form to step 4 took all three apart.
              -->
              <PlanShapePreview
                :plan-type="viewingPlanType"
                :basis="commissionBasis"
                :seed="planShapeSeed"
                :live-level-rates="liveLevelRates"
                default-open
              >
                <!--
                  THE REAL LADDER, right of the diagram. Every keystroke moves
                  the chart; nothing is written until บันทึก, because each save
                  is an audited write to the table that decides what people are
                  paid.
                -->
                <template #rates="{ baseSatang, format, at: rateOf }">
                  <div data-test="level-ladder-editor">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                      <p class="text-[12.5px] font-bold text-slate-600">อัตราหัวหน้าทีมแต่ละชั้น</p>
                      <span v-if="levelLadderDirty" class="text-[11.5px] font-bold text-amber-700" data-test="level-ladder-dirty">
                        ยังไม่ได้บันทึก
                      </span>
                    </div>

                    <p v-if="!levelLadderDraft.length" class="mt-1.5 text-[12px] text-slate-500" data-test="level-ladder-empty">
                      ยังไม่ได้ตั้งอัตราตามชั้น — ตอนนี้หัวหน้าทีมยังไม่ได้ส่วนแบ่งตามชั้น
                      <span v-if="hasCompanyWideLeaderRate">(แต่ยังมีอัตราแบบใช้ทุกชั้นอยู่ ดูข้อ 4.3)</span>
                    </p>

                    <div v-else class="mt-1.5 space-y-1.5">
                      <div
                        v-for="(row, i) in levelLadderDraft"
                        :key="`ladder-${row.id ?? `new-${i}`}`"
                        class="grid grid-cols-[1fr_84px_110px] items-center gap-2"
                      >
                        <span class="text-[13px] text-slate-600">
                          ชั้น {{ row.level }}
                          <span v-if="row.level === 1" class="text-[11px] text-slate-400">· หัวหน้าโดยตรง</span>
                        </span>
                        <input
                          :value="row.percent"
                          type="number"
                          min="0"
                          max="100"
                          step="0.5"
                          :disabled="!canEditCommissionConfig || levelLadderSaving"
                          class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm text-right disabled:opacity-60"
                          :aria-label="`อัตราชั้นที่ ${row.level}`"
                          :data-test="`ladder-rate-${row.level}`"
                          @input="setLevelPercent(i, ($event.target as HTMLInputElement).value)"
                        />
                        <span class="text-right font-mono text-[13px] font-bold text-emerald-700">
                          {{ format(rateOf(baseSatang, row.percent)) }} บาท
                        </span>
                      </div>
                    </div>

                    <div v-if="canEditCommissionConfig" class="mt-2.5 flex flex-wrap gap-2">
                      <button
                        type="button"
                        class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-[12.5px] font-bold text-slate-600 transition-colors hover:border-brand-600 hover:text-brand-600 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="levelLadderSaving || levelLadderDraft.length >= MAX_PRICEABLE_LEVEL"
                        data-test="ladder-add"
                        @click="addLevelRung"
                      >+ เพิ่มชั้น</button>
                      <button
                        type="button"
                        class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-[12.5px] font-bold text-slate-600 transition-colors hover:border-brand-600 hover:text-brand-600 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="levelLadderSaving || !levelLadderDraft.length"
                        data-test="ladder-remove"
                        @click="removeLevelRung"
                      >− ลดชั้น</button>
                      <button
                        type="button"
                        class="btn-primary ml-auto"
                        :disabled="levelLadderSaving || !levelLadderDirty"
                        data-test="ladder-save"
                        @click="saveLevelLadder"
                      >{{ levelLadderSaving ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
                    </div>

                    <p v-if="levelLadderMessage" class="mt-1.5 text-[12.5px] font-bold text-slate-600" data-test="ladder-message">
                      {{ levelLadderMessage }}
                    </p>

                    <!--
                      The one contradiction these two screens could hide from
                      each other: the cap lives on step 4, the rungs live here,
                      and a ladder deeper than the cap means the bottom rungs
                      are priced and never paid. Nothing else would say so —
                      the rows look saved, because they are.
                    -->
                    <p
                      v-if="ladderRungsBeyondCap > 0"
                      class="mt-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-[12px] font-bold text-amber-800"
                      data-test="ladder-beyond-cap"
                    >
                      ตั้งไว้ {{ levelLadderDraft.length }} ชั้น แต่ขั้นที่ 4 จำกัดการจ่ายไว้ที่ {{ maxOverrideDepth }} ชั้น —
                      อีก {{ ladderRungsBeyondCap }} ชั้นล่างสุดจะไม่ได้เงิน
                    </p>
                    <p v-if="!canEditCommissionConfig" class="mt-1.5 text-[12px] text-slate-400">
                      ดูได้อย่างเดียว — การแก้อัตราค่าแนะนำเป็นสิทธิ์ของผู้ดูแลระบบ
                    </p>
                  </div>
                </template>
              </PlanShapePreview>

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
                      PV คือ "มูลค่าที่ใช้คิดค่าแนะนำ" ของสินค้า ตั้งเป็นบาทเหมือนราคา เช่น ราคา 8,900 แต่ให้ PV 1,000
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
                      {{ c.commission_ledger_id ? 'จ่ายแล้ว' : 'ไม่มีค่าแนะนำ' }}
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
                  <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider">อัตราค่าแนะนำตาม Level</h3>
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

                    <!--
                      2026-09-19 — WHOSE YODS COUNT. The setting that decides
                      whether this plan can pay a leader at all.

                      Stairstep pays the DIFFERENCE between a manager's rank
                      rate and their downline's. On personal-only volume a
                      leader who recruits instead of selling falls behind the
                      people under them, the differential goes to zero or
                      negative, and NO LEDGER ROW IS WRITTEN — the plan quietly
                      stops paying the person it exists to pay, and
                      is_breakaway_rank becomes unreachable, which leaves
                      Generation with no breakaway legs to count.

                      Default stays 'personal' because that is what every
                      company on this system computes today (BR-7: which one a
                      company promises its agents is theirs to decide).
                    -->
                    <div class="col-span-2">
                      <label class="text-sm font-bold text-slate-500">
                        นับยอดของใคร
                        <InfoPopover label="นับยอดของใคร">
                          <p>
                            <b>เฉพาะยอดตัวเอง (ค่าเริ่มต้น):</b> นับเฉพาะดีลที่คนนั้นปิดเอง —
                            เป็นแบบที่ระบบคิดอยู่ตอนนี้ทุกบริษัท
                          </p>
                          <p class="mt-2">
                            <b>ยอดทั้งทีม:</b> นับยอดตัวเอง <u>บวก</u> ยอดของทุกคนที่อยู่ใต้สายงาน
                          </p>
                          <p class="mt-2">
                            แผน Stairstep จ่าย<b>ส่วนต่าง</b>ระหว่างอัตราขั้นของหัวหน้ากับขั้นของลูกทีม
                            ถ้านับเฉพาะยอดตัวเอง หัวหน้าที่หันไปสร้างทีมจะมีขั้นต่ำกว่าลูกทีม
                            ส่วนต่างเป็นศูนย์ และ<b>ไม่มีการลงบัญชีเลย</b>
                          </p>
                          <p class="mt-2 text-slate-500">
                            ถ้าเปลี่ยนมาเป็น "ยอดทั้งทีม" ควร<b>ปรับยอดขั้นต่ำของแต่ละขั้นให้สูงขึ้น</b>ด้วย —
                            ตัวเลขนั้นเป็นของคุณกำหนด ระบบไม่ปรับให้เอง
                          </p>
                        </InfoPopover>
                      </label>
                      <select
                        v-model="rankSettingsForm.volume_scope"
                        data-test="rank-volume-scope"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white"
                      >
                        <option value="personal">เฉพาะยอดที่ขายเอง (ค่าเริ่มต้น — เท่าเดิม)</option>
                        <option value="group">ยอดทั้งทีม (ยอดตัวเอง + ทุกคนใต้สายงาน)</option>
                      </select>
                      <p
                        v-if="rankSettingsForm.volume_scope === 'group'"
                        class="mt-1.5 text-[12px] font-bold text-amber-700"
                        data-test="rank-volume-scope-warning"
                      >
                        ยอดของทุกคนจะสูงขึ้นทันทีที่คำนวณรอบถัดไป — ตรวจยอดขั้นต่ำของแต่ละขั้นข้างล่างก่อนบันทึก
                      </p>
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
                  <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider">อัตราค่าแนะนำตาม Generation</h3>
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
              <p class="text-[17px] font-extrabold text-slate-900">สมาชิกผู้ขายได้กี่เปอร์เซ็นต์</p>
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
              message="กลับไปที่ขั้นที่ 1 แล้วเลือกบริษัท เพื่อดูและตั้งอัตราค่าแนะนำ"
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
                <p class="text-sm font-bold text-rose-800">พบอัตราสมาชิกซ้อนทับกัน {{ conflictingRuleIds.size }} รายการ</p>
                <p class="mt-1 text-xs text-rose-700 leading-relaxed">
                  แถวที่ติดป้าย <b>ซ้อนทับ</b> ด้านล่างมีผลพร้อมกันในขอบเขตเดียวกัน — ระบบเรียงตามวันที่เริ่มมีผลแล้วหยิบอันแรก
                  <b>เมื่อวันเริ่มเท่ากันจึงหยิบอันไหนก็ได้ ทำนายไม่ได้</b> · ค่าแนะนำที่ลงบัญชีไปแล้วแก้ย้อนหลังไม่ได้
                  จึงควรลบให้เหลือรายการเดียวก่อนจะมีดีลปิดเพิ่ม
                </p>
                <p class="mt-1 text-xs text-rose-600">
                  ระบบไม่ยอมให้สร้างแบบนี้แล้วตั้งแต่ต้น — รายการเหล่านี้มักเป็นข้อมูลเก่าที่เคยแยกด้วยระดับใบรับรอง ก่อนเปลี่ยนกติกาเมื่อ 18 ส.ค. 2569
                </p>
              </div>

              <!-- ── 3.1 ค่าเริ่มต้นทั้งบริษัท ──
                   THE FRAME IS BRAND, NOT ROSE, AND THAT IS THE WHOLE FIX.
                   Red already means "this is broken" on this screen (the panel
                   inside this frame is red, and says so in one sentence). If
                   the frame were red too, the colour would be answering "what
                   is wrong" and "where do I start" at once — which is the
                   condition the owner described as แดงไปหมด หาไม่เจอต้องทำอะไร
                   ก่อนหลัง. One colour, one job: brand ring + ทำตรงนี้ก่อน is
                   "you are here", and it moves to 3.2 the moment this is done.
                   See `subStepThreeOneDone`. -->
              <div
                data-test="step3-company-default"
                class="rounded-2xl transition-colors"
                :class="subStepThreeOneDone ? '' : 'border-2 border-brand-500 ring-4 ring-brand-100 bg-brand-50/30 px-4 py-3.5'"
              >
                <div class="flex flex-wrap items-center gap-2 mb-2">
                  <span class="text-[13.5px] font-extrabold text-slate-900">3.1 ตั้งค่าเริ่มต้นทั้งบริษัทก่อน</span>
                  <span
                    v-if="!subStepThreeOneDone"
                    class="text-[11px] font-extrabold rounded-full px-2.5 py-1 bg-brand-600 text-white"
                    data-test="substep-focus-3-1"
                  >
                    ทำตรงนี้ก่อน
                  </span>
                  <span v-if="!subStepThreeOneDone" class="text-[11.5px] text-slate-400">ตาข่ายกันพลาด — สินค้าที่ยังไม่ได้ตั้งเรตจะตกลงมาใช้ค่านี้</span>
                  <!-- Done: one quiet line carrying the rate itself. The rows
                       below still render with their แก้ไข/ลบ — collapsing the
                       EXPLANATION must never collapse the ability to change
                       the number it explained. -->
                  <span v-else class="text-[11.5px] font-bold text-emerald-700" data-test="substep-3-1-summary">
                    ตั้งไว้แล้ว {{ companyDefaultSummary }} — สินค้าที่ไม่ได้แยกเรตใช้ค่านี้
                  </span>
                  <span
                    class="ml-auto text-[11px] font-bold rounded-full px-2.5 py-1"
                    :class="companyDefaultRules.length ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'"
                    data-test="company-default-pill"
                  >
                    {{ companyDefaultRules.length ? 'เรียบร้อย' : 'ยังไม่มี' }}
                  </span>
                </div>

                <div v-if="companyDefaultRulesAll.length" class="space-y-2">
                  <div
                    v-for="r in companyDefaultRulesAll"
                    :key="r.id"
                    class="flex flex-wrap items-center gap-3.5 rounded-xl border px-4 py-3"
                    :class="conflictingRuleIds.has(r.id) ? 'border-rose-300 bg-rose-50/40' : ruleDateStatus(r) ? 'border-slate-200 bg-slate-50/70' : 'border-slate-200'"
                    :data-test="`company-default-rule-${r.id}`"
                  >
                    <span class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-brand-50 text-brand-700">สมาชิกผู้ขาย</span>
                    <!-- Marked, not hidden. A row outside its dates is not
                         paying anybody today and still blocks a new rate from
                         being created over it — so it has to be visible and
                         deletable, with the reason on its face. -->
                    <span
                      v-if="ruleDateStatus(r)"
                      class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-slate-200 text-slate-600"
                      :data-test="`rule-date-status-${r.id}`"
                    >{{ ruleDateStatus(r) }}</span>
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

              <!-- ── 3.2 ตามหมวดหมู่สินค้า (NEW, 2026-09-14) ──

                   Owner: "การตั้งค่าแบบหมวดสินค้า ผมแทบไม่เห็นใน UI เลย" — and
                   he was right in the strongest possible way. The scope was
                   writable (a "+ เพิ่มอัตราของหมวดหมู่" button existed, down
                   with the product buttons) and the result was RENDERED
                   NOWHERE: 3.1 listed company-wide rows, 3.2 listed products,
                   and a category rate appeared only as a four-word badge on
                   whichever products happened to resolve to it. No row, no
                   rate, no dates, no แก้ไข, no ลบ.

                   A rate you can create and cannot see is worse than one you
                   cannot create: it pays real money, it cannot be corrected
                   once it reaches a ledger row (BR-4), and the only way to
                   find it was to guess it existed. So the middle rung of the
                   ladder gets the same box the other two have, in the position
                   the ladder puts it.

                   Locked behind 3.1 for the same reason the product box is: a
                   category rate written before the company default exists is
                   an exception to a rule nobody has decided yet. -->
              <div
                data-test="step3-categories"
                class="rounded-2xl border border-slate-200 bg-white px-4 py-3.5"
              >
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-[13.5px] font-extrabold text-slate-900">3.2 ตามหมวดหมู่สินค้า</span>
                  <span
                    class="text-[11px] font-bold rounded-full px-2 py-0.5"
                    :class="categoryRules.length ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-400'"
                  >{{ categoryRulesAll.length }}</span>
                  <span class="text-[11.5px] text-slate-400">ไม่บังคับ — ใช้เมื่อทั้งหมวดมาร์จิ้นต่างจากค่าเริ่มต้น</span>
                  <button
                    v-if="canEditCommissionConfig"
                    type="button"
                    class="ml-auto px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-slate-700 text-xs font-bold hover:bg-slate-50"
                    data-test="add-category-rate"
                    @click="openCreateRuleFormWithScope('category')"
                  >
                    + เพิ่มอัตราของหมวดหมู่
                  </button>
                </div>

                <p v-if="!categoryRulesAll.length" class="mt-2 text-[12px] text-slate-400" data-test="step3-categories-empty">
                  ยังไม่ได้ตั้ง — ทุกหมวดใช้ค่าเริ่มต้นทั้งบริษัทข้างบน
                </p>
                <TransitionGroup v-else tag="div" name="list-fade" class="mt-2 space-y-2">
                  <div
                    v-for="r in categoryRulesAll"
                    :key="`category-rule-${r.id}`"
                    class="flex flex-wrap items-center gap-3 rounded-xl border px-4 py-2.5"
                    :class="conflictingRuleIds.has(r.id) ? 'border-rose-300 bg-rose-50/40' : ruleDateStatus(r) ? 'border-slate-200 bg-slate-50/70' : 'border-slate-200'"
                    :data-test="`category-rule-${r.id}`"
                  >
                    <span class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-brand-50 text-brand-700">สมาชิกผู้ขาย</span>
                    <span
                      v-if="ruleDateStatus(r)"
                      class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-slate-200 text-slate-600"
                      :data-test="`rule-date-status-${r.id}`"
                    >{{ ruleDateStatus(r) }}</span>
                    <span v-if="conflictingRuleIds.has(r.id)" class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-rose-100 text-rose-700">ซ้อนทับ</span>
                    <span class="text-sm font-extrabold text-slate-900">{{ r.product_category?.name }}</span>
                    <span class="text-sm font-extrabold text-brand-700">{{ formatRate(r.rate_type, r.rate_value) }}</span>
                    <!-- How many products this row actually decides. A rate
                         scoped to a category nobody sells from is not an error,
                         but it is the single most likely reason an admin's
                         change "did nothing" — so the number is on the row
                         rather than left to be worked out. -->
                    <span class="text-xs text-slate-400">
                      ครอบคลุม {{ productsInCategoryCount(r.product_category?.id) }} สินค้า ·
                      มีผล {{ formatDate(r.effective_from) }}{{ r.effective_to ? ` ถึง ${formatDate(r.effective_to)}` : ' — ไม่มีวันสิ้นสุด' }}
                      <span v-if="r.renewal_rate_type"> · ต่ออายุ {{ formatRate(r.renewal_rate_type, r.renewal_rate_value!) }}</span>
                    </span>
                    <span v-if="canEditCommissionConfig" class="ml-auto flex items-center gap-3">
                      <button class="text-sm font-bold text-brand-700 hover:underline" @click="openEditRuleForm(r)">แก้ไข</button>
                      <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteRule(r)">ลบ</button>
                    </span>
                  </div>
                </TransitionGroup>
              </div>

              <!-- ── 3.3 อัตราต่อสินค้า และผลลัพธ์ ──

                   ONE TABLE, NOT A LIST PLUS A TABLE (owner, 2026-09-14:
                   "3.3 กับต่างผลลัพธ์ … รวมเป็นการแสดงผลเดียวได้ไหม").

                   There used to be a card list here — every product, the rate
                   that resolved, a selling switch, ทดสอบคำนวณ and one edit
                   button — and a resolution table directly underneath it
                   listing the same products again with all three rungs and the
                   baht each would pay. The duplication was mine: the note that
                   justified keeping the boxes ("a category rate covering no
                   product would go invisible") is true of 3.1 and 3.2, which
                   list RULES, and simply false of this one, which listed
                   PRODUCTS — the table's own rows.

                   So the list is gone and its three controls moved into the
                   table's rows. Two things had to change to make that safe,
                   both decided by the owner:

                     1. CLOSED PRODUCTS STAY LISTED ("แสดงไว้ ใช้แบบเดียวกับ
                        3.3 เดิมคือปรับค่าคอมได้ เปิดปิดได้"). The server no
                        longer filters by isSellableBy(); it flags instead.
                        Without that the switch that REOPENS a product would
                        have vanished with its row.
                     2. NOTHING IS HIDDEN BY DEFAULT. The table's old collapse
                        showed only "interesting" rows, which is right for an
                        explainer and wrong for the list where you check that
                        every product has been dealt with. Filter chips replace
                        it: the reader narrows, the table never does.
              -->
              <div
                data-test="step3-products"
                class="rounded-2xl transition-colors"
                :class="companyDefaultMissing
                  ? 'border border-slate-200 bg-white px-4 py-3.5'
                  : 'border-2 border-brand-500 ring-4 ring-brand-100 bg-brand-50/20 px-4 py-3.5'"
              >
                <div class="flex flex-wrap items-center gap-2 mb-2">
                  <span class="text-[13.5px] font-extrabold text-slate-900">3.3 อัตราต่อสินค้า และผลลัพธ์</span>
                  <!-- Not "ทำตรงนี้ก่อน": this is an exception list, not a duty
                       — a badge that ORDERED an optional refinement would be
                       the same lie as marking step 4 ยังไม่ครบ for being
                       skippable, and it is how a badge stops being read. -->
                  <span
                    v-if="!companyDefaultMissing"
                    class="text-[11px] font-extrabold rounded-full px-2.5 py-1 bg-brand-50 text-brand-700 border border-brand-200"
                    data-test="substep-focus-3-3"
                  >
                    ทำต่อได้ตรงนี้
                  </span>
                  <span v-if="!companyDefaultMissing" class="text-[11.5px] text-slate-400">
                    ไม่ต้องแยกทุกตัว — ที่ไม่แยกจะใช้{{ companyDefaultRules[0] ? ` ${formatRate(companyDefaultRules[0].rate_type, companyDefaultRules[0].rate_value)} ` : 'ค่าเริ่มต้น' }}ข้างบน
                  </span>
                </div>

                <!--
                  ADVICE, NOT A PADLOCK.
                  A product rate written before the company default is unusual,
                  not invalid, and the server takes it. What the admin actually
                  needs to know is the CONSEQUENCE of stopping here — products
                  they do not list get nothing — and that is a sentence, not a
                  refusal. The table below stays fully usable.
                -->
                <p
                  v-if="companyDefaultMissing"
                  class="mb-2.5 inline-flex items-center gap-2 text-[12.5px] text-slate-500"
                  data-test="substep-3-3-advice"
                >
                  <Icon name="info" :size="14" class="shrink-0 text-slate-400" />
                  แนะนำให้ตั้ง <b>ค่าเริ่มต้นทั้งบริษัทที่ 3.1</b> ก่อน — ไม่อย่างนั้นสินค้าที่ไม่ได้แยกเรตไว้จะไม่มีใครได้เงิน
                </p>

                <RateResolutionMatrix
                  kind="agent"
                  :rows="resolutionRows"
                  :loading="resolutionLoading"
                  :failed="resolutionFailed"
                  :can-edit="canEditCommissionConfig"
                  :basis-label="commissionBasis === 'pv' ? 'PV' : 'ยอดขาย'"
                  :mode-labels="overrideModeLabels"
                  show-selling
                  show-simulate
                  :togglable-product-ids="togglableProductIds"
                  :rateable-product-ids="rateableProductIds"
                  :saving-product-id="sellingSavingId"
                  :selling-error="sellingError || null"
                  test-id="step3-resolution"
                  @edit="editFromMatrix('agent', $event)"
                  @toggle-selling="toggleSellingFromMatrix"
                  @simulate="simulateFromMatrix"
                  @retry="loadResolution"
                >
                  <!-- Everything in an opened row that needs to know about the
                       OTHER steps. It lives here rather than in the component
                       for exactly that reason: a design-system table that knew
                       what step 4 was would not be one. -->
                  <template #row-detail="{ row }">
                    <p class="text-[12px] font-extrabold text-slate-500 mb-1.5">สถานะของสินค้านี้</p>
                    <p class="text-[12.5px] text-slate-500" :data-test="`product-plan-${row.product_id}`">
                      แผนค่าแนะนำ: {{ rowPlanLabel(row) }}
                    </p>

                    <p
                      v-if="rowExpiredRule(row)"
                      class="mt-1.5 text-[12.5px] font-bold"
                      :class="companyDefaultMissing ? 'text-amber-700' : 'text-rose-700'"
                      :data-test="`product-expired-${row.product_id}`"
                    >
                      อัตราหมดอายุ {{ formatDate(rowExpiredRule(row)!.effective_to!) }} — ปิดการขายแล้วไม่มีใครได้เงิน
                    </p>

                    <!-- Suppressed while 3.1 is missing: the 'bad' message is
                         verbatim what 3.1 says, once per product, and the
                         'warn' ones are advice about the share of a commission
                         that is not being paid at all yet. They come back the
                         moment 3.1 exists. -->
                    <div
                      v-else-if="!companyDefaultMissing && rowReadiness(row) && rowReadiness(row)!.level !== 'ok'"
                      class="mt-1.5 px-3 py-2 rounded-lg text-xs flex items-center justify-between gap-2 flex-wrap"
                      :class="rowReadiness(row)!.level === 'bad' ? 'bg-rose-100/60 text-rose-700' : 'bg-amber-50 text-amber-700'"
                      :data-test="`product-readiness-${row.product_id}`"
                    >
                      <span class="font-bold">{{ rowReadiness(row)!.level === 'bad' ? '●' : '!' }} {{ rowReadiness(row)!.message }}</span>
                      <!-- Points at the STEP that owns the gap, which is the
                           whole reason the steps exist: a structural gap is
                           step 2's, a leader-rate gap is step 4's, and neither
                           is fixable from here. -->
                      <button
                        v-if="rowStructureGap(row)"
                        type="button"
                        class="font-bold whitespace-nowrap hover:underline"
                        :data-test="`jump-structure-${row.product_id}`"
                        @click="viewPlan(rowStructureGap(row)!); goToStep(2)"
                      >
                        ไปขั้นที่ 2 ตั้งโครงสร้าง →
                      </button>
                      <button
                        v-else-if="rowReadiness(row)!.level === 'warn' && stepReachable[4]"
                        type="button"
                        class="font-bold whitespace-nowrap hover:underline"
                        :data-test="`jump-leader-${row.product_id}`"
                        @click="goToStep(4)"
                      >
                        ไปขั้นที่ 4 ตั้งอัตราหัวหน้าทีม →
                      </button>
                    </div>

                    <p
                      v-else-if="!companyDefaultMissing"
                      class="mt-1.5 text-xs font-bold text-emerald-700"
                      :data-test="`product-ready-${row.product_id}`"
                    >✓ ตั้งค่าครบ พร้อมจ่าย</p>
                  </template>
                </RateResolutionMatrix>
              </div>
            </template>
          </section>

          <!-- ═══════════ ขั้นที่ 4 · ส่วนเพิ่มเติม ═══════════ -->
          <section v-else class="space-y-4" data-test="step-panel-4">
            <div>
              <p class="text-[17px] font-extrabold text-slate-900">ส่วนเพิ่มเติม</p>
              <p class="mt-1 text-[13px] text-slate-500">
                ข้ามได้ทั้งหมด — ระบบจ่ายค่าแนะนำได้แล้วตั้งแต่จบขั้นที่ 3
              </p>
            </div>

            <!--
              THE ALERT THAT SAYS WHAT THIS STEP IS (2026-09-13).

              Owner: "เนื่องจากการ Setup step 4 เป็น Option ควรขึ้นคำอธิบายเป็น
              Alert ให้ผู้ใช้เข้าใจในกระบวนการ Setup ว่าอะไรทำอะไรบ้าง".

              Steps 1-3 each ask ONE question, so their heading is enough. This
              step is four unrelated settings that happen to share the property
              of being optional, and an admin arriving here has no way to tell
              whether they are looking at something they must finish. Naming
              the four, saying what each decides, and saying plainly that
              nothing here blocks a payout is the difference between "optional"
              as a word and "optional" as something the reader can act on.

              Sky rather than amber deliberately: every amber box on this
              screen means "something is wrong here". This one means the
              opposite, and borrowing the warning colour to say "you may stop"
              is how a screen teaches people to ignore its warnings.
            -->
            <div class="rounded-2xl border border-sky-200 bg-sky-50/70 p-4" data-test="step4-intro-alert">
              <div class="flex items-start gap-2.5">
                <Icon name="info" :size="18" class="text-sky-600 mt-0.5 shrink-0" />
                <div class="min-w-0">
                  <p class="text-[14px] font-extrabold text-sky-900">ขั้นนี้ไม่บังคับ — ข้ามไปได้เลยถ้ายังไม่ต้องใช้</p>
                  <p class="mt-1 text-[12.5px] text-sky-900/80">
                    จบขั้นที่ 3 แล้วระบบจ่ายค่าแนะนำให้ “สมาชิกที่ปิดการขาย” ได้ครบถ้วน · ขั้นที่ 4 คือการจ่ายให้ <b>คนอื่นนอกจากคนปิดการขาย</b>
                    และเงื่อนไขการเบิก — ตั้งเมื่อไหร่ก็ได้ ไม่มีอะไรในนี้ที่ทำให้ค่าแนะนำหยุดจ่าย
                  </p>
                  <ul class="mt-2.5 space-y-1.5 text-[12.5px] text-sky-900/90">
                    <!--
                      2026-09-14 — THE MODE COMES FIRST NOW (owner: "ผมสลับข้อ
                      4.4 มาเป็น 4.1").

                      It was last, after the three rate boxes, and that order
                      asked the admin to type a percentage before knowing what
                      the percentage was OF — the same 2% is 200 baht under one
                      mode and 6 under another. Deciding where the money comes
                      from is the question the rates are answers to, so it is
                      now 4.1 and the rates follow it as 4.2–4.4.
                    -->
                    <li class="flex gap-2">
                      <span class="font-extrabold shrink-0">4.1</span>
                      <span><b>เงินของหัวหน้าทีมมาจากไหน</b> — บริษัทจ่ายเพิ่ม หรือหักจากค่าแนะนำของคนปิดการขาย · ตั้งอันนี้ก่อน เพราะมันเปลี่ยนความหมายของ % ที่จะตั้งใน 4.3–4.5</span>
                    </li>
                    <!--
                      2026-09-15 — 4.2 JOINED THE LIST between "มาจากไหน" and
                      "เท่าไหร่", which is the order the three questions can
                      actually be answered in.
                    -->
                    <li class="flex gap-2">
                      <span class="font-extrabold shrink-0">4.2</span>
                      <span><b>ใครเป็นผู้รับ</b> — เฉพาะหัวหน้าทีมที่เป็นคนจริง หรือให้บริษัทนั่งยอดสุดของสายงานแล้วรับส่วนแบ่งจากทุกดีลด้วย</span>
                    </li>
                    <li class="flex gap-2">
                      <span class="font-extrabold shrink-0">4.3–4.5</span>
                      <span>
                        <b>อัตราหัวหน้าทีม</b> — หัวหน้าได้เท่าไหร่เมื่อลูกทีมปิดการขาย · แยกเป็น 3 ชั้น
                        (ค่าเริ่มต้นทั้งบริษัท / ตามหมวดหมู่ / ตามสินค้า) ชั้นที่เจาะจงกว่าทับชั้นที่กว้างกว่า ·
                        ไม่ตั้งเลย = หัวหน้าไม่ได้อะไร (สมาชิกยังได้ปกติ)
                      </span>
                    </li>
                    <li class="flex gap-2">
                      <span class="font-extrabold shrink-0">4.6</span>
                      <span><b>แบ่งค่าแนะนำผู้แนะนำ/ผู้ปิดการขาย</b> — ใช้เมื่อคนหาลูกค้ากับคนปิดดีลเป็นคนละคน</span>
                    </li>
                    <li class="flex gap-2">
                      <span class="font-extrabold shrink-0">4.7</span>
                      <span><b>ยอดขั้นต่ำในการเบิก</b> — สมาชิกต้องสะสมถึงเท่าไหร่จึงกดขอเบิกได้ · เว้นว่าง = ไม่มีขั้นต่ำ</span>
                    </li>
                  </ul>
                  <p class="mt-2.5 text-[12px] font-bold text-sky-900/70">
                    ทุกอย่างในขั้นนี้มีผลกับ <b>ดีลที่เกิดหลังจากบันทึก</b> เท่านั้น — รายการที่ลงบัญชีไปแล้วไม่ถูกแก้ย้อนหลัง
                  </p>
                </div>
              </div>
            </div>

            <EmptyState
              v-if="activeCompany.requiresCompanyPick"
              icon="building"
              title="กรุณาเลือกบริษัทก่อน"
              message="กลับไปที่ขั้นที่ 1 แล้วเลือกบริษัท เพื่อดูและตั้งค่าส่วนเพิ่มเติม"
            />
            <template v-else>
              <!--
                ═══ 4.1 — THE MOST MISUNDERSTOOD NUMBER IN THE SYSTEM ═══

                Owner, verbatim: "จุดที่คนเข้าใจผิดบ่อยที่สุด — 2% ไม่ได้หักจาก
                300 ของสมชาย · เรื่องนี้ต้องทำให้ชัดเจน และปรับได้ทั้งหักจาก
                สมชายปิดการขาย และบริษัทจ่ายเพิ่ม [ทำ UI ให้ผู้ใช้เข้าใจก่อน
                เลือกแบบใดแบบหนึ่ง]".

                The card shows ALL THREE ANSWERS IN BAHT BEFORE the admin
                picks, computed from this company's own rates. That ordering is
                the entire design: a selector that named three modes and left
                the arithmetic to be discovered at a payout would reproduce the
                misunderstanding it was built to end. Three named options with
                three numbers beside them is a choice; three names alone is a
                guess with extra steps.

                The comparison table is above the buttons for the same reason —
                you read the consequences, then you choose.

                ── AND IT IS FIRST IN THE STEP, AS OF 2026-09-14 ──

                Owner: "ผมสลับข้อ 4.4 มาเป็น 4.1 และข้อเดิม 4.1-4.3 ขยับลงมา
                เป็น 4.2-4.4".

                It used to sit BELOW the three rate boxes, which had the admin
                typing a percentage before knowing what the percentage was OF.
                Under บริษัทจ่ายเพิ่ม a leader's 2% is 2% of the sale; under
                หักจากค่าคอมของตัวแทน it is 2% of the seller's commission — the
                same two digits, thirty-three times apart. A screen that asks
                for the number first and the meaning second is asking the
                question in the wrong order, and this card exists precisely to
                stop that confusion.

                So: decide where the money comes from, THEN say how much.
              -->
              <div class="rounded-2xl border border-indigo-200 bg-indigo-50/40 p-4" data-test="step4-override-mode">
                <div class="flex flex-wrap items-center gap-2">
                  <p class="text-[15px] font-extrabold text-slate-900">
                    <span class="text-slate-400 mr-1.5">4.1</span>เงินของหัวหน้าทีมมาจากไหน (ค่าเริ่มต้นของบริษัท)
                  </p>
                  <span
                    v-if="!overrideModeUnknown"
                    class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-indigo-100 text-indigo-700"
                    data-test="override-mode-current"
                  >
                    ตอนนี้: {{ overrideModeLabels[overrideMode] }}
                  </span>
                </div>
                <p class="mt-1 text-[12.5px] text-slate-600">
                  ตัดสินว่าค่าแนะนำของหัวหน้าทีมเป็น <b>ต้นทุนใหม่ของบริษัท</b> หรือ <b>หักออกจากค่าแนะนำของคนปิดการขาย</b> ·
                  อันนี้คือ <b>ค่าเริ่มต้น</b> — อัตราในข้อ 4.3–4.5 ที่ไม่ได้เลือกโหมดของตัวเองจะใช้อันนี้ แต่แต่ละอัตราตั้งของตัวเองทับได้
                </p>

                <!-- The read failed, so the card does not know. Same defence as
                     step 2's basis: showing the default as the chosen answer
                     would state a wrong fact about somebody's pay. -->
                <div
                  v-if="overrideModeUnknown"
                  class="mt-3 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-3"
                  data-test="override-mode-unknown"
                >
                  <Icon name="warning" :size="18" class="text-rose-600 mt-0.5 shrink-0" />
                  <div>
                    <p class="text-[13px] font-extrabold text-rose-800">อ่านค่าปัจจุบันไม่สำเร็จ</p>
                    <p class="mt-0.5 text-[12.5px] text-rose-700">
                      ระบบยังไม่รู้ว่าบริษัทนี้ใช้โหมดไหน จึงยังไม่แสดงว่าอันไหนถูกเลือก — โหลดหน้าใหม่อีกครั้ง
                    </p>
                  </div>
                </div>

                <template v-else>
                  <!-- THE TABLE. One sale, one leader above the seller. -->
                  <div class="mt-3 rounded-xl border border-indigo-200 bg-white/90 overflow-hidden" data-test="override-mode-example">
                    <div class="px-3.5 py-2.5 border-b border-indigo-100 bg-indigo-50/60">
                      <p class="text-[12.5px] font-bold text-slate-700">
                        ตัวอย่างจากอัตราจริงของบริษัทนี้ — ขาย “{{ overrideModeExample.productName }}”
                      </p>
                      <p class="text-[12px] text-slate-500 mt-0.5">
                        {{ overrideModeExample.baseLabel }} {{ formatSatang(overrideModeExample.baseSatang) }} ·
                        สมาชิก {{ overrideModeExample.sellerRateLabel }} · หัวหน้าทีม {{ overrideModeExample.leaderRateLabel }} · หัวหน้า 1 คน
                      </p>
                      <!-- Labelled, not hidden. A company with no rates yet is
                           exactly the one that has to understand the modes
                           BEFORE it sets any — so the example falls back to a
                           round illustration and says so, rather than
                           disappearing and leaving the choice unexplained. -->
                      <p v-if="overrideModeExample.hypothetical" class="text-[12px] font-bold text-amber-700 mt-1" data-test="override-example-hypothetical">
                        ⚠ ยังไม่มีสินค้าที่มีทั้งอัตราสมาชิกและอัตราหัวหน้าทีม — ตัวเลขข้างล่างเป็น <b>ตัวอย่างสมมติ</b> เพื่ออธิบายเท่านั้น
                      </p>
                    </div>
                    <table class="w-full text-[12.5px]">
                      <thead>
                        <tr class="text-slate-500 bg-white">
                          <th class="text-left font-bold px-3.5 py-2">โหมด</th>
                          <th class="text-right font-bold px-3.5 py-2">คนปิดการขายได้</th>
                          <th class="text-right font-bold px-3.5 py-2">หัวหน้าทีมได้</th>
                          <th class="text-right font-bold px-3.5 py-2">บริษัทจ่ายรวม</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="opt in overrideModeOptions"
                          :key="`row-${opt.value}`"
                          class="border-t border-slate-100"
                          :class="opt.value === overrideMode ? 'bg-indigo-50/80 font-bold text-slate-900' : 'text-slate-600'"
                          :data-test="`override-mode-row-${opt.value}`"
                        >
                          <td class="px-3.5 py-2">
                            {{ opt.title }}
                            <span v-if="opt.value === overrideMode" class="ml-1 text-[11px] text-indigo-600">← ใช้อยู่</span>
                          </td>
                          <td class="px-3.5 py-2 text-right tabular-nums">{{ formatSatang(overrideModeExample.rows[opt.value].sellerSatang) }}</td>
                          <td class="px-3.5 py-2 text-right tabular-nums">{{ formatSatang(overrideModeExample.rows[opt.value].leaderSatang) }}</td>
                          <td class="px-3.5 py-2 text-right tabular-nums">{{ formatSatang(overrideModeExample.rows[opt.value].companyPaysSatang) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>

                  <!-- The depth, and what it does to the two deduct modes. The
                       example above deliberately shows ONE leader because that
                       is the smallest case where the three modes disagree; this
                       line is where the real chain gets said out loud, because
                       multiplying the table by five managers makes the numbers
                       dramatic and the comparison unreadable. -->
                  <div class="mt-2.5 text-[12.5px] text-slate-600" data-test="override-mode-chain">
                    <!--
                      2026-09-15 — the third state. A company with a house
                      account can never read "ยังไม่มีสายงาน" again, because the
                      seat itself is a layer — and the reader has to know that
                      the layer they are being charged for is the company, not
                      a person they forgot about.
                    -->
                    <p v-if="deepestManagerChain === 0">
                      ตอนนี้บริษัทนี้ <b>ยังไม่มีสายงาน</b> (ไม่มีใครมีหัวหน้า) — ยังไม่มีใครได้ค่าแนะนำหัวหน้าทีม ไม่ว่าจะเลือกโหมดไหน
                    </p>
                    <template v-else>
                      <p>
                        สายงานลึกที่สุดตอนนี้ <b>{{ deepestManagerChain }} ชั้น</b><template v-if="houseAccount"> (รวมบัญชีบริษัท 1 ชั้น)</template> — ดีลหนึ่งอาจมีหัวหน้าได้ถึง {{ deepestManagerChain }} คน
                        และแบบ “หักจากสมาชิก” จะหัก <b>{{ deepestManagerChain }} เท่า</b>ของตัวเลขในตาราง
                      </p>
                      <p v-if="maxOverridePerLevelSatang !== null" class="mt-1">
                        ดังนั้นถ้าเลือกแบบหัก อัตราหัวหน้าทีมจะตั้งได้ไม่เกิน <b>{{ formatSatang(maxOverridePerLevelSatang) }} ต่อชั้น</b>
                        (คิดจากสินค้าที่สมาชิกได้ค่าแนะนำน้อยที่สุด) — เกินกว่านี้ระบบจะไม่ให้บันทึก
                      </p>
                    </template>
                  </div>

                  <!-- THE CHOICE. Hidden entirely from a Company Admin, per the
                       house rule ("อันไหนสิทธิ์ company admin ทำไม่ได้ต้องซ่อน
                       ไม่ใช่ให้ error 403") — the table above still explains
                       what their company does, which is the half they are
                       allowed to know. -->
                  <div v-if="canEditCommissionConfig && effectiveCompanyId" class="mt-3 grid grid-cols-1 gap-2" data-test="override-mode-picker">
                    <button
                      v-for="opt in overrideModeOptions"
                      :key="`pick-${opt.value}`"
                      type="button"
                      class="text-left rounded-xl border px-3.5 py-3 transition-colors disabled:opacity-60"
                      :class="opt.value === overrideMode
                        ? 'border-indigo-400 bg-indigo-100/70'
                        : 'border-slate-200 bg-white hover:border-indigo-300'"
                      :disabled="overrideModeSaving"
                      :data-test="`override-mode-pick-${opt.value}`"
                      @click="setOverrideMode(opt.value)"
                    >
                      <span class="flex items-center gap-2">
                        <Icon v-if="opt.value === overrideMode" name="check" :size="14" class="text-indigo-600" />
                        <span class="text-[13.5px] font-extrabold text-slate-900">{{ opt.title }}</span>
                      </span>
                      <span class="block mt-0.5 text-[12.5px] text-slate-600">{{ opt.oneLine }}</span>
                      <span class="block mt-1 text-[12px] text-slate-500">{{ opt.detail }}</span>
                    </button>
                  </div>

                  <p v-if="overrideModeError" class="mt-2 text-[12.5px] font-bold text-rose-600" data-test="override-mode-error">
                    {{ overrideModeError }}
                  </p>
                  <p v-else-if="canEditCommissionConfig" class="mt-2 text-[12px] text-slate-500">
                    เปลี่ยนโหมดมีผลกับดีลที่เกิดหลังจากนี้เท่านั้น · รายการที่ลงบัญชีไปแล้วเก็บโหมดเดิมไว้กับตัวมันเอง แก้ย้อนหลังไม่ได้
                  </p>
                </template>
              </div>

              <!--
                ═══ 4.2 — WHO RECEIVES THE LEADER'S SHARE ═══

                Owner, 2026-09-15: "หัวหน้าทีมในที่นี้มีได้ 2 ความหมาย คือหัวหน้า
                ทีมที่เป็น user จริงในระบบ กับหัวหน้าทีมที่เป็นตัวบริษัทเองที่ได้
                ค่าคอมจากการขาย เช่น Thailife หากไม่มีการตั้งหัวหน้าทีม".

                BETWEEN THE MODE AND THE RATES ON PURPOSE. The reading order of
                this step is now "เงินมาจากไหน → ใครรับ → เท่าไหร่", and each
                question is only answerable once the one before it is. Putting
                this after the rates would have an admin type a percentage
                before knowing who it is for.

                The company takes a seat at the TOP of its own hierarchy — a
                real user row, so the payout walk reaches it with no second
                kind of recipient anywhere in the money code. The consequence
                the owner accepted after seeing it three times is stated in the
                box rather than buried: the company sits ABOVE any human
                leader, so it is paid on EVERY deal, and a seller with a leader
                funds both.
              -->
              <div
                class="rounded-2xl border p-4"
                :class="houseAccount ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200 bg-white'"
                data-test="step4-house-account"
              >
                <div class="flex flex-wrap items-center gap-2">
                  <p class="text-[15px] font-extrabold text-slate-900">
                    <span class="text-slate-400 mr-1.5">4.2</span>ใครเป็นผู้รับค่าแนะนำหัวหน้าทีม
                  </p>
                  <span
                    class="text-[11px] font-bold rounded-full px-2.5 py-1"
                    :class="houseAccount ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                    data-test="house-account-state"
                  >
                    {{ houseAccount ? 'บริษัทรับด้วย' : 'เฉพาะหัวหน้าทีมที่เป็นคนจริง' }}
                  </span>
                </div>

                <!-- STATE A — no seat. Says what is happening now, then what
                     would change, in money terms rather than in settings. -->
                <template v-if="!houseAccount">
                  <p class="mt-1 text-[12.5px] text-slate-500">
                    ตอนนี้ค่าแนะนำหัวหน้าทีมจ่ายให้เฉพาะ <b>คนจริงที่อยู่เหนือคนปิดการขาย</b> ตามสายงาน —
                    สมาชิกที่ไม่มีหัวหน้า ขายแล้วไม่มีใครได้ส่วนนี้ และบริษัทไม่ได้เก็บไว้ด้วย
                  </p>
                  <div v-if="canEditCommissionConfig && !showHouseAccountConfirm" class="mt-3">
                    <button type="button" class="btn-primary" data-test="house-account-enable" @click="openHouseAccountConfirm">
                      ให้บริษัทรับค่าแนะนำหัวหน้าทีมด้วย
                    </button>
                  </div>

                  <!--
                    THE CONFIRMATION NAMES BOTH THINGS THAT ARE HARD TO UNDO.
                    Attaching every unmanaged agent is a structural edit nobody
                    asked for by name, and the deduction starts on the next
                    deal — a ledger row cannot be corrected afterwards (BR-4).
                    A confirm that only said "ยืนยัน" would be a button, not a
                    decision.
                  -->
                  <div
                    v-if="showHouseAccountConfirm"
                    class="mt-3 rounded-xl border border-emerald-300 bg-white p-3.5"
                    data-test="house-account-confirm"
                  >
                    <label class="block text-[12px] font-extrabold text-slate-500 mb-1">ชื่อที่จะแสดงในรายงาน</label>
                    <input
                      v-model="houseAccountName"
                      type="text"
                      maxlength="120"
                      class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
                      data-test="house-account-name"
                      placeholder="ชื่อบริษัท"
                    />
                    <ul class="mt-2.5 space-y-1 text-[12.5px] text-slate-600">
                      <li>· สมาชิกที่ยังไม่มีหัวหน้าทุกคนจะถูกผูกเข้าสายงานใต้บัญชีนี้ทันที</li>
                      <li>· บริษัทอยู่<b>ยอดสุด</b> จึงได้ส่วนแบ่งจาก<b>ทุกดีล</b> ไม่ใช่เฉพาะดีลที่ไม่มีหัวหน้า</li>
                      <li>· สายงานลึกขึ้น 1 ชั้น เพดานอัตราหัวหน้าทีมที่ตั้งได้จะแคบลง</li>
                      <li>· มีผลกับดีลที่เกิดหลังจากนี้เท่านั้น รายการที่ลงบัญชีไปแล้วไม่เปลี่ยน</li>
                    </ul>
                    <div class="mt-3 flex flex-wrap gap-2">
                      <button
                        type="button"
                        class="btn-primary"
                        :disabled="houseAccountSaving"
                        data-test="house-account-confirm-save"
                        @click="saveHouseAccount(true)"
                      >
                        {{ houseAccountSaving ? 'กำลังบันทึก…' : 'ยืนยัน ให้บริษัทรับด้วย' }}
                      </button>
                      <button type="button" class="btn-secondary" :disabled="houseAccountSaving" @click="showHouseAccountConfirm = false">
                        ยกเลิก
                      </button>
                    </div>
                  </div>
                </template>

                <!-- STATE B — the seat exists. The two counts are what tell a
                     working seat from one attached to nobody; "on" alone would
                     not. -->
                <template v-else>
                  <p class="mt-1 text-[12.5px] text-slate-500">
                    บริษัทอยู่ยอดสุดของสายงาน — ได้ส่วนแบ่งหัวหน้าทีมจากทุกดีล ตามอัตราในข้อ 4.3–4.5
                    <span v-if="houseAccount.agents_under === 0" class="font-bold text-amber-700">
                      · ตอนนี้ยังไม่มีใครขึ้นตรงกับบัญชีนี้ จึงยังไม่ได้รับอะไร
                    </span>
                  </p>
                  <div class="mt-2.5 rounded-xl border border-emerald-200 bg-white px-3.5 py-3 flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div>
                      <p class="text-[11px] font-extrabold text-slate-400">บัญชีที่รับเงิน</p>
                      <p class="text-sm font-extrabold text-slate-900" data-test="house-account-name-shown">{{ houseAccount.name }}</p>
                      <!-- The one edit this row allows. Every other one is
                           refused on purpose, which is why it needs its own
                           button here instead of a link to จัดการผู้ใช้. -->
                      <button
                        v-if="canEditCommissionConfig && !renamingHouseAccount"
                        type="button"
                        class="mt-0.5 text-[11.5px] font-bold text-brand-600 hover:text-brand-700"
                        data-test="house-account-rename-open"
                        @click="openHouseAccountRename"
                      >
                        แก้ไขชื่อ / บัญชีรับเงิน
                      </button>
                    </div>
                    <!--
                      2026-09-15 (ครั้งที่สอง) — where this money is transferred.
                      Said on the summary row, not only inside the form, because
                      the payout screen refuses to tick this payee without it and
                      this is the screen that fixes that.
                    -->
                    <div>
                      <p class="text-[11px] font-extrabold text-slate-400">บัญชีรับเงินของบริษัท</p>
                      <p
                        v-if="houseAccount.payout_details_complete"
                        class="text-sm font-extrabold text-slate-900"
                        data-test="house-account-bank-shown"
                      >
                        {{ houseAccount.bank_name }} · {{ houseAccount.bank_account_number }}
                      </p>
                      <p v-else class="text-sm font-extrabold text-amber-700" data-test="house-account-bank-missing">
                        ยังไม่ได้กรอก — ตั้งจ่ายส่วนของบริษัทไม่ได้
                      </p>
                    </div>
                    <div>
                      <p class="text-[11px] font-extrabold text-slate-400">ขึ้นตรงกับบัญชีนี้</p>
                      <p class="text-sm font-extrabold text-slate-900 tabular-nums" data-test="house-account-agents">{{ houseAccount.agents_under }} คน</p>
                    </div>
                    <div>
                      <p class="text-[11px] font-extrabold text-slate-400">ได้รับสะสม</p>
                      <p class="text-sm font-extrabold text-slate-900 tabular-nums" data-test="house-account-earned">{{ formatSatang(houseAccount.earned_satang) }}</p>
                    </div>
                    <button
                      v-if="canEditCommissionConfig"
                      type="button"
                      class="ml-auto text-xs font-bold text-rose-600 hover:text-rose-700"
                      :disabled="houseAccountSaving"
                      data-test="house-account-disable"
                      @click="saveHouseAccount(false)"
                    >
                      {{ houseAccountSaving ? 'กำลังบันทึก…' : 'ปิดใช้งาน' }}
                    </button>
                  </div>
                  <div v-if="renamingHouseAccount" class="mt-2.5 rounded-xl border border-brand-200 bg-white px-3.5 py-3" data-test="house-account-rename">
                    <label class="block text-[12px] font-bold text-slate-600 mb-1" for="house-account-rename-input">
                      ชื่อที่จะแสดงบนรายการค่าแนะนำของบริษัท
                    </label>
                    <input
                      id="house-account-rename-input"
                      v-model="houseAccountRename"
                      type="text"
                      maxlength="120"
                      class="w-full max-w-sm px-3 py-2 rounded-lg border border-slate-200 text-sm"
                      data-test="house-account-rename-input"
                    />
                    <p class="mt-1 text-[11.5px] text-slate-500">
                      เปลี่ยนเฉพาะชื่อที่แสดง · ไม่กระทบว่าใครขึ้นตรงกับบัญชีนี้ หรือค่าแนะนำที่ได้รับไปแล้ว
                    </p>

                    <!--
                      THE ACCOUNT THE COMPANY'S OWN SHARE IS TRANSFERRED INTO.
                      Three fields, the same three every agent is paid through,
                      so the bank file has one set of columns. Not asked for an
                      identity document: a company has none, and asking would
                      refuse every company payout forever while blaming
                      paperwork nobody can supply.
                    -->
                    <div class="mt-3 pt-3 border-t border-slate-100">
                      <p class="text-[12px] font-bold text-slate-600 mb-1.5">บัญชีรับเงินของบริษัท</p>
                      <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 max-w-2xl">
                        <input
                          v-model="houseAccountBank.bank_name"
                          type="text"
                          placeholder="ธนาคาร"
                          class="px-3 py-2 rounded-lg border border-slate-200 text-sm"
                          data-test="house-account-bank-name"
                        />
                        <input
                          v-model="houseAccountBank.bank_account_number"
                          type="text"
                          inputmode="numeric"
                          placeholder="เลขที่บัญชี"
                          class="px-3 py-2 rounded-lg border border-slate-200 text-sm"
                          data-test="house-account-bank-number"
                        />
                        <input
                          v-model="houseAccountBank.bank_account_holder_name"
                          type="text"
                          placeholder="ชื่อบัญชี"
                          class="px-3 py-2 rounded-lg border border-slate-200 text-sm"
                          data-test="house-account-bank-holder"
                        />
                      </div>
                      <p class="mt-1 text-[11.5px] text-slate-500">
                        กรอกครบทั้งสามช่องแล้วจึงจะตั้งจ่ายส่วนของบริษัทได้ที่หน้า ตั้งจ่าย · เว้นว่างไว้เพื่อลบบัญชีออก
                      </p>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                      <button
                        type="button"
                        class="btn-primary"
                        :disabled="houseAccountSaving || houseAccountRename.trim() === ''"
                        data-test="house-account-rename-save"
                        @click="renameHouseAccount"
                      >
                        {{ houseAccountSaving ? 'กำลังบันทึก…' : 'บันทึก' }}
                      </button>
                      <button type="button" class="btn-secondary" :disabled="houseAccountSaving" @click="renamingHouseAccount = false">
                        ยกเลิก
                      </button>
                    </div>
                  </div>

                  <!-- Not offered as an undo: the rows it was already paid stay
                       where they are, because a ledger row is immutable. -->
                  <p class="mt-2 text-[12px] text-slate-500">
                    ปิดใช้งานแล้วสมาชิกที่ขึ้นตรงกับบัญชีนี้จะกลับไปไม่มีหัวหน้า · ค่าแนะนำที่บริษัทได้รับไปแล้วยังอยู่ตามเดิม
                  </p>
                </template>

                <p v-if="houseAccountError" class="mt-2 text-[12.5px] font-bold text-rose-600" data-test="house-account-error">
                  {{ houseAccountError }}
                </p>
              </div>

              <!--
                ═══ THE LEADERS THIS PLAN WILL PAY NOTHING (2026-09-15) ═══

                ADR-035 makes a certification a GATE on being paid an
                override: an uncertified manager is SKIPPED by the payout
                walk. Not paid less — skipped, with no error, no log line and
                no ledger row. Every box on this screen can be filled in
                correctly and the money still goes nowhere.

                Sits here, between "who receives" and "how much", because that
                is where the reader is deciding who the rates are for. Shown
                only when there is somebody to name: a permanent advisory
                about a thing that is not happening is the kind of warning
                people learn to scroll past, and this one has to still be
                readable on the day it appears.
              -->
              <div
                v-if="uncertifiedLeaders.total > 0"
                class="rounded-2xl border border-amber-300 bg-amber-50/70 p-4"
                data-test="step4-uncertified-leaders"
              >
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-[11px] font-extrabold text-amber-700 bg-amber-100 rounded px-1.5 py-0.5">ตรวจสอบก่อน</span>
                  <h4 class="text-[14px] font-extrabold text-slate-900">
                    มีหัวหน้าทีม {{ uncertifiedLeaders.total }} คนที่จะ<span class="text-amber-700">ไม่ได้รับ</span>ส่วนแบ่ง
                  </h4>
                </div>
                <p class="mt-1 text-[12.5px] text-slate-600">
                  คนเหล่านี้มีลูกทีมอยู่จริง แต่ยังไม่ผ่านเกณฑ์ใบรับรอง — ระบบจะ<b>ข้ามไปเงียบ ๆ</b>
                  ตอนคำนวณค่าแนะนำ ไม่มีข้อความแจ้งเตือน และไม่มีรายการค้างไว้ให้ตามทีหลัง
                  ตั้งอัตราในข้อ 4.3–4.5 ไว้เท่าไรก็ไม่มีผลจนกว่าจะผ่านเกณฑ์
                </p>
                <ul class="mt-2.5 flex flex-wrap gap-2">
                  <li
                    v-for="leader in namedLeaders"
                    :key="`uncertified-${leader.id}`"
                    class="rounded-lg border border-amber-200 bg-white px-2.5 py-1.5"
                    :data-test="`uncertified-leader-${leader.id}`"
                  >
                    <span class="text-[12.5px] font-bold text-slate-900">{{ leader.name }}</span>
                    <span class="ml-1.5 text-[11.5px] text-slate-500 tabular-nums">ลูกทีม {{ leader.agents_under }} คน</span>
                  </li>
                </ul>
                <!-- The list is capped; the count is not. "3 of 47" is a
                     different situation from "3". -->
                <p v-if="unnamedLeaderCount > 0" class="mt-2 text-[12px] text-slate-500" data-test="uncertified-leaders-more">
                  และอีก {{ unnamedLeaderCount }} คน (แสดงเฉพาะคนที่มีลูกทีมมากที่สุด)
                </p>
              </div>

              <!--
                ═══ 4.3 / 4.4 / 4.5 — ONE BOX PER LAYER OF THE LADDER ═══

                Owner, 2026-09-14: "การตั้งค่าใน Step ที่ 4 ต้องต่างกันทั้งหมด
                แต่ตอนนี้เป็นตัวเลือกการทำงานแบบอย่างเดียว" and "การตั้งค่าแบบ
                หมวดสินค้า ผมแทบไม่เห็นใน UI เลย".

                Two reports, one defect. The leader rate is scoped product >
                category > company — a LADDER the server walks — and this card
                rendered all three layers as one flat list fed by one dropdown
                buried in a modal. You could not see which layer you had
                configured, which was empty, or which one a row belonged to
                without reading its label; the category layer in particular was
                effectively invisible, which is exactly what the owner hit.

                Three boxes, in the order a human SETS them (broadest first),
                each owning one layer and carrying its own + button. The
                resolution order is the reverse and is stated in words under the
                heading, because the two orders being opposite is precisely the
                thing that has to not be guessed.

                2026-09-14 — these were 4.1–4.3, then 4.2–4.4, and are now 4.3–4.5 as
                the mode and then the payee moved above them. See those cards:
                every percentage typed here means something different depending
                on the answer given up there, so the answer comes first.
              -->
              <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4" data-test="step4-leader-rates">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <p class="text-[15px] font-extrabold text-slate-900">อัตราหัวหน้าทีม</p>
                  <span
                    class="text-[11px] font-bold rounded-full px-2.5 py-1"
                    :class="activeOverrideRules.length ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                  >
                    {{ activeOverrideRules.length ? `${activeOverrideRules.length} อัตราที่ใช้อยู่` : 'ยังไม่ได้ตั้ง' }}
                  </span>
                </div>
                <p class="text-[12.5px] text-slate-500 mb-1">
                  จ่ายให้หัวหน้าทีมทุกครั้งที่ลูกทีมปิดการขาย · แผน <b>Unilevel</b> จ่ายขึ้นไปทั้งสาย · แผน <b>พันธมิตร (Affiliate)</b> จ่ายชั้นเดียว
                </p>
                <p class="text-[12.5px] text-amber-800 mb-3">
                  ถ้าสินค้าหนึ่งเข้าเงื่อนไขหลายกล่อง ระบบใช้ <b>กล่องที่เจาะจงที่สุดเพียงกล่องเดียว</b> — ตามสินค้า ก่อน ตามหมวดหมู่ ก่อน ค่าเริ่มต้นทั้งบริษัท · ไม่เอามาบวกกัน
                </p>

                <div v-if="leaderRateGaps.length" class="mb-3 px-3 py-2 rounded-lg bg-amber-100/70 text-xs font-bold text-amber-800">
                  สินค้า {{ leaderRateGaps.length }} รายการยังไม่มีอัตราหัวหน้าทีมที่ใช้ได้ — สมาชิกได้ตามปกติ แต่หัวหน้าจะไม่ได้ส่วนแบ่งจากดีลนั้น
                </div>

                <!--
                  ═══ 4.2b — HOW FAR UP, AND WHO INHERITS A SKIPPED LEVEL ═══

                  Unilevel only. It is the one plan whose payout walks the
                  manager chain level by level
                  (CommissionService::resolveUnilevelOverrides): Affiliate pays
                  one level by definition, and Binary / Matrix / Stairstep /
                  Generation each have their own structure tab. A knob that did
                  nothing on four of six plans is exactly the confusion this
                  screen exists to remove.

                  Placed ABOVE the three rate boxes because it is the frame
                  they sit in: "how many levels do we pay" has to be answered
                  before "what does each one get", and an admin who sets three
                  levels of rate under a cap of 1 has been allowed to do a
                  contradictory thing quietly.

                  Both controls are gated on canEditCommissionConfig, unlike
                  the withdrawal floor below: they write through
                  /commission-settings, which is Super-Admin-only.
                -->
                <div
                  v-if="levelLadderApplies"
                  class="mb-3 rounded-xl border border-amber-200 bg-white/70 p-3.5"
                  data-test="step4-level-ladder"
                >
                  <p class="text-[13.5px] font-extrabold text-slate-900">
                    <span class="text-slate-400 mr-1.5">4.2</span>จ่ายขึ้นไปกี่ชั้น
                  </p>
                  <p class="mt-0.5 text-[12px] text-slate-500">
                    ทุกวันนี้จ่ายขึ้นไปทั้งสาย ไม่จำกัด · สายที่ลึกที่สุดของบริษัทตอนนี้คือ
                    <b>{{ deepestManagerChain }}</b> ชั้น
                  </p>

                  <div class="mt-2.5 flex flex-wrap gap-2 max-w-md">
                    <input
                      v-model="maxOverrideDepth"
                      type="number"
                      min="1"
                      :max="MAX_PRICEABLE_LEVEL"
                      step="1"
                      placeholder="เว้นว่าง = ทั้งสาย"
                      :disabled="!canEditCommissionConfig || depthSaving"
                      class="flex-1 min-w-[10rem] px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                      data-test="override-depth-input"
                    />
                    <button
                      v-if="canEditCommissionConfig"
                      type="button"
                      class="btn-primary"
                      :disabled="depthSaving"
                      data-test="override-depth-save"
                      @click="saveMaxOverrideDepth"
                    >
                      {{ depthSaving ? 'กำลังบันทึก...' : 'บันทึก' }}
                    </button>
                  </div>
                  <p v-if="depthMessage" class="mt-1.5 text-[12.5px] font-bold text-slate-600" data-test="override-depth-message">
                    {{ depthMessage }}
                  </p>

                  <!--
                    THE COMPRESSION SWITCH.

                    A real difference in money, so it gets the ⓘ treatment (§7)
                    rather than a paragraph: the gate that skips an uncertified
                    manager is old behaviour, and this decides only what the
                    NEXT manager up is then paid — the skipped person's rate, or
                    the one after it. Both are plans real companies run.
                  -->
                  <div class="mt-3 pt-3 border-t border-amber-100 flex items-start gap-2.5" data-test="override-compression">
                    <input
                      id="override-compression-toggle"
                      type="checkbox"
                      :checked="overrideCompression"
                      :disabled="!canEditCommissionConfig || compressionSaving"
                      class="mt-0.5 h-4 w-4 rounded border-slate-300 disabled:opacity-60"
                      data-test="override-compression-toggle"
                      @change="setOverrideCompression(($event.target as HTMLInputElement).checked)"
                    />
                    <label for="override-compression-toggle" class="text-[12.5px] text-slate-700 leading-snug">
                      <span class="font-bold">เลื่อนชั้นแทนคนที่ถูกข้าม</span>
                      <InfoPopover label="เลื่อนชั้นแทนคนที่ถูกข้าม">
                        <p>
                          หัวหน้าที่ยังไม่ผ่านการอบรม (cert) จะถูก<b>ข้าม</b>และไม่ได้เงิน — อันนี้เป็นกติกาเดิม
                          ไม่เกี่ยวกับสวิตช์นี้
                        </p>
                        <p class="mt-2">
                          สวิตช์นี้ตอบว่า <b>คนถัดไปข้างบนจะได้อัตราของชั้นไหน</b>
                        </p>
                        <p class="mt-2">
                          <b>ปิด (ค่าเริ่มต้น เท่าเดิมทุกอย่าง):</b> คนที่ถูกข้ามยังกินชั้นของตัวเอง —
                          ผู้ขาย → A (ชั้น 1) → B ถูกข้าม (ชั้น 2) → C ได้อัตรา<b>ชั้นที่ 3</b>
                        </p>
                        <p class="mt-2">
                          <b>เปิด:</b> ชั้นที่ถูกข้ามเลื่อนลงมา — C ได้อัตรา<b>ชั้นที่ 2</b> แทน
                        </p>
                        <p class="mt-2 text-slate-500">
                          ตอนที่ทุกชั้นใช้อัตราเดียวกัน ความต่างนี้มองไม่เห็นเลย · พอตั้งอัตราต่อชั้นแล้ว
                          มันคือส่วนต่างจริงที่ลงบัญชีค่าแนะนำแล้วแก้ย้อนหลังไม่ได้
                        </p>
                      </InfoPopover>
                      <span v-if="compressionSaving" class="ml-1 text-[11px] text-slate-400">กำลังบันทึก...</span>
                    </label>
                  </div>

                  <p
                    v-if="levelGaps.length"
                    class="mt-3 px-3 py-2 rounded-lg bg-amber-100/70 text-[12px] font-bold text-amber-800"
                    data-test="level-gap-warning"
                  >
                    ยังไม่ได้ตั้งอัตราของชั้นที่ {{ levelGaps.join(', ') }} — ชั้นนั้นจะไม่ได้เงิน
                    แต่ชั้นที่อยู่สูงกว่ายังได้ตามปกติ · ถ้าตั้งใจให้ชั้นที่เหลือได้เท่ากัน
                    ให้เพิ่มอีกหนึ่งอัตราแบบ<b>เว้นช่องชั้นไว้</b>
                  </p>
                </div>

                <div class="space-y-3">
                  <div
                    v-for="group in leaderRateGroups"
                    :key="`leader-group-${group.scope}`"
                    class="rounded-xl border border-amber-200 bg-white/70 p-3.5"
                    :data-test="`leader-group-${group.scope}`"
                  >
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="text-[13.5px] font-extrabold text-slate-900">
                        <span class="text-slate-400 mr-1.5">{{ group.number }}</span>{{ group.title }}
                      </p>
                      <span
                        class="text-[11px] font-bold rounded-full px-2 py-0.5"
                        :class="group.rows.length ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-400'"
                      >{{ group.rows.length }}</span>
                      <button
                        v-if="canEditCommissionConfig"
                        type="button"
                        class="ml-auto px-3 py-1.5 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 text-xs font-bold hover:bg-amber-100"
                        :data-test="`add-leader-rate-${group.scope}`"
                        @click="openCreateOverrideFormWithScope(group.scope)"
                      >
                        + เพิ่ม
                      </button>
                    </div>
                    <p class="mt-0.5 text-[12px] text-slate-500">{{ group.hint }}</p>

                    <!-- An empty layer says what its emptiness MEANS, not just
                         that it is empty. "ยังไม่มีข้อมูล" on a ladder rung is
                         the least useful sentence available: the reader's real
                         question is what happens to the products this rung
                         would have covered. -->
                    <p v-if="!group.rows.length" class="mt-2 text-[12px] text-slate-400" :data-test="`leader-group-empty-${group.scope}`">
                      {{ group.empty }}
                    </p>
                    <TransitionGroup v-else tag="div" name="list-fade" class="mt-2 space-y-2">
                      <div
                        v-for="r in group.rows"
                        :key="`leader-${r.id}`"
                        class="bg-white rounded-lg px-3.5 py-2.5 flex items-center justify-between gap-3 border"
                        :class="conflictingOverrideIds.has(r.id) ? 'border-rose-300 bg-rose-50/40' : 'border-slate-200'"
                        :data-test="`leader-rule-${r.id}`"
                      >
                        <div class="min-w-0">
                          <p class="text-sm font-bold text-slate-900">
                            <span
                              v-if="ruleDateStatus(r)"
                              class="mr-2 px-2 py-0.5 rounded-md bg-slate-200 text-slate-600 text-[11px] align-middle"
                              :data-test="`leader-rule-date-status-${r.id}`"
                            >{{ ruleDateStatus(r) }}</span>
                            <span v-if="conflictingOverrideIds.has(r.id)" class="mr-2 px-2 py-0.5 rounded-md bg-rose-100 text-rose-700 text-[11px] align-middle">ซ้อนทับ</span>
                            <!-- On EVERY row, including the catch-all: a badge
                                 only on the levelled rows would leave an admin
                                 reading the unlabelled ones as level 1, which
                                 is the opposite of what null means. -->
                            <!--
                              Shown when the plan HAS a ladder (so the
                              catch-all is labelled and cannot be misread as
                              level 1), and on any row that carries a level
                              whatever the plan — a levelled row under Matrix
                              has nowhere else to be shown, and an unlabelled
                              one would be indistinguishable from a rate that
                              pays the whole chain.

                              Absent on a catch-all under a plan with no chain
                              walk, where "ทุกชั้น" would be noise on every row.
                            -->
                            <span
                              v-if="levelLadderApplies || !isCatchAllLevel(r)"
                              class="mr-2 px-2 py-0.5 rounded-md text-[11px] align-middle"
                              :class="isCatchAllLevel(r) ? 'bg-slate-100 text-slate-500' : 'bg-indigo-100 text-indigo-700'"
                              :data-test="`leader-rule-level-${r.id}`"
                            >{{ leaderLevelLabel(r) }}</span>
                            {{ leaderRowLabel(r) }}
                            <span class="ml-1.5 text-amber-800">{{ formatRate(r.rate_type, r.rate_value) }}</span>
                            <!-- Shown only on legacy rows. A row created after
                                 TASK-214 has no tier, and saying "ทุก tier" on it
                                 would imply a dimension that no longer exists. -->
                            <span v-if="r.manager_cert_tier" class="ml-1 text-[11px] font-normal text-slate-400">
                              (เดิมตั้งไว้ที่ tier {{ r.manager_cert_tier.name }} — ไม่ถูกใช้แล้ว)
                            </span>
                          </p>
                          <p class="text-xs text-slate-400">
                            <!-- The mode belongs on the ROW because it is now a
                                 property of the row. An inherited one names what
                                 it is following so the badge stays true when the
                                 company's own setting moves. -->
                            <span
                              class="mr-2 px-1.5 py-0.5 rounded align-middle text-[11px]"
                              :class="r.override_mode === null ? 'bg-slate-100 text-slate-500' : 'bg-indigo-100 text-indigo-700'"
                              :data-test="`leader-rule-mode-${r.id}`"
                            >{{ overrideModeLabelFor(r) }}</span>
                            มีผล {{ formatDate(r.effective_from) }}{{ r.effective_to ? ` ถึง ${formatDate(r.effective_to)}` : '' }}
                          </p>
                        </div>
                        <div v-if="canEditCommissionConfig" class="flex items-center gap-2 shrink-0">
                          <button class="text-sm font-bold text-slate-500 hover:text-slate-700" :data-test="`edit-leader-rule-${r.id}`" @click="openEditOverrideForm(r)">แก้ไข</button>
                          <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteOverrideRule(r)">ลบ</button>
                        </div>
                      </div>
                    </TransitionGroup>
                  </div>
                </div>
              </div>


              <!--
                THE TABLE (owner's ข้อเสนอ 2, 2026-09-14).

                Placed BELOW the edit boxes and not instead of them, which is
                the whole shape of the fix: the boxes list the rows that exist
                — including a category rate that currently covers no product,
                which has no line in this table at all and would go invisible
                again, the exact bug that started this. Boxes say what you set;
                this says what happens.
              -->
              <RateResolutionMatrix
                kind="leader"
                :rows="resolutionRows"
                :loading="resolutionLoading"
                :failed="resolutionFailed"
                :can-edit="canEditCommissionConfig"
                :basis-label="commissionBasis === 'pv' ? 'PV' : 'ยอดขาย'"
                :mode-labels="overrideModeLabels"
                :rateable-product-ids="rateableProductIds"
                test-id="step4-resolution"
                @edit="editFromMatrix('leader', $event)"
                @retry="loadResolution"
              />
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
                <!-- The number lives outside the card because the card is
                     shared with nothing else on this screen and must not learn
                     about this step's numbering to be reusable. -->
                <p class="text-[12px] font-extrabold text-slate-400 mb-1 ml-1">4.6</p>
                <CommissionSplitSettingCard
                  :key="effectiveCompanyId ?? 'own'"
                  :company-id="effectiveCompanyId"
                  :is-super-admin="isSuperAdmin"
                  :read-only="!canEditCommissionConfig"
                />
              </div>

              <!--
                ═══ 4.7 — EDITED HERE NOW, NOT LINKED (2026-09-13) ═══

                Owner: "ยอดขั้นต่ำในการเบิก ปรับมาเป็น UI หน้านี้หน้าเดียวให้จบ
                นำของเก่าออกเลย".

                This used to be a link card, and the comment defending the link
                argued that pulling the field over here "would leave two places
                to change one number". That was the right worry and the wrong
                conclusion: the answer is ONE place, and the place is the setup
                flow, not the middle of an approval queue. /commission-
                withdrawals now shows the floor READ-ONLY with a pointer back
                here, so "why was that request refused" is still answered where
                it gets asked — without a second door onto the same column.

                NOT gated on canEditCommissionConfig, unlike everything above
                it: this writes through SettingsCommissionWithdrawalUpdate,
                which a Company Admin holds. Hiding a control from somebody the
                server would let use it is the house rule applied backwards.
              -->
              <div class="rounded-2xl border border-slate-200 bg-white p-4" data-test="step4-withdrawal-minimum">
                <p class="text-[15px] font-extrabold text-slate-900">
                  <span class="text-slate-400 mr-1.5">4.7</span>ยอดขั้นต่ำในการเบิก
                </p>
                <p class="mt-1 text-[12.5px] text-slate-500">
                  สมาชิกต้องมียอดค่าแนะนำสะสมถึงเท่าไหร่จึงจะกดขอเบิกได้ · <b>เว้นว่าง = ไม่มีขั้นต่ำ</b> (เบิกเท่าไรก็ได้)
                </p>

                <p v-if="minWithdrawalUnknown" class="mt-2 text-[12.5px] font-bold text-rose-600" data-test="withdrawal-min-unknown">
                  อ่านค่าปัจจุบันไม่สำเร็จ — ยังไม่แสดงตัวเลข เพราะช่องว่างในนี้แปลว่า "ไม่มีขั้นต่ำ" ซึ่งอาจไม่ใช่ค่าจริง
                </p>
                <div v-else class="mt-2.5 flex flex-wrap gap-2 max-w-md">
                  <input
                    v-model="minWithdrawalBaht"
                    type="text"
                    inputmode="decimal"
                    placeholder="เช่น 1000.00"
                    :disabled="minWithdrawalLoading || minWithdrawalSaving"
                    class="flex-1 min-w-[10rem] px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                    data-test="withdrawal-min-input"
                  />
                  <button
                    type="button"
                    class="btn-primary"
                    :disabled="minWithdrawalLoading || minWithdrawalSaving"
                    data-test="withdrawal-min-save"
                    @click="saveMinWithdrawal"
                  >
                    {{ minWithdrawalSaving ? 'กำลังบันทึก...' : 'บันทึก' }}
                  </button>
                </div>
                <p v-if="minWithdrawalMessage" class="mt-1.5 text-[12.5px] font-bold text-slate-600" data-test="withdrawal-min-message">
                  {{ minWithdrawalMessage }}
                </p>
                <p class="mt-2 text-[12px] text-slate-400">
                  ดูคำขอที่รออนุมัติได้ที่
                  <RouterLink :to="{ name: 'commission-withdrawals' }" class="font-bold text-brand-600 hover:underline" data-test="link-withdrawal-queue">
                    คำขอเบิกค่าแนะนำ →
                  </RouterLink>
                </p>
              </div>

              <!--
                ═══ 4.8 — ภาษีหัก ณ ที่จ่าย (2026-09-19) ═══

                Same card, same endpoint and therefore the same Ability as 4.7
                above: NOT gated on canEditCommissionConfig, because a Company
                Admin holds SettingsCommissionWithdrawalUpdate and hiding a
                control from somebody the server would let use it is the house
                rule applied backwards.

                THE RATE IS NOT SUGGESTED ANYWHERE ON THIS SCREEN. BR-7, and
                this instance of it is the law rather than a preference: which
                rate applies depends on how the payment is classified and on
                whether the payee is an individual or a juristic person, and a
                wrong number is a filing error with a penalty attached. The
                placeholder is empty and the hint says who decides.

                The card explains what does NOT change, which is the part
                people get wrong: the tax never reduces what an agent earned or
                what the ledger says, only what the bank sends.
              -->
              <div class="rounded-2xl border border-slate-200 bg-white p-4" data-test="step4-withholding-tax">
                <p class="text-[15px] font-extrabold text-slate-900">
                  <span class="text-slate-400 mr-1.5">4.8</span>ภาษีหัก ณ ที่จ่าย
                  <InfoPopover label="ภาษีหัก ณ ที่จ่าย">
                    <p>
                      ตอนโอนเงินให้สมาชิก บริษัทหัก<b>ภาษี ณ ที่จ่าย</b>ไว้ แล้วนำส่งกรมสรรพากรในชื่อของสมาชิก
                    </p>
                    <p class="mt-2">
                      <b>สิ่งที่ไม่เปลี่ยน:</b> ยอดค่าแนะนำที่สมาชิกหาได้ และตัวเลขในบัญชีค่าแนะนำ (ledger)
                      ยังเป็น<b>ยอดเต็ม</b>เท่าเดิม · ภาษีไปลดเฉพาะ<b>ยอดที่โอนออกจากธนาคาร</b>
                    </p>
                    <p class="mt-2">
                      ถ้าไปหักออกจากยอดที่หาได้ ยอดคงเหลือของสมาชิกกับหนังสือรับรองหักภาษีของเขาจะไม่ตรงกันตลอดไป
                    </p>
                    <p class="mt-2">
                      อัตราจะถูก<b>บันทึกติดไปกับคำขอเบิกแต่ละใบ</b>ตอนที่เปิดคำขอ —
                      เปลี่ยนอัตราทีหลังจะไม่ไปเปลี่ยนใบที่อนุมัติไปแล้ว
                    </p>
                  </InfoPopover>
                </p>
                <p class="mt-1 text-[12.5px] text-slate-500">
                  กรอกเป็น <b>%</b> (เช่น 3 = 3%) · <b>เว้นว่าง = ไม่หักภาษี</b> (แบบที่ทุกบริษัทเป็นอยู่ตอนนี้)
                </p>

                <p v-if="whtUnknown" class="mt-2 text-[12.5px] font-bold text-rose-600" data-test="wht-unknown">
                  อ่านค่าปัจจุบันไม่สำเร็จ — ยังไม่แสดงตัวเลข เพราะช่องว่างในนี้แปลว่า "ไม่หักภาษี" ซึ่งอาจไม่ใช่ค่าจริง
                </p>
                <div v-else class="mt-2.5 flex flex-wrap gap-2 max-w-md">
                  <input
                    v-model="whtRatePercent"
                    type="text"
                    inputmode="decimal"
                    placeholder="เช่น 3"
                    :disabled="minWithdrawalLoading || whtSaving"
                    class="flex-1 min-w-[10rem] px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:opacity-60"
                    data-test="wht-input"
                  />
                  <button
                    type="button"
                    class="btn-primary"
                    :disabled="minWithdrawalLoading || whtSaving"
                    data-test="wht-save"
                    @click="saveWithholdingTax"
                  >
                    {{ whtSaving ? 'กำลังบันทึก...' : 'บันทึก' }}
                  </button>
                </div>
                <p v-if="whtMessage" class="mt-1.5 text-[12.5px] font-bold text-slate-600" data-test="wht-message">
                  {{ whtMessage }}
                </p>
                <p class="mt-2 text-[12px] text-slate-400">
                  อัตราที่ถูกต้องขึ้นกับประเภทเงินได้และผู้รับเงิน — <b>ระบบไม่เดาให้</b> กรุณาใช้ตัวเลขจากฝ่ายบัญชีของคุณ
                </p>
              </div>

              <!-- Carried over from the setup hub the overview tab used to
                   host, so the entry point does not disappear with the tab. -->
              <p class="text-xs text-slate-400">
                อยากให้สมาชิกได้แต้ม/เหรียญ/ภารกิจเพิ่มด้วย?
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
          {{ blockingStep ? `ครบทุกขั้นแล้ว แต่ยังติดอยู่ที่ขั้นที่ ${blockingStep}` : 'ครบทุกขั้นแล้ว — พร้อมจ่ายค่าแนะนำ' }}
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

    <!--
      "ยังจ่ายค่าคอมให้ใครไม่ได้" — the ONE modal on this screen that opens by
      itself (2026-09-13). Every condition that keeps it from becoming the nag
      deleted on 2026-09-11 lives in `showStartHereModal`; read that before
      widening any of them.

      DELIBERATELY SHORT. The screen behind it now says what to do first — 3.1
      is framed, 3.2 is locked with one line naming the cure — so a paragraph
      here would be a paragraph covering the answer it repeats. Title, one
      consequence, one button onto the path.
    -->
    <div
      v-if="showStartHereModal"
      class="fixed inset-0 z-[1100] bg-black/60 flex items-center justify-center p-4"
      data-test="start-here-modal"
    >
      <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl p-5">
        <div class="flex items-start gap-3">
          <Icon name="alert" :size="20" class="shrink-0 mt-0.5 text-rose-600" />
          <div class="min-w-0">
            <p class="text-[16px] font-extrabold text-slate-900" data-test="start-here-title">
              บริษัทนี้ยังจ่ายค่าแนะนำให้ใครไม่ได้
            </p>
            <p class="mt-1 text-[13px] text-slate-500">
              ดีลที่ปิดได้ตอนนี้จะไม่มีใครได้เงิน และค่าแนะนำที่ไม่ได้ลงบัญชีไว้ ย้อนกลับไปแก้ทีหลังไม่ได้
            </p>
          </div>
        </div>
        <div class="mt-4 flex items-center justify-end gap-4">
          <button
            type="button"
            class="text-[13px] font-bold text-slate-400 hover:text-slate-600"
            data-test="start-here-dismiss"
            @click="dismissStartHereForToday"
          >
            ปิดไว้ก่อน
          </button>
          <button type="button" class="btn-primary" data-test="start-here-go" @click="goFixCommission">
            ไปตั้งค่าตอนนี้
          </button>
        </div>
      </div>
    </div>

    <!-- อัตราหัวหน้าทีม — opened from step 4 -->
    <!-- TASK-216 r2 — a real modal (human, 2026-08-20: "ทำไมไม่เป็น modal
         backgroud สีดำ"). Inline forms opened at the TOP of the page while
         the row being edited sat further down and often off-screen; the
         overlay removes the question by removing everything else. -->
    <div v-if="showOverrideForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="resetOverrideForm">
      <form data-test="override-form" class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitOverrideRule">
        <div class="shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
          <div class="min-w-0">
            <p class="text-xs font-bold tracking-wide text-amber-700">{{ editingOverrideId ? 'แก้ไข' : 'เพิ่ม' }}อัตราค่าแนะนำหัวหน้าทีม</p>
            <h1 class="mt-0.5 text-xl font-bold text-slate-900 break-words leading-snug">{{ overrideFormTargetLabel }}</h1>
          </div>
          <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600" @click="resetOverrideForm">
            <Icon name="x" :size="20" />
          </button>
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto py-3 -mx-1 px-1 space-y-3">
          <!--
            2026-09-14 — rewritten in the owner's words, not the codebase's.
            The two lines here used to read "อัตราแยกรายสินค้าได้แล้ว · ลำดับการ
            ใช้ค่าเหมือนอัตราตัวแทนเป๊ะ ๆ" — a changelog note and an internal
            cross-reference, neither of which answers the question an admin
            actually has in front of this dropdown: what does each scope DO,
            and what happens to the products it does not cover.
          -->
          <p class="text-xs text-amber-800">
            จ่ายให้ <b>หัวหน้าทีม</b> ทุกครั้งที่ลูกทีมปิดการขาย ·
            แผน <b>มาตรฐาน (Unilevel)</b> จ่ายขึ้นไปทั้งสาย · แผน <b>พันธมิตร (Affiliate)</b> จ่ายชั้นเดียว
          </p>
          <div class="text-xs text-amber-800 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 space-y-1">
            <p class="font-bold">ขอบเขตทั้ง 3 แบบ ต่างกันตรงที่ "ใช้กับสินค้าไหนบ้าง"</p>
            <p><b>ค่าเริ่มต้นทั้งบริษัท</b> — ใช้กับสินค้าทุกตัวที่ไม่ได้ตั้งอัตราเฉพาะไว้</p>
            <p><b>ตามหมวดหมู่สินค้า</b> — ใช้กับทุกสินค้าในหมวดนั้น และทับค่าเริ่มต้นทั้งบริษัท</p>
            <p><b>ตามสินค้า</b> — ใช้กับสินค้าตัวนั้นตัวเดียว และทับทั้งหมวดหมู่และค่าเริ่มต้น</p>
            <p class="text-amber-700">ระบบหยิบมาใช้ <b>อันที่เจาะจงที่สุดเพียงอันเดียว ไม่เอามาบวกกัน</b></p>
          </div>

          <!--
            "ยังไม่ได้ตั้งค่าเริ่มต้นบริษัท แต่ตัวเลือกยังขึ้นมา ควรมีหรือไม่"
            (owner, 2026-09-14).

            The narrow scopes STAY offered: paying the leader on one product
            and nothing else is a real plan, and this whole step is optional —
            so refusing the option would be the screen inventing a rule nobody
            set. What it must not be is SILENT, which is what it was: a
            product-scoped rate saved with no company default leaves every
            other product paying the leader nothing, and the admin finds that
            out at a payout.

            So the consequence is counted and printed at the moment the scope
            is picked, with the way out named. Same shape as the deduction
            guard's message: never a refusal without a number.
          -->
          <div
            v-if="overrideForm.scope !== 'company' && !hasCompanyWideLeaderRate"
            class="text-xs rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-slate-600"
            data-test="override-no-company-default"
          >
            บริษัทนี้ <b>ยังไม่มีค่าเริ่มต้นทั้งบริษัท</b> — ถ้าบันทึกแบบนี้ หัวหน้าทีมจะได้ค่าแนะนำเฉพาะขอบเขตที่เลือกไว้เท่านั้น
            <template v-if="leaderRateGaps.length">
              ส่วนสินค้าอีก <b>{{ leaderRateGaps.length }} รายการ</b> หัวหน้าจะไม่ได้อะไร (สมาชิกที่ปิดการขายยังได้ตามปกติ)
            </template>
            · ถ้าต้องการให้ครอบคลุมทุกสินค้า ให้เลือกขอบเขตเป็น <b>“ค่าเริ่มต้นทั้งบริษัท”</b> ก่อน แล้วค่อยเพิ่มอัตราเฉพาะทีหลัง
          </div>
          <div v-if="overrideFormError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ overrideFormError }}</div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <!-- TASK-214 — the cert-tier picker that used to be here is gone:
                 the rate no longer depends on the manager's tier (human
                 ruling 2026-08-19). This is the scope selector that replaced
                 it, deliberately identical to the agent rate's so both read
                 the same way. -->
            <div class="col-span-2">
              <label class="text-sm font-bold text-slate-500">ขอบเขต</label>
              <!--
                2026-09-14 — a STATEMENT, and NOT a control (owner, twice:
                "ขอบเขตยังต้องเลือกซ้ำอีกเหรอ" and then "ผมกดมาจากสินค้ารายตัว
                ขอบเขตมันต้องแก้ไขเป็นสินค้าเท่านั้น เลือกไม่ได้").

                I shipped a "เปลี่ยน" link here first, on the theory that an
                admin who opened the wrong box should not have to cancel. The
                owner overruled it and was right: cancelling and pressing the
                correct button is two clicks, not a dead end — while a control
                that can move a rate from one product to the WHOLE COMPANY,
                sitting in a modal whose heading names that one product, is a
                wrong payout waiting for a stray click. Two clicks against an
                immutable ledger row (BR-4) is not a trade.
              -->
              <div
                v-if="overrideFormScopeLocked"
                class="mt-1 flex items-center gap-2.5 px-3 py-2 rounded-lg border border-slate-200 bg-slate-50"
                data-test="override-form-scope-fixed"
              >
                <span class="text-sm font-bold text-slate-700">{{ ruleScopeLabels[overrideForm.scope] }}</span>
                <span class="ml-auto text-xs text-slate-400">ตามปุ่มที่กดเข้ามา · เปลี่ยนที่นี่ไม่ได้</span>
              </div>
              <select v-else v-model="overrideForm.scope" data-test="override-form-scope" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="company">ค่าเริ่มต้นทั้งบริษัท</option>
                <option value="category">ตามหมวดหมู่สินค้า</option>
                <option value="product">ตามสินค้า</option>
              </select>
            </div>
            <div v-if="overrideForm.scope === 'product'" class="col-span-2">
              <label class="text-sm font-bold text-slate-500">สินค้า</label>
              <select v-model="overrideForm.product_id" data-test="override-form-product" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
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
            <!--
              2026-09-19 — WHICH LEVEL THIS RATE PRICES.

              Unilevel only: it is the one plan whose payout walks the chain
              level by level. Offering the field on Affiliate (one level by
              definition) or on the plans with their own structure tab would be
              a control that does nothing.

              EMPTY IS THE DEFAULT AND IS A REAL ANSWER — one rate paid all the
              way up, which is what every rate on this system is today. Spelled
              out in the placeholder and the hint rather than left to be
              inferred, because the value an admin reaches for when they mean
              "all of them" is 0, and 0 is the one number the server refuses.
            -->
            <div v-if="levelLadderApplies" class="col-span-2">
              <label class="text-sm font-bold text-slate-500">
                ชั้นที่จ่าย
                <InfoPopover label="ชั้นที่จ่าย">
                  <p>
                    แผน Unilevel จ่ายไล่ขึ้นไปทีละชั้น — หัวหน้าโดยตรงคือ <b>ชั้นที่ 1</b>,
                    หัวหน้าของหัวหน้าคือ <b>ชั้นที่ 2</b> ไปเรื่อยๆ
                  </p>
                  <p class="mt-2">
                    ใส่เลขชั้น = อัตรานี้ใช้กับชั้นนั้นชั้นเดียว (เช่น 10% / 5% / 3%) ·
                    <b>เว้นว่าง = ใช้กับทุกชั้นที่ยังไม่ได้ตั้งอัตราเฉพาะ</b>
                  </p>
                  <p class="mt-2">
                    ตั้งได้ทั้งสองแบบพร้อมกัน: ตั้งชั้นที่ 1 กับ 2 ไว้ชัดๆ แล้วเหลืออีกอันหนึ่งไม่ใส่เลขชั้น
                    เพื่อเป็นค่ากลางของชั้นที่เหลือ
                  </p>
                </InfoPopover>
              </label>
              <input
                v-model="overrideForm.level"
                type="number"
                min="1"
                :max="MAX_PRICEABLE_LEVEL"
                step="1"
                placeholder="เว้นว่าง = ทุกชั้น"
                data-test="override-form-level"
                class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
              />
              <p class="mt-1 text-[11.5px] text-slate-400">
                เว้นว่าง = ใช้กับทุกชั้น (แบบเดิม) · ใส่เลข = ใช้กับชั้นนั้นชั้นเดียว
              </p>
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
              <input v-model="overrideForm.rate_value_input" data-test="override-form-rate-value" type="number" min="0" step="0.01" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <!--
              2026-09-14 — the per-RATE deduction mode (owner: "การตั้งค่าใน
              Step ที่ 4 ต้องต่างกันทั้งหมด").

              The empty option is FIRST and is the default, because "follow the
              company" is the answer for nearly every rate and making somebody
              re-answer a company-level question once per product is how a form
              gets abandoned. It is also a real, persisted state — not an unset
              one — so it keeps following the company when the company changes
              its mind, and the option says so in words.
            -->
            <!--
              2026-09-19 — REPLACED BY A SENTENCE WHEN THE RATE HAS A LEVEL.

              The server refuses override_mode alongside level
              (prohibitedIf), because one walk up one chain has one funding
              model: letting level 2 deduct from the seller while level 1 was
              paid by the company would make the seller's own row depend on how
              deep their upline happened to go.

              Shown as an explanation rather than simply removed — a control
              that vanishes when you type in another field reads as a bug, and
              an admin who was about to change it needs to know why they no
              longer can.
            -->
            <p
              v-if="levelLadderApplies && String(overrideForm.level ?? '').trim() !== ''"
              class="col-span-2 px-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-[12px] text-slate-500"
              data-test="override-form-mode-locked"
            >
              อัตราที่ระบุชั้นจะใช้วิธีจ่ายของบริษัท ({{ overrideModeLabels[overrideMode] }}) เสมอ —
              ทั้งสายต้องมาจากกระเป๋าเดียวกัน ไม่งั้นส่วนของผู้ขายจะขึ้นกับว่าสายบนลึกแค่ไหน
            </p>
            <div v-else class="col-span-2">
              <label class="text-sm font-bold text-slate-500">เงินของหัวหน้าทีมมาจากไหน (เฉพาะอัตรานี้)</label>
              <select v-model="overrideForm.override_mode" data-test="override-form-mode" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="">ใช้ตามค่าเริ่มต้นของบริษัท — {{ overrideModeLabels[overrideMode] }}</option>
                <option v-for="opt in overrideModeOptions" :key="`form-mode-${opt.value}`" :value="opt.value">
                  {{ opt.title }}
                </option>
              </select>
              <p class="mt-1 text-[11.5px] text-slate-400">
                เลือกอย่างอื่นเพื่อให้อัตรานี้ต่างจากทั้งบริษัท · ดูตัวเลขของแต่ละแบบได้ที่ข้อ 4.1
              </p>
            </div>
            <RateImpactPreview
              kind="leader"
              :company-id="effectiveCompanyId"
              :rate-type="overrideForm.rate_type"
              :rate-value="overrideFormPreviewValue"
              :product-id="overrideForm.scope === 'product' ? Number(overrideForm.product_id) || null : null"
              :product-category-id="overrideForm.scope === 'category' ? Number(overrideForm.product_category_id) || null : null"
              :override-mode="overrideForm.override_mode || null"
              :exclude-rule-id="editingOverrideId"
              :ready="overrideFormPreviewReady"
            />
            <EffectivePeriodField
              :from="overrideForm.effective_from"
              :to="overrideForm.effective_to"
              @update:to="overrideForm.effective_to = $event"
              :fallback-from="overrideFormFallbackFrom"
              :test-id="'override-period'"
              @update:from="overrideForm.effective_from = $event"
            />
          </div>
        </div>
        <div class="shrink-0 pt-3 mt-1 border-t border-slate-100 flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="resetOverrideForm">ยกเลิก</button>
          <button type="submit" :disabled="savingOverride" data-test="override-form-submit" class="btn-primary">{{ savingOverride ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
        </div>
      </form>
    </div>

    <!-- อัตราตัวแทนผู้ขาย — opened from step 3 -->
    <div v-if="showRuleForm" class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4" @click.self="resetRuleForm">
      <form class="w-[70vw] min-w-[320px] max-w-[70vw] h-[60vh] p-5 rounded-2xl bg-white shadow-2xl flex flex-col" @submit.prevent="submitRule">
        <div class="shrink-0 flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
          <div class="min-w-0">
            <p class="text-xs font-bold tracking-wide text-brand-700">{{ editingRuleId ? 'แก้ไข' : 'เพิ่ม' }}อัตราค่าแนะนำสมาชิกผู้ขาย</p>
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
              <!--
                Same statement-not-control treatment as the leader form. The
                note there covers why. On an EDIT the wording differs because
                the reason differs: an agent rate's scope is immutable on
                update server-side (CommissionRuleService), so it could not be
                changed here even if the screen offered to.
              -->
              <div
                v-if="ruleFormScopeLocked"
                class="mt-1 flex items-center gap-2.5 px-3 py-2 rounded-lg border border-slate-200 bg-slate-50"
                data-test="rule-form-scope-fixed"
              >
                <span class="text-sm font-bold text-slate-700">{{ ruleScopeLabels[ruleForm.scope] }}</span>
                <span class="ml-auto text-xs text-slate-400">
                  {{ editingRuleId ? 'เปลี่ยนขอบเขตของอัตราที่มีอยู่แล้วไม่ได้' : 'ตามปุ่มที่กดเข้ามา · เปลี่ยนที่นี่ไม่ได้' }}
                </span>
              </div>
              <select v-else v-model="ruleForm.scope" data-test="rule-form-scope" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
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
              <p v-if="ruleCapGuard.isOverCap.value" class="mt-1 text-xs font-bold text-rose-600">เกินเพดานค่าแนะนำที่กำหนด</p>
            </div>
            <RateImpactPreview
              kind="agent"
              :company-id="effectiveCompanyId"
              :rate-type="effectiveRuleFormRateType"
              :rate-value="ruleFormPreviewValue"
              :product-id="ruleForm.scope === 'product' ? Number(ruleForm.product_id) || null : null"
              :product-category-id="ruleForm.scope === 'category' ? Number(ruleForm.product_category_id) || null : null"
              :exclude-rule-id="editingRuleId"
              :ready="ruleFormPreviewReady"
            />
            <EffectivePeriodField
              :from="ruleForm.effective_from"
              :to="ruleForm.effective_to"
              @update:to="ruleForm.effective_to = $event"
              :fallback-from="ruleFormFallbackFrom"
              :test-id="'rule-period'"
              @update:from="ruleForm.effective_from = $event"
            />
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
          <p class="text-sm font-bold text-slate-900">เกินเพดานค่าแนะนำที่กำหนด</p>
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
            <p class="text-xs font-bold tracking-wide text-brand-700">คัดลอกอัตราค่าแนะนำ</p>
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
            และเมื่อลงบัญชีค่าแนะนำไปแล้วแก้ย้อนหลังไม่ได้ · การคัดลอกไม่ใช่การเดา เพราะมี "บริษัทต้นทาง" ที่คนเลือกเอง
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
              สมาชิกผู้ขาย {{ copyRatesPreview.agent_rates.copied.length }} · หัวหน้าทีม {{ copyRatesPreview.leader_rates.copied.length }}
            </p>
            <!-- Every copy starts today and has no end date, and that is not
                 guessable from the list — an admin reading "3.00%" here would
                 otherwise assume the source row's dates came with it. -->
            <p class="text-xs text-slate-400">ทุกรายการจะเริ่มมีผลวันนี้ และไม่มีวันสิ้นสุด · อัตราที่หมดอายุแล้วในบริษัทต้นทางจะไม่ถูกคัดลอก</p>

            <div v-if="copyRatesPreview.agent_rates.copied.length">
              <p class="text-xs font-bold text-slate-500 mb-1">อัตราสมาชิกผู้ขาย</p>
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
            <p class="text-xs font-bold tracking-wide text-slate-400">อัตราค่าแนะนำรายชั้น (Matrix)</p>
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
          <EffectivePeriodField
            :from="levelRateForm.effective_from"
            :fallback-from="levelRateFormFallbackFrom"
            :supports-end-date="false"
            :test-id="'level-rate-period'"
            @update:from="levelRateForm.effective_from = $event"
          />
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
            <p class="text-xs font-bold tracking-wide text-slate-400">อัตราค่าแนะนำราย Generation</p>
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
          <EffectivePeriodField
            :from="generationRuleForm.effective_from"
            :fallback-from="generationRuleFormFallbackFrom"
            :supports-end-date="false"
            :test-id="'generation-rule-period'"
            @update:from="generationRuleForm.effective_from = $event"
          />
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
          <p class="text-sm font-bold text-slate-900">ลำดับการใช้ค่าแนะนำ</p>
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
              <p class="text-sm font-bold text-slate-900">ค่าแนะนำทางตรง: {{ formatSatang(simulateResult.amountSatang) }}</p>
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
            <p v-else class="text-xs text-rose-600">ยังไม่มีกฎค่าแนะนำที่ใช้ได้กับสินค้านี้</p>
            <p class="text-xs text-slate-400 mt-2">
              * ตัวอย่างนี้แสดงเฉพาะค่าแนะนำทางตรงจากยอดขาย ไม่รวมโครงสร้าง Override/Matrix/Generation/อันดับ ซึ่งคำนวณจริงที่ฝั่งเซิร์ฟเวอร์เมื่อมีการขายจริงเท่านั้น
            </p>
          </div>
        </div>
      </div>
    </div>
  </main>
</template>
