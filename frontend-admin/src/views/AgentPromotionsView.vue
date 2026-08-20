<script setup lang="ts">
/**
 * AgentPromotionsView — "Promotion สำหรับ Agent" (targeted bonus campaigns).
 *
 * Admin-side CRUD for agent_promotions (backend already shipped +
 * route-registered; this is UI-only, matching the existing modal-form
 * pattern from CommissionPlansView.vue and the card/list pattern from
 * AgentManagementView.vue's Overview tab).
 *
 * bonus_value units follow the exact same convention as Commission Rules
 * (CommissionPlansView.vue): percentage is stored in basis points
 * (500 = 5.00%), fixed_satang is stored in THB satang (BR-3) — this
 * screen displays/accepts THB and converts, never sends a raw THB value.
 *
 * Company scoping: Company Admin never sends company_id (server forces
 * their own). Super Admin must pick a company when creating (a
 * promotion always belongs to exactly one company — unlike reward items/
 * announcements, there's no "platform-wide" promotion concept here).
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
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

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
type BonusType = 'percentage' | 'fixed_satang'
// Same THB<->basis-points/satang conversion as CommissionPlansView.vue's
// rateValueToBasisOrSatang — kept identical so the two screens stay
// consistent (percentage: "5" -> 500 basis points; fixed_satang: "200"
// THB -> 20000 satang).
function bonusValueToStored(bonusType: BonusType, input: string | number): number {
  return Math.round(Number(input) * 100)
}
function bonusValueToInput(bonusType: BonusType, stored: number): number {
  return stored / 100
}
function bonusSummary(p: PromotionItem): string {
  return p.bonus_type === 'percentage' ? `โบนัส ${(p.bonus_value / 100).toFixed(2)}% ของยอดขาย` : `โบนัส ${formatSatang(p.bonus_value)}`
}
// TASK-042 §3: payout_timing — required, no server default (see migration
// 2026_07_30_090000_add_payout_timing_to_agent_promotions_table.php). Existing
// rows were backfilled to 'immediate' at the DB level, so every promotion
// returned by the API always carries a real value here — the '' form state
// below exists only to force an explicit choice on create/edit, never to
// model "no value yet" from the server.
type PayoutTiming = 'immediate' | 'monthly_batch'
const payoutTimingLabels: Record<PayoutTiming, string> = {
  immediate: 'จ่ายทันทีเมื่อครบเงื่อนไข',
  monthly_batch: 'จ่ายรอบสิ้นเดือน',
}
function payoutTimingLabel(timing: PayoutTiming): string {
  return payoutTimingLabels[timing]
}
function payoutTimingBadgeClass(timing: PayoutTiming): string {
  return timing === 'immediate' ? 'bg-sky-50 text-sky-700' : 'bg-amber-50 text-amber-700'
}

const auth = useAuthStore()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

interface CompanyOption { id: number; name: string }
interface CertTierOption { id: number; key: string; name: string }
interface ProductOption { id: number; company_id: number; name: string }
interface UserOption { id: number; name: string; role: string; is_active: boolean; company: { id: number; name: string } | null }
type TargetType = 'all_agents' | 'cert_tier' | 'specific_agents'
// Per TASK-042 §4 (BR-7 resolution): 'exact' = today's only behavior,
// 'and_above' compares cert_tiers.sort_order (agent's highest passed
// tier) >= target tier's sort_order. Backend defaults to 'exact' when
// omitted, so this mirrors that default client-side too.
type CertTierMode = 'exact' | 'and_above'
interface PromotionItem {
  id: number
  company_id: number
  product_id: number | null
  product_name: string | null
  name: string
  description: string | null
  target_type: TargetType
  target_cert_tier_id: number | null
  target_cert_tier_name: string | null
  target_cert_tier_mode: CertTierMode
  target_agent_ids: number[]
  bonus_type: BonusType
  bonus_value: number
  payout_timing: PayoutTiming
  status: 'draft' | 'active' | 'ended'
  is_currently_active: boolean
  starts_at: string
  ends_at: string | null
  created_by: number
  created_at: string
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const promotions = ref<PromotionItem[]>([])

async function loadPromotions() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: PromotionItem[] }>(activeCompany.scopedPath('/agent-promotions'))
    promotions.value = res.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

// ── Lazily-loaded lookups for the create/edit form (avoid extra calls
// just to view the list) ──
const lookupsLoaded = ref(false)
const loadingLookups = ref(false)
const companies = ref<CompanyOption[]>([])
const certTiers = ref<CertTierOption[]>([])
const products = ref<ProductOption[]>([])
const users = ref<UserOption[]>([])

// Same paginate()-with-no-args gap already documented in
// AgentManagementView.vue — /products and /users are Laravel-paginated,
// reused here rather than duplicating a differently-shaped helper.
// TASK-209 P3 — same scope-in-the-query-string rule as the shared copy in
// agentEdit.ts: this walks every page, so filtering after the fetch would
// still pull every company's rows over the wire.
async function fetchAllPages<T>(path: string): Promise<T[]> {
  const scoped = activeCompany.scopedPath(path)
  const sep = scoped.includes('?') ? '&' : '?'
  const first = await api.get<{ data: T[]; meta?: { last_page: number } }>(`${scoped}${sep}page=1`)
  const items = [...first.data]
  const lastPage = first.meta?.last_page ?? 1
  for (let page = 2; page <= lastPage; page++) {
    const next = await api.get<{ data: T[] }>(`${scoped}${sep}page=${page}`)
    items.push(...next.data)
  }
  return items
}

async function ensureLookupsLoaded() {
  if (lookupsLoaded.value || loadingLookups.value) return
  loadingLookups.value = true
  try {
    const requests: Promise<unknown>[] = [
      api.get<{ data: CertTierOption[] }>('/cert-tiers'),
      fetchAllPages<ProductOption>('/products'),
      fetchAllPages<UserOption>('/users?include_inactive=1'),
    ]
    // TASK-209 P4 — company list from the global store (see AgentRosterView).
    if (isSuperAdmin.value) requests.push(activeCompany.loadCompanies())
    const [ct, p, u] = await Promise.all(requests)
    certTiers.value = (ct as { data: CertTierOption[] }).data
    products.value = p as ProductOption[]
    users.value = u as UserOption[]
    companies.value = activeCompany.companies
    lookupsLoaded.value = true
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลประกอบไม่สำเร็จ')
  } finally {
    loadingLookups.value = false
  }
}

onMounted(() => {
  loadPromotions()
})

// ── Create/edit form ──
const showForm = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const formError = ref('')
const form = ref({
  company_id: '' as string | number,
  product_id: '' as string | number, // '' = all products
  name: '',
  description: '',
  target_type: 'all_agents' as TargetType,
  target_cert_tier_id: '' as string | number,
  target_cert_tier_mode: 'exact' as CertTierMode,
  target_agent_ids: [] as number[],
  bonus_type: 'percentage' as BonusType,
  bonus_value_input: '' as string | number,
  payout_timing: '' as PayoutTiming | '',
  status: 'draft' as 'draft' | 'active' | 'ended',
  starts_at: '',
  ends_at: '',
})

// Reset dependent fields when target_type changes so a stale selection
// can never be silently submitted alongside the wrong target_type.
watch(
  () => form.value.target_type,
  (t) => {
    if (t !== 'cert_tier') {
      form.value.target_cert_tier_id = ''
      form.value.target_cert_tier_mode = 'exact'
    }
    if (t !== 'specific_agents') form.value.target_agent_ids = []
  },
)

const productOptionsForForm = computed(() => {
  if (!isSuperAdmin.value) return products.value
  return form.value.company_id ? products.value.filter((p) => p.company_id === Number(form.value.company_id)) : products.value
})
const agentOptionsForForm = computed(() => {
  let list = users.value.filter((u) => u.role === 'agent' && u.is_active)
  if (isSuperAdmin.value && form.value.company_id) {
    list = list.filter((u) => u.company?.id === Number(form.value.company_id))
  }
  return list
})

function resetForm() {
  form.value = {
    company_id: '',
    product_id: '',
    name: '',
    description: '',
    target_type: 'all_agents',
    target_cert_tier_id: '',
    target_cert_tier_mode: 'exact',
    target_agent_ids: [],
    bonus_type: 'percentage',
    bonus_value_input: '',
    payout_timing: '',
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
async function openEditForm(p: PromotionItem) {
  editingId.value = p.id
  form.value = {
    company_id: p.company_id,
    product_id: p.product_id ?? '',
    name: p.name,
    description: p.description ?? '',
    target_type: p.target_type,
    target_cert_tier_id: p.target_cert_tier_id ?? '',
    target_cert_tier_mode: p.target_cert_tier_mode ?? 'exact',
    target_agent_ids: [...p.target_agent_ids],
    bonus_type: p.bonus_type,
    bonus_value_input: bonusValueToInput(p.bonus_type, p.bonus_value),
    // Backfilled to 'immediate' for every pre-existing row by the migration
    // (never left null at the DB level) — always a real value here, but we
    // still fall back to '' defensively rather than assume the API payload
    // shape can never surprise us.
    payout_timing: p.payout_timing ?? '',
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
function toggleTargetAgent(id: number) {
  const idx = form.value.target_agent_ids.indexOf(id)
  if (idx === -1) form.value.target_agent_ids.push(id)
  else form.value.target_agent_ids.splice(idx, 1)
}

function validateForm(): string {
  if (isSuperAdmin.value && !editingId.value && !form.value.company_id) return 'กรุณาเลือกบริษัท'
  if (!form.value.name.trim()) return 'กรุณาระบุชื่อ Promotion'
  if (!form.value.starts_at) return 'กรุณาระบุวันที่เริ่มต้น'
  if (form.value.ends_at && form.value.ends_at < form.value.starts_at) return 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มต้น'
  if (form.value.target_type === 'cert_tier' && !form.value.target_cert_tier_id) return 'กรุณาเลือก Cert Tier เป้าหมาย'
  if (form.value.target_type === 'specific_agents' && !form.value.target_agent_ids.length) return 'กรุณาเลือก Agent อย่างน้อย 1 คน'
  if (form.value.bonus_value_input === '' || form.value.bonus_value_input === null) return 'กรุณาระบุมูลค่าโบนัส'
  if (!form.value.payout_timing) return 'กรุณาเลือกรูปแบบการจ่ายโบนัส'
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
      product_id: form.value.product_id === '' ? null : Number(form.value.product_id),
      name: form.value.name,
      description: form.value.description || null,
      target_type: form.value.target_type,
      target_cert_tier_id: form.value.target_type === 'cert_tier' ? Number(form.value.target_cert_tier_id) : null,
      target_cert_tier_mode: form.value.target_type === 'cert_tier' ? form.value.target_cert_tier_mode : null,
      target_agent_ids: form.value.target_type === 'specific_agents' ? form.value.target_agent_ids : [],
      bonus_type: form.value.bonus_type,
      bonus_value: bonusValueToStored(form.value.bonus_type, form.value.bonus_value_input),
      payout_timing: form.value.payout_timing,
      status: form.value.status,
      starts_at: form.value.starts_at,
      ends_at: form.value.ends_at || null,
    }
    if (editingId.value) {
      await api.put(`/agent-promotions/${editingId.value}`, payload)
    } else {
      await api.post('/agent-promotions', payload)
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
const pendingDeletePromotion = ref<PromotionItem | null>(null)
function deletePromotion(p: PromotionItem) {
  pendingDeletePromotion.value = p
}
async function confirmDeletePromotion() {
  const p = pendingDeletePromotion.value
  if (!p) return
  try {
    await api.delete(`/agent-promotions/${p.id}`)
    promotions.value = promotions.value.filter((x) => x.id !== p.id)
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  } finally {
    pendingDeletePromotion.value = null
  }
}

function targetSummary(p: PromotionItem): string {
  if (p.target_type === 'all_agents') return 'Agent ทั้งหมด'
  if (p.target_type === 'cert_tier') {
    const tierName = p.target_cert_tier_name ?? '-'
    return `Cert Tier: ${tierName}${p.target_cert_tier_mode === 'and_above' ? ' ขึ้นไป' : ''}`
  }
  return `เฉพาะ ${p.target_agent_ids.length} คน`
}
function statusBadgeClass(status: PromotionItem['status']): string {
  if (status === 'active') return 'bg-emerald-50 text-emerald-700'
  if (status === 'ended') return 'bg-slate-100 text-slate-400 line-through'
  return 'bg-slate-100 text-slate-500'
}
function statusLabel(status: PromotionItem['status']): string {
  return { draft: 'ฉบับร่าง', active: 'ใช้งาน', ended: 'สิ้นสุดแล้ว' }[status]
}

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadPromotions() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="tag"
      title="Promotion สำหรับ Agent"
      subtitle="แคมเปญโบนัสพิเศษแบบเจาะกลุ่ม (ตาม Cert Tier หรือรายบุคคล)"
      accent-color="brand"
      storage-key="agent-promotions"
    >
      <template #actions>
        <button
          class="btn-primary"
          @click="openCreateForm"
        >
          + สร้าง Promotion
        </button>
      </template>
    </HeroHeader>

    <CompanyScopeNotice action="จัดการโปรโมชั่นตัวแทน" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState
        v-if="!promotions.length"
        icon="tag"
        title="ยังไม่มี Promotion"
        message="สร้างแคมเปญโบนัสพิเศษให้ Agent เพื่อกระตุ้นยอดขาย"
        cta-label="+ สร้าง Promotion แรก"
        :cta-disabled="false"
        class="mt-4"
        @cta="openCreateForm"
      />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="p in promotions" :key="p.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
              <Icon name="tag" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 truncate flex items-center gap-2 flex-wrap">
                  {{ p.name }}
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="statusBadgeClass(p.status)">{{ statusLabel(p.status) }}</span>
                  <span v-if="p.is_currently_active" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-600 text-white">กำลังใช้งานอยู่</span>
                </p>
                <p v-if="p.description" class="text-xs text-slate-400 truncate mt-0.5">{{ p.description }}</p>
                <p class="text-xs text-slate-500 mt-1">{{ p.product_name ?? 'ทุกสินค้า' }} · {{ targetSummary(p) }}</p>
                <p class="text-xs font-bold text-slate-700 mt-1 flex items-center gap-2 flex-wrap">
                  {{ bonusSummary(p) }}
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="payoutTimingBadgeClass(p.payout_timing)">{{ payoutTimingLabel(p.payout_timing) }}</span>
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

    <!-- ═══════════ Create/Edit modal ═══════════ -->
    <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeForm">
      <!-- Human request (2026-07-23): create/edit modals widened to 60% of
           the viewport, same pattern as AnnouncementsView (min-width guard
           so it never gets uncomfortably narrow on small screens). -->
      <div class="w-[60vw] min-w-[320px] max-w-[60vw] bg-white rounded-2xl shadow-lg p-5 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-3">
          <!-- TASK-216 — name WHICH promotion. "แก้ไข Promotion" alone
               reads the same on all of them, and this modal is opened from
               a list where several can look alike. -->
          <div class="min-w-0">
            <p class="text-sm font-bold text-slate-900">{{ editingId ? 'แก้ไข Promotion' : 'สร้าง Promotion ใหม่' }}</p>
            <p v-if="editingId" class="mt-0.5 text-xs font-bold text-slate-500 truncate">
              {{ form.name || '(ยังไม่ได้ตั้งชื่อ)' }}
            </p>
          </div>
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
            <label class="text-sm font-bold text-slate-500">ชื่อ Promotion</label>
            <input v-model="form.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">รายละเอียด (ไม่บังคับ)</label>
            <textarea v-model="form.description" rows="2" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></textarea>
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">สินค้า</label>
            <select v-model="form.product_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="">ทุกสินค้า</option>
              <option v-for="p in productOptionsForForm" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>

          <div>
            <label class="text-sm font-bold text-slate-500">กลุ่มเป้าหมาย</label>
            <select v-model="form.target_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="all_agents">Agent ทั้งหมด</option>
              <option value="cert_tier">ตาม Cert Tier</option>
              <option value="specific_agents">เลือกเฉพาะราย</option>
            </select>
          </div>
          <div v-if="form.target_type === 'cert_tier'">
            <label class="text-sm font-bold text-slate-500">Cert Tier เป้าหมาย</label>
            <select v-model="form.target_cert_tier_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="" disabled>เลือก Tier</option>
              <option v-for="ct in certTiers" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
            </select>
            <div class="mt-2 inline-flex rounded-lg border border-slate-200 p-0.5 bg-slate-50">
              <button
                type="button"
                class="px-3 py-1 rounded-md text-xs font-bold transition-colors"
                :class="form.target_cert_tier_mode === 'exact' ? 'bg-brand-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                @click="form.target_cert_tier_mode = 'exact'"
              >
                เฉพาะระดับนี้เท่านั้น
              </button>
              <button
                type="button"
                class="px-3 py-1 rounded-md text-xs font-bold transition-colors"
                :class="form.target_cert_tier_mode === 'and_above' ? 'bg-brand-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                @click="form.target_cert_tier_mode = 'and_above'"
              >
                ระดับนี้ขึ้นไป
              </button>
            </div>
          </div>
          <div v-if="form.target_type === 'specific_agents'">
            <label class="text-sm font-bold text-slate-500">เลือก Agent ({{ form.target_agent_ids.length }} คน)</label>
            <div class="mt-1 max-h-40 overflow-y-auto border border-slate-200 rounded-lg divide-y divide-slate-100">
              <label v-for="a in agentOptionsForForm" :key="a.id" class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50">
                <input type="checkbox" :checked="form.target_agent_ids.includes(a.id)" @change="toggleTargetAgent(a.id)" />
                {{ a.name }}
              </label>
              <p v-if="!agentOptionsForForm.length" class="px-3 py-2 text-xs text-slate-400">ไม่มี Agent ให้เลือก</p>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-sm font-bold text-slate-500">รูปแบบโบนัส</label>
              <select v-model="form.bonus_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                <option value="percentage">% ของยอดขาย</option>
                <option value="fixed_satang">จำนวนเงินคงที่ (บาท)</option>
              </select>
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">{{ form.bonus_type === 'percentage' ? 'มูลค่า (%)' : 'มูลค่า (บาท)' }}</label>
              <input v-model="form.bonus_value_input" type="number" min="0" step="0.01" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
          </div>

          <div>
            <label class="text-sm font-bold text-slate-500">รูปแบบการจ่ายโบนัส</label>
            <select v-model="form.payout_timing" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="" disabled>— เลือกรูปแบบการจ่าย —</option>
              <option value="immediate">จ่ายทันทีเมื่อครบเงื่อนไข</option>
              <option value="monthly_batch">จ่ายรอบสิ้นเดือน</option>
            </select>
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
      :body='pendingDeletePromotion ? `ยืนยันลบ Promotion "${pendingDeletePromotion.name}"?` : ""'
      @confirm="confirmDeletePromotion"
      @update:show="(v) => { if (!v) pendingDeletePromotion = null }"
    />
  </main>
</template>
