<script setup lang="ts">
/**
 * CatalogManagementView — "แคตตาล็อกกลาง" (Global Catalog), ADR-036/TASK-214.
 *
 * Super-Admin-only screen for the shared, cross-company catalog that
 * TASK-211/212/213 built on the backend: catalog_brands / catalog_categories
 * / product_catalog_items — all global tables with no company_id. A
 * per-company product can OPTIONALLY link to a product_catalog_items row
 * (see ProductEditView.vue's own catalog-link UI); when linked, the
 * product's name/brand/category/description/spec_description are resolved
 * from here instead of the product's own columns.
 *
 * Route is gated `requiresSuperAdmin: true` (router/index.ts) and the nav
 * entry is a Super-Admin-only sub-item under "สินค้า" (AdminNavigation.vue)
 * — same precedent as CompanyManagementView's '/companies'. Every user who
 * can reach this screen at all is already Super Admin, so the write-action
 * v-if guards below are belt-and-suspenders (matches this app's existing
 * convention of gating both the route AND the individual controls — see
 * ProductCatalogView.vue's isSuperAdmin usage).
 *
 * Structurally this is a smaller sibling of ProductCatalogView.vue's own
 * brands/categories tabs: catalog_brands/catalog_categories are the same
 * shape as brands/product_categories minus company_id, so the CRUD forms
 * below are deliberately patterned after that file almost verbatim.
 * product_catalog_items also reuses that same list/inline-edit shape, with
 * an added brand/category picker and a read-only linked_product_count
 * badge (media/specs upload UI is deliberately NOT built here — the
 * backend contract is explicit that those two arrays are always empty
 * right now, deferred to a future sprint).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import IconPicker from '@/design-system/components/IconPicker.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/auth'
// TASK-209 P4 — this screen ignores the header company scope on purpose.
import PlatformScopeBadge from '@/design-system/components/PlatformScopeBadge.vue'

interface CatalogBrand {
  id: number
  company_id: null
  name: string
  logo_path: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}
interface CatalogCategory {
  id: number
  company_id: null
  name: string
  icon: string | null
  sort_order: number
  is_active: boolean
  pipeline_template_id: null
  created_at: string
  updated_at: string
}
interface ProductCatalogItem {
  id: number
  catalog_brand_id: number
  catalog_category_id: number
  catalog_brand: CatalogBrand
  catalog_category: CatalogCategory
  name: string
  description: string | null
  spec_description: string | null
  // TASK-251 — BR-3 satang. The price each company's copy is CREATED with,
  // never a price anybody is currently selling at.
  default_price_satang: number | null
  is_active: boolean
  media: unknown[]
  specs: unknown[]
  linked_product_count: number
  created_at: string
  updated_at: string
}

const route = useRoute()
const authStore = useAuthStore()
// Belt-and-suspenders — see the header docblock. The route itself already
// blocks a non-Super-Admin from reaching this screen (router guard,
// requiresSuperAdmin), this just also hides every write control.
const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin')

type Tab = 'items' | 'brands' | 'categories'
const VALID_TABS: Tab[] = ['items', 'brands', 'categories']
function initialTab(): Tab {
  const q = route.query.tab
  return typeof q === 'string' && (VALID_TABS as string[]).includes(q) ? (q as Tab) : 'items'
}
const activeTab = ref<Tab>(initialTab())
const tabs: { key: Tab; label: string; icon: string }[] = [
  { key: 'items', label: 'รายการแคตตาล็อก', icon: 'cube' },
  { key: 'brands', label: 'แบรนด์', icon: 'tag' },
  { key: 'categories', label: 'หมวดหมู่', icon: 'layers' },
]

// TASK-215 — ProductEditView's "ไปที่แคตตาล็อกกลาง →" link deep-links here
// with ?tab=items&highlight=<id>. Purely a visual nudge (ring + scroll-into-
// view once) — no route/query mutation, so navigating away and back doesn't
// re-trigger it from a stale bookmark URL sitting in history.
const highlightId = computed(() => {
  const q = route.query.highlight
  const n = typeof q === 'string' ? Number(q) : NaN
  return Number.isFinite(n) ? n : null
})

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

const brands = ref<CatalogBrand[]>([])
const categories = ref<CatalogCategory[]>([])
const items = ref<ProductCatalogItem[]>([])

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [b, c, i] = await Promise.all([
      api.get<{ data: CatalogBrand[] }>('/catalog-brands'),
      api.get<{ data: CatalogCategory[] }>('/catalog-categories'),
      api.get<{ data: ProductCatalogItem[] }>('/product-catalog-items'),
    ])
    brands.value = b.data
    categories.value = c.data
    items.value = i.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

watch(activeTab, (tab) => {
  if (tab === 'items' && highlightId.value) {
    // Best-effort scroll to the linked item once its tab is actually showing.
    requestAnimationFrame(() => {
      document.getElementById(`catalog-item-${highlightId.value}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    })
  }
})

function deleteFailureMessage(e: unknown): string {
  if (e instanceof ApiError) {
    return e.status === 422 && e.message ? e.message : `ลบไม่สำเร็จ (${e.status})`
  }
  return 'ลบไม่สำเร็จ'
}

// ── Catalog brand ──
const showBrandForm = ref(false)
const brandForm = ref({ name: '' })
async function submitBrand() {
  await api.post('/catalog-brands', { name: brandForm.value.name })
  brandForm.value = { name: '' }
  showBrandForm.value = false
  await loadAll()
}
const editingBrandId = ref<number | null>(null)
const editBrandForm = ref({ name: '', is_active: true })
const editBrandError = ref('')
const savingBrandEdit = ref(false)
function startEditBrand(brand: CatalogBrand): void {
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
    await api.put(`/catalog-brands/${editingBrandId.value}`, {
      name: editBrandForm.value.name,
      is_active: editBrandForm.value.is_active,
    })
    editingBrandId.value = null
    await loadAll()
  } catch (e) {
    editBrandError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingBrandEdit.value = false
  }
}
const pendingDeleteBrand = ref<CatalogBrand | null>(null)
function deleteBrand(brand: CatalogBrand): void {
  pendingDeleteBrand.value = brand
}
async function confirmDeleteBrand(): Promise<void> {
  const brand = pendingDeleteBrand.value
  if (!brand) return
  try {
    await api.delete(`/catalog-brands/${brand.id}`)
    brands.value = brands.value.filter((b) => b.id !== brand.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteBrand.value = null
  }
}

// ── Catalog category ──
const showCategoryForm = ref(false)
const categoryForm = ref({ name: '', icon: '', sort_order: 0 })
async function submitCategory() {
  await api.post('/catalog-categories', {
    name: categoryForm.value.name,
    sort_order: categoryForm.value.sort_order,
    ...(categoryForm.value.icon ? { icon: categoryForm.value.icon } : {}),
  })
  categoryForm.value = { name: '', icon: '', sort_order: 0 }
  showCategoryForm.value = false
  await loadAll()
}
const editingCategoryId = ref<number | null>(null)
const editCategoryForm = ref({ name: '', icon: '', sort_order: 0, is_active: true })
const editCategoryError = ref('')
const savingCategoryEdit = ref(false)
function startEditCategory(category: CatalogCategory): void {
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
    await api.put(`/catalog-categories/${editingCategoryId.value}`, {
      name: editCategoryForm.value.name,
      icon: editCategoryForm.value.icon || null,
      sort_order: editCategoryForm.value.sort_order,
      is_active: editCategoryForm.value.is_active,
    })
    editingCategoryId.value = null
    await loadAll()
  } catch (e) {
    editCategoryError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingCategoryEdit.value = false
  }
}
const pendingDeleteCategory = ref<CatalogCategory | null>(null)
function deleteCategory(category: CatalogCategory): void {
  pendingDeleteCategory.value = category
}
async function confirmDeleteCategory(): Promise<void> {
  const category = pendingDeleteCategory.value
  if (!category) return
  try {
    await api.delete(`/catalog-categories/${category.id}`)
    categories.value = categories.value.filter((c) => c.id !== category.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteCategory.value = null
  }
}

// ── Product catalog item ──
const showItemForm = ref(false)
const editingItemId = ref<number | null>(null)
const itemForm = ref({
  catalog_brand_id: '' as number | '',
  catalog_category_id: '' as number | '',
  name: '',
  description: '',
  spec_description: '',
  /*
   * TASK-251 — held in BAHT, sent in satang (BR-3).
   *
   * The admin types 8900, the API stores 890000. Keeping the conversion at
   * this one boundary is the same rule the rest of the app follows: satang
   * everywhere inside, baht only where a human reads or types it.
   */
  default_price_baht: '' as number | '',
  is_active: true,
})
const itemFormError = ref('')
const savingItem = ref(false)

function resetItemForm(): void {
  itemForm.value = { catalog_brand_id: '', catalog_category_id: '', name: '', description: '', spec_description: '', default_price_baht: '', is_active: true }
  editingItemId.value = null
  itemFormError.value = ''
}
function openCreateItemForm(): void {
  resetItemForm()
  showItemForm.value = true
}
function openEditItemForm(item: ProductCatalogItem): void {
  editingItemId.value = item.id
  itemForm.value = {
    catalog_brand_id: item.catalog_brand_id,
    catalog_category_id: item.catalog_category_id,
    name: item.name,
    description: item.description ?? '',
    spec_description: item.spec_description ?? '',
    default_price_baht: item.default_price_satang === null ? '' : item.default_price_satang / 100,
    is_active: item.is_active,
  }
  itemFormError.value = ''
  showItemForm.value = true
}
function closeItemForm(): void {
  showItemForm.value = false
  resetItemForm()
}
async function submitItemForm(): Promise<void> {
  if (!itemForm.value.catalog_brand_id || !itemForm.value.catalog_category_id || !itemForm.value.name) {
    itemFormError.value = 'กรุณาเลือกแบรนด์ หมวดหมู่ และตั้งชื่อสินค้าในแคตตาล็อก'
    return
  }
  /*
   * TASK-251 — checked here as well as server-side, because saving this form
   * writes a priced listing into every company. `=== ''` deliberately, not a
   * falsy test: 0 is a price somebody may genuinely mean, and treating it as
   * "empty" would refuse the one value the server is happy to accept.
   */
  if (itemForm.value.default_price_baht === '') {
    itemFormError.value = 'กรุณาระบุราคาเริ่มต้น — การบันทึกจะเพิ่มสินค้านี้ให้ทุกบริษัท (ปิดการใช้งานไว้)'
    return
  }
  savingItem.value = true
  itemFormError.value = ''
  try {
    const payload = {
      catalog_brand_id: Number(itemForm.value.catalog_brand_id),
      catalog_category_id: Number(itemForm.value.catalog_category_id),
      name: itemForm.value.name,
      description: itemForm.value.description || undefined,
      spec_description: itemForm.value.spec_description || undefined,
      // BR-3 — baht x 100, rounded so a typed 8900.005 cannot become a
      // fractional satang the database would silently truncate.
      default_price_satang: Math.round(Number(itemForm.value.default_price_baht) * 100),
      is_active: itemForm.value.is_active,
    }
    if (editingItemId.value) {
      await api.put(`/product-catalog-items/${editingItemId.value}`, payload)
    } else {
      await api.post('/product-catalog-items', payload)
    }
    closeItemForm()
    await loadAll()
  } catch (e) {
    itemFormError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingItem.value = false
  }
}
const pendingDeleteItem = ref<ProductCatalogItem | null>(null)
function deleteItem(item: ProductCatalogItem): void {
  pendingDeleteItem.value = item
}
async function confirmDeleteItem(): Promise<void> {
  const item = pendingDeleteItem.value
  if (!item) return
  try {
    await api.delete(`/product-catalog-items/${item.id}`)
    items.value = items.value.filter((i) => i.id !== item.id)
  } catch (e) {
    errorMessage.value = deleteFailureMessage(e)
  } finally {
    pendingDeleteItem.value = null
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="globe"
      title="แคตตาล็อกกลาง"
      subtitle="แบรนด์ / หมวดหมู่ / รายการสินค้า ที่ใช้ร่วมกันทุกบริษัท (ADR-036)"
      description="เมื่อสินค้าของบริษัทหนึ่งเชื่อมกับรายการในแคตตาล็อกนี้ ชื่อ/แบรนด์/หมวดหมู่/คำอธิบาย/คำอธิบายสเปคของสินค้านั้นจะดึงมาจากที่นี่เสมอ — ราคาและค่าคอมมิชชั่นยังคงเป็นของแต่ละบริษัทแยกกัน แก้ไขได้เฉพาะ Super Admin เท่านั้น"
      accent-color="brand"
      storage-key="catalog-management"
    >
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in tabs"
            :key="t.key"
            type="button"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key"
          >
            <Icon :name="t.icon" :size="14" />

    <PlatformScopeBadge reason="แคตตาล็อกกลางใช้ร่วมกันทุกบริษัท (ADR-036)" />
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
      <!-- Catalog items -->
      <section v-if="activeTab === 'items'" class="mt-4">
        <div class="flex justify-end mb-2">
          <button v-if="isSuperAdmin" class="btn-primary" @click="openCreateItemForm">
            + เพิ่มรายการแคตตาล็อก
          </button>
        </div>

        <div v-if="showItemForm && isSuperAdmin" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 space-y-3">
          <p class="text-sm font-bold text-slate-700">{{ editingItemId ? 'แก้ไขรายการแคตตาล็อก' : 'เพิ่มรายการแคตตาล็อกใหม่' }}</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="text-xs font-bold text-slate-500">แบรนด์</label>
              <select v-model="itemForm.catalog_brand_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="" disabled>เลือกแบรนด์</option>
                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
              </select>
            </div>
            <div>
              <label class="text-xs font-bold text-slate-500">หมวดหมู่</label>
              <select v-model="itemForm.catalog_category_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="" disabled>เลือกหมวดหมู่</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">ชื่อสินค้าในแคตตาล็อก</label>
            <input v-model="itemForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <!-- TASK-251 — the field that makes the rest of the form reach
               every company. The note beside it is not decoration: an admin
               who does not know that saving creates a listing in every
               tenant will type a price for the wrong audience. -->
          <div>
            <label class="text-xs font-bold text-slate-500">ราคาเริ่มต้น (บาท)</label>
            <input
              v-model="itemForm.default_price_baht"
              type="number"
              min="0"
              step="1"
              required
              data-test="catalog-default-price"
              class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
            />
            <p class="mt-1 text-[11px] text-slate-400">
              ราคานี้ใช้ตอน<strong>สร้าง</strong>รายการสินค้าให้แต่ละบริษัทเท่านั้น — หลังจากนั้นแต่ละบริษัทแก้ราคาของตัวเองได้
              และการแก้ตรงนี้ภายหลังจะไม่ไปเปลี่ยนราคาที่บริษัทตั้งไว้แล้ว
            </p>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">คำอธิบาย (ไม่บังคับ)</label>
            <textarea v-model="itemForm.description" rows="3" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm resize-y" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">คำอธิบายสเปค (ไม่บังคับ)</label>
            <textarea v-model="itemForm.spec_description" rows="3" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm resize-y" />
          </div>
          <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer">
            <input v-model="itemForm.is_active" type="checkbox" class="rounded border-slate-300" /> ใช้งาน
          </label>
          <!-- Said BEFORE the click, not after it. A product appearing in
               every company's catalog is a surprise if it is only explained
               in a success message. -->
          <p v-if="!editingItemId" data-test="propagation-notice" class="text-xs text-slate-500 bg-slate-50 border border-dashed border-slate-200 rounded-lg px-3 py-2">
            เมื่อบันทึก ระบบจะเพิ่มสินค้านี้ให้<strong>ทุกบริษัท</strong>โดยอัตโนมัติ และ<strong>ปิดการใช้งานไว้ทั้งหมด</strong>
            — แต่ละบริษัทเปิดขายเองเมื่อพร้อม และตั้งราคา/ค่าคอมมิชชั่นของตัวเองได้
          </p>
          <p v-if="itemFormError" class="text-xs font-bold text-rose-600">{{ itemFormError }}</p>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="closeItemForm">ยกเลิก</button>
            <button type="button" :disabled="savingItem" class="btn-primary" @click="submitItemForm">
              {{ savingItem ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </div>

        <EmptyState v-if="!items.length" icon="cube" title="ยังไม่มีรายการในแคตตาล็อกกลาง" />
        <div v-else class="space-y-2">
          <div
            v-for="item in items"
            :id="`catalog-item-${item.id}`"
            :key="item.id"
            class="bg-white/95 border rounded-xl p-4 flex items-center justify-between gap-3 transition-shadow"
            :class="highlightId === item.id ? 'border-brand-400 ring-2 ring-brand-200' : 'border-slate-200'"
          >
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900 truncate">{{ item.name }}</p>
              <p class="text-xs text-slate-400">
                {{ item.catalog_brand.name }} · {{ item.catalog_category.name }}
                <template v-if="item.default_price_satang !== null">
                  · ราคาเริ่มต้น {{ (item.default_price_satang / 100).toLocaleString('th-TH') }} บาท
                </template>
              </p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <span
                class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"
                title="จำนวนสินค้าที่เชื่อมกับรายการนี้ (ทุกบริษัท)"
              >
                เชื่อมอยู่ {{ item.linked_product_count }} สินค้า
              </span>
              <span :class="item.is_active ? 'text-emerald-600' : 'text-slate-400'" class="text-xs font-bold">{{ item.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span>
              <template v-if="isSuperAdmin">
                <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="openEditItemForm(item)">
                  <Icon name="pencil" :size="14" />
                </button>
                <button class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteItem(item)">
                  <Icon name="trash" :size="14" />
                </button>
              </template>
            </div>
          </div>
        </div>
      </section>

      <!-- Catalog brands -->
      <section v-if="activeTab === 'brands'" class="mt-4">
        <div class="flex justify-end mb-2">
          <button v-if="isSuperAdmin" class="btn-primary" @click="showBrandForm = !showBrandForm">
            + เพิ่มแบรนด์
          </button>
        </div>
        <form v-if="showBrandForm && isSuperAdmin" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 flex gap-2 items-end" @submit.prevent="submitBrand">
          <div class="flex-1">
            <label class="text-xs font-bold text-slate-500">ชื่อแบรนด์</label>
            <input v-model="brandForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <button type="submit" class="btn-primary">บันทึก</button>
        </form>
        <EmptyState v-if="!brands.length" icon="tag" title="ยังไม่มีแบรนด์ในแคตตาล็อกกลาง" />
        <div v-else class="space-y-2">
          <div v-for="b in brands" :key="b.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
            <template v-if="editingBrandId === b.id && isSuperAdmin">
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
                <template v-if="isSuperAdmin">
                  <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditBrand(b)">
                    <Icon name="pencil" :size="14" />
                  </button>
                  <button class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteBrand(b)">
                    <Icon name="trash" :size="14" />
                  </button>
                </template>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Catalog categories -->
      <section v-if="activeTab === 'categories'" class="mt-4">
        <div class="flex justify-end mb-2">
          <button v-if="isSuperAdmin" class="btn-primary" @click="showCategoryForm = !showCategoryForm">
            + เพิ่มหมวดหมู่
          </button>
        </div>
        <form v-if="showCategoryForm && isSuperAdmin" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 space-y-3" @submit.prevent="submitCategory">
          <div>
            <label class="text-xs font-bold text-slate-500">ชื่อหมวดหมู่</label>
            <input v-model="categoryForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">ไอคอน (ไม่บังคับ)</label>
            <IconPicker v-model="categoryForm.icon" fallback-icon="box" fallback-label="ยังไม่ได้เลือกไอคอน" clear-label="ล้างไอคอน" />
          </div>
          <div class="flex justify-end">
            <button type="submit" class="btn-primary">บันทึก</button>
          </div>
        </form>
        <EmptyState v-if="!categories.length" icon="layers" title="ยังไม่มีหมวดหมู่ในแคตตาล็อกกลาง" />
        <div v-else class="space-y-2">
          <div v-for="c in categories" :key="c.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
            <template v-if="editingCategoryId === c.id && isSuperAdmin">
              <div class="space-y-3">
                <div>
                  <label class="text-xs font-bold text-slate-500">ชื่อหมวดหมู่</label>
                  <input v-model="editCategoryForm.name" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
                </div>
                <div>
                  <label class="text-xs font-bold text-slate-500 block mb-1">ไอคอน (ไม่บังคับ)</label>
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
                <template v-if="isSuperAdmin">
                  <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditCategory(c)">
                    <Icon name="pencil" :size="14" />
                  </button>
                  <button class="text-slate-400 hover:text-rose-600" title="ลบ" @click="deleteCategory(c)">
                    <Icon name="trash" :size="14" />
                  </button>
                </template>
              </div>
            </div>
          </div>
        </div>
      </section>
    </template>

    <ConfirmDialog
      :show="pendingDeleteBrand !== null"
      variant="danger"
      :body='pendingDeleteBrand ? `ลบแบรนด์ "${pendingDeleteBrand.name}" ออกจากแคตตาล็อกกลาง? แบรนด์นี้ใช้ร่วมกันทุกบริษัท` : ""'
      @confirm="confirmDeleteBrand"
      @update:show="(v) => { if (!v) pendingDeleteBrand = null }"
    />
    <ConfirmDialog
      :show="pendingDeleteCategory !== null"
      variant="danger"
      :body='pendingDeleteCategory ? `ลบหมวดหมู่ "${pendingDeleteCategory.name}" ออกจากแคตตาล็อกกลาง? หมวดหมู่นี้ใช้ร่วมกันทุกบริษัท` : ""'
      @confirm="confirmDeleteCategory"
      @update:show="(v) => { if (!v) pendingDeleteCategory = null }"
    />
    <ConfirmDialog
      :show="pendingDeleteItem !== null"
      variant="danger"
      :body='pendingDeleteItem ? `ลบรายการ "${pendingDeleteItem.name}" ออกจากแคตตาล็อกกลาง? ${pendingDeleteItem.linked_product_count > 0 ? "รายการนี้ยังมีสินค้าเชื่อมอยู่ " + pendingDeleteItem.linked_product_count + " รายการ" : ""}` : ""'
      @confirm="confirmDeleteItem"
      @update:show="(v) => { if (!v) pendingDeleteItem = null }"
    />
  </main>
</template>
