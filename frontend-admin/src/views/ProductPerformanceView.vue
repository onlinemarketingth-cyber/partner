<script setup lang="ts">
/**
 * ProductPerformanceView — "มุมมองสินค้า" (TASK-040, item 2.2 + 2.3b).
 *
 * Two independent sections on one scrollable page (not a tab switcher —
 * one is a read-only report, the other a CRUD list, so they don't need
 * to share state the way RewardCenterView.vue's two tabs do):
 *
 *   Section A — ABC sales grading (read-only). GET /products-abc-grades,
 *   computed live server-side (Pareto 80/15/5), never persisted. The
 *   revenue figure is explicitly an ESTIMATE (sold_count × CURRENT
 *   price — the schema has no historical price snapshot), so this
 *   screen must disclose that honestly rather than presenting it as an
 *   actual historical revenue figure.
 *
 *   Section B — product_price_promotions CRUD (display-only discounted
 *   pricing shown to customers at a branch/period; does NOT feed
 *   commission calculation or a real checkout — also must be disclosed,
 *   same "honest gap" pattern as AgentManagementView.vue's overview
 *   gap-note box). Modal structure mirrors AgentPromotionsView.vue
 *   closely (company_id Super-Admin-only rule, BuddhistDateInput,
 *   status badge, fetchAllPages product lookup).
 *
 * Money: BR-3 — all satang fields divide by 100 only at display, and
 * are multiplied back by 100 before being sent.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import BuddhistDateInput from '@/design-system/components/BuddhistDateInput.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}
function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

// ══════════════════════════ Section A: ABC grading ══════════════════════════
interface AbcGradeRow {
  product_id: number
  product_name: string
  sold_count: number
  estimated_revenue_satang: number
  revenue_share_percent: number
  cumulative_percent: number
  grade: 'A' | 'B' | 'C' | 'D'
}
type WindowOption = 30 | 90 | 365 | null
const abcWindow = ref<WindowOption>(null)
const abcRows = ref<AbcGradeRow[]>([])
const abcLoading = ref(false)
const abcLoadedOnce = ref(false)
const abcError = ref('')

async function loadAbcGrades() {
  abcLoading.value = true
  abcError.value = ''
  try {
    const path = abcWindow.value ? `/products-abc-grades?window_days=${abcWindow.value}` : '/products-abc-grades'
    const res = await api.get<{ data: AbcGradeRow[]; window_days: number | null; computed_at: string }>(path)
    abcRows.value = res.data
  } catch (e) {
    abcError.value = apiErrorMessage(e, 'โหลดข้อมูลเกรดสินค้าไม่สำเร็จ')
  } finally {
    abcLoading.value = false
    abcLoadedOnce.value = true
  }
}
watch(abcWindow, loadAbcGrades)

const windowTabs: { key: WindowOption; label: string }[] = [
  { key: 30, label: '30 วัน' },
  { key: 90, label: '90 วัน' },
  { key: 365, label: '365 วัน' },
  { key: null, label: 'ทั้งหมด' },
]

function gradeBadgeClass(grade: AbcGradeRow['grade']): string {
  return {
    A: 'bg-emerald-50 text-emerald-700',
    B: 'bg-sky-50 text-sky-700',
    C: 'bg-amber-50 text-amber-700',
    D: 'bg-slate-100 text-slate-500',
  }[grade]
}

// ══════════════════════════ Section B: Price promotions ══════════════════════════
interface PricePromotion {
  id: number
  company_id: number
  product_id: number
  product_name: string
  product_price_satang: number
  discounted_price_satang: number
  note: string | null
  status: 'draft' | 'active' | 'ended'
  is_currently_active: boolean
  starts_at: string
  ends_at: string | null
  created_by: number
  created_at: string
}
const promotions = ref<PricePromotion[]>([])
const promoLoading = ref(false)
const promoLoadedOnce = ref(false)
const promoError = ref('')

async function loadPromotions() {
  promoLoading.value = true
  promoError.value = ''
  try {
    const res = await api.get<{ data: PricePromotion[] }>('/product-price-promotions')
    promotions.value = res.data
  } catch (e) {
    promoError.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    promoLoading.value = false
    promoLoadedOnce.value = true
  }
}

// ── Lazily-loaded lookups for the create/edit form (same pattern as
// AgentPromotionsView.vue) ──
interface CompanyOption { id: number; name: string }
interface ProductOption { id: number; company_id: number; name: string; price_satang: number }
const lookupsLoaded = ref(false)
const loadingLookups = ref(false)
const companies = ref<CompanyOption[]>([])
const products = ref<ProductOption[]>([])

async function fetchAllPages<T>(path: string): Promise<T[]> {
  const sep = path.includes('?') ? '&' : '?'
  const first = await api.get<{ data: T[]; meta?: { last_page: number } }>(`${path}${sep}page=1`)
  const items = [...first.data]
  const lastPage = first.meta?.last_page ?? 1
  for (let page = 2; page <= lastPage; page++) {
    const next = await api.get<{ data: T[] }>(`${path}${sep}page=${page}`)
    items.push(...next.data)
  }
  return items
}

async function ensureLookupsLoaded() {
  if (lookupsLoaded.value || loadingLookups.value) return
  loadingLookups.value = true
  try {
    const requests: Promise<unknown>[] = [fetchAllPages<ProductOption>('/products')]
    if (isSuperAdmin.value) requests.push(api.get<{ data: CompanyOption[] }>('/companies'))
    const [p, c] = await Promise.all(requests)
    products.value = p as ProductOption[]
    if (c) companies.value = (c as { data: CompanyOption[] }).data
    lookupsLoaded.value = true
  } catch (e) {
    promoError.value = apiErrorMessage(e, 'โหลดข้อมูลประกอบไม่สำเร็จ')
  } finally {
    loadingLookups.value = false
  }
}

onMounted(() => {
  loadAbcGrades()
  loadPromotions()
})

const productOptionsForForm = computed(() => {
  if (!isSuperAdmin.value) return products.value
  return form.value.company_id ? products.value.filter((p) => p.company_id === Number(form.value.company_id)) : products.value
})

// ── Create/edit form ──
const showForm = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const formError = ref('')
const form = ref({
  company_id: '' as string | number,
  product_id: '' as string | number,
  discounted_price_input: '' as string | number, // THB, converted to satang before send
  note: '',
  status: 'draft' as 'draft' | 'active' | 'ended',
  starts_at: '',
  ends_at: '',
})

function resetForm() {
  form.value = {
    company_id: '',
    product_id: '',
    discounted_price_input: '',
    note: '',
    status: 'draft',
    starts_at: '',
    ends_at: '',
  }
  editingId.value = null
  formError.value = ''
}
async function openCreateForm() {
  resetForm()
  showForm.value = true
  await ensureLookupsLoaded()
}
async function openEditForm(p: PricePromotion) {
  editingId.value = p.id
  form.value = {
    company_id: p.company_id,
    product_id: p.product_id,
    discounted_price_input: p.discounted_price_satang / 100,
    note: p.note ?? '',
    status: p.status,
    starts_at: p.starts_at,
    ends_at: p.ends_at ?? '',
  }
  formError.value = ''
  showForm.value = true
  await ensureLookupsLoaded()
}
function closeForm() {
  showForm.value = false
}

function validateForm(): string {
  if (isSuperAdmin.value && !editingId.value && !form.value.company_id) return 'กรุณาเลือกบริษัท'
  if (!form.value.product_id) return 'กรุณาเลือกสินค้า'
  if (form.value.discounted_price_input === '' || form.value.discounted_price_input === null || Number(form.value.discounted_price_input) < 0) {
    return 'กรุณาระบุราคาลดที่ถูกต้อง'
  }
  if (!form.value.starts_at) return 'กรุณาระบุวันที่เริ่มต้น'
  if (form.value.ends_at && form.value.ends_at < form.value.starts_at) return 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มต้น'
  return ''
}

async function submitForm() {
  const validation = validateForm()
  if (validation) {
    formError.value = validation
    return
  }
  saving.value = true
  formError.value = ''
  try {
    const payload = {
      ...(isSuperAdmin.value && form.value.company_id ? { company_id: Number(form.value.company_id) } : {}),
      product_id: Number(form.value.product_id),
      discounted_price_satang: Math.round(Number(form.value.discounted_price_input) * 100),
      note: form.value.note || null,
      status: form.value.status,
      starts_at: form.value.starts_at,
      ends_at: form.value.ends_at || null,
    }
    if (editingId.value) {
      await api.put(`/product-price-promotions/${editingId.value}`, payload)
    } else {
      await api.post('/product-price-promotions', payload)
    }
    closeForm()
    await loadPromotions()
  } catch (e) {
    formError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

// TASK-066 (human-reported 2026-07-31) — native window.confirm() replaced
// with the ConfirmDialog modal.
const pendingDeletePromotion = ref<PricePromotion | null>(null)
function deletePromotion(p: PricePromotion) {
  pendingDeletePromotion.value = p
}
async function confirmDeletePromotion() {
  const p = pendingDeletePromotion.value
  if (!p) return
  try {
    await api.delete(`/product-price-promotions/${p.id}`)
    promotions.value = promotions.value.filter((x) => x.id !== p.id)
  } catch (e) {
    promoError.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  } finally {
    pendingDeletePromotion.value = null
  }
}

function statusBadgeClass(status: PricePromotion['status']): string {
  if (status === 'active') return 'bg-emerald-50 text-emerald-700'
  if (status === 'ended') return 'bg-slate-100 text-slate-400 line-through'
  return 'bg-slate-100 text-slate-500'
}
function statusLabel(status: PricePromotion['status']): string {
  return { draft: 'ฉบับร่าง', active: 'ใช้งาน', ended: 'สิ้นสุดแล้ว' }[status]
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="bar_chart"
      title="มุมมองสินค้า"
      subtitle="เกรดสินค้า (ABC) และโปรโมชั่นราคาตามช่วง"
      accent-color="brand"
      storage-key="product-performance"
    />

    <!-- Link-out disambiguation note vs. /agent-promotions -->
    <div class="mt-4 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
      ต้องการให้โบนัสพิเศษกับ agent แทนการลดราคาสินค้า?
      <RouterLink :to="{ name: 'agent-promotions' }" class="font-bold text-brand-600 hover:text-brand-700">
        ไปที่หน้า Promotion สำหรับ Agent
      </RouterLink>
      — หน้านี้ใช้สำหรับลดราคาสินค้าที่แสดงให้ลูกค้าเห็นเท่านั้น
    </div>

    <!-- ═══════════ Section A: เกรดสินค้า (ABC) ═══════════ -->
    <section class="mt-6">
      <div class="flex items-center justify-between gap-3 flex-wrap mb-2">
        <p class="text-sm font-bold text-slate-900">เกรดสินค้า (ABC)</p>
        <div class="flex gap-1 p-1 rounded-xl bg-slate-100">
          <button
            v-for="t in windowTabs"
            :key="String(t.key)"
            class="px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="abcWindow === t.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
            @click="abcWindow = t.key"
          >
            {{ t.label }}
          </button>
        </div>
      </div>

      <div class="mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
        ยอดขายเป็นการประมาณ (จำนวนที่ขายได้ × ราคาปัจจุบัน) ไม่ใช่ยอดขายจริงย้อนหลัง
      </div>

      <div v-if="abcError" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ abcError }}</div>

      <LoadingSkeleton v-if="abcLoading && !abcLoadedOnce" type="list" :rows="4" />
      <template v-else>
        <EmptyState v-if="!abcRows.length" icon="bar_chart" title="ยังไม่มีข้อมูลยอดขายในช่วงที่เลือก" />
        <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white/95">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left">
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 whitespace-nowrap">อันดับ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">ชื่อสินค้า</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">จำนวนที่ขายได้</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">ยอดขายโดยประมาณ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">ส่วนแบ่ง%</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">สะสม%</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-center whitespace-nowrap">เกรด</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in abcRows" :key="row.product_id" class="border-b border-slate-50 last:border-0">
                <td class="px-4 py-2.5 text-xs text-slate-400">{{ idx + 1 }}</td>
                <td class="px-4 py-2.5 font-bold text-slate-900">{{ row.product_name }}</td>
                <td class="px-4 py-2.5 text-right text-slate-600">{{ row.sold_count.toLocaleString('th-TH') }}</td>
                <td class="px-4 py-2.5 text-right text-slate-900 font-bold whitespace-nowrap">{{ formatSatang(row.estimated_revenue_satang) }}</td>
                <td class="px-4 py-2.5 text-right text-slate-500">{{ row.revenue_share_percent.toFixed(2) }}%</td>
                <td class="px-4 py-2.5 text-right text-slate-500">{{ row.cumulative_percent.toFixed(2) }}%</td>
                <td class="px-4 py-2.5 text-center">
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="gradeBadgeClass(row.grade)">{{ row.grade }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <!-- ═══════════ Section B: ส่วนลดราคาสินค้าตามช่วง ═══════════ -->
    <section class="mt-8">
      <div class="flex items-center justify-between gap-3 mb-2">
        <p class="text-sm font-bold text-slate-900">ส่วนลดราคาสินค้าตามช่วง</p>
        <button
          class="btn-primary"
          @click="openCreateForm"
        >
          + สร้างส่วนลด
        </button>
      </div>

      <div class="mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
        ราคานี้แสดงผลเท่านั้น ยังไม่เชื่อมกับการคำนวณค่าคอมมิชชั่นจริง
      </div>

      <div v-if="promoError" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ promoError }}</div>

      <LoadingSkeleton v-if="promoLoading && !promoLoadedOnce" type="list" :rows="4" />
      <template v-else>
        <EmptyState
          v-if="!promotions.length"
          icon="tag"
          title="ยังไม่มีส่วนลดราคาสินค้า"
          message="สร้างส่วนลดราคาสำหรับแสดงผลให้ลูกค้าตามช่วงเวลา"
          cta-label="+ สร้างส่วนลดแรก"
          :cta-disabled="false"
          @cta="openCreateForm"
        />
        <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
          <div v-for="p in promotions" :key="p.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-start gap-3 min-w-0">
                <Icon name="tag" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
                <div class="min-w-0">
                  <p class="text-sm font-bold text-slate-900 truncate flex items-center gap-2 flex-wrap">
                    {{ p.product_name }}
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="statusBadgeClass(p.status)">{{ statusLabel(p.status) }}</span>
                    <span v-if="p.is_currently_active" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-600 text-white">กำลังใช้งานอยู่</span>
                  </p>
                  <p v-if="p.note" class="text-xs text-slate-400 truncate mt-0.5">{{ p.note }}</p>
                  <p class="text-xs mt-1">
                    <span class="line-through text-slate-400">{{ formatSatang(p.product_price_satang) }}</span>
                    <span class="ml-2 font-bold text-slate-900">{{ formatSatang(p.discounted_price_satang) }}</span>
                  </p>
                  <p class="text-xs text-slate-400 mt-1">
                    {{ formatDate(p.starts_at) }} — {{ p.ends_at ? formatDate(p.ends_at) : 'ไม่มีกำหนดสิ้นสุด' }}
                  </p>
                </div>
              </div>
              <div class="flex gap-1 shrink-0">
                <button class="text-xs font-bold text-slate-500 hover:text-slate-700 px-2 py-1 flex items-center gap-1" @click="openEditForm(p)">
                  <Icon name="edit" :size="14" /> แก้ไข
                </button>
                <button class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1 flex items-center gap-1" @click="deletePromotion(p)">
                  <Icon name="trash" :size="14" /> ลบ
                </button>
              </div>
            </div>
          </div>
        </TransitionGroup>
      </template>
    </section>

    <!-- ═══════════ Create/Edit modal ═══════════ -->
    <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeForm">
      <!-- Human request (2026-07-23): create/edit modals widened to 60% of
           the viewport, same pattern as AnnouncementsView. -->
      <div class="w-[60vw] min-w-[320px] max-w-[60vw] bg-white rounded-2xl shadow-lg p-5 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-bold text-slate-900">{{ editingId ? 'แก้ไขส่วนลด' : 'สร้างส่วนลดใหม่' }}</p>
          <button class="text-slate-400 hover:text-slate-600" @click="closeForm">
            <Icon name="x" :size="18" />
          </button>
        </div>

        <form class="space-y-3" @submit.prevent="submitForm">
          <div v-if="isSuperAdmin && !editingId">
            <label class="text-sm font-bold text-slate-500">บริษัท (Super Admin)</label>
            <select v-model="form.company_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="">— เลือกบริษัท —</option>
              <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">สินค้า</label>
            <select v-model="form.product_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="" disabled>เลือกสินค้า</option>
              <option v-for="p in productOptionsForForm" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">ราคาลด (บาท)</label>
            <input v-model="form.discounted_price_input" type="number" min="0" step="0.01" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">หมายเหตุ (ไม่บังคับ)</label>
            <input v-model="form.note" maxlength="255" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-sm font-bold text-slate-500">วันที่เริ่มต้น (คีย์วันที่เป็น พ.ศ.)</label>
              <div class="mt-1">
                <BuddhistDateInput v-model="form.starts_at" required />
              </div>
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">วันที่สิ้นสุด (ไม่บังคับ)</label>
              <div class="mt-1">
                <BuddhistDateInput v-model="form.ends_at" />
              </div>
            </div>
          </div>

          <div>
            <label class="text-sm font-bold text-slate-500">สถานะ</label>
            <select v-model="form.status" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="draft">ฉบับร่าง</option>
              <option value="active">ใช้งาน</option>
              <option value="ended">สิ้นสุดแล้ว</option>
            </select>
          </div>

          <div v-if="formError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ formError }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="closeForm">ยกเลิก</button>
            <button type="submit" :disabled="saving" class="btn-primary">
              {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- TASK-066 — replaces native window.confirm(). Bug fix (2026-08-01,
         human-reported: sub-menu nav needed a hard refresh to render) —
         this was a SIBLING of <main>, making the template a multi-root
         Fragment, which breaks App.vue's <Transition mode="out-in"> around
         <RouterView> (see AgentManagementView.vue's identical fix for the
         full explanation). Moved inside <main>. -->
    <ConfirmDialog
      :show="pendingDeletePromotion !== null"
      variant="danger"
      :body='pendingDeletePromotion ? `ยืนยันลบส่วนลดของ "${pendingDeletePromotion.product_name}"?` : ""'
      @confirm="confirmDeletePromotion"
      @update:show="(v) => { if (!v) pendingDeletePromotion = null }"
    />
  </main>
</template>
