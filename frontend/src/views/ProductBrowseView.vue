<script setup lang="ts">
/**
 * ProductBrowseView — Agent Portal "สินค้า" (TASK-056 P3, redesigned
 * TASK-070 / ADR-020 into a 4-row consumer-app-style storefront).
 *
 * Rows (top to bottom):
 *   1. Search + Filter — existing debounced `q` search, plus a filter
 *      panel (category/brand/price range) mapped to the extended
 *      GET /products query params.
 *   2. Banner carousel — GET /storefront-banners (admin-curated,
 *      optional — the row simply doesn't render with zero banners).
 *   3. Category icon row — GET /product-categories (`icon` + `name`).
 *      Tapping a category is a shortcut into the SAME filter state as
 *      row 1, not a separate mechanism.
 *   4. Recommended for you — GET /products/recommended (admin pins +
 *      ProductGradingService auto-fill, TASK-040/ADR-020 decision #1).
 * Main grid (existing, retained) — the full searchable/filterable
 * catalog, now respecting whatever filter state rows 1/3 have set.
 *
 * Each row fetches and fails independently (per DoD §9 — a banner-fetch
 * failure must never block the product grid from rendering, etc.).
 *
 * BR-1 gate: mint/reuse a public product-share link per product
 * (ProductShareLink — a standalone system from AffiliateLink/
 * SalesMaterialShareLink, confirmed with the human via
 * AskUserQuestion: "สร้างระบบสาธารณะใหม่แยกต่างหาก"). BR-1 gates
 * minting exactly like AffiliateLinksView.vue's hasPassedBasic guard —
 * browsing/searching stays open, only the "แชร์" action is blocked
 * pre-Basic-cert.
 *
 * TASK-066/067 follow-up (human-reported 2026-07-31 — "The agent id
 * field is required" on แชร์ click) — GET /user-certifications returns
 * ONLY the caller's own rows when they're an Agent, but the FULL
 * company roster when they're Company Admin/Super Admin (see
 * UserCertificationController::index()'s isAgent() branch — used
 * elsewhere for the "grant cert" admin screens). hasPassedBasic below
 * MUST stay filtered by `authStore.user?.id` — do not regress this
 * (TASK-070 explicit instruction).
 *
 * POST /product-shares is idempotent per (agent, product) —
 * ProductShareLinkService::create() returns the existing usable link
 * instead of minting a duplicate, so re-clicking "แชร์" on the same
 * product (from the grid, the recommended row, or a banner) is always
 * safe and fast.
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — every row's error copy used to end in a raw
// HTTP status; apiErrorMessage() below is what replaced that.
//
// ApiError itself is no longer imported here: the 422 branch that needed the
// status — telling a real BR-1 rejection apart from a generic FormRequest
// message — moved into useProductShare() on 2026-08-21 when the product
// detail page became the second caller of that flow.
// TASK-079 Phase 4 — isAbortError() for the page-level AbortController.
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useAuthStore } from '@/stores/auth'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppButton from '@/design-system/components/AppButton.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ProductCard, { type ProductCardItem } from '@/design-system/components/ProductCard.vue'
import ShareLinkModal from '@/design-system/components/ShareLinkModal.vue'
import { useProductShare } from '@/composables/useProductShare'
// TASK-080 — announcements can now also render as an inline banner
// carousel on this page (page key 'products'), opening the same modal
// HomeView/AnnouncementsListView already use.
import AnnouncementModal, { type AnnouncementDisplayStyle } from '@/design-system/components/AnnouncementModal.vue'
import AnnouncementBanner from '@/design-system/components/AnnouncementBanner.vue'
import { bannerAnnouncementsForPage, type BannerAwareAnnouncement } from '@/utils/announcementBanners'
import { recordAnnouncementView } from '@/utils/seenAnnouncements'

interface ProductItem extends ProductCardItem {
  description: string | null
  is_active: boolean
  brand?: { id: number; name: string } | null
}
interface Certification {
  id: number
  user_id: number
  cert_tier: { id: number; key: string; name: string } | null
}
interface BannerProduct {
  id: number
  name: string
  thumbnail_url: string | null
}
interface StorefrontBannerItem {
  id: number
  link_type: 'product' | 'url' | 'internal'
  external_url: string | null
  internal_path: string | null
  product: BannerProduct | null
  image_url: string | null
  title: string | null
  placement: 'top' | 'middle' | 'bottom'
  sort_order: number
  is_active: boolean
}
interface ProductCategoryItem {
  id: number
  name: string
  icon: string | null
  sort_order: number
  is_active: boolean
}
interface BrandItem {
  id: number
  name: string
  is_active: boolean
}

const hasLoadedOnce = ref(false)
const errorMessage = ref('')

/**
 * TASK-079 Phase 4 (2026-08-03, UX audit — perceived performance).
 *
 * This page used to fire SIX un-awaited loaders in onMounted (products,
 * certifications, banners, categories, brands, recommended), each with
 * its own loading ref and its own skeleton block. They resolved at six
 * different moments, so the page visibly re-laid-out four or five times
 * as each row popped in and pushed the rows below it down — on a phone,
 * with a thumb already moving toward a product card, that means tapping
 * the wrong thing.
 *
 * `initialLoading` is now the ONE page-level loading state: everything
 * below the header is a single skeleton until every loader has settled,
 * then the page paints once, complete. The per-row loading refs
 * (bannersLoading / categoriesLoading / recommendedLoading) are gone.
 *
 * Nothing about WHICH requests are made or what they send changed — the
 * six loader functions are untouched; they are merely awaited together.
 * Each still swallows/records its own failure independently, so DoD §9's
 * "a banner-fetch failure must never block the product grid" still holds
 * (Promise.all here can only settle, never reject: every loader catches).
 */
const initialLoading = ref(true)

/**
 * One controller for this view's lifetime. The storefront is the app's
 * heaviest screen (6 requests, images) and the easiest to leave early —
 * without this, all six keep running and resolve into a dead component.
 */
const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

const authStore = useAuthStore()
const router = useRouter()
const products = ref<ProductItem[]>([])
const certifications = ref<Certification[]>([])
// See docblock above — this exact `authStore.user?.id` filter is the
// TASK-067 fix and must not regress.
const hasPassedBasic = computed(() =>
  certifications.value.some((c) => c.user_id === authStore.user?.id && c.cert_tier?.key === 'basic'),
)

const searchQuery = ref('')
let searchDebounce: ReturnType<typeof setTimeout> | null = null

// ── Row 1: Filter panel state (category_id/brand_id/price range) ──────────
const filterPanelOpen = ref(false)
const filters = reactive<{
  categoryId: number | null
  brandId: number | null
  priceMinBaht: number | null
  priceMaxBaht: number | null
}>({ categoryId: null, brandId: null, priceMinBaht: null, priceMaxBaht: null })

// Vue's `v-model.number` on a native <input type="number"> resolves to ''
// (empty string), not null, when the user clears the field — looseToNumber()
// only rewrites values parseFloat() can read, so a cleared string passes
// through unchanged. Treat both null/undefined AND '' as "unset" everywhere
// filter state is read, so a cleared price input doesn't silently become an
// active `price_min_satang=0` filter.
function isFilterValueSet(v: number | null): boolean {
  return v !== null && v !== undefined && (v as unknown as string) !== ''
}

const activeFilterCount = computed(
  () => [filters.categoryId, filters.brandId, filters.priceMinBaht, filters.priceMaxBaht].filter(isFilterValueSet).length,
)

function applyFilters() {
  filterPanelOpen.value = false
  loadProducts()
}

function clearFilters() {
  filters.categoryId = null
  filters.brandId = null
  filters.priceMinBaht = null
  filters.priceMaxBaht = null
  loadProducts()
}

/**
 * TASK-099 — the results-header "ล้างตัวกรอง" must also drop the search
 * term, otherwise clearing looks broken: the filters go, the header stays
 * (because `isBrowsingFiltered` still sees a query), and the banners never
 * come back. `clearFilters()` is left alone — it belongs to the filter
 * panel, where search is a separate control the agent can still see.
 */
function clearAllFilters() {
  searchQuery.value = ''
  clearFilters()
}

async function loadProducts() {
  errorMessage.value = ''
  try {
    const params = new URLSearchParams({ per_page: '60', is_active: '1' })
    if (searchQuery.value.trim()) params.set('q', searchQuery.value.trim())
    if (isFilterValueSet(filters.categoryId)) params.set('category_id', String(filters.categoryId))
    if (isFilterValueSet(filters.brandId)) params.set('brand_id', String(filters.brandId))
    // BR-3 — money is satang-integer at the API boundary; the filter
    // inputs stay in baht (UI display layer) and are only converted here.
    if (isFilterValueSet(filters.priceMinBaht)) params.set('price_min_satang', String(Math.round(Number(filters.priceMinBaht) * 100)))
    if (isFilterValueSet(filters.priceMaxBaht)) params.set('price_max_satang', String(Math.round(Number(filters.priceMaxBaht) * 100)))
    const res = await api.get<{ data: ProductItem[] }>(`/products?${params.toString()}`, pageAbort.signal)
    products.value = res.data
  } catch (e) {
    if (isAbortError(e)) return
    errorMessage.value = apiErrorMessage(e, 'โหลดสินค้าไม่สำเร็จ')
  } finally {
    hasLoadedOnce.value = true
  }
}

watch(searchQuery, () => {
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(loadProducts, 350)
})

async function loadCertifications() {
  try {
    const res = await api.get<{ data: Certification[] }>('/user-certifications', pageAbort.signal)
    certifications.value = res.data
  } catch {
    // non-fatal — BR-1 gate just stays conservative (locked) on failure
  }
}

// ── Banner carousel — TASK-072 (human-confirmed via AskUserQuestion,
// 2026-08-02): admin can now pin each banner to one of 3 fixed spots on
// this page (App\Enums\StorefrontBannerPlacement). One fetch, grouped
// client-side into 3 arrays — 'top' keeps the original Row 2 position
// (under search/filter, above categories); 'middle' renders between the
// category row and "แนะนำสำหรับคุณ"; 'bottom' renders just above the main
// product grid. Loading/error UI stays only at the 'top' spot (as before
// TASK-072) so a slow/failed fetch doesn't sprinkle 3 skeletons/errors
// down the page — middle/bottom simply render nothing until banners.value
// is populated, consistent with DoD §9 (non-blocking).
// TASK-079 Phase 4 — `bannersLoading` removed; the whole page now shares
// one `initialLoading` skeleton (see its docblock). The error ref stays:
// each row still fails independently (DoD §9).
const banners = ref<StorefrontBannerItem[]>([])
const bannersError = ref('')
const bannersTop = computed(() => banners.value.filter((b) => (b.placement ?? 'top') === 'top'))
const bannersMiddle = computed(() => banners.value.filter((b) => b.placement === 'middle'))
const bannersBottom = computed(() => banners.value.filter((b) => b.placement === 'bottom'))

async function loadBanners() {
  bannersError.value = ''
  try {
    const res = await api.get<{ data: StorefrontBannerItem[] }>('/storefront-banners?is_active=1', pageAbort.signal)
    banners.value = res.data
  } catch (e) {
    // Non-blocking per DoD §9 — a banner-fetch failure must never stop
    // the rest of the page (search/grid/etc.) from rendering.
    if (isAbortError(e)) return
    bannersError.value = apiErrorMessage(e, 'โหลดแบนเนอร์ไม่สำเร็จ')
  }
}

// TASK-073 (2026-08-02, human-confirmed) supersedes ADR-020 decision #2
// — a banner's click target is now one of 3 types (link_type):
//   product  -> no product-detail route exists in this app (confirmed
//               against router/index.ts), so reuse the exact same
//               share/mint flow a product card's "แชร์" button uses.
//   url      -> open in a new tab (admin-only free-text input).
//   internal -> in-app navigation to one of this app's own authenticated
//               routes (whitelisted server-side, never free text).
function handleBannerClick(banner: StorefrontBannerItem) {
  if (banner.link_type === 'url') {
    if (banner.external_url) window.open(banner.external_url, '_blank', 'noopener')
    return
  }
  if (banner.link_type === 'internal') {
    if (banner.internal_path) router.push(banner.internal_path)
    return
  }
  if (!banner.product) return
  shareProduct(banner.product)
}

// ── Row 3: Category icon row ────────────────────────────────────────────
const categories = ref<ProductCategoryItem[]>([])
const categoriesError = ref('')

async function loadCategories() {
  categoriesError.value = ''
  try {
    const res = await api.get<{ data: ProductCategoryItem[] }>('/product-categories', pageAbort.signal)
    categories.value = res.data.filter((c) => c.is_active)
  } catch (e) {
    if (isAbortError(e)) return
    categoriesError.value = apiErrorMessage(e, 'โหลดหมวดหมู่ไม่สำเร็จ')
  }
}

/**
 * TASK-099 (human-reported 2026-08-04): "Vital Blueprint คลิ๊กไปค้นหาสินค้า
 * แล้วต้องกดอีกที ทำงานให้การใช้งานสับสน คลิ๊กแล้วกรองไปสินค้าเลยได้ไหม"
 *
 * The tap DID filter — `selectCategory` has always called `loadProducts()`.
 * The problem was that the result of that filter is the main grid, which
 * sits below the announcement banner, the top banner carousel, the category
 * row, the middle banner, the "แนะนำสำหรับคุณ" row and the bottom banner.
 * Nothing on screen changed, so the tap read as a no-op and agents tapped
 * again.
 *
 * So the page now has two modes, Shopee/Lazada-style: a merchandising
 * mode (banners + recommended) and a RESULTS mode. Anything that narrows
 * the catalogue — a category tap, a search term, a filter-panel value —
 * switches to results mode, which hides the merchandising rows (they are
 * not filtered and would only push the answer off-screen) and puts the
 * grid directly under the search bar.
 *
 * The category row itself stays visible in both modes: it is how the agent
 * switches or clears the category, and hiding it would strand them.
 */
const isBrowsingFiltered = computed(
  () => activeFilterCount.value > 0 || searchQuery.value.trim() !== '',
)

const activeCategoryName = computed(
  () => categories.value.find((c) => c.id === filters.categoryId)?.name ?? null,
)

// Tapping a category is a shortcut into the SAME row-1 filter state,
// not a separate mechanism (TASK-070 spec) — toggles off on re-tap.
function selectCategory(category: ProductCategoryItem) {
  filters.categoryId = filters.categoryId === category.id ? null : category.id
  loadProducts()

  // Scroll back to the top so the results header is the first thing in
  // view. Without this a tap made from further down the page still leaves
  // the agent looking at a banner.
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// Filter-panel category/brand dropdowns (brand list is filter-only, no
// dedicated row — a fetch failure here is non-fatal, same treatment as
// loadCertifications() above, since the dropdown just renders empty).
const brands = ref<BrandItem[]>([])
async function loadBrands() {
  try {
    const res = await api.get<{ data: BrandItem[] }>('/brands', pageAbort.signal)
    brands.value = res.data.filter((b) => b.is_active)
  } catch {
    // non-fatal — brand filter dropdown just stays empty
  }
}

// ── Row 4: Recommended for you ──────────────────────────────────────────
const recommended = ref<ProductItem[]>([])
const recommendedError = ref('')

async function loadRecommended() {
  recommendedError.value = ''
  try {
    const res = await api.get<{ data: ProductItem[] }>('/products/recommended', pageAbort.signal)
    recommended.value = res.data
  } catch (e) {
    if (isAbortError(e)) return
    recommendedError.value = apiErrorMessage(e, 'โหลดสินค้าแนะนำไม่สำเร็จ')
  }
}

// ── Announcement banners (TASK-080) ─────────────────────────────────────
// This page had no announcement data at all before TASK-080. The fetch is
// added to the SAME Promise.all as everything else (never a separate
// un-awaited call — Phase 4 consolidated this view onto one loading state
// on purpose) and shares the page AbortController.
//
// FALLBACK_DISPLAY_STYLE mirrors HomeView/AnnouncementsListView: it is
// used only if GET /announcement-settings fails, and matches
// config/announcements.php's own default_display_style (BR-7 — the real
// value is admin-editable, never hardcoded into logic).
const FALLBACK_DISPLAY_STYLE: AnnouncementDisplayStyle = 'bottom_sheet'
const announcementBanners = ref<BannerAwareAnnouncement[]>([])
const announcementDisplayStyle = ref<AnnouncementDisplayStyle>(FALLBACK_DISPLAY_STYLE)
const modalAnnouncement = ref<BannerAwareAnnouncement | null>(null)
const showAnnouncementModal = ref(false)

async function loadAnnouncements() {
  try {
    // Both announcement calls live inside this one loader so the page
    // Promise.all below keeps reading as "one entry per row" — the
    // settings call still degrades on its own (.catch → fallback style),
    // and a failure of either is non-fatal (DoD §9): the banners row
    // simply doesn't render and the storefront is unaffected.
    const [listRes, settingsRes] = await Promise.all([
      api.get<{ data: BannerAwareAnnouncement[] }>('/announcements', pageAbort.signal),
      api
        .get<{ data: { repeat_count: number; display_style: AnnouncementDisplayStyle } }>(
          '/announcement-settings',
          pageAbort.signal,
        )
        .catch(() => null),
    ])
    announcementBanners.value = bannerAnnouncementsForPage(listRes.data, 'products')
    announcementDisplayStyle.value = settingsRes?.data.display_style ?? FALLBACK_DISPLAY_STYLE
  } catch {
    // Non-fatal, and intentionally silent — announcements are optional
    // content on a product page; no error banner, same as the storefront
    // banner row's "just don't render" behavior.
  }
}

// Tapping an announcement banner opens the SAME AnnouncementModal and
// records the same view count as a news-card tap elsewhere in the app,
// so the auto-popup repeat limit (TASK-076) stays consistent no matter
// which screen the agent opened the announcement from.
function openAnnouncement(a: BannerAwareAnnouncement) {
  modalAnnouncement.value = a
  showAnnouncementModal.value = true
  recordAnnouncementView(authStore.user?.id, a.id)
}

// TASK-079 Phase 4 (UX audit) — the six loaders are the SAME six calls,
// with the same params, in the same six functions; they are simply
// awaited together now instead of being fired and forgotten, so the page
// can paint once when all of them are done rather than re-flowing five
// times. `finally` (not `.then`) because every loader already catches its
// own failure — the page must appear even if some rows came back empty.
onMounted(async () => {
  initialLoading.value = true
  try {
    await Promise.all([
      loadProducts(),
      loadCertifications(),
      loadBanners(),
      loadCategories(),
      loadBrands(),
      loadRecommended(),
      // TASK-080 — joins the same single-paint batch as the other rows.
      loadAnnouncements(),
    ])
  } finally {
    initialLoading.value = false
  }
})

// ── Share ────────────────────────────────────────────────────────────────
//
// Moved into useProductShare() on 2026-08-21, when ProductDetailView became
// the second screen with a "แชร์" button on a product. What was extracted is
// mostly the 422 handling — see the composable's docblock for the production
// incident it exists for. A second copy of that would be the copy that misses
// the next correction.
const {
  sharingProductId,
  shareError,
  showShareModal,
  shareLink,
  shareHeading,
  shareProduct,
} = useProductShare({ canShare: () => hasPassedBasic.value, signal: pageAbort.signal })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="box"
      title="สินค้า"
      subtitle="ค้นหาสินค้าและแชร์ให้ลูกค้า"
      description="แชร์หน้าสินค้าสาธารณะให้ลูกค้าดูคลิป รายละเอียด และเอกสารการขาย — ระบุตัวคุณเป็นผู้แนะนำโดยอัตโนมัติ"
      accent-color="brand"
      storage-key="product-browse"
      back-page="/"
      back-label="หน้าหลัก"
    >
      <template #tabs>
        <!-- Row 1: Search + Filter -->
        <div class="px-4 py-3">
          <div class="flex items-center gap-2">
            <div class="relative flex-1 min-w-0">
              <Icon name="search" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
              <input
                v-model="searchQuery"
                type="text"
                placeholder="ค้นหาสินค้า..."
                class="placeholder:text-ink-input-placeholder w-full min-h-[44px] pl-9 pr-3 py-2 rounded-lg border border-line-input bg-surface-input text-ink-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
            </div>
            <button
              type="button"
              :title="'ตัวกรอง'"
              class="relative shrink-0 w-11 h-11 rounded-lg border flex items-center justify-center transition-all active:scale-95"
              :class="activeFilterCount
                ? 'border-brand-300 bg-brand-50 text-brand-600'
                : 'border-line-card text-ink-card-muted hover:bg-surface-chip'"
              @click="filterPanelOpen = !filterPanelOpen"
            >
              <Icon name="filter" :size="16" />
              <span
                v-if="activeFilterCount"
                class="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-surface-primary text-ink-primary text-[9px] font-bold flex items-center justify-center"
              >
                {{ activeFilterCount }}
              </span>
            </button>
          </div>

          <!-- Filter panel: category / brand / price range.
               TASK-079 Phase 3 (UX audit): every control in here was
               py-2 text-xs (~32px). Raised to the 44px minimum via
               min-h-[44px]; the type size stays as-is so the panel keeps
               reading as a secondary control surface. -->
          <div v-if="filterPanelOpen" class="mt-3 pt-3 border-t border-line-card-subtle space-y-2.5">
            <div class="grid grid-cols-2 gap-2">
              <select
                v-model.number="filters.categoryId"
                class="w-full min-h-[44px] px-2.5 py-2 rounded-lg border border-line-input bg-surface-input text-ink-input text-xs focus:outline-none focus:ring-2 focus:ring-brand-200"
              >
                <option :value="null">ทุกหมวดหมู่</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
              </select>
              <select
                v-model.number="filters.brandId"
                class="w-full min-h-[44px] px-2.5 py-2 rounded-lg border border-line-input bg-surface-input text-ink-input text-xs focus:outline-none focus:ring-2 focus:ring-brand-200"
              >
                <option :value="null">ทุกแบรนด์</option>
                <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <input
                v-model.number="filters.priceMinBaht"
                type="number"
                min="0"
                placeholder="ราคาต่ำสุด (฿)"
                class="placeholder:text-ink-input-placeholder w-full min-h-[44px] px-2.5 py-2 rounded-lg border border-line-input bg-surface-input text-ink-input text-xs focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <input
                v-model.number="filters.priceMaxBaht"
                type="number"
                min="0"
                placeholder="ราคาสูงสุด (฿)"
                class="placeholder:text-ink-input-placeholder w-full min-h-[44px] px-2.5 py-2 rounded-lg border border-line-input bg-surface-input text-ink-input text-xs focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
            </div>
            <div class="flex items-center gap-2">
              <AppButton size="sm" class="flex-1" @click="applyFilters">นำตัวกรองไปใช้</AppButton>
              <AppButton variant="secondary" size="sm" @click="clearFilters">ล้างตัวกรอง</AppButton>
            </div>
          </div>
        </div>
      </template>
    </HeroHeader>

    <!-- TASK-079 Phase 4 (2026-08-03, UX audit — perceived performance):
         ONE skeleton for the whole page body, replacing the four separate
         hand-rolled `animate-pulse` blocks (banners / categories /
         recommended / grid) that used to resolve at four different moments
         and re-flow the page under the reader's thumb. Everything below the
         header now appears together, once. .content-fade keeps the swap
         from reading as a flash (Phase 3); <Transition> needs exactly ONE
         child per branch, hence the wrapper <div>. -->
    <Transition name="content-fade">
      <LoadingSkeleton v-if="initialLoading" type="list" :rows="4" />
      <div v-else>
    <!-- TASK-079 Phase 2 (UX audit): dead-end error banner — retry re-runs
         the product fetch with the current search/filter state intact. -->
    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3">
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="loadProducts"
      >
        ลองใหม่
      </button>
    </div>
    <div v-if="shareError" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger">
      {{ shareError }}
    </div>

    <!-- BR-1 gate — informational only here; browsing stays open -->
    <div
      v-if="hasLoadedOnce && !hasPassedBasic"
      class="mt-4 flex items-start gap-3 px-4 py-3 rounded-xl bg-surface-warning border border-line-card text-sm text-ink-warning"
    >
      <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
      <span>คุณต้องผ่านการรับรอง Basic ก่อนจึงจะแชร์ลิงก์สินค้าได้ (BR-1) — ไปที่หน้า Academy เพื่อเริ่มเรียน</span>
    </div>

    <!-- TASK-080 — announcement banners (page key 'products'), placed
         directly ABOVE the storefront banner carousel. They share the
         same visual treatment, so keeping them as their own row above
         Row 2 is what stops an agent from reading a company announcement
         as a product promotion (and vice-versa). -->
    <AnnouncementBanner v-if="!isBrowsingFiltered" :items="announcementBanners" class="mt-4" @select="openAnnouncement" />

    <!-- Row 2: Banner carousel — no placeholder when empty (admin-optional content).
         TASK-079 Phase 4: the per-row `bannersLoading` skeleton is gone —
         the page-level skeleton above covers it. The error branch stays:
         each row still fails independently (DoD §9). -->
    <div v-if="bannersError && !isBrowsingFiltered" class="mt-4 px-1 text-xs text-ink-danger">{{ bannersError }}</div>
    <div v-else-if="bannersTop.length && !isBrowsingFiltered" class="mt-4 flex gap-3 overflow-x-auto no-scrollbar snap-x snap-mandatory pb-1">
      <button
        v-for="banner in bannersTop"
        :key="banner.id"
        type="button"
        class="relative shrink-0 w-full aspect-[16/9] snap-start rounded-2xl overflow-hidden border border-line-card bg-surface-chip text-left active:scale-[0.98] transition-transform"
        @click="handleBannerClick(banner)"
      >
        <img v-if="banner.image_url" :src="banner.image_url" :alt="banner.title || banner.product?.name || 'banner'" class="w-full h-full object-cover" />
        <div v-else class="w-full h-full flex items-center justify-center text-ink-card-subtle">
          <Icon name="image" :size="24" />
        </div>
        <div v-if="banner.title" class="absolute inset-x-0 bottom-0 px-3 py-2 bg-gradient-to-t from-black/60 to-transparent">
          <p class="text-xs font-bold text-white truncate">{{ banner.title }}</p>
        </div>
      </button>
    </div>

    <!-- Row 3: Category icon row.
         TASK-079 Phase 3 (UX audit): banners, category icons and product
         cards are all tappable but the app had no `active:` state
         anywhere — on a touchscreen a tap looked like nothing happened
         until the next screen rendered, so agents tapped twice. -->
    <div v-if="categoriesError" class="mt-4 px-1 text-xs text-ink-danger">{{ categoriesError }}</div>
    <div v-else-if="categories.length" class="mt-4 flex gap-4 overflow-x-auto no-scrollbar pb-1">
      <button
        v-for="cat in categories"
        :key="cat.id"
        type="button"
        class="flex flex-col items-center gap-1.5 shrink-0 w-16 active:scale-95 transition-transform"
        @click="selectCategory(cat)"
      >
        <div
          class="w-12 h-12 rounded-full flex items-center justify-center transition"
          :class="filters.categoryId === cat.id ? 'bg-surface-primary text-ink-primary' : 'bg-surface-card/95 border border-line-card text-ink-card-muted'"
        >
          <Icon :name="cat.icon || 'tag'" :size="20" />
        </div>
        <span class="text-[11px] font-bold text-ink-app text-center leading-tight line-clamp-2">{{ cat.name }}</span>
      </button>
    </div>

    <!-- Banner placement: middle (TASK-072) — between categories and recommended -->
    <div v-if="bannersMiddle.length && !isBrowsingFiltered" class="mt-4 flex gap-3 overflow-x-auto no-scrollbar snap-x snap-mandatory pb-1">
      <button
        v-for="banner in bannersMiddle"
        :key="banner.id"
        type="button"
        class="relative shrink-0 w-full aspect-[16/9] snap-start rounded-2xl overflow-hidden border border-line-card bg-surface-chip text-left active:scale-[0.98] transition-transform"
        @click="handleBannerClick(banner)"
      >
        <img v-if="banner.image_url" :src="banner.image_url" :alt="banner.title || banner.product?.name || 'banner'" class="w-full h-full object-cover" />
        <div v-else class="w-full h-full flex items-center justify-center text-ink-card-subtle">
          <Icon name="image" :size="24" />
        </div>
        <div v-if="banner.title" class="absolute inset-x-0 bottom-0 px-3 py-2 bg-gradient-to-t from-black/60 to-transparent">
          <p class="text-xs font-bold text-white truncate">{{ banner.title }}</p>
        </div>
      </button>
    </div>

    <!-- Row 4: Recommended for you -->
    <div v-if="recommendedError && !isBrowsingFiltered" class="mt-4 px-1 text-xs text-ink-danger">{{ recommendedError }}</div>
    <template v-else-if="recommended.length && !isBrowsingFiltered">
      <h2 class="mt-4 px-1 text-sm font-bold text-ink-app">แนะนำสำหรับคุณ</h2>
      <div class="mt-2 flex gap-3 overflow-x-auto no-scrollbar pb-1">
        <div v-for="product in recommended" :key="'rec-' + product.id" class="shrink-0 w-40">
          <ProductCard
            :product="product"
            :has-passed-basic="hasPassedBasic"
            :sharing="sharingProductId === product.id"
            @share="shareProduct"
          />
        </div>
      </div>
    </template>

    <!-- Banner placement: bottom (TASK-072) — directly above the main grid -->
    <div v-if="bannersBottom.length && !isBrowsingFiltered" class="mt-4 flex gap-3 overflow-x-auto no-scrollbar snap-x snap-mandatory pb-1">
      <button
        v-for="banner in bannersBottom"
        :key="banner.id"
        type="button"
        class="relative shrink-0 w-full aspect-[16/9] snap-start rounded-2xl overflow-hidden border border-line-card bg-surface-chip text-left active:scale-[0.98] transition-transform"
        @click="handleBannerClick(banner)"
      >
        <img v-if="banner.image_url" :src="banner.image_url" :alt="banner.title || banner.product?.name || 'banner'" class="w-full h-full object-cover" />
        <div v-else class="w-full h-full flex items-center justify-center text-ink-card-subtle">
          <Icon name="image" :size="24" />
        </div>
        <div v-if="banner.title" class="absolute inset-x-0 bottom-0 px-3 py-2 bg-gradient-to-t from-black/60 to-transparent">
          <p class="text-xs font-bold text-white truncate">{{ banner.title }}</p>
        </div>
      </button>
    </div>

        <!-- TASK-099 — results header. Only in filtered mode, and it is
             what makes the category tap feel like it did something: the
             merchandising rows above have collapsed, this row names what
             is being filtered, and the grid starts immediately below. -->
        <div v-if="isBrowsingFiltered" class="mt-4 flex items-center gap-2 flex-wrap">
          <span class="text-sm font-bold text-ink-app">
            ผลลัพธ์ {{ products.length }} รายการ
          </span>
          <span
            v-if="activeCategoryName"
            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-surface-chip text-ink-chip text-[11px] font-bold"
          >
            {{ activeCategoryName }}
          </span>
          <span
            v-if="searchQuery.trim()"
            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-surface-chip text-ink-chip text-[11px] font-bold"
          >
            “{{ searchQuery.trim() }}”
          </span>
          <button
            type="button"
            class="ml-auto min-h-[44px] px-3 text-xs font-bold text-ink-brand active:scale-95 transition"
            @click="clearAllFilters"
          >
            ล้างตัวกรอง
          </button>
        </div>

        <!-- Main grid (existing behavior, retained) — respects rows 1/3 filter
             state. TASK-079 Phase 4: its own inner <Transition> +
             `loading && !hasLoadedOnce` skeleton is gone — that condition can
             only ever be true during the initial load, which the page-level
             skeleton above now owns. A later search/filter re-fetch swaps the
             grid in place, exactly as it did before. -->
        <EmptyState
          v-if="!products.length"
          icon="box"
          title="ไม่พบสินค้า"
          message="ลองค้นหาด้วยคำอื่น ปรับตัวกรอง หรือติดต่อแอดมินเพื่อเพิ่มสินค้า"
          class="mt-4"
        />
        <div v-else class="grid grid-cols-2 gap-3 mt-4">
          <ProductCard
            v-for="product in products"
            :key="product.id"
            :product="product"
            :has-passed-basic="hasPassedBasic"
            :sharing="sharingProductId === product.id"
            @share="shareProduct"
          />
        </div>
      </div>
    </Transition>

    <!-- TASK-212 — a product-share link is a broadcast link with no
         intended reader, so no :default-email: the agent types who it
         goes to. -->
    <ShareLinkModal
      v-model:show="showShareModal"
      :url="shareLink?.short_url ?? shareLink?.public_url ?? ''"
      :heading="shareHeading"
      email-type="product_share"
      :email-target-id="shareLink?.id ?? null"
    />

    <!-- TASK-080 — same modal component the other two views mount. No
         `start-expanded` prop: every open here is a manual banner tap,
         never an auto-popup, so AnnouncementModal's own default (true) is
         the correct behavior. -->
    <AnnouncementModal
      :show="showAnnouncementModal"
      :announcement="modalAnnouncement"
      :display-style="announcementDisplayStyle"
      @close="showAnnouncementModal = false"
    />
  </main>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
