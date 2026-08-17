<script setup lang="ts">
/**
 * CommissionManagementView — Admin company-wide commission ledger +
 * the "mark paid" action (Phase 8). Ported from the Agent Portal's
 * read-only CommissionView.vue, with the one write action this app is
 * actually allowed to do (CommissionLedgerPolicy::markPaid — Company
 * Admin/Super Admin only, an Agent marking their own commission "paid"
 * would be an obvious self-dealing gap, already enforced server-side).
 *
 * BR-3: money is integer satang server-side; divided by 100 only here,
 * at the display layer. BR-4: entries are immutable except
 * payment_status/paid_at — no other field is ever editable here.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'

interface LedgerItem {
  id: number
  referral: { id: number; client: { id: number; name: string } | null } | null
  agent: { id: number; name: string } | null
  cert_tier_at_time: { id: number; key: string; name: string } | null
  product: { id: number; name: string } | null
  rate_type_applied: 'percentage' | 'fixed_satang'
  rate_applied: number
  amount_satang: number
  payment_status: 'pending' | 'paid'
  paid_at: string | null
  created_at: string
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const entries = ref<LedgerItem[]>([])

const kpis = computed(() => {
  const pending = entries.value.filter((e) => e.payment_status === 'pending')
  const paid = entries.value.filter((e) => e.payment_status === 'paid')
  return [
    { label: 'รอจ่าย', value: formatSatang(pending.reduce((sum, e) => sum + e.amount_satang, 0)) },
    { label: 'จ่ายแล้ว', value: formatSatang(paid.reduce((sum, e) => sum + e.amount_satang, 0)) },
    { label: 'รายการทั้งหมด', value: entries.value.length },
  ]
})

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: LedgerItem[] }>('/commission-ledger')
    entries.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

const activeTab = ref<'all' | 'pending' | 'paid'>('pending')
const tabs = computed(() => [
  { id: 'all', label: 'ทั้งหมด', count: entries.value.length },
  { id: 'pending', label: 'รอจ่าย', count: entries.value.filter((e) => e.payment_status === 'pending').length },
  { id: 'paid', label: 'จ่ายแล้ว', count: entries.value.filter((e) => e.payment_status === 'paid').length },
])
const filteredEntries = computed(() => {
  if (activeTab.value === 'pending') return entries.value.filter((e) => e.payment_status === 'pending')
  if (activeTab.value === 'paid') return entries.value.filter((e) => e.payment_status === 'paid')
  return entries.value
})

const marking = ref<number | null>(null)
async function markPaid(entry: LedgerItem) {
  marking.value = entry.id
  errorMessage.value = ''
  try {
    await api.post(`/commission-ledger/${entry.id}/mark-paid`)
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
  } finally {
    marking.value = null
  }
}

function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}
function formatRate(entry: LedgerItem): string {
  return entry.rate_type_applied === 'percentage' ? (entry.rate_applied / 100).toFixed(2) + '%' : formatSatang(entry.rate_applied)
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="money"
      title="Commission Ledger"
      subtitle="คอมมิชชั่นทั้งบริษัท"
      description="รายการเป็นแบบอ่านอย่างเดียว ยกเว้นสถานะการจ่ายเงิน (BR-4) — Agent ไม่สามารถ mark paid ให้ตัวเองได้"
      :kpis="kpis"
      accent-color="brand"
      storage-key="admin-commission"
    >
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in tabs"
            :key="t.id"
            type="button"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.id ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.id as 'all' | 'pending' | 'paid'"
          >
            {{ t.label }} ({{ t.count }})
          </button>
        </div>
      </template>
    </HeroHeader>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!filteredEntries.length" icon="money" title="ยังไม่มีรายการคอมมิชชั่นในหมวดนี้" class="mt-4" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="e in filteredEntries" :key="e.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
          <div class="flex items-start gap-3">
            <Icon name="money" :size="18" class="text-brand-600 mt-0.5" />
            <div>
              <p class="text-sm font-bold text-slate-900">{{ e.referral?.client?.name ?? '—' }}</p>
              <p class="text-xs text-slate-400">
                Agent: {{ e.agent?.name ?? '—' }} · {{ e.product?.name }} · {{ e.cert_tier_at_time?.name }} tier · อัตรา {{ formatRate(e) }} · {{ formatDate(e.created_at) }}
              </p>
            </div>
          </div>
          <div class="text-right flex items-center gap-3">
            <div>
              <p class="text-sm font-bold text-slate-900">{{ formatSatang(e.amount_satang) }}</p>
              <span
                class="text-xs font-bold px-2 py-0.5 rounded-lg whitespace-nowrap"
                :class="e.payment_status === 'paid' ? 'text-emerald-600 bg-emerald-50' : 'text-amber-600 bg-amber-50'"
              >
                {{ e.payment_status === 'paid' ? 'จ่ายแล้ว' : 'รอจ่าย' }}
              </span>
            </div>
            <button
              v-if="e.payment_status === 'pending'"
              :disabled="marking === e.id"
              class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 disabled:opacity-50 whitespace-nowrap"
              @click="markPaid(e)"
            >
              {{ marking === e.id ? 'กำลังบันทึก...' : 'จ่ายแล้ว' }}
            </button>
          </div>
        </div>
      </TransitionGroup>
    </template>
  </main>
</template>
