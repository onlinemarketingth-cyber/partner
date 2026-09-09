<script setup lang="ts">
/**
 * ProductCatalogView — Admin CRUD for Brand / ProductCategory / Product /
 * CommissionRule (ERD-001 §"Product Catalog", BR-2, BR-3).
 *
 * First real (non-placeholder) Admin screen wired to the live API. Money
 * is stored/transmitted as integer satang everywhere (BR-3) — divided by
 * 100 only here, at the display layer, and multiplied back by 100 before
 * sending. Commission rate_value is basis points when rate_type is
 * "percentage" (500 = 5.00%) — same divide-at-display-only rule applies.
 *
 * Company Admin only ever sees/manages their own company's rows — the
 * backend's TenantScope + Policies enforce this regardless of what this
 * screen does, but company_id is never sent from here either way (the
 * Service injects it server-side, per BR-6).
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
// 2026-09-09 — product thumbnails on the package rows; the stream URL is
// Sanctum-protected, so a bare <img src> would render a broken image.
import AuthenticatedMedia from '@/design-system/components/AuthenticatedMedia.vue'
import Icon from '@/design-system/components/Icon.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import IconPicker from '@/design-system/components/IconPicker.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
// TASK-203 — tick-box company picker for the brand/category create forms.
import CompanyMultiSelect from '@/design-system/components/CompanyMultiSelect.vue'
import { compressImageToFit } from '@/utils/imageCompression'
import { useAuthStore } from '@/stores/auth'
// TASK-208 / ADR-038 — one company scope for the whole Admin app.
import { useActiveCompanyStore } from '@/stores/activeCompany'

// TASK-208 / ADR-038 — the app-wide company scope. Declared HERE (not beside
// the rest of the company code far below) because top-level watchers further
// up this file read it during setup(); see the note at the old location.
/**
 * TASK-245 — the Policy's own answer, carried on every row.
 *
 * `undefined` means an older payload that predates the field, and it reads as
 * NO. A permission that defaults to "allowed" when the answer is missing is
 * how a button comes back the day a resource is refactored.
 */
interface RowPermissions {
  update: boolean
  delete: boolean
  // 2026-09-09 — asked of the server per row, same reason as the two above:
  // a hidden PLATFORM row is listed for a Company Admin (so the catalogue
  // does not look as though it simply ceased to exist) but only Super Admin
  // may bring it back.
  restore?: boolean
}

const activeCompany = useActiveCompanyStore()
const selectedCatalogCompanyId = computed(() => activeCompany.companyId)
const companyOptions = computed(() => activeCompany.companies)

interface Brand {
  id: number
  // TASK-202 (human, 2026-08-19: "ต้องแสดงชัดเจนว่าแบรนด์ไหนอยู่บริษัทอะไร").
  // BrandResource has always sent this — this interface simply dropped it,
  // so the manage dialog rendered a flat cross-company list (TenantScope
  // does not narrow a Super Admin) with nothing saying which row belonged
  // to whom. Same field, same reason, as Product.company_id below.
  company_id: number | null
  name: string
  is_active: boolean
  // TASK-202 — withCount('products') on the index query only; absent on
  // show/store/update payloads, hence optional.
  products_count?: number
  // TASK-205 — brand logo. logo_url is the resolved public-disk URL
  // (BrandResource), null when no logo has been uploaded.
  logo_path?: string | null
  logo_url?: string | null
  permissions?: RowPermissions
  /** 2026-09-09 — set only on rows fetched from /catalog-trash. */
  deleted_at?: string | null
}
interface ProductCategory {
  id: number
  // TASK-256 / ADR-040 — null once the category belongs to the PLATFORM.
  // catalog:promote-products repoints a promoted product at a platform
  // brand/category, because a product every company sells cannot sit inside
  // one company's taxonomy.
  company_id: number | null
  name: string
  // TASK-068 / ADR-020 row 3 — Icon.vue name from the curated whitelist
  // (App\Support\CuratedIcons::WHITELIST), or null if unset.
  icon: string | null
  sort_order: number
  is_active: boolean
  products_count?: number
  permissions?: RowPermissions
  /** 2026-09-09 — set only on rows fetched from /catalog-trash. */
  deleted_at?: string | null
}
interface Product {
  id: number
  /*
   * TASK-256 / ADR-040 — a PLATFORM-owned product: one row every company
   * sells. `company_id` is null for these, which is why every field below
   * that used to be "the product's" now comes in two flavours.
   */
  is_shared: boolean
  /** What the company in the header actually charges (own price, else central). */
  effective_price_satang: number
  /** Whether that company has switched this product on. Never inherited. */
  is_sellable_here: boolean
  /**
   * That company's OWN price, or null when it has never set one and is
   * therefore riding the central price. Not derivable from
   * effective_price_satang === price_satang: a company may have deliberately
   * matched the centre, and that price does NOT move when the centre does.
   */
  own_price_satang: number | null
  // TASK-069 — needed client-side to scope the Banner/Pin product
  // pickers to the caller's own company when Super Admin has multiple
  // companies' products in one flat /products response (TenantScope
  // only auto-filters for non-Super-Admin actors).
  // TASK-256 / ADR-040 — NULL for a platform-owned product.
  company_id: number | null
  name: string
  price_satang: number
  is_active: boolean
  brand: Brand | null
  category: ProductCategory | null
  // TASK-197 §2.1/§3.5 — this product's locked-in commission rate
  // FORMAT (null = not configured yet, the first product-scoped rule
  // decides it server-side). Read by the commission_rules tab's
  // per-product form below.
  commission_rate_type: 'percentage' | 'fixed_satang' | null
  /**
   * TASK-245 — `set_commission_rule` is deliberately NOT derived from
   * `update`: ADR-040 keeps commission per company, so a Company Admin may
   * price their own commission on a shared product they may not otherwise
   * touch. Only a catalog-LINKED product refuses both.
   */
  permissions?: RowPermissions & { set_commission_rule: boolean }
  /** 2026-09-09 — set only on rows fetched from /catalog-trash. */
  deleted_at?: string | null
  /**
   * 2026-09-09 (human: "นำรูปสินค้าหลักมาแสดงเป็น thumbnail หน้าชื่อสินค้า").
   *
   * Already sent by ProductResource whenever `media` is eager-loaded, which
   * GET /products does — this list simply never read it. Resolved server-side
   * as the PRIMARY cover first, so it is the same image the star button on the
   * product's images tab controls; the two cannot disagree.
   *
   * A Sanctum-protected route, not a public file (Section 5 rule 6), so it is
   * rendered through AuthenticatedMedia rather than a bare <img src>.
   */
  thumbnail_url?: string | null
}
// TASK-068 / ADR-020 row 2.
// TASK-073 (2026-08-02, human-confirmed) — link_type/external_url/
// internal_path supersede "always a product" (see backend StorefrontBanner
// model docblock). `product` is only populated when link_type === 'product'.
interface StorefrontBanner {
  id: number
  company_id: number
  link_type: 'product' | 'url' | 'internal'
  external_url: string | null
  internal_path: string | null
  product: { id: number; name: string; thumbnail_url: string | null } | null
  image_url: string | null
  title: string | null
  placement: 'top' | 'middle' | 'bottom'
  sort_order: number
  is_active: boolean
}
// TASK-072 — human-confirmed via AskUserQuestion (2026-08-02): 3 fixed
// placement spots on the Agent Portal "สินค้า" page (ProductBrowseView.vue).
const BANNER_PLACEMENT_OPTIONS: Array<{ value: 'top' | 'middle' | 'bottom'; label: string }> = [
  { value: 'top', label: 'ตำแหน่งเดิม (ใต้ช่องค้นหา เหนือหมวดหมู่)' },
  { value: 'middle', label: 'ระหว่างหมวดหมู่กับแนะนำสำหรับคุณ' },
  { value: 'bottom', label: 'เหนือตารางสินค้าทั้งหมด (ล่างสุด)' },
]
function bannerPlacementLabel(placement: string): string {
  return BANNER_PLACEMENT_OPTIONS.find((o) => o.value === placement)?.label ?? placement
}
// TASK-073 — human-confirmed via AskUserQuestion (2026-08-02): a banner
// can link to a Product (original), a free-typed URL, or one of the
// Agent Portal's own in-app routes (whitelisted — must stay in sync with
// backend App\Support\StorefrontBannerInternalPaths::ALLOWED).
const BANNER_LINK_TYPE_OPTIONS: Array<{ value: 'product' | 'url' | 'internal'; label: string }> = [
  { value: 'product', label: 'หน้าสินค้า (เดิม)' },
  { value: 'url', label: 'URL ภายนอก / URL ใดๆ (ใส่เอง)' },
  { value: 'internal', label: 'หน้าภายในระบบอื่น' },
]
const BANNER_INTERNAL_PATH_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '/', label: 'หน้าแรก' },
  { value: '/clients', label: 'ลูกค้าของฉัน' },
  { value: '/products', label: 'หน้าสินค้า' },
  { value: '/orders', label: 'คำสั่งซื้อ' },
  { value: '/referrals', label: 'SWS Referral' },
  { value: '/pipeline', label: 'ไปป์ไลน์การขาย' },
  { value: '/academy', label: 'Academy (คอร์สเรียน/สอบ)' },
  { value: '/commission', label: 'ค่าคอมมิชชั่น' },
  { value: '/leaderboard', label: 'Leaderboard' },
  { value: '/affiliate-links', label: 'ลิงก์พันธมิตรของฉัน' },
  { value: '/profile', label: 'โปรไฟล์' },
  { value: '/notifications', label: 'การแจ้งเตือน' },
  { value: '/announcements', label: 'ข่าวสารทั้งหมด' },
]
function bannerLinkTargetLabel(b: StorefrontBanner): string {
  if (b.link_type === 'url') return `URL: ${b.external_url ?? '—'}`
  if (b.link_type === 'internal') return `หน้าในระบบ: ${BANNER_INTERNAL_PATH_OPTIONS.find((o) => o.value === b.internal_path)?.label ?? b.internal_path}`
  return `สินค้า: ${b.product?.name ?? '—'}`
}
interface CertTierRef {
  id: number
  key: string
  name: string
}
type Tab = 'brands' | 'categories' | 'products' | 'banners' | 'commission_rules' | 'override_rules' | 'trash'
const route = useRoute()
const VALID_TABS: Tab[] = ['brands', 'categories', 'products', 'banners', 'commission_rules', 'override_rules', 'trash']
// Deep-link support — ProductEditView.vue's back button passes
// ?tab=products so "แก้ไข" → "back" lands on the same tab it came from,
// instead of always resetting to the first tab (brands).
/**
 * TASK-102 (human, 2026-08-04): "ยุบ 3 เมนูนี้ให้เหลือแพ็กเกจอันเดียว
 * แต่การทำงานยังใช้งานได้ครบถ้วน."
 *
 * `brands` and `categories` are no longer TABS — they are reference data
 * an admin sets up once per company and then almost never touches, while
 * packages are the daily work. Giving all three equal billing on the tab
 * bar gave the once-a-year job the same real estate as the every-day one.
 *
 * They remain valid Tab VALUES because `?tab=brands` links may already
 * exist in the wild and because the drawer reuses the same
 * `activeTab`-driven sections. A deep link to either now lands on
 * `products` with the drawer open (see initialTab + openRefDrawer).
 */
function initialTab(): Tab {
  const q = route.query.tab
  if (typeof q === 'string' && (VALID_TABS as string[]).includes(q)) {
    return q === 'brands' || q === 'categories' ? 'products' : (q as Tab)
  }

  return 'products'
}
const activeTab = ref<Tab>(initialTab())
const tabs: { key: Tab; label: string; icon: string }[] = [
  { key: 'products', label: 'แพ็กเกจ', icon: 'cube' },
  // TASK-068 / ADR-020 row 2 — storefront carousel banners (Agent Portal
  // row 2), colocated here since every banner links to a product.
  { key: 'banners', label: 'แบนเนอร์', icon: 'image' },
  /*
   * 2026-09-09 (human: "ออกแบบ Ui สำหรับปุ่มกู้คืนสินค้าซึ่งจะทำใน tab ใหม่").
   *
   * Everything in this catalogue has soft-deleted since TASK-091 — "ลบ" has
   * always meant "hide" — and until now nothing on any screen could bring a
   * row back. The rows sat in the database and the only route to them was a
   * hand-written UPDATE against production, which is the kind of errand that
   * ends badly. Its own tab rather than a toggle on the package list: a bin
   * is a different question ("what did I lose") and mixing deleted rows into
   * a picker is how a deleted product ends up on sale.
   */
  { key: 'trash', label: 'ถังขยะ', icon: 'trash' },
  // TASK-213 Phase 3 — 'commission_rules' and 'override_rules' stay VALID
  // (deep links still resolve, and land on the signpost section below) but
  // are no longer offered as tabs: both now live in แผนคอมมิชชั่น.
  // TASK-104 — the video-compression config tab MOVED to
  // ThemeSettingsView ("ตั้งค่าระบบ"). It was never product-scoped; it is
  // a company-wide media setting that also governs Academy clips and
  // announcement attachments, so sitting in the product catalogue meant
  // an admin looking for it had to guess "products" for something that
  // is not about products.
]

/**
 * 2026-09-09 (human: "ตอนนี้ไม่มี Ui ลบสินค้ากลาง กับสินค้าจาก company ผู้ใช้
 * ไม่ทราบ ควรแยกกัน").
 *
 * The two kinds of package sit in one list, distinguishable only by a small
 * badge — and the actions on them are not equivalent at all: hiding a shared
 * one takes it off every storefront on the system. Being able to look at just
 * one kind is the cheapest half of telling them apart.
 */
type OwnerFilter = 'all' | 'platform' | 'company'
const ownerFilter = ref<OwnerFilter>('all')

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

const brands = ref<Brand[]>([])
const categories = ref<ProductCategory[]>([])
const products = ref<Product[]>([])
const banners = ref<StorefrontBanner[]>([])
// Real cert tier list from GET /cert-tiers (any authenticated user) —
// mirrors CommissionPlansView.vue's certTiers ref/loading pattern.
const certTiers = ref<CertTierRef[]>([])

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [b, c, p, sb, ct] = await Promise.all([
      // TASK-209 — narrowed server-side. Client-side narrowing of a page of
      // results is what silently dropped rows in TASK-202.
      api.get<{ data: Brand[] }>(activeCompany.scopedPath('/brands')),
      api.get<{ data: ProductCategory[] }>(activeCompany.scopedPath('/product-categories')),
      api.get<{ data: Product[] }>(activeCompany.scopedPath('/products')),
      api.get<{ data: StorefrontBanner[] }>(activeCompany.scopedPath('/storefront-banners')),
      api.get<{ data: CertTierRef[] }>('/cert-tiers'),
    ])
    brands.value = b.data
    categories.value = c.data
    products.value = p.data
    banners.value = sb.data
    certTiers.value = ct.data
    // 2026-09-09 — the bin is a tab on this page, and its count is on the tab
    // itself, so it cannot wait until the tab is opened.
    void loadTrash()
    // The stars on the package rows.
    void loadPins()

    // TASK-213 Phase 3 — /commission-rules and /commission-override-rules
    // used to be fetched here for two tabs that now live in แผนคอมมิชชั่น.
    // Dropped rather than left in: they were two extra requests on every
    // load of a page that no longer renders a single field from either.
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

/**
 * TASK-256 — just the packages, for after a per-company price or switch is
 * saved. Nothing else on the screen can have changed, and reloading brands,
 * categories and banners as well would blank the list for a beat over a
 * one-field write.
 */
async function loadProducts(): Promise<void> {
  const p = await api.get<{ data: Product[] }>(activeCompany.scopedPath('/products'))
  products.value = p.data
}

onMounted(loadAll)

// TASK-209 — the header scope is part of every list query above, so a change
// has to refetch; nothing on this screen can be re-derived client-side.
watch(() => activeCompany.companyId, () => loadAll())

// ── Brand ──
// BrandService::create() requires company_id in the payload when the
// actor is Super Admin (Company Admin's own company_id is inferred
// server-side) — same shape as StoreProductRequest/ProductEditView.vue
// and video-processing-settings below. Found via live UAT: Super Admin
// creating a Brand returned 422 because this form never sent it.
const showBrandForm = ref(false)
const brandForm = ref({ name: '' })
const brandFormError = ref('')
const savingBrand = ref(false)

/**
 * TASK-203 (human, 2026-08-19): the create form picks its OWN target
 * companies with tick boxes ("ติ๊ก All หรือ clear all และติ๊กเลือกบริษัทได้"),
 * independent of the list's scope picker at the top of the dialog.
 *
 * Brands are per-company rows (BR-6), so "this brand in three companies"
 * is three POSTs — fanned out below, never one request with an array,
 * because BrandService::create() is the single place that stamps
 * company_id and it stamps exactly one.
 */
const brandCompanyIds = ref<number[]>([])
const categoryCompanyIds = ref<number[]>([])

/*
 * 2026-09-09 (human: "ทำแบบเดียวกันกับทางแบรนด์ และหมวดหมู่").
 *
 * The tick boxes above answer "which companies get a row of their own". They
 * cannot express the third answer ADR-040 introduced: ONE row, owned by the
 * platform (`company_id NULL`), that every company may use. Reads have
 * understood that since TASK-253 — BrandController::index already returns
 * platform rows to every company — but nothing on any screen could create one,
 * so the only source of central taxonomy was `catalog:promote-products`.
 *
 * Deliberately a radio pair rather than an extra "กลาง" tick in the company
 * list: it is not one more company. Ticking it alongside two companies would
 * mean "shared with everyone AND owned by these two", which is not a thing.
 */
const brandCreateAsPlatform = ref(false)
const categoryCreateAsPlatform = ref(false)

/**
 * ── TASK-205 — brand logo upload (human, 2026-08-19: "ผมต้องการเฉพาะแบรนด์
 * มีการ upload รูปแบรนด์ได้" — brands only, categories keep their icon
 * picker) ──
 *
 * Same client-side pipeline as the banner uploader further down this file:
 * compress before the network, refuse what is still too big afterwards,
 * multipart POST (with _method=PUT for updates, since browsers cannot send
 * multipart on a real PUT). 2 MB mirrors StoreBrandRequest's max:2048.
 */
const BRAND_LOGO_MAX_BYTES = 2 * 1024 * 1024
const brandLogoFile = ref<File | null>(null)
const brandLogoPreview = ref<string | null>(null)
const editBrandLogoFile = ref<File | null>(null)
const editBrandLogoPreview = ref<string | null>(null)
const editBrandRemoveLogo = ref(false)
const compressingBrandLogo = ref(false)

function formatMb(bytes: number): string {
  return (bytes / 1024 / 1024).toFixed(1)
}

function resetBrandLogo(which: 'create' | 'edit'): void {
  const preview = which === 'create' ? brandLogoPreview : editBrandLogoPreview
  if (preview.value) URL.revokeObjectURL(preview.value)
  preview.value = null
  if (which === 'create') brandLogoFile.value = null
  else {
    editBrandLogoFile.value = null
    editBrandRemoveLogo.value = false
  }
}

async function onBrandLogoChange(e: Event, which: 'create' | 'edit'): Promise<void> {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  const errorRef = which === 'create' ? brandFormError : editBrandError
  errorRef.value = ''
  compressingBrandLogo.value = true
  try {
    const compressed = await compressImageToFit(file, BRAND_LOGO_MAX_BYTES)
    if (compressed.size > BRAND_LOGO_MAX_BYTES) {
      errorRef.value = `รูปโลโก้ขนาด ${formatMb(compressed.size)} MB ใหญ่เกินไปแม้บีบอัดแล้ว (สูงสุด ${formatMb(BRAND_LOGO_MAX_BYTES)} MB)`
      return
    }

    resetBrandLogo(which)
    if (which === 'create') {
      brandLogoFile.value = compressed
      brandLogoPreview.value = URL.createObjectURL(compressed)
    } else {
      editBrandLogoFile.value = compressed
      editBrandLogoPreview.value = URL.createObjectURL(compressed)
      // Picking a replacement cancels a pending "remove".
      editBrandRemoveLogo.value = false
    }
  } finally {
    compressingBrandLogo.value = false
    // Let the same file be re-picked after an error.
    input.value = ''
  }
}

/** Multipart body for create — brands always post as FormData now, file or not. */
function buildBrandCreateFormData(companyId: number | null, file: File | null, isActive = true, platform = false): FormData {
  const fd = new FormData()
  fd.append('name', brandForm.value.name)
  fd.append('is_active', isActive ? '1' : '0')
  // is_platform is sent INSTEAD of company_id, never alongside it —
  // StoreBrandRequest reads exactly one of them (ChoosesPlatformOrCompany).
  if (platform) fd.append('is_platform', '1')
  else if (companyId !== null) fd.append('company_id', String(companyId))
  if (file) fd.append('logo', file)

  return fd
}

/**
 * Fan a create out over every ticked company and report per-company
 * outcomes. A partial failure must NOT read as a total failure: if 4 of
 * 5 companies got the row, saying "บันทึกไม่สำเร็จ" would send the admin
 * back to create 5 duplicates.
 *
 * @returns '' on full success, otherwise a message naming what failed.
 */
async function createForEachCompany(
  companyIds: number[],
  post: (companyId: number | null) => Promise<unknown>,
  platform = false,
): Promise<string> {
  // A platform row is ONE row for everybody, so it is one POST with no
  // company at all — the opposite of the fan-out this function exists for.
  // Company Admin: also one call, no company_id — the Service infers it.
  const targets: (number | null)[] = platform || !isSuperAdmin.value ? [null] : companyIds
  const failed: string[] = []

  for (const companyId of targets) {
    try {
      await post(companyId)
    } catch (e) {
      const label = companyId === null ? '' : `${companyName(companyId) ?? `#${companyId}`}: `
      failed.push(`${label}${saveFailureMessage(e)}`)
    }
  }

  if (!failed.length) return ''

  const okCount = targets.length - failed.length

  return okCount > 0
    ? `บันทึกสำเร็จ ${okCount} จาก ${targets.length} บริษัท — ที่ไม่สำเร็จ: ${failed.join(' / ')}`
    : failed.join(' / ')
}

/**
 * Human-reported 2026-08-19: "บันทึกไม่สำเร็จ" with no explanation, and a
 * stale "กรุณาเลือกบริษัทก่อนบันทึก" still showing AFTER a company had
 * been picked. Two separate defects, both fixed here and mirrored in
 * submitCategory below:
 *
 *   1. Neither create handler had a try/catch, unlike saveEditBrand()
 *      right below them. Any failed POST — a 422, or the request never
 *      reaching Laravel at all (backend down / wrong port) — became an
 *      unhandled promise rejection: the form just sat there with no
 *      message, no cleared state, nothing in the UI at all.
 *   2. The "เลือกบริษัทก่อน" guard message was only ever cleared on the
 *      NEXT successful submit, so it kept contradicting the company
 *      dropdown the user had already fixed. It is now cleared the moment
 *      the company changes (watcher below).
 */
function saveFailureMessage(e: unknown): string {
  if (e instanceof ApiError) {
    // Laravel's own field message (422) is far more useful than a code —
    // same reasoning as deleteFailureMessage() below.
    return e.status === 422 && e.message ? e.message : `บันทึกไม่สำเร็จ (${e.status})`
  }

  // Not an ApiError at all => fetch itself rejected, i.e. the request
  // never reached the API (backend not running, wrong port, CORS, offline).
  return 'บันทึกไม่สำเร็จ — ติดต่อ API ไม่ได้ ตรวจสอบว่า backend กำลังทำงานอยู่'
}

async function submitBrand() {
  // A แบรนด์กลาง has no companies to tick — the guard only applies to the
  // per-company branch.
  if (isSuperAdmin.value && !brandCreateAsPlatform.value && !brandCompanyIds.value.length) {
    brandFormError.value = 'ติ๊กเลือกอย่างน้อย 1 บริษัทก่อนบันทึก'
    return
  }
  brandFormError.value = ''
  savingBrand.value = true
  try {
    // TASK-205 — multipart so the same submit can carry the logo file. A
    // fresh FormData per company: the same brand mark is uploaded once per
    // company row, which is what "this brand exists in 3 companies" means.
    const platform = isSuperAdmin.value && brandCreateAsPlatform.value
    const problem = await createForEachCompany(
      brandCompanyIds.value,
      (companyId) => api.postForm('/brands', buildBrandCreateFormData(companyId, brandLogoFile.value, true, platform)),
      platform,
    )

    // Reload either way: on a partial failure the rows that DID save must
    // appear, or the admin re-creates them.
    await loadAll()

    if (problem) {
      // Form deliberately stays open with its typed value intact so the
      // admin can correct and retry, rather than losing what they typed.
      brandFormError.value = problem
      return
    }

    brandForm.value = { name: '' }
    brandCreateAsPlatform.value = false
    resetBrandLogo('create')
    showBrandForm.value = false
  } finally {
    savingBrand.value = false
  }
}

// ── Category ──
// ProductCategoryService::create() has the identical Super-Admin
// company_id requirement as BrandService above.
const showCategoryForm = ref(false)
// icon: '' = unset (IconPicker's own convention) -> omitted from the
// create payload entirely, matching every other optional field here.
const categoryForm = ref({ name: '', icon: '', sort_order: 0 })
const categoryFormError = ref('')
const savingCategory = ref(false)
async function submitCategory() {
  if (isSuperAdmin.value && !categoryCreateAsPlatform.value && !categoryCompanyIds.value.length) {
    categoryFormError.value = 'ติ๊กเลือกอย่างน้อย 1 บริษัทก่อนบันทึก'
    return
  }
  categoryFormError.value = ''
  savingCategory.value = true
  try {
    const platform = isSuperAdmin.value && categoryCreateAsPlatform.value
    const problem = await createForEachCompany(
      categoryCompanyIds.value,
      (companyId) =>
        api.post('/product-categories', {
          name: categoryForm.value.name,
          sort_order: categoryForm.value.sort_order,
          ...(categoryForm.value.icon ? { icon: categoryForm.value.icon } : {}),
          // Exactly one of the two, same contract as the brand form above.
          ...(platform ? { is_platform: true } : companyId === null ? {} : { company_id: companyId }),
        }),
      platform,
    )

    await loadAll()

    if (problem) {
      categoryFormError.value = problem
      return
    }

    categoryForm.value = { name: '', icon: '', sort_order: 0 }
    categoryCreateAsPlatform.value = false
    showCategoryForm.value = false
  } finally {
    savingCategory.value = false
  }
}

// TASK-068 / ADR-020 row 3 — category edit (this screen previously had
// NO edit capability for categories at all, only create+list; icon
// picking needs one, per TASK-069's own spec: "wired into
// ProductCatalogView.vue's category edit form"). Same inline-expand
/**
 * Brand edit + delete — TASK-088 (2026-08-03, human: "แบรนด์ไม่มีการจัดการ
 * ลบ หรือ แก้ไข ใช้ ลบแบบ soft del").
 *
 * The backend already had both: `Route::apiResource('brands')` exposes
 * PUT and DELETE, BrandPolicy gates them, and `Brand` uses the
 * `SoftDeletes` trait so `$brand->delete()` in BrandController::destroy()
 * already writes `deleted_at` rather than removing the row. The gap was
 * purely that this screen rendered brands as a read-only list, so nothing
 * could reach those endpoints.
 *
 * Soft delete is also what the FKs require: `products.brand_id` is
 * `restrictOnDelete`, so a hard delete of a brand that has products would
 * be rejected by the database. Soft delete keeps the row (and therefore
 * every product's brand name) intact while removing it from the list,
 * which is why the confirmation copy says "ซ่อน" rather than "ลบถาวร".
 *
 * Same inline-edit shape as the categories list below, deliberately —
 * two adjacent tabs behaving differently would be its own bug.
 */
/**
 * TASK-091 (2026-08-03, human: "หากมีการเกิด fk แล้ว มี DATA ที่เป็น fk
 * แจ้งเตือนไม่ให้ลบ").
 *
 * The backend's DeletionGuard answers a blocked delete with a 422 whose
 * message names exactly what is still referencing the record ("ลบไม่ได้
 * เพราะยังมีข้อมูลอ้างอิงอยู่: สินค้า 3 รายการ ..."). Every delete handler
 * on this page used to throw that away and print "ลบไม่สำเร็จ (422)",
 * which tells the admin nothing and looks like a bug rather than a rule.
 *
 * ApiError.message already carries Laravel's first field error verbatim
 * (see api/client.ts), so passing it straight through is all that is
 * needed — and only for 422, since a 500's message is framework noise.
 */
function deleteFailureMessage(e: unknown): string {
  if (e instanceof ApiError) {
    return e.status === 422 && e.message ? e.message : `ลบไม่สำเร็จ (${e.status})`
  }

  return 'ลบไม่สำเร็จ'
}

/**
 * TASK-204 — brand edit is now NAME-level and carries the same tick-box
 * company picker as create (human, 2026-08-19: "แก้ edit ทั้งแบรนด์และ
 * หมวดหมู่ให้มีการเลือกบริษัทแบบเดียวกับการเพิ่ม").
 *
 * Saving reconciles the ticked set against the rows that exist:
 *   ticked & exists    -> PUT   (rename / activate)
 *   ticked & missing   -> POST  (add this brand to that company)
 *   unticked & exists  -> DELETE (remove it from that company; soft delete,
 *                         and DeletionGuard still refuses while products
 *                         use it — reported per company, never swallowed)
 */
/**
 * TASK-256 / ADR-040 — the real companies in a group, platform row excluded.
 *
 * Used to seed the tick-box picker and to decide what "untick everything"
 * means. A platform brand cannot be unticked away: every company's promoted
 * products point at that one row, so removing it is a catalogue-wide delete,
 * not "take it off this company".
 */
function tenantCompanyIds<T>(group: RefNameGroup<T>): number[] {
  return group.companyIds.filter((id): id is number => id !== null)
}

const editingBrandKey = ref<string | null>(null)
const editBrandForm = ref({ name: '', is_active: true })
const editBrandCompanyIds = ref<number[]>([])
const editBrandRows = ref<Brand[]>([])
const editBrandError = ref('')
const savingBrandEdit = ref(false)

function startEditBrand(group: RefNameGroup<Brand>): void {
  editingBrandKey.value = group.key
  editBrandForm.value = { name: group.name, is_active: group.rows.every((r) => r.is_active) }
  // TASK-256 — the picker is "which COMPANIES have this name", so the platform
  // row is not one of its boxes. It is still edited (rename/activate) below.
  editBrandCompanyIds.value = tenantCompanyIds(group)
  editBrandRows.value = [...group.rows]
  editBrandError.value = ''
  resetBrandLogo('edit')
}

function cancelEditBrand(): void {
  editingBrandKey.value = null
  resetBrandLogo('edit')
}

/** First logo found in the group being edited — what the form shows as "current". */
const editBrandCurrentLogoUrl = computed(() => editBrandRows.value.find((r) => r.logo_url)?.logo_url ?? null)

async function saveEditBrand(): Promise<void> {
  if (editingBrandKey.value === null) return
  const rows = editBrandRows.value
  // TASK-256 — a platform row is not one of the boxes, so an empty picker on a
  // group that HAS one still leaves a brand standing; the guard would be a lie.
  const hasPlatformRow = rows.some((r) => r.company_id === null)
  if (isSuperAdmin.value && !editBrandCompanyIds.value.length && !hasPlatformRow) {
    // Untick-everything is a delete in disguise; make the admin say so with
    // the delete button, which asks for confirmation.
    editBrandError.value = 'ต้องเหลืออย่างน้อย 1 บริษัท — ถ้าต้องการเอาออกทุกบริษัท ให้ใช้ปุ่มลบ'
    return
  }

  savingBrandEdit.value = true
  editBrandError.value = ''
  const tenantRows = rows.filter((r): r is Brand & { company_id: number } => r.company_id !== null)
  const ticked = isSuperAdmin.value ? editBrandCompanyIds.value : tenantRows.map((r) => r.company_id)
  const failures: string[] = []
  const label = (companyId: number | null) => `${ownerLabel(companyId, 'brand')}: `

  try {
    // TASK-205 — multipart, so a logo can be replaced or cleared in the same
    // save. _method=PUT because browsers cannot send multipart on a PUT.
    const editFormData = (companyId: number | null): FormData => {
      const fd = new FormData()
      if (companyId === null) fd.append('_method', 'PUT')
      else fd.append('company_id', String(companyId))
      fd.append('name', editBrandForm.value.name)
      fd.append('is_active', editBrandForm.value.is_active ? '1' : '0')
      if (editBrandLogoFile.value) fd.append('logo', editBrandLogoFile.value)
      // remove_logo only makes sense on an existing row; a brand-new row has
      // nothing to clear.
      else if (editBrandRemoveLogo.value && companyId === null) fd.append('remove_logo', '1')

      return fd
    }

    /*
     * The platform row is in the update set — a rename has to reach it or the
     * group splits into two names on the next reload — but ONLY for someone
     * allowed to write it (TASK-245). For a Company Admin it is skipped, they
     * rename their own half, and the note in the form says the central one did
     * not move. Sending it anyway is the 403 this whole payload exists to stop.
     */
    for (const row of rows.filter((r) => canUpdate(r) && (r.company_id === null || ticked.includes(r.company_id)))) {
      try {
        await api.postForm(`/brands/${row.id}`, editFormData(null))
      } catch (e) {
        failures.push(label(row.company_id) + saveFailureMessage(e))
      }
    }

    for (const companyId of ticked.filter((id) => !tenantRows.some((r) => r.company_id === id))) {
      try {
        await api.postForm('/brands', editFormData(companyId))
      } catch (e) {
        failures.push(label(companyId) + saveFailureMessage(e))
      }
    }

    for (const row of tenantRows.filter((r) => !ticked.includes(r.company_id) && canDelete(r))) {
      try {
        await api.delete(`/brands/${row.id}`)
      } catch (e) {
        failures.push(label(row.company_id) + deleteFailureMessage(e))
      }
    }

    await loadAll()

    if (failures.length) {
      editBrandError.value = failures.join(' / ')
      return
    }

    editingBrandKey.value = null
  } finally {
    savingBrandEdit.value = false
  }
}

/**
 * 2026-09-09 — what deleting a row would actually do, asked of the SERVER
 * before the dialog is drawn.
 *
 * The old confirmation recited the rules from memory ("ถ้ามีการขาย/คอมมิชชั่น
 * ผูกอยู่ ระบบจะไม่ยอมให้ลบ") — a sentence in a template with nothing keeping
 * it true, and one that could not name the other companies a shared package
 * would vanish from, because the row does not carry them. GET
 * /{resource}/{id}/deletion-impact is computed by the same class that
 * enforces the refusal, so the dialog and the outcome cannot disagree.
 */
interface DeletionImpact {
  is_shared: boolean
  /** Thai label → count. Any non-zero entry means the delete will be refused. */
  blockers: Record<string, number>
  /** Companies with this switched ON right now — a warning, never a refusal. */
  selling_companies: string[]
}
const deleteImpact = ref<DeletionImpact | null>(null)
const loadingImpact = ref(false)

async function loadDeletionImpact(path: string): Promise<void> {
  deleteImpact.value = null
  loadingImpact.value = true
  try {
    const res = await api.get<{ data: DeletionImpact }>(path)
    deleteImpact.value = res.data
  } catch {
    // A dialog that cannot fetch its impact still opens, and simply says
    // less. Blocking the delete behind a failed GET would be the wrong
    // trade: the server refuses on its own if there is anything to refuse.
    deleteImpact.value = null
  } finally {
    loadingImpact.value = false
  }
}

/** The blocker lines, or '' when nothing is in the way. */
function blockerLines(impact: DeletionImpact | null): string {
  if (!impact) return ''
  const found = Object.entries(impact.blockers).filter(([, n]) => n > 0)
  if (!found.length) return ''

  return '\n\nลบไม่ได้ตอนนี้ เพราะมีข้อมูลผูกอยู่:\n'
    + found.map(([label, n]) => `• ${label} ${n} รายการ`).join('\n')
}

// TASK-066 convention — ConfirmDialog, never native window.confirm().
// TASK-204 — the unit is the name, so delete removes it from every company
// that has it; the dialog body says how many.
const pendingDeleteBrand = ref<RefNameGroup<Brand> | null>(null)
function deleteBrand(group: RefNameGroup<Brand>): void {
  pendingDeleteBrand.value = group
}

/**
 * TASK-245 — the dialog counts the rows that will ACTUALLY be deleted.
 *
 * It used to say "จาก 2 บริษัท" and name the platform among them, then delete
 * one and 403 on the other. A confirmation that overstates what it is about to
 * do is worse than no confirmation: it is the sentence the person read before
 * agreeing.
 */
function refDeleteBody(group: RefNameGroup<{ company_id: number | null, permissions?: RowPermissions }> | null, noun: string, tail: string, kind: CatalogKind): string {
  if (!group) return ''

  const targets = group.rows.filter(canDelete)
  const kept = group.rows.length - targets.length
  const owners = targets.map((r) => ownerLabel(r.company_id, kind)).join(', ')
  const note = kept > 0 ? ` · ${PLATFORM_LABEL[kind]}จะยังอยู่ (ลบได้เฉพาะ Super Admin)` : ''

  /*
   * 2026-09-09 — a name card can hold this company's row AND the platform's,
   * and deleting the platform half is not the same act as deleting the
   * company half: every other company loses it too. Said on its own line,
   * because it is the part that is not obvious from the card.
   */
  const sharedWarning = targets.some((r) => r.company_id === null)
    ? `\n\n⚠️ รวมถึง${PLATFORM_LABEL[kind]}ด้วย — ทุกบริษัทจะไม่เห็น${noun}นี้อีก`
    : ''

  return `ซ่อน${noun} "${group.name}" จาก ${targets.length} บริษัท (${owners})?${note}${sharedWarning}\n\n${tail}`
    + `\nข้อมูลไม่ได้หายถาวร กู้คืนได้ที่แท็บ "ถังขยะ"`
}

/**
 * Type-to-confirm only when a PLATFORM row is among the targets — the same
 * rule as products. Asking for it on an ordinary company brand would train
 * people to type past the prompt, which is how the gate stops working on the
 * one delete it exists for.
 */
function refDeletePhrase(group: RefNameGroup<{ company_id: number | null, permissions?: RowPermissions }> | null): string {
  if (!group) return ''

  return group.rows.filter(canDelete).some((r) => r.company_id === null) ? group.name : ''
}

const deleteBrandBody = computed(() => refDeleteBody(pendingDeleteBrand.value, 'แบรนด์', 'สินค้าที่ใช้แบรนด์นี้อยู่จะยังทำงานได้ตามปกติ', 'brand'))
const deleteBrandPhrase = computed(() => refDeletePhrase(pendingDeleteBrand.value))
async function confirmDeleteBrand(): Promise<void> {
  const group = pendingDeleteBrand.value
  if (!group) return
  const failures: string[] = []

  // TASK-245 — only the rows this viewer may delete. A Company Admin removing
  // a name that also exists centrally removes THEIR row; the platform's stays,
  // and the message below says so rather than letting a 403 say it.
  for (const row of group.rows.filter(canDelete)) {
    try {
      await api.delete(`/brands/${row.id}`)
    } catch (e) {
      failures.push(`${ownerLabel(row.company_id, 'brand')}: ${deleteFailureMessage(e)}`)
    }
  }

  await loadAll()
  errorMessage.value = failures.join(' / ')
  pendingDeleteBrand.value = null
}

// pattern used nowhere else on this page yet, closest precedent is
// ProductEditView.vue's pencil-edit-toggle sections.
// TASK-204 — name-level edit with the same tick-box company picker as
// brands above; see saveEditBrand()'s docblock for the reconcile rules.
const editingCategoryKey = ref<string | null>(null)
const editCategoryForm = ref({ name: '', icon: '', sort_order: 0, is_active: true })
const editCategoryCompanyIds = ref<number[]>([])
const editCategoryRows = ref<ProductCategory[]>([])
const editCategoryError = ref('')
const savingCategoryEdit = ref(false)
function startEditCategory(group: RefNameGroup<ProductCategory>): void {
  // A group only exists because it has rows (see groupByName), so [0] is
  // always there — but noUncheckedIndexedAccess does not know that, and a
  // non-null assertion would be a claim rather than a guard.
  const first = group.rows[0]
  if (!first) return
  editingCategoryKey.value = group.key
  editCategoryForm.value = {
    name: group.name,
    // icon/sort_order are per row too, but they are presentation-only and in
    // practice identical across companies for the same category name — take
    // the first row's and write it to all, rather than pretending the form
    // can hold three different icons for one name.
    icon: first.icon ?? '',
    sort_order: first.sort_order,
    is_active: group.rows.every((r) => r.is_active),
  }
  editCategoryCompanyIds.value = tenantCompanyIds(group)
  editCategoryRows.value = [...group.rows]
  editCategoryError.value = ''
}
function cancelEditCategory(): void {
  editingCategoryKey.value = null
}
async function saveEditCategory(): Promise<void> {
  if (editingCategoryKey.value === null) return
  const hasPlatformRow = editCategoryRows.value.some((r) => r.company_id === null)
  if (isSuperAdmin.value && !editCategoryCompanyIds.value.length && !hasPlatformRow) {
    editCategoryError.value = 'ต้องเหลืออย่างน้อย 1 บริษัท — ถ้าต้องการเอาออกทุกบริษัท ให้ใช้ปุ่มลบ'
    return
  }

  savingCategoryEdit.value = true
  editCategoryError.value = ''
  const rows = editCategoryRows.value
  // TASK-256 — same split as saveEditBrand(): the platform row is renamed with
  // the rest, but is never one of the tick boxes.
  const tenantRows = rows.filter((r): r is ProductCategory & { company_id: number } => r.company_id !== null)
  const ticked = isSuperAdmin.value ? editCategoryCompanyIds.value : tenantRows.map((r) => r.company_id)
  const failures: string[] = []
  const label = (companyId: number | null) => `${ownerLabel(companyId, 'category')}: `
  const payload = {
    name: editCategoryForm.value.name,
    // Explicit null (not omitted) so a cleared icon actually clears
    // server-side — UpdateProductCategoryRequest's own comment: "null
    // clears the icon back to 'none chosen'".
    icon: editCategoryForm.value.icon || null,
    sort_order: editCategoryForm.value.sort_order,
    is_active: editCategoryForm.value.is_active,
  }

  try {
    // TASK-245 — see saveEditBrand(): the platform row is renamed with the
    // rest only by somebody allowed to rename it.
    for (const row of rows.filter((r) => canUpdate(r) && (r.company_id === null || ticked.includes(r.company_id)))) {
      try {
        await api.put(`/product-categories/${row.id}`, payload)
      } catch (e) {
        failures.push(label(row.company_id) + saveFailureMessage(e))
      }
    }

    for (const companyId of ticked.filter((id) => !tenantRows.some((r) => r.company_id === id))) {
      try {
        // StoreProductCategoryRequest treats icon as nullable+optional; send
        // it only when set, matching submitCategory()'s create payload.
        await api.post('/product-categories', {
          ...payload,
          ...(payload.icon ? {} : { icon: undefined }),
          company_id: companyId,
        })
      } catch (e) {
        failures.push(label(companyId) + saveFailureMessage(e))
      }
    }

    for (const row of tenantRows.filter((r) => !ticked.includes(r.company_id) && canDelete(r))) {
      try {
        await api.delete(`/product-categories/${row.id}`)
      } catch (e) {
        failures.push(label(row.company_id) + deleteFailureMessage(e))
      }
    }

    await loadAll()

    if (failures.length) {
      editCategoryError.value = failures.join(' / ')
      return
    }

    editingCategoryKey.value = null
  } finally {
    savingCategoryEdit.value = false
  }
}

/**
 * Category delete — TASK-088 follow-up (2026-08-03, human: "หมวดหมู่ ไม่มี
 * เลย แบบ soft del").
 *
 * Identical situation to brands one commit earlier: the backend was
 * already complete — `Route::apiResource('product-categories')` exposes
 * DELETE, ProductCategoryPolicy@delete gates it, and ProductCategory uses
 * the `SoftDeletes` trait, so the controller's `$productCategory->delete()`
 * has always written `deleted_at` rather than dropping the row. Only the
 * button was missing.
 *
 * Soft delete is again the only option the schema allows:
 * `products.category_id` is `restrictOnDelete`, so a hard delete of a
 * category still in use would be refused by the database.
 */
const pendingDeleteCategory = ref<RefNameGroup<ProductCategory> | null>(null)
const deleteCategoryBody = computed(() => refDeleteBody(pendingDeleteCategory.value, 'หมวดหมู่', 'สินค้าที่อยู่ในหมวดหมู่นี้จะยังทำงานได้ตามปกติ', 'category'))
const deleteCategoryPhrase = computed(() => refDeletePhrase(pendingDeleteCategory.value))
function deleteCategory(group: RefNameGroup<ProductCategory>): void {
  pendingDeleteCategory.value = group
}
async function confirmDeleteCategory(): Promise<void> {
  const group = pendingDeleteCategory.value
  if (!group) return
  const failures: string[] = []

  // TASK-245 — same as confirmDeleteBrand(): their rows, not the platform's.
  for (const row of group.rows.filter(canDelete)) {
    try {
      await api.delete(`/product-categories/${row.id}`)
    } catch (e) {
      failures.push(`${ownerLabel(row.company_id, 'category')}: ${deleteFailureMessage(e)}`)
    }
  }

  await loadAll()
  errorMessage.value = failures.join(' / ')
  pendingDeleteCategory.value = null
}

// ── Banners (TASK-068 / ADR-020 row 2) ──────────────────────────────────
// Mirrors StoreStorefrontBannerRequest's 'image' => [...'max:5120'...]
// rule (5120 KB = 5 MB) — same reasoning as AnnouncementsView.vue's own
// pre-upload size guard: compress client-side BEFORE hitting the network.
const BANNER_IMAGE_MAX_BYTES = 5 * 1024 * 1024
function formatBannerMb(bytes: number): string {
  return (bytes / 1024 / 1024).toFixed(1)
}

const showBannerForm = ref(false)
const editingBannerId = ref<number | null>(null)
const bannerForm = ref({
  link_type: 'product' as 'product' | 'url' | 'internal',
  product_id: '' as string | number,
  external_url: '',
  internal_path: '',
  title: '',
  placement: 'top' as 'top' | 'middle' | 'bottom',
  sort_order: 0,
  is_active: true,
})
const bannerFormError = ref('')
const savingBanner = ref(false)

// TASK-073 — human-confirmed via AskUserQuestion (2026-08-02): selecting
// multiple files at once creates multiple separate banner records
// automatically (one per file), sharing every other field entered once
// in this form. Editing an existing banner still replaces at most 1
// image (bulk-upload semantics only apply to creating new banners).
const bannerImageFiles = ref<File[]>([])
const bannerImagePreviewUrls = ref<string[]>([])
const existingBannerImageUrl = ref<string | null>(null)
const compressingBannerImage = ref(false)
const bannerImageSizeError = ref('')

// TASK-069 acceptance criteria — the banner's product picker must only
// ever list the caller's own company's products, never leak cross-
// company options via the dropdown. Company Admin's /products response
// is already tenant-scoped (TenantScope), but Super Admin's is NOT (see
// TenantScope::apply() — it returns unfiltered for Super Admin), so this
// filters client-side by whichever company is currently selected above.
/*
 * TASK-256 / ADR-040 — a shared product qualifies only when this company has
 * actually switched it ON. `is_sellable_here` is resolved for the company in
 * the header (see CompanyScopeFilter::contextCompanyId), which is the same
 * company this banner is being created for.
 *
 * Not `is_shared` alone: a banner pointing at a product the company does not
 * sell is a dead link on its storefront, and not `is_active` either — that is
 * the platform's switch, not theirs.
 */
const bannerCompanyProducts = computed(() => {
  if (!isSuperAdmin.value) return products.value.filter((p) => !p.is_shared || p.is_sellable_here)
  if (!selectedCatalogCompanyId.value) return []

  return products.value.filter((p) => p.is_shared
    ? p.is_sellable_here
    : p.company_id === selectedCatalogCompanyId.value)
})

function resetBannerForm(): void {
  bannerForm.value = { link_type: 'product', product_id: '', external_url: '', internal_path: '', title: '', placement: 'top', sort_order: 0, is_active: true }
  editingBannerId.value = null
  bannerFormError.value = ''
  bannerImagePreviewUrls.value.forEach((u) => URL.revokeObjectURL(u))
  bannerImageFiles.value = []
  bannerImagePreviewUrls.value = []
  existingBannerImageUrl.value = null
  bannerImageSizeError.value = ''
  compressingBannerImage.value = false
}
function openCreateBannerForm(): void {
  resetBannerForm()
  showBannerForm.value = true
}
function openEditBannerForm(banner: StorefrontBanner): void {
  resetBannerForm()
  editingBannerId.value = banner.id
  bannerForm.value = {
    link_type: banner.link_type ?? 'product',
    product_id: banner.product?.id ?? '',
    external_url: banner.external_url ?? '',
    internal_path: banner.internal_path ?? '',
    title: banner.title ?? '',
    placement: banner.placement ?? 'top',
    sort_order: banner.sort_order,
    is_active: banner.is_active,
  }
  existingBannerImageUrl.value = banner.image_url
  showBannerForm.value = true
}
function closeBannerForm(): void {
  showBannerForm.value = false
}

async function onBannerImageChange(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  bannerImageSizeError.value = ''
  if (!files.length) return
  compressingBannerImage.value = true
  try {
    const accepted: File[] = []
    for (const file of files) {
      const result = await compressImageToFit(file, BANNER_IMAGE_MAX_BYTES)
      if (result.size > BANNER_IMAGE_MAX_BYTES) {
        bannerImageSizeError.value = `รูปภาพ "${file.name}" ขนาด ${formatBannerMb(result.size)} MB ใหญ่เกินไปแม้บีบอัดแล้ว (สูงสุด ${formatBannerMb(BANNER_IMAGE_MAX_BYTES)} MB) กรุณาเลือกรูปอื่นหรือครอปให้เล็กลง`
        continue
      }
      accepted.push(result)
    }
    // Editing an existing banner replaces at most 1 image — bulk-upload
    // (many files -> many new banners) only applies when creating.
    const toAdd = editingBannerId.value ? accepted.slice(0, 1) : accepted
    if (editingBannerId.value) {
      bannerImagePreviewUrls.value.forEach((u) => URL.revokeObjectURL(u))
      bannerImageFiles.value = toAdd
      bannerImagePreviewUrls.value = toAdd.map((f) => URL.createObjectURL(f))
    } else {
      bannerImageFiles.value = [...bannerImageFiles.value, ...toAdd]
      bannerImagePreviewUrls.value = [...bannerImagePreviewUrls.value, ...toAdd.map((f) => URL.createObjectURL(f))]
    }
  } finally {
    compressingBannerImage.value = false
    input.value = ''
  }
}
function removeBannerImageAt(index: number): void {
  const url = bannerImagePreviewUrls.value[index]
  if (url) URL.revokeObjectURL(url)
  bannerImageFiles.value.splice(index, 1)
  bannerImagePreviewUrls.value.splice(index, 1)
}
function clearExistingBannerImage(): void {
  existingBannerImageUrl.value = null
}

function buildBannerFormData(file: File | null): FormData {
  const fd = new FormData()
  if (editingBannerId.value) fd.append('_method', 'PUT')
  fd.append('link_type', bannerForm.value.link_type)
  if (bannerForm.value.link_type === 'product') {
    fd.append('product_id', String(bannerForm.value.product_id))
  } else if (bannerForm.value.link_type === 'url') {
    fd.append('external_url', bannerForm.value.external_url)
  } else {
    fd.append('internal_path', bannerForm.value.internal_path)
  }
  if (bannerForm.value.title) fd.append('title', bannerForm.value.title)
  fd.append('placement', bannerForm.value.placement)
  fd.append('sort_order', String(bannerForm.value.sort_order))
  fd.append('is_active', bannerForm.value.is_active ? '1' : '0')
  if (file) fd.append('image', file)
  if (isSuperAdmin.value && selectedCatalogCompanyId.value && !editingBannerId.value) {
    fd.append('company_id', String(selectedCatalogCompanyId.value))
  }
  return fd
}

async function submitBannerForm(): Promise<void> {
  bannerFormError.value = ''
  if (bannerForm.value.link_type === 'product' && !bannerForm.value.product_id) {
    bannerFormError.value = 'กรุณาเลือกสินค้า'
    return
  }
  if (bannerForm.value.link_type === 'url' && !bannerForm.value.external_url) {
    bannerFormError.value = 'กรุณาใส่ URL ปลายทาง'
    return
  }
  if (bannerForm.value.link_type === 'internal' && !bannerForm.value.internal_path) {
    bannerFormError.value = 'กรุณาเลือกหน้าปลายทางภายในระบบ'
    return
  }
  if (!editingBannerId.value && bannerImageFiles.value.length === 0) {
    bannerFormError.value = 'กรุณาอัปโหลดรูปแบนเนอร์อย่างน้อย 1 รูป'
    return
  }
  if (bannerImageSizeError.value) {
    bannerFormError.value = bannerImageSizeError.value
    return
  }
  savingBanner.value = true
  try {
    const path = editingBannerId.value ? `/storefront-banners/${editingBannerId.value}` : '/storefront-banners'
    if (editingBannerId.value) {
      await api.postForm(path, buildBannerFormData(bannerImageFiles.value[0] ?? null))
    } else {
      // TASK-073 — human-confirmed via AskUserQuestion (2026-08-02):
      // uploading N images at once creates N separate banner records,
      // each sharing every other field entered once in this form.
      for (const file of bannerImageFiles.value) {
        await api.postForm(path, buildBannerFormData(file))
      }
    }
    closeBannerForm()
    await loadAll()
  } catch (e) {
    bannerFormError.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  } finally {
    savingBanner.value = false
  }
}

// TASK-066 convention — ConfirmDialog, never native window.confirm().
const pendingDeleteBanner = ref<StorefrontBanner | null>(null)
function deleteBanner(banner: StorefrontBanner): void {
  pendingDeleteBanner.value = banner
}
async function confirmDeleteBanner(): Promise<void> {
  const banner = pendingDeleteBanner.value
  if (!banner) return
  try {
    await api.delete(`/storefront-banners/${banner.id}`)
    banners.value = banners.value.filter((b) => b.id !== banner.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteBanner.value = null
  }
}

/**
 * TASK-091 — delete for the three tabs that had none. Products go through
 * DeletionGuard server-side (referrals / commission ledger / rules /
 * Academy modules block them); the two rule tables have no dependents of
 * their own, so those deletes always succeed.
 */
const pendingDeleteProduct = ref<Product | null>(null)
function deleteProduct(product: Product): void {
  pendingDeleteProduct.value = product
  void loadDeletionImpact(`/products/${product.id}/deletion-impact`)
}

/**
 * 2026-09-09 — two deletes, two dialogs.
 *
 * Hiding a company's package takes it off one storefront; hiding a PLATFORM
 * package takes it off every storefront on the system at once, including
 * companies nobody on this screen is looking at. The old dialog said the same
 * sentence for both, so the second one was performed blind.
 *
 * The companies are named rather than counted. "3 บริษัท" is a number to
 * agree with; "AIA · Thai Life · GENESENN Health" is a list to recognise, and
 * recognising one you did not expect is the whole point of the pause.
 */
const deleteProductBody = computed(() => {
  const product = pendingDeleteProduct.value
  if (!product) return ''

  const impact = deleteImpact.value
  const blockers = blockerLines(impact)

  if (!product.is_shared) {
    return `ซ่อนแพ็กเกจ "${product.name}" ออกจาก ${companyName(product.company_id) ?? 'บริษัทนี้'}?\n\n`
      + '• Agent ของบริษัทนี้จะไม่เห็นแพ็กเกจนี้บนหน้าร้านทันที\n'
      + '• บริษัทอื่นไม่ได้รับผลกระทบ\n'
      + '• ข้อมูลไม่ได้หายถาวร กู้คืนได้ที่แท็บ "ถังขยะ"'
      + blockers
  }

  const selling = impact?.selling_companies ?? []
  const who = selling.length
    ? `ตอนนี้มี ${selling.length} บริษัทเปิดขายสินค้านี้อยู่\n  ${selling.join(' · ')}\n\n`
      + `• ทั้ง ${selling.length} บริษัทจะไม่เห็นสินค้านี้บนหน้าร้านทันที\n`
    : 'ตอนนี้ยังไม่มีบริษัทไหนเปิดขายสินค้านี้\n\n'

  return `สินค้านี้เป็นสินค้ากลาง — ลบแล้วมีผลกับทุกบริษัท\n\n${who}`
    + '• ราคาที่แต่ละบริษัทตั้งไว้ยังถูกเก็บไว้ ถ้ากู้คืนจะกลับมาเหมือนเดิม\n'
    + '• ข้อมูลไม่ได้หายถาวร กู้คืนได้ที่แท็บ "ถังขยะ"'
    + blockers
})

/** Type-to-confirm, for the one delete that reaches every company at once. */
const deleteProductPhrase = computed(() => (pendingDeleteProduct.value?.is_shared ? pendingDeleteProduct.value.name : ''))
async function confirmDeleteProduct(): Promise<void> {
  const product = pendingDeleteProduct.value
  if (!product) return
  try {
    await api.delete(`/products/${product.id}`)
    products.value = products.value.filter((p) => p.id !== product.id)
    // It is in the bin now, and the bin is a sibling tab on this page.
    await loadTrash()
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteProduct.value = null
  }
}


function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 0 }) + ' บาท'
}

/*
 * ── TASK-256 / ADR-040 — the per-company price and on/off switch ──
 *
 * A shared product is ONE row that every company sells, so this list can no
 * longer print "the price" and "ใช้งาน/ปิดใช้งาน" and mean anything: those
 * belong to a company now, and the company is the one in the header switcher.
 *
 *   is_active            the PLATFORM's — "does this product exist at all"
 *   is_sellable_here     this company's switch, default off, never inherited
 *   price_satang         the central price a Super Admin types
 *   effective_price_satang  what this company actually charges
 *   own_price_satang     null when that number is inherited, not chosen
 *
 * The controls only appear with a company selected. In "ทุกบริษัท" there is no
 * company to answer for — the API says so by reporting the central price and
 * false — so the row shows the central price alone rather than a state that
 * belongs to nobody.
 */
const scopedCompanyLabel = computed(() => {
  const id = selectedCatalogCompanyId.value

  return id === null ? null : (companyName(id) ?? `บริษัท #${id}`)
})

const companySettingProduct = ref<Product | null>(null)
const companySettingInherit = ref(true)
const companySettingPriceBaht = ref('')
const companySettingError = ref('')
const savingCompanySetting = ref(false)
/** Product ids whose on/off switch is mid-flight, so the row can disable it. */
const togglingSellHere = ref<number[]>([])

function openCompanySetting(product: Product): void {
  companySettingProduct.value = product
  companySettingInherit.value = product.own_price_satang === null
  // BR-3 — satang is divided by 100 here, at the display edge, and nowhere
  // else. An inherited row shows the central price as the starting point so
  // the admin edits from a real number rather than an empty box.
  companySettingPriceBaht.value = String((product.own_price_satang ?? product.price_satang) / 100)
  companySettingError.value = ''
}

function closeCompanySetting(): void {
  companySettingProduct.value = null
  companySettingError.value = ''
}

/**
 * PUT /products/{id}/company-settings — Super Admin only, and the server says
 * so too (UpdateCompanyProductSettingRequest::authorize()). The button is
 * hidden for anyone else; the endpoint is what actually refuses.
 *
 * `price_satang: null` is a real instruction ("stop overriding, go back to the
 * central price"), which is why the tick box sends null rather than omitting
 * the key — omitting it means "leave the price alone", a different request.
 */
async function saveCompanySetting(): Promise<void> {
  const product = companySettingProduct.value
  const companyId = selectedCatalogCompanyId.value
  if (!product || companyId === null) return

  let priceSatang: number | null = null
  if (!companySettingInherit.value) {
    const baht = Number(companySettingPriceBaht.value)
    if (!Number.isFinite(baht) || baht < 0) {
      companySettingError.value = 'ราคาไม่ถูกต้อง'
      return
    }
    // Math.round, not truncation: 199.99 baht is 19999 satang, and a
    // float that lands on 19998.999999 must not quietly become 19998.
    priceSatang = Math.round(baht * 100)
  }

  savingCompanySetting.value = true
  companySettingError.value = ''
  try {
    await api.put(`/products/${product.id}/company-settings`, {
      company_id: companyId,
      price_satang: priceSatang,
    })
    await loadProducts()
    companySettingProduct.value = null
  } catch (e) {
    companySettingError.value = saveFailureMessage(e)
  } finally {
    savingCompanySetting.value = false
  }
}

async function toggleSellHere(product: Product): Promise<void> {
  const companyId = selectedCatalogCompanyId.value
  if (companyId === null || togglingSellHere.value.includes(product.id)) return

  togglingSellHere.value = [...togglingSellHere.value, product.id]
  errorMessage.value = ''
  try {
    // Only is_active — the price key is deliberately absent, because opening
    // a product for sale must not also decide what it costs.
    await api.put(`/products/${product.id}/company-settings`, {
      company_id: companyId,
      is_active: !product.is_sellable_here,
    })
    /*
     * 2026-09-09 — the pins are refetched too, because closing a sale switches
     * that company's pin off server-side (CompanyProductSettingService). The
     * star must go dark in the same paint as the switch, or the row would
     * contradict itself until the next reload.
     */
    await Promise.all([loadProducts(), loadPins()])
  } catch (e) {
    errorMessage.value = saveFailureMessage(e)
  } finally {
    togglingSellHere.value = togglingSellHere.value.filter((id) => id !== product.id)
  }
}

// ── Video processing settings (ADR-007) — per-company override of
// config/media.php's platform defaults (max upload size, target
// resolution/bitrate for the async compression job). Company Admin
// manages their own company automatically; Super Admin must pick a
// company first (mirrors CompanyManagementView's /companies list). ──
const authStore = useAuthStore()
const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin')
// TASK-208 — this page used to own THREE company controls: the scope bar in
// the brand/category dialog, the "บริษัท: ทั้งหมด" filter on the package
// toolbar, and the banners tab's own picker. All three are gone; the header
// switcher is the only one, and the aliases below keep the rest of this file
// (including TASK-203's multi-company create forms) reading unchanged.
// TASK-209 bugfix (browser QA, 2026-08-19): these three MUST stay at the top
// of the script. They used to live ~1000 lines down, next to the other
// company code — but `watch(() => activeCompany.companyId, ...)` near
// loadAll() runs during setup(), so a `const` declared later put
// activeCompany in the temporal dead zone and the whole view threw
// ReferenceError before it ever rendered (symptom: a blank page under the
// nav bar). Declaration order is load-bearing in <script setup>; the moved
// block is below, right after the imports.

// Defect 2 of submitBrand()'s docblock (human-reported 2026-08-19): the
// "กรุณาเลือกบริษัทก่อนบันทึก" guard message must not outlive the
// condition that produced it. Clearing it when the company changes
// (instead of only on the next successful submit) makes it disappear the
// instant the admin fixes what it complained about, rather than sitting
// there contradicting the dropdown right above it.
// Declared HERE, not next to submitBrand(): watch() runs during setup, so
// it must come after selectedCatalogCompanyId's own declaration.
watch(selectedCatalogCompanyId, () => {
  brandFormError.value = ''
  categoryFormError.value = ''
})

/**
 * Super Admin company list for the Brand / Category / Banner create
 * forms. Stayed behind when the video-settings tab moved out — those
 * forms still need it, and TenantScope does not auto-filter for a Super
 * Admin, so without a picker they would be creating rows with no company.
 *
 * Errors land in the page-level `errorMessage` now that the
 * video-settings error ref it used to write to has gone.
 */
// TASK-208 — the fetch itself moved into the store (idempotent), so every
// call site below is now just "make sure it has happened".
const loadCompanyOptionsIfNeeded = () => activeCompany.loadCompanies()
watch(activeTab, (tab) => {
  // Brand/Category/Banner create forms need the same company picker —
  // load it lazily the first time any of these tabs is opened, not just
  // Banners need it to scope the product-picker dropdown
  // to one company (TASK-069 — Super Admin sees every company's products
  // in the flat /products response, TenantScope only auto-filters for
  // non-Super-Admin actors). 'products' added (2026-08-18, human report):
  // Super Admin's own package list is the same flat cross-company
  // response, and the list needed a "บริษัท" filter + label for the same
  // reason the Banner product-picker did.
  if ((tab === 'brands' || tab === 'categories' || tab === 'banners' || tab === 'products') && isSuperAdmin.value) {
    loadCompanyOptionsIfNeeded()
  }
})
// Cover the case where the page loads directly on the brands/categories/
// banners/products tab (deep link, or it's just the default initial tab —
// 'products' IS the default, see initialTab() above) for Super Admin — the
// watch(activeTab) above only fires on a change, not on the initial value.
if (isSuperAdmin.value && (activeTab.value === 'brands' || activeTab.value === 'categories' || activeTab.value === 'banners' || activeTab.value === 'products')) {
  loadCompanyOptionsIfNeeded()
}

/*
 * TASK-102 — reference-data drawer + package filters.
 */

/** Which half of the drawer is showing. */
const refDrawerTab = ref<'brands' | 'categories'>('brands')
const refDrawerOpen = ref(false)

function openRefDrawer(which: 'brands' | 'categories' = 'brands') {
  refDrawerTab.value = which
  refDrawerOpen.value = true
  // The brand/category sections still key off activeTab (they were tabs
  // until this task); pointing it at the drawer's half is what renders
  // them. `products` is restored on close so the page underneath is
  // never left showing the wrong list.
  activeTab.value = which
  if (isSuperAdmin.value) loadCompanyOptionsIfNeeded()
}

function closeRefDrawer() {
  refDrawerOpen.value = false
  activeTab.value = 'products'
}

watch(refDrawerTab, (which) => {
  if (refDrawerOpen.value) activeTab.value = which
})

/**
 * Esc closes it, and the body stops scrolling while it is open.
 *
 * Both matter more now that the drawer is full-screen: there is no
 * backdrop left to click, so Esc and the × are the only ways out, and a
 * page scrolling underneath a full-screen surface is the kind of thing
 * you only notice as "the scrollbar jumped" after closing.
 */
watch(refDrawerOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
  if (open) window.addEventListener('keydown', onRefDrawerKeydown)
  else window.removeEventListener('keydown', onRefDrawerKeydown)
})

function onRefDrawerKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') closeRefDrawer()
}

onUnmounted(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', onRefDrawerKeydown)
})

// A deep link that used to open the brands/categories tab now opens the
// drawer instead, so an old bookmark still lands somewhere useful.
if (typeof route.query.tab === 'string' && (route.query.tab === 'brands' || route.query.tab === 'categories')) {
  openRefDrawer(route.query.tab)
}

/**
 * Package filters. Brand and category stop being places you navigate to
 * and become how you narrow the package list — which is what an admin
 * wanted from them 99% of the time anyway. The list had no filter at all
 * before this task.
 *
 * Persisted per SESSION (human-confirmed): switching to another tab and
 * back keeps the filter, but a fresh visit starts clean — a filter that
 * outlives the browser tab is the kind that has an admin convinced a
 * package was deleted.
 */
const FILTER_KEY = 'sv_admin_catalog_filters'
// companyId (2026-08-18, human report) — Super Admin sees every company's
// products in this one flat list (TenantScope skips the company_id filter
// entirely for Super Admin, per TenantScope::apply()); meaningless for
// Company Admin, who only ever has one company's rows here regardless, so
// it stays null and unused/hidden for them (see the v-if="isSuperAdmin"
// guards on the filter control and the row label below).
type CatalogFilters = { q: string; brandId: number | null; categoryId: number | null; companyId: number | null }

function readStoredFilters(): CatalogFilters {
  try {
    const raw = window.sessionStorage?.getItem(FILTER_KEY)
    if (raw) {
      const parsed = JSON.parse(raw) as Partial<CatalogFilters>

      return {
        q: typeof parsed.q === 'string' ? parsed.q : '',
        brandId: typeof parsed.brandId === 'number' ? parsed.brandId : null,
        categoryId: typeof parsed.categoryId === 'number' ? parsed.categoryId : null,
        companyId: typeof parsed.companyId === 'number' ? parsed.companyId : null,
      }
    }
  } catch {
    /* private mode / corrupt value — fall through to defaults */
  }

  return { q: '', brandId: null, categoryId: null, companyId: null }
}

const productFilters = reactive<CatalogFilters>(readStoredFilters())

watch(productFilters, (value) => {
  try {
    window.sessionStorage?.setItem(FILTER_KEY, JSON.stringify(value))
  } catch {
    /* ignore */
  }
}, { deep: true })

/**
 * Filtered CLIENT-SIDE on purpose. The catalog list is already fully
 * loaded for this page (it is a per-company package list, not a paginated
 * feed), so a round trip per keystroke would add latency and a loading
 * flash for zero benefit.
 */
/*
 * ── ถังขยะ (2026-09-09) ──────────────────────────────────────────────
 *
 * GET /catalog-trash answers for all three kinds at once, because a bin is
 * one question. Each row carries the server's own `permissions.restore`: a
 * Company Admin sees a hidden PLATFORM row (pretending it never existed
 * would read as "the product is gone forever") but cannot bring it back, and
 * a button that 403s is worse than no button.
 */
interface TrashBucket {
  products: Product[]
  brands: Brand[]
  categories: ProductCategory[]
}
/*
 * ── ปักหมุดแนะนำ, on the row (2026-09-09) ─────────────────────────────
 *
 * human: "เพิ่ม icon ดาวปักหมุด ในหน้านี้เลยไม่ต้องเข้าไปข้างใน".
 *
 * Pinning has only ever been reachable three clicks in — open the product,
 * find the section at the bottom of the general tab, toggle, save — which is
 * a lot of ceremony for a decision that is really "this one, and that one".
 * The same endpoint, from the list.
 *
 * A pin belongs to ONE company (ADR-040), so the star means nothing without a
 * company scope, and it is hidden in ทุกบริษัท mode rather than guessing.
 */
interface PinRow {
  id: number
  product_id: number
  company_id: number
  sort_order: number
  is_active: boolean
}
const pins = ref<PinRow[]>([])
const pinningId = ref<number | null>(null)

const pinOf = (product: Product) => pins.value.find((pin) => pin.product_id === product.id) ?? null
const isPinned = (product: Product) => pinOf(product)?.is_active === true

async function loadPins(): Promise<void> {
  if (selectedCatalogCompanyId.value === null) {
    pins.value = []

    return
  }
  try {
    const res = await api.get<{ data: PinRow[] }>('/product-recommendation-pins')
    pins.value = res.data
  } catch {
    // A pin list that will not load must not blank the catalogue: the stars
    // simply render unlit, and every other control on the row still works.
    pins.value = []
  }
}

/**
 * Toggle the pin for the scoped company.
 *
 * PUT when a row already exists — including a switched-off one, which is what
 * closing the sale leaves behind — and POST only the first time. Creating a
 * second row would hit the unique(company_id, product_id) index as a 422 that
 * reads like a validation mistake the admin made.
 */
async function togglePin(product: Product): Promise<void> {
  const companyId = selectedCatalogCompanyId.value
  if (companyId === null || pinningId.value !== null) return

  pinningId.value = product.id
  errorMessage.value = ''
  try {
    const existing = pinOf(product)
    if (existing) {
      await api.put(`/product-recommendation-pins/${existing.id}`, {
        sort_order: existing.sort_order,
        is_active: !existing.is_active,
      })
    } else {
      await api.post('/product-recommendation-pins', {
        product_id: product.id,
        sort_order: 0,
        ...(isSuperAdmin.value ? { company_id: companyId } : {}),
      })
    }
    await loadPins()
  } catch (e) {
    errorMessage.value = saveFailureMessage(e)
  } finally {
    pinningId.value = null
  }
}

const trash = ref<TrashBucket>({ products: [], brands: [], categories: [] })
const loadingTrash = ref(false)
const restoringId = ref<string | null>(null)
const trashError = ref('')

async function loadTrash(): Promise<void> {
  loadingTrash.value = true
  try {
    const res = await api.get<{ data: Partial<TrashBucket> }>(activeCompany.scopedPath('/catalog-trash'))
    /*
     * Read the three buckets out one by one rather than assigning the payload
     * whole. `trashCount` reads `.length` off each of them on every render,
     * so a response missing a key — an older backend, a proxy error page,
     * anything — would not be a wrong number, it would be a blank page and a
     * TypeError inside a computed.
     */
    trash.value = {
      products: res.data?.products ?? [],
      brands: res.data?.brands ?? [],
      categories: res.data?.categories ?? [],
    }
  } catch (e) {
    trashError.value = e instanceof ApiError ? e.message : 'โหลดถังขยะไม่สำเร็จ'
  } finally {
    loadingTrash.value = false
  }
}

const trashCount = computed(
  () => trash.value.products.length + trash.value.brands.length + trash.value.categories.length,
)

/**
 * Restore is one call and then a full reload, deliberately: the row coming
 * back changes the package list, the brand picker and the category picker at
 * once, and patching three client-side arrays to match is how they drift.
 *
 * `restoringId` is keyed by "kind:id" rather than id alone — a brand and a
 * product can share the number 4, and a shared spinner would light up the
 * wrong row.
 */
async function restoreRow(kind: 'products' | 'brands' | 'product-categories', id: number): Promise<void> {
  const key = `${kind}:${id}`
  if (restoringId.value) return
  restoringId.value = key
  trashError.value = ''
  try {
    await api.post(`/${kind}/${id}/restore`, {})
    await Promise.all([loadAll(), loadTrash()])
  } catch (e) {
    trashError.value = e instanceof ApiError ? e.message : 'กู้คืนไม่สำเร็จ'
  } finally {
    restoringId.value = null
  }
}

function canRestore(row: { permissions?: RowPermissions }): boolean {
  return row.permissions?.restore === true
}

/** "2 วันที่แล้ว" is friendlier than a timestamp for a bin. */
function deletedAgo(iso: string | null | undefined): string {
  if (!iso) return ''
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
  if (days <= 0) return 'ลบวันนี้'
  if (days === 1) return 'ลบเมื่อวาน'

  return `ลบเมื่อ ${days} วันที่แล้ว`
}

const filteredProducts = computed(() => {
  const q = productFilters.q.trim().toLowerCase()

  return products.value.filter((p) => {
    if (productFilters.brandId !== null && p.brand?.id !== productFilters.brandId) return false
    if (productFilters.categoryId !== null && p.category?.id !== productFilters.categoryId) return false
    // TASK-208 — narrowing by company is the header switcher's job now.
    // TASK-256 / ADR-040 — a PLATFORM product (company_id null) belongs to the
    // scoped company as much as to any other; the server already sends it
    // (CompanyScopeFilter includePlatformWide) and dropping it here would hide
    // the shared catalogue from the only screen that can switch it on.
    if (activeCompany.companyId !== null && p.company_id !== null && p.company_id !== activeCompany.companyId) return false
    // 2026-09-09 — "show me only the shared ones" / "only this company's".
    if (ownerFilter.value === 'platform' && !p.is_shared) return false
    if (ownerFilter.value === 'company' && p.is_shared) return false
    if (q && !p.name.toLowerCase().includes(q)) return false

    return true
  })
    /*
     * 2026-09-09 (human: "เลื่อนตำแหน่งล่างสุด ให้สินค้าที่เปิดขายอยู่ด้านบน").
     *
     * What is on sale is the day-to-day work; what is closed is reference.
     * Sorted rather than filtered, because a closed product must stay
     * findable — it is the one somebody wants to switch back on.
     *
     * `.slice()` first: this derives from `products`, and sort() mutates in
     * place — sorting the array a computed returned would reorder the source
     * ref and make each render depend on the last one.
     *
     * Then by name within each half, with 'th', so the two groups are each
     * ordered the way a Thai reader expects rather than by insertion.
     */
    .slice()
    .sort((a, b) => {
      if (a.is_sellable_here !== b.is_sellable_here) return a.is_sellable_here ? -1 : 1

      return a.name.localeCompare(b.name, 'th')
    })
})

const productFilterCount = computed(
  () => [productFilters.brandId, productFilters.categoryId].filter((v) => v !== null).length
    + (productFilters.q.trim() ? 1 : 0),
)

// companyId -> name lookup for the row label below. companyOptions is
// only ever populated for Super Admin (loadCompanyOptionsIfNeeded() is a
// no-op otherwise), so this naturally returns undefined and the label
// stays hidden for Company Admin — nothing to look up when every row is
// already their own one company.
function companyName(companyId: number | null): string | undefined {
  if (companyId === null) return undefined

  return activeCompany.companies.find((c) => c.id === companyId)?.name
}

/**
 * TASK-256 / ADR-040 — what to CALL an owner, now that `null` is a real one.
 *
 * companyName() answers "which company row is this" and returns undefined for
 * a platform row, which is right for it: there is no company. But every place
 * that prints an owner needs a word, and "#null" or a blank chip would read as
 * a bug. `null` is not a missing company — it is the platform, deliberately.
 */
/*
 * 2026-09-09 (human: "เปลี่ยนคำว่า ของกลาง เป็น สินค้ากลาง แบรนด์กลาง หมวดกลาง").
 *
 * One word was doing three jobs. On a package row "ของกลาง" meant a shared
 * PACKAGE; on a brand card, a shared BRAND; in the bin, whichever kind that
 * row happened to be — and the reader had to work out which from the
 * surroundings. Naming the thing costs the same number of characters and asks
 * nothing of them.
 */
type CatalogKind = 'product' | 'brand' | 'category'
const PLATFORM_LABEL: Record<CatalogKind, string> = {
  product: 'สินค้ากลาง',
  brand: 'แบรนด์กลาง',
  category: 'หมวดกลาง',
}

/**
 * What to CALL an owner, now that `null` is a real one (TASK-256 / ADR-040).
 *
 * companyName() answers "which company row is this" and returns undefined for
 * a platform row, which is right for it: there is no company. But every place
 * that prints an owner needs a word, and "#null" or a blank chip would read as
 * a bug. `null` is not a missing company — it is the platform, deliberately.
 */
function ownerLabel(companyId: number | null, kind: CatalogKind = 'product'): string {
  if (companyId === null) return PLATFORM_LABEL[kind]

  return companyName(companyId) ?? `#${companyId}`
}

/*
 * TASK-245 — ask, never re-derive.
 *
 * Every one of these used to be a guess on this screen, and each guess was
 * wrong in a way nobody could see by looking:
 *
 *   • the brand/category cards group rows BY NAME, so one card holds this
 *     company's row AND the platform's — and "แก้ไข" reached both. A Company
 *     Admin renaming their own brand got a 403 from the platform half.
 *   • the product row asked `is_shared`, which cannot see a catalog-LINKED
 *     product: company_id is still theirs, and every write is still refused
 *     (ADR-036 §5/§6).
 *
 * Missing permissions read as NO. Failing closed costs a button; failing open
 * costs a 403 in front of somebody who was told they could.
 */
function canUpdate(row: { permissions?: RowPermissions }): boolean {
  return row.permissions?.update === true
}

function canDelete(row: { permissions?: RowPermissions }): boolean {
  return row.permissions?.delete === true
}

/**
 * TASK-209 (browser QA, 2026-08-19: "ระบบ Filter brand ในโหมดทุกบริษัท ควรมี
 * ชื่อบริษัทนำหน้าใน Select box ผู้ใช้สับสน").
 *
 * In ทุกบริษัท mode the brand/category lists are cross-company, so the same
 * name legitimately appears once per company — three identical
 * "QA Test Category" entries with nothing to tell them apart.
 *
 * The company name is prefixed onto the LABEL (the human's own instruction),
 * not just carried in an <optgroup>: a native select shows only the chosen
 * option's text once it is closed, so an optgroup alone would answer the
 * question while the list is open and lose it the moment you pick one.
 * Options are also grouped, so the open list reads company-by-company.
 *
 * With a company scoped there is only one company on screen — the prefix
 * would be noise, so the plain name is used exactly as before.
 */
function groupOptionsByCompany<T extends { id: number, name: string, company_id: number | null }>(items: T[]) {
  const buckets = new Map<number | null, T[]>()
  for (const item of items) {
    const bucket = buckets.get(item.company_id)
    if (bucket) bucket.push(item)
    else buckets.set(item.company_id, [item])
  }

  return [...buckets.entries()]
    .map(([id, list]) => ({
      companyId: id,
      // v-for needs a PropertyKey and `null` is not one — TASK-256.
      key: id === null ? 'platform' : String(id),
      // A group HEADING over a mixed list, not the name of one row — so it
      // says what the group is rather than borrowing one kind's noun.
      companyName: id === null ? 'ใช้ร่วมกันทุกบริษัท' : (companyName(id) ?? `บริษัท #${id}`),
      items: [...list].sort((a, b) => a.name.localeCompare(b.name, 'th')),
    }))
    // TASK-256 — the platform's brands and categories head the list. They are
    // the ones every company can use, so they are the likeliest pick; sorting
    // them alphabetically would bury them somewhere in the middle.
    .sort((a, b) => {
      if ((a.companyId === null) !== (b.companyId === null)) return a.companyId === null ? -1 : 1

      return a.companyName.localeCompare(b.companyName, 'th')
    })
}

const brandFilterGroups = computed(() => groupOptionsByCompany(brands.value))
const categoryFilterGroups = computed(() => groupOptionsByCompany(categories.value))

function clearProductFilters() {
  productFilters.q = ''
  productFilters.brandId = null
  productFilters.categoryId = null
}

/**
 * ── TASK-202 / TASK-204 — the brand & category list, grouped by NAME ──
 *
 * TASK-202 grouped rows under company headings. Human, 2026-08-19, after
 * seeing it: "ui list แสดงแบบนี้สับสนได้ง่าย ให้ปรับเป็นชื่อ...นำ และมีชื่อ
 * บริษัทอยู่ใต้ชื่อแบรนด์ — Brand 1 / company1 company2 company3".
 *
 * So the unit on screen is now the NAME, not the row: one card per distinct
 * brand (or category) name, with a chip per company that has it. The same
 * "sss" existing in three companies is three `brands` rows (BR-6 —
 * company-scoped, and they must stay separate rows), but reads as one thing
 * with three chips, which is how the admin actually thinks about it.
 *
 * Consequences that follow from that choice, all handled below:
 *   - edit is name-level: rename/activate applies to every ticked company,
 *     ticking a new company CREATES its row, unticking one DELETES that
 *     company's row (soft delete — DeletionGuard still refuses if products
 *     use it, and the refusal is reported per company).
 *   - delete is name-level too: it removes the name from every company it
 *     is in, which the confirm dialog states explicitly.
 *
 * Grouping/keying is client-side on the trimmed name; the scope picker
 * filters to names present in that company but chips still show ALL the
 * companies that have the name (that cross-company view is the point).
 */
const refSearch = ref('')

interface RefNameGroup<T> {
  /** Trimmed name — also the v-for key and the "is this group being edited" token. */
  key: string
  name: string
  /** One row per company that has this name. */
  rows: T[]
  companyIds: (number | null)[]
  /**
   * TASK-256 — true when one of the rows is the PLATFORM's. Such a row is not
   * "a company that has this name": it is the row every company shares, so the
   * tick-box picker below never adds or removes it.
   */
  hasPlatformRow: boolean
  /**
   * TASK-245 — whether the card's buttons do anything at all. A card is
   * editable when at least ONE of its rows is: a Company Admin whose card also
   * holds the platform's row still renames their own half, and the save skips
   * the half that is not theirs.
   */
  canEditAny: boolean
  canDeleteAny: boolean
  totalProducts: number
}

function matchesRefSearch(name: string): boolean {
  const q = refSearch.value.trim().toLowerCase()
  return q === '' || name.toLowerCase().includes(q)
}

/*
 * TASK-256 / ADR-040 — `company_id: number | null`, because a brand or
 * category may now belong to the PLATFORM. Grouping is by NAME and was never
 * about ownership, so a shared row simply joins the group its name belongs to
 * — which is what the screen already says ("ใช้งาน 2/3 บริษัท").
 */
function groupByName<T extends { company_id: number | null, name: string, is_active: boolean, products_count?: number, permissions?: RowPermissions }>(items: T[]): RefNameGroup<T>[] {
  const scope = selectedCatalogCompanyId.value
  const buckets = new Map<string, T[]>()
  for (const item of items) {
    if (!matchesRefSearch(item.name)) continue
    const key = item.name.trim()
    const bucket = buckets.get(key)
    if (bucket) bucket.push(item)
    else buckets.set(key, [item])
  }

  return [...buckets.entries()]
    // Scoped: only names this company actually has. The chips below still
    // list every company holding the name — hiding those would put us back
    // at "why does this name exist twice".
    // TASK-256 — a PLATFORM row (company_id null) belongs to this company too;
    // it belongs to all of them. Filtering it out would hide the brand a
    // promoted product actually points at.
    .filter(([, rows]) => scope === null || rows.some((r) => r.company_id === scope || r.company_id === null))
    .map(([key, rows]) => ({
      key,
      // `rows` came from a bucket created by pushing its first element, so
      // it is never empty; the fallback keeps noUncheckedIndexedAccess happy
      // without an assertion that claims more than the code proves.
      name: rows[0]?.name ?? key,
      // The platform row first, for the same reason as the filter dropdown.
      rows: [...rows].sort((a, b) => {
        if ((a.company_id === null) !== (b.company_id === null)) return a.company_id === null ? -1 : 1

        return ownerLabel(a.company_id).localeCompare(ownerLabel(b.company_id), 'th')
      }),
      companyIds: rows.map((r) => r.company_id),
      hasPlatformRow: rows.some((r) => r.company_id === null),
      canEditAny: rows.some(canUpdate),
      canDeleteAny: rows.some(canDelete),
      totalProducts: rows.reduce((n, r) => n + (r.products_count ?? 0), 0),
    }))
    .sort((a, b) => a.name.localeCompare(b.name, 'th'))
}

const brandGroups = computed(() => groupByName(brands.value))
const categoryGroups = computed(() => groupByName(categories.value))

/** "ใช้งาน" / "ปิดใช้งาน" / "ใช้งาน 2/3 บริษัท" — is_active is per row, so a name can be mixed. */
function refStatusLabel(rows: { is_active: boolean }[]): string {
  const active = rows.filter((r) => r.is_active).length
  if (active === rows.length) return 'ใช้งาน'
  if (active === 0) return 'ปิดใช้งาน'

  return `ใช้งาน ${active}/${rows.length} บริษัท`
}

function refStatusClass(rows: { is_active: boolean }[]): string {
  const active = rows.filter((r) => r.is_active).length
  if (active === rows.length) return 'text-emerald-600'

  return active === 0 ? 'text-slate-400' : 'text-amber-600'
}

/**
 * TASK-203 — the create button is no longer gated on the list's scope
 * picker: the form carries its own company tick boxes now. Opening the
 * form pre-ticks whatever the list is scoped to, since "I am looking at
 * GENESENN Health and clicked add" almost always means "add it there" —
 * but every tick stays editable, including adding more companies.
 */
function toggleBrandForm(): void {
  showBrandForm.value = !showBrandForm.value
  if (showBrandForm.value && isSuperAdmin.value && !brandCompanyIds.value.length && selectedCatalogCompanyId.value !== null) {
    brandCompanyIds.value = [selectedCatalogCompanyId.value]
  }
}

function toggleCategoryForm(): void {
  showCategoryForm.value = !showCategoryForm.value
  if (showCategoryForm.value && isSuperAdmin.value && !categoryCompanyIds.value.length && selectedCatalogCompanyId.value !== null) {
    categoryCompanyIds.value = [selectedCatalogCompanyId.value]
  }
}

/**
 * The dialog header's create button (human request, 2026-08-20).
 *
 * It used to be TWO buttons, one per tab, each sitting beside its own
 * search box. The human moved it up to the title row, where there is only
 * one row for both tabs — so which form it opens has to be decided here
 * rather than by which panel rendered it.
 *
 * Dispatching on the active tab, rather than rendering two buttons with
 * v-if, keeps the two toggles exactly as they were: each still pre-ticks
 * its own company list, and neither grew a second caller to keep in step.
 */
const refCreateLabel = computed(() => (refDrawerTab.value === 'brands' ? '+ เพิ่มแบรนด์' : '+ เพิ่มหมวดหมู่'))

function toggleRefForm(): void {
  if (refDrawerTab.value === 'brands') {
    toggleBrandForm()

    return
  }

  toggleCategoryForm()
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="cube"
      title="Product catalog"
      subtitle="แบรนด์ / หมวดหมู่ / แพ็กเกจ / อัตราคอมมิชชั่น"
      description="ERD-001 §Product Catalog — BR-2, BR-3. ตัวเลขราคา/อัตราคอมมิชชั่นบางส่วนยังเป็นค่าตัวอย่างชั่วคราว (seed placeholder) รอค่าจริงยืนยัน (BR-7)"
      accent-color="brand"
      storage-key="product-catalog"
    >
      <!-- TASK-040 — link-out to the new "มุมมองสินค้า" report (ABC
           grading + price promotions), pure addition, does not touch any
           existing tab content below. -->
      <template #actions>
        <RouterLink
          :to="{ name: 'product-performance' }"
          class="px-3 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 text-sm whitespace-nowrap flex items-center gap-1.5"
        >
          <Icon name="bar_chart" :size="14" />
          มุมมองสินค้า
        </RouterLink>
      </template>
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in tabs"
            :key="t.key"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key"
          >
            <Icon :name="t.icon" :size="14" />
            {{ t.label }}
            <!-- The bin is the only tab whose emptiness is the good news, so
                 it is the only one worth counting on the bar. -->
            <span v-if="t.key === 'trash' && trashCount" class="ml-0.5 px-1.5 rounded-full bg-slate-200 text-slate-600 text-[10px]">
              {{ trashCount }}
            </span>
          </button>
        </div>
      </template>
    </HeroHeader>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
    <!-- Brands -->
    <!-- TASK-102 — reference-data drawer. Brands and categories used to be
         two top-level tabs; they are data an admin configures once per
         company, so they now live behind the "จัดการแบรนด์ / หมวดหมู่"
         button on the package toolbar. Every control below is the SAME
         markup they had as tabs — create, inline edit, activate toggle,
         soft delete with the FK dependency guard, category icon picker,
         and the Super Admin company picker. Nothing was dropped. -->
    <Teleport to="body">
      <Transition name="fade">
        <!-- Human follow-up (2026-08-04): "เลื่อนมาให้สุด Screen เลย ไม่ทำ
             menu และ tab" — was full-screen white, covering the top nav
             and the tab bar rather than a panel over a dimmed page.
             SUPERSEDED by the human's later ruling (2026-08-19): "แก้ UI
             ให้คลิ๊กแล้วเป็น Modal แบบ pop up" — a centred dialog over a
             dimmed page, the same shape as this app's other dialogs (the
             ADR-036 catalog-link picker in ProductEditView.vue, and every
             ConfirmDialog). Full-screen-white made a two-row brand list
             look like a whole new page with nowhere to go back to; the
             dimmed backdrop keeps the catalogue visible behind it so the
             edit reads as a side trip, not a navigation. Click on the
             backdrop now closes it, alongside the X and Esc. -->
        <div
          v-if="refDrawerOpen"
          class="fixed inset-0 z-[1000] bg-black/60 flex items-center justify-center p-4"
          @click.self="closeRefDrawer"
        >
          <div class="w-full max-w-3xl max-h-[85vh] overflow-y-auto bg-white rounded-2xl shadow-2xl px-5 py-5">
            <!-- Human request (2026-08-20): the create button moves from the
                 tab's own toolbar up here to the title row. One button for
                 both tabs now — see toggleRefForm(); its label follows the
                 active tab so it never offers to add the thing you are not
                 looking at. Placed BEFORE the close button, never after: the
                 far-right corner of a dialog is where "close" lives, and a
                 create button landing there is the one misclick that costs
                 you the form you were filling in. -->
            <div class="flex items-center gap-3 mb-4">
              <h2 class="flex-1 min-w-0 text-base font-bold text-slate-900">จัดการแบรนด์ / หมวดหมู่</h2>
              <button class="btn-primary shrink-0" @click="toggleRefForm">{{ refCreateLabel }}</button>
              <button class="w-10 h-10 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100" title="ปิด (Esc)" @click="closeRefDrawer">
                <Icon name="x" :size="18" />
              </button>
            </div>
            <!-- TASK-202 — company scope bar, ABOVE the tabs on purpose: it
                 scopes both tabs, and sitting inside one tab's panel made it
                 look like it only applied to that tab. Tinted + a heavier
                 border so it reads as the control the whole dialog hangs off,
                 not one more field. -->
            <!-- TASK-208 — the dialog's own scope <select> is gone; this
                 states which scope the header is on so the grouping below is
                 never a surprise. -->
            <div v-if="isSuperAdmin" class="flex items-center gap-2 mb-3 px-3 py-2.5 rounded-xl bg-brand-50 border border-brand-100">
              <Icon name="building" :size="14" class="text-brand-600 shrink-0" />
              <span class="text-xs font-bold text-brand-700">
                {{ activeCompany.companyName ?? 'ทุกบริษัท (จัดกลุ่มให้)' }}
              </span>
              <span class="text-[11px] text-brand-600/70">เปลี่ยนบริษัทได้จากปุ่มมุมขวาบนของหน้าจอ</span>
            </div>
            <!-- TASK-207 (human, 2026-08-19): the badge counted ROWS, so 4
                 brands existing in 2 companies each read "8". The unit on this
                 screen is the NAME (TASK-204), so the badge now counts name
                 groups — exactly the number of cards below it. It therefore
                 follows the company scope and the search box too, which is the
                 point: it always states what is on screen, never a hidden
                 total. (The duplicate "แสดง N รายการ" text that used to sit in
                 the company bar is gone with it.) -->
            <div class="flex gap-1 p-1 rounded-xl bg-slate-100">
              <button
                v-for="opt in ([{ key: 'brands', label: 'แบรนด์', count: brandGroups.length }, { key: 'categories', label: 'หมวดหมู่', count: categoryGroups.length }] as const)"
                :key="opt.key"
                type="button"
                class="flex-1 h-[36px] rounded-lg text-sm font-bold transition"
                :class="refDrawerTab === opt.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'"
                @click="refDrawerTab = opt.key"
              >
                <!-- Human-reported 2026-08-19: "แบรนด์ 2" read as "brand
                     number 2", not "2 brands" — a bare number welded to a
                     noun is ambiguous in Thai. Human's chosen form: a
                     notification-style badge lifted above the label's
                     top-right corner, blue fill / white text — the count
                     is then unmistakably a quantity ON the label, never
                     part of its name. Absolutely positioned against the
                     label span (not the button) so it hugs the text
                     regardless of how wide the tab stretches. -->
                <span class="relative inline-flex items-center">
                  {{ opt.label }}
                  <span
                    class="absolute -top-2 -right-5 min-w-[18px] h-[18px] px-1 inline-flex items-center justify-center rounded-full bg-brand-600 text-white text-[10px] font-bold leading-none"
                  >{{ opt.count }}</span>
                </span>
              </button>
            </div>

    <section v-if="refDrawerTab === 'brands'" class="mt-4">
      <!-- TASK-202 — search. The create button that used to sit beside it
           moved to the dialog title row (human, 2026-08-20); it is one
           button for both tabs now, so it cannot live inside either tab's
           panel. -->
      <div class="mb-2">
        <div class="relative">
          <Icon name="search" :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input v-model="refSearch" type="text" placeholder="ค้นหาแบรนด์..." class="w-full h-[34px] pl-8 pr-3 rounded-lg border border-slate-200 text-sm" />
        </div>
      </div>
      <!-- TASK-203 — the create form owns its target companies (tick boxes,
           เลือกทั้งหมด / ล้างทั้งหมด). One submit = one POST per ticked
           company, because brands.company_id makes each one its own row. -->
      <form v-if="showBrandForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 space-y-3" @submit.prevent="submitBrand">
        <div>
          <label class="text-xs font-bold text-slate-500">ชื่อแบรนด์</label>
          <input v-model="brandForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <!-- 2026-09-09 (human: "ทำแบบเดียวกันกับทางแบรนด์ และหมวดหมู่") —
             the tick boxes below can only say WHICH COMPANIES get a row of
             their own. แบรนด์กลาง is a different answer, not one more company,
             so it is asked first and hides the tick list when chosen. -->
        <div v-if="isSuperAdmin" class="rounded-lg border border-brand-100 bg-brand-50 p-3">
          <p class="text-xs font-bold text-brand-700">บันทึกเป็น</p>
          <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
            <label
              class="flex items-start gap-2 p-2.5 rounded-lg border bg-white cursor-pointer transition-colors"
              :class="brandCreateAsPlatform ? 'border-slate-200' : 'border-brand-500 ring-2 ring-brand-100'"
            >
              <input v-model="brandCreateAsPlatform" type="radio" :value="false" data-test="brand-owner-company" class="mt-0.5 shrink-0" />
              <span class="min-w-0">
                <span class="block text-sm font-bold text-slate-800">แบรนด์ของบริษัท</span>
                <span class="block mt-0.5 text-[11px] text-slate-500">สร้างแยกให้ทุกบริษัทที่ติ๊กไว้ (บริษัทละ 1 รายการ) · บริษัทอื่นไม่เห็น</span>
              </span>
            </label>
            <label
              class="flex items-start gap-2 p-2.5 rounded-lg border bg-white cursor-pointer transition-colors"
              :class="brandCreateAsPlatform ? 'border-brand-500 ring-2 ring-brand-100' : 'border-slate-200'"
            >
              <input v-model="brandCreateAsPlatform" type="radio" :value="true" data-test="brand-owner-platform" class="mt-0.5 shrink-0" />
              <span class="min-w-0">
                <span class="block text-sm font-bold text-slate-800">แบรนด์กลาง (ทุกบริษัทใช้ร่วมกัน)</span>
                <span class="block mt-0.5 text-[11px] text-slate-500">รายการเดียว ทุกบริษัทเลือกใช้ได้ตอนเพิ่มสินค้า · แก้ชื่อหรือโลโก้ครั้งเดียว เปลี่ยนพร้อมกันทุกบริษัท</span>
              </span>
            </label>
          </div>
        </div>
        <CompanyMultiSelect
          v-if="isSuperAdmin && !brandCreateAsPlatform"
          v-model="brandCompanyIds"
          :options="companyOptions"
          label="สร้างในบริษัท (เลือกได้หลายบริษัท)"
          placeholder="ติ๊กเลือกบริษัท..."
        />
        <!-- TASK-205 — brand logo (brands only; categories keep the icon picker). -->
        <div>
          <label class="text-xs font-bold text-slate-500 block mb-1">โลโก้แบรนด์ (ไม่บังคับ)</label>
          <div class="flex items-center gap-3">
            <span class="w-14 h-14 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden shrink-0">
              <img v-if="brandLogoPreview" :src="brandLogoPreview" alt="" class="w-full h-full object-contain" />
              <Icon v-else name="tag" :size="18" class="text-slate-300" />
            </span>
            <div class="flex-1 min-w-0">
              <input type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-xs text-slate-500 file:mr-2 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 file:text-xs file:font-bold" @change="onBrandLogoChange($event, 'create')" />
              <p class="mt-1 text-[11px] text-slate-400">JPG / PNG / WEBP — ระบบย่อให้อัตโนมัติ สูงสุด 2 MB</p>
            </div>
            <button v-if="brandLogoPreview" type="button" class="text-[11px] font-bold text-rose-600 hover:underline shrink-0" @click="resetBrandLogo('create')">
              เอารูปออก
            </button>
          </div>
        </div>
        <div class="flex justify-end">
          <button type="submit" :disabled="savingBrand || compressingBrandLogo" class="btn-primary">
            {{ compressingBrandLogo ? 'กำลังย่อรูป...' : (savingBrand ? 'กำลังบันทึก...' : 'บันทึก') }}
          </button>
        </div>
      </form>
      <p v-if="brandFormError" class="mb-3 text-xs font-bold text-rose-600">{{ brandFormError }}</p>
      <EmptyState v-if="!brandGroups.length" icon="tag" :title="refSearch ? 'ไม่พบแบรนด์ที่ค้นหา' : 'ยังไม่มีแบรนด์'" />
      <!-- TASK-204 — one card per NAME: name on top, the companies that have
           it as chips underneath (human's own sketch: "Brand 1 / company1
           company2 company3"). -->
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div
          v-for="g in brandGroups"
          :key="g.key"
          class="border rounded-xl p-4"
          :class="editingBrandKey === g.key ? 'bg-white border-brand-500 ring-2 ring-brand-100' : 'bg-white/95 border-slate-200'"
        >
          <template v-if="editingBrandKey === g.key">
            <div class="space-y-3">
              <p class="text-[11px] font-bold text-brand-700">กำลังแก้ไข · {{ g.name }}</p>
              <!-- TASK-245 — said before the save, not discovered after it: a
                   Company Admin editing a name that also exists centrally
                   changes only their own row. -->
              <p v-if="g.hasPlatformRow && !isSuperAdmin" class="text-[11px] text-amber-600">
                ชื่อนี้มีแบรนด์กลางอยู่ด้วย — การแก้ไขนี้จะมีผลเฉพาะแบรนด์ของบริษัทคุณ ส่วนแบรนด์กลางแก้ไขได้เฉพาะ Super Admin
              </p>
              <div>
                <label class="text-xs font-bold text-slate-500">ชื่อแบรนด์</label>
                <input v-model="editBrandForm.name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <!-- TASK-204 — same picker as create. Unticking a company
                   REMOVES the brand from it (soft delete); the hint says so
                   because the tick box alone does not. -->
              <CompanyMultiSelect
                v-if="isSuperAdmin"
                v-model="editBrandCompanyIds"
                :options="companyOptions"
                label="บริษัทที่มีแบรนด์นี้ (ติ๊กเพิ่ม = สร้างให้, เอาติ๊กออก = ลบออกจากบริษัทนั้น)"
                placeholder="ติ๊กเลือกบริษัท..."
              />
              <!-- TASK-205 — replace / clear the logo. Leaving both alone
                   keeps whatever is already stored (the backend only touches
                   logo_path when a file or remove_logo arrives). -->
              <div>
                <label class="text-xs font-bold text-slate-500 block mb-1">โลโก้แบรนด์</label>
                <div class="flex items-center gap-3">
                  <span class="w-14 h-14 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden shrink-0">
                    <img v-if="editBrandLogoPreview" :src="editBrandLogoPreview" alt="" class="w-full h-full object-contain" />
                    <img v-else-if="editBrandCurrentLogoUrl && !editBrandRemoveLogo" :src="editBrandCurrentLogoUrl" alt="" class="w-full h-full object-contain" />
                    <Icon v-else name="tag" :size="18" class="text-slate-300" />
                  </span>
                  <div class="flex-1 min-w-0">
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-xs text-slate-500 file:mr-2 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 file:text-xs file:font-bold" @change="onBrandLogoChange($event, 'edit')" />
                    <p class="mt-1 text-[11px] text-slate-400">
                      {{ editBrandLogoPreview ? 'จะแทนที่รูปเดิมเมื่อกดบันทึก' : 'เลือกไฟล์เพื่อเปลี่ยนรูป — ไม่เลือก = ใช้รูปเดิม' }}
                    </p>
                  </div>
                </div>
                <label v-if="editBrandCurrentLogoUrl && !editBrandLogoPreview" class="mt-2 flex items-center gap-2 text-[11px] font-bold text-rose-600 cursor-pointer">
                  <input v-model="editBrandRemoveLogo" type="checkbox" class="rounded border-slate-300" /> ลบรูปโลโก้ออก (ทุกบริษัทที่ติ๊กไว้)
                </label>
              </div>
              <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer">
                <input v-model="editBrandForm.is_active" type="checkbox" class="rounded border-slate-300" /> ใช้งาน (มีผลกับทุกบริษัทที่ติ๊กไว้)
              </label>
              <p v-if="editBrandError" class="text-xs font-bold text-rose-600">{{ editBrandError }}</p>
              <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="cancelEditBrand">ยกเลิก</button>
                <button type="button" :disabled="savingBrandEdit" class="btn-primary" @click="saveEditBrand">
                  {{ savingBrandEdit ? 'กำลังบันทึก...' : 'บันทึก' }}
                </button>
              </div>
            </div>
          </template>
          <div v-else class="flex items-start justify-between gap-3">
            <!-- TASK-205 — the logo leads the row when there is one, so the
                 list is scannable by mark as well as by name. -->
            <span class="w-10 h-10 rounded-lg border border-slate-100 bg-slate-50 flex items-center justify-center overflow-hidden shrink-0">
              <img v-if="g.rows.find((r) => r.logo_url)" :src="g.rows.find((r) => r.logo_url)?.logo_url ?? ''" :alt="g.name" class="w-full h-full object-contain" />
              <Icon v-else name="tag" :size="16" class="text-slate-300" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-bold text-slate-900 truncate">{{ g.name }}</p>
              <div v-if="isSuperAdmin" class="mt-1.5 flex flex-wrap gap-1">
                <span
                  v-for="row in g.rows"
                  :key="row.id"
                  class="text-[11px] font-bold px-2 py-0.5 rounded-full border"
                  :class="row.company_id === selectedCatalogCompanyId
                    ? 'bg-brand-600 text-white border-brand-600'
                    : 'bg-brand-50 text-brand-700 border-brand-100'"
                  :title="row.is_active ? 'ใช้งาน' : 'ปิดใช้งานในบริษัทนี้'"
                >{{ ownerLabel(row.company_id, 'brand') }}<template v-if="!row.is_active"> (ปิด)</template></span>
              </div>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <!-- products_count summed across every company holding this name. -->
              <span class="text-[11px] text-slate-400 whitespace-nowrap">ใช้กับสินค้า {{ g.totalProducts }}</span>
              <span :class="refStatusClass(g.rows)" class="text-xs font-bold whitespace-nowrap">{{ refStatusLabel(g.rows) }}</span>
              <!-- TASK-245 — hidden when nothing in this card is the viewer's
                   to change. A card holding ONLY the platform's brand offers a
                   Company Admin no buttons; one holding both still offers them,
                   and the save touches only their own row. -->
              <button v-if="g.canEditAny" class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditBrand(g)">
                <Icon name="pencil" :size="14" />
              </button>
              <button v-if="g.canDeleteAny" class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteBrand(g)">
                <Icon name="trash" :size="14" />
              </button>
              <span v-if="!g.canEditAny && !g.canDeleteAny" class="text-[11px] text-slate-400 whitespace-nowrap" title="แบรนด์กลางแก้ไขได้เฉพาะ Super Admin">อ่านอย่างเดียว</span>
            </div>
          </div>
        </div>
      </TransitionGroup>
    </section>

    <!-- Categories -->
    <section v-if="refDrawerTab === 'categories'" class="mt-4">
      <!-- TASK-202 — mirrors the brands toolbar above, deliberately identical:
           two adjacent tabs behaving differently would be its own bug. Its
           create button moved to the title row in the same change. -->
      <div class="mb-2">
        <div class="relative">
          <Icon name="search" :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input v-model="refSearch" type="text" placeholder="ค้นหาหมวดหมู่..." class="w-full h-[34px] pl-8 pr-3 rounded-lg border border-slate-200 text-sm" />
        </div>
      </div>
      <form v-if="showCategoryForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 space-y-3" @submit.prevent="submitCategory">
        <div>
          <label class="text-xs font-bold text-slate-500">ชื่อหมวดหมู่</label>
          <input v-model="categoryForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <!-- Same question, same shape as the brands tab one screen away:
             two adjacent tabs behaving differently would be its own bug. -->
        <div v-if="isSuperAdmin" class="rounded-lg border border-brand-100 bg-brand-50 p-3">
          <p class="text-xs font-bold text-brand-700">บันทึกเป็น</p>
          <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
            <label
              class="flex items-start gap-2 p-2.5 rounded-lg border bg-white cursor-pointer transition-colors"
              :class="categoryCreateAsPlatform ? 'border-slate-200' : 'border-brand-500 ring-2 ring-brand-100'"
            >
              <input v-model="categoryCreateAsPlatform" type="radio" :value="false" data-test="category-owner-company" class="mt-0.5 shrink-0" />
              <span class="min-w-0">
                <span class="block text-sm font-bold text-slate-800">หมวดหมู่ของบริษัท</span>
                <span class="block mt-0.5 text-[11px] text-slate-500">สร้างแยกให้ทุกบริษัทที่ติ๊กไว้ (บริษัทละ 1 รายการ) · บริษัทอื่นไม่เห็น</span>
              </span>
            </label>
            <label
              class="flex items-start gap-2 p-2.5 rounded-lg border bg-white cursor-pointer transition-colors"
              :class="categoryCreateAsPlatform ? 'border-brand-500 ring-2 ring-brand-100' : 'border-slate-200'"
            >
              <input v-model="categoryCreateAsPlatform" type="radio" :value="true" data-test="category-owner-platform" class="mt-0.5 shrink-0" />
              <span class="min-w-0">
                <span class="block text-sm font-bold text-slate-800">หมวดกลาง (ทุกบริษัทใช้ร่วมกัน)</span>
                <span class="block mt-0.5 text-[11px] text-slate-500">รายการเดียว ทุกบริษัทเลือกใช้ได้ตอนเพิ่มสินค้า · แก้ครั้งเดียว เปลี่ยนพร้อมกันทุกบริษัท</span>
              </span>
            </label>
          </div>
        </div>
        <CompanyMultiSelect
          v-if="isSuperAdmin && !categoryCreateAsPlatform"
          v-model="categoryCompanyIds"
          :options="companyOptions"
          label="สร้างในบริษัท (เลือกได้หลายบริษัท)"
          placeholder="ติ๊กเลือกบริษัท..."
        />
        <div>
          <label class="text-xs font-bold text-slate-500 block mb-1">ไอคอน (ไม่บังคับ — แสดงบนหน้าร้าน Agent Portal)</label>
          <IconPicker v-model="categoryForm.icon" fallback-icon="box" fallback-label="ยังไม่ได้เลือกไอคอน" clear-label="ล้างไอคอน" />
        </div>
        <div class="flex justify-end">
          <button type="submit" :disabled="savingCategory" class="btn-primary">{{ savingCategory ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
        </div>
      </form>
      <p v-if="categoryFormError" class="mb-3 text-xs font-bold text-rose-600">{{ categoryFormError }}</p>
      <EmptyState v-if="!categoryGroups.length" icon="layers" :title="refSearch ? 'ไม่พบหมวดหมู่ที่ค้นหา' : 'ยังไม่มีหมวดหมู่'" />
      <!-- TASK-204 — same name-first card as the brands tab. -->
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div
          v-for="g in categoryGroups"
          :key="g.key"
          class="border rounded-xl p-4"
          :class="editingCategoryKey === g.key ? 'bg-white border-brand-500 ring-2 ring-brand-100' : 'bg-white/95 border-slate-200'"
        >
          <template v-if="editingCategoryKey === g.key">
            <div class="space-y-3">
              <p class="text-[11px] font-bold text-brand-700">กำลังแก้ไข · {{ g.name }}</p>
              <!-- TASK-245 — see the brand form above. -->
              <p v-if="g.hasPlatformRow && !isSuperAdmin" class="text-[11px] text-amber-600">
                ชื่อนี้มีหมวดกลางอยู่ด้วย — การแก้ไขนี้จะมีผลเฉพาะหมวดหมู่ของบริษัทคุณ ส่วนหมวดกลางแก้ไขได้เฉพาะ Super Admin
              </p>
              <div>
                <label class="text-xs font-bold text-slate-500">ชื่อหมวดหมู่</label>
                <input v-model="editCategoryForm.name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <CompanyMultiSelect
                v-if="isSuperAdmin"
                v-model="editCategoryCompanyIds"
                :options="companyOptions"
                label="บริษัทที่มีหมวดหมู่นี้ (ติ๊กเพิ่ม = สร้างให้, เอาติ๊กออก = ลบออกจากบริษัทนั้น)"
                placeholder="ติ๊กเลือกบริษัท..."
              />
              <div>
                <label class="text-xs font-bold text-slate-500 block mb-1">ไอคอน (ไม่บังคับ — แสดงบนหน้าร้าน Agent Portal)</label>
                <IconPicker v-model="editCategoryForm.icon" fallback-icon="box" fallback-label="ยังไม่ได้เลือกไอคอน" clear-label="ล้างไอคอน" />
              </div>
              <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                  <label class="text-xs font-bold text-slate-500">ลำดับ</label>
                  <input v-model.number="editCategoryForm.sort_order" type="number" min="0" class="w-20 px-2 py-1.5 rounded-lg border border-slate-200 text-sm" />
                </div>
                <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer ml-auto">
                  <input v-model="editCategoryForm.is_active" type="checkbox" class="rounded border-slate-300" /> ใช้งาน (ทุกบริษัทที่ติ๊กไว้)
                </label>
              </div>
              <p v-if="editCategoryError" class="text-xs font-bold text-rose-600">{{ editCategoryError }}</p>
              <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="cancelEditCategory">ยกเลิก</button>
                <button type="button" :disabled="savingCategoryEdit" class="btn-primary" @click="saveEditCategory">
                  {{ savingCategoryEdit ? 'กำลังบันทึก...' : 'บันทึก' }}
                </button>
              </div>
            </div>
          </template>
          <div v-else class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
              <span class="w-9 h-9 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center shrink-0">
                <Icon :name="g.rows[0]?.icon || 'layers'" :size="16" class="text-slate-600" />
              </span>
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 truncate">{{ g.name }}</p>
                <div v-if="isSuperAdmin" class="mt-1.5 flex flex-wrap gap-1">
                  <span
                    v-for="row in g.rows"
                    :key="row.id"
                    class="text-[11px] font-bold px-2 py-0.5 rounded-full border"
                    :class="row.company_id === selectedCatalogCompanyId
                      ? 'bg-brand-600 text-white border-brand-600'
                      : 'bg-brand-50 text-brand-700 border-brand-100'"
                    :title="row.is_active ? 'ใช้งาน' : 'ปิดใช้งานในบริษัทนี้'"
                  >{{ ownerLabel(row.company_id, 'category') }}<template v-if="!row.is_active"> (ปิด)</template></span>
                </div>
              </div>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <span class="text-[11px] text-slate-400 whitespace-nowrap">ใช้กับสินค้า {{ g.totalProducts }}</span>
              <span :class="refStatusClass(g.rows)" class="text-xs font-bold whitespace-nowrap">{{ refStatusLabel(g.rows) }}</span>
              <!-- TASK-245 — see the brand card above. -->
              <button v-if="g.canEditAny" class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditCategory(g)">
                <Icon name="pencil" :size="14" />
              </button>
              <button v-if="g.canDeleteAny" class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteCategory(g)">
                <Icon name="trash" :size="14" />
              </button>
              <span v-if="!g.canEditAny && !g.canDeleteAny" class="text-[11px] text-slate-400 whitespace-nowrap" title="หมวดหมู่กลางแก้ไขได้เฉพาะ Super Admin">อ่านอย่างเดียว</span>
            </div>
          </div>
        </div>
      </TransitionGroup>
    </section>

          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Products -->
    <section v-if="activeTab === 'products' || refDrawerOpen" class="mt-4">
      <!-- TASK-102 — toolbar. Brand/category became FILTERS here because
           narrowing the list is what an admin actually wanted from them
           day to day; the gear opens the drawer for the rare edit. The
           package list had no filter at all before this. -->
      <!-- 2026-09-09 — the two kinds of package live in one list and the
           actions on them are not equivalent. Being able to look at one kind
           at a time is the cheapest half of telling them apart; the badge and
           the delete button are the other half. -->
      <div v-if="isSuperAdmin" class="mb-2 inline-flex rounded-lg bg-slate-100 p-0.5">
        <button
          v-for="opt in [
            { key: 'all', label: 'ทั้งหมด' },
            { key: 'platform', label: 'สินค้ากลาง' },
            { key: 'company', label: 'ของบริษัทนี้' },
          ]"
          :key="opt.key"
          type="button"
          :data-test="`owner-filter-${opt.key}`"
          class="px-3 py-1.5 rounded-md text-xs font-bold transition-colors"
          :class="ownerFilter === opt.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
          @click="ownerFilter = opt.key as OwnerFilter"
        >
          {{ opt.label }}
        </button>
      </div>
      <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[180px]">
          <Icon name="search" :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            v-model="productFilters.q"
            type="text"
            placeholder="ค้นหาแพ็กเกจ"
            class="w-full h-[40px] pl-8 pr-3 rounded-lg border border-slate-200 text-sm"
          />
        </div>
        <!-- TASK-209 — in ทุกบริษัท mode the same brand/category name exists
             once per company; group by company so the options are
             distinguishable. Scoped mode has one group, so it renders flat. -->
        <select v-model.number="productFilters.brandId" class="h-[40px] px-3 rounded-lg border border-slate-200 text-sm">
          <option :value="null">แบรนด์: ทั้งหมด</option>
          <template v-if="activeCompany.isAllCompanies">
            <optgroup v-for="g in brandFilterGroups" :key="g.key" :label="g.companyName">
              <option v-for="b in g.items" :key="b.id" :value="b.id">{{ g.companyName }} · {{ b.name }}</option>
            </optgroup>
          </template>
          <option v-for="b in brands" v-else :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
        <select v-model.number="productFilters.categoryId" class="h-[40px] px-3 rounded-lg border border-slate-200 text-sm">
          <option :value="null">หมวดหมู่: ทั้งหมด</option>
          <template v-if="activeCompany.isAllCompanies">
            <optgroup v-for="g in categoryFilterGroups" :key="g.key" :label="g.companyName">
              <option v-for="c in g.items" :key="c.id" :value="c.id">{{ g.companyName }} · {{ c.name }}</option>
            </optgroup>
          </template>
          <option v-for="c in categories" v-else :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <!-- 2026-08-18 (human report) — Super Admin's package list is the
             same flat cross-company response the Banner product-picker
             already had to filter (TenantScope skips the company filter
             entirely for Super Admin). Hidden for Company Admin: they only
             ever see their own one company here, so a company filter would
             have exactly one option and answer nothing. -->
        <button
          v-if="productFilterCount"
          class="h-[40px] px-3 rounded-lg text-xs font-bold text-slate-500 hover:text-slate-700"
          @click="clearProductFilters"
        >
          ล้างตัวกรอง
        </button>
        <button class="btn-secondary flex items-center gap-1.5" @click="openRefDrawer('brands')">
          <Icon name="settings" :size="14" /> จัดการแบรนด์ / หมวดหมู่
        </button>
        <RouterLink :to="{ name: 'product-create' }" class="btn-primary">
          + เพิ่มแพ็กเกจ
        </RouterLink>
      </div>

      <EmptyState v-if="!products.length" icon="cube" title="ยังไม่มีแพ็กเกจ" message="ต้องมีแบรนด์และหมวดหมู่ก่อนจึงเพิ่มแพ็กเกจได้" />
      <!-- A filtered-to-empty list is NOT the same state as an empty
           catalog: telling an admin to "add a brand first" when they have
           50 packages and a typo in the search box is actively wrong. -->
      <EmptyState
        v-else-if="!filteredProducts.length"
        icon="search"
        title="ไม่พบแพ็กเกจที่ตรงกับตัวกรอง"
        message="ลองล้างตัวกรองหรือค้นหาด้วยคำอื่น"
      />
      <!-- TASK-256 / ADR-040 — one row per PRODUCT, not per company copy. A
           shared product's price and on-sale state belong to the company in
           the header switcher, so both are labelled with that company's name;
           in ทุกบริษัท mode neither exists and the row falls back to the
           central price alone. -->
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <!--
          2026-09-09 — a closed product reads as closed at a glance.

          Not hidden and not disabled: the reason to look at a closed row is to
          change something about it. It is only visibly not part of today's
          shop — the text drops to grey and the photograph loses its colour,
          which the eye sorts far faster than it reads a status word.
        -->
        <div
          v-for="p in filteredProducts"
          :key="p.id"
          class="border rounded-xl p-4 flex items-center justify-between gap-3 transition-colors"
          :class="p.is_sellable_here ? 'bg-white/95 border-slate-200' : 'bg-slate-50/60 border-slate-100'"
          :data-test="p.is_sellable_here ? 'product-row-open' : 'product-row-closed'"
        >
          <!--
            2026-09-09 — the primary image, in front of the name.

            52px is the height of the three lines beside it, measured rather
            than guessed: the name (text-sm, 20px line box) + brand · category
            (text-xs, 16px) + the sell-status line (text-xs, 16px). A square at
            exactly that height sits flush with the text block instead of
            dragging every row taller, which either of Tailwind's neighbouring
            h-14 / h-16 steps would have done.

            `shrink-0` because the name beside it can be long: without it flex
            would take the space out of the image and leave a sliver.
          -->
          <div class="flex items-center gap-3 min-w-0">
          <div
            class="w-[52px] h-[52px] rounded-lg overflow-hidden border border-slate-200 bg-slate-50 shrink-0 flex items-center justify-center transition-all"
            :class="p.is_sellable_here ? '' : 'grayscale opacity-60'"
          >
            <AuthenticatedMedia
              v-if="p.thumbnail_url"
              :src="p.thumbnail_url"
              type="image"
              class="w-full h-full object-cover"
              data-test="product-thumbnail"
            />
            <!-- A product with no photo yet keeps the same 52px box rather
                 than collapsing it: names starting at two different x
                 positions read as two lists instead of one. -->
            <Icon v-else name="image" :size="18" class="text-slate-300" />
          </div>
          <div class="min-w-0">
            <p class="text-sm font-bold flex items-center gap-2" :class="p.is_sellable_here ? 'text-slate-900' : 'text-slate-400'">
              <span class="truncate">{{ p.name }}</span>
              <span v-if="p.is_shared" class="shrink-0 px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-600 text-[10px] font-bold" title="สินค้ากลาง — ทุกบริษัทใช้รายการเดียวกัน แต่ตั้งราคาและเปิด/ปิดขายเองได้">
                สินค้ากลาง
              </span>
            </p>
            <p class="text-xs" :class="p.is_sellable_here ? 'text-slate-400' : 'text-slate-300'">
              {{ p.brand?.name }} · {{ p.category?.name }}
              <!-- Company name only for Super Admin (2026-08-18, human
                   report): they're the only role who sees rows spanning
                   more than one company here, so it's the only role this
                   label disambiguates anything for — see companyName(). -->
              <template v-if="isSuperAdmin && companyName(p.company_id)"> · {{ companyName(p.company_id) }}</template>
            </p>
            <!-- The sentence a Company Admin actually needs: not "ใช้งาน",
                 which is the platform's state, but whether THEIR company is
                 selling it. Never inherited, so it is never a guess. -->
            <p v-if="p.is_shared && scopedCompanyLabel" class="mt-0.5 text-xs" :class="p.is_sellable_here ? 'text-emerald-600' : 'text-slate-400'">
              {{ p.is_sellable_here ? `ขายอยู่ที่ ${scopedCompanyLabel}` : `ยังไม่เปิดขายที่ ${scopedCompanyLabel}` }}
            </p>
          </div>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <!-- A shared product that the platform switched off is off
                 everywhere, whatever a company set — say so rather than
                 letting the per-company line above read as the whole truth. -->
            <span v-if="!p.is_active" class="text-xs font-bold text-slate-400" title="ปิดที่ระดับสินค้า — ปิดทุกบริษัท">ปิดใช้งาน</span>
            <span v-else-if="!p.is_shared" class="text-xs font-bold text-emerald-600">ใช้งาน</span>

            <div class="text-right">
              <span class="text-sm font-bold" :class="p.is_sellable_here ? 'text-slate-900' : 'text-slate-400'">{{ formatSatang(p.is_shared && scopedCompanyLabel ? p.effective_price_satang : p.price_satang) }}</span>
              <!-- An inherited price is not the same number as a chosen one:
                   it moves the next time the central price is edited. -->
              <p v-if="p.is_shared && scopedCompanyLabel && p.own_price_satang === null" class="text-[10px] text-slate-400 leading-tight">
                (ราคากลาง)
              </p>
            </div>

            <button
              v-if="isSuperAdmin && p.is_shared && scopedCompanyLabel"
              class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200"
              :title="`ตั้งราคาเฉพาะของ ${scopedCompanyLabel}`"
              @click="openCompanySetting(p)"
            >
              ตั้งราคา
            </button>

            <!--
              2026-09-09 (human: "เพิ่ม icon ดาวปักหมุด ในหน้านี้เลยไม่ต้อง
              เข้าไปข้างใน") — the same pin that used to be three clicks in.

              Disabled when the product is not on sale here, because the server
              refuses it (ValidatesProductOwnership: a pin is a storefront
              promise and needs the sell grant). Disabled with the REASON in
              the tooltip, rather than hidden — a star that vanishes teaches
              nothing, and this is the exact pairing the human asked for: close
              the sale, the pin goes out.
            -->
            <button
              v-if="canUpdate(p) && scopedCompanyLabel"
              type="button"
              data-test="toggle-pin"
              class="w-8 h-8 rounded-lg flex items-center justify-center transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              :class="isPinned(p) ? 'bg-amber-50 text-amber-500 hover:bg-amber-100' : 'text-slate-300 hover:text-amber-500 hover:bg-slate-100'"
              :disabled="pinningId !== null || !p.is_sellable_here"
              :title="!p.is_sellable_here
                ? 'ปักหมุดไม่ได้ — สินค้านี้ยังไม่เปิดขายที่บริษัทนี้'
                : (isPinned(p)
                  ? `เอาออกจากแถว “แนะนำสำหรับคุณ” ของ ${scopedCompanyLabel}`
                  : `ปักหมุดขึ้นแถว “แนะนำสำหรับคุณ” ของ ${scopedCompanyLabel}`)"
              @click="togglePin(p)"
            >
              <Icon name="star" :size="16" />
            </button>

            <!-- TASK-245 — the server's answer, not `is_shared`.
                 `is_shared` misses a catalog-LINKED product: it still belongs
                 to this company, and every write to it is still refused
                 (ADR-036 §5/§6), so the delete button used to be offered and
                 then 403'd. The detail page stays reachable either way — it
                 renders read-only — so the link is relabelled rather than
                 removed. -->
            <RouterLink
              :to="{ name: 'product-edit', params: { id: p.id } }"
              class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200 flex items-center gap-1"
              :title="canUpdate(p) ? (p.is_shared ? 'แก้ไขสินค้ากลาง — มีผลกับทุกบริษัท' : 'แก้ไข') : 'ดูรายละเอียด (แก้ไขได้เฉพาะ Super Admin)'"
            >
              <Icon :name="canUpdate(p) ? 'pencil' : 'search'" :size="12" /> {{ canUpdate(p) ? 'แก้ไข' : 'ดู' }}
            </RouterLink>
            <!-- 2026-09-09 (human: "ผู้ใช้ไม่ทราบ ควรแยกกัน") — a bare grey
                 trash icon cannot say "ทุกบริษัท", and for a shared package
                 that is the entire difference between the two acts. So the
                 shared one is a labelled, outlined button and the ordinary
                 one stays exactly the icon it has always been. -->
            <button
              v-if="canDelete(p) && p.is_shared"
              data-test="delete-platform-product"
              class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-rose-200 text-rose-600 text-xs font-bold hover:bg-rose-50 whitespace-nowrap"
              title="ลบสินค้ากลาง — หายจากทุกบริษัทพร้อมกัน"
              @click="deleteProduct(p)"
            >
              <Icon name="trash" :size="12" /> ลบสินค้ากลาง
            </button>
            <button
              v-else-if="canDelete(p)"
              data-test="delete-company-product"
              class="text-slate-400 hover:text-rose-600"
              title="ลบ"
              @click="deleteProduct(p)"
            >
              <Icon name="trash" :size="14" />
            </button>
            <!-- A Company Admin looking at a shared package has no delete, no
                 price control and no on/off switch — by design (ADR-040), but
                 an empty row reads as a broken screen rather than a rule. -->
            <span v-else-if="p.is_shared" class="text-[11px] text-slate-400 whitespace-nowrap" title="สินค้ากลางจัดการโดยผู้ดูแลระบบ">
              จัดการโดยผู้ดูแลระบบ
            </span>

            <!--
              2026-09-09 (human: "ปรับเมนูเปิด ปิด สินค้าให้เป็นสวิทซ์ …
              เลื่อนที่ช่องสุดท้ายต่อจากลบสินค้า").

              Last in the row, and a switch rather than a button. A button
              labelled "ปิดขาย" states the ACTION it performs; a switch shows
              the STATE it is in — and the state is what the admin is scanning
              a list of twenty products for. It is also the one control here
              that takes effect the moment it is touched, with no dialog: a
              switch is the shape people already read that way.

              Closing the sale also switches this company's pin off, server
              side — which is why the star beside it goes dark in the same
              paint (see toggleSellHere).
            -->
            <button
              v-if="isSuperAdmin && p.is_shared && scopedCompanyLabel"
              type="button"
              data-test="sell-here-switch"
              class="relative w-12 h-6 shrink-0 rounded-full border transition-colors disabled:opacity-50"
              :class="p.is_sellable_here ? 'bg-brand-600 border-brand-600' : 'bg-white border-slate-300'"
              :disabled="togglingSellHere.includes(p.id)"
              :title="p.is_sellable_here ? `กำลังขายที่ ${scopedCompanyLabel} — แตะเพื่อปิดขาย` : `ยังไม่เปิดขายที่ ${scopedCompanyLabel} — แตะเพื่อเปิดขาย`"
              @click="toggleSellHere(p)"
            >
              <span
                class="absolute top-0.5 bottom-0.5 w-5 rounded-full shadow transition-all duration-200"
                :class="p.is_sellable_here ? 'translate-x-[26px] bg-white' : 'translate-x-0.5 bg-slate-300'"
              ></span>
            </button>
          </div>
        </div>
      </TransitionGroup>

      <!-- TASK-256 — the per-company price. Deliberately a separate action
           from "เปิดขาย": opening a product for sale must not also decide
           what it costs, and clearing the override is a real instruction
           (null), not an empty field. -->
      <div v-if="companySettingProduct" class="fixed inset-0 z-40 bg-slate-900/40 flex items-center justify-center p-4" @click.self="closeCompanySetting()">
        <div class="w-full max-w-md bg-white rounded-2xl p-5 shadow-xl">
          <p class="text-sm font-bold text-slate-900">ตั้งราคาของ {{ scopedCompanyLabel }}</p>
          <p class="mt-0.5 text-xs text-slate-400">{{ companySettingProduct.name }} · ราคากลาง {{ formatSatang(companySettingProduct.price_satang) }}</p>

          <label class="mt-4 flex items-start gap-2 text-sm text-slate-700">
            <input v-model="companySettingInherit" type="checkbox" class="mt-0.5" />
            <span>
              ใช้ราคากลาง
              <span class="block text-xs text-slate-400">ถ้า Super Admin แก้ราคากลางในอนาคต บริษัทนี้จะเปลี่ยนตามด้วย</span>
            </span>
          </label>

          <div v-if="!companySettingInherit" class="mt-3">
            <label class="text-xs font-bold text-slate-500">ราคาของบริษัทนี้ (บาท)</label>
            <input
              v-model="companySettingPriceBaht"
              type="number"
              min="0"
              step="0.01"
              class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
            />
          </div>

          <p v-if="companySettingError" class="mt-2 text-xs text-rose-600">{{ companySettingError }}</p>

          <div class="mt-5 flex justify-end gap-2">
            <button class="btn-secondary" :disabled="savingCompanySetting" @click="closeCompanySetting()">ยกเลิก</button>
            <button class="btn-primary" :disabled="savingCompanySetting" @click="saveCompanySetting()">
              {{ savingCompanySetting ? 'กำลังบันทึก…' : 'บันทึก' }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- Banners (TASK-068 / ADR-020 row 2) -->
    <!-- ── ถังขยะ (2026-09-09) ─────────────────────────────────────────
         One list per kind, newest deletion first, each row saying when it
         went and offering the one action a bin has. Restore is per row
         rather than a bulk "undo everything": what got hidden by mistake is
         usually one thing, and un-hiding the rest silently would be its own
         surprise. -->
    <section v-if="activeTab === 'trash'" class="mt-4">
      <div class="mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-500 leading-relaxed">
        รายการที่ลบไปจะถูก<span class="font-bold text-slate-700">ซ่อน</span> ไม่ได้ถูกลบถาวร —
        กู้คืนกลับมาใช้งานได้ทุกเมื่อ ราคาและการตั้งค่าของแต่ละบริษัทยังอยู่ครบ
      </div>

      <p v-if="trashError" class="mb-3 text-xs font-bold text-rose-600">{{ trashError }}</p>
      <LoadingSkeleton v-if="loadingTrash" type="list" />
      <EmptyState v-else-if="!trashCount" icon="trash" title="ถังขยะว่าง" description="ยังไม่มีสินค้า แบรนด์ หรือหมวดหมู่ที่ถูกลบ" />

      <template v-else>
        <template
          v-for="bucket in [
            { key: 'products', title: 'แพ็กเกจ', icon: 'cube', rows: trash.products, endpoint: 'products', kind: 'product' },
            { key: 'brands', title: 'แบรนด์', icon: 'tag', rows: trash.brands, endpoint: 'brands', kind: 'brand' },
            { key: 'categories', title: 'หมวดหมู่', icon: 'layers', rows: trash.categories, endpoint: 'product-categories', kind: 'category' },
          ]"
          :key="bucket.key"
        >
          <div v-if="bucket.rows.length" class="mb-5">
            <p class="mb-2 text-xs font-bold text-slate-500 flex items-center gap-1.5">
              <Icon :name="bucket.icon" :size="13" /> {{ bucket.title }} ({{ bucket.rows.length }})
            </p>
            <div class="space-y-2">
              <div
                v-for="row in bucket.rows"
                :key="row.id"
                class="bg-white/95 border border-slate-200 rounded-xl p-3 flex items-center justify-between gap-3"
                :data-test="`trash-${bucket.key}-row`"
              >
                <div class="min-w-0">
                  <p class="text-sm font-bold text-slate-700 flex items-center gap-2">
                    <span class="truncate">{{ row.name }}</span>
                    <!-- The bin holds all three kinds, so the badge names
                         the one this row actually is. -->
                    <span v-if="row.company_id === null" class="shrink-0 px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-600 text-[10px] font-bold">
                      {{ PLATFORM_LABEL[bucket.kind as CatalogKind] }}
                    </span>
                  </p>
                  <p class="text-[11px] text-slate-400">
                    {{ deletedAgo(row.deleted_at) }}
                    <template v-if="isSuperAdmin && companyName(row.company_id)"> · {{ companyName(row.company_id) }}</template>
                  </p>
                </div>
                <button
                  v-if="canRestore(row)"
                  type="button"
                  data-test="restore-row"
                  :disabled="restoringId !== null"
                  class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-xs font-bold hover:bg-emerald-100 disabled:opacity-50"
                  @click="restoreRow(bucket.endpoint as 'products' | 'brands' | 'product-categories', row.id)"
                >
                  <Icon name="refresh" :size="12" />
                  {{ restoringId === `${bucket.endpoint}:${row.id}` ? 'กำลังกู้คืน…' : 'กู้คืน' }}
                </button>
                <!-- A platform row is listed for a Company Admin on purpose —
                     hiding it would make the catalogue look as though the
                     product simply ceased to exist — but the button that
                     would 403 is replaced by the reason. -->
                <span v-else class="shrink-0 text-[11px] text-slate-400 whitespace-nowrap">
                  กู้คืนได้เฉพาะผู้ดูแลระบบ
                </span>
              </div>
            </div>
          </div>
        </template>
      </template>
    </section>

    <section v-if="activeTab === 'banners'" class="mt-4">
      <p class="text-xs text-slate-400 mb-2">
        แบนเนอร์หน้าร้าน Agent Portal (สไลด์ได้ 3 ตำแหน่ง) — คลิกแล้วพาไปหน้าสินค้า, URL ภายนอก, หรือหน้าภายในระบบ ตามที่ตั้งค่าไว้ · เลือกหลายรูปพร้อมกันเพื่อสร้างหลายแบนเนอร์ในครั้งเดียว
      </p>
      <div class="flex justify-end mb-2">
        <button
          class="btn-primary"
          @click="showBannerForm ? closeBannerForm() : openCreateBannerForm()"
        >
          + เพิ่มแบนเนอร์
        </button>
      </div>

      <form v-if="showBannerForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 space-y-3" @submit.prevent="submitBannerForm">
        <div>
          <!-- TASK-073 — human-confirmed via AskUserQuestion (2026-08-02):
               3 link target types. -->
          <label class="text-xs font-bold text-slate-500">คลิกแล้วไปที่</label>
          <select v-model="bannerForm.link_type" required class="mt-1 w-full sm:w-64 px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
            <option v-for="opt in BANNER_LINK_TYPE_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div v-if="bannerForm.link_type === 'product'">
            <label class="text-xs font-bold text-slate-500">สินค้า</label>
            <select v-model="bannerForm.product_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="" disabled>เลือกสินค้า</option>
              <option v-for="p in bannerCompanyProducts" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <p v-if="isSuperAdmin && !selectedCatalogCompanyId" class="mt-1 text-[11px] text-amber-600">เลือกบริษัทด้านบนก่อนจึงจะเลือกสินค้าได้</p>
          </div>
          <div v-else-if="bannerForm.link_type === 'url'">
            <label class="text-xs font-bold text-slate-500">URL ปลายทาง</label>
            <input v-model="bannerForm.external_url" type="url" required placeholder="https://example.com/promo" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            <p class="mt-1 text-[11px] text-slate-400">เปิดในแท็บใหม่ — ใส่เองได้อิสระ (เฉพาะ Admin/Super Admin)</p>
          </div>
          <div v-else>
            <label class="text-xs font-bold text-slate-500">หน้าภายในระบบ</label>
            <select v-model="bannerForm.internal_path" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="" disabled>เลือกหน้าปลายทาง</option>
              <option v-for="opt in BANNER_INTERNAL_PATH_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">หัวข้อ (ไม่บังคับ)</label>
            <input v-model="bannerForm.title" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <!-- TASK-072 — human-confirmed via AskUserQuestion (2026-08-02):
                 3 fixed placement spots on ProductBrowseView.vue. -->
            <label class="text-xs font-bold text-slate-500">ตำแหน่งแสดง</label>
            <select v-model="bannerForm.placement" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option v-for="opt in BANNER_PLACEMENT_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">ลำดับการแสดง</label>
            <input v-model.number="bannerForm.sort_order" type="number" min="0" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div class="flex items-end">
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer">
              <input v-model="bannerForm.is_active" type="checkbox" class="rounded border-slate-300" /> ใช้งาน
            </label>
          </div>
        </div>

        <div>
          <label class="text-xs font-bold text-slate-500">
            รูปแบนเนอร์{{ editingBannerId ? ' (ไม่บังคับ — เว้นว่างถ้าไม่เปลี่ยนรูป)' : ' (เลือกได้หลายรูป — แต่ละรูปจะกลายเป็นแบนเนอร์แยกกัน)' }}
          </label>
          <p class="text-[11px] text-slate-400 mt-0.5">สูงสุด {{ formatBannerMb(BANNER_IMAGE_MAX_BYTES) }} MB ต่อรูป — ระบบจะย่อขนาดให้อัตโนมัติถ้าเกิน</p>
          <div v-if="compressingBannerImage" class="mt-1 flex items-center justify-center gap-1.5 h-24 w-40 rounded-lg border border-dashed border-slate-300 text-slate-400 text-xs font-bold">
            <Icon name="refresh" :size="16" class="animate-spin" /> กำลังบีบอัด...
          </div>
          <div v-else class="mt-1 flex flex-wrap items-start gap-2">
            <div v-if="existingBannerImageUrl && !bannerImagePreviewUrls.length" class="relative w-fit">
              <img :src="existingBannerImageUrl" class="h-24 w-40 object-cover rounded-lg border border-slate-200" />
              <button
                type="button"
                class="absolute -top-2 -right-2 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center hover:bg-rose-700"
                @click="clearExistingBannerImage"
              >
                <Icon name="x" :size="12" />
              </button>
            </div>
            <div v-for="(url, idx) in bannerImagePreviewUrls" :key="url" class="relative w-fit">
              <img :src="url" class="h-24 w-40 object-cover rounded-lg border border-slate-200" />
              <button
                type="button"
                class="absolute -top-2 -right-2 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center hover:bg-rose-700"
                @click="removeBannerImageAt(idx)"
              >
                <Icon name="x" :size="12" />
              </button>
            </div>
            <label
              v-if="editingBannerId ? bannerImagePreviewUrls.length === 0 : true"
              class="flex items-center justify-center gap-1.5 h-24 w-40 rounded-lg border border-dashed border-slate-300 text-slate-400 hover:text-slate-600 hover:border-slate-400 cursor-pointer text-xs font-bold"
            >
              <Icon name="image" :size="18" /> อัปโหลดรูป
              <input type="file" accept="image/jpeg,image/png,image/webp" :multiple="!editingBannerId" class="hidden" @change="onBannerImageChange" />
            </label>
          </div>
          <p v-if="bannerImageSizeError" class="text-[11px] text-rose-600 mt-1">{{ bannerImageSizeError }}</p>
        </div>

        <p v-if="bannerFormError" class="text-xs font-bold text-rose-600">{{ bannerFormError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="closeBannerForm">ยกเลิก</button>
          <button type="submit" :disabled="savingBanner || compressingBannerImage" class="btn-primary">
            {{ savingBanner ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>

      <EmptyState
        v-if="!banners.length"
        icon="image"
        title="ยังไม่มีแบนเนอร์"
        message="เพิ่มแบนเนอร์แรกเพื่อแสดงในหน้าร้าน Agent Portal"
        cta-label="+ เพิ่มแบนเนอร์แรก"
        :cta-disabled="false"
        @cta="openCreateBannerForm"
      />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div v-for="b in banners" :key="b.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center gap-3">
          <img v-if="b.image_url" :src="b.image_url" class="w-20 h-12 object-cover rounded-lg border border-slate-200 shrink-0" />
          <div v-else class="w-20 h-12 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center shrink-0">
            <Icon name="image" :size="16" class="text-slate-300" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-slate-900 truncate">{{ b.title || b.product?.name || '(ไม่มีหัวข้อ)' }}</p>
            <p class="text-xs text-slate-400 truncate">{{ bannerLinkTargetLabel(b) }} · {{ bannerPlacementLabel(b.placement) }} · ลำดับ {{ b.sort_order }}</p>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <span :class="b.is_active ? 'text-emerald-600' : 'text-slate-400'" class="text-xs font-bold">{{ b.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span>
            <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="openEditBannerForm(b)">
              <Icon name="pencil" :size="14" />
            </button>
            <button class="text-rose-600 hover:text-rose-700" title="ลบ" @click="deleteBanner(b)">
              <Icon name="trash" :size="14" />
            </button>
          </div>
        </div>
      </TransitionGroup>
    </section>

    <!-- Commission rules -->
    <!-- ═══ TASK-213 Phase 3 — both commission tabs MOVED ═══
         `commission_rules` was editable from four different forms
         (the wizard, แผนคอมมิชชั่น → กฎคอมมิชชั่น, this tab, and the
         product edit page) and they did not have the same powers: only
         แผนคอมมิชชั่น could author a หมวดหมู่- or company-wide rule, and
         the form here could only ever do a single product. Four doors to
         one table, three of them narrower than the widest, with nothing
         on screen saying so.

         The leader-rate tab was worse: it was the ONLY place that rate
         could be set anywhere in the product, and it was filed under the
         product catalogue — so the one screen named after commissions did
         not contain it.

         The sections are not hidden, they are gone. What stays is this
         signpost, because deep links (?tab=commission_rules) and muscle
         memory both still land here. -->
    <section v-if="activeTab === 'commission_rules' || activeTab === 'override_rules'" class="mt-4">
      <div class="p-5 rounded-xl bg-white/95 border border-slate-200">
        <div class="flex items-start gap-3">
          <span class="w-10 h-10 rounded-xl bg-brand-50 text-brand-700 flex items-center justify-center shrink-0">
            <Icon name="money" :size="18" />
          </span>
          <div class="min-w-0">
            <p class="text-sm font-bold text-slate-900">ย้ายไปหน้า "แผนคอมมิชชั่น" แล้ว</p>
            <p class="mt-1 text-xs text-slate-500 leading-relaxed">
              อัตราค่าคอมของ<b>ตัวแทนผู้ขาย</b>และของ<b>หัวหน้าทีม</b> ตอนนี้อยู่ในหน้าเดียวกัน
              ที่แท็บ "อัตราค่าคอม" — กรองด้วยปุ่ม <b>ตัวแทนผู้ขาย / หัวหน้าทีม</b> ได้
              และหน้านั้นมีแท็บ "ภาพรวม" ที่บอกด้วยว่าสินค้าไหน<b>ตั้งค่ายังไม่ครบจนจะไม่มีใครได้เงิน</b>
            </p>
            <RouterLink
              :to="{ name: 'commission-plan-settings' }"
              class="mt-3 inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700"
            >
              ไปที่แผนคอมมิชชั่น →
            </RouterLink>
          </div>
        </div>
      </div>
    </section>

    <!-- Video processing settings (ADR-007) — per-company override of config/media.php -->
    </template>

    <!-- TASK-066 convention — ConfirmDialog, never native window.confirm().
         Bug fix (2026-08-01, human-reported: sub-menu nav needed a hard
         refresh to render) — this was a SIBLING of <main>, making the
         template a multi-root Fragment, which breaks App.vue's
         <Transition mode="out-in"> around <RouterView> (see
         AgentManagementView.vue's identical fix for the full
         explanation). Moved inside <main>. -->
    <ConfirmDialog
      :show="pendingDeleteBanner !== null"
      variant="danger"
      :body='pendingDeleteBanner ? `ยืนยันลบแบนเนอร์ "${pendingDeleteBanner.title || pendingDeleteBanner.product?.name || ""}"?` : ""'
      @confirm="confirmDeleteBanner"
      @update:show="(v) => { if (!v) pendingDeleteBanner = null }"
    />

    <!-- TASK-088 — soft delete, so the copy says "ซ่อน...จากรายการ", not
         "ลบถาวร": products already using this brand keep working. -->
    <ConfirmDialog
      :show="pendingDeleteBrand !== null"
      variant="danger"
      :size="deleteBrandPhrase ? 'md' : 'sm'"
      :body="deleteBrandBody"
      :confirm-phrase="deleteBrandPhrase"
      @confirm="confirmDeleteBrand"
      @update:show="(v) => { if (!v) pendingDeleteBrand = null }"
    />

    <!-- TASK-088 — soft delete, same wording rationale as the brand dialog. -->
    <ConfirmDialog
      :show="pendingDeleteCategory !== null"
      variant="danger"
      :size="deleteCategoryPhrase ? 'md' : 'sm'"
      :body="deleteCategoryBody"
      :confirm-phrase="deleteCategoryPhrase"
      @confirm="confirmDeleteCategory"
      @update:show="(v) => { if (!v) pendingDeleteCategory = null }"
    />

    <!-- TASK-091 — the remaining three tabs. Product copy warns about the
         Agent Portal because a hidden package disappears from the
         storefront immediately. -->
    <!-- 2026-09-09 — one dialog, two shapes. The body is built from the
         server's own deletion-impact answer (see deleteProductBody), so it
         can name the other companies and can never promise a delete the
         server then refuses. A shared package additionally asks for its name
         to be typed. -->
    <ConfirmDialog
      :show="pendingDeleteProduct !== null"
      variant="danger"
      :size="pendingDeleteProduct?.is_shared ? 'md' : 'sm'"
      :title="pendingDeleteProduct?.is_shared ? 'ลบสินค้ากลาง' : 'ซ่อนแพ็กเกจ'"
      :body="loadingImpact ? 'กำลังตรวจสอบผลกระทบ…' : deleteProductBody"
      :confirm-phrase="deleteProductPhrase"
      :busy="loadingImpact"
      @confirm="confirmDeleteProduct"
      @update:show="(v) => { if (!v) { pendingDeleteProduct = null; deleteImpact = null } }"
    />
  </main>
</template>
