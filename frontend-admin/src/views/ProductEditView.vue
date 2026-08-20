<script setup lang="ts">
/**
 * ProductEditView — consolidated full-page product create/edit screen
 * (ADR-008). Replaces ProductCatalogView.vue's old inline expandable-row
 * UX for a single product: this page now owns the product's basics,
 * description, Commission panel (scoped to this product), media
 * gallery, key-value specs, spec-description narrative, spec-attachment
 * gallery (image+PDF, ADR-008 Decision 2), and Sales Materials +
 * share-links — all "ย้ายทั้งหมด" per ADR-008's human-confirmed answer.
 *
 * Money is stored/transmitted as integer satang everywhere (BR-3) —
 * divided by 100 only here, at the display layer, and multiplied back
 * by 100 before sending. Commission rate_value is basis points when
 * rate_type is "percentage" (500 = 5.00%) — same divide-at-display-only
 * rule applies.
 *
 * Handles BOTH modes via the route: no :id param (route name
 * 'product-create') → create mode; :id present (route name
 * 'product-edit') → edit mode, fetches the product + every nested
 * collection in parallel on mount. The nested sections (media/specs/
 * spec-attachments/commission/materials) only make sense once a product
 * id exists, so they're hidden entirely in create mode.
 */
import { computed, ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
// TASK-208 / ADR-038 — the app-wide company scope replaces this screen's
// create-mode company <select>.
import { useActiveCompanyStore } from '@/stores/activeCompany'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import BuddhistDateInput from '@/design-system/components/BuddhistDateInput.vue'
import CalendarDatePicker from '@/design-system/components/CalendarDatePicker.vue'
import AuthenticatedMedia from '@/design-system/components/AuthenticatedMedia.vue'
import MediaUploadModal from '@/design-system/components/MediaUploadModal.vue'
import MediaPreviewModal, { type PreviewItem } from '@/design-system/components/MediaPreviewModal.vue'
import PdfThumbnail from '@/design-system/components/PdfThumbnail.vue'
import GroupCombobox from '@/design-system/components/GroupCombobox.vue'
import InfoPopover from '@/design-system/components/InfoPopover.vue'
// TASK-196 §3 — live commission-rate-cap guard, shared math with
// CommissionPlansView.vue and ProductCatalogView.vue (see the composable's
// own docblock for why the cap fetch is module-scope/shared but each form
// here gets its own guard instance).
import { useCommissionRateCapGuard } from '@/composables/useCommissionRateCap'
// ADR-026 — Thai stage wording lives in the UI layer, never in the enum
// (PipelineStage::label() is English by §7). One map per app.
import { PAYMENT_STAGE_KEY, stageLabelTh, type PipelineStageRef } from '@/utils/pipelineStages'
// Grid cell for an embed-type item shows a plain link icon by default;
// YouTube links get the recognizable red-badge logo instead so agents can
// tell at a glance which embeds are YouTube.
import { isYoutubeUrl } from '@/utils/embedUrl'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

// Super Admin has no company_id of their own — StoreProductRequest only
// accepts (and requires) company_id when the actor is Super Admin
// (Company Admin's own company_id is inferred server-side). Mirrors the
// exact isSuperAdmin/companyOptions/selectedCompanyId pattern already
// used for video-processing settings in ProductCatalogView.vue.
const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin')
// TASK-208 — creating a product no longer asks WHICH company inside the
// form: it lands in whatever company the header switcher is scoped to, which
// is also the company whose brands/categories the pickers below list. Those
// two answers coming from one place is the point — before this, picking
// company A in the form while the brand list showed company B's was two
// clicks away.
const activeCompany = useActiveCompanyStore()
const selectedCompanyId = computed(() => activeCompany.companyId)

/**
 * Name of the company this product belongs to — products.company_id for an
 * existing row (which can differ from the header scope if someone deep-links
 * into another company's product), the header scope in create mode.
 */
const productCompanyName = computed<string | null>(() => {
  const id = product.value?.company_id ?? activeCompany.companyId
  if (id === null || id === undefined) return null

  return activeCompany.companies.find((c) => c.id === id)?.name ?? activeCompany.companyName
})

// route name is set explicitly in router/index.ts ('product-create' has
// no :id param at all, 'product-edit' always does) — simpler/less
// fragile than inspecting route.params.id for the literal string 'new'.
const isCreateMode = computed(() => !route.params.id)
const productId = computed(() => (route.params.id ? Number(route.params.id) : null))

interface Brand {
  id: number
  name: string
  is_active: boolean
}
interface ProductCategory {
  id: number
  name: string
  sort_order: number
  is_active: boolean
}
// ADR-011/TASK-027: null = inherit the company's default plan type;
// effective_plan_type is always resolved server-side (never null) —
// see Product::effectivePlanType() on the backend.
type CommissionPlanType = 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'
// TASK-194 §3.1 — only meaningful when effective_plan_type is
// 'affiliate'; null on the product record means 'additive' (see
// Product::effectiveAffiliateOverrideMode() on the backend, and
// AffiliateOverrideMode enum for the two payout maths).
type AffiliateOverrideMode = 'additive' | 'deductive'
// TASK-197 §2.1 — the FORMAT (% vs fixed THB) for every commission_rules
// row this product ever gets, hoisted from a per-rule choice to a single
// per-product setting. Null = "not yet configured" (the first rule this
// product ever gets decides it — backend side effect, §2.2); never a
// second enum, same App\Enums\CommissionRateType the per-rule rate_type
// below already uses.
type CommissionRateType = 'percentage' | 'fixed_satang'
// ADR-026 §3.3 — GET /pipeline-templates (index only; authoring is
// TASK-134b and has no route yet). `stages` is ORDERED — the order IS
// the customer journey, which is why the selector below renders it.
interface PipelineTemplate {
  id: number
  company_id: number
  key: string
  name: string
  is_system: boolean
  stages: (PipelineStageRef & { position: number })[]
}
interface Product {
  id: number
  company_id: number
  brand: Brand | null
  category: ProductCategory | null
  name: string
  price_satang: number
  is_active: boolean
  description: string | null
  spec_description: string | null
  commission_plan_type: CommissionPlanType | null
  effective_plan_type: CommissionPlanType
  // TASK-194 §3.1/§3.4 — same own-override/always-resolved pairing as
  // commission_plan_type/effective_plan_type directly above. Only
  // meaningful when effective_plan_type is 'affiliate'; harmless
  // otherwise. null on affiliate_override_mode means 'additive'.
  affiliate_override_mode: AffiliateOverrideMode | null
  effective_affiliate_override_mode: AffiliateOverrideMode
  // TASK-197 §2.1 — this product's own commission rate FORMAT. Unlike
  // commission_plan_type/affiliate_override_mode above there is no
  // "effective_" resolved sibling: it's not an inherit chain, it's a
  // per-product lock that starts null and gets set once (either here by
  // the admin, or as a side effect of the first commission_rules row —
  // §2.2). Read raw, no fallback needed beyond the `?? 'percentage'`
  // the rule forms below apply themselves.
  commission_rate_type: CommissionRateType | null
  // ADR-026 §3.3 — this product's OWN override; null = inherit from the
  // category, then the company. Exactly the commission_plan_type /
  // effective_plan_type pairing one line up, and for the same reason:
  // the inherit chain is resolved server-side, never re-derived here.
  pipeline_template_id: number | null
  // The RESOLVED journey. `null` does NOT mean "no journey" — it means
  // resolution failed closed (a company with no templates at all), i.e.
  // MISCONFIGURED. ProductResource's own comment is explicit that ag-ui
  // must not render it as "none".
  effective_pipeline_template: PipelineTemplate | null
  // ADR-033 (TASK-189) §2.3/§2.5 — BR-7 admin-editable. Null quota/
  // validity mean unlimited/never-expires (never "0" or "required").
  voucher_usage_quota: number | null
  voucher_validity_days: number | null
  requires_shipping: boolean
  // ADR-036 (TASK-211/212/213) — non-null once this product is linked to
  // a shared cross-company product_catalog_items row. When set, `name` /
  // `description` / `spec_description` above are already the RESOLVED
  // values from that catalog item (nothing to re-resolve client-side),
  // and `brand`/`category` are the catalog's own CatalogBrand/
  // CatalogCategory (company_id: null) — never this product's own
  // brand_id/category_id, which is why those two fields must never be
  // rendered as editable selects bound to THIS company's /brands and
  // /product-categories lists while linked (see isCatalogLinked below).
  catalog_item_id: number | null
  created_at: string
  updated_at: string
}
// ADR-036 — the shared catalog item a product can optionally link to.
// Only the fields this page actually needs (brand/category picker +
// name, for the link-picker modal's list) — the full shape (media/specs/
// linked_product_count) lives on CatalogManagementView.vue instead.
interface CatalogBrandRef {
  id: number
  name: string
}
interface CatalogCategoryRef {
  id: number
  name: string
}
interface ProductCatalogItemOption {
  id: number
  name: string
  catalog_brand: CatalogBrandRef
  catalog_category: CatalogCategoryRef
  is_active: boolean
}
interface CertTierRef {
  id: number
  key: string
  name: string
}
interface CommissionRule {
  id: number
  cert_tier: CertTierRef | null
  product: Product | null
  // Historical rows are never rewritten (TASK-197 §1) — this stays each
  // row's own actual rate_type even after a product's commission_rate_type
  // setting is hoisted/changed. Only the add/edit FORMS lose their own
  // selector; the list display below still reads this per-row.
  rate_type: CommissionRateType
  rate_value: number
  effective_from: string
  effective_to: string | null
  renewal_rate_type: CommissionRateType | null
  renewal_rate_value: number | null
  renewal_recurs: boolean
}
interface ProductMediaItem {
  id: number
  media_type: 'image' | 'video'
  source_type: 'upload' | 'embed' | null
  // TASK-097 — 'cover' = รูปสินค้า (Shopee-style photo set, images only),
  // 'detail' = รายละเอียดสินค้า (long-form gallery, video + embeds too).
  purpose: 'cover' | 'detail'
  stream_url: string | null
  thumbnail_url: string | null
  embed_url: string | null
  is_primary: boolean
  sort_order: number
  processing_status: 'pending' | 'processing' | 'ready' | 'failed' | null
}
interface ProductSpecItem {
  id: number
  spec_group: string | null
  spec_key: string
  spec_value: string
  sort_order: number
}
// ADR-008 — image+PDF spec-attachment gallery. No is_primary concept
// here (unlike ProductMediaItem) — just sort_order.
interface ProductSpecAttachmentItem {
  id: number
  media_type: 'image' | 'pdf'
  source_type: 'upload' | 'embed'
  stream_url: string | null
  thumbnail_url: string | null
  embed_url: string | null
  page_count: number | null
  processing_status: 'pending' | 'processing' | 'ready' | 'failed' | null
  sort_order: number
  created_at: string
}
interface SalesMaterialItem {
  id: number
  material_group: string | null
  original_filename: string | null
  size_bytes: number | null
  mime_type: string | null
  source_type: 'upload' | 'embed' | null
  stream_url: string | null
  embed_url: string | null
  processing_status: 'pending' | 'processing' | 'ready' | 'failed' | null
}
interface ShareLinkItem {
  id: number
  share_url: string
  expires_at: string
  revoked_at: string | null
  view_count: number
}
// TASK-068 / ADR-020 row 4 — the manual half of the "Recommended for
// you" row's hybrid fill (admin-pinned products first, ABC/Pareto
// auto-fill tops up any remaining slots — see ProductRecommendationService,
// not duplicated here).
interface RecommendationPinItem {
  id: number
  product_id: number
  sort_order: number
  is_active: boolean
}

// ── Page-level loading/error state ──
const loading = ref(true)
const errorMessage = ref('')
const savingBasics = ref(false)

const product = ref<Product | null>(null)
const brands = ref<Brand[]>([])
const categories = ref<ProductCategory[]>([])

// ── ADR-036 (TASK-215) — shared cross-company catalog link ──
// A linked product's name/brand/category/description/spec_description are
// resolved from the catalog item server-side (already correct in `product`
// — nothing to re-fetch/re-resolve here). Two independent locks apply:
//  1. isCatalogLinked — those 5 identity fields render read-only for
//     EVERY user, Super Admin included (there is nothing to "edit" here;
//     the only way to change them is from the catalog item itself).
//  2. readOnlyForCompanyAdmin — EVERY other tab/section (price/commission/
//     voucher/media/specs/materials/is_active/pin) is fully read-only for
//     a Company Admin on a linked product, mirroring the backend's
//     ProductPolicy::update() (403 for Company Admin on any linked
//     product) — Super Admin keeps full edit rights there.
const isCatalogLinked = computed(() => product.value?.catalog_item_id != null)
const readOnlyForCompanyAdmin = computed(() => isCatalogLinked.value && !isSuperAdmin.value)

const showCatalogLinkPicker = ref(false)
const catalogItemOptions = ref<ProductCatalogItemOption[]>([])
const loadingCatalogItems = ref(false)
const catalogLinkError = ref('')
const catalogLinkSearch = ref('')
const linkingCatalogItemId = ref<number | null>(null)

const filteredCatalogItemOptions = computed(() => {
  const q = catalogLinkSearch.value.trim().toLowerCase()
  if (!q) return catalogItemOptions.value
  return catalogItemOptions.value.filter(
    (i) => i.name.toLowerCase().includes(q) || i.catalog_brand.name.toLowerCase().includes(q) || i.catalog_category.name.toLowerCase().includes(q),
  )
})

async function openCatalogLinkPicker() {
  catalogLinkError.value = ''
  catalogLinkSearch.value = ''
  showCatalogLinkPicker.value = true
  if (catalogItemOptions.value.length) return
  loadingCatalogItems.value = true
  try {
    const res = await api.get<{ data: ProductCatalogItemOption[] }>('/product-catalog-items')
    catalogItemOptions.value = res.data
  } catch (e) {
    catalogLinkError.value = apiErrorMessage(e, 'โหลดรายการแคตตาล็อกกลางไม่สำเร็จ')
  } finally {
    loadingCatalogItems.value = false
  }
}
function closeCatalogLinkPicker() {
  showCatalogLinkPicker.value = false
}
async function linkToCatalogItem(item: ProductCatalogItemOption) {
  if (!product.value) return
  linkingCatalogItemId.value = item.id
  catalogLinkError.value = ''
  try {
    const res = await api.post<{ data: Product }>(`/products/${product.value.id}/catalog-link`, { catalog_item_id: item.id })
    product.value = res.data
    syncBasicsFormFromProduct(res.data)
    showCatalogLinkPicker.value = false
  } catch (e) {
    catalogLinkError.value = apiErrorMessage(e, 'เชื่อมกับแคตตาล็อกกลางไม่สำเร็จ')
  } finally {
    linkingCatalogItemId.value = null
  }
}

// Unlink — the DELETE payload replaces the catalog-resolved identity with
// a fresh standalone one, using THIS company's own /brands and
// /product-categories lists (never catalog_brand_id/catalog_category_id —
// those belong to a different, global table).
const showCatalogUnlinkForm = ref(false)
const unlinkForm = ref({ name: '', brand_id: '' as number | '', category_id: '' as number | '', description: '', spec_description: '' })
const unlinkError = ref('')
const unlinkingCatalog = ref(false)
function openCatalogUnlinkForm() {
  unlinkForm.value = { name: '', brand_id: '', category_id: '', description: '', spec_description: '' }
  unlinkError.value = ''
  showCatalogUnlinkForm.value = true
}
function closeCatalogUnlinkForm() {
  showCatalogUnlinkForm.value = false
}
async function confirmUnlinkCatalog() {
  if (!product.value) return
  if (!unlinkForm.value.name || !unlinkForm.value.brand_id || !unlinkForm.value.category_id) {
    unlinkError.value = 'กรุณากรอกชื่อ แบรนด์ และหมวดหมู่สำหรับสินค้านี้หลังยกเลิกการเชื่อม'
    return
  }
  unlinkingCatalog.value = true
  unlinkError.value = ''
  try {
    const res = await api.delete<{ data: Product }>(`/products/${product.value.id}/catalog-link`, {
      name: unlinkForm.value.name,
      brand_id: Number(unlinkForm.value.brand_id),
      category_id: Number(unlinkForm.value.category_id),
      description: unlinkForm.value.description || undefined,
      spec_description: unlinkForm.value.spec_description || undefined,
    })
    product.value = res.data
    syncBasicsFormFromProduct(res.data)
    showCatalogUnlinkForm.value = false
  } catch (e) {
    unlinkError.value = apiErrorMessage(e, 'ยกเลิกการเชื่อมไม่สำเร็จ')
  } finally {
    unlinkingCatalog.value = false
  }
}

// Bug fix 2026-07-20: ApiError.message now carries Laravel's real
// validation reason (see client.ts) instead of always being the generic
// `API error ${status}` — show that real reason when we have one, only
// falling back to the generic "<fallback> (<status>)" when the body
// genuinely had nothing more specific to say.
function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}

// ── Section A — basics (name/price/brand/category/is_active) ──
interface BasicsForm {
  name: string
  price_thb: string | number
  is_active: boolean
  brand_id: string | number
  category_id: string | number
  // '' is the "inherit from company" sentinel (same convention as
  // brand_id/category_id's own '' = unselected) — never sent to the API
  // as a literal empty string, saveBasics() maps it to `null`.
  commission_plan_type: CommissionPlanType | ''
  // TASK-194 §3.1/§3.4 — '' is the "use the default (Additive)" sentinel
  // (unlike commission_plan_type/pipeline_template_id above, there is no
  // company/category inherit chain here — null on the backend always
  // resolves to Additive, full stop). saveBasics() maps '' to explicit
  // null; only sent/shown when effective plan type is Affiliate.
  affiliate_override_mode: AffiliateOverrideMode | ''
  // TASK-197 §2.1/§3.2 — '' is the "not yet configured" sentinel (same
  // shape as affiliate_override_mode directly above) — saveBasics() maps
  // it to explicit null. Unlike affiliate_override_mode there is no
  // meaningful default to fall back to once a product HAS rules: this is
  // the value every "+ เพิ่มอัตราคอมตาม tier" submission on this product
  // must match (server-enforced, TASK-197 §2.2).
  commission_rate_type: CommissionRateType | ''
  // ADR-026 §3.3 — same '' = inherit sentinel as commission_plan_type
  // above; saveBasics() maps it to an explicit null.
  pipeline_template_id: number | ''
  // ADR-033 (TASK-189) §2.3/§2.5 — '' is the "unlimited"/"never expires"
  // sentinel for the two nullable number fields (same '' convention as
  // brand_id/category_id above), never sent to the API as a literal
  // empty string — saveBasics() maps '' to null.
  voucher_usage_quota: string | number
  voucher_validity_days: string | number
  requires_shipping: boolean
}
const basicsForm = ref<BasicsForm>({
  name: '',
  price_thb: '',
  is_active: true,
  brand_id: '',
  category_id: '',
  commission_plan_type: '',
  affiliate_override_mode: '',
  commission_rate_type: '',
  pipeline_template_id: '',
  // Human, 2026-08-16: new products default to a 1-time voucher rather
  // than opening on the "ไม่จำกัด" (unlimited) blank state — admin can
  // still clear it back to unlimited. Only affects CREATE mode; editing
  // an existing product still loads its real stored value (or '' if it
  // was actually saved as unlimited) via syncBasicsFormFromProduct below.
  voucher_usage_quota: 1,
  voucher_validity_days: '',
  requires_shipping: false,
})
// TASK-189 follow-up (human, 2026-08-16, second revision): calendar-first
// entry. Value is STILL stored/sent as an integer day-count counted from
// the CUSTOMER'S PAYMENT DATE (unchanged, per ADR-033 §2.4 — one product
// template applies to many future orders paid on different days, so an
// absolute calendar date can never be the stored value itself). The
// calendar date shown here is only "if a customer paid TODAY, the voucher
// would expire on ___" — a way to arrive at the day-count, not a second
// meaning for the field. The preview text below the picker says this
// explicitly so the distinction isn't lost on whoever's configuring it.
//
// Quick-select presets ("สิ้นเดือนนี้ / 3 เดือน / 6 เดือน / สิ้นปีนี้") are
// computed from TODAY (the day of configuring), per the human's answer —
// they just jump the calendar to that date; the underlying day-count math
// is identical to picking the date by hand.
function toIsoDate(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}
function daysFromToday(target: Date): number {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const t = new Date(target)
  t.setHours(0, 0, 0, 0)
  return Math.round((t.getTime() - today.getTime()) / 86_400_000)
}
const voucherValidityDate = computed<string>({
  get() {
    if (basicsForm.value.voucher_validity_days === '') return ''
    const days = Number(basicsForm.value.voucher_validity_days)
    if (!Number.isFinite(days)) return ''
    const target = new Date()
    target.setHours(0, 0, 0, 0)
    target.setDate(target.getDate() + days)
    return toIsoDate(target)
  },
  set(val: string) {
    if (!val) {
      basicsForm.value.voucher_validity_days = ''
      return
    }
    const days = daysFromToday(new Date(`${val}T00:00:00`))
    if (days < 1) return // a picked date today/in the past is not a usable validity window
    basicsForm.value.voucher_validity_days = String(days)
  },
})
type VoucherValidityPreset = 'end_of_month' | 'end_of_year' | '3_months' | '6_months'
function applyVoucherValidityPreset(preset: VoucherValidityPreset) {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const target =
    preset === 'end_of_month'
      ? new Date(today.getFullYear(), today.getMonth() + 1, 0)
      : preset === 'end_of_year'
        ? new Date(today.getFullYear(), 11, 31)
        : preset === '3_months'
          ? new Date(today.getFullYear(), today.getMonth() + 3, today.getDate())
          : new Date(today.getFullYear(), today.getMonth() + 6, today.getDate())
  const days = daysFromToday(target)
  if (days < 1) return
  basicsForm.value.voucher_validity_days = String(days)
}
function clearVoucherValidity() {
  basicsForm.value.voucher_validity_days = ''
}

const planTypeLabels: Record<CommissionPlanType, string> = {
  unilevel: 'มาตรฐาน (Unilevel)',
  binary: 'ไบนารี (Binary)',
  matrix: 'เมทริกซ์ (Matrix)',
  stairstep_breakaway: 'Stairstep/Breakaway',
  generation: 'Generation',
  affiliate: 'พันธมิตร (Affiliate)',
}

// TASK-194 §3.4 — the selector below is only rendered when this
// product's plan type resolves to Affiliate. No other plan-type-
// conditional section exists yet on this page (grepped for
// effective_plan_type / commission_plan_type usage — the readout at
// §"ค่าที่ใช้จริงตอนนี้" above is the only other consumer), so per the
// task spec's fallback instruction this reads the SAME two sources the
// plan-type select and its readout already use: the live in-progress
// override pick (so choosing "Affiliate" reveals the field immediately,
// before saving) falling back to the server-resolved effective_plan_type
// once a product exists (so an Affiliate value INHERITED from the
// company/category — no explicit override on this product — still
// shows the field).
const isEffectivelyAffiliate = computed(
  () => (basicsForm.value.commission_plan_type || product.value?.effective_plan_type) === 'affiliate',
)

const affiliateOverrideModeLabels: Record<AffiliateOverrideMode, string> = {
  additive: 'จ่ายเพิ่มแยกต่างหาก (Additive)',
  deductive: 'หักจากค่าคอมตัวแทน (Deductive)',
}

// TASK-197 §3.1/§3.3 — same labels the per-rule rate_type selector used
// before this task (kept verbatim, not reworded — reused across the
// product-level settings block AND the read-only "จะบันทึกเป็น: ..."
// note on the add/edit rule forms below).
const commissionRateTypeLabels: Record<CommissionRateType, string> = {
  percentage: '% ของยอดขาย',
  fixed_satang: 'จำนวนคงที่ (บาท)',
}

// ── ADR-026 — pipeline template (customer journey) ──
const pipelineTemplates = ref<PipelineTemplate[]>([])

/**
 * Thai names for the two SEEDED system templates. Everything else shows
 * its own `name` — an admin-authored template is admin-named, and
 * translating a name the admin typed would be wrong.
 *
 * Keyed on `key`, not on id: ids differ per company (every company gets
 * its own seeded pair), the key does not.
 */
const SYSTEM_TEMPLATE_LABELS_TH: Record<string, string> = {
  medical_package_default: 'แพ็กเกจการแพทย์ (มีขั้นพบแพทย์)',
  direct_sale_default: 'ขายตรง (ลงทะเบียน → ชำระเงิน)',
}
function templateLabel(template: PipelineTemplate): string {
  return SYSTEM_TEMPLATE_LABELS_TH[template.key] ?? template.name
}

/**
 * BR-6 — only ever offer templates belonging to THIS product's company.
 * Both Product Requests validate `pipeline_template_id` with
 * `exists(...)->where('company_id', ...)`, so offering another company's
 * template would be offering a guaranteed 422.
 *
 * A Super Admin's `GET /pipeline-templates` is unscoped (TenantScope
 * exempts them, §5 rule 4) and therefore spans every company — hence the
 * explicit filter here rather than trusting the list. A Company Admin's
 * list is already narrowed to their own company, and `companyScope` is
 * null for them in create mode, so the filter no-ops.
 */
const templateCompanyScope = computed<number | null>(
  () => product.value?.company_id ?? (isCreateMode.value && isSuperAdmin.value ? selectedCompanyId.value : null),
)
const templateOptions = computed(() =>
  templateCompanyScope.value === null
    ? pipelineTemplates.value
    : pipelineTemplates.value.filter((t) => t.company_id === templateCompanyScope.value),
)

/**
 * The journey the admin is looking at RIGHT NOW: the one they have just
 * picked in the select if they picked one, otherwise the server-resolved
 * inherited journey. Rendered as stage chips under the select so
 * choosing a journey is never choosing a name blind (the whole reason
 * PipelineTemplateResource sends `stages` at all).
 */
const previewTemplate = computed<PipelineTemplate | null>(() => {
  if (basicsForm.value.pipeline_template_id !== '') {
    return templateOptions.value.find((t) => t.id === basicsForm.value.pipeline_template_id) ?? null
  }
  return product.value?.effective_pipeline_template ?? null
})

async function loadPipelineTemplates() {
  try {
    const res = await api.get<{ data: PipelineTemplate[] }>('/pipeline-templates')
    pipelineTemplates.value = res.data
  } catch (e) {
    // Non-fatal: the rest of the product form must still work. The
    // selector renders its own "โหลดรายการเส้นทางไม่สำเร็จ" note when the
    // list is empty rather than silently offering only "inherit".
    if (import.meta.env.DEV) console.warn('[pipeline-templates]', e)
  }
}

// Display-only: shows "20,000" while typing, but basicsForm.price_thb
// underneath always stays a plain unformatted number — saveBasics()'s
// BR-3 satang conversion (Math.round(Number(price_thb) * 100)) never
// sees a comma.
const priceDisplay = computed<string>({
  get() {
    const n = Number(basicsForm.value.price_thb)
    return basicsForm.value.price_thb === '' || Number.isNaN(n) ? '' : n.toLocaleString('en-US')
  },
  set(val: string) {
    const digitsOnly = val.replace(/[^\d]/g, '')
    basicsForm.value.price_thb = digitsOnly === '' ? '' : Number(digitsOnly)
  },
})

function syncBasicsFormFromProduct(p: Product) {
  basicsForm.value = {
    name: p.name,
    price_thb: p.price_satang / 100,
    is_active: p.is_active,
    brand_id: p.brand?.id ?? '',
    category_id: p.category?.id ?? '',
    commission_plan_type: p.commission_plan_type ?? '',
    affiliate_override_mode: p.affiliate_override_mode ?? '',
    commission_rate_type: p.commission_rate_type ?? '',
    pipeline_template_id: p.pipeline_template_id ?? '',
    voucher_usage_quota: p.voucher_usage_quota ?? '',
    voucher_validity_days: p.voucher_validity_days ?? '',
    requires_shipping: p.requires_shipping,
  }
}

async function saveBasics() {
  if (isCreateMode.value && activeCompany.requiresCompanyPick) {
    errorMessage.value = 'กรุณาเลือกบริษัทก่อนบันทึก'
    return
  }
  savingBasics.value = true
  errorMessage.value = ''
  try {
    const payload = {
      name: basicsForm.value.name,
      brand_id: Number(basicsForm.value.brand_id),
      category_id: Number(basicsForm.value.category_id),
      price_satang: Math.round(Number(basicsForm.value.price_thb) * 100), // THB -> satang (BR-3)
      is_active: basicsForm.value.is_active,
      // ADR-011/TASK-027/034: '' (inherit) -> explicit null, which both
      // StoreProductRequest and UpdateProductRequest accept — an
      // UPDATE with an explicit null is how an existing override gets
      // cleared back to "inherit from company" (UpdateProductRequest's
      // own ['sometimes', 'nullable', ...] rule was written specifically
      // to allow this, per TASK-027's Resource/Request review).
      commission_plan_type: basicsForm.value.commission_plan_type || null,
      // TASK-194 §3.1/§3.4 — '' -> explicit null, same sentinel contract
      // as commission_plan_type directly above. Always sent (harmless
      // when effective_plan_type isn't Affiliate — the backend ignores
      // it outside that plan type per the enum's own docblock).
      affiliate_override_mode: basicsForm.value.affiliate_override_mode || null,
      // TASK-197 §2.1/§3.2 — '' -> explicit null, same inherit/clear
      // contract as the other sentinel fields above. Once a value is
      // locked in (either picked here, or auto-set server-side by the
      // first commission_rules row, §2.2), sending null again would
      // clear it back to "unconfigured" — UpdateProductRequest is the
      // authoritative gate on whether that's ever allowed.
      commission_rate_type: basicsForm.value.commission_rate_type || null,
      // ADR-026 §3.3 — identical inherit-or-override contract: '' ->
      // explicit null clears the product's own journey back to
      // "resolve from the category, then the company". Both Product
      // Requests declare it ['sometimes','nullable','integer', exists
      // scoped to the SAME company], so an explicit null is accepted and
      // is the only way to clear an existing override.
      pipeline_template_id: basicsForm.value.pipeline_template_id === '' ? null : basicsForm.value.pipeline_template_id,
      // ADR-033 (TASK-189) §2.3/§2.5 — '' -> explicit null, same
      // inherit/clear contract as commission_plan_type and
      // pipeline_template_id above: null means unlimited quota / never
      // expires, and an explicit null on UPDATE is how an existing
      // number gets cleared back to that.
      voucher_usage_quota: basicsForm.value.voucher_usage_quota === '' ? null : Number(basicsForm.value.voucher_usage_quota),
      voucher_validity_days: basicsForm.value.voucher_validity_days === '' ? null : Number(basicsForm.value.voucher_validity_days),
      requires_shipping: basicsForm.value.requires_shipping,
      // StoreProductRequest: company_id required for Super Admin only
      // (Company Admin's own company is inferred server-side) — omit
      // entirely for Company Admin so we never send a stray null/0.
      ...(isCreateMode.value && isSuperAdmin.value ? { company_id: selectedCompanyId.value } : {}),
    }
    if (isCreateMode.value) {
      const res = await api.post<{ data: Product }>('/products', payload)
      // Nested sections (media/specs/commission/materials) all need a
      // product id — redirect straight into edit mode (ADR-008 §7).
      router.push({ name: 'product-edit', params: { id: res.data.id } })
    } else {
      const res = await api.put<{ data: Product }>(`/products/${product.value!.id}`, payload)
      product.value = res.data
      syncBasicsFormFromProduct(res.data)
    }
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'บันทึกข้อมูลสินค้าไม่สำเร็จ')
  } finally {
    savingBasics.value = false
  }
}

// 2026-08-17 — human request: one "บันทึก" button for the whole "ข้อมูล
// สินค้า" section instead of two (basics + the recommendation pin's own
// separate button below it). savePin() itself is unchanged (still its own
// try/catch/pinError, still a no-op when never-pinned-and-staying-unpinned)
// — this just chains it after saveBasics() behind the single submit
// button. Pin save is skipped in create mode: savePin() reads
// product.value, which doesn't exist until saveBasics() has redirected
// into edit mode on a fresh create (see saveBasics()'s isCreateMode branch
// above), so there is nothing to pin yet on that first submit.
async function saveBasicsAndPin() {
  await saveBasics()
  if (!isCreateMode.value) {
    await savePin()
  }
}

// ── Section C — description (pencil-edit toggle) ──
const editingDescription = ref(false)
const descriptionDraft = ref('')
const savingDescription = ref(false)
function startEditDescription() {
  descriptionDraft.value = product.value?.description ?? ''
  editingDescription.value = true
}
function cancelEditDescription() {
  editingDescription.value = false
}
async function saveDescription() {
  if (!product.value) return
  savingDescription.value = true
  errorMessage.value = ''
  try {
    const res = await api.put<{ data: Product }>(`/products/${product.value.id}`, {
      description: descriptionDraft.value || null,
    })
    product.value = res.data
    editingDescription.value = false
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'บันทึกคำอธิบายไม่สำเร็จ')
  } finally {
    savingDescription.value = false
  }
}

// ── Section F — spec description (same pencil-edit toggle pattern) ──
const editingSpecDescription = ref(false)
const specDescriptionDraft = ref('')
const savingSpecDescription = ref(false)
function startEditSpecDescription() {
  specDescriptionDraft.value = product.value?.spec_description ?? ''
  editingSpecDescription.value = true
}
function cancelEditSpecDescription() {
  editingSpecDescription.value = false
}
async function saveSpecDescription() {
  if (!product.value) return
  savingSpecDescription.value = true
  errorMessage.value = ''
  try {
    const res = await api.put<{ data: Product }>(`/products/${product.value.id}`, {
      spec_description: specDescriptionDraft.value || null,
    })
    product.value = res.data
    editingSpecDescription.value = false
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'บันทึกคำอธิบายสเปคไม่สำเร็จ')
  } finally {
    savingSpecDescription.value = false
  }
}

// ── Section B — product media gallery (ADR-007, ported from
// ProductCatalogView.vue's expandable-row panel, single-product scope) ──
const media = ref<ProductMediaItem[]>([])
const loadingMedia = ref(false)
const mediaError = ref('')

async function loadMedia() {
  if (!product.value) return
  loadingMedia.value = true
  try {
    const res = await api.get<{ data: ProductMediaItem[] }>(`/products/${product.value.id}/media`)
    media.value = res.data
  } catch (e) {
    mediaError.value = apiErrorMessage(e, 'โหลดแกลเลอรี่ไม่สำเร็จ')
  } finally {
    loadingMedia.value = false
  }
}

/** Passed to MediaUploadModal as `embedFn` — accepts the URL directly (rather than reading a shared ref) so the modal can call this repeatedly for multiple links without closing. */
async function addProductVideoEmbed(url: string) {
  if (!product.value) return
  await api.post(`/products/${product.value.id}/media`, {
    media_type: 'video',
    source_type: 'embed',
    embed_url: url,
  })
  await loadMedia()
}

// Hero + 3×3 grid layout (redesign, human-confirmed 2026-07-19,
// reference: e-commerce-style "1 big image + thumbnail grid").
// Primary item (or the first uploaded item if none is marked primary
// yet) is the hero; everything else fills a 3×3 grid; if there are more
// than fit, the last cell becomes a "เพิ่มเติม" (More) button opening a
// modal with the rest — same star/delete actions available everywhere.
const GRID_SIZE = 12 // 3 cols × 4 rows — human-confirmed 2026-07-19

/*
 * TASK-097 — `media` holds BOTH galleries; every consumer below picks
 * its own half. One fetch (and one refresh after every mutation) rather
 * than two independent lists that can drift out of sync after an upload.
 */
const coverMedia = computed<ProductMediaItem[]>(() => media.value.filter((m) => m.purpose === 'cover'))
const detailMedia = computed<ProductMediaItem[]>(() => media.value.filter((m) => m.purpose !== 'cover'))

const heroMedia = computed<ProductMediaItem | null>(() => detailMedia.value.find((m) => m.is_primary) ?? detailMedia.value[0] ?? null)
const restMedia = computed<ProductMediaItem[]>(() => detailMedia.value.filter((m) => m.id !== heroMedia.value?.id))
const hasMediaOverflow = computed(() => restMedia.value.length > GRID_SIZE)
const visibleGridMedia = computed<ProductMediaItem[]>(() => (hasMediaOverflow.value ? restMedia.value.slice(0, GRID_SIZE - 1) : restMedia.value.slice(0, GRID_SIZE)))
const overflowGridMedia = computed<ProductMediaItem[]>(() => (hasMediaOverflow.value ? restMedia.value.slice(GRID_SIZE - 1) : []))
const showMoreMediaModal = ref(false)
const showMediaUploadModal = ref(false)

// Click-to-preview (human-requested 2026-07-19): every tile in the
// gallery grid opens MediaPreviewModal, arrow-navigable across every
// item (not just the visible grid — same full `media` list the "More"
// overflow modal already shows).
const mediaPreviewItems = computed<PreviewItem[]>(() =>
  media.value.map((m) => ({
    id: m.id,
    kind: m.source_type === 'embed' ? (isYoutubeUrl(m.embed_url) ? 'youtube' : 'embed') : m.media_type,
    streamUrl: m.stream_url,
    embedUrl: m.embed_url,
  })),
)
const mediaPreviewIndex = ref<number | null>(null)
function openMediaPreview(item: ProductMediaItem) {
  const idx = media.value.findIndex((m) => m.id === item.id)
  if (idx !== -1) mediaPreviewIndex.value = idx
}

/** Passed to MediaUploadModal as `uploadFn` — infers media_type from the file's mime type, matching what uploadProductImage/uploadProductVideoFile did per-input before. */
function uploadMediaFile(file: File, onProgress: (fraction: number) => void) {
  const isVideo = file.type.startsWith('video/')
  // TASK-097 — explicit, even though 'detail' is the server default: this
  // modal sits inside the รายละเอียดสินค้า block and must never quietly
  // start writing covers if that default ever changes.
  const fields: Record<string, string> = { media_type: isVideo ? 'video' : 'image', purpose: 'detail' }
  if (isVideo) fields.source_type = 'upload'

  // TASK-094 — postFileWithProgress chunks anything over 4MB so PHP's
  // per-request post_max_size is never the ceiling. Videos are exactly
  // why: a 44MB clip 413'd before this existed.
  return api.postFileWithProgress(`/products/${product.value!.id}/media`, file, fields, onProgress)
}

/*
 * TASK-097 / ADR-022 — "รูปสินค้า" is a SET of photos, Shopee-style.
 *
 * Human request (2026-08-04): "ทำไม up รูปปกสินค้า แล้วรายละเอียดสินค้า
 * ขึ้นด้วย ต้องแยกกัน และรูปสินค้าสามารถ Upload ได้หลายรูปเหมือน Shopee."
 *
 * The previous version (TASK-096) surfaced the existing `is_primary`
 * flag, which meant the cover was literally a row in the detail gallery
 * — uploading one made it appear in both places. `product_media.purpose`
 * now separates them for real, so this section only ever shows, uploads
 * to and deletes from the cover set.
 *
 * Images only, enforced server-side too (StoreProductMediaRequest): the
 * cover is what ProductResource::thumbnail_url gives the storefront card,
 * and a video there renders as an empty box.
 */
const coverInput = ref<HTMLInputElement | null>(null)
const coverUploading = ref(false)
const coverProgress = ref(0)
const coverQueueTotal = ref(0)
const coverQueueDone = ref(0)

function pickCoverFiles() {
  coverInput.value?.click()
}

/**
 * Uploads sequentially, not with Promise.all.
 *
 * The backend decides `is_primary` for the first cover of a product
 * (ProductMediaService::store) — firing them in parallel would race that
 * check and could leave two rows flagged, or none. Sequential also keeps
 * the progress number honest and avoids N concurrent chunked uploads
 * competing on a shared-hosting connection.
 */
async function onCoverFilesSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])

  // Reset immediately so re-picking the SAME file still fires change.
  input.value = ''

  if (files.length === 0 || !product.value) return

  mediaError.value = ''
  coverUploading.value = true
  coverQueueTotal.value = files.length
  coverQueueDone.value = 0

  try {
    for (const file of files) {
      coverProgress.value = 0

      await api.postFileWithProgress<{ data: ProductMediaItem }>(
        `/products/${product.value.id}/media`,
        file,
        { media_type: 'image', purpose: 'cover' },
        (f) => { coverProgress.value = Math.round(f * 100) },
      ).promise

      coverQueueDone.value += 1
    }
  } catch (e) {
    mediaError.value = apiErrorMessage(e, 'อัปโหลดรูปสินค้าไม่สำเร็จ')
  } finally {
    coverUploading.value = false
    // Refresh even on failure: earlier files in the queue may already
    // have been created, and leaving them invisible would invite the
    // admin to upload duplicates.
    await loadMedia()
  }
}

async function setPrimaryMedia(item: ProductMediaItem) {
  try {
    await api.put(`/product-media/${item.id}`, { is_primary: true })
    await loadMedia()
  } catch (e) {
    mediaError.value = apiErrorMessage(e, 'ตั้งรูปหลักไม่สำเร็จ')
  }
}

async function deleteMedia(mediaId: number) {
  try {
    await api.delete(`/product-media/${mediaId}`)
    media.value = media.value.filter((m) => m.id !== mediaId)
  } catch (e) {
    mediaError.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}

/** Download icon on every image/video tile (human-requested 2026-07-19) — excludes embed items, there's no file to save, embed_url IS the content at an external host. */
async function downloadMediaItem(item: ProductMediaItem) {
  try {
    await api.download(`/product-media/${item.id}/download`)
  } catch (e) {
    mediaError.value = apiErrorMessage(e, 'ดาวน์โหลดไม่สำเร็จ')
  }
}

function processingStatusLabel(status: ProductMediaItem['processing_status']): string {
  switch (status) {
    case 'pending':
    case 'processing':
      return 'กำลังย่อไฟล์วิดีโอ...'
    case 'failed':
      return 'ย่อไฟล์ไม่สำเร็จ (ใช้ไฟล์ต้นฉบับ)'
    default:
      return ''
  }
}

// ── Section E — key-value specs (ADR-007, ported as-is) ──
const specs = ref<ProductSpecItem[]>([])
const loadingSpecs = ref(false)
const specError = ref('')
const specForm = ref({ spec_group: '', spec_key: '', spec_value: '' })
const addingSpec = ref(false)
const editingSpecId = ref<number | null>(null)
const editSpecForm = ref({ spec_group: '', spec_key: '', spec_value: '' })

interface SpecGroupView {
  label: string
  items: ProductSpecItem[]
}

// Human-requested 2026-07-20: spec_group drives groupedSpecs() below via
// exact-string match, so the input became a forced-select GroupCombobox
// instead of free text — this supplies its option list from groups
// already used on this product (deduped, sorted).
const existingSpecGroups = computed<string[]>(() => {
  const set = new Set<string>()
  for (const s of specs.value) {
    if (s.spec_group) set.add(s.spec_group)
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'th'))
})

function groupedSpecs(items: ProductSpecItem[]): SpecGroupView[] {
  if (!items.length) return []
  const byLabel = new Map<string, ProductSpecItem[]>()
  for (const s of items) {
    const label = s.spec_group ?? ''
    const existing = byLabel.get(label)
    if (existing) {
      existing.push(s)
    } else {
      byLabel.set(label, [s])
    }
  }
  return Array.from(byLabel.entries()).map(([label, groupItems]) => ({ label, items: groupItems }))
}

async function loadSpecs() {
  if (!product.value) return
  loadingSpecs.value = true
  try {
    const res = await api.get<{ data: ProductSpecItem[] }>(`/products/${product.value.id}/specs`)
    specs.value = res.data
  } catch (e) {
    specError.value = apiErrorMessage(e, 'โหลดสเปคสินค้าไม่สำเร็จ')
  } finally {
    loadingSpecs.value = false
  }
}

async function addSpec() {
  if (!specForm.value.spec_key || !specForm.value.spec_value || !product.value) return
  addingSpec.value = true
  specError.value = ''
  try {
    await api.post(`/products/${product.value.id}/specs`, {
      spec_group: specForm.value.spec_group || undefined,
      spec_key: specForm.value.spec_key,
      spec_value: specForm.value.spec_value,
    })
    specForm.value = { spec_group: '', spec_key: '', spec_value: '' }
    await loadSpecs()
  } catch (e) {
    specError.value = apiErrorMessage(e, 'เพิ่มสเปคไม่สำเร็จ')
  } finally {
    addingSpec.value = false
  }
}

function startEditSpec(spec: ProductSpecItem) {
  editingSpecId.value = spec.id
  editSpecForm.value = { spec_group: spec.spec_group ?? '', spec_key: spec.spec_key, spec_value: spec.spec_value }
}
function cancelEditSpec() {
  editingSpecId.value = null
}
async function saveEditSpec(specId: number) {
  specError.value = ''
  try {
    await api.put(`/product-specs/${specId}`, {
      spec_group: editSpecForm.value.spec_group || undefined,
      spec_key: editSpecForm.value.spec_key,
      spec_value: editSpecForm.value.spec_value,
    })
    editingSpecId.value = null
    await loadSpecs()
  } catch (e) {
    specError.value = apiErrorMessage(e, 'บันทึกสเปคไม่สำเร็จ')
  }
}
async function deleteSpec(specId: number) {
  try {
    await api.delete(`/product-specs/${specId}`)
    specs.value = specs.value.filter((s) => s.id !== specId)
  } catch (e) {
    specError.value = apiErrorMessage(e, 'ลบสเปคไม่สำเร็จ')
  }
}

// ── Section G — spec-attachment gallery (ADR-008 Decision 2: new
// image+PDF gallery, separate from the hero/thumbnail product_media
// gallery above) ──
const specAttachments = ref<ProductSpecAttachmentItem[]>([])
const loadingSpecAttachments = ref(false)
const specAttachmentError = ref('')
const showSpecAttachmentUploadModal = ref(false)

// Hero + grid layout (human-requested 2026-07-19, same visual pattern as
// the media gallery above). Unlike ProductMediaItem, spec attachments have
// no is_primary flag — so there's no "set as hero" action here, the hero
// is simply whichever item sorts first (sort_order from the API).
const attachmentHero = computed<ProductSpecAttachmentItem | null>(() => specAttachments.value[0] ?? null)
const attachmentRest = computed<ProductSpecAttachmentItem[]>(() => specAttachments.value.slice(1))

// Click-to-preview (human-requested 2026-07-19) — same MediaPreviewModal
// as the media gallery above, normalized for image/pdf/embed items.
const specAttachmentPreviewItems = computed<PreviewItem[]>(() =>
  specAttachments.value.map((a) => ({
    id: a.id,
    kind: a.source_type === 'embed' ? (isYoutubeUrl(a.embed_url) ? 'youtube' : 'embed') : a.media_type,
    streamUrl: a.stream_url,
    embedUrl: a.embed_url,
  })),
)
const specAttachmentPreviewIndex = ref<number | null>(null)

async function loadSpecAttachments() {
  if (!product.value) return
  loadingSpecAttachments.value = true
  try {
    const res = await api.get<{ data: ProductSpecAttachmentItem[] }>(`/products/${product.value.id}/spec-attachments`)
    specAttachments.value = res.data
  } catch (e) {
    specAttachmentError.value = apiErrorMessage(e, 'โหลดไฟล์แนบสเปคไม่สำเร็จ')
  } finally {
    loadingSpecAttachments.value = false
  }
}

/** Passed to MediaUploadModal as `uploadFn` — same pattern as the media gallery's uploadMediaFile, infers media_type (image vs pdf) from the file's mime type. */
function uploadSpecAttachmentFile(file: File, onProgress: (fraction: number) => void) {
  const isPdf = file.type === 'application/pdf'

  return api.postFileWithProgress(
    `/products/${product.value!.id}/spec-attachments`,
    file,
    { media_type: isPdf ? 'pdf' : 'image', source_type: 'upload' },
    onProgress,
  )
}

/** Passed to MediaUploadModal as `embedFn`. The shared modal has no
 * image/PDF type dropdown (unlike the old inline form here), so
 * media_type is inferred from the URL's extension — .pdf → pdf,
 * otherwise image (human-confirmed 2026-07-19, same reasoning as the
 * media gallery's automatic YouTube detection). */
async function addSpecAttachmentEmbedLink(url: string) {
  if (!product.value) return
  const isPdf = /\.pdf(?:[?#]|$)/i.test(url)
  await api.post(`/products/${product.value.id}/spec-attachments`, {
    media_type: isPdf ? 'pdf' : 'image',
    source_type: 'embed',
    embed_url: url,
  })
  await loadSpecAttachments()
}

async function deleteSpecAttachment(attachmentId: number) {
  try {
    await api.delete(`/product-spec-attachments/${attachmentId}`)
    specAttachments.value = specAttachments.value.filter((a) => a.id !== attachmentId)
  } catch (e) {
    specAttachmentError.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}

/** Download icon on every image/PDF tile (human-requested 2026-07-19) — excludes embed items, same reasoning as downloadMediaItem. */
async function downloadSpecAttachment(attachment: ProductSpecAttachmentItem) {
  try {
    await api.download(`/product-spec-attachments/${attachment.id}/download`)
  } catch (e) {
    specAttachmentError.value = apiErrorMessage(e, 'ดาวน์โหลดไม่สำเร็จ')
  }
}

function specAttachmentStatusLabel(status: ProductSpecAttachmentItem['processing_status']): string {
  switch (status) {
    case 'pending':
    case 'processing':
      return 'กำลังประมวลผล...'
    case 'failed':
      return 'สร้างตัวอย่างไม่สำเร็จ'
    default:
      return ''
  }
}

function openSpecAttachment(attachment: ProductSpecAttachmentItem) {
  const idx = specAttachments.value.findIndex((a) => a.id === attachment.id)
  if (idx !== -1) specAttachmentPreviewIndex.value = idx
}

// ── Section H — Sales materials + share-links (human-requested,
// 2026-07-13; ADR-007 extended for video/embed; 2026-07-20 redesign —
// grouped into free-text sections, each with its own scoped grid +
// upload button, matching the media-gallery/spec-attachment hero-grid
// visual pattern) ──
const materials = ref<SalesMaterialItem[]>([])
const loadingMaterials = ref(false)
const materialError = ref('')

// Human-requested 2026-07-20: group sales materials into free-text
// sections (e.g. "บทเรียนที่ 1") — same grouping shape/pattern as
// product_specs.spec_group (GroupCombobox + exact-match grouping), just
// confirmed to be its own independent field, not tied to Academy modules.
const editingMaterialGroupId = ref<number | null>(null)

interface MaterialGroupView {
  label: string
  items: SalesMaterialItem[]
}
const existingMaterialGroups = computed<string[]>(() => {
  const set = new Set<string>()
  for (const m of materials.value) {
    if (m.material_group) set.add(m.material_group)
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'th'))
})
function groupedMaterials(items: SalesMaterialItem[]): MaterialGroupView[] {
  if (!items.length) return []
  const byLabel = new Map<string, SalesMaterialItem[]>()
  for (const m of items) {
    const label = m.material_group ?? ''
    const existing = byLabel.get(label)
    if (existing) {
      existing.push(m)
    } else {
      byLabel.set(label, [m])
    }
  }
  return Array.from(byLabel.entries()).map(([label, groupItems]) => ({ label, items: groupItems }))
}

// 3 cols × 6 rows per group, "More" tile replaces the last cell on
// overflow — same GRID_SIZE pattern as the media gallery's 3×4 grid
// (see GRID_SIZE above), just human-requested at double the row count
// here since materials render one grid PER GROUP rather than one grid
// total. Plain functions (not computed) since there are N groups, not one.
const MATERIAL_GRID_SIZE = 18
function materialGridHasOverflow(items: SalesMaterialItem[]): boolean {
  return items.length > MATERIAL_GRID_SIZE
}
function materialGridVisible(items: SalesMaterialItem[]): SalesMaterialItem[] {
  return materialGridHasOverflow(items) ? items.slice(0, MATERIAL_GRID_SIZE - 1) : items.slice(0, MATERIAL_GRID_SIZE)
}
function materialGridOverflowCount(items: SalesMaterialItem[]): number {
  return materialGridHasOverflow(items) ? items.length - (MATERIAL_GRID_SIZE - 1) : 0
}

const showMoreMaterialsModal = ref(false)
const moreMaterialsGroupLabel = ref('')
const moreMaterialsItems = computed<SalesMaterialItem[]>(
  () => groupedMaterials(materials.value).find((g) => g.label === moreMaterialsGroupLabel.value)?.items ?? [],
)
function openMoreMaterials(groupLabel: string) {
  moreMaterialsGroupLabel.value = groupLabel
  showMoreMaterialsModal.value = true
}

async function updateMaterialGroup(material: SalesMaterialItem, newGroup: string | null) {
  try {
    await api.patch(`/sales-materials/${material.id}`, { material_group: newGroup })
    material.material_group = newGroup
  } catch (e) {
    materialError.value = apiErrorMessage(e, 'แก้ไขกลุ่มไม่สำเร็จ')
  } finally {
    editingMaterialGroupId.value = null
  }
}

async function loadMaterials() {
  if (!product.value) return
  loadingMaterials.value = true
  try {
    const res = await api.get<{ data: SalesMaterialItem[] }>(`/products/${product.value.id}/sales-materials`)
    materials.value = res.data
  } catch (e) {
    materialError.value = apiErrorMessage(e, 'โหลดสื่อการขายไม่สำเร็จ')
  } finally {
    loadingMaterials.value = false
  }
}

// Human-requested 2026-07-20 (revised): the upload button is now
// per-group, not a single shared button — each group's own "+ อัปโหลด"
// button (plus the bottom "new group / ไม่มีกลุ่ม" card) calls
// openMaterialUpload(groupLabel) right before showing the modal, so
// uploadMaterialFn/addMaterialEmbedLink (below) know which group to
// stamp on every file/link added during that modal session.
const showMaterialUploadModal = ref(false)
const materialUploadTargetGroup = ref<string | null>(null)
function openMaterialUpload(groupLabel: string | null) {
  materialUploadTargetGroup.value = groupLabel
  showMaterialUploadModal.value = true
}

/** Passed to MediaUploadModal as `uploadFn`. */
function uploadMaterialFile(file: File, onProgress: (fraction: number) => void) {
  const fields: Record<string, string> = {}
  if (materialUploadTargetGroup.value) fields.material_group = materialUploadTargetGroup.value

  return api.postFileWithProgress(`/products/${product.value!.id}/sales-materials`, file, fields, onProgress)
}

/** Passed to MediaUploadModal as `embedFn`. */
async function addMaterialEmbedLink(url: string) {
  if (!product.value) return
  await api.post(`/products/${product.value.id}/sales-materials`, {
    source_type: 'embed',
    embed_url: url,
    material_group: materialUploadTargetGroup.value || undefined,
  })
  await loadMaterials()
}

// Bottom "เพิ่มกลุ่มใหม่ / ไม่มีกลุ่ม" card's own group picker — separate
// from materialUploadTargetGroup (that one is set by whichever button was
// actually clicked; this one is just the combobox's current draft value).
const newMaterialGroupDraft = ref<string | null>(null)

async function deleteMaterial(materialId: number) {
  try {
    await api.delete(`/sales-materials/${materialId}`)
    materials.value = materials.value.filter((m) => m.id !== materialId)
  } catch (e) {
    materialError.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  }
}

async function downloadMaterial(material: SalesMaterialItem) {
  if (!material.original_filename) return
  try {
    await api.download(`/sales-materials/${material.id}/download`, material.original_filename)
  } catch (e) {
    materialError.value = apiErrorMessage(e, 'ดาวน์โหลดไม่สำเร็จ')
  }
}

// Click-to-preview (human-requested 2026-07-20 — sales materials never
// had this before; mirrors media gallery/spec-attachment wiring). Unlike
// those two, SalesMaterialItem has no media_type field — kind is derived
// from mime_type instead.
function materialKind(m: SalesMaterialItem): PreviewItem['kind'] {
  if (m.source_type === 'embed') return isYoutubeUrl(m.embed_url) ? 'youtube' : 'embed'
  if (m.mime_type === 'application/pdf') return 'pdf'
  if (m.mime_type?.startsWith('video/')) return 'video'
  return 'image'
}
const materialPreviewItems = computed<PreviewItem[]>(() =>
  materials.value.map((m) => ({
    id: m.id,
    kind: materialKind(m),
    streamUrl: m.stream_url,
    embedUrl: m.embed_url,
    label: m.original_filename ?? undefined,
  })),
)
const materialPreviewIndex = ref<number | null>(null)
function openMaterialPreview(item: SalesMaterialItem) {
  const idx = materials.value.findIndex((m) => m.id === item.id)
  if (idx !== -1) materialPreviewIndex.value = idx
}

// External share links (ADR-007 Decision 3 — signed, expiring,
// revocable PUBLIC link for one sales material; deliberate, narrow,
// human-approved exception to "never a public URL").
const shareLinksByMaterial = ref<Record<number, ShareLinkItem[]>>({})
const expandedShareLinksMaterialId = ref<number | null>(null)
const loadingShareLinksFor = ref<number | null>(null)
const creatingShareLinkFor = ref<number | null>(null)
const shareLinkExpiryDays = ref(7)
const shareError = ref('')
const copiedShareLinkId = ref<number | null>(null)

async function toggleShareLinks(materialId: number) {
  if (expandedShareLinksMaterialId.value === materialId) {
    expandedShareLinksMaterialId.value = null
    return
  }
  expandedShareLinksMaterialId.value = materialId
  shareError.value = ''
  if (!shareLinksByMaterial.value[materialId]) await loadShareLinksFor(materialId)
}

async function loadShareLinksFor(materialId: number) {
  loadingShareLinksFor.value = materialId
  try {
    const res = await api.get<{ data: ShareLinkItem[] }>(`/sales-materials/${materialId}/share-links`)
    shareLinksByMaterial.value[materialId] = res.data
  } catch (e) {
    shareError.value = apiErrorMessage(e, 'โหลดลิงก์แชร์ไม่สำเร็จ')
  } finally {
    loadingShareLinksFor.value = null
  }
}

async function createShareLink(materialId: number) {
  creatingShareLinkFor.value = materialId
  shareError.value = ''
  try {
    await api.post(`/sales-materials/${materialId}/share-links`, { expires_in_days: shareLinkExpiryDays.value })
    await loadShareLinksFor(materialId)
  } catch (e) {
    shareError.value = apiErrorMessage(e, 'สร้างลิงก์แชร์ไม่สำเร็จ')
  } finally {
    creatingShareLinkFor.value = null
  }
}

async function revokeShareLink(materialId: number, linkId: number) {
  try {
    await api.delete(`/share-links/${linkId}`)
    await loadShareLinksFor(materialId)
  } catch (e) {
    shareError.value = apiErrorMessage(e, 'ยกเลิกลิงก์ไม่สำเร็จ')
  }
}

async function copyShareLink(link: ShareLinkItem) {
  try {
    await navigator.clipboard.writeText(link.share_url)
    copiedShareLinkId.value = link.id
    setTimeout(() => {
      if (copiedShareLinkId.value === link.id) copiedShareLinkId.value = null
    }, 2000)
  } catch {
    shareError.value = 'คัดลอกลิงก์ไม่สำเร็จ — กรุณาคัดลอกด้วยตนเอง'
  }
}

function isLinkUsable(link: ShareLinkItem): boolean {
  return !link.revoked_at && new Date(link.expires_at) > new Date()
}

// ── Section D — Commission panel (ADR-008 Decision 3: directly
// editable on this page, not read-only like the old commission_rules
// tab). No nested GET /products/{product}/commission-rules route — the
// flat /commission-rules collection is loaded once and filtered
// client-side, exactly like ProductCatalogView.vue's tab already does. ──
const commissionRules = ref<CommissionRule[]>([])
const productRules = computed(() => commissionRules.value.filter((r) => r.product?.id === product.value?.id))

async function loadCommissionRules() {
  try {
    const res = await api.get<{ data: CommissionRule[] }>('/commission-rules')
    commissionRules.value = res.data
  } catch (e) {
    // Company Admin/Super Admin only (CommissionRulePolicy) — a 403
    // here shouldn't blank the rest of the page.
    if (!(e instanceof ApiError && e.status === 403)) {
      errorMessage.value = apiErrorMessage(e, 'โหลดอัตราคอมมิชชั่นไม่สำเร็จ')
    }
  }
}

// ── Recommendation pin (TASK-068 / ADR-020 row 4) — "ปักหมุดแนะนำ" +
// sort_order. This whole app is already Company Admin/Super Admin only
// (Agent is blocked at the frontend-admin app boundary), so no further
// role check is needed beyond simply being on this page. No nested
// GET /products/{product}/recommendation-pin route exists — same
// load-everything-then-filter-client-side pattern as commissionRules
// above (and ProductCatalogView.vue's own commission_rules tab). ──
const recommendationPins = ref<RecommendationPinItem[]>([])
const currentPin = computed(() => recommendationPins.value.find((p) => p.product_id === product.value?.id) ?? null)
const pinForm = ref({ is_pinned: false, sort_order: 0 })
const pinError = ref('')
const savingPin = ref(false)

async function loadRecommendationPins() {
  try {
    const res = await api.get<{ data: RecommendationPinItem[] }>('/product-recommendation-pins')
    recommendationPins.value = res.data
    const pin = currentPin.value
    pinForm.value = { is_pinned: pin?.is_active ?? false, sort_order: pin?.sort_order ?? 0 }
  } catch (e) {
    // Company Admin/Super Admin only (ProductRecommendationPinPolicy) —
    // mirrors loadCommissionRules()'s 403 handling above.
    if (!(e instanceof ApiError && e.status === 403)) {
      pinError.value = apiErrorMessage(e, 'โหลดข้อมูลปักหมุดแนะนำไม่สำเร็จ')
    }
  }
}

async function savePin() {
  if (!product.value) return
  savingPin.value = true
  pinError.value = ''
  try {
    const pin = currentPin.value
    if (pin) {
      const res = await api.put<{ data: RecommendationPinItem }>(`/product-recommendation-pins/${pin.id}`, {
        sort_order: pinForm.value.sort_order,
        is_active: pinForm.value.is_pinned,
      })
      recommendationPins.value = recommendationPins.value.map((p) => (p.id === pin.id ? res.data : p))
    } else if (pinForm.value.is_pinned) {
      const res = await api.post<{ data: RecommendationPinItem }>('/product-recommendation-pins', {
        product_id: product.value.id,
        sort_order: pinForm.value.sort_order,
        is_active: true,
        // StoreProductRecommendationPinRequest requires company_id for
        // Super Admin (prohibited for everyone else) — same shape as
        // StoreProductRequest/saveBasics() above. product.value.company_id
        // is always the pin's own target company (this product's owner),
        // never the actor's — the exact company this pin must belong to.
        ...(isSuperAdmin.value ? { company_id: product.value.company_id } : {}),
      })
      recommendationPins.value = [...recommendationPins.value, res.data]
    }
    // else: never pinned and staying unpinned — nothing to persist.
  } catch (e) {
    pinError.value = apiErrorMessage(e, 'บันทึกการปักหมุดแนะนำไม่สำเร็จ')
  } finally {
    savingPin.value = false
  }
}

function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 0 }) + ' บาท'
}
function formatRate(rule: CommissionRule): string {
  if (rule.rate_type === 'percentage') return (rule.rate_value / 100).toFixed(2) + '%'
  return formatSatang(rule.rate_value)
}

const ruleForm = ref({
  rate_value_input: '' as string | number, // % if percentage, THB if fixed_satang
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '' as string,
  renewal_rate_percent: '' as string | number,
  renewal_recurs: false,
})
const ruleError = ref('')
const savingRule = ref(false)

// TASK-197 §3.3/§3.4 — the FORMAT every commission_rules row on THIS
// product must submit, resolved from the product's own persisted
// commission_rate_type (server-authoritative, §2.2 — a mismatched
// rate_type in the payload is rejected). Defaults to 'percentage' when
// null (never configured yet), same default the per-rule selector used
// before this task and the same default §2.1 specifies for the very
// first rule a product ever gets. The per-rule rate_type selector this
// used to read from is gone (§3.3) — every form below reads this instead.
// 2026-08-18 — human report: picking "จำนวนคงที่ (บาท)" in the settings
// dropdown above left this label/hint stuck on "อัตรา (%)" until after a
// save round-trip, since it only ever read the PERSISTED
// product.value.commission_rate_type. Now that both blocks share a single
// "บันทึก" button (saveCommissionTab()), that lag reads as "the fixed-
// amount option doesn't work" even though the actual save was always
// correct (saveBasics() persists commission_rate_type and refreshes
// product.value BEFORE submitRule() reads this computed — see
// saveCommissionTab()). Prefer the live, unsaved dropdown pick first so
// the label/preview tracks what the admin just selected; fall back to the
// persisted value, then 'percentage', for the untouched/"inherit" case.
const resolvedRuleRateType = computed<CommissionRateType>(
  () => basicsForm.value.commission_rate_type || product.value?.commission_rate_type || 'percentage',
)

// Same conversion for both directions — % -> basis points and THB -> satang
// are both "multiply by 100, round" (mirrors CommissionPlansView.vue's
// rateValueToBasisOrSatang, kept here since this file doesn't import that one).
function rateValueToBasisOrSatang(input: string | number): number {
  return Math.round(Number(input) * 100)
}

// TASK-196 §3.2 — the create-rule form's own cap guard. product.value's
// price_satang is this whole page's own product, always available once the
// nested sections render (create mode hides this tab entirely — see the
// isCreateMode guard around the Tab 2 section below).
const createRuleCapGuard = useCommissionRateCapGuard()
function recheckCreateRuleCap(): void {
  createRuleCapGuard.recheck(resolvedRuleRateType.value, rateValueToBasisOrSatang(ruleForm.value.rate_value_input), product.value?.price_satang ?? null)
}
function recheckCreateRuleCapDebounced(): void {
  createRuleCapGuard.recheckDebounced(resolvedRuleRateType.value, rateValueToBasisOrSatang(ruleForm.value.rate_value_input), product.value?.price_satang ?? null)
}

function renewalPayloadFields(form: typeof ruleForm.value): Record<string, unknown> {
  if (form.renewal_rate_percent === '') return {}
  return {
    renewal_rate_type: 'percentage',
    renewal_rate_value: Math.round(Number(form.renewal_rate_percent) * 100),
    renewal_recurs: form.renewal_recurs,
  }
}

function resetRuleForm() {
  // TASK-200 — effective_from/effective_to are deliberately NOT reset here:
  // a post-submit reset carries the admin's last-used dates forward as the
  // new default for the next tier's rule in this session, since adding
  // several cert-tier rules back-to-back with the same start date is the
  // common case. Only the fresh-page-load ref() declaration above still
  // defaults to today's date / blank.
  ruleForm.value = {
    rate_value_input: '',
    effective_from: ruleForm.value.effective_from,
    effective_to: ruleForm.value.effective_to,
    renewal_rate_percent: '',
    renewal_recurs: false,
  }
  createRuleCapGuard.reset()
}

async function submitRule() {
  if (!product.value) return
  // TASK-196 §3.2 — the Save button is already disabled while over the cap;
  // this defensive re-check covers e.g. an Enter-to-submit keypress
  // bypassing a disabled button in some browsers.
  recheckCreateRuleCap()
  if (createRuleCapGuard.isOverCap.value) return
  savingRule.value = true
  ruleError.value = ''
  const submittedRateType = resolvedRuleRateType.value
  try {
    await api.post('/commission-rules', {
      product_id: product.value.id,
      rate_type: submittedRateType,
      rate_value: rateValueToBasisOrSatang(ruleForm.value.rate_value_input),
      effective_from: ruleForm.value.effective_from,
      effective_to: ruleForm.value.effective_to || null,
      ...renewalPayloadFields(ruleForm.value),
      // StoreCommissionRuleRequest requires company_id for Super Admin only
      // (Company Admin's own company is inferred server-side) — same
      // pattern as saveBasics()/savePin() above. Was missing here; a Super
      // Admin saving a rate on the merged commission tab hit "The company
      // id field is required." because this POST never sent it. This is
      // the product's OWN company (edit-mode only — Tab 2 doesn't render
      // in create mode), not selectedCompanyId (that's only meaningful
      // pre-creation).
      ...(isSuperAdmin.value ? { company_id: product.value.company_id } : {}),
    })
    // TASK-197 §2.2 — the FIRST rule for a product sets its
    // commission_rate_type server-side as a side effect. Patch it
    // locally rather than a whole extra GET /products/{id} round-trip:
    // the settings block above and every subsequent "+ เพิ่มอัตราคอมตาม
    // tier" open now correctly see it as locked in, without a reload.
    if (product.value && product.value.commission_rate_type === null) {
      product.value = { ...product.value, commission_rate_type: submittedRateType }
      basicsForm.value.commission_rate_type = submittedRateType
    }
    resetRuleForm()
    await loadCommissionRules()
  } catch (e) {
    ruleError.value = apiErrorMessage(e, 'บันทึกอัตราคอมมิชชั่นไม่สำเร็จ')
  } finally {
    savingRule.value = false
  }
}

// 2026-08-18 — human request: one "บันทึก" button for the whole
// คอมมิชชั่น tab, mirroring saveBasicsAndPin()'s chaining pattern above.
// Chains saveBasics() (product-level settings: rate_type, affiliate
// override mode) and, ONLY when the admin actually typed a rate value,
// submitRule() (POST a new commission_rules row). The rate field's
// `required` attribute was removed from the template specifically so a
// settings-only submit — rate field left blank — can still go through:
// without this guard, EVERY press of the single shared button would
// attempt to add another commission rate, which is wrong once the admin
// is just tweaking rate_type/affiliate mode on a product that already
// has its rates set up.
async function saveCommissionTab() {
  await saveBasics()
  if (ruleForm.value.rate_value_input !== '') {
    await submitRule()
  }
}

// Inline edit-in-place (ADR-008 — new capability; the old tab was
// create+display only, no edit/delete).
const editingRuleId = ref<number | null>(null)
const editRuleForm = ref({
  rate_value_input: '' as string | number,
  effective_from: '',
  effective_to: '',
  renewal_rate_percent: '' as string | number,
  renewal_recurs: false,
})
// TASK-196 §3.2 — the inline per-row edit form's own guard, separate from
// createRuleCapGuard above (a different Save button, tracked independently
// so "fire once per crossing" is correct for each form on its own). Only
// one row can be in edit mode at a time (editingRuleId is a single ref), so
// one shared instance for the whole list is enough — it's reset every time
// a new row starts editing.
const editRuleCapGuard = useCommissionRateCapGuard()
function recheckEditRuleCap(): void {
  editRuleCapGuard.recheck(resolvedRuleRateType.value, rateValueToBasisOrSatang(editRuleForm.value.rate_value_input), product.value?.price_satang ?? null)
}
function recheckEditRuleCapDebounced(): void {
  editRuleCapGuard.recheckDebounced(resolvedRuleRateType.value, rateValueToBasisOrSatang(editRuleForm.value.rate_value_input), product.value?.price_satang ?? null)
}
function startEditRule(rule: CommissionRule) {
  editingRuleId.value = rule.id
  // TASK-197 §3.3 — the edit form no longer carries its own rate_type
  // (rule.rate_type may be a HISTORICAL value that no longer matches the
  // product's resolved format — never rewritten, §1). Re-saving this row
  // now always submits resolvedRuleRateType, same as a brand-new rule.
  editRuleForm.value = {
    rate_value_input: rule.rate_value / 100,
    effective_from: rule.effective_from,
    effective_to: rule.effective_to ?? '',
    renewal_rate_percent: rule.renewal_rate_type === 'percentage' ? (rule.renewal_rate_value ?? 0) / 100 : '',
    renewal_recurs: rule.renewal_recurs,
  }
  editRuleCapGuard.reset()
}
function cancelEditRule() {
  editingRuleId.value = null
  editRuleCapGuard.reset()
}
async function saveEditRule(rule: CommissionRule) {
  ruleError.value = ''
  // TASK-196 §3.2 — same defensive re-check as submitRule() above.
  recheckEditRuleCap()
  if (editRuleCapGuard.isOverCap.value) return
  try {
    await api.put(`/commission-rules/${rule.id}`, {
      rate_type: resolvedRuleRateType.value,
      rate_value: rateValueToBasisOrSatang(editRuleForm.value.rate_value_input),
      effective_from: editRuleForm.value.effective_from,
      effective_to: editRuleForm.value.effective_to || null,
      ...renewalPayloadFields({
        rate_value_input: '',
        effective_from: '',
        effective_to: '',
        renewal_rate_percent: editRuleForm.value.renewal_rate_percent,
        renewal_recurs: editRuleForm.value.renewal_recurs,
      }),
    })
    editingRuleId.value = null
    await loadCommissionRules()
  } catch (e) {
    ruleError.value = apiErrorMessage(e, 'บันทึกอัตราคอมมิชชั่นไม่สำเร็จ')
  }
}
async function deleteRule(ruleId: number) {
  ruleError.value = ''
  try {
    await api.delete(`/commission-rules/${ruleId}`)
    commissionRules.value = commissionRules.value.filter((r) => r.id !== ruleId)
  } catch (e) {
    ruleError.value = apiErrorMessage(e, 'ลบอัตราคอมมิชชั่นไม่สำเร็จ')
  }
}

// ── Initial load ──
async function loadInitialData() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [b, c] = await Promise.all([
      api.get<{ data: Brand[] }>('/brands'),
      api.get<{ data: ProductCategory[] }>('/product-categories'),
      // ADR-026 — the journey selector's options. Kept in the same
      // parallel batch (not awaited separately) so it costs no extra
      // round-trip; its own failure is swallowed inside the function
      // because a template list that won't load must not blank the
      // whole product form.
      loadPipelineTemplates(),
    ])
    brands.value = b.data
    categories.value = c.data

    if (!isCreateMode.value && productId.value) {
      const p = await api.get<{ data: Product }>(`/products/${productId.value}`)
      product.value = p.data
      syncBasicsFormFromProduct(p.data)

      await Promise.all([loadMedia(), loadSpecs(), loadSpecAttachments(), loadMaterials(), loadCommissionRules(), loadRecommendationPins()])
    } else if (isCreateMode.value && isSuperAdmin.value) {
      await activeCompany.loadCompanies()
    }
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(loadInitialData)

// ── TASK-195 — 6-tab layout (pure navigation reorg of the 8 stacked
// cards above; see docs/tasks/TASK-195-product-edit-tabbed-layout.md).
// Same tab-bar pattern/state shape as CommissionPlansView.vue's own
// `Tab`/`activeTab`/`tabDefs` (its closest precedent — same page, same
// author, same visual language): a plain top-level ref, panels switched
// with `v-if` (not `v-show`) — matching CommissionPlansView's own
// tab-switch behavior exactly (its own rule/binary/matrix/... sections
// are each `v-if="activeTab === '...'"`). In-progress form state in an
// inactive tab still survives the switch because every form's state
// already lives in a top-level ref here (basicsForm, ruleForm,
// specForm, editSpecForm, etc.), never inside a v-for/local scope —
// v-if only unmounts the DOM, it never resets those refs, so
// re-showing a tab just re-binds the same values.
type ProductEditTab = 'general' | 'commission' | 'voucher' | 'media' | 'specs' | 'materials'
const activeTab = ref<ProductEditTab>('general')
const tabDefs: { key: ProductEditTab; label: string; icon: string }[] = [
  { key: 'general', label: 'ทั่วไป', icon: 'cube' },
  { key: 'commission', label: 'คอมมิชชั่น', icon: 'dollar' },
  { key: 'voucher', label: 'บัตรกำนัลและจัดส่ง', icon: 'tag' },
  { key: 'media', label: 'รูปภาพและสื่อ', icon: 'image' },
  { key: 'specs', label: 'สเปคสินค้า', icon: 'layout' },
  { key: 'materials', label: 'สื่อการขาย', icon: 'document' },
]
// Tabs 2-6 need a saved product (ADR-008's own "nested sections need a
// product id" rule, unchanged) — gated at the TAB level now, not just
// its content, per the task spec (§2): a new product only ever sees
// "ทั่วไป" until the first save redirects into edit mode.
const visibleTabDefs = computed(() => tabDefs.filter((t) => t.key === 'general' || !isCreateMode.value))

// Section I — video settings live on ProductCatalogView.vue's own tab
// (company-wide config, not per-product — ADR-008 Decision 6).
// ProductCatalogView.vue now reads an initial tab from ?tab= (added
// alongside the back-button fix below), so this deep-links straight in.
function goToVideoSettings() {
  router.push({ name: 'product-catalog', query: { tab: 'video_settings' } })
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="cube"
      :title="isCreateMode ? 'เพิ่มสินค้าใหม่' : product?.name || 'แก้ไขสินค้า'"
      subtitle="รายละเอียดสินค้า / คอมมิชชั่น / สื่อการขาย"
      accent-color="brand"
      :storage-key="isCreateMode ? 'product-create' : `product-edit-${productId}`"
    >
      <template #before-icon>
        <RouterLink
          :to="{ name: 'product-catalog', query: { tab: 'products' } }"
          class="shrink-0 w-9 h-9 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition"
          title="กลับไปหน้า Product catalog"
        >
          <Icon name="arrow_left" :size="18" />
        </RouterLink>
      </template>
      <template #actions>
        <!-- เปิดใช้งาน — สวิตช์เลื่อนแบบเดียวกับปุ่มเปลี่ยนภาษา (AdminNavigation.vue), สีน้ำเงิน (brand).
             Left at the actions slot's default position (before HeroHeader's own
             collapse/expand chevron) — this is the exact same slot position every
             other screen's "+ เพิ่ม..." button uses (AgentManagementView,
             CompanyManagementView), so its right edge lines up vertically with
             both those buttons and the "บันทึก" button in the form below (same
             column, still on the header row — no row/height change). -->
        <div class="flex items-center gap-2">
          <span class="text-xs font-bold whitespace-nowrap" :class="basicsForm.is_active ? 'text-brand-600' : 'text-slate-400'">
            {{ basicsForm.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}
          </span>
          <button
            type="button"
            :disabled="readOnlyForCompanyAdmin"
            @click="basicsForm.is_active = !basicsForm.is_active"
            class="relative w-14 h-7 shrink-0 rounded-full border transition-colors flex items-center px-1 disabled:opacity-50 disabled:cursor-not-allowed"
            :class="basicsForm.is_active ? 'bg-brand-600 border-brand-600' : 'bg-slate-100 border-slate-200'"
            :title="basicsForm.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน'"
          >
            <div
              class="absolute top-1 bottom-1 w-5 rounded-full shadow bg-white transition-all duration-300"
              :class="basicsForm.is_active ? 'translate-x-7' : 'translate-x-0'"
            ></div>
          </button>
        </div>
      </template>
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in visibleTabDefs"
            :key="t.key"
            type="button"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key"
          >
            <Icon :name="t.icon" :size="16" />
            {{ t.label }}
          </button>
        </div>
      </template>
    </HeroHeader>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700 flex items-center justify-between gap-3">
      <span>{{ errorMessage }}</span>
      <button class="shrink-0 text-rose-400 hover:text-rose-600" @click="errorMessage = ''">
        <Icon name="x" :size="16" />
      </button>
    </div>

    <!-- ADR-036 (TASK-215) — top-of-page notice, visible on every tab
         regardless of which one is active, so the lock is never a
         surprise discovered field-by-field. -->
    <div v-if="isCatalogLinked" class="mt-4 px-4 py-3 rounded-xl bg-blue-50 border border-blue-200 text-sm text-blue-700 flex items-start gap-2">
      <Icon name="globe" :size="16" class="mt-0.5 shrink-0" />
      <span v-if="isSuperAdmin">
        สินค้านี้เชื่อมกับ<span class="font-bold">แคตตาล็อกกลาง</span> — ชื่อ/แบรนด์/หมวดหมู่/คำอธิบาย/คำอธิบายสเปค อ่านได้อย่างเดียวที่นี่ (แก้ไขได้จากแคตตาล็อกกลางเท่านั้น) ส่วนราคา/คอมมิชชั่น/สื่อ/สเปค ยังแก้ไขได้ตามปกติ
      </span>
      <span v-else>
        สินค้านี้เชื่อมกับ<span class="font-bold">แคตตาล็อกกลาง</span> — ข้อมูลทั้งหน้านี้เป็นแบบอ่านอย่างเดียวสำหรับบัญชีของคุณ (แก้ไขได้เฉพาะ Super Admin เท่านั้น)
      </span>
    </div>

    <LoadingSkeleton v-if="loading" type="detail" class="mt-4" />
    <template v-else>
      <!-- Tab 1 — ทั่วไป (General). Available pre-save — the only tab
           visible in create mode (see visibleTabDefs above). Former
           "Section A — basics" card, minus the Affiliate override field
           (moved to the Commission tab, §2 Tab 2) and the voucher/
           shipping block (moved to its own tab, §2 Tab 3) — TASK-195. -->
      <section v-if="activeTab === 'general'" class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5">
        <p class="text-base font-bold text-slate-500 mb-3 flex items-center gap-1.5">
          <Icon name="cube" :size="14" /> ข้อมูลสินค้า
        </p>
        <form class="grid grid-cols-1 sm:grid-cols-2 gap-3" @submit.prevent="saveBasicsAndPin">
          <!-- TASK-208 — read-only confirmation of the company this product
               belongs to (or will be created in). Changing it means changing
               the header scope, which also re-scopes the brand/category
               pickers below — the two must never disagree. -->
          <div v-if="isSuperAdmin" class="sm:col-span-2 flex flex-wrap items-center gap-2 px-3 py-2 rounded-lg bg-brand-50 border border-brand-100">
            <Icon name="building" :size="14" class="text-brand-600 shrink-0" />
            <span class="text-xs font-bold text-brand-700">บริษัท: {{ productCompanyName ?? '— ยังไม่ได้เลือก —' }}</span>
            <span v-if="isCreateMode" class="text-[11px] text-brand-600/70">(เปลี่ยนได้จากปุ่มบริษัทมุมขวาบน)</span>
          </div>
          <!-- ADR-036 (TASK-215) — when this product is catalog-linked,
               name/brand/category are RESOLVED from the shared catalog
               item (never this product's own brand_id/category_id — that
               select would be bound to the wrong table entirely, since a
               linked product's brand/category ids point at
               catalog_brands/catalog_categories, not this company's own
               /brands and /product-categories lists). Read-only display
               for EVERY user, Super Admin included — the only way to
               change these is to edit the catalog item itself, or unlink. -->
          <div v-if="isCatalogLinked" class="sm:col-span-2 p-3 rounded-lg bg-slate-50 border border-slate-200 flex items-start gap-3">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-bold text-slate-500 flex items-center gap-1.5">
                ชื่อ / แบรนด์ / หมวดหมู่
                <InfoPopover label="เชื่อมกับแคตตาล็อกกลาง">
                  <p>
                    ชื่อ แบรนด์ หมวดหมู่ คำอธิบาย และคำอธิบายสเปคของสินค้านี้ถูกดึงมาจาก
                    "แคตตาล็อกกลาง" ที่ใช้ร่วมกันทุกบริษัท จึงแก้ไขจากหน้านี้ไม่ได้ —
                    ต้องแก้ไขจากรายการในแคตตาล็อกกลางเท่านั้น (Super Admin เท่านั้นที่แก้ไขได้)
                    ส่วนราคาและค่าคอมมิชชั่นยังคงเป็นของสินค้านี้แยกต่างหาก
                  </p>
                </InfoPopover>
              </p>
              <p class="mt-1 text-base font-bold text-slate-900 truncate">{{ product?.name }}</p>
              <p class="mt-0.5 text-xs text-slate-500">{{ product?.brand?.name ?? '—' }} · {{ product?.category?.name ?? '—' }}</p>
            </div>
            <div v-if="isSuperAdmin" class="shrink-0 flex flex-col items-end gap-1.5">
              <RouterLink
                v-if="product?.catalog_item_id"
                :to="{ name: 'catalog-management', query: { tab: 'items', highlight: product.catalog_item_id } }"
                class="text-xs font-bold text-brand-600 hover:underline whitespace-nowrap"
              >
                ไปที่แคตตาล็อกกลาง →
              </RouterLink>
              <button type="button" class="text-xs font-bold text-rose-600 hover:underline whitespace-nowrap" @click="openCatalogUnlinkForm">
                ยกเลิกการเชื่อม
              </button>
            </div>
          </div>
          <template v-else>
            <div class="sm:col-span-2">
              <div class="flex items-center justify-between gap-2">
                <label class="text-sm font-bold text-slate-500">ชื่อแพ็กเกจ</label>
                <!-- ADR-036 — link-out to the shared catalog, Super Admin
                     only, only meaningful once the product exists.

                     Human ruling 2026-08-19: this used to be a bare text
                     link (text-xs, brand-600, underline only on hover)
                     sitting in the label row — on a real screen it read
                     as a second field LABEL rather than an action, and
                     was missed entirely. Promoted to a filled gold
                     button with an icon: gold is the one accent in the
                     palette that is not brand-navy (which every label
                     and heading here already uses), so the control stops
                     competing with the text around it. Same
                     gold-600/gold-700 filled-button shape already used
                     by GamificationConfigView.vue's action buttons — an
                     existing pattern, not a new one. -->
                <button
                  v-if="isSuperAdmin && !isCreateMode"
                  type="button"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gold-600 text-white text-xs font-bold whitespace-nowrap transition-colors hover:bg-gold-700"
                  @click="openCatalogLinkPicker"
                >
                  <Icon name="globe" :size="14" />
                  เชื่อมกับแคตตาล็อกกลาง
                </button>
              </div>
              <input v-model="basicsForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">แบรนด์</label>
              <select v-model="basicsForm.brand_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                <option value="" disabled>เลือกแบรนด์</option>
                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
              </select>
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">หมวดหมู่</label>
              <select v-model="basicsForm.category_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                <option value="" disabled>เลือกหมวดหมู่</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
          </template>
          <fieldset class="contents" :disabled="readOnlyForCompanyAdmin">
          <div>
            <label class="text-sm font-bold text-slate-500">ราคา (บาท)</label>
            <input v-model="priceDisplay" type="text" inputmode="numeric" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <!-- ADR-011/TASK-027/034 — per-product plan-type override.
               '' (default) = inherit the company's plan type; an
               explicit value here overrides it for THIS product only.
               effective_plan_type (read-only, server-resolved) is shown
               alongside so the admin always sees which one actually
               applies, never just the raw possibly-null override. -->
          <div class="sm:col-span-2">
            <label class="text-sm font-bold text-slate-500">รูปแบบค่าคอมมิชชั่นของสินค้านี้</label>
            <select v-model="basicsForm.commission_plan_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="">สืบทอดจากบริษัท (ค่าเริ่มต้น)</option>
              <option v-for="(label, pt) in planTypeLabels" :key="pt" :value="pt">{{ label }} (กำหนดเฉพาะสินค้านี้)</option>
            </select>
            <p v-if="product" class="mt-1 text-xs text-slate-400">
              ค่าที่ใช้จริงตอนนี้: <span class="font-bold text-slate-600">{{ planTypeLabels[product.effective_plan_type] }}</span>
              <RouterLink :to="{ name: 'commission-plan-settings' }" class="ml-1 text-brand-600 hover:underline">ตั้งค่าแผนคอมมิชชั่น →</RouterLink>
            </p>
          </div>
          <!-- ADR-026 §3.3 — per-product pipeline template override.

               Deliberately the SAME shape as the commission plan-type
               override directly above (inherit sentinel + a "ค่าที่ใช้
               จริงตอนนี้" readout of the server-resolved value), because
               it is the same idea: product → category → company, most
               specific wins. Two resolution chains that behave
               identically must not look like two different features.

               The stage chips below the readout are the point of the
               control: PipelineTemplateResource sends `stages` ORDERED
               precisely so an admin is never asked to pick a customer
               journey by name without seeing the journey. -->
          <div class="sm:col-span-2">
            <label class="text-sm font-bold text-slate-500">เส้นทางการขายของสินค้านี้ (Pipeline)</label>
            <select v-model="basicsForm.pipeline_template_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="">ใช้ค่าจากหมวดสินค้า / บริษัท (ค่าเริ่มต้น)</option>
              <option v-for="t in templateOptions" :key="t.id" :value="t.id">
                {{ templateLabel(t) }} (กำหนดเฉพาะสินค้านี้)
              </option>
            </select>

            <p v-if="!templateOptions.length" class="mt-1 text-xs text-amber-600">
              ยังไม่มีเส้นทางการขายให้เลือก — ตรวจสอบว่าบริษัทนี้มี pipeline template แล้วหรือยัง
            </p>

            <!-- Resolved value. `null` is MISCONFIGURED, not "none" —
                 ProductResource resolves through category → company →
                 medical_package_default and only returns null when that
                 whole chain fails closed. Saying "ไม่มี" here would read
                 as a valid, chosen state. -->
            <p v-if="product" class="mt-1 text-xs text-slate-400">
              ค่าที่ใช้จริงตอนนี้:
              <span v-if="product.effective_pipeline_template" class="font-bold text-slate-600">
                {{ templateLabel(product.effective_pipeline_template) }}
              </span>
              <span v-else class="font-bold text-rose-600">ตั้งค่าไม่ถูกต้อง — บริษัทนี้ยังไม่มีเส้นทางการขายที่ใช้ได้</span>
            </p>

            <div v-if="previewTemplate" class="mt-2 p-3 rounded-lg bg-slate-50 border border-slate-200">
              <p class="text-xs font-bold text-slate-500 mb-2">
                ขั้นตอนของเส้นทางนี้ ({{ previewTemplate.stages.length }} ขั้น)
              </p>
              <div class="flex flex-wrap items-center gap-1.5">
                <template v-for="(stage, idx) in previewTemplate.stages" :key="stage.key">
                  <span v-if="idx > 0" class="text-slate-300 text-xs">→</span>
                  <span
                    class="px-2 py-1 rounded-lg text-xs font-bold"
                    :class="stage.key === PAYMENT_STAGE_KEY ? 'bg-emerald-50 text-emerald-700' : 'bg-white border border-slate-200 text-slate-600'"
                  >
                    {{ stageLabelTh(stage) }}
                  </span>
                </template>
              </div>
              <!-- BR-4 is untouched by ADR-026: commission fires at
                   Complete Payment and nowhere else, on every template. -->
              <p class="mt-2 text-[11px] text-slate-400">คอมมิชชั่น (BR-4) เกิดขึ้นที่ขั้น “ชำระเงินสำเร็จ” เท่านั้น</p>
            </div>
          </div>

          <!-- Recommendation pin (TASK-068 / ADR-020 row 4) — folded into
               this tab as a compact subsection per TASK-195 §2 Tab 1
               (it's a 2-field toggle, doesn't need its own tab). Needs an
               existing product (its own load/save reads product.value),
               so gated the same way the rest of the page always was
               pre-save. 2026-08-17: merged into the single "บันทึก" button
               below (saveBasicsAndPin()) per human request — no longer has
               its own submit button, pinError still surfaces here. Moved
               inside the form, above the submit button, per human request
               so it visually reads as part of the one save action. -->
          <div v-if="!isCreateMode" class="sm:col-span-2 mt-2 pt-4 border-t border-slate-100">
            <p class="text-sm font-bold text-slate-500 mb-3 flex items-center gap-1.5">
              <Icon name="star" :size="14" /> การแนะนำสินค้า
            </p>
            <div class="flex items-center gap-3">
              <span class="text-xs font-bold whitespace-nowrap" :class="pinForm.is_pinned ? 'text-brand-600' : 'text-slate-400'">
                ปักหมุดแนะนำ
              </span>
              <button
                type="button"
                @click="pinForm.is_pinned = !pinForm.is_pinned"
                class="relative w-14 h-7 shrink-0 rounded-full border transition-colors flex items-center px-1"
                :class="pinForm.is_pinned ? 'bg-brand-600 border-brand-600' : 'bg-slate-100 border-slate-200'"
              >
                <div
                  class="absolute top-1 bottom-1 w-5 rounded-full shadow bg-white transition-all duration-300"
                  :class="pinForm.is_pinned ? 'translate-x-7' : 'translate-x-0'"
                ></div>
              </button>
              <template v-if="pinForm.is_pinned">
                <label class="text-xs font-bold text-slate-500 ml-2">ลำดับการแสดงผล</label>
                <input v-model.number="pinForm.sort_order" type="number" min="0" class="w-20 px-2 py-1.5 rounded-lg border border-slate-200 text-sm" />
              </template>
            </div>
            <p class="mt-2 text-xs text-slate-400">
              สินค้าที่ปักหมุดจะแสดงในแถว "แนะนำสำหรับคุณ" ก่อนเสมอ (เรียงตามลำดับที่กำหนด) ถ้ายังไม่ครบจำนวนช่อง ระบบจะเติมด้วยสินค้าขายดีอัตโนมัติ
            </p>
            <p v-if="pinError" class="mt-2 text-xs font-bold text-rose-600">{{ pinError }}</p>
          </div>

          <div class="sm:col-span-2 flex justify-end">
            <button type="submit" :disabled="savingBasics || savingPin || readOnlyForCompanyAdmin" class="btn-primary">
              {{ savingBasics || savingPin ? 'กำลังบันทึก...' : isCreateMode ? 'บันทึกและดำเนินการต่อ' : 'บันทึก' }}
            </button>
          </div>
          </fieldset>
        </form>
      </section>

      <!-- Create-mode note — nested sections need a product id first -->
      <div v-if="isCreateMode" class="mt-4 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-sm text-amber-700">
        บันทึกข้อมูลสินค้าก่อน จึงจะเพิ่มรูปภาพ/สเปค/คอมมิชชั่นได้
      </div>

      <template v-else>
        <!-- Tab 2 — คอมมิชชั่น (Commission). Former "Section D — Commission
             panel" PLUS the Affiliate override-mode field relocated from
             Tab 1 (TASK-194's field — it's fundamentally a commission-
             payout setting, not a basic product attribute; TASK-195 §2
             Tab 2). Same isEffectivelyAffiliate computed, unchanged. -->
        <section v-if="activeTab === 'commission'" class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5">
          <!-- ADR-036 (TASK-215) — price/commission stay per-company even
               on a catalog-linked product, but a Company Admin loses edit
               rights on them too once the product is linked (backend's
               ProductPolicy::update() 403s for Company Admin on any linked
               product). One fieldset around the whole tab (settings block +
               rate form + the rules list's own edit/delete buttons) —
               `display: contents` so the existing grid/flex layout is
               untouched, native `disabled` cascade covers every input/
               select/textarea/button inside regardless of which block it's
               in. Super Admin is unaffected (readOnlyForCompanyAdmin is
               always false for them). -->
          <fieldset class="contents" :disabled="readOnlyForCompanyAdmin">
          <!-- 2026-08-18 — human request: one "บันทึก" button for the whole
               คอมมิชชั่น tab instead of two stacked ones. This single outer
               <form> now wraps BOTH the "การตั้งค่าคอมมิชชั่นของสินค้านี้"
               block and the "อัตราคอมมิชชั่น" (add-a-rate) block below;
               submit runs saveCommissionTab(), which chains saveBasics()
               and then submitRule() — same chaining pattern as
               saveBasicsAndPin() on Tab 1. submitRule() only runs when a
               rate value has actually been entered (see saveCommissionTab()
               docblock), so saving the settings alone — with the rate field
               left blank — no longer forces a new commission_rules row. -->
          <form class="grid grid-cols-2 gap-3" @submit.prevent="saveCommissionTab">
          <!-- TASK-197 §3.2 — "การตั้งค่าคอมมิชชั่นของสินค้านี้": one
               small block, ABOVE the per-tier rate list, grouping every
               "set once per product" commission setting — (a) the
               rate_type FORMAT for every cert-tier rule this product
               gets (b) the Affiliate override-payout mode, relocated
               here from further down this same tab. Both save through
               the same saveBasics() call Tab 1/Tab 3 already use — no
               second save path. Distinct on purpose from "+ เพิ่มอัตรา
               คอมตาม tier" below, which is genuinely per-tier repeatable
               data, not a setting. -->
          <div class="col-span-2 mb-2 p-4 rounded-xl bg-slate-50 border border-slate-200">
            <p class="text-sm font-bold text-slate-500 mb-3 flex items-center gap-1.5">
              <Icon name="settings" :size="14" /> การตั้งค่าคอมมิชชั่นของสินค้านี้
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">รูปแบบอัตราคอมมิชชั่น</label>
                <select v-model="basicsForm.commission_rate_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option value="">ยังไม่กำหนด (ตั้งอัตโนมัติจากอัตราแรกที่เพิ่ม)</option>
                  <option v-for="(label, rt) in commissionRateTypeLabels" :key="rt" :value="rt">{{ label }}</option>
                </select>
                <p class="mt-1 text-xs text-slate-400">
                  ใช้รูปแบบเดียวกันทุก cert tier ของสินค้านี้ — เลือกครั้งเดียว หรือปล่อยว่างให้ระบบตั้งอัตโนมัติจากอัตราแรกที่เพิ่ม
                </p>
                <p v-if="product?.commission_rate_type" class="mt-1 text-xs text-slate-400">
                  ค่าที่ใช้จริงตอนนี้: <span class="font-bold text-slate-600">{{ commissionRateTypeLabels[product.commission_rate_type] }}</span>
                </p>
              </div>

              <!-- TASK-194 §3.4 — team-leader override payout mode,
                   relocated here (was further down this tab, standalone).
                   Only meaningful (and only shown) when this product's
                   effective plan type is Affiliate: the manager_id/
                   CommissionOverrideRule infra it reads is TASK-025's,
                   unchanged — this selector only decides HOW the override
                   is computed once one exists. '' (default) = null on the
                   record = Additive (§3.1), never a "no override" state. -->
              <div v-if="isEffectivelyAffiliate">
                <label class="text-sm font-bold text-slate-500">รูปแบบค่าคอมหัวหน้าทีม (Affiliate)</label>
                <div class="mt-1 flex items-center gap-2">
                  <select v-model="basicsForm.affiliate_override_mode" class="flex-1 min-w-0 px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                    <option value="">ค่าเริ่มต้น (Additive)</option>
                    <option v-for="(label, mode) in affiliateOverrideModeLabels" :key="mode" :value="mode">{{ label }}</option>
                  </select>
                  <!-- TASK-194 §3.2 — this is a real money-behavior
                       difference, not two unexplained radio labels: which
                       THB amount the team leader actually receives, and
                       whether the selling agent's own payout changes,
                       depends entirely on this pick. Moved off the row
                       into InfoPopover (TASK-188 precedent, ⓘ) per human
                       request 2026-08-17, so the row stays a single
                       compact control instead of a paragraph. -->
                  <InfoPopover label="รูปแบบค่าคอมหัวหน้าทีม (Affiliate)">
                    <p>
                      <span class="font-bold">Additive:</span> หัวหน้าทีมได้ค่าคอมมิชชั่นเพิ่ม
                      "ต่างหาก" นอกเหนือจากค่าคอมของตัวแทนที่ขาย — ตัวแทนได้เท่าเดิมไม่ถูกหัก
                      แต่ต้นทุนรวมที่บริษัทจ่ายออกไปสำหรับการขายครั้งนี้จะ<span class="font-bold">เพิ่มขึ้น</span>
                    </p>
                    <p class="mt-2">
                      <span class="font-bold">Deductive:</span> ค่าคอมของหัวหน้าทีมจะถูก
                      "หัก" ออกจากค่าคอมของตัวแทนที่ขายเอง (ตัวแทนได้รับน้อยลงเท่ากับส่วนที่หัวหน้าทีมได้ไป)
                      แต่ต้นทุนรวมที่บริษัทจ่ายออกไปสำหรับการขายครั้งนี้<span class="font-bold">ไม่เปลี่ยนแปลง</span>
                    </p>
                  </InfoPopover>
                </div>
                <p v-if="product" class="mt-1 text-xs text-slate-400">
                  ค่าที่ใช้จริงตอนนี้:
                  <span class="font-bold text-slate-600">{{ affiliateOverrideModeLabels[product.effective_affiliate_override_mode] }}</span>
                </p>
              </div>
            </div>
          </div>

          <p class="text-base font-bold text-slate-500 flex items-center gap-1.5 mb-2">
            <Icon name="dollar" :size="14" /> อัตราคอมมิชชั่น
          </p>
          <p v-if="ruleError" class="mb-2 text-xs font-bold text-rose-600">{{ ruleError }}</p>

          <!-- ADR-035 (2026-08-18) — Unilevel commission is flat-rate per
               product now: no Cert Tier dimension. Higher commission for
               better results is Stairstep/Breakaway's job (agent_ranks),
               not a per-tier row here. Form is always visible (no "+"
               toggle) — human ruling 2026-08-18: nothing left to hide
               behind a button once tier selection is gone. Rate value is
               NOT required here (2026-08-18 follow-up): this block now
               shares its submit button with the settings block above, so
               leaving it blank must be a valid "just save settings" submit
               — see saveCommissionTab(). -->
          <div class="col-span-2 mb-3 p-4 rounded-xl bg-slate-50 border border-slate-200 grid grid-cols-2 gap-3">
            <div class="col-span-2">
              <label class="text-sm font-bold text-slate-500">{{ resolvedRuleRateType === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
              <input
                v-model="ruleForm.rate_value_input"
                type="number"
                min="0"
                step="0.01"
                class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
                @input="recheckCreateRuleCapDebounced"
                @blur="recheckCreateRuleCap"
              />
              <!-- TASK-197 §3.3 — read-only, no selector: this form submits
                   whatever the product's resolved rate_type already is
                   (or the 'percentage' default, when this is the very
                   first rule the product will ever get). -->
              <p class="mt-1 text-xs text-slate-400">จะบันทึกเป็น: {{ commissionRateTypeLabels[resolvedRuleRateType] }}</p>
              <p v-if="createRuleCapGuard.isOverCap.value" class="mt-1 text-xs font-bold text-rose-600">เกินเพดานคอมมิชชั่นที่กำหนด</p>
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">มีผลตั้งแต่ (คีย์วันที่เป็น พ.ศ.)</label>
              <div class="mt-1 flex flex-wrap items-start gap-2">
                <BuddhistDateInput v-model="ruleForm.effective_from" required />
                <!-- Same combo as voucher validity (TASK-189 follow-up v3):
                     dropdowns + a real clickable calendar side by side,
                     sharing one v-model. -->
                <CalendarDatePicker v-model="ruleForm.effective_from" />
              </div>
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">วันหมดอายุ (ไม่บังคับ)</label>
              <div class="mt-1 flex flex-wrap items-start gap-2">
                <BuddhistDateInput v-model="ruleForm.effective_to" />
                <CalendarDatePicker v-model="ruleForm.effective_to" />
              </div>
            </div>
            <div class="col-span-2 pt-2 border-t border-slate-200">
              <label class="text-sm font-bold text-slate-500">อัตราคอมมิชชั่นปีต่ออายุ (%) — ไม่บังคับ</label>
              <input
                v-model="ruleForm.renewal_rate_percent"
                type="number"
                min="0"
                step="0.01"
                placeholder="เว้นว่างถ้าไม่มีคอมฯ ปีต่ออายุ"
                class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
              />
              <label v-if="ruleForm.renewal_rate_percent !== ''" class="mt-2 flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer">
                <input v-model="ruleForm.renewal_recurs" type="checkbox" class="rounded border-slate-300" />
                ต่ออายุอัตโนมัติทุกปี (ไม่ติ๊ก = จ่ายคอมฯ ต่ออายุแค่ปีเดียว)
              </label>
            </div>
          </div>

          <div class="col-span-2 flex justify-end">
            <button type="submit" :disabled="savingBasics || savingRule || createRuleCapGuard.isOverCap.value" class="btn-primary">
              {{ savingBasics || savingRule ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
          </form>

          <EmptyState v-if="!productRules.length" icon="dollar" title="ยังไม่มีอัตราคอมมิชชั่นสำหรับสินค้านี้" />
          <div v-else class="space-y-2">
            <div v-for="r in productRules" :key="r.id" class="bg-white border border-slate-200 rounded-xl p-4">
              <template v-if="editingRuleId === r.id">
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="text-sm font-bold text-slate-500">{{ resolvedRuleRateType === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
                    <input
                      v-model="editRuleForm.rate_value_input"
                      type="number"
                      min="0"
                      step="0.01"
                      class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
                      @input="recheckEditRuleCapDebounced"
                      @blur="recheckEditRuleCap"
                    />
                    <!-- TASK-197 §3.3 — read-only, same as the create form
                         above: this row's own historical rate_type is
                         NEVER rewritten (§1), but re-saving it submits
                         the product's current resolved format. -->
                    <p class="mt-1 text-xs text-slate-400">จะบันทึกเป็น: {{ commissionRateTypeLabels[resolvedRuleRateType] }}</p>
                    <p v-if="editRuleCapGuard.isOverCap.value" class="mt-1 text-xs font-bold text-rose-600">เกินเพดานคอมมิชชั่นที่กำหนด</p>
                  </div>
                  <div>
                    <label class="text-sm font-bold text-slate-500">มีผลตั้งแต่</label>
                    <div class="mt-1 flex flex-wrap items-start gap-2">
                      <BuddhistDateInput v-model="editRuleForm.effective_from" />
                      <CalendarDatePicker v-model="editRuleForm.effective_from" />
                    </div>
                  </div>
                  <div>
                    <label class="text-sm font-bold text-slate-500">วันหมดอายุ (ไม่บังคับ)</label>
                    <div class="mt-1 flex flex-wrap items-start gap-2">
                      <BuddhistDateInput v-model="editRuleForm.effective_to" />
                      <CalendarDatePicker v-model="editRuleForm.effective_to" />
                    </div>
                  </div>
                  <div class="col-span-2">
                    <label class="text-sm font-bold text-slate-500">อัตราคอมมิชชั่นปีต่ออายุ (%) — ไม่บังคับ</label>
                    <input v-model="editRuleForm.renewal_rate_percent" type="number" min="0" step="0.01" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
                  </div>
                </div>
                <div class="flex justify-end gap-2 mt-2">
                  <button class="text-xs text-slate-500" @click="cancelEditRule">ยกเลิก</button>
                  <button class="text-xs font-bold text-brand-600 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="editRuleCapGuard.isOverCap.value" @click="saveEditRule(r)">บันทึก</button>
                </div>
              </template>
              <div v-else class="flex items-center justify-between gap-2">
                <div>
                  <p class="text-sm font-bold text-slate-900">อัตราคอมมิชชั่น</p>
                  <p class="text-xs text-slate-400">มีผลตั้งแต่ {{ r.effective_from }}{{ r.effective_to ? ` ถึง ${r.effective_to}` : '' }}</p>
                  <p v-if="r.renewal_rate_type" class="text-xs text-slate-400">
                    คอมฯ ปีต่ออายุ: {{ (r.renewal_rate_value! / 100).toFixed(2) }}% · {{ r.renewal_recurs ? 'ต่อทุกปี' : 'ปีเดียว' }}
                  </p>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                  <span class="text-sm font-bold text-slate-900">{{ formatRate(r) }}</span>
                  <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditRule(r)">
                    <Icon name="pencil" :size="14" />
                  </button>
                  <button class="text-rose-600 hover:text-rose-700" title="ลบ" @click="deleteRule(r.id)">
                    <Icon name="trash" :size="14" />
                  </button>
                </div>
              </div>
            </div>
          </div>
          </fieldset>
        </section>

        <!-- Tab 3 — บัตรกำนัลและจัดส่ง (Voucher & Shipping). The
             voucher_usage_quota / voucher_validity_days / requires_shipping
             fields, relocated out of the Basics form (Tab 1) — TASK-195
             §2 Tab 3. Still submitted through the SAME saveBasics() call
             as Tab 1 (not a separate/independent API call — see the
             task spec's own note against splitting one form submission
             into two): this tab has its own "บันทึก" button bound to the
             identical saveBasics() function, so there's no "how do I
             save this tab" dead end. basicsForm is one shared top-level
             ref, so this tab's fields and Tab 1's fields are always part
             of the same in-progress payload regardless of which tab is
             currently active. -->
        <section v-if="activeTab === 'voucher'" class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5">
          <p class="text-base font-bold text-slate-500 mb-3 flex items-center gap-1.5">
            <Icon name="tag" :size="14" /> บัตรกำนัลหลังชำระเงิน และการจัดส่ง
          </p>
          <form class="space-y-3" @submit.prevent="saveBasics">
          <fieldset class="contents" :disabled="readOnlyForCompanyAdmin">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-sm font-bold text-slate-500">จำนวนสิทธิ์การใช้บัตรกำนัล</label>
                <input
                  v-model="basicsForm.voucher_usage_quota"
                  type="text"
                  inputmode="numeric"
                  placeholder="ไม่จำกัด"
                  class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white"
                />
                <p class="mt-1 text-xs text-slate-400">เว้นว่าง = ไม่จำกัดจำนวนครั้ง</p>
              </div>
              <div>
                <label class="text-sm font-bold text-slate-500">อายุการใช้งานบัตรกำนัล (นับจากวันที่ลูกค้าชำระเงิน)</label>
                <div class="mt-1 flex flex-wrap gap-1.5">
                  <button
                    type="button"
                    class="px-2.5 py-1 rounded-full border border-slate-200 text-xs font-bold text-slate-600 bg-white hover:border-brand-400"
                    @click="applyVoucherValidityPreset('end_of_month')"
                  >
                    สิ้นเดือนนี้
                  </button>
                  <button
                    type="button"
                    class="px-2.5 py-1 rounded-full border border-slate-200 text-xs font-bold text-slate-600 bg-white hover:border-brand-400"
                    @click="applyVoucherValidityPreset('3_months')"
                  >
                    3 เดือน
                  </button>
                  <button
                    type="button"
                    class="px-2.5 py-1 rounded-full border border-slate-200 text-xs font-bold text-slate-600 bg-white hover:border-brand-400"
                    @click="applyVoucherValidityPreset('6_months')"
                  >
                    6 เดือน
                  </button>
                  <button
                    type="button"
                    class="px-2.5 py-1 rounded-full border border-slate-200 text-xs font-bold text-slate-600 bg-white hover:border-brand-400"
                    @click="applyVoucherValidityPreset('end_of_year')"
                  >
                    สิ้นปีนี้
                  </button>
                  <button
                    type="button"
                    class="px-2.5 py-1 rounded-full border border-dashed border-slate-300 text-xs font-bold text-slate-400 bg-white hover:border-slate-400"
                    @click="clearVoucherValidity"
                  >
                    ไม่มีวันหมดอายุ
                  </button>
                </div>
                <div class="mt-2 flex flex-wrap items-start gap-2">
                  <BuddhistDateInput v-model="voucherValidityDate" :years-forward="20" />
                  <!-- TASK-189 follow-up v3 (human): "dropdown เอาไว้ แต่เพิ่ม
                       ปฏิทิน เมื่อเลือกค่าในปฏิทิน ค่าใน dropdown จะเปลี่ยนตาม"
                       — both share voucherValidityDate; BuddhistDateInput's
                       own modelValue watcher re-syncs its 3 dropdowns
                       whenever this calendar changes it. -->
                  <CalendarDatePicker v-model="voucherValidityDate" />
                </div>
                <p v-if="basicsForm.voucher_validity_days !== ''" class="mt-1 text-xs text-slate-400">
                  = {{ basicsForm.voucher_validity_days }} วัน นับจากวันที่ลูกค้าชำระเงินจริง (วันที่เลือกด้านบนใช้แค่ช่วยคำนวณจำนวนวัน โดยอิงจากวันนี้)
                </p>
                <p v-else class="mt-1 text-xs text-slate-400">เว้นว่าง = ไม่มีวันหมดอายุ</p>
              </div>
            </div>
            <div class="flex items-center justify-between gap-3 pt-1">
              <div>
                <p class="text-sm font-bold text-slate-600">สินค้านี้ต้องจัดส่งสินค้าจริง</p>
                <p class="text-xs text-slate-400">เปิดไว้เพื่อให้หน้าชำระเงินของลูกค้าแสดงฟอร์มที่อยู่จัดส่ง</p>
              </div>
              <button
                type="button"
                @click="basicsForm.requires_shipping = !basicsForm.requires_shipping"
                class="relative w-14 h-7 shrink-0 rounded-full border transition-colors flex items-center px-1"
                :class="basicsForm.requires_shipping ? 'bg-brand-600 border-brand-600' : 'bg-slate-100 border-slate-200'"
                :title="basicsForm.requires_shipping ? 'ต้องจัดส่ง' : 'ไม่ต้องจัดส่ง'"
              >
                <div
                  class="absolute top-1 bottom-1 w-5 rounded-full shadow bg-white transition-all duration-300"
                  :class="basicsForm.requires_shipping ? 'translate-x-7' : 'translate-x-0'"
                ></div>
              </button>
            </div>
            <div class="flex justify-end">
              <button type="submit" :disabled="savingBasics || readOnlyForCompanyAdmin" class="btn-primary">
                {{ savingBasics ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
            </div>
          </fieldset>
          </form>
        </section>

        <!-- Tab 4 — รูปภาพและสื่อ (Media). "รูปสินค้า" (cover photos) +
             "รายละเอียดสินค้า" (detail gallery + description) + the video
             quality settings link — TASK-195 §2 Tab 4. -->
        <template v-if="activeTab === 'media'">
        <!-- ADR-036 (TASK-215) — cover photos + detail gallery stay
             per-company/per-product even when linked, but lose edit
             rights for a Company Admin (same reasoning as the commission
             tab's fieldset above). The description column further down is
             handled separately (isCatalogLinked, not readOnlyForCompanyAdmin
             — it's read-only for EVERY user once linked, Super Admin
             included, since its content comes from the catalog item). -->
        <fieldset class="contents" :disabled="readOnlyForCompanyAdmin">
        <!-- Section A2 — TASK-097 product photos (รูปสินค้า), Shopee-style.
             Its OWN card and its OWN data (purpose='cover'), completely
             separate from the รายละเอียดสินค้า gallery below: uploading
             here used to also populate that gallery, which is exactly
             what the human asked to be pulled apart. -->
        <section class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5">
          <div class="flex items-center justify-between mb-1">
            <p class="text-base font-bold text-slate-500 flex items-center gap-1.5">
              <Icon name="image" :size="14" /> รูปสินค้า
              <span v-if="coverMedia.length" class="text-xs font-bold text-slate-400">({{ coverMedia.length }} รูป)</span>
            </p>
            <span class="text-xs text-slate-400">แสดงบนการ์ดสินค้าใน Agent Portal และหน้าแชร์ · รูปภาพเท่านั้น</span>
          </div>
          <p class="text-xs text-slate-400 mb-3">
            รูปที่ติดป้าย <span class="font-bold text-amber-600">หลัก</span> คือรูปที่ขึ้นบนการ์ด — รูปที่เหลือแสดงเป็นแกลเลอรีในหน้าสินค้า
          </p>

          <div class="flex flex-wrap gap-3">
            <div
              v-for="m in coverMedia"
              :key="m.id"
              class="relative w-32 h-32 rounded-xl overflow-hidden border group cursor-pointer"
              :class="m.is_primary ? 'border-amber-400 ring-2 ring-amber-200' : 'border-slate-200'"
              @click="openMediaPreview(m)"
            >
              <AuthenticatedMedia :src="m.stream_url" type="image" class="w-full h-full object-cover" />

              <span v-if="m.is_primary" class="absolute top-1.5 right-1.5 bg-amber-500 text-white text-[10px] font-bold px-2 py-0.5 rounded">
                หลัก
              </span>

              <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-3">
                <button v-if="!m.is_primary" class="text-white hover:text-amber-300" title="ตั้งเป็นรูปหลัก" @click.stop="setPrimaryMedia(m)">
                  <Icon name="star" :size="18" />
                </button>
                <button class="text-white hover:text-rose-300" title="ลบ" @click.stop="deleteMedia(m.id)">
                  <Icon name="trash" :size="18" />
                </button>
              </div>
            </div>

            <!-- Upload tile, always last — the Shopee/Lazada affordance.
                 Present even when the set is empty, which is where the
                 previous version had no control at all. -->
            <button
              class="w-32 h-32 rounded-xl border-2 border-dashed border-slate-300 flex flex-col items-center justify-center gap-1 text-slate-400 hover:border-brand-400 hover:text-brand-600 disabled:opacity-50"
              :disabled="coverUploading"
              @click="pickCoverFiles"
            >
              <Icon name="upload" :size="22" />
              <span class="text-xs font-bold text-center px-1">
                <template v-if="coverUploading">
                  {{ coverQueueDone + 1 }}/{{ coverQueueTotal }} · {{ coverProgress }}%
                </template>
                <template v-else>เพิ่มรูป</template>
              </span>
            </button>

            <input ref="coverInput" type="file" accept="image/*" multiple class="hidden" @change="onCoverFilesSelected">
          </div>
        </section>

        <!-- Section B — merged with the former Section C (human-requested
             2026-07-19): media gallery (hero + 3×3 grid) on the left,
             product description on the right, one block titled
             "รายละเอียดสินค้า". (Section E — key-value specs — was briefly
             merged in here too, but corrected 2026-07-19: the human meant
             the spec-attachment section below, not this one.) Media
             loading/error/empty states are scoped to the left column
             only — the description column always renders regardless of
             whether any media exists. -->
        <section class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5">
          <div class="flex items-center justify-between mb-2">
            <p class="text-base font-bold text-slate-500 flex items-center gap-1.5">
              <Icon name="image" :size="14" /> รายละเอียดสินค้า
            </p>
          </div>
          <div class="flex gap-4">
            <!-- Left column — media gallery, fixed at 1/3 (shrink-0 so the description column can't squeeze it) -->
            <div class="w-1/3 shrink-0">
              <p v-if="mediaError" class="mb-2 text-xs font-bold text-rose-600">{{ mediaError }}</p>
              <p v-if="loadingMedia" class="text-xs text-slate-400">กำลังโหลด...</p>
              <!-- Bug fix (2026-08-04, human: "UI รายละเอียดสินค้าไม่มีที่
                   Upload รูป"). The upload button used to live INSIDE the
                   `v-else` gallery branch below, so a product with zero
                   media rendered the empty state and nothing else — the
                   one state where uploading is most needed was the one
                   state with no way to do it. The empty state now carries
                   its own CTA. -->
              <div v-else-if="!detailMedia.length"
                   class="flex flex-col items-center justify-center gap-3 py-8 px-4 rounded-xl border border-dashed border-slate-300 text-center">
                <Icon name="image" :size="28" class="text-slate-300" />
                <p class="text-sm font-bold text-slate-500">ยังไม่มีรูป/วิดีโอสินค้า</p>
                <button
                  class="px-4 py-2 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center gap-1.5"
                  @click="showMediaUploadModal = true"
                >
                  <Icon name="upload" :size="14" /> อัปโหลดรูปแรก
                </button>
              </div>
              <!-- Default flex stretch (no items-start) — the right column
                   needs a DEFINITE height (matching the hero) for its own
                   h-[80%]/h-[20%] children to resolve against. Those two
                   children are two fully independent rows (grid row, button
                   row) with their own explicit heights, rather than one
                   flex-col whose sizing could bleed into the other — this
                   is what keeps the grid's own rows packed tight instead of
                   spreading out (the earlier bug). -->
              <div v-else class="flex gap-2 mb-2">
                <!-- Hero — the primary item (or first uploaded if none set) -->
            <div class="relative w-[70%] aspect-square rounded-xl overflow-hidden border border-slate-200 group shrink-0 cursor-pointer" @click="openMediaPreview(heroMedia!)">
              <AuthenticatedMedia
                v-if="heroMedia!.source_type !== 'embed'"
                :src="heroMedia!.media_type === 'image' ? heroMedia!.stream_url : (heroMedia!.thumbnail_url ?? heroMedia!.stream_url)"
                type="image"
                class="w-full h-full object-cover"
              />
              <div v-else class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400 gap-1">
                <svg v-if="isYoutubeUrl(heroMedia!.embed_url)" viewBox="0 0 24 17" width="32" height="22">
                  <rect x="0" y="0" width="24" height="17" rx="4" fill="#FF0000" />
                  <path d="M9.5 12.2V4.8L16.5 8.5z" fill="#FFFFFF" />
                </svg>
                <template v-else>
                  <Icon name="link" :size="24" />
                  <span class="text-xs">embed</span>
                </template>
              </div>
              <span v-if="heroMedia!.media_type === 'video'" class="absolute top-2 left-2 bg-black/60 text-white rounded p-1">
                <Icon name="play" :size="14" />
              </span>
              <!-- TASK-097 — no "หลัก" badge and no set-as-primary star in
                   this gallery any more. `is_primary` only means anything
                   within the cover set now; leaving a star here would let
                   an admin flag a detail screenshot as primary and then
                   watch nothing change on the storefront card. -->
              <p v-if="processingStatusLabel(heroMedia!.processing_status)" class="absolute bottom-0 inset-x-0 bg-black/60 text-white text-[10px] px-2 py-1 truncate">
                {{ processingStatusLabel(heroMedia!.processing_status) }}
              </p>
              <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-3">
                <button v-if="heroMedia!.source_type !== 'embed'" class="text-white hover:text-brand-300" title="ดาวน์โหลด" @click.stop="downloadMediaItem(heroMedia!)">
                  <Icon name="download" :size="18" />
                </button>
                <button class="text-white hover:text-rose-300" title="ลบ" @click.stop="deleteMedia(heroMedia!.id)">
                  <Icon name="trash" :size="18" />
                </button>
              </div>
            </div>

            <!-- Right column: same total height as the hero, split into
                 two fully independent rows (grid 80% / upload button
                 20%), each own its own height explicitly. -->
            <div class="w-[30%] shrink-0 flex flex-col gap-1.5">
              <!-- Row 1 — 3×4 grid, 80% height -->
              <div class="h-[80%] border border-slate-200 rounded-lg p-1.5 overflow-y-auto">
                <div class="grid grid-cols-3 gap-1.5 content-start">
                  <div v-for="m in visibleGridMedia" :key="m.id" class="relative aspect-square rounded-md overflow-hidden border border-slate-200 group cursor-pointer" @click="openMediaPreview(m)">
                    <AuthenticatedMedia
                      v-if="m.source_type !== 'embed'"
                      :src="m.media_type === 'image' ? m.stream_url : (m.thumbnail_url ?? m.stream_url)"
                      type="image"
                      class="w-full h-full object-cover"
                    />
                    <div v-else class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400">
                      <svg v-if="isYoutubeUrl(m.embed_url)" viewBox="0 0 24 17" width="20" height="14">
                        <rect x="0" y="0" width="24" height="17" rx="4" fill="#FF0000" />
                        <path d="M9.5 12.2V4.8L16.5 8.5z" fill="#FFFFFF" />
                      </svg>
                      <Icon v-else name="link" :size="12" />
                    </div>
                    <span v-if="m.media_type === 'video'" class="absolute top-0.5 left-0.5 bg-black/60 text-white rounded p-0.5">
                      <Icon name="play" :size="8" />
                    </span>
                    <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1.5">
                      <button v-if="m.source_type !== 'embed'" class="text-white hover:text-brand-300" title="ดาวน์โหลด" @click.stop="downloadMediaItem(m)">
                        <Icon name="download" :size="11" />
                      </button>
                      <button class="text-white hover:text-rose-300" title="ลบ" @click.stop="deleteMedia(m.id)">
                        <Icon name="trash" :size="11" />
                      </button>
                    </div>
                  </div>

                  <!-- "More" tile replaces the last grid cell when there's overflow -->
                  <button
                    v-if="hasMediaOverflow"
                    class="aspect-square rounded-md border border-dashed border-slate-300 flex flex-col items-center justify-center gap-0.5 text-slate-500 hover:border-brand-400 hover:text-brand-600"
                    @click="showMoreMediaModal = true"
                  >
                    <Icon name="layout" :size="14" />
                    <span class="text-sm font-bold">+{{ overflowGridMedia.length }} เพิ่มเติม</span>
                  </button>
                </div>
              </div>

              <!-- Row 2 — upload button, 20% height -->
              <div class="h-[20%]">
                <button
                  class="w-full h-full px-2 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center gap-1.5"
                  @click="showMediaUploadModal = true"
                >
                  <Icon name="upload" :size="14" /> อัปโหลด
                </button>
              </div>
            </div>
          </div>
            </div>

            <!-- Right column — product description (formerly the standalone Section C) -->
            <div class="flex-1 min-w-0 border border-slate-200 rounded-xl p-4">
              <div class="flex items-center gap-2 mb-2">
                <p class="text-base font-bold text-slate-500 flex items-center gap-1.5">
                  <Icon name="document" :size="14" /> คำอธิบายสินค้า
                  <!-- ADR-036 (TASK-215) — resolved from the catalog item
                       once linked; NOT gated on readOnlyForCompanyAdmin
                       (unlike the fieldset around this whole tab) because
                       this must be read-only for EVERY user, Super Admin
                       included — the only way to change it is to edit the
                       catalog item itself. -->
                  <InfoPopover v-if="isCatalogLinked" label="เชื่อมกับแคตตาล็อกกลาง">
                    <p>คำอธิบายสินค้านี้ถูกดึงมาจากแคตตาล็อกกลาง จึงแก้ไขจากหน้านี้ไม่ได้ — ต้องแก้ไขจากรายการในแคตตาล็อกกลางเท่านั้น (Super Admin เท่านั้นที่แก้ไขได้)</p>
                  </InfoPopover>
                </p>
                <button
                  v-if="!editingDescription && !isCatalogLinked"
                  class="flex items-center gap-1 px-2 py-1 rounded-lg bg-brand-600 text-white hover:bg-brand-700 transition"
                  title="แก้ไข"
                  @click="startEditDescription"
                >
                  <Icon name="pencil" :size="14" />
                  <span class="text-xs font-bold">แก้ไข</span>
                </button>
              </div>
              <template v-if="editingDescription && !isCatalogLinked">
                <textarea v-model="descriptionDraft" rows="8" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" placeholder="คำอธิบายสินค้า..." />
                <div class="flex justify-end gap-2 mt-2">
                  <button class="text-xs font-bold text-slate-500" @click="cancelEditDescription">ยกเลิก</button>
                  <button class="btn-primary" :disabled="savingDescription" @click="saveDescription">
                    {{ savingDescription ? 'กำลังบันทึก...' : 'บันทึก' }}
                  </button>
                </div>
              </template>
              <p v-else-if="product?.description" class="text-sm text-slate-600 whitespace-pre-line">{{ product.description }}</p>
              <p v-else class="text-sm text-slate-400">ยังไม่มีคำอธิบายสินค้า</p>
            </div>
          </div>
        </section>
        </fieldset>
        </template>
        <!-- /Tab 4 -->

        <!-- Tab 5 — สเปคสินค้า (Specifications). Former "Section G" as-is,
             just moved under this tab — TASK-195 §2 Tab 5. spec-attachment
             gallery (image/PDF/embed + upload controls), product specs
             (key-value), and spec description — three equal columns
             (grid-cols-3, ~33% each). Each column's own loading/error/empty
             state is scoped to that column — the other columns always
             render regardless of whether attachments/specs/description
             exist. -->
        <section v-if="activeTab === 'specs'" class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5">
          <p class="text-base font-bold text-slate-500 mb-2 flex items-center gap-1.5">
            <Icon name="document" :size="14" /> ไฟล์แนบสเปค (รูป/PDF)
          </p>
          <!-- ADR-036 (TASK-215) — same fieldset lock as the other tabs
               (readOnlyForCompanyAdmin). Column 2 (spec_description) has
               its own ADDITIONAL always-on lock (isCatalogLinked) nested
               inside, same pattern as the description column on Tab 4. -->
          <fieldset class="contents" :disabled="readOnlyForCompanyAdmin">
          <div class="grid grid-cols-3 gap-4">
            <!-- Column 1 — attachment hero + grid + upload (same visual pattern as the media gallery) -->
            <div class="min-w-0">
              <p v-if="specAttachmentError" class="mb-2 text-xs font-bold text-rose-600">{{ specAttachmentError }}</p>
              <p v-if="loadingSpecAttachments" class="text-xs text-slate-400">กำลังโหลด...</p>
              <template v-else-if="!specAttachments.length">
                <EmptyState icon="document" title="ยังไม่มีไฟล์แนบสเปค" class="mb-2" />
                <button
                  class="w-full px-2 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center gap-1.5"
                  @click="showSpecAttachmentUploadModal = true"
                >
                  <Icon name="upload" :size="14" /> อัปโหลด
                </button>
              </template>
              <div v-else class="flex gap-2 mb-2">
                <!-- Hero — first attachment (no is_primary concept here, unlike product media) -->
                <div
                  class="relative w-[70%] aspect-square rounded-xl overflow-hidden border border-slate-200 group shrink-0 cursor-pointer"
                  @click="openSpecAttachment(attachmentHero!)"
                >
                  <AuthenticatedMedia v-if="attachmentHero!.media_type === 'image' && attachmentHero!.source_type === 'upload'" :src="attachmentHero!.stream_url" type="image" class="w-full h-full object-cover" />
                  <AuthenticatedMedia
                    v-else-if="attachmentHero!.media_type === 'pdf' && attachmentHero!.thumbnail_url"
                    :src="attachmentHero!.thumbnail_url"
                    type="image"
                    class="w-full h-full object-cover"
                  />
                  <PdfThumbnail v-else-if="attachmentHero!.media_type === 'pdf' && attachmentHero!.stream_url" :stream-url="attachmentHero!.stream_url" />
                  <div v-else-if="attachmentHero!.media_type === 'pdf'" class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400 gap-1">
                    <Icon name="document" :size="24" />
                    <span v-if="specAttachmentStatusLabel(attachmentHero!.processing_status)" class="text-xs text-center px-1">{{ specAttachmentStatusLabel(attachmentHero!.processing_status) }}</span>
                  </div>
                  <div v-else-if="attachmentHero!.source_type === 'embed'" class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400 gap-1">
                    <Icon name="link" :size="24" />
                    <span class="text-xs">embed</span>
                  </div>
                  <span v-if="attachmentHero!.media_type === 'pdf'" class="absolute top-2 left-2 bg-black/60 text-white rounded p-1">
                    <Icon name="document" :size="14" />
                  </span>
                  <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-3">
                    <button v-if="attachmentHero!.source_type !== 'embed'" class="text-white hover:text-brand-300" title="ดาวน์โหลด" @click.stop="downloadSpecAttachment(attachmentHero!)">
                      <Icon name="download" :size="18" />
                    </button>
                    <button class="text-white hover:text-rose-300" title="ลบ" @click.stop="deleteSpecAttachment(attachmentHero!.id)">
                      <Icon name="trash" :size="18" />
                    </button>
                  </div>
                </div>

                <!-- Right sub-column: same total height as the hero, grid (80%) + upload button (20%), same structure as the media gallery -->
                <div class="w-[30%] shrink-0 flex flex-col gap-1.5">
                  <div class="h-[80%] border border-slate-200 rounded-lg p-1.5 overflow-y-auto">
                    <div class="grid grid-cols-3 gap-1.5 content-start">
                      <div v-for="a in attachmentRest" :key="a.id" class="relative aspect-square rounded-md overflow-hidden border border-slate-200 group cursor-pointer" @click="openSpecAttachment(a)">
                        <AuthenticatedMedia v-if="a.media_type === 'image' && a.source_type === 'upload'" :src="a.stream_url" type="image" class="w-full h-full object-cover" />
                        <AuthenticatedMedia
                          v-else-if="a.media_type === 'pdf' && a.thumbnail_url"
                          :src="a.thumbnail_url"
                          type="image"
                          class="w-full h-full object-cover"
                        />
                        <PdfThumbnail v-else-if="a.media_type === 'pdf' && a.stream_url" :stream-url="a.stream_url" />
                        <div v-else-if="a.media_type === 'pdf'" class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400">
                          <Icon name="document" :size="16" />
                        </div>
                        <div v-else class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400">
                          <Icon name="link" :size="12" />
                        </div>
                        <span v-if="a.media_type === 'pdf'" class="absolute top-0.5 left-0.5 bg-black/60 text-white rounded p-0.5">
                          <Icon name="document" :size="8" />
                        </span>
                        <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1.5">
                          <button v-if="a.source_type !== 'embed'" class="text-white hover:text-brand-300" title="ดาวน์โหลด" @click.stop="downloadSpecAttachment(a)">
                            <Icon name="download" :size="11" />
                          </button>
                          <button class="text-white hover:text-rose-300" title="ลบ" @click.stop="deleteSpecAttachment(a.id)">
                            <Icon name="trash" :size="11" />
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="h-[20%]">
                    <button
                      class="w-full h-full px-2 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center gap-1.5"
                      @click="showSpecAttachmentUploadModal = true"
                    >
                      <Icon name="upload" :size="14" /> อัปโหลด
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Column 2 — spec description (formerly the standalone Section F; swapped with specs 2026-07-20 per human request) -->
            <div class="min-w-0 border border-slate-200 rounded-xl p-4">
              <div class="flex items-center gap-2 mb-2">
                <p class="text-base font-bold text-slate-500 flex items-center gap-1.5">
                  <Icon name="note" :size="14" /> คำอธิบายสเปคสินค้า
                  <!-- ADR-036 (TASK-215) — same always-on lock as the
                       description column on Tab 4: resolved from the
                       catalog item once linked, read-only for EVERY user. -->
                  <InfoPopover v-if="isCatalogLinked" label="เชื่อมกับแคตตาล็อกกลาง">
                    <p>คำอธิบายสเปคของสินค้านี้ถูกดึงมาจากแคตตาล็อกกลาง จึงแก้ไขจากหน้านี้ไม่ได้ — ต้องแก้ไขจากรายการในแคตตาล็อกกลางเท่านั้น (Super Admin เท่านั้นที่แก้ไขได้)</p>
                  </InfoPopover>
                </p>
                <button
                  v-if="!editingSpecDescription && !isCatalogLinked"
                  class="flex items-center gap-1 px-2 py-1 rounded-lg bg-brand-600 text-white hover:bg-brand-700 transition"
                  title="แก้ไข"
                  @click="startEditSpecDescription"
                >
                  <Icon name="pencil" :size="14" />
                  <span class="text-xs font-bold">แก้ไข</span>
                </button>
              </div>
              <template v-if="editingSpecDescription && !isCatalogLinked">
                <textarea v-model="specDescriptionDraft" rows="8" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" placeholder="คำอธิบายสเปคสินค้า..." />
                <div class="flex justify-end gap-2 mt-2">
                  <button class="text-xs font-bold text-slate-500" @click="cancelEditSpecDescription">ยกเลิก</button>
                  <button class="btn-primary" :disabled="savingSpecDescription" @click="saveSpecDescription">
                    {{ savingSpecDescription ? 'กำลังบันทึก...' : 'บันทึก' }}
                  </button>
                </div>
              </template>
              <p v-else-if="product?.spec_description" class="text-sm text-slate-600 whitespace-pre-line">{{ product.spec_description }}</p>
              <p v-else class="text-sm text-slate-400">ยังไม่มีคำอธิบายสเปคสินค้า</p>
            </div>

            <!-- Column 3 — product specs (formerly the standalone Section E; swapped with description 2026-07-20 per human request) -->
            <div class="min-w-0 border border-slate-200 rounded-xl p-4">
              <p class="text-base font-bold text-slate-500 mb-2 flex items-center gap-1.5">
                <Icon name="layout" :size="14" /> สเปคสินค้า
              </p>
              <p v-if="specError" class="mb-2 text-xs font-bold text-rose-600">{{ specError }}</p>
              <p v-if="loadingSpecs" class="text-xs text-slate-400">กำลังโหลด...</p>
              <template v-else>
                <EmptyState v-if="!specs.length" icon="layout" title="ยังไม่มีสเปคสินค้า" class="mb-2" />
                <div v-else class="mb-3">
                  <div v-for="group in groupedSpecs(specs)" :key="group.label" class="mb-2 last:mb-0">
                    <p v-if="group.label" class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-1">{{ group.label }}</p>
                    <div v-for="s in group.items" :key="s.id" class="py-1.5 border-t border-slate-100 first:border-t-0">
                      <template v-if="editingSpecId === s.id">
                        <!-- Stacked (grid-cols-1), not side-by-side — this column is only ~33% wide, three inputs in a row would be too cramped -->
                        <div class="grid grid-cols-1 gap-2">
                          <div>
                            <label class="text-[11px] font-bold text-slate-400 block mb-0.5">กลุ่ม (ไม่บังคับ) — สำหรับจัดหมวดสเปคที่เกี่ยวข้องกันไว้ด้วยกัน</label>
                            <GroupCombobox
                              :model-value="editSpecForm.spec_group || null"
                              :options="existingSpecGroups"
                              placeholder="เช่น ขนาด, สี, วัสดุ"
                              @update:model-value="editSpecForm.spec_group = $event ?? ''"
                            />
                          </div>
                          <div>
                            <label class="text-[11px] font-bold text-slate-400 block mb-0.5">หัวข้อสเปค</label>
                            <input v-model="editSpecForm.spec_key" placeholder="เช่น น้ำหนัก, ขนาดบรรจุ" class="w-full px-2 py-1 rounded border border-slate-200 text-xs" />
                          </div>
                          <div>
                            <label class="text-[11px] font-bold text-slate-400 block mb-0.5">ค่า</label>
                            <textarea
                              v-model="editSpecForm.spec_value"
                              rows="3"
                              placeholder="เช่น 500 กรัม, สีแดง, ผ้าฝ้าย 100%, 12 เดือน"
                              class="w-full px-2 py-1 rounded border border-slate-200 text-xs resize-y"
                            ></textarea>
                          </div>
                        </div>
                        <div class="flex justify-end gap-2 mt-1.5">
                          <button class="text-xs text-slate-500" @click="cancelEditSpec">ยกเลิก</button>
                          <button class="text-xs font-bold text-brand-600" @click="saveEditSpec(s.id)">บันทึก</button>
                        </div>
                      </template>
                      <div v-else class="flex items-center justify-between gap-2 text-sm">
                        <p class="min-w-0 truncate">
                          <span class="font-bold text-slate-700">{{ s.spec_key }}:</span>
                          <span class="text-slate-600"> {{ s.spec_value }}</span>
                        </p>
                        <div class="flex items-center gap-2 shrink-0">
                          <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditSpec(s)">
                            <Icon name="pencil" :size="14" />
                          </button>
                          <button class="text-rose-600 hover:text-rose-700" title="ลบ" @click="deleteSpec(s.id)">
                            <Icon name="trash" :size="14" />
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
              <div class="mt-3 pt-3 border-t border-slate-100">
                <p class="text-xs font-bold text-slate-500 mb-2">เพิ่มสเปคใหม่</p>
                <div class="grid grid-cols-1 gap-2">
                  <div>
                    <label class="text-[11px] font-bold text-slate-400 block mb-0.5">กลุ่ม (ไม่บังคับ) — ใช้จัดสเปคที่เกี่ยวข้องกันไว้เป็นหมวดเดียวกัน</label>
                    <GroupCombobox
                      :model-value="specForm.spec_group || null"
                      :options="existingSpecGroups"
                      placeholder="เช่น ขนาด, สี, วัสดุ"
                      @update:model-value="specForm.spec_group = $event ?? ''"
                    />
                  </div>
                  <div>
                    <label class="text-[11px] font-bold text-slate-400 block mb-0.5">หัวข้อสเปค</label>
                    <input v-model="specForm.spec_key" placeholder="เช่น น้ำหนัก, ขนาดบรรจุ, ระยะเวลารับประกัน" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs" />
                  </div>
                  <div>
                    <label class="text-[11px] font-bold text-slate-400 block mb-0.5">ค่า</label>
                    <textarea
                      v-model="specForm.spec_value"
                      rows="3"
                      placeholder="เช่น 500 กรัม, สีแดง, ผ้าฝ้าย 100%, 12 เดือน"
                      class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs resize-y"
                    ></textarea>
                  </div>
                </div>
                <div class="flex justify-end mt-2">
                  <button
                    class="btn-primary"
                    :disabled="addingSpec || !specForm.spec_key || !specForm.spec_value"
                    @click="addSpec"
                  >
                    + เพิ่มสเปค
                  </button>
                </div>
              </div>
            </div>
          </div>
          </fieldset>
        </section>

        <!-- Tab 6 — สื่อการขาย (Sales Materials). Former "Section H" as-is,
             unchanged internally — TASK-195 §2 Tab 6. Sales materials are
             never part of ADR-036's catalog resolution (fully per-company),
             so a single fieldset for the whole tab (readOnlyForCompanyAdmin)
             is all this one needs — no always-on isCatalogLinked lock. -->
        <section v-if="activeTab === 'materials'" class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5">
          <fieldset class="contents" :disabled="readOnlyForCompanyAdmin">
          <p class="text-base font-bold text-slate-500 mb-2 flex items-center gap-1.5">
            <Icon name="document" :size="14" /> สื่อการขาย
          </p>
          <p v-if="materialError" class="mb-2 text-xs font-bold text-rose-600">{{ materialError }}</p>
          <p v-if="loadingMaterials" class="text-xs text-slate-400">กำลังโหลด...</p>
          <template v-else>
            <EmptyState v-if="!materials.length" icon="document" title="ยังไม่มีสื่อการขาย" class="mb-3" />
            <div v-else class="space-y-3 mb-3">
              <div v-for="group in groupedMaterials(materials)" :key="group.label" class="border border-slate-200 rounded-xl p-3">
                <div class="flex items-center justify-between mb-2">
                  <p class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ group.label || 'ไม่มีกลุ่ม' }}</p>
                  <span class="text-[11px] text-slate-400">{{ group.items.length }} ไฟล์</span>
                </div>

                <div class="grid grid-cols-3 gap-1.5 mb-2">
                  <div
                    v-for="m in materialGridVisible(group.items)"
                    :key="m.id"
                    class="relative aspect-square rounded-md overflow-hidden border border-slate-200 group cursor-pointer"
                    @click="openMaterialPreview(m)"
                  >
                    <AuthenticatedMedia
                      v-if="m.source_type !== 'embed' && m.mime_type?.startsWith('image/')"
                      :src="m.stream_url"
                      type="image"
                      class="w-full h-full object-cover"
                    />
                    <PdfThumbnail v-else-if="m.source_type !== 'embed' && m.mime_type === 'application/pdf'" :stream-url="m.stream_url" />
                    <AuthenticatedMedia
                      v-else-if="m.source_type !== 'embed' && m.mime_type?.startsWith('video/')"
                      :src="m.stream_url"
                      type="video"
                      :controls="false"
                      class="w-full h-full object-cover"
                    />
                    <div v-else class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400 gap-1">
                      <svg v-if="isYoutubeUrl(m.embed_url)" viewBox="0 0 24 17" width="20" height="14">
                        <rect x="0" y="0" width="24" height="17" rx="4" fill="#FF0000" />
                        <path d="M9.5 12.2V4.8L16.5 8.5z" fill="#FFFFFF" />
                      </svg>
                      <Icon v-else name="link" :size="14" />
                    </div>

                    <span v-if="m.mime_type?.startsWith('video/')" class="absolute top-1 left-1 bg-black/60 text-white rounded p-0.5">
                      <Icon name="play" :size="9" />
                    </span>
                    <span v-if="processingStatusLabel(m.processing_status)" class="absolute bottom-0 inset-x-0 bg-black/60 text-white text-[9px] px-1 py-0.5 truncate">
                      {{ processingStatusLabel(m.processing_status) }}
                    </span>

                    <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1.5">
                      <button v-if="m.source_type !== 'embed'" class="text-white hover:text-brand-300" title="ดาวน์โหลด" @click.stop="downloadMaterial(m)">
                        <Icon name="download" :size="13" />
                      </button>
                      <button class="text-white hover:text-amber-300" title="ย้ายกลุ่ม" @click.stop="editingMaterialGroupId = editingMaterialGroupId === m.id ? null : m.id">
                        <Icon name="layout" :size="13" />
                      </button>
                      <button class="text-white hover:text-brand-300" title="ลิงก์แชร์ภายนอก" @click.stop="toggleShareLinks(m.id)">
                        <Icon name="share" :size="13" />
                      </button>
                      <button class="text-white hover:text-rose-300" title="ลบ" @click.stop="deleteMaterial(m.id)">
                        <Icon name="trash" :size="13" />
                      </button>
                    </div>

                    <div v-if="editingMaterialGroupId === m.id" class="absolute inset-x-0 top-full mt-1 z-10 bg-white border border-slate-200 rounded-lg p-1.5 shadow-lg" @click.stop>
                      <GroupCombobox
                        :model-value="m.material_group"
                        :options="existingMaterialGroups"
                        placeholder="เช่น บทเรียนที่ 1"
                        @update:model-value="updateMaterialGroup(m, $event)"
                      />
                    </div>
                  </div>

                  <!-- "More" tile replaces the last cell on overflow — same pattern as the media gallery's grid -->
                  <button
                    v-if="materialGridHasOverflow(group.items)"
                    class="aspect-square rounded-md border border-dashed border-slate-300 flex flex-col items-center justify-center gap-0.5 text-slate-500 hover:border-brand-400 hover:text-brand-600"
                    @click="openMoreMaterials(group.label)"
                  >
                    <Icon name="layout" :size="14" />
                    <span class="text-sm font-bold">+{{ materialGridOverflowCount(group.items) }} เพิ่มเติม</span>
                  </button>
                </div>

                <!-- Human-requested 2026-07-20: upload scoped to THIS group, not one shared button -->
                <button
                  class="w-full px-2 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center gap-1.5"
                  @click="openMaterialUpload(group.label || null)"
                >
                  <Icon name="upload" :size="14" /> อัปโหลดเข้ากลุ่มนี้
                </button>
              </div>
            </div>
          </template>

          <!-- New group / ungrouped upload -->
          <div class="border border-dashed border-slate-300 rounded-xl p-3">
            <p class="text-xs font-bold text-slate-500 mb-2">เพิ่มกลุ่มใหม่ / ไม่ระบุกลุ่ม</p>
            <div class="flex items-center gap-2">
              <div class="flex-1">
                <GroupCombobox
                  :model-value="newMaterialGroupDraft"
                  :options="existingMaterialGroups"
                  placeholder="เช่น บทเรียนที่ 1 หรือเว้นว่างไว้"
                  @update:model-value="newMaterialGroupDraft = $event"
                />
              </div>
              <button
                class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center gap-1.5 shrink-0"
                @click="openMaterialUpload(newMaterialGroupDraft)"
              >
                <Icon name="upload" :size="14" /> อัปโหลด
              </button>
            </div>
          </div>
          </fieldset>
        </section>

        <!-- Section I — video settings convenience link, relocated onto
             Tab 4 (รูปภาพและสื่อ) — TASK-195 §2 Tab 4 ("relocate here
             since it's video/media related"). Still just a navigation
             link to ProductCatalogView, unchanged. -->
        <div v-if="activeTab === 'media'" class="mt-4 text-right">
          <button class="text-xs font-bold text-brand-600 hover:underline" @click="goToVideoSettings">ตั้งค่าคุณภาพวิดีโอ</button>
        </div>
      </template>
    </template>

    <!-- TASK-196 §3.3 — blocking alert when a commission-rate input crosses
         over the platform cap. Closest existing pattern for a single-button
         informational modal in this codebase (CommissionPlansView.vue's own
         showResolutionOrderModal) — ConfirmDialog.vue has no alert-only mode
         (always renders both Cancel and Confirm), so this is not built as a
         new shared component, just the app's existing inline-modal shape. -->
    <div v-if="createRuleCapGuard.modalOpen.value" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="createRuleCapGuard.closeModal">
      <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-5">
        <div class="flex items-center gap-2 mb-2">
          <Icon name="alert" :size="18" class="text-rose-600 shrink-0" />
          <p class="text-sm font-bold text-slate-900">เกินเพดานคอมมิชชั่นที่กำหนด</p>
        </div>
        <p class="text-xs text-slate-500 mb-4">{{ createRuleCapGuard.violationMessage.value }}</p>
        <div class="flex justify-end">
          <button class="btn-primary" @click="createRuleCapGuard.closeModal">เข้าใจแล้ว</button>
        </div>
      </div>
    </div>
    <div v-if="editRuleCapGuard.modalOpen.value" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="editRuleCapGuard.closeModal">
      <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-5">
        <div class="flex items-center gap-2 mb-2">
          <Icon name="alert" :size="18" class="text-rose-600 shrink-0" />
          <p class="text-sm font-bold text-slate-900">เกินเพดานคอมมิชชั่นที่กำหนด</p>
        </div>
        <p class="text-xs text-slate-500 mb-4">{{ editRuleCapGuard.violationMessage.value }}</p>
        <div class="flex justify-end">
          <button class="btn-primary" @click="editRuleCapGuard.closeModal">เข้าใจแล้ว</button>
        </div>
      </div>
    </div>

    <!-- Click-to-preview — media gallery (hero/grid/More modal all open here) -->
    <MediaPreviewModal
      v-if="mediaPreviewIndex !== null"
      :items="mediaPreviewItems"
      :index="mediaPreviewIndex"
      @update:index="mediaPreviewIndex = $event"
      @close="mediaPreviewIndex = null"
    />

    <!-- Click-to-preview — spec-attachment gallery (image/PDF/embed) -->
    <MediaPreviewModal
      v-if="specAttachmentPreviewIndex !== null"
      :items="specAttachmentPreviewItems"
      :index="specAttachmentPreviewIndex"
      @update:index="specAttachmentPreviewIndex = $event"
      @close="specAttachmentPreviewIndex = null"
    />

    <!-- Click-to-preview — sales materials -->
    <MediaPreviewModal
      v-if="materialPreviewIndex !== null"
      :items="materialPreviewItems"
      :index="materialPreviewIndex"
      @update:index="materialPreviewIndex = $event"
      @close="materialPreviewIndex = null"
    />

    <!-- Media gallery — drag-drop upload modal (real progress via XHR) -->
    <MediaUploadModal
      v-if="showMediaUploadModal"
      title="อัปโหลดรูป/วิดีโอสินค้า"
      accept=".jpg,.jpeg,.png,.webp,video/*"
      hint="รูป: JPG/PNG/WEBP ไม่เกิน 15MB · วิดีโอ: ตามขนาดที่บริษัทกำหนด"
      :upload-fn="uploadMediaFile"
      :embed-fn="addProductVideoEmbed"
      embed-placeholder="วางลิงก์วิดีโอ (YouTube/Vimeo embed URL)"
      @uploaded="loadMedia"
      @close="showMediaUploadModal = false"
    />

    <!-- Spec-attachment gallery — same drag-drop upload modal pattern as the media gallery -->
    <MediaUploadModal
      v-if="showSpecAttachmentUploadModal"
      title="อัปโหลดไฟล์แนบสเปค"
      accept=".jpg,.jpeg,.png,.webp,.pdf"
      hint="รูป: JPG/PNG/WEBP · PDF — ไม่เกินขนาดที่บริษัทกำหนด"
      :upload-fn="uploadSpecAttachmentFile"
      :embed-fn="addSpecAttachmentEmbedLink"
      embed-placeholder="วางลิงก์ไฟล์ภายนอก (รูป หรือ PDF)"
      @uploaded="loadSpecAttachments"
      @close="showSpecAttachmentUploadModal = false"
    />

    <!-- Sales materials — same drag-drop upload modal pattern; scoped to
         whichever group's "+ อัปโหลด" button was clicked (see
         openMaterialUpload / materialUploadTargetGroup) -->
    <MediaUploadModal
      v-if="showMaterialUploadModal"
      title="อัปโหลดสื่อการขาย"
      accept=".jpg,.jpeg,.png,.webp,.pdf,video/*"
      hint="รูป/PDF: ไม่เกินขนาดที่บริษัทกำหนด · วิดีโอ: ตามขนาดที่บริษัทกำหนด"
      :upload-fn="uploadMaterialFile"
      :embed-fn="addMaterialEmbedLink"
      embed-placeholder="วางลิงก์วิดีโอ (YouTube/Vimeo) หรือไฟล์ภายนอก"
      @uploaded="loadMaterials"
      @close="showMaterialUploadModal = false"
    />

    <!-- Media gallery — "เพิ่มเติม" overflow modal, same star/delete actions as the grid -->
    <div v-if="showMoreMediaModal" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="showMoreMediaModal = false">
      <div class="w-full max-w-2xl max-h-[80vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 shrink-0">
          <h3 class="text-sm font-bold text-slate-800">รูป/วิดีโอสินค้า ({{ detailMedia.length }})</h3>
          <button class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" @click="showMoreMediaModal = false">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <div class="p-5 overflow-y-auto">
          <div class="grid grid-cols-4 sm:grid-cols-6 gap-2">
            <div v-for="m in media" :key="m.id" class="relative aspect-square rounded-lg overflow-hidden border border-slate-200 group cursor-pointer" @click="openMediaPreview(m)">
              <AuthenticatedMedia
                v-if="m.source_type !== 'embed'"
                :src="m.media_type === 'image' ? m.stream_url : (m.thumbnail_url ?? m.stream_url)"
                type="image"
                class="w-full h-full object-cover"
              />
              <div v-else class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400 gap-1">
                <svg v-if="isYoutubeUrl(m.embed_url)" viewBox="0 0 24 17" width="24" height="17">
                  <rect x="0" y="0" width="24" height="17" rx="4" fill="#FF0000" />
                  <path d="M9.5 12.2V4.8L16.5 8.5z" fill="#FFFFFF" />
                </svg>
                <template v-else>
                  <Icon name="link" :size="18" />
                  <span class="text-[10px]">embed</span>
                </template>
              </div>
              <span v-if="m.media_type === 'video'" class="absolute top-1 left-1 bg-black/60 text-white rounded p-0.5">
                <Icon name="play" :size="10" />
              </span>
              <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                <button v-if="m.source_type !== 'embed'" class="text-white hover:text-brand-300" title="ดาวน์โหลด" @click.stop="downloadMediaItem(m)">
                  <Icon name="download" :size="14" />
                </button>
                <button class="text-white hover:text-rose-300" title="ลบ" @click.stop="deleteMedia(m.id)">
                  <Icon name="trash" :size="14" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Sales materials — "เพิ่มเติม" overflow modal, one group's full grid -->
    <div v-if="showMoreMaterialsModal" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="showMoreMaterialsModal = false">
      <div class="w-full max-w-2xl max-h-[80vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 shrink-0">
          <h3 class="text-sm font-bold text-slate-800">{{ moreMaterialsGroupLabel || 'ไม่มีกลุ่ม' }} ({{ moreMaterialsItems.length }})</h3>
          <button class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" @click="showMoreMaterialsModal = false">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <div class="p-5 overflow-y-auto">
          <div class="grid grid-cols-4 sm:grid-cols-6 gap-2">
            <div
              v-for="m in moreMaterialsItems"
              :key="m.id"
              class="relative aspect-square rounded-lg overflow-hidden border border-slate-200 group cursor-pointer"
              @click="openMaterialPreview(m)"
            >
              <AuthenticatedMedia
                v-if="m.source_type !== 'embed' && m.mime_type?.startsWith('image/')"
                :src="m.stream_url"
                type="image"
                class="w-full h-full object-cover"
              />
              <PdfThumbnail v-else-if="m.source_type !== 'embed' && m.mime_type === 'application/pdf'" :stream-url="m.stream_url" />
              <AuthenticatedMedia
                v-else-if="m.source_type !== 'embed' && m.mime_type?.startsWith('video/')"
                :src="m.stream_url"
                type="video"
                :controls="false"
                class="w-full h-full object-cover"
              />
              <div v-else class="flex flex-col items-center justify-center h-full bg-slate-100 text-slate-400 gap-1">
                <svg v-if="isYoutubeUrl(m.embed_url)" viewBox="0 0 24 17" width="24" height="17">
                  <rect x="0" y="0" width="24" height="17" rx="4" fill="#FF0000" />
                  <path d="M9.5 12.2V4.8L16.5 8.5z" fill="#FFFFFF" />
                </svg>
                <template v-else>
                  <Icon name="link" :size="18" />
                  <span class="text-[10px]">embed</span>
                </template>
              </div>
              <span v-if="m.mime_type?.startsWith('video/')" class="absolute top-1 left-1 bg-black/60 text-white rounded p-0.5">
                <Icon name="play" :size="10" />
              </span>
              <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                <button v-if="m.source_type !== 'embed'" class="text-white hover:text-brand-300" title="ดาวน์โหลด" @click.stop="downloadMaterial(m)">
                  <Icon name="download" :size="14" />
                </button>
                <button class="text-white hover:text-rose-300" title="ลบ" @click.stop="deleteMaterial(m.id)">
                  <Icon name="trash" :size="14" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Sales materials — external share-links modal (ADR-007 Decision 3).
         Redesigned from an inline expanding row (the old layout was a flat
         list) into a modal, since grid tiles are too small to expand
         in-place. expandedShareLinksMaterialId doubles as both "which
         material" and "is the modal open" (mirrors its pre-redesign role). -->
    <div
      v-if="expandedShareLinksMaterialId !== null"
      class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4"
      @click.self="expandedShareLinksMaterialId = null"
    >
      <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
          <h3 class="text-sm font-bold text-slate-800">ลิงก์แชร์ภายนอก</h3>
          <button class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" @click="expandedShareLinksMaterialId = null">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <div class="p-5">
          <p v-if="shareError" class="mb-2 text-xs font-bold text-rose-600">{{ shareError }}</p>
          <p v-if="loadingShareLinksFor === expandedShareLinksMaterialId" class="text-xs text-slate-400">กำลังโหลด...</p>
          <template v-else>
            <p v-if="!shareLinksByMaterial[expandedShareLinksMaterialId]?.length" class="text-xs text-slate-400 mb-3">ยังไม่มีลิงก์แชร์</p>
            <div v-else class="space-y-1.5 mb-3">
              <div v-for="link in shareLinksByMaterial[expandedShareLinksMaterialId]" :key="link.id" class="flex items-center justify-between gap-2 text-xs">
                <span :class="isLinkUsable(link) ? 'text-slate-600' : 'text-slate-300 line-through'" class="truncate">{{ link.share_url }}</span>
                <div class="flex items-center gap-1.5 shrink-0">
                  <span class="text-slate-400">{{ link.view_count }} views</span>
                  <button v-if="isLinkUsable(link)" class="text-brand-600 hover:text-brand-700" title="คัดลอกลิงก์" @click="copyShareLink(link)">
                    <Icon :name="copiedShareLinkId === link.id ? 'check' : 'copy'" :size="13" />
                  </button>
                  <button v-if="isLinkUsable(link)" class="text-rose-600 hover:text-rose-700" title="ยกเลิกลิงก์" @click="revokeShareLink(expandedShareLinksMaterialId!, link.id)">
                    <Icon name="x" :size="13" />
                  </button>
                </div>
              </div>
            </div>
          </template>
          <div class="flex items-center gap-1.5">
            <span class="text-xs text-slate-500">หมดอายุใน</span>
            <input v-model.number="shareLinkExpiryDays" type="number" min="1" max="90" class="w-16 px-1.5 py-1 rounded border border-slate-200 text-xs" />
            <span class="text-xs text-slate-500">วัน</span>
            <button
              class="px-2 py-1 rounded-lg bg-brand-600 text-white text-xs font-bold disabled:opacity-50"
              :disabled="creatingShareLinkFor === expandedShareLinksMaterialId"
              @click="createShareLink(expandedShareLinksMaterialId!)"
            >
              + สร้างลิงก์แชร์
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ADR-036 (TASK-215) — link picker: browse/search /product-catalog-items
         and pick one to link this standalone product to. Super Admin only
         (the "เชื่อมกับแคตตาล็อกกลาง..." trigger button is itself gated). -->
    <div v-if="showCatalogLinkPicker" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="closeCatalogLinkPicker">
      <div class="w-full max-w-lg max-h-[80vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 shrink-0">
          <h3 class="text-sm font-bold text-slate-800">เชื่อมกับแคตตาล็อกกลาง</h3>
          <button class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" @click="closeCatalogLinkPicker">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <div class="px-5 pt-3 shrink-0">
          <p class="text-xs text-slate-400 mb-3">
            เมื่อเชื่อมแล้ว ชื่อ/แบรนด์/หมวดหมู่/คำอธิบาย/คำอธิบายสเปคของสินค้านี้จะถูกแทนที่ด้วยข้อมูลจากรายการที่เลือก — ราคาและค่าคอมมิชชั่นยังคงเป็นของสินค้านี้แยกต่างหาก
          </p>
          <div class="relative">
            <Icon name="search" :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input v-model="catalogLinkSearch" type="text" placeholder="ค้นหาชื่อ / แบรนด์ / หมวดหมู่" class="w-full h-[38px] pl-8 pr-3 rounded-lg border border-slate-200 text-sm" />
          </div>
          <p v-if="catalogLinkError" class="mt-2 text-xs font-bold text-rose-600">{{ catalogLinkError }}</p>
        </div>
        <div class="p-5 pt-3 overflow-y-auto">
          <p v-if="loadingCatalogItems" class="text-xs text-slate-400">กำลังโหลด...</p>
          <EmptyState v-else-if="!filteredCatalogItemOptions.length" icon="globe" title="ไม่พบรายการในแคตตาล็อกกลาง" />
          <div v-else class="space-y-2">
            <button
              v-for="item in filteredCatalogItemOptions"
              :key="item.id"
              type="button"
              class="w-full text-left p-3 rounded-xl border border-slate-200 hover:border-brand-400 hover:bg-brand-50/40 transition disabled:opacity-50"
              :disabled="linkingCatalogItemId !== null"
              @click="linkToCatalogItem(item)"
            >
              <p class="text-sm font-bold text-slate-900">{{ item.name }}</p>
              <p class="text-xs text-slate-400">
                {{ item.catalog_brand.name }} · {{ item.catalog_category.name }}
                <span v-if="!item.is_active" class="text-amber-600 font-bold">· ปิดใช้งาน</span>
                <span v-if="linkingCatalogItemId === item.id" class="text-brand-600 font-bold">· กำลังเชื่อม...</span>
              </p>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ADR-036 (TASK-215) — unlink form: replaces the catalog-resolved
         identity with a fresh standalone one, using THIS company's own
         /brands and /product-categories lists (unlinkForm.brand_id/
         category_id — never catalog_brand_id/catalog_category_id). -->
    <div v-if="showCatalogUnlinkForm" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="closeCatalogUnlinkForm">
      <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-1">ยกเลิกการเชื่อมกับแคตตาล็อกกลาง</h3>
        <p class="text-xs text-slate-400 mb-4">
          กรอกชื่อ แบรนด์ และหมวดหมู่ใหม่สำหรับสินค้านี้ (เป็นข้อมูลของบริษัทนี้เอง แยกจากแคตตาล็อกกลาง) — สินค้าจะกลับไปเป็นสินค้าอิสระของบริษัทนี้ทันทีที่ยืนยัน
        </p>
        <div class="space-y-3">
          <div>
            <label class="text-xs font-bold text-slate-500">ชื่อแพ็กเกจ</label>
            <input v-model="unlinkForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs font-bold text-slate-500">แบรนด์</label>
              <select v-model="unlinkForm.brand_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="" disabled>เลือกแบรนด์</option>
                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
              </select>
            </div>
            <div>
              <label class="text-xs font-bold text-slate-500">หมวดหมู่</label>
              <select v-model="unlinkForm.category_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="" disabled>เลือกหมวดหมู่</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">คำอธิบาย (ไม่บังคับ)</label>
            <textarea v-model="unlinkForm.description" rows="3" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm resize-y" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">คำอธิบายสเปค (ไม่บังคับ)</label>
            <textarea v-model="unlinkForm.spec_description" rows="3" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm resize-y" />
          </div>
          <p v-if="unlinkError" class="text-xs font-bold text-rose-600">{{ unlinkError }}</p>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" class="btn-secondary" @click="closeCatalogUnlinkForm">ยกเลิก</button>
          <button type="button" :disabled="unlinkingCatalog" class="px-4 py-2 rounded-xl bg-rose-600 text-white font-bold hover:bg-rose-700 text-sm disabled:opacity-50" @click="confirmUnlinkCatalog">
            {{ unlinkingCatalog ? 'กำลังบันทึก...' : 'ยืนยันยกเลิกการเชื่อม' }}
          </button>
        </div>
      </div>
    </div>
  </main>
</template>
