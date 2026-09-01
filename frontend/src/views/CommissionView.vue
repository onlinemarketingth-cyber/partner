<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * CommissionView — commission ledger, read-only, wired to the real API.
 *
 * BR-2: rate = cert tier x package, from `commission_rules` config —
 * never hardcoded, so no rate/amount is computed here, only displayed
 * exactly as the API returns it.
 * BR-3: money is integer satang server-side; divided by 100 only at
 * this display layer (formatSatang), never stored/transmitted as a
 * float.
 * BR-4: ledger entries are immutable once created — this view has no
 * edit/delete affordances at all, only the read-only payment_status
 * (pending/paid). Marking a commission as paid is a Company
 * Admin/Super Admin action (CommissionLedgerPolicy::markPaid), not
 * exposed here — Agent Portal only shows the agent their own earnings.
 */
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — the audit found raw HTTP status codes
// rendered to the agent, e.g. "โหลดข้อมูลไม่สำเร็จ (500)". One shared
// normalizer replaces every hand-built message (utils/apiError.ts).
import { apiErrorMessage } from '@/utils/apiError'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import TabFilterBar from '@/design-system/components/TabFilterBar.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
// TASK-082 (2026-08-03, UX audit): the ledger is homogeneous, comparable
// content — Material's guidance is lists for that, cards only for
// heterogeneous blocks, and explicitly never cards when the user has to
// scan comparable items to find one. That is exactly what this screen is.
import AppCard from '@/design-system/components/AppCard.vue'
import AppList from '@/design-system/components/AppList.vue'
import AppListGroupHeader from '@/design-system/components/AppListGroupHeader.vue'
import { useThemeStore } from '@/stores/theme'

const theme = useThemeStore()

interface LedgerItem {
  id: number
  referral: { id: number; client: { id: number; name: string } | null } | null
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
  const now = new Date()
  const thisMonth = entries.value.filter((e) => {
    const d = new Date(e.created_at)
    return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth()
  })
  const pending = entries.value.filter((e) => e.payment_status === 'pending')
  const paid = entries.value.filter((e) => e.payment_status === 'paid')
  return [
    { label: 'ค่าแนะนำเดือนนี้', value: formatSatang(thisMonth.reduce((sum, e) => sum + e.amount_satang, 0)) },
    { label: 'รอจ่าย', value: formatSatang(pending.reduce((sum, e) => sum + e.amount_satang, 0)) },
    { label: 'จ่ายแล้ว', value: formatSatang(paid.reduce((sum, e) => sum + e.amount_satang, 0)) },
  ]
})

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: LedgerItem[] }>('/commission-ledger')
    entries.value = res.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

const activeTab = ref<'all' | 'pending' | 'paid'>('all')
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

/**
 * TASK-082 — grouping by payment status. Purely a presentation-level
 * partition of `filteredEntries` (i.e. of the CURRENTLY VISIBLE set, so
 * selecting the "รอจ่าย" tab leaves exactly one group on screen and never
 * an empty header); no request, no recomputation of any amount. BR-4's
 * immutable ledger is read exactly as the API returned it.
 *
 * This grouping is what gives the screen its own silhouette. The human
 * rejected per-page accent colours (2026-08-03), so the differentiator has
 * to be structural — "รอจ่าย vs จ่ายแล้ว" is the split the agent actually
 * cares about, and it is the one thing no other list screen looks like.
 */
const PAYMENT_STATUS_GROUPS: { key: LedgerItem['payment_status']; label: string }[] = [
  { key: 'pending', label: 'รอจ่าย' },
  { key: 'paid', label: 'จ่ายแล้ว' },
]
const groupedEntries = computed(() =>
  PAYMENT_STATUS_GROUPS.map((g) => ({
    ...g,
    items: filteredEntries.value.filter((e) => e.payment_status === g.key),
  })).filter((g) => g.items.length > 0),
)

// Total of what is on screen right now — deliberately NOT the same number
// as any HeroHeader KPI (those are always all-time). BR-3: satang stays an
// integer everywhere; the /100 happens only in formatSatang, at display.
const filteredTotalSatang = computed(() => filteredEntries.value.reduce((sum, e) => sum + e.amount_satang, 0))

function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}
function formatRate(entry: LedgerItem): string {
  return entry.rate_type_applied === 'percentage' ? (entry.rate_applied / 100).toFixed(2) + '%' : formatSatang(entry.rate_applied)
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

/**
 * TASK-105 (human: "frontend ตรง head ปรับชื่อให้ตรงกับ setup จากระบบ").
 *
 * The page title is the SAME configured label as the bottom-nav tab that
 * opens this screen. Hardcoding it meant a company that renamed the tab
 * still landed on a page announcing the platform's own name for it.
 * Fallbacks match BottomNav.vue exactly — if the two drifted, an unset
 * tenant would see the mismatch this task exists to remove.
 */
const pageTitle = computed(() => theme.label('nav_commission', 'ค่าแนะนำ'))
const pageIcon = computed(() => theme.icon('nav_commission', 'money'))
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      :icon="pageIcon"
      :title="pageTitle"
      :subtitle="td('commission.subtitle')"
      :description="td('commission.description')"
      :kpis="kpis"
      accent-color="brand"
      storage-key="commission"
      :default-collapsed="false"
    >
      <template #tabs>
        <div class="px-4">
          <TabFilterBar v-model="activeTab" :tabs="tabs" accent-color="brand" />
        </div>
      </template>
    </HeroHeader>

    <!-- TASK-079 Phase 2 (UX audit): this banner used to be a dead end —
         a failed load left the agent with nothing to tap but the browser
         reload. Retry re-runs the same fetch in place. -->
    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3">
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="loadAll"
      >
        {{ td('common.retry') }}
      </button>
    </div>

    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone. .content-fade
         lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or App.vue's <Transition mode="out-in"> around
         <RouterView> breaks (the multi-root Fragment regression). -->
    <Transition name="content-fade">
      <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
      <div v-else>
        <EmptyState
          v-if="!filteredEntries.length"
          icon="money"
          :title="td('commission.empty')"
          :message="td('commission.empty_help')"
          class="mt-4"
        />
        <!-- TASK-082 (UX audit): this was a stack of identical floating
             cards. A commission ledger is homogeneous, comparable content —
             Material's rule is lists for that and cards only for
             heterogeneous blocks, never cards when the user has to scan
             comparable items to find one. So the per-row card is gone: rows
             butt together inside one <AppList> surface (hence no space-y-2),
             and the group headers carry the spacing. -->
        <div v-else class="mt-4">
          <!-- The single `raised` element this screen is allowed. It is the
               only genuinely non-homogeneous thing here — the total of what
               the active tab is showing — so it earns the one surface that
               is deliberately rarer than the rows around it. -->
          <AppCard variant="raised" class="flex items-baseline justify-between gap-3">
            <div>
              <p class="text-[11px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ td('common.showing_total') }}</p>
              <p class="text-xs text-ink-card-muted">{{ filteredEntries.length }} รายการ</p>
            </div>
            <p class="text-2xl font-bold text-ink-card leading-tight tabular-nums">{{ formatSatang(filteredTotalSatang) }}</p>
          </AppCard>

          <template v-for="group in groupedEntries" :key="group.key">
            <AppListGroupHeader :label="group.label" :count="group.items.length" />
            <AppList>
              <!-- TransitionGroup keeps its `list-fade` behaviour but now
                   renders as a fragment (no `tag`), so the rows stay DIRECT
                   children of AppList — that is what its
                   `[&>*:last-child]:border-b-0` selector needs to find. -->
              <TransitionGroup name="list-fade">
                <AppCard v-for="e in group.items" :key="e.id" variant="flat" class="flex flex-col gap-2">
                  <!-- Flex-squeeze bug fix (2026-08-03, human-reported at 768px on
                       the Referrals screen: the client name wrapped to ONE
                       CHARACTER PER LINE — same root cause here). The text column
                       carried `min-w-0` but no `flex-1`, so it resolved to
                       `flex: 0 1 auto` and collapsed to min-content while the
                       amount/status column kept its full width. `flex-1 min-w-0`
                       fixes the ratio (the amount column already had `shrink-0`).
                       Stacking below `sm` as well, mobile-first: at 375px a
                       `whitespace-nowrap` money value over a status chip leaves no
                       usable room for a name on the same line. -->
                  <!-- TASK-081 (typography audit): the client name and the money
                       amount were BOTH `text-sm font-bold text-ink-card`, so the
                       row had no hero value — the one number the agent opened this
                       screen for read exactly like its own label. The amount is now
                       the dominant element (text-xl); the name stays the row title
                       and the rate/tier/date line is demoted to metadata. Unchanged
                       by TASK-082 — only the surface around it became flat. -->
                  <div class="flex items-start gap-3 min-w-0 flex-1">
                    <Icon name="money" :size="18" class="text-ink-brand mt-0.5 shrink-0" />
                    <div class="min-w-0">
                      <p class="text-sm font-bold text-ink-card">{{ e.referral?.client?.name ?? '—' }}</p>
                      <p class="text-xs text-ink-card-muted">
                        {{ e.product?.name }} · {{ e.cert_tier_at_time?.name }} tier · อัตรา {{ formatRate(e) }} · {{ formatDate(e.created_at) }}
                      </p>
                    </div>
                  </div>
                  <div class="text-left shrink-0 pl-8">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ td('nav.commission2') }}</p>
                    <p class="text-xl font-bold text-ink-card leading-tight">{{ formatSatang(e.amount_satang) }}</p>
                    <!-- Semantic status colours (emerald=paid / amber=pending)
                         are business meaning, not decoration — untouched. -->
                    <span
                      class="text-xs font-bold px-2 py-0.5 rounded-lg whitespace-nowrap"
                      :class="e.payment_status === 'paid' ? 'text-ink-success bg-surface-success' : 'text-ink-warning bg-surface-warning'"
                    >
                      {{ e.payment_status === 'paid' ? 'จ่ายแล้ว' : 'รอจ่าย' }}
                    </span>
                  </div>
                </AppCard>
              </TransitionGroup>
            </AppList>
          </template>
        </div>
      </div>
    </Transition>
  </main>
</template>
