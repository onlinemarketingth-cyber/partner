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
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()

/**
 * TASK-215 — `earned_via` and `override_source_agent` were being sent by
 * CommissionLedgerResource and thrown away by this screen.
 *
 * Found during UAT-016 (2026-08-19). One closed sale wrote three rows —
 * the seller's own 3.00%, the team leader's 2.50% override, and a 10%
 * campaign bonus — and this list rendered all three IDENTICALLY: same
 * client, same product, same date, three different amounts. Reading them
 * carefully, the honest first conclusion was "this sale paid the same
 * agent twice".
 *
 * It had not. But an accountant reconciling a payout run would reach that
 * same wrong conclusion, and the Resource's own docblock already says
 * earned_via is "the single most important field" for answering how a row
 * was calculated. The data was one property away the whole time.
 */
type EarnedVia =
  | 'direct'
  | 'renewal'
  | 'override'
  | 'binary_match'
  | 'matrix_override'
  | 'stairstep_override'
  | 'generation_override'
  | 'promotion_bonus'

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
  earned_via: EarnedVia | null
  /** Whose sale produced this override — null on a row the agent earned themselves. */
  override_source_agent: { id: number; name: string } | null
  paid_at: string | null
  created_at: string
}

/**
 * Thai labels + colour per payout type. Deliberately NOT one neutral grey
 * chip for everything: "ค่าคอมของตัวเอง" and "ค่าคอมหัวหน้าทีม" land in
 * different people's pockets for different reasons, and the whole point of
 * the badge is that the difference is visible at a glance.
 */
const earnedViaLabels: Record<EarnedVia, { label: string; cls: string }> = {
  direct: { label: 'ค่าคอมของตัวเอง', cls: 'bg-brand-50 text-brand-700' },
  renewal: { label: 'ค่าคอมปีต่ออายุ', cls: 'bg-sky-50 text-sky-700' },
  override: { label: 'ค่าคอมหัวหน้าทีม', cls: 'bg-amber-100 text-amber-800' },
  binary_match: { label: 'Binary (จับคู่ขา)', cls: 'bg-violet-50 text-violet-700' },
  matrix_override: { label: 'Matrix (ชั้นล่าง)', cls: 'bg-violet-50 text-violet-700' },
  stairstep_override: { label: 'อันดับ (ส่วนต่าง)', cls: 'bg-violet-50 text-violet-700' },
  generation_override: { label: 'Generation', cls: 'bg-violet-50 text-violet-700' },
  promotion_bonus: { label: 'โบนัสแคมเปญ', cls: 'bg-emerald-50 text-emerald-700' },
}

function earnedViaBadge(e: LedgerItem): { label: string; cls: string } {
  // An unknown/absent value must read as unknown, never silently as
  // "direct" — mislabelling a payout type is the exact failure this fix
  // exists to remove.
  return e.earned_via
    ? (earnedViaLabels[e.earned_via] ?? { label: e.earned_via, cls: 'bg-slate-100 text-slate-600' })
    : { label: 'ไม่ระบุประเภท', cls: 'bg-slate-100 text-slate-500' }
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
    const res = await api.get<{ data: LedgerItem[] }>(activeCompany.scopedPath('/commission-ledger'))
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
/**
 * TASK-215 — a second, independent filter. The existing tabs answer "has
 * this been paid out yet"; this answers "what kind of money is it", which
 * is the question a reconciliation actually starts from.
 */
const viaFilter = ref<'all' | EarnedVia>('all')
const viaFilterOptions = computed(() => {
  const present = new Set(entries.value.map((e) => e.earned_via).filter(Boolean) as EarnedVia[])

  return [
    { id: 'all' as const, label: 'ทุกประเภท' },
    // Only offer types this company actually has — a filter for a plan
    // they do not use is noise.
    ...([...present] as EarnedVia[]).map((v) => ({ id: v, label: earnedViaLabels[v]?.label ?? v })),
  ]
})

const filteredEntries = computed(() => {
  let rows = entries.value
  if (activeTab.value === 'pending') rows = rows.filter((e) => e.payment_status === 'pending')
  else if (activeTab.value === 'paid') rows = rows.filter((e) => e.payment_status === 'paid')
  if (viaFilter.value !== 'all') rows = rows.filter((e) => e.earned_via === viaFilter.value)

  return rows
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

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAll() })
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

    <CompanyScopeNotice action="ดูคอมมิชชั่นรายบริษัท" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <!-- TASK-215 — filter by payout type. -->
      <div v-if="viaFilterOptions.length > 2" class="mt-4 flex flex-wrap items-center gap-2">
        <span class="text-xs font-bold text-slate-400">ประเภท:</span>
        <button
          v-for="o in viaFilterOptions"
          :key="o.id"
          class="px-3 py-1.5 rounded-full border text-xs font-bold"
          :class="viaFilter === o.id ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'"
          @click="viaFilter = o.id"
        >
          {{ o.label }}
        </button>
      </div>

      <EmptyState v-if="!filteredEntries.length" icon="money" title="ยังไม่มีรายการคอมมิชชั่นในหมวดนี้" class="mt-4" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="e in filteredEntries" :key="e.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
          <div class="flex items-start gap-3">
            <Icon name="money" :size="18" class="text-brand-600 mt-0.5" />
            <div>
              <p class="text-sm font-bold text-slate-900">
                {{ e.referral?.client?.name ?? '—' }}
                <!-- TASK-215 — WHAT KIND of money this row is. Without
                     it, a direct commission, an upline override and a
                     campaign bonus on the same sale are three visually
                     identical lines. -->
                <span class="ml-1.5 px-2 py-0.5 rounded-md text-[11px] font-bold align-middle" :class="earnedViaBadge(e).cls">
                  {{ earnedViaBadge(e).label }}
                </span>
              </p>
              <p class="text-xs text-slate-400">
                Agent: {{ e.agent?.name ?? '—' }}
                <!-- Whose sale earned it. Only meaningful on override
                     rows, where "Agent" is the RECIPIENT, not the seller —
                     the single most misread thing on this screen. -->
                <span v-if="e.override_source_agent" class="text-amber-700 font-bold">
                  (จากการขายของ {{ e.override_source_agent.name }})
                </span>
                · {{ e.product?.name }} · {{ e.cert_tier_at_time?.name }} tier · อัตรา {{ formatRate(e) }} · {{ formatDate(e.created_at) }}
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
