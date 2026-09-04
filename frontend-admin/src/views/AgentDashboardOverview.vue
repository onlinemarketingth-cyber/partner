<script setup lang="ts">
/**
 * AgentDashboardOverview — TASK-052 / ADR-015.
 *
 * The chart-based rework of the "ภาพรวม" tab on AgentManagementView.
 * Self-contained analytics dashboard: fetches its own aggregates from
 * GET /agent-dashboard-metrics (a server-side, tenant-scoped rollup —
 * BR-6) and, best-effort, the pending-approval queue from
 * /agent-approvals for a small "live" list.
 *
 * Frontend-only: no business logic here (CLAUDE.md §7). All satang are
 * integers (BR-3) — divided by 100 only at this display layer. Charts
 * use ApexCharts (vue3-apexcharts); a single brand accent is the primary
 * chart colour, with emerald as the one complementary money tone and a
 * small brand-shade set for the donut (no rainbow palettes, CLAUDE.md
 * §6.5).
 *
 * ══ TASK-179 §4 — what each label on this screen is allowed to claim ══
 *
 * Nothing here was ever mocked; the defect this screen carried was real
 * numbers under labels describing a different quantity. Read this before
 * renaming anything back:
 *
 *  • "ยอดขาย" is MONEY THE CUSTOMER PAID (D1/D2) — SUM over paid orders.
 *    It is NOT commission disbursed, so the old "(จ่ายแล้ว)" suffix (which
 *    reads as a payout) is gone, and the card no longer pairs that money
 *    with a deal count from another source (F-2).
 *  • `closed_deals_without_order` is the disclosure that keeps the figure
 *    above honest: closed deals contributing ZERO baht. Shown as a plain
 *    sentence ONLY when > 0 — a permanent caveat trains people to ignore
 *    it (§4.2).
 *  • "ดีลปิด" = REACHED Complete Payment, post-sale stages included (D4).
 *    Advancing a paid deal never reduces the close rate.
 *  • `agents_pending` counts EVERY user awaiting approval regardless of
 *    role (§3.4) — exactly what GET /agent-approvals lists. Hence
 *    "ผู้ใช้ที่รออนุมัติ", never "ตัวแทนที่รออนุมัติ".
 *  • `agents_total` is ACTIVE agents, deactivated excluded (§3.5) — so it
 *    is labelled "ตัวแทนที่ใช้งานอยู่", not "ทั้งหมด".
 *  • `cert_tier_distribution` partitions the CERTIFIED agents only
 *    (§3.8); `totals.cert_pending` is the uncertified remainder. The donut
 *    adds that remainder as its own slice AND states its denominator, so
 *    the percentages are shares of something real.
 *  • The pipeline funnel renders whatever stages the server sends, in the
 *    order it sends them (§4.1) — see stageCounts() in
 *    utils/pipelineStages.ts. No fixed-length array lives here any more.
 *  • §4.4 — a number nobody measured must never render as 0. Every chart
 *    and the close-rate gauge are gated on "is there anything to measure
 *    yet"; the /agent-approvals queue shows its own error state instead of
 *    a green "nothing pending" when the request fails.
 */
import { computed, onMounted, ref, watch } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { api, ApiError } from '@/api/client'
import Icon from '@/design-system/components/Icon.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { stageCounts } from '@/utils/pipelineStages'
import { useI18n } from '@/composables/useI18n'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const { lang, td } = useI18n()

// ── Backend contract (GET /agent-dashboard-metrics → { data }) ──
interface Totals {
  agents_total: number
  agents_active: number
  agents_inactive: number
  /** Every PENDING user, any role (§3.4) — not agents only. */
  agents_pending: number
  new_agents_this_month: number
  cert_passed: number
  cert_pending: number
  clients_total: number
  deals_total: number
  deals_closed: number
  conversion: number
  /** D1/D2 — customer money from paid orders, integer satang (BR-3). */
  sales_paid_satang: number
  /** §3.2 — closed deals with no paid order, contributing zero baht. */
  closed_deals_without_order: number
  commission_paid_satang: number
  commission_pending_satang: number
}
interface MonthlyPoint {
  month: string
  sales_satang: number
  commission_satang: number
  new_agents: number
}
interface CertTierSlice {
  key: string
  name: string
  count: number
}
interface LeadSourceSlice {
  source: string
  count: number
}
interface TopAgent {
  agent_id: number
  name: string
  avatar_url: string | null
  commission_satang: number
}
interface DashboardMetrics {
  totals: Totals
  monthly: MonthlyPoint[]
  /**
   * §4.1 — a `{ stage_key: count }` map holding EVERY PipelineStage case
   * the server knows about, in the enum's declaration order. Deliberately
   * an open Record and not an interface with named keys: the stage set is
   * config-driven business data (BR-7, ADR-026) and grew from five to
   * eight without this file changing. Naming the keys here is what made
   * the funnel bars stop summing to the ดีลทั้งหมด KPI (F-4).
   */
  deals_by_stage: Record<string, number>
  cert_tier_distribution: CertTierSlice[]
  lead_source_distribution: LeadSourceSlice[]
  top_agents: TopAgent[]
}
interface PendingAgent {
  id: number
  name: string
  email: string
}

// ── Brand palette (tailwind.config.js `brand` ramp — CI-002) ──
const BRAND = '#1E2A54'
const BRAND_LIGHT = '#3F59B2'
const EMERALD = '#10B981'
const BRAND_SHADES = ['#1E2A54', '#2F4183', '#3F59B2', '#677DC9', '#96A5DA']

// ── State ──
const loading = ref(false)
const loadedOnce = ref(false)
const errorMessage = ref('')
const metrics = ref<DashboardMetrics | null>(null)
const pendingAgents = ref<PendingAgent[]>([])
/**
 * §4.3 (F-7) — /agent-approvals paginates at 15 with no ?per_page, so
 * `pendingAgents.length` was a CAPPED number rendered as the badge, sitting
 * next to a KPI counting the whole queue. `meta.total` is the server's own
 * count of the same set the KPI now counts, so the two agree by
 * construction; the list stays one page and says so ("แสดง N จาก M").
 * Deliberately the second of §4.3's two sanctioned options rather than a
 * third approach — fetchAllPages() (AgentManagementView's choice) walks
 * every page, which is right for a review queue and wasteful for a
 * five-row side panel.
 */
const pendingTotal = ref(0)
const pendingLoaded = ref(false)
/**
 * §4.4 (F-14) — the catch used to swallow EVERYTHING, so a 403 or a 500
 * rendered as the green "ไม่มีตัวแทนรออนุมัติ": a failure displayed as good
 * news. A failed request is not an empty queue.
 */
const pendingError = ref('')

/*
 * ── THIS PAGE IGNORED THE COMPANY PICKER UNTIL 2026-09-04 ──
 *
 * Human-reported: "เปลี่ยนบริษัทที่ Admin ... ข้อมูลที่เฉพาะบริษัทไม่เปลี่ยนตาม".
 * This screen was the worst case of it, and became the worst case twice
 * over when it was made the LANDING page: every figure on it — sales,
 * commission, agent counts, the whole set of charts — was the platform's,
 * rendered under a header naming one company. Wrong numbers that look
 * right, which is the failure ADR-038's picker exists to prevent.
 *
 * The API has accepted `company_id` since it was written
 * (AgentDashboardMetricsController) — only the request never sent it.
 */
const activeCompany = useActiveCompanyStore()

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: DashboardMetrics }>(
      activeCompany.scopedPath('/agent-dashboard-metrics'),
    )
    metrics.value = res.data
  } catch (e) {
    errorMessage.value =
      e instanceof ApiError ? `${td('common.load_failed')} (${e.status})` : td('common.load_failed')
  } finally {
    loading.value = false
    loadedOnce.value = true
  }

  pendingError.value = ''
  try {
    const res = await api.get<{ data: PendingAgent[]; meta?: { total: number } }>(
      activeCompany.scopedPath('/agent-approvals?status=pending'),
    )
    pendingAgents.value = res.data
    pendingTotal.value = res.meta?.total ?? res.data.length
  } catch (e) {
    pendingAgents.value = []
    pendingTotal.value = 0
    pendingError.value =
      e instanceof ApiError
        ? `${td('dash.load_pending_failed')} (${e.status})`
        : td('dash.load_pending_failed')
  } finally {
    pendingLoaded.value = true
  }
}
onMounted(() => {
  activeCompany.loadCompanies()
  void load()
})

// Reloading on the switch is half the fix and the half that is easy to
// forget: sending company_id once at mount still leaves yesterday's company
// on screen for as long as the admin stays on this page.
watch(() => activeCompany.companyId, load)

// ── Helpers ──
function baht(satang: number): string {
  return (satang / 100).toLocaleString('th-TH')
}
function bahtInt(satang: number): number {
  return Math.round(satang / 100)
}
// Month abbreviations are DATA, not copy — they are never mixed into a
// sentence, so a plain per-language array is clearer here than 24 dictionary
// keys. Buddhist-era years are not shown on this axis, so no era conversion.
const MONTHS = {
  th: ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'],
  en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
}
function monthLabel(ym: string): string {
  const idx = Number((ym.split('-')[1] ?? '')) - 1
  return (MONTHS[lang.value === 'en' ? 'en' : 'th'])[idx] ?? ym
}
function initial(name: string): string {
  return (name.trim()[0] ?? '?').toUpperCase()
}

const totals = computed<Totals | null>(() => metrics.value?.totals ?? null)
const monthly = computed<MonthlyPoint[]>(() => metrics.value?.monthly ?? [])
const monthLabels = computed(() => monthly.value.map((m) => monthLabel(m.month)))

/**
 * §4.4 — "is there anything to measure yet". Each of these gates one
 * visual whose zero-state would otherwise read as a measured result:
 * a flat 6-month chart, an empty bar chart and a confident 0% gauge on a
 * brand-new company are three statements nobody computed.
 *
 * The distinction drawn here is "no data was collected" vs "the data was
 * collected and it is zero". A company WITH referrals that has collected
 * no money yet still gets the (honest, flat) money chart — that zero is a
 * real measurement. A company with nothing at all gets "ยังไม่มีข้อมูล".
 */
const hasMoneyHistory = computed(() => {
  const t = totals.value
  if (!t) return false
  return (
    t.deals_total > 0 ||
    t.sales_paid_satang > 0 ||
    t.commission_paid_satang > 0 ||
    t.commission_pending_satang > 0
  )
})
/** No agents at all ⇒ "agents joining per month" was never measured. */
const hasAgents = computed(() => (totals.value?.agents_total ?? 0) > 0)
/** No deals ⇒ ดีลปิด ÷ ดีลทั้งหมด has no denominator; 0% would be invented. */
const hasDeals = computed(() => (totals.value?.deals_total ?? 0) > 0)

// ── KPI cards ──
interface Kpi {
  label: string
  value: string
  sub: string
  icon: string
  /** §4.2 — a plain-sentence caveat, rendered only when present. */
  note?: string
}
const kpiCards = computed<Kpi[]>(() => {
  const t = totals.value
  if (!t) return []
  return [
    {
      // §3.5 (F-8) — ACTIVE agents; the deactivated ones are a separate
      // field, so "ทั้งหมด" would have named a set this number excludes.
      label: td('dash.kpi_active_agents'),
      value: t.agents_total.toLocaleString('th-TH'),
      // §3.4 (F-7) — "ผู้ใช้", not "ตัวแทน": the pending count is every
      // role, including a Company Admin waiting for approval.
      sub: td('dash.kpi_active_agents_sub', '', { inactive: t.agents_inactive, pending: t.agents_pending }),
      icon: 'users',
    },
    {
      // D1/D2 — customer money from paid orders. "(จ่ายแล้ว)" is gone: on a
      // commission-heavy screen it read as "commission we have paid out".
      label: td('dash.kpi_sales'),
      value: `฿${baht(t.sales_paid_satang)}`,
      sub: td('dash.kpi_sales_sub'),
      icon: 'cart',
      // §4.2 — say nothing when it is 0. A caveat that is always there is
      // a caveat nobody reads.
      note:
        t.closed_deals_without_order > 0
          ? td('dash.kpi_sales_unbooked', '', { count: t.closed_deals_without_order.toLocaleString() })
          : undefined,
    },
    {
      label: td('dash.kpi_commission_paid'),
      value: `฿${baht(t.commission_paid_satang)}`,
      sub: td('dash.kpi_commission_paid_sub', '', { amount: baht(t.commission_pending_satang) }),
      icon: 'money',
    },
    {
      label: td('dash.kpi_clients'),
      value: t.clients_total.toLocaleString('th-TH'),
      sub: td('dash.kpi_clients_sub'),
      icon: 'contact',
    },
    {
      // D4 — "ปิด" means REACHED Complete Payment (post-sale stages
      // included), not "is sitting exactly on one of two stages".
      label: td('dash.kpi_closed_deals'),
      value: `${t.deals_closed.toLocaleString('th-TH')}`,
      // §4.4 — with no deals there is no rate to state, so none is stated.
      sub: hasDeals.value
        ? td('dash.kpi_closed_deals_sub', '', {
            total: t.deals_total.toLocaleString(),
            rate: t.conversion,
          })
        : td('dash.kpi_closed_deals_none'),
      icon: 'deal',
    },
  ]
})

// KPI sparkline (monthly sales, baht) — reused across sales/commission cards.
const salesSparkSeries = computed(() => [
  { name: td('dash.series_sales'), data: monthly.value.map((m) => bahtInt(m.sales_satang)) },
])
const commissionSparkSeries = computed(() => [
  { name: td('dash.series_commission'), data: monthly.value.map((m) => bahtInt(m.commission_satang)) },
])
function sparkOptions(color: string): ApexOptions {
  return {
    chart: { type: 'area', sparkline: { enabled: true }, fontFamily: 'Kanit, sans-serif' },
    colors: [color],
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0 } },
    tooltip: { enabled: false },
  }
}
const salesSparkOptions = sparkOptions(EMERALD)
const commissionSparkOptions = sparkOptions(BRAND_LIGHT)

// ── Area chart: monthly ยอดขาย + ค่าคอม ──
const areaSeries = computed(() => [
  { name: td('dash.series_sales'), data: monthly.value.map((m) => bahtInt(m.sales_satang)) },
  { name: td('dash.series_commission'), data: monthly.value.map((m) => bahtInt(m.commission_satang)) },
])
const areaOptions = computed<ApexOptions>(() => ({
  chart: { type: 'area', toolbar: { show: false }, fontFamily: 'Kanit, sans-serif', zoom: { enabled: false } },
  colors: [BRAND, EMERALD],
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2.5 },
  fill: {
    type: 'gradient',
    gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 90, 100] },
  },
  xaxis: {
    categories: monthLabels.value,
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: { style: { colors: '#94a3b8', fontFamily: 'Kanit, sans-serif' } },
  },
  yaxis: {
    labels: {
      style: { colors: '#94a3b8', fontFamily: 'Kanit, sans-serif' },
      formatter: (v: number) => (v >= 1000 ? `${Math.round(v / 1000)}k` : `${Math.round(v)}`),
    },
  },
  grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
  legend: { fontFamily: 'Kanit, sans-serif', markers: { size: 6 } },
  tooltip: { y: { formatter: (v: number) => `฿${v.toLocaleString('th-TH')}` } },
}))

// ── Bar chart: ตัวแทนใหม่ต่อเดือน ──
const newAgentsSeries = computed(() => [
  { name: td('dash.series_new_agents'), data: monthly.value.map((m) => m.new_agents) },
])
const newAgentsOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'Kanit, sans-serif' },
  colors: [BRAND],
  plotOptions: { bar: { borderRadius: 6, columnWidth: '45%' } },
  dataLabels: { enabled: false },
  xaxis: {
    categories: monthLabels.value,
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: { style: { colors: '#94a3b8', fontFamily: 'Kanit, sans-serif' } },
  },
  yaxis: { labels: { style: { colors: '#94a3b8', fontFamily: 'Kanit, sans-serif' } } },
  grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
  tooltip: { y: { formatter: (v: number) => `${v} ${td('common.people_unit')}` } },
}))

// ── Radial gauge: อัตราปิดการขาย ──
const conversionSeries = computed(() => [totals.value?.conversion ?? 0])
// computed, not a plain const: td() reads a dictionary that arrives by
// fetch AFTER setup runs, and the reader can switch language at any time.
// A one-shot const would freeze whatever td() returned on the first tick.
const conversionOptions = computed<ApexOptions>(() => ({
  chart: { type: 'radialBar', fontFamily: 'Kanit, sans-serif' },
  colors: [BRAND],
  plotOptions: {
    radialBar: {
      hollow: { size: '62%' },
      track: { background: '#f1f5f9' },
      dataLabels: {
        name: { offsetY: 22, color: '#94a3b8', fontSize: '13px' },
        value: { offsetY: -12, color: '#0f172a', fontSize: '28px', fontWeight: 700, formatter: (v: number) => `${v}%` },
      },
    },
  },
  labels: [td('dash.chart_conversion_title')],
}))

/**
 * ── Donut: สัดส่วนใบรับรองของตัวแทน ──
 *
 * §3.8 (F-5) — `cert_tier_distribution` is a partition of the CERTIFIED
 * agents (one agent, their HIGHEST tier), NOT of the workforce. Rendering
 * it alone would print percentages whose denominator is invisible: "80%
 * Basic" would mean 80% of the certified, while every reader takes it for
 * 80% of the agents.
 *
 * So the uncertified remainder (`totals.cert_pending`) is added as its own
 * slice, which makes the denominator all ACTIVE agents — and the subtitle
 * states that denominator out loud as well. The zero slice is deliberately
 * kept when everybody is certified: "ยังไม่มีใบรับรอง 0%" is information.
 */
const certTiers = computed<CertTierSlice[]>(() => metrics.value?.cert_tier_distribution ?? [])

// §4.4 — no agents ⇒ no distribution was measured; do not draw an empty ring.
const hasCert = computed(() => hasAgents.value)
const certSeries = computed(() => [...certTiers.value.map((c) => c.count), totals.value?.cert_pending ?? 0])
const certOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut', fontFamily: 'Kanit, sans-serif' },
  labels: [...certTiers.value.map((c) => c.name), td('dash.cert_none')],
  colors: BRAND_SHADES,
  stroke: { width: 0 },
  legend: { position: 'bottom', fontFamily: 'Kanit, sans-serif' },
  dataLabels: { enabled: true, formatter: (val: number) => `${Math.round(val)}%` },
  plotOptions: { pie: { donut: { size: '68%' } } },
}))

/**
 * ── Horizontal bar (funnel): ดีลแยกตามขั้น Pipeline ──
 *
 * §4.1 (F-4, BR-7) — whatever stages the server sends, in the order it
 * sends them. The categories are derived from the SAME array as the bar
 * values, so a stage can never be plotted under another stage's name, and
 * the bars sum to the ดีลทั้งหมด KPI because none is dropped.
 *
 * This used to be a hardcoded five-element STAGE_LABELS array plus five
 * named-key reads; ADR-026's three post-sale stages were silently
 * discarded by both. Reintroducing a fixed list here — in any form —
 * reintroduces F-4.
 */
const stages = computed(() => stageCounts(metrics.value?.deals_by_stage ?? {}))
const stageSeries = computed(() => [{ name: td('dash.series_deals'), data: stages.value.map((s) => s.count) }])
const stageOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'Kanit, sans-serif' },
  colors: BRAND_SHADES,
  plotOptions: { bar: { horizontal: true, borderRadius: 5, distributed: true, barHeight: '62%' } },
  dataLabels: { enabled: true, style: { colors: ['#ffffff'], fontFamily: 'Kanit, sans-serif' } },
  legend: { show: false },
  xaxis: {
    categories: stages.value.map((s) => s.label),
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: { style: { colors: '#94a3b8', fontFamily: 'Kanit, sans-serif' } },
  },
  yaxis: { labels: { style: { colors: '#475569', fontFamily: 'Kanit, sans-serif' } } },
  grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
  tooltip: { y: { formatter: (v: number) => `${v} ${td('common.deal_unit')}` } },
}))

// ── Lead source distribution (segmented progress bars) ──
const leadSources = computed<LeadSourceSlice[]>(() => metrics.value?.lead_source_distribution ?? [])
const leadTotal = computed(() => leadSources.value.reduce((sum, s) => sum + s.count, 0))
function leadPct(count: number): number {
  return leadTotal.value ? Math.round((count / leadTotal.value) * 100) : 0
}

// ── Top agents ──
const topAgents = computed<TopAgent[]>(() => metrics.value?.top_agents ?? [])
const topMax = computed(() => topAgents.value.reduce((m, a) => Math.max(m, a.commission_satang), 0))
function topBarWidth(satang: number): string {
  return topMax.value ? `${Math.max(6, Math.round((satang / topMax.value) * 100))}%` : '0%'
}
</script>

<template>
  <div style="font-family: Kanit, sans-serif">
    <LoadingSkeleton v-if="loading && !loadedOnce" type="dashboard" class="mt-4 !px-0 !pb-0" />

    <div v-else-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <EmptyState
      v-else-if="!metrics"
      icon="chart"
      :title="td('dash.empty_title')"
      :message="td('dash.empty_message')"
    />

    <div v-else class="mt-4 space-y-4">
      <!-- ═══ KPI stat cards ═══ -->
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
        <div
          v-for="(k, i) in kpiCards"
          :key="k.label"
          class="bg-white/95 border border-slate-200 rounded-2xl p-4 flex flex-col"
        >
          <div class="flex items-center gap-2 text-slate-400">
            <Icon :name="k.icon" :size="16" />
            <span class="text-xs font-bold">{{ k.label }}</span>
          </div>
          <p class="text-xl font-bold text-slate-900 mt-2 leading-tight truncate">{{ k.value }}</p>
          <p class="text-xs text-slate-400 mt-0.5 truncate">{{ k.sub }}</p>
          <!-- §4.2 — the closed-deals-without-an-order disclosure. Rendered
               ONLY when the server sends a non-zero count; it wraps rather
               than truncating, because a caveat you cannot read is not one. -->
          <p v-if="k.note" class="text-[11px] text-amber-600 mt-1 leading-snug">{{ k.note }}</p>
          <!-- §4.4 — the KPI sparklines are the same 6-month series as the
               big chart below, so they are gated on the same question. A flat
               sparkline under a ฿0 card is the miniature of F-13. -->
          <div v-if="i === 1 && hasMoneyHistory" class="mt-auto pt-2 -mx-1">
            <VueApexCharts
              data-chart="sales-spark"
              type="area"
              height="40"
              :options="salesSparkOptions"
              :series="salesSparkSeries"
            />
          </div>
          <div v-else-if="i === 2 && hasMoneyHistory" class="mt-auto pt-2 -mx-1">
            <VueApexCharts
              data-chart="commission-spark"
              type="area"
              height="40"
              :options="commissionSparkOptions"
              :series="commissionSparkSeries"
            />
          </div>
        </div>
      </div>

      <!-- ═══ Area chart (wide) + Radial gauge ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 bg-white/95 border border-slate-200 rounded-2xl p-4 lg:p-5">
          <p class="text-sm font-bold text-slate-900 mb-1">{{ td('dash.chart_sales_title') }}</p>
          <!-- D3 — the two series are now on the SAME time axis: ยอดขาย is
               bucketed on the date the CUSTOMER paid, ค่าคอม on the date the
               company disbursed. They answer different questions. -->
          <p class="text-xs text-slate-400 mb-2">
            {{ td('dash.chart_sales_help') }}
          </p>
          <VueApexCharts
            v-if="hasMoneyHistory"
            data-chart="money"
            type="area"
            height="300"
            :options="areaOptions"
            :series="areaSeries"
          />
          <!-- §4.4 (F-13) — a flat 6-month line on a brand-new company reads
               as "we measured, and it was zero". Nothing was measured. -->
          <EmptyState
            v-else
            icon="chart"
            :title="td('common.no_data')"
            :message="td('dash.chart_sales_empty')"
          />
        </div>
        <div class="bg-white/95 border border-slate-200 rounded-2xl p-4 lg:p-5 flex flex-col">
          <p class="text-sm font-bold text-slate-900 mb-1">{{ td('dash.chart_conversion_title') }}</p>
          <!-- D4 — "ปิด" = ดีลที่ไปถึงขั้นชำระเงินแล้ว รวมขั้นหลังการขายทุกขั้น
               (จัดส่ง / นัดใช้บริการ / ติดตามผล). Advancing a paid deal must
               never reduce this rate — that was F-3. -->
          <p class="text-xs text-slate-400 leading-snug">
            {{ td('dash.chart_conversion_help') }}
          </p>
          <div class="flex-1 flex items-center justify-center">
            <VueApexCharts
              v-if="hasDeals"
              data-chart="conversion"
              type="radialBar"
              height="280"
              :options="conversionOptions"
              :series="conversionSeries"
            />
            <!-- §4.4 — with no deals there is no denominator, and a 0% gauge
                 is a confident statement about a division nobody performed. -->
            <EmptyState
              v-else
              icon="deal"
              :title="td('common.no_data')"
              :message="td('dash.chart_conversion_empty')"
              class="w-full"
            />
          </div>
        </div>
      </div>

      <!-- ═══ New-agents bar (wide) + Cert donut ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 bg-white/95 border border-slate-200 rounded-2xl p-4 lg:p-5">
          <p class="text-sm font-bold text-slate-900 mb-1">{{ td('dash.chart_new_agents_title') }}</p>
          <p class="text-xs text-slate-400 mb-2">{{ td('dash.chart_new_agents_help') }}</p>
          <VueApexCharts
            v-if="hasAgents"
            data-chart="new-agents"
            type="bar"
            height="280"
            :options="newAgentsOptions"
            :series="newAgentsSeries"
          />
          <!-- §4.4 — no agents at all ⇒ "how many joined each month" was
               never measured. (An empty month for a company that HAS agents
               is a real zero and still renders.) -->
          <EmptyState v-else icon="users" :title="td('common.no_data')" :message="td('dash.chart_agents_empty')" />
        </div>
        <div class="bg-white/95 border border-slate-200 rounded-2xl p-4 lg:p-5 flex flex-col">
          <p class="text-sm font-bold text-slate-900 mb-1">{{ td('dash.chart_cert_title') }}</p>
          <!-- §3.8 — the denominator, stated. One agent counts once, under
               their HIGHEST passed tier; the rest sit in ยังไม่มีใบรับรอง. -->
          <p class="text-xs text-slate-400 mb-2 leading-snug">
            {{ td('dash.chart_cert_help', '', { count: totals?.agents_total ?? 0 }) }}
          </p>
          <div class="flex-1 flex items-center justify-center">
            <VueApexCharts
              v-if="hasCert"
              data-chart="cert"
              type="donut"
              height="280"
              :options="certOptions"
              :series="certSeries"
            />
            <EmptyState
              v-else
              icon="shield_check"
              :title="td('common.no_data')"
              :message="td('dash.chart_agents_empty')"
              class="w-full"
            />
          </div>
        </div>
      </div>

      <!-- ═══ Pipeline funnel (wide) + Lead source ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 bg-white/95 border border-slate-200 rounded-2xl p-4 lg:p-5">
          <p class="text-sm font-bold text-slate-900 mb-1">{{ td('dash.chart_stage_title') }}</p>
          <!-- §4.1 — every stage the server knows about (ADR-026's full
               vocabulary), so these bars add up to ดีลทั้งหมด. -->
          <p class="text-xs text-slate-400 mb-2">
            {{ td('dash.chart_stage_help', '', { total: totals?.deals_total ?? 0 }) }}
          </p>
          <VueApexCharts
            v-if="hasDeals"
            data-chart="funnel"
            type="bar"
            height="280"
            :options="stageOptions"
            :series="stageSeries"
          />
          <EmptyState v-else icon="pipeline" :title="td('common.no_data')" :message="td('dash.chart_stage_empty')" />
        </div>
        <div class="bg-white/95 border border-slate-200 rounded-2xl p-4 lg:p-5">
          <p class="text-sm font-bold text-slate-900 mb-1">{{ td('dash.chart_source_title') }}</p>
          <p class="text-xs text-slate-400 mb-3">{{ td('dash.chart_source_help') }}</p>
          <div v-if="leadSources.length" class="space-y-3">
            <div v-for="(s, idx) in leadSources" :key="s.source">
              <div class="flex items-center justify-between text-xs mb-1">
                <span class="font-bold text-slate-600 truncate">{{ s.source }}</span>
                <span class="text-slate-400 shrink-0">{{ s.count }} · {{ leadPct(s.count) }}%</span>
              </div>
              <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                <div
                  class="h-full rounded-full"
                  :style="{ width: `${leadPct(s.count)}%`, background: BRAND_SHADES[idx % BRAND_SHADES.length] }"
                ></div>
              </div>
            </div>
          </div>
          <EmptyState v-else icon="pie_chart" :title="td('dash.chart_source_empty')" />
        </div>
      </div>

      <!-- ═══ Top agents (wide) + Pending live list ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 bg-white/95 border border-slate-200 rounded-2xl p-4 lg:p-5">
          <!-- §4.2 — this ranks PAID commission only (commission_ledger rows
               with payment_status = paid). Pending commission is not in it,
               so the label has to say which of the two it is. -->
          <p class="text-sm font-bold text-slate-900 mb-1">{{ td('dash.top_agents_title') }}</p>
          <p class="text-xs text-slate-400 mb-3">{{ td('dash.top_agents_help') }}</p>
          <div v-if="topAgents.length" class="space-y-3">
            <div v-for="(a, i) in topAgents" :key="a.agent_id" class="flex items-center gap-3">
              <span class="text-xs font-bold text-slate-400 w-4 shrink-0 text-center">{{ i + 1 }}</span>
              <span
                class="w-8 h-8 rounded-full bg-brand-500 text-white text-xs font-bold flex items-center justify-center shrink-0"
              >
                {{ initial(a.name) }}
              </span>
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-sm font-bold text-slate-900 truncate">{{ a.name }}</span>
                  <span class="text-sm font-bold text-slate-900 shrink-0">฿{{ baht(a.commission_satang) }}</span>
                </div>
                <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden mt-1.5">
                  <div class="h-full rounded-full bg-brand-500" :style="{ width: topBarWidth(a.commission_satang) }"></div>
                </div>
              </div>
            </div>
          </div>
          <EmptyState v-else icon="trophy" :title="td('dash.top_agents_empty')" />
        </div>

        <div class="bg-white/95 border border-slate-200 rounded-2xl p-4 lg:p-5">
          <div class="flex items-center justify-between mb-3">
            <!-- §3.4 (F-7) — the queue holds every role, not just agents. -->
            <p class="text-sm font-bold text-slate-900">{{ td('dash.pending_title') }}</p>
            <!-- The badge is the SERVER's total, not this page's row count:
                 the endpoint paginates at 15 and the list below shows one
                 page, so `pendingAgents.length` capped the badge at 15 while
                 the KPI above counted the whole queue (F-7). -->
            <span
              v-if="!pendingError"
              class="text-xs font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600"
            >{{ pendingTotal }}</span>
          </div>
          <!-- §4.4 (F-14) — a 403/500 used to render as the green "nothing
               pending". A failed request is not an empty queue; say so. -->
          <div
            v-if="pendingError"
            class="flex items-start gap-2 px-3 py-3 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-700"
          >
            <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
            <span>{{ td('dash.pending_unknown', '', { error: pendingError ?? '' }) }}</span>
          </div>
          <div v-else-if="pendingAgents.length" class="space-y-2">
            <div
              v-for="p in pendingAgents"
              :key="p.id"
              class="flex items-start gap-2 bg-white/95 border border-slate-200 rounded-xl p-3"
            >
              <Icon name="user_plus" :size="16" class="text-amber-600 mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 truncate">{{ p.name }}</p>
                <p class="text-xs text-slate-400 truncate">{{ p.email }}</p>
              </div>
            </div>
            <p v-if="pendingTotal > pendingAgents.length" class="text-[11px] text-slate-400 pt-1">
              {{ td('dash.pending_showing', '', { shown: pendingAgents.length, total: pendingTotal }) }}
            </p>
          </div>
          <div v-else-if="pendingLoaded" class="flex items-center gap-2 text-xs text-slate-400 py-4">
            <Icon name="check_circle" :size="16" class="text-emerald-500" />
            {{ td('dash.pending_none') }}
          </div>
          <div v-else class="text-xs text-slate-400 py-4">{{ td('dash.pending_loading') }}</div>
        </div>
      </div>
    </div>
  </div>
</template>
