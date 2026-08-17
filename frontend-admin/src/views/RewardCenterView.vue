<script setup lang="ts">
/**
 * RewardCenterView — "ศูนย์รางวัล" combining reward_items catalog CRUD
 * (tab A) and the reward_redemptions approval queue (tab B) behind one
 * segmented header, same pattern as CommissionPlansView.vue's
 * "ภาพรวมสินค้า"/"การตั้งค่าทั้งหมด" toggle.
 *
 * Status transition rule (enforced here to match the backend's own
 * guard): pending -> approved|rejected; approved -> fulfilled;
 * rejected/fulfilled are terminal — only the buttons valid for the
 * row's CURRENT status are ever shown.
 */
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

type ViewMode = 'items' | 'requests'
const viewMode = ref<ViewMode>('items')

interface CompanyOption { id: number; name: string }
const companies = ref<CompanyOption[]>([])
async function loadCompanyOptions() {
  if (!isSuperAdmin.value || companies.value.length) return
  try {
    const res = await api.get<{ data: CompanyOption[] }>('/companies')
    companies.value = res.data
  } catch {
    /* non-fatal — the company selector just stays empty */
  }
}

// ══════════════════════════ Tab A: Reward Items catalog ══════════════════════════
// TASK-042 §2: reward_type is set on the item itself (App\Enums\RewardType on
// the backend) — physical items require shipping capture at redemption time,
// digital items never show a shipping block.
type RewardType = 'physical' | 'digital'
interface RewardItem {
  id: number
  company_id: number | null
  name: string
  description: string | null
  cost_points: number
  stock_quantity: number | null
  is_active: boolean
  reward_type: RewardType
  created_at: string
}
const rewardItems = ref<RewardItem[]>([])
const loadingItems = ref(false)
const itemsLoadedOnce = ref(false)
const itemsError = ref('')
async function loadRewardItems() {
  loadingItems.value = true
  itemsError.value = ''
  try {
    const res = await api.get<{ data: RewardItem[] }>('/reward-items')
    rewardItems.value = res.data
  } catch (e) {
    itemsError.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loadingItems.value = false
    itemsLoadedOnce.value = true
  }
}

const showItemForm = ref(false)
const editingItemId = ref<number | null>(null)
const savingItem = ref(false)
const itemFormError = ref('')
const itemForm = ref({
  company_id: '' as string | number, // '' = platform-wide (Super Admin only)
  name: '',
  description: '',
  cost_points: '' as string | number,
  stock_quantity: '' as string | number, // '' = unlimited
  is_active: true,
  reward_type: 'physical' as RewardType,
})
function resetItemForm() {
  itemForm.value = { company_id: '', name: '', description: '', cost_points: '', stock_quantity: '', is_active: true, reward_type: 'physical' }
  editingItemId.value = null
  itemFormError.value = ''
}
async function openCreateItemForm() {
  resetItemForm()
  await loadCompanyOptions()
  showItemForm.value = true
}
async function openEditItemForm(item: RewardItem) {
  editingItemId.value = item.id
  itemForm.value = {
    company_id: item.company_id ?? '',
    name: item.name,
    description: item.description ?? '',
    cost_points: item.cost_points,
    stock_quantity: item.stock_quantity ?? '',
    is_active: item.is_active,
    reward_type: item.reward_type,
  }
  itemFormError.value = ''
  await loadCompanyOptions()
  showItemForm.value = true
}
function closeItemForm() {
  showItemForm.value = false
}
async function submitItemForm() {
  if (!itemForm.value.name.trim()) {
    itemFormError.value = 'กรุณาระบุชื่อของรางวัล'
    return
  }
  if (itemForm.value.cost_points === '' || Number(itemForm.value.cost_points) < 1) {
    itemFormError.value = 'กรุณาระบุแต้มที่ใช้แลก (อย่างน้อย 1 แต้ม)'
    return
  }
  savingItem.value = true
  itemFormError.value = ''
  try {
    const payload = {
      ...(isSuperAdmin.value ? { company_id: itemForm.value.company_id === '' ? null : Number(itemForm.value.company_id) } : {}),
      name: itemForm.value.name,
      description: itemForm.value.description || null,
      cost_points: Number(itemForm.value.cost_points),
      stock_quantity: itemForm.value.stock_quantity === '' ? null : Number(itemForm.value.stock_quantity),
      is_active: itemForm.value.is_active,
      reward_type: itemForm.value.reward_type,
    }
    if (editingItemId.value) {
      await api.put(`/reward-items/${editingItemId.value}`, payload)
    } else {
      await api.post('/reward-items', payload)
    }
    closeItemForm()
    await loadRewardItems()
  } catch (e) {
    itemFormError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingItem.value = false
  }
}
// TASK-066 (human-reported 2026-07-31) — native window.confirm() replaced
// with the ConfirmDialog modal.
const pendingDeleteItem = ref<RewardItem | null>(null)
function deleteItem(item: RewardItem) {
  pendingDeleteItem.value = item
}
async function confirmDeleteItem() {
  const item = pendingDeleteItem.value
  if (!item) return
  try {
    await api.delete(`/reward-items/${item.id}`)
    rewardItems.value = rewardItems.value.filter((x) => x.id !== item.id)
  } catch (e) {
    itemsError.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  } finally {
    pendingDeleteItem.value = null
  }
}
function stockLabel(item: RewardItem): string {
  return item.stock_quantity === null ? 'ไม่จำกัดจำนวน' : `คงเหลือ ${item.stock_quantity.toLocaleString('th-TH')} ชิ้น`
}
function rewardTypeLabel(type: RewardType): string {
  return type === 'physical' ? 'ของจริง (จัดส่ง)' : 'ดิจิทัล'
}

// ══════════════════════════ Tab B: Reward Redemptions queue ══════════════════════════
type RedemptionStatus = 'pending' | 'approved' | 'rejected' | 'fulfilled'
interface RedemptionItem {
  id: number
  company_id: number
  user_id: number
  agent_name: string
  reward_item_id: number
  reward_item_name: string
  reward_item_reward_type: RewardType
  points_spent: number
  status: RedemptionStatus
  requested_at: string
  decided_by: number | null
  decided_by_name: string | null
  decided_at: string | null
  decision_note: string | null
  // TASK-042 §2: captured by the agent at request time, populated only when
  // the redeemed item was physical (see StoreRewardRedemptionRequest).
  shipping_recipient_name: string | null
  shipping_phone: string | null
  shipping_address: string | null
  // Admin-editable any time after Approved/Fulfilled.
  tracking_number: string | null
}
const redemptions = ref<RedemptionItem[]>([])
const loadingRedemptions = ref(false)
const redemptionsLoadedOnce = ref(false)
const redemptionsError = ref('')
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
async function loadRedemptions() {
  loadingRedemptions.value = true
  redemptionsError.value = ''
  try {
    redemptions.value = await fetchAllPages<RedemptionItem>('/reward-redemptions')
  } catch (e) {
    redemptionsError.value = apiErrorMessage(e, 'โหลดคิวคำขอไม่สำเร็จ')
  } finally {
    loadingRedemptions.value = false
    redemptionsLoadedOnce.value = true
  }
}

const decidingId = ref<number | null>(null)
const decidingStatus = ref<'approved' | 'rejected' | 'fulfilled' | null>(null)
const decisionNote = ref('')
const deciding = ref(false)
function openDecision(item: RedemptionItem, status: 'approved' | 'rejected' | 'fulfilled') {
  decidingId.value = item.id
  decidingStatus.value = status
  decisionNote.value = ''
}
function cancelDecision() {
  decidingId.value = null
  decidingStatus.value = null
  decisionNote.value = ''
}
async function submitDecision(item: RedemptionItem) {
  if (!decidingStatus.value) return
  deciding.value = true
  redemptionsError.value = ''
  try {
    await api.post(`/reward-redemptions/${item.id}/decide`, {
      status: decidingStatus.value,
      decision_note: decisionNote.value || null,
    })
    cancelDecision()
    await loadRedemptions()
  } catch (e) {
    redemptionsError.value = apiErrorMessage(e, 'บันทึกการตัดสินใจไม่สำเร็จ')
  } finally {
    deciding.value = false
  }
}
function redemptionStatusBadgeClass(status: RedemptionStatus): string {
  return { pending: 'bg-amber-50 text-amber-700', approved: 'bg-sky-50 text-sky-700', rejected: 'bg-rose-50 text-rose-700', fulfilled: 'bg-emerald-50 text-emerald-700' }[status]
}
function redemptionStatusLabel(status: RedemptionStatus): string {
  return { pending: 'รออนุมัติ', approved: 'อนุมัติแล้ว', rejected: 'ปฏิเสธแล้ว', fulfilled: 'ส่งมอบแล้ว' }[status]
}

// Tracking number — Admin-editable any time after Approved/Fulfilled (TASK-042 §2).
// Same inline-input-plus-confirm-button interaction as the decision-note field
// above (openDecision/submitDecision), not a separate modal.
const editingTrackingId = ref<number | null>(null)
const trackingInput = ref('')
const savingTracking = ref(false)
function openTrackingEdit(item: RedemptionItem) {
  editingTrackingId.value = item.id
  trackingInput.value = item.tracking_number ?? ''
}
function cancelTrackingEdit() {
  editingTrackingId.value = null
  trackingInput.value = ''
}
async function saveTracking(item: RedemptionItem) {
  savingTracking.value = true
  redemptionsError.value = ''
  try {
    const res = await api.patch<{ data: RedemptionItem }>(`/reward-redemptions/${item.id}/tracking-number`, {
      tracking_number: trackingInput.value || null,
    })
    item.tracking_number = res.data.tracking_number
    cancelTrackingEdit()
  } catch (e) {
    redemptionsError.value = apiErrorMessage(e, 'บันทึกเลขพัสดุไม่สำเร็จ')
  } finally {
    savingTracking.value = false
  }
}

onMounted(() => {
  loadRewardItems()
  loadRedemptions()
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader icon="trophy" title="ศูนย์รางวัล" subtitle="ของรางวัลแลกแต้ม + คิวคำขอแลกแต้มของ Agent" accent-color="brand" storage-key="reward-center">
      <template #actions>
        <button
          v-if="viewMode === 'items'"
          class="btn-primary"
          @click="openCreateItemForm"
        >
          + เพิ่มของรางวัล
        </button>
      </template>
      <template #tabs>
        <div class="flex gap-1 px-4 py-2">
          <button
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold whitespace-nowrap transition-colors"
            :class="viewMode === 'items' ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="viewMode = 'items'"
          >
            <Icon name="trophy" :size="16" />
            รางวัล
          </button>
          <button
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold whitespace-nowrap transition-colors"
            :class="viewMode === 'requests' ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="viewMode = 'requests'"
          >
            <Icon name="list" :size="16" />
            คำขอแลกแต้ม
            <span v-if="redemptions.filter((r) => r.status === 'pending').length" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-amber-500 text-white">
              {{ redemptions.filter((r) => r.status === 'pending').length }}
            </span>
          </button>
        </div>
      </template>
    </HeroHeader>

    <!-- ═══════════ Tab A: รางวัล ═══════════ -->
    <template v-if="viewMode === 'items'">
      <div v-if="itemsError" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ itemsError }}</div>
      <LoadingSkeleton v-if="loadingItems && !itemsLoadedOnce" type="list" :rows="4" class="mt-4" />
      <template v-else>
        <EmptyState
          v-if="!rewardItems.length"
          icon="trophy"
          title="ยังไม่มีของรางวัล"
          message="เพิ่มของรางวัลให้ Agent แลกด้วยแต้มสะสม"
          cta-label="+ เพิ่มของรางวัลแรก"
          :cta-disabled="false"
          class="mt-4"
          @cta="openCreateItemForm"
        />
        <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
          <div v-for="item in rewardItems" :key="item.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-start gap-3 min-w-0">
                <Icon name="trophy" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
                <div class="min-w-0">
                  <p class="text-sm font-bold text-slate-900 truncate flex items-center gap-2 flex-wrap">
                    {{ item.name }}
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="item.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-400'">
                      {{ item.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}
                    </span>
                    <span v-if="isSuperAdmin && item.company_id === null" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">
                      ทั้งแพลตฟอร์ม
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="item.reward_type === 'physical' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700'">
                      {{ rewardTypeLabel(item.reward_type) }}
                    </span>
                  </p>
                  <p v-if="item.description" class="text-xs text-slate-400 truncate mt-0.5">{{ item.description }}</p>
                  <p class="text-xs text-slate-500 mt-1">{{ item.cost_points.toLocaleString('th-TH') }} แต้ม · {{ stockLabel(item) }}</p>
                </div>
              </div>
              <div class="flex gap-1 shrink-0">
                <button class="text-xs font-bold text-slate-500 hover:text-slate-700 px-2 py-1 flex items-center gap-1" @click="openEditItemForm(item)">
                  <Icon name="edit" :size="14" /> แก้ไข
                </button>
                <button class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1 flex items-center gap-1" @click="deleteItem(item)">
                  <Icon name="trash" :size="14" /> ลบ
                </button>
              </div>
            </div>
          </div>
        </TransitionGroup>
      </template>
    </template>

    <!-- ═══════════ Tab B: คำขอแลกแต้ม ═══════════ -->
    <template v-else>
      <div v-if="redemptionsError" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ redemptionsError }}</div>
      <LoadingSkeleton v-if="loadingRedemptions && !redemptionsLoadedOnce" type="list" :rows="4" class="mt-4" />
      <template v-else>
        <EmptyState v-if="!redemptions.length" icon="list" title="ยังไม่มีคำขอแลกแต้ม" class="mt-4" />
        <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
          <div v-for="r in redemptions" :key="r.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-start gap-3 min-w-0">
                <Icon name="list" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
                <div class="min-w-0">
                  <p class="text-sm font-bold text-slate-900 truncate flex items-center gap-2 flex-wrap">
                    {{ r.agent_name }}
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="redemptionStatusBadgeClass(r.status)">{{ redemptionStatusLabel(r.status) }}</span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="r.reward_item_reward_type === 'physical' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700'">
                      {{ rewardTypeLabel(r.reward_item_reward_type) }}
                    </span>
                  </p>
                  <p class="text-xs text-slate-500 mt-1">แลก {{ r.reward_item_name }} · {{ r.points_spent.toLocaleString('th-TH') }} แต้ม</p>
                  <p class="text-xs text-slate-400 mt-1">ขอเมื่อ {{ formatDate(r.requested_at) }}</p>
                  <p v-if="r.decided_at" class="text-xs text-slate-400 mt-1">
                    ตัดสินใจโดย {{ r.decided_by_name ?? '-' }} เมื่อ {{ formatDate(r.decided_at) }}
                    <span v-if="r.decision_note"> — {{ r.decision_note }}</span>
                  </p>

                  <!-- Shipping info — only for physical rewards, captured by the agent at request time -->
                  <div v-if="r.reward_item_reward_type === 'physical'" class="mt-2 px-3 py-2 rounded-lg bg-slate-50 border border-slate-100 text-xs text-slate-600 space-y-0.5">
                    <p class="font-bold text-slate-500">ข้อมูลจัดส่ง</p>
                    <p>ผู้รับ: {{ r.shipping_recipient_name ?? '-' }}</p>
                    <p>เบอร์โทร: {{ r.shipping_phone ?? '-' }}</p>
                    <p>ที่อยู่: {{ r.shipping_address ?? '-' }}</p>
                  </div>

                  <!-- Tracking number — Admin-editable any time after Approved/Fulfilled -->
                  <div v-if="r.reward_item_reward_type === 'physical' && (r.status === 'approved' || r.status === 'fulfilled')" class="mt-2 flex items-center gap-2">
                    <template v-if="editingTrackingId === r.id">
                      <input
                        v-model="trackingInput"
                        type="text"
                        placeholder="เลขพัสดุ"
                        class="flex-1 px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
                      />
                      <button class="btn-secondary" @click="cancelTrackingEdit">ยกเลิก</button>
                      <button :disabled="savingTracking" class="btn-primary" @click="saveTracking(r)">
                        {{ savingTracking ? 'กำลังบันทึก...' : 'บันทึก' }}
                      </button>
                    </template>
                    <template v-else>
                      <p class="text-xs text-slate-500">เลขพัสดุ: {{ r.tracking_number ?? 'ยังไม่ระบุ' }}</p>
                      <button class="text-xs font-bold text-brand-600 hover:text-brand-700 px-2 py-1 flex items-center gap-1" @click="openTrackingEdit(r)">
                        <Icon name="edit" :size="12" /> แก้ไข
                      </button>
                    </template>
                  </div>
                </div>
              </div>
              <div v-if="r.status === 'pending'" class="flex gap-1 shrink-0">
                <button class="text-xs font-bold text-emerald-600 hover:text-emerald-700 px-2 py-1" @click="openDecision(r, 'approved')">อนุมัติ</button>
                <button class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1" @click="openDecision(r, 'rejected')">ปฏิเสธ</button>
              </div>
              <div v-else-if="r.status === 'approved'" class="flex gap-1 shrink-0">
                <button class="text-xs font-bold text-emerald-600 hover:text-emerald-700 px-2 py-1" @click="openDecision(r, 'fulfilled')">ส่งมอบแล้ว</button>
              </div>
            </div>
            <div v-if="decidingId === r.id" class="mt-3 pt-3 border-t border-slate-100 flex gap-2 items-center">
              <input
                v-model="decisionNote"
                type="text"
                placeholder="หมายเหตุ (ไม่บังคับ)"
                class="flex-1 px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
              />
              <button class="btn-secondary" @click="cancelDecision">ยกเลิก</button>
              <button :disabled="deciding" class="btn-primary" @click="submitDecision(r)">
                {{ deciding ? 'กำลังบันทึก...' : 'ยืนยัน' }}
              </button>
            </div>
          </div>
        </TransitionGroup>
      </template>
    </template>

    <!-- ═══════════ Create/Edit Reward Item modal ═══════════ -->
    <div v-if="showItemForm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeItemForm">
      <!-- Human request (2026-07-23): create/edit modals widened to 60% of
           the viewport, same pattern as AnnouncementsView. -->
      <div class="w-[60vw] min-w-[320px] max-w-[60vw] bg-white rounded-2xl shadow-lg p-5 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-bold text-slate-900">{{ editingItemId ? 'แก้ไขของรางวัล' : 'เพิ่มของรางวัลใหม่' }}</p>
          <button class="text-slate-400 hover:text-slate-600" @click="closeItemForm">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <form class="space-y-3" @submit.prevent="submitItemForm">
          <div v-if="isSuperAdmin">
            <label class="text-sm font-bold text-slate-500">บริษัท (Super Admin — เว้นว่าง = ทั้งแพลตฟอร์ม)</label>
            <select v-model="itemForm.company_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="">— ทั้งแพลตฟอร์ม —</option>
              <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">ชื่อของรางวัล</label>
            <input v-model="itemForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">รายละเอียด (ไม่บังคับ)</label>
            <textarea v-model="itemForm.description" rows="2" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></textarea>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-sm font-bold text-slate-500">แต้มที่ใช้แลก</label>
              <input v-model="itemForm.cost_points" type="number" min="1" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">จำนวนคงเหลือ (ว่าง = ไม่จำกัด)</label>
              <input v-model="itemForm.stock_quantity" type="number" min="0" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">ประเภทของรางวัล</label>
            <div class="mt-1 flex gap-4">
              <label class="flex items-center gap-1.5 text-sm text-slate-700">
                <input v-model="itemForm.reward_type" type="radio" value="physical" name="reward_type" />
                ของจริง (ต้องจัดส่ง)
              </label>
              <label class="flex items-center gap-1.5 text-sm text-slate-700">
                <input v-model="itemForm.reward_type" type="radio" value="digital" name="reward_type" />
                ดิจิทัล
              </label>
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm font-bold text-slate-500">
            <input v-model="itemForm.is_active" type="checkbox" />
            เปิดใช้งาน
          </label>
          <div v-if="itemFormError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ itemFormError }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="closeItemForm">ยกเลิก</button>
            <button type="submit" :disabled="savingItem" class="btn-primary">
              {{ savingItem ? 'กำลังบันทึก...' : 'บันทึก' }}
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
      :show="pendingDeleteItem !== null"
      variant="danger"
      :body='pendingDeleteItem ? `ยืนยันลบของรางวัล "${pendingDeleteItem.name}"?` : ""'
      @confirm="confirmDeleteItem"
      @update:show="(v) => { if (!v) pendingDeleteItem = null }"
    />
  </main>
</template>
