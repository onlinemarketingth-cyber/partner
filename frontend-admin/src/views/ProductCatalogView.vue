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
import Icon from '@/design-system/components/Icon.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import BuddhistDateInput from '@/design-system/components/BuddhistDateInput.vue'
import IconPicker from '@/design-system/components/IconPicker.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { compressImageToFit } from '@/utils/imageCompression'
import { useAuthStore } from '@/stores/auth'
// TASK-196 §3 — live commission-rate-cap guard, one shared implementation
// with ProductEditView.vue and CommissionPlansView.vue.
import { useCommissionRateCapGuard } from '@/composables/useCommissionRateCap'

interface Brand {
  id: number
  name: string
  is_active: boolean
}
interface ProductCategory {
  id: number
  name: string
  // TASK-068 / ADR-020 row 3 — Icon.vue name from the curated whitelist
  // (App\Support\CuratedIcons::WHITELIST), or null if unset.
  icon: string | null
  sort_order: number
  is_active: boolean
}
interface Product {
  id: number
  // TASK-069 — needed client-side to scope the Banner/Pin product
  // pickers to the caller's own company when Super Admin has multiple
  // companies' products in one flat /products response (TenantScope
  // only auto-filters for non-Super-Admin actors).
  company_id: number
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
interface CommissionRule {
  id: number
  cert_tier: CertTierRef | null
  product: Product | null
  rate_type: 'percentage' | 'fixed_satang'
  rate_value: number
  effective_from: string
  effective_to: string | null
  // TASK-024 (ADR-006) — null/null/false unless an admin has opted a
  // rule into renewal-year commission.
  renewal_rate_type: 'percentage' | 'fixed_satang' | null
  renewal_rate_value: number | null
  renewal_recurs: boolean
}
// TASK-025 / ADR-006 — keyed by the MANAGER's own cert tier (not
// product-scoped, unlike CommissionRule above): a manager earns this
// rate whenever anyone in their downline closes a direct sale.
interface CommissionOverrideRule {
  id: number
  manager_cert_tier: CertTierRef | null
  rate_type: 'percentage' | 'fixed_satang'
  rate_value: number
  effective_from: string
  effective_to: string | null
}

type Tab = 'brands' | 'categories' | 'products' | 'banners' | 'commission_rules' | 'override_rules'
const route = useRoute()
const VALID_TABS: Tab[] = ['brands', 'categories', 'products', 'banners', 'commission_rules', 'override_rules']
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
  { key: 'commission_rules', label: 'อัตราคอมมิชชั่น', icon: 'dollar' },
  // TASK-104 — the video-compression config tab MOVED to
  // ThemeSettingsView ("ตั้งค่าระบบ"). It was never product-scoped; it is
  // a company-wide media setting that also governs Academy clips and
  // announcement attachments, so sitting in the product catalogue meant
  // an admin looking for it had to guess "products" for something that
  // is not about products.
  // TASK-025 / ADR-006 — Unilevel manager override rate, separate from
  // the per-product rate above (this one has no product dimension).
  { key: 'override_rules', label: 'ค่าคอมหัวหน้าทีม (Override)', icon: 'branch' },
]

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

const brands = ref<Brand[]>([])
const categories = ref<ProductCategory[]>([])
const products = ref<Product[]>([])
const commissionRules = ref<CommissionRule[]>([])
const commissionOverrideRules = ref<CommissionOverrideRule[]>([])
const banners = ref<StorefrontBanner[]>([])
// Real cert tier list from GET /cert-tiers (any authenticated user) —
// mirrors CommissionPlansView.vue's certTiers ref/loading pattern.
const certTiers = ref<CertTierRef[]>([])

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [b, c, p, sb, ct] = await Promise.all([
      api.get<{ data: Brand[] }>('/brands'),
      api.get<{ data: ProductCategory[] }>('/product-categories'),
      api.get<{ data: Product[] }>('/products'),
      api.get<{ data: StorefrontBanner[] }>('/storefront-banners'),
      api.get<{ data: CertTierRef[] }>('/cert-tiers'),
    ])
    brands.value = b.data
    categories.value = c.data
    products.value = p.data
    banners.value = sb.data
    certTiers.value = ct.data

    // Commission rules is Company Admin/Super Admin only (403 for
    // Agent) — load it separately so one 403 doesn't blank the rest of
    // the page for a role that isn't allowed to see it anyway (this
    // view itself is Admin-app-only, but defensive regardless).
    try {
      const cr = await api.get<{ data: CommissionRule[] }>('/commission-rules')
      commissionRules.value = cr.data
    } catch (e) {
      if (!(e instanceof ApiError && e.status === 403)) throw e
    }

    // TASK-025 / ADR-006 — same Company Admin/Super Admin-only access
    // shape as commission-rules above (CommissionOverrideRulePolicy).
    try {
      const cor = await api.get<{ data: CommissionOverrideRule[] }>('/commission-override-rules')
      commissionOverrideRules.value = cor.data
    } catch (e) {
      if (!(e instanceof ApiError && e.status === 403)) throw e
    }
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

onMounted(loadAll)

// ── Brand ──
// BrandService::create() requires company_id in the payload when the
// actor is Super Admin (Company Admin's own company_id is inferred
// server-side) — same shape as StoreProductRequest/ProductEditView.vue
// and video-processing-settings below. Found via live UAT: Super Admin
// creating a Brand returned 422 because this form never sent it.
const showBrandForm = ref(false)
const brandForm = ref({ name: '' })
const brandFormError = ref('')
async function submitBrand() {
  if (isSuperAdmin.value && !selectedCatalogCompanyId.value) {
    brandFormError.value = 'กรุณาเลือกบริษัทก่อนบันทึก'
    return
  }
  brandFormError.value = ''
  await api.post('/brands', {
    name: brandForm.value.name,
    ...(isSuperAdmin.value ? { company_id: selectedCatalogCompanyId.value } : {}),
  })
  brandForm.value = { name: '' }
  showBrandForm.value = false
  await loadAll()
}

// ── Category ──
// ProductCategoryService::create() has the identical Super-Admin
// company_id requirement as BrandService above.
const showCategoryForm = ref(false)
// icon: '' = unset (IconPicker's own convention) -> omitted from the
// create payload entirely, matching every other optional field here.
const categoryForm = ref({ name: '', icon: '', sort_order: 0 })
const categoryFormError = ref('')
async function submitCategory() {
  if (isSuperAdmin.value && !selectedCatalogCompanyId.value) {
    categoryFormError.value = 'กรุณาเลือกบริษัทก่อนบันทึก'
    return
  }
  categoryFormError.value = ''
  await api.post('/product-categories', {
    name: categoryForm.value.name,
    sort_order: categoryForm.value.sort_order,
    ...(categoryForm.value.icon ? { icon: categoryForm.value.icon } : {}),
    ...(isSuperAdmin.value ? { company_id: selectedCatalogCompanyId.value } : {}),
  })
  categoryForm.value = { name: '', icon: '', sort_order: 0 }
  showCategoryForm.value = false
  await loadAll()
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

const editingBrandId = ref<number | null>(null)
const editBrandForm = ref({ name: '', is_active: true })
const editBrandError = ref('')
const savingBrandEdit = ref(false)

function startEditBrand(brand: Brand): void {
  editingBrandId.value = brand.id
  editBrandForm.value = { name: brand.name, is_active: brand.is_active }
  editBrandError.value = ''
}

function cancelEditBrand(): void {
  editingBrandId.value = null
}

async function saveEditBrand(): Promise<void> {
  if (!editingBrandId.value) return
  savingBrandEdit.value = true
  editBrandError.value = ''
  try {
    await api.put(`/brands/${editingBrandId.value}`, {
      name: editBrandForm.value.name,
      is_active: editBrandForm.value.is_active,
    })
    editingBrandId.value = null
    await loadAll()
  } catch (e) {
    editBrandError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
  } finally {
    savingBrandEdit.value = false
  }
}

// TASK-066 convention — ConfirmDialog, never native window.confirm().
const pendingDeleteBrand = ref<Brand | null>(null)
function deleteBrand(brand: Brand): void {
  pendingDeleteBrand.value = brand
}
async function confirmDeleteBrand(): Promise<void> {
  const brand = pendingDeleteBrand.value
  if (!brand) return
  try {
    await api.delete(`/brands/${brand.id}`)
    brands.value = brands.value.filter((b) => b.id !== brand.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteBrand.value = null
  }
}

// pattern used nowhere else on this page yet, closest precedent is
// ProductEditView.vue's pencil-edit-toggle sections.
const editingCategoryId = ref<number | null>(null)
const editCategoryForm = ref({ name: '', icon: '', sort_order: 0, is_active: true })
const editCategoryError = ref('')
const savingCategoryEdit = ref(false)
function startEditCategory(category: ProductCategory): void {
  editingCategoryId.value = category.id
  editCategoryForm.value = {
    name: category.name,
    icon: category.icon ?? '',
    sort_order: category.sort_order,
    is_active: category.is_active,
  }
  editCategoryError.value = ''
}
function cancelEditCategory(): void {
  editingCategoryId.value = null
}
async function saveEditCategory(): Promise<void> {
  if (!editingCategoryId.value) return
  savingCategoryEdit.value = true
  editCategoryError.value = ''
  try {
    await api.put(`/product-categories/${editingCategoryId.value}`, {
      name: editCategoryForm.value.name,
      // Explicit null (not omitted) so a cleared icon actually clears
      // server-side — UpdateProductCategoryRequest's own comment: "null
      // clears the icon back to 'none chosen'".
      icon: editCategoryForm.value.icon || null,
      sort_order: editCategoryForm.value.sort_order,
      is_active: editCategoryForm.value.is_active,
    })
    editingCategoryId.value = null
    await loadAll()
  } catch (e) {
    editCategoryError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
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
const pendingDeleteCategory = ref<ProductCategory | null>(null)
function deleteCategory(category: ProductCategory): void {
  pendingDeleteCategory.value = category
}
async function confirmDeleteCategory(): Promise<void> {
  const category = pendingDeleteCategory.value
  if (!category) return
  try {
    await api.delete(`/product-categories/${category.id}`)
    categories.value = categories.value.filter((c) => c.id !== category.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteCategory.value = null
  }
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
const bannerCompanyProducts = computed(() => {
  if (!isSuperAdmin.value) return products.value
  if (!selectedCatalogCompanyId.value) return []
  return products.value.filter((p) => p.company_id === selectedCatalogCompanyId.value)
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
}
async function confirmDeleteProduct(): Promise<void> {
  const product = pendingDeleteProduct.value
  if (!product) return
  try {
    await api.delete(`/products/${product.id}`)
    products.value = products.value.filter((p) => p.id !== product.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteProduct.value = null
  }
}

const pendingDeleteCommissionRule = ref<CommissionRule | null>(null)
function deleteCommissionRule(rule: CommissionRule): void {
  pendingDeleteCommissionRule.value = rule
}
async function confirmDeleteCommissionRule(): Promise<void> {
  const rule = pendingDeleteCommissionRule.value
  if (!rule) return
  try {
    await api.delete(`/commission-rules/${rule.id}`)
    commissionRules.value = commissionRules.value.filter((r) => r.id !== rule.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteCommissionRule.value = null
  }
}

const pendingDeleteOverrideRule = ref<CommissionOverrideRule | null>(null)
function deleteOverrideRule(rule: CommissionOverrideRule): void {
  pendingDeleteOverrideRule.value = rule
}
async function confirmDeleteOverrideRule(): Promise<void> {
  const rule = pendingDeleteOverrideRule.value
  if (!rule) return
  try {
    await api.delete(`/commission-override-rules/${rule.id}`)
    commissionOverrideRules.value = commissionOverrideRules.value.filter((r) => r.id !== rule.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteOverrideRule.value = null
  }
}

// ── Commission rule ──
const showRuleForm = ref(false)
const ruleForm = ref({
  product_id: '',
  cert_tier_id: '' as string | number,
  // TASK-197 §3.5 — renamed from rate_percent: this input no longer
  // always means "a percent" (see effectiveRuleFormRateType below), it
  // is % OR THB depending on the selected product's own
  // commission_rate_type. rateValueToBasisOrSatang's math is identical
  // either way (both are "multiply by 100, round"), only the unit label
  // and the rate_type sent to the API differ.
  rate_value_input: '' as string | number,
  effective_from: new Date().toISOString().slice(0, 10),
  // TASK-024 (ADR-006) — optional. Empty string = "no renewal rate",
  // never sent to the API at all (opt-in, never a $0 rate).
  renewal_rate_percent: '' as string | number,
  renewal_recurs: false,
})

// TASK-197 §3.5 (human decision) — the "ใช้อัตรานี้กับทุกแพ็กเกจ" bulk-apply
// checkbox and its applyRateToAllProducts() code path are REMOVED
// entirely, not just hidden: one entered number can no longer mean the
// same thing across every product once each product can have its own %
// vs fixed-THB format — a bulk value could silently be wrong for any
// product whose format differs from what the admin had in mind. Only
// the per-product form (below) remains.

// TASK-197 §3.5 — the selected product's OWN locked-in rate_type
// (server-authoritative, set either via ProductEditView's settings
// block or as a side effect of this product's first rule, §2.2). This
// form used to hardcode rate_type: 'percentage' unconditionally (see
// the superseded TASK-196 comment this replaces) — it now reads and
// respects the product's real format, falling back to 'percentage' only
// when the product has never had a rule before (§2.1's null default,
// same as every other form in this task).
const ruleFormSelectedProduct = computed<Product | null>(() => {
  if (ruleForm.value.product_id === '') return null
  return products.value.find((p) => p.id === Number(ruleForm.value.product_id)) ?? null
})
const effectiveRuleFormRateType = computed<'percentage' | 'fixed_satang'>(() => ruleFormSelectedProduct.value?.commission_rate_type ?? 'percentage')

const ruleCapGuard = useCommissionRateCapGuard()
const ruleFormProductPriceSatang = computed<number | null>(() => {
  if (ruleForm.value.product_id === '') return null
  return products.value.find((p) => p.id === Number(ruleForm.value.product_id))?.price_satang ?? null
})
function recheckRuleCap(): void {
  ruleCapGuard.recheck(effectiveRuleFormRateType.value, rateValueToBasisOrSatang(ruleForm.value.rate_value_input), ruleFormProductPriceSatang.value)
}
function recheckRuleCapDebounced(): void {
  ruleCapGuard.recheckDebounced(effectiveRuleFormRateType.value, rateValueToBasisOrSatang(ruleForm.value.rate_value_input), ruleFormProductPriceSatang.value)
}
function rateValueToBasisOrSatang(input: string | number): number {
  return Math.round(Number(input) * 100)
}

async function submitRule() {
  if (!ruleForm.value.cert_tier_id) {
    errorMessage.value = 'กรุณาเลือก Cert Tier'
    return
  }
  if (!ruleForm.value.product_id) {
    errorMessage.value = 'กรุณาเลือกแพ็กเกจ'
    return
  }
  const certTierId = Number(ruleForm.value.cert_tier_id)

  // TASK-196 §3.2 — defensive re-check alongside the disabled Save button.
  recheckRuleCap()
  if (ruleCapGuard.isOverCap.value) return

  await api.post('/commission-rules', {
    product_id: Number(ruleForm.value.product_id),
    cert_tier_id: certTierId,
    rate_type: effectiveRuleFormRateType.value,
    rate_value: rateValueToBasisOrSatang(ruleForm.value.rate_value_input),
    effective_from: ruleForm.value.effective_from,
    ...renewalPayloadFields(),
  })
  resetRuleForm()
  await loadAll()
}

// TASK-024 (ADR-006) — spreads in only when a renewal % was actually
// entered; omitted entirely otherwise so a plain rule stays exactly as
// before (renewal_rate_type stays null server-side, BR-7 opt-in).
function renewalPayloadFields(): Record<string, unknown> {
  if (ruleForm.value.renewal_rate_percent === '') return {}
  return {
    renewal_rate_type: 'percentage',
    renewal_rate_value: Math.round(Number(ruleForm.value.renewal_rate_percent) * 100),
    renewal_recurs: ruleForm.value.renewal_recurs,
  }
}

function resetRuleForm() {
  ruleForm.value = {
    product_id: '',
    cert_tier_id: '',
    rate_value_input: '',
    effective_from: new Date().toISOString().slice(0, 10),
    renewal_rate_percent: '',
    renewal_recurs: false,
  }
  showRuleForm.value = false
  ruleCapGuard.reset()
}

function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 0 }) + ' บาท'
}
function formatRate(rule: CommissionRule | CommissionOverrideRule): string {
  if (rule.rate_type === 'percentage') return (rule.rate_value / 100).toFixed(2) + '%'
  return formatSatang(rule.rate_value)
}

// ── Commission override rule (TASK-025 / ADR-006) ──
const showOverrideRuleForm = ref(false)
const overrideRuleForm = ref({ cert_tier_id: '' as string | number, rate_percent: '' as string | number, effective_from: new Date().toISOString().slice(0, 10) })
async function submitOverrideRule() {
  if (!overrideRuleForm.value.cert_tier_id) {
    errorMessage.value = 'กรุณาเลือก Cert Tier'
    return
  }
  await api.post('/commission-override-rules', {
    manager_cert_tier_id: Number(overrideRuleForm.value.cert_tier_id),
    rate_type: 'percentage',
    rate_value: Math.round(Number(overrideRuleForm.value.rate_percent) * 100), // % -> basis points
    effective_from: overrideRuleForm.value.effective_from,
  })
  overrideRuleForm.value = { cert_tier_id: '', rate_percent: '', effective_from: new Date().toISOString().slice(0, 10) }
  showOverrideRuleForm.value = false
  await loadAll()
}

// ── Video processing settings (ADR-007) — per-company override of
// config/media.php's platform defaults (max upload size, target
// resolution/bitrate for the async compression job). Company Admin
// manages their own company automatically; Super Admin must pick a
// company first (mirrors CompanyManagementView's /companies list). ──
const authStore = useAuthStore()
const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin')
interface CompanyOption {
  id: number
  name: string
}
const companyOptions = ref<CompanyOption[]>([])
// TASK-104 — `selectedCompanyId` went with the video-settings tab; the
// only company picker left on this page is the catalogue one below.
const selectedCatalogCompanyId = ref<number | null>(null)

/**
 * Super Admin company list for the Brand / Category / Banner create
 * forms. Stayed behind when the video-settings tab moved out — those
 * forms still need it, and TenantScope does not auto-filter for a Super
 * Admin, so without a picker they would be creating rows with no company.
 *
 * Errors land in the page-level `errorMessage` now that the
 * video-settings error ref it used to write to has gone.
 */
async function loadCompanyOptionsIfNeeded() {
  if (!isSuperAdmin.value || companyOptions.value.length) return
  try {
    const res = await api.get<{ data: CompanyOption[] }>('/companies')
    companyOptions.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดรายชื่อบริษัทไม่สำเร็จ (${e.status})` : 'โหลดรายชื่อบริษัทไม่สำเร็จ'
  }
}
watch(activeTab, (tab) => {
  // Brand/Category/Banner create forms need the same company picker —
  // load it lazily the first time any of these tabs is opened, not just
  // Banners need it to scope the product-picker dropdown
  // to one company (TASK-069 — Super Admin sees every company's products
  // in the flat /products response, TenantScope only auto-filters for
  // non-Super-Admin actors).
  if ((tab === 'brands' || tab === 'categories' || tab === 'banners') && isSuperAdmin.value) {
    loadCompanyOptionsIfNeeded()
  }
})
// Cover the case where the page loads directly on the brands/categories/
// banners tab (deep link, or it's just the default initial tab) for
// Super Admin — the watch(activeTab) above only fires on a change, not
// on the initial value.
if (isSuperAdmin.value && (activeTab.value === 'brands' || activeTab.value === 'categories' || activeTab.value === 'banners')) {
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
type CatalogFilters = { q: string; brandId: number | null; categoryId: number | null }

function readStoredFilters(): CatalogFilters {
  try {
    const raw = window.sessionStorage?.getItem(FILTER_KEY)
    if (raw) {
      const parsed = JSON.parse(raw) as Partial<CatalogFilters>

      return {
        q: typeof parsed.q === 'string' ? parsed.q : '',
        brandId: typeof parsed.brandId === 'number' ? parsed.brandId : null,
        categoryId: typeof parsed.categoryId === 'number' ? parsed.categoryId : null,
      }
    }
  } catch {
    /* private mode / corrupt value — fall through to defaults */
  }

  return { q: '', brandId: null, categoryId: null }
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
const filteredProducts = computed(() => {
  const q = productFilters.q.trim().toLowerCase()

  return products.value.filter((p) => {
    if (productFilters.brandId !== null && p.brand?.id !== productFilters.brandId) return false
    if (productFilters.categoryId !== null && p.category?.id !== productFilters.categoryId) return false
    if (q && !p.name.toLowerCase().includes(q)) return false

    return true
  })
})

const productFilterCount = computed(
  () => [productFilters.brandId, productFilters.categoryId].filter((v) => v !== null).length
    + (productFilters.q.trim() ? 1 : 0),
)

function clearProductFilters() {
  productFilters.q = ''
  productFilters.brandId = null
  productFilters.categoryId = null
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
             menu และ tab" — full-screen, covering the top nav and the tab
             bar rather than a side panel over a dimmed page. A sliver of
             greyed-out page behind a panel invites clicks that do nothing;
             at this width it was also squeezing the brand rows. The
             content still sits in a centred column so a 1500px monitor
             does not stretch a two-row list across the whole desk. -->
        <div v-if="refDrawerOpen" class="fixed inset-0 z-[1000] bg-white overflow-y-auto">
          <div class="max-w-3xl mx-auto px-5 py-5">
            <div class="flex items-center gap-3 mb-4">
              <h2 class="flex-1 text-base font-bold text-slate-900">จัดการแบรนด์ / หมวดหมู่</h2>
              <button class="w-10 h-10 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100" title="ปิด (Esc)" @click="closeRefDrawer">
                <Icon name="x" :size="18" />
              </button>
            </div>
            <div class="flex gap-1 p-1 rounded-xl bg-slate-100">
              <button
                v-for="opt in ([{ key: 'brands', label: 'แบรนด์', count: brands.length }, { key: 'categories', label: 'หมวดหมู่', count: categories.length }] as const)"
                :key="opt.key"
                type="button"
                class="flex-1 h-[36px] rounded-lg text-sm font-bold transition"
                :class="refDrawerTab === opt.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'"
                @click="refDrawerTab = opt.key"
              >
                {{ opt.label }} {{ opt.count }}
              </button>
            </div>

    <section v-if="refDrawerTab === 'brands'" class="mt-4">
      <div v-if="isSuperAdmin" class="mb-2 flex items-center gap-2">
        <label class="text-xs font-bold text-slate-500">บริษัท (Super Admin)</label>
        <select v-model.number="selectedCatalogCompanyId" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm">
          <option :value="null">— เลือกบริษัท —</option>
          <option v-for="c in companyOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="flex justify-end mb-2">
        <button class="btn-primary" @click="showBrandForm = !showBrandForm">
          + เพิ่มแบรนด์
        </button>
      </div>
      <form v-if="showBrandForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 flex gap-2 items-end" @submit.prevent="submitBrand">
        <div class="flex-1">
          <label class="text-xs font-bold text-slate-500">ชื่อแบรนด์</label>
          <input v-model="brandForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <button type="submit" class="btn-primary">บันทึก</button>
      </form>
      <p v-if="brandFormError" class="mb-3 text-xs font-bold text-rose-600">{{ brandFormError }}</p>
      <EmptyState v-if="!brands.length" icon="tag" title="ยังไม่มีแบรนด์" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div v-for="b in brands" :key="b.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
          <template v-if="editingBrandId === b.id">
            <div class="space-y-3">
              <div>
                <label class="text-xs font-bold text-slate-500">ชื่อแบรนด์</label>
                <input v-model="editBrandForm.name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer">
                <input v-model="editBrandForm.is_active" type="checkbox" class="rounded border-slate-300" /> ใช้งาน
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
          <div v-else class="flex items-center justify-between gap-3">
            <span class="text-sm font-bold text-slate-900 truncate">{{ b.name }}</span>
            <div class="flex items-center gap-3 shrink-0">
              <span :class="b.is_active ? 'text-emerald-600' : 'text-slate-400'" class="text-xs font-bold">{{ b.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span>
              <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditBrand(b)">
                <Icon name="pencil" :size="14" />
              </button>
              <button class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteBrand(b)">
                <Icon name="trash" :size="14" />
              </button>
            </div>
          </div>
        </div>
      </TransitionGroup>
    </section>

    <!-- Categories -->
    <section v-if="refDrawerTab === 'categories'" class="mt-4">
      <div v-if="isSuperAdmin" class="mb-2 flex items-center gap-2">
        <label class="text-xs font-bold text-slate-500">บริษัท (Super Admin)</label>
        <select v-model.number="selectedCatalogCompanyId" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm">
          <option :value="null">— เลือกบริษัท —</option>
          <option v-for="c in companyOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="flex justify-end mb-2">
        <button class="btn-primary" @click="showCategoryForm = !showCategoryForm">
          + เพิ่มหมวดหมู่
        </button>
      </div>
      <form v-if="showCategoryForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 space-y-3" @submit.prevent="submitCategory">
        <div>
          <label class="text-xs font-bold text-slate-500">ชื่อหมวดหมู่</label>
          <input v-model="categoryForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500 block mb-1">ไอคอน (ไม่บังคับ — แสดงบนหน้าร้าน Agent Portal)</label>
          <IconPicker v-model="categoryForm.icon" fallback-icon="box" fallback-label="ยังไม่ได้เลือกไอคอน" clear-label="ล้างไอคอน" />
        </div>
        <div class="flex justify-end">
          <button type="submit" class="btn-primary">บันทึก</button>
        </div>
      </form>
      <p v-if="categoryFormError" class="mb-3 text-xs font-bold text-rose-600">{{ categoryFormError }}</p>
      <EmptyState v-if="!categories.length" icon="layers" title="ยังไม่มีหมวดหมู่" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div v-for="c in categories" :key="c.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
          <template v-if="editingCategoryId === c.id">
            <div class="space-y-3">
              <div>
                <label class="text-xs font-bold text-slate-500">ชื่อหมวดหมู่</label>
                <input v-model="editCategoryForm.name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
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
                  <input v-model="editCategoryForm.is_active" type="checkbox" class="rounded border-slate-300" /> ใช้งาน
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
          <div v-else class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
              <span class="w-9 h-9 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center shrink-0">
                <Icon :name="c.icon || 'layers'" :size="16" class="text-slate-600" />
              </span>
              <span class="text-sm font-bold text-slate-900 truncate">{{ c.name }}</span>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <span :class="c.is_active ? 'text-emerald-600' : 'text-slate-400'" class="text-xs font-bold">{{ c.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span>
              <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditCategory(c)">
                <Icon name="pencil" :size="14" />
              </button>
              <button class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteCategory(c)">
                <Icon name="trash" :size="14" />
              </button>
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
        <select v-model.number="productFilters.brandId" class="h-[40px] px-3 rounded-lg border border-slate-200 text-sm">
          <option :value="null">แบรนด์: ทั้งหมด</option>
          <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
        <select v-model.number="productFilters.categoryId" class="h-[40px] px-3 rounded-lg border border-slate-200 text-sm">
          <option :value="null">หมวดหมู่: ทั้งหมด</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
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
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div v-for="p in filteredProducts" :key="p.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
          <div>
            <p class="text-sm font-bold text-slate-900">{{ p.name }}</p>
            <p class="text-xs text-slate-400">{{ p.brand?.name }} · {{ p.category?.name }}</p>
          </div>
          <div class="flex items-center gap-3">
            <span :class="p.is_active ? 'text-emerald-600' : 'text-slate-400'" class="text-xs font-bold">{{ p.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span>
            <span class="text-sm font-bold text-slate-900">{{ formatSatang(p.price_satang) }}</span>
            <RouterLink
              :to="{ name: 'product-edit', params: { id: p.id } }"
              class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold hover:bg-slate-200 flex items-center gap-1"
            >
              <Icon name="pencil" :size="12" /> แก้ไข
            </RouterLink>
            <button class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteProduct(p)">
              <Icon name="trash" :size="14" />
            </button>
          </div>
        </div>
      </TransitionGroup>
    </section>

    <!-- Banners (TASK-068 / ADR-020 row 2) -->
    <section v-if="activeTab === 'banners'" class="mt-4">
      <div v-if="isSuperAdmin" class="mb-2 flex items-center gap-2">
        <label class="text-xs font-bold text-slate-500">บริษัท (Super Admin)</label>
        <select v-model.number="selectedCatalogCompanyId" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm">
          <option :value="null">— เลือกบริษัท —</option>
          <option v-for="c in companyOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
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
    <section v-if="activeTab === 'commission_rules'" class="mt-4">
      <div class="flex justify-end mb-2">
        <button class="btn-primary" @click="showRuleForm = !showRuleForm">
          + เพิ่มอัตราคอมตาม tier
        </button>
      </div>
      <form v-if="showRuleForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitRule">
        <div>
          <label class="text-xs font-bold text-slate-500">แพ็กเกจ</label>
          <select v-model="ruleForm.product_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" @change="recheckRuleCap">
            <option value="" disabled>เลือกแพ็กเกจ</option>
            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">Cert tier</label>
          <select v-model="ruleForm.cert_tier_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            <option value="" disabled>เลือก tier</option>
            <option v-for="ct in certTiers" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">{{ effectiveRuleFormRateType === 'percentage' ? 'อัตรา (%)' : 'จำนวน (บาท)' }}</label>
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
          <!-- TASK-197 §3.5 — this form has no rate_type selector of its
               own (it never did): the unit is fully determined by the
               selected package's own commission_rate_type, so this is
               purely informational, not a choice. -->
          <p v-if="ruleForm.product_id" class="mt-1 text-xs text-slate-400">
            จะบันทึกเป็น: {{ effectiveRuleFormRateType === 'percentage' ? '% ของยอดขาย' : 'จำนวนคงที่ (บาท)' }}
          </p>
          <p v-if="ruleCapGuard.isOverCap.value" class="mt-1 text-xs font-bold text-rose-600">เกินเพดานคอมมิชชั่นที่กำหนด</p>
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">มีผลตั้งแต่ (คีย์วันที่เป็น พ.ศ.)</label>
          <div class="mt-1">
            <BuddhistDateInput v-model="ruleForm.effective_from" required />
          </div>
        </div>
        <!-- TASK-024 (ADR-006) — optional renewal-year rate, separate from the direct-sale rate above -->
        <div class="col-span-2 pt-2 border-t border-slate-100">
          <label class="text-xs font-bold text-slate-500">อัตราคอมมิชชั่นปีต่ออายุ (%) — ไม่บังคับ</label>
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
        <div class="col-span-2 flex justify-end">
          <button type="submit" :disabled="ruleCapGuard.isOverCap.value" class="btn-primary">
            บันทึก
          </button>
        </div>
      </form>

      <!-- TASK-196 §3.3 — same single-button blocking-alert shape reused
           across all 3 commission-rule forms (see ProductEditView.vue /
           CommissionPlansView.vue for the same pattern; ConfirmDialog.vue
           has no alert-only mode, this is the app's closest existing
           informational-modal shape instead). -->
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

      <EmptyState v-if="!commissionRules.length" icon="dollar" title="ยังไม่มีอัตราคอมมิชชั่น" message="Agent จะไม่เห็นข้อมูลส่วนนี้ (ตาม CommissionRulePolicy)" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div v-for="r in commissionRules" :key="r.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
          <div>
            <p class="text-sm font-bold text-slate-900">{{ r.product?.name }} · {{ r.cert_tier?.name }}</p>
            <p class="text-xs text-slate-400">มีผลตั้งแต่ {{ r.effective_from }}{{ r.effective_to ? ` ถึง ${r.effective_to}` : '' }}</p>
            <!-- TASK-024 (ADR-006) -->
            <p v-if="r.renewal_rate_type" class="text-xs text-slate-400">
              คอมฯ ปีต่ออายุ: {{ (r.renewal_rate_value! / 100).toFixed(2) }}% · {{ r.renewal_recurs ? 'ต่อทุกปี' : 'ปีเดียว' }}
            </p>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <span class="text-sm font-bold text-slate-900">{{ formatRate(r) }}</span>
            <button class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteCommissionRule(r)">
              <Icon name="trash" :size="14" />
            </button>
          </div>
        </div>
      </TransitionGroup>
    </section>

    <!-- Commission override rules (TASK-025 / ADR-006 — Unilevel manager chain) -->
    <section v-if="activeTab === 'override_rules'" class="mt-4">
      <p class="text-xs text-slate-400 mb-2">
        อัตรานี้จ่ายให้ "หัวหน้าทีม" ตาม cert tier ของหัวหน้าเอง (ไม่ผูกกับสินค้า) — ทุกครั้งที่ลูกทีมปิดการขายโดยตรง
      </p>
      <div class="flex justify-end mb-2">
        <button class="btn-primary" @click="showOverrideRuleForm = !showOverrideRuleForm">
          + เพิ่มอัตรา Override
        </button>
      </div>
      <form v-if="showOverrideRuleForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitOverrideRule">
        <div>
          <label class="text-xs font-bold text-slate-500">Cert tier ของหัวหน้าทีม</label>
          <select v-model="overrideRuleForm.cert_tier_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            <option value="" disabled>เลือก tier</option>
            <option v-for="ct in certTiers" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">อัตรา (%)</label>
          <input v-model="overrideRuleForm.rate_percent" type="number" min="0" step="0.01" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">มีผลตั้งแต่ (คีย์วันที่เป็น พ.ศ.)</label>
          <div class="mt-1">
            <BuddhistDateInput v-model="overrideRuleForm.effective_from" required />
          </div>
        </div>
        <div class="col-span-2 flex justify-end">
          <button type="submit" class="btn-primary">บันทึก</button>
        </div>
      </form>
      <EmptyState v-if="!commissionOverrideRules.length" icon="branch" title="ยังไม่มีอัตรา Override" message="Agent จะไม่เห็นข้อมูลส่วนนี้ (ตาม CommissionOverrideRulePolicy)" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div v-for="r in commissionOverrideRules" :key="r.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
          <div>
            <p class="text-sm font-bold text-slate-900">หัวหน้าทีม · {{ r.manager_cert_tier?.name }}</p>
            <p class="text-xs text-slate-400">มีผลตั้งแต่ {{ r.effective_from }}{{ r.effective_to ? ` ถึง ${r.effective_to}` : '' }}</p>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <span class="text-sm font-bold text-slate-900">{{ formatRate(r) }}</span>
            <button class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteOverrideRule(r)">
              <Icon name="trash" :size="14" />
            </button>
          </div>
        </div>
      </TransitionGroup>
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
      :body='pendingDeleteBrand ? `ซ่อนแบรนด์ "${pendingDeleteBrand.name}" จากรายการ? สินค้าที่ใช้แบรนด์นี้อยู่จะยังทำงานได้ตามปกติ` : ""'
      @confirm="confirmDeleteBrand"
      @update:show="(v) => { if (!v) pendingDeleteBrand = null }"
    />

    <!-- TASK-088 — soft delete, same wording rationale as the brand dialog. -->
    <ConfirmDialog
      :show="pendingDeleteCategory !== null"
      variant="danger"
      :body='pendingDeleteCategory ? `ซ่อนหมวดหมู่ "${pendingDeleteCategory.name}" จากรายการ? สินค้าที่อยู่ในหมวดหมู่นี้จะยังทำงานได้ตามปกติ` : ""'
      @confirm="confirmDeleteCategory"
      @update:show="(v) => { if (!v) pendingDeleteCategory = null }"
    />

    <!-- TASK-091 — the remaining three tabs. Product copy warns about the
         Agent Portal because a hidden package disappears from the
         storefront immediately. -->
    <ConfirmDialog
      :show="pendingDeleteProduct !== null"
      variant="danger"
      :body='pendingDeleteProduct ? `ซ่อนแพ็กเกจ "${pendingDeleteProduct.name}" จากรายการ? Agent จะไม่เห็นแพ็กเกจนี้บนหน้าร้านอีก (ถ้ามีการขาย/คอมมิชชั่นผูกอยู่ ระบบจะไม่ยอมให้ลบ)` : ""'
      @confirm="confirmDeleteProduct"
      @update:show="(v) => { if (!v) pendingDeleteProduct = null }"
    />
    <ConfirmDialog
      :show="pendingDeleteCommissionRule !== null"
      variant="danger"
      body="ลบอัตราคอมมิชชั่นนี้? รายการคอมมิชชั่นที่คำนวณไปแล้วจะไม่เปลี่ยนแปลง (BR-4 — ledger เป็นข้อมูลถาวร)"
      @confirm="confirmDeleteCommissionRule"
      @update:show="(v) => { if (!v) pendingDeleteCommissionRule = null }"
    />
    <ConfirmDialog
      :show="pendingDeleteOverrideRule !== null"
      variant="danger"
      body="ลบอัตรา Override นี้? รายการคอมมิชชั่นที่คำนวณไปแล้วจะไม่เปลี่ยนแปลง (BR-4)"
      @confirm="confirmDeleteOverrideRule"
      @update:show="(v) => { if (!v) pendingDeleteOverrideRule = null }"
    />
  </main>
</template>
