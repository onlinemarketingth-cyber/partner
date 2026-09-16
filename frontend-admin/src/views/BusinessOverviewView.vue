<script setup lang="ts">
/**
 * ภาพรวมธุรกิจ — /reports/business. 2026-09-16.
 *
 * Owner: "ที่ผมว่ามันยังขาดจริง คือหน้าที่ให้ทีมบริหารค่าคอมได้ ตัวเลขที่จำเป็น
 * ต่างๆ สำหรับผู้บริหารในการบริหารการเงิน ยอดขาย สินค้าขายดีต่างๆ" — and, on
 * the layout, แบบ A: the money reads left to right as a subtraction.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ONE REQUEST, ONE WINDOW, ONE DATE COLUMN.
 *
 * Everything here comes from GET /business-overview. It would have been
 * cheaper to assemble this page from the five report endpoints that already
 * exist — and it would have been wrong: four of them bucket on four different
 * date columns, so "กันยายน" means four different things across them. Putting
 * those side by side and subtracting them is how a dashboard produces a
 * confident number nobody can reconcile.
 *
 * The axis the server used is printed on the screen, because it is the one
 * thing a reader cannot infer from the numbers.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT THIS SCREEN REFUSES TO PRINT ──
 *
 * A ratio over no sales (null, not 0%). A gross profit over sales whose cost
 * nobody recorded (excluded, and counted out loud). A revenue figure that
 * quietly drops a refund the gateway reported but no human actioned (kept, and
 * disclosed). Each of those would make the page look more finished and mean
 * less, and each has a `null` coming from the server precisely so this file
 * cannot paper over it with `?? 0`.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { RouterLink } from 'vue-router'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

const activeCompany = useActiveCompanyStore()

interface Overview {
  window: { from: string; to: string; axis: string }
  money: {
    revenue_satang: number
    orders_paid: number
    commission_satang: number
    cost_satang: number
    gross_profit_satang: number
    /** null when there were no sales — 0% over nothing is not a fact. */
    commission_ratio: number | null
    gross_margin_ratio: number | null
    average_order_satang: number | null
  }
  commission_pipeline: { unpaid_satang: number; scheduled_satang: number; paid_satang: number }
  monthly: Array<{ month: string; revenue_satang: number; orders: number }>
  top_products: Array<{
    product_id: number
    product_name: string
    units: number
    revenue_satang: number
    /** null when NOTHING about this product could be costed. */
    cost_satang: number | null
    uncosted_units: number
  }>
  top_agents: Array<{ agent_id: number; agent_name: string; orders: number; revenue_satang: number }>
  clients: { new_clients: number; deals_closed: number }
  disclosures: {
    orders_with_reported_refund: number
    closed_deals_without_paid_order: number
    orders_without_cost: number
  }
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const data = ref<Overview | null>(null)

// ── The window ───────────────────────────────────────────────────────────
type Preset = 'month' | 'quarter' | 'year' | 'custom'
const preset = ref<Preset>('month')
const dateFrom = ref('')
const dateTo = ref('')

function iso(d: Date): string {
  return d.toISOString().slice(0, 10)
}

/**
 * The window each preset means.
 *
 * Computed here rather than sent as a name, so the server has one contract
 * (two dates) and the screen can offer any period without a backend release.
 */
function windowFor(p: Preset): { from: string; to: string } {
  const now = new Date()

  if (p === 'quarter') {
    const firstMonthOfQuarter = Math.floor(now.getMonth() / 3) * 3
    return { from: iso(new Date(now.getFullYear(), firstMonthOfQuarter, 1)), to: iso(now) }
  }

  if (p === 'year') {
    return { from: iso(new Date(now.getFullYear(), 0, 1)), to: iso(now) }
  }

  return { from: iso(new Date(now.getFullYear(), now.getMonth(), 1)), to: iso(now) }
}

function choosePreset(p: Preset): void {
  preset.value = p

  if (p !== 'custom') {
    const w = windowFor(p)
    dateFrom.value = w.from
    dateTo.value = w.to
  }

  void load()
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const params = new URLSearchParams()
    if (dateFrom.value) params.set('date_from', dateFrom.value)
    if (dateTo.value) params.set('date_to', dateTo.value)
    const query = params.toString()

    const res = await api.get<{ data: Overview }>(
      activeCompany.scopedPath(`/business-overview${query ? `?${query}` : ''}`),
    )
    data.value = res.data
  } catch (e) {
    // The whole page is one request, so a failure leaves NOTHING rendered
    // rather than a half-filled dashboard whose blank tiles read as zeros.
    data.value = null
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

onMounted(() => {
  const w = windowFor('month')
  dateFrom.value = w.from
  dateTo.value = w.to
  void load()
})

// Every figure is scoped server-side; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { void load() })

// ── Display ──────────────────────────────────────────────────────────────
// BR-3 — satang in, baht out. Divided by 100 only here.
function baht(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { maximumFractionDigits: 0 })
}

/**
 * A percentage, or the reason there isn't one.
 *
 * `?? 0` here would print "0.0%" for a period with no sales, which reads as a
 * measured fact about the business rather than as the absence of one.
 */
function percent(value: number | null): string {
  return value === null ? '—' : `${value.toFixed(1)}%`
}

const monthLabels = computed(() =>
  (data.value?.monthly ?? []).map((m) => {
    const [year, month] = m.month.split('-')
    const names = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.']
    return { ...m, label: `${names[Number(month) - 1] ?? month} ${String(Number(year) + 543).slice(-2)}` }
  }),
)

const peakRevenue = computed(() =>
  Math.max(1, ...(data.value?.monthly ?? []).map((m) => m.revenue_satang)),
)

/** Anything the figures above could not measure. Empty when there is nothing to say. */
const disclosures = computed(() => {
  const d = data.value?.disclosures
  if (!d) return []

  const out: string[] = []

  if (d.closed_deals_without_paid_order > 0) {
    out.push(`${d.closed_deals_without_paid_order} ดีลปิดการขายแล้วแต่ไม่มีคำสั่งซื้อที่ชำระเงิน — ยอดขายด้านบนจึงไม่ได้นับดีลเหล่านี้`)
  }
  if (d.orders_with_reported_refund > 0) {
    out.push(`${d.orders_with_reported_refund} รายการที่ธนาคาร/ผู้ให้บริการแจ้งว่าคืนเงินแล้ว แต่สถานะในระบบยังเป็นชำระแล้ว — ยังถูกนับเป็นยอดขายอยู่`)
  }
  if (d.orders_without_cost > 0) {
    out.push(`${d.orders_without_cost} รายการที่สินค้ายังไม่ได้ตั้งต้นทุน — ไม่ถูกนำไปคิดกำไรขั้นต้น (ไม่ได้คิดเป็นกำไรเต็ม)`)
  }

  return out
})

const kpis = computed(() => {
  const m = data.value?.money

  return [
    { label: 'ยอดขาย', value: m ? `${baht(m.revenue_satang)} บาท` : '—' },
    { label: 'กำไรขั้นต้น', value: m ? `${baht(m.gross_profit_satang)} บาท` : '—' },
    { label: 'ค่าแนะนำ/ยอดขาย', value: m ? percent(m.commission_ratio) : '—' },
  ]
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="chart"
      title="ภาพรวมธุรกิจ"
      subtitle="เงินเข้า ค่าแนะนำ ต้นทุน และกำไร ในช่วงที่เลือก"
      description="ทุกตัวเลขในหน้านี้นับจากคำสั่งซื้อที่ชำระเงินแล้ว ตามวันที่รับเงินจริง — แกนเดียวกันทั้งหน้า จึงนำมาลบกันได้"
      :kpis="kpis"
      accent-color="brand"
      storage-key="business-overview"
    />

    <CompanyScopeNotice action="ดูภาพรวมธุรกิจ" />

    <!-- ═══ THE WINDOW ═══ -->
    <div class="mt-4 flex flex-wrap items-center gap-3">
      <div class="inline-flex p-[3px] bg-slate-100 rounded-xl">
        <button
          v-for="p in ([
            { key: 'month', label: 'เดือนนี้' },
            { key: 'quarter', label: 'ไตรมาสนี้' },
            { key: 'year', label: 'ปีนี้' },
            { key: 'custom', label: 'กำหนดเอง' },
          ] as const)"
          :key="p.key"
          type="button"
          class="px-4 py-1.5 rounded-[0.6rem] text-sm font-bold transition"
          :class="preset === p.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
          :data-test="`overview-preset-${p.key}`"
          @click="choosePreset(p.key)"
        >
          {{ p.label }}
        </button>
      </div>

      <div v-if="preset === 'custom'" class="flex items-center gap-2">
        <input v-model="dateFrom" type="date" class="h-9 px-3 rounded-xl border border-slate-200 text-sm" data-test="overview-from" />
        <span class="text-slate-400 text-sm">ถึง</span>
        <input v-model="dateTo" type="date" class="h-9 px-3 rounded-xl border border-slate-200 text-sm" data-test="overview-to" />
        <button
          type="button"
          class="h-9 px-4 rounded-xl bg-brand-600 text-white text-sm font-bold hover:bg-brand-700"
          data-test="overview-apply"
          @click="load"
        >
          ดูข้อมูล
        </button>
      </div>

      <p v-if="data" class="ml-auto text-[12px] text-slate-400" data-test="overview-axis">
        {{ data.window.from }} — {{ data.window.to }} · นับตามวันที่รับเงินจริง
      </p>
    </div>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />

    <template v-else-if="data">
      <!-- ═══ 1. เงินเดินซ้ายไปขวา (แบบ A) ═══
           The subtraction an owner does in their head, drawn as the
           subtraction it is. These four figures describe the SAME orders —
           that is what makes putting a minus sign between them legitimate,
           and it is only true because one endpoint computed all of them over
           one window on one date column. -->
      <div class="mt-4 p-5 rounded-2xl bg-white/95 border border-slate-200" data-test="overview-money">
        <div class="flex items-baseline justify-between gap-4">
          <p class="text-[15px] font-extrabold text-slate-900">เงินเข้า–เงินออก</p>
          <p class="text-[12px] text-slate-400">{{ data.money.orders_paid.toLocaleString('th-TH') }} คำสั่งซื้อที่ชำระแล้ว</p>
        </div>

        <div class="mt-4 flex flex-wrap items-stretch gap-0">
          <div class="flex-1 min-w-[170px] px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50">
            <p class="text-[11.5px] font-bold text-slate-400">เก็บเงินได้</p>
            <p class="mt-1 text-2xl font-extrabold tabular-nums" data-test="overview-revenue">{{ baht(data.money.revenue_satang) }}</p>
          </div>
          <div class="flex items-center px-2 text-xl text-slate-300">−</div>
          <div class="flex-1 min-w-[170px] px-4 py-3 rounded-2xl border border-amber-200 bg-amber-50">
            <p class="text-[11.5px] font-bold text-amber-700">ค่าแนะนำ</p>
            <p class="mt-1 text-2xl font-extrabold tabular-nums text-amber-700" data-test="overview-commission">{{ baht(data.money.commission_satang) }}</p>
          </div>
          <div class="flex items-center px-2 text-xl text-slate-300">−</div>
          <div class="flex-1 min-w-[170px] px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50">
            <p class="text-[11.5px] font-bold text-slate-400">ต้นทุนสินค้า</p>
            <p class="mt-1 text-2xl font-extrabold tabular-nums" data-test="overview-cost">{{ baht(data.money.cost_satang) }}</p>
          </div>
          <div class="flex items-center px-2 text-xl text-slate-300">=</div>
          <div class="flex-1 min-w-[190px] px-4 py-3 rounded-2xl border-2 border-brand-600 bg-brand-50">
            <p class="text-[11.5px] font-bold text-brand-700">กำไรขั้นต้น</p>
            <p class="mt-1 text-2xl font-extrabold tabular-nums text-brand-700" data-test="overview-profit">{{ baht(data.money.gross_profit_satang) }}</p>
            <p class="text-[12px] font-bold text-brand-700/80">{{ percent(data.money.gross_margin_ratio) }} ของยอดขายที่มีต้นทุน</p>
          </div>
        </div>

        <div class="mt-4 pt-3 border-t border-slate-100 flex flex-wrap gap-x-7 gap-y-3">
          <div>
            <p class="text-[11.5px] font-bold text-slate-400">ค่าแนะนำ ต่อ ยอดขาย</p>
            <p class="text-[17px] font-extrabold tabular-nums" data-test="overview-ratio">{{ percent(data.money.commission_ratio) }}</p>
          </div>
          <div>
            <p class="text-[11.5px] font-bold text-slate-400">ยอดขายเฉลี่ยต่อคำสั่งซื้อ</p>
            <p class="text-[17px] font-extrabold tabular-nums">
              {{ data.money.average_order_satang === null ? '—' : baht(data.money.average_order_satang) }}
            </p>
          </div>
          <div>
            <p class="text-[11.5px] font-bold text-slate-400">ดีลที่ปิดได้</p>
            <p class="text-[17px] font-extrabold tabular-nums">{{ data.clients.deals_closed.toLocaleString('th-TH') }}</p>
          </div>
          <div>
            <p class="text-[11.5px] font-bold text-slate-400">ลูกค้าใหม่</p>
            <p class="text-[17px] font-extrabold tabular-nums">{{ data.clients.new_clients.toLocaleString('th-TH') }}</p>
          </div>
        </div>
      </div>

      <!--
        ═══ WHAT THE FIGURES ABOVE COULD NOT MEASURE ═══
        Directly under them, not at the bottom of the page. A dashboard that
        hides this looks more authoritative and is less true — and every one
        of these three is a real reason a number above is not the whole story.
      -->
      <div
        v-if="disclosures.length"
        class="mt-3 px-4 py-3 rounded-2xl border border-amber-200 bg-amber-50"
        data-test="overview-disclosures"
      >
        <p class="text-[13px] font-extrabold text-amber-800">สิ่งที่ตัวเลขข้างบนยังไม่ได้นับ</p>
        <ul class="mt-1.5 space-y-1">
          <li v-for="line in disclosures" :key="line" class="text-[12.5px] text-amber-800 flex gap-2">
            <span class="text-amber-500">·</span><span>{{ line }}</span>
          </li>
        </ul>
      </div>

      <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- ═══ 2. ยอดขายรายเดือน ═══ -->
        <div class="lg:col-span-2 p-5 rounded-2xl bg-white/95 border border-slate-200">
          <p class="text-[14.5px] font-extrabold text-slate-900">ยอดขายรายเดือน</p>
          <p class="mt-0.5 text-[11.5px] text-slate-400">ทุกเดือนในช่วงที่เลือก รวมเดือนที่ไม่มียอด</p>

          <div class="mt-4 flex items-end gap-2 h-44" data-test="overview-chart">
            <div v-for="m in monthLabels" :key="m.month" class="flex-1 flex flex-col items-center justify-end h-full">
              <p class="text-[10.5px] font-bold text-slate-500 tabular-nums mb-1">{{ baht(m.revenue_satang) }}</p>
              <div
                class="w-full rounded-t-md bg-brand-500"
                :style="{ height: `${Math.max(2, (m.revenue_satang / peakRevenue) * 100)}%` }"
              />
              <p class="mt-1.5 text-[11px] text-slate-400 whitespace-nowrap">{{ m.label }}</p>
            </div>
          </div>
        </div>

        <!-- ═══ 3. เงินค่าแนะนำที่ค้างอยู่ — ยอดคงเหลือ ไม่ใช่ยอดของช่วงเวลา ═══ -->
        <div class="p-5 rounded-2xl bg-white/95 border border-slate-200" data-test="overview-pipeline">
          <p class="text-[14.5px] font-extrabold text-slate-900">เงินค่าแนะนำที่ต้องจ่าย</p>
          <!-- Says outright that this box ignores the date filter. A figure
               that does not move when the window does, sitting beside three
               that do, is otherwise read as a bug. -->
          <p class="mt-0.5 text-[11.5px] text-slate-400">ยอดคงเหลือ ณ ตอนนี้ — ไม่ขึ้นกับช่วงเวลาที่เลือก</p>

          <div class="mt-3 space-y-2">
            <div class="flex items-baseline justify-between px-3 py-2 rounded-xl bg-amber-50 border border-amber-200">
              <span class="text-[12.5px] font-bold text-amber-800">ยังไม่ได้ตั้งจ่าย</span>
              <span class="text-[15px] font-extrabold tabular-nums text-amber-800">{{ baht(data.commission_pipeline.unpaid_satang) }}</span>
            </div>
            <div class="flex items-baseline justify-between px-3 py-2 rounded-xl border border-slate-200">
              <span class="text-[12.5px] font-bold text-slate-600">ตั้งจ่ายแล้ว รอโอน</span>
              <span class="text-[15px] font-extrabold tabular-nums">{{ baht(data.commission_pipeline.scheduled_satang) }}</span>
            </div>
            <div class="flex items-baseline justify-between px-3 py-2 rounded-xl bg-emerald-50 border border-emerald-200">
              <span class="text-[12.5px] font-bold text-emerald-700">โอนแล้ว (สะสม)</span>
              <span class="text-[15px] font-extrabold tabular-nums text-emerald-700">{{ baht(data.commission_pipeline.paid_satang) }}</span>
            </div>
          </div>

          <!-- The one thing about these three that is easy to get wrong. -->
          <p class="mt-2 text-[11px] text-slate-400">
            “ตั้งจ่ายแล้ว รอโอน” เป็นส่วนหนึ่งของ “ยังไม่ได้ตั้งจ่าย” ไม่ใช่ยอดแยก จึงไม่ต้องบวกกัน
          </p>
          <RouterLink :to="{ name: 'commission-payouts' }" class="mt-2 inline-block text-[12.5px] font-bold text-brand-600 hover:underline">
            ไปหน้าตั้งจ่าย →
          </RouterLink>
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- ═══ 4. สินค้าขายดี — จากคำสั่งซื้อจริง ═══ -->
        <div class="lg:col-span-2 p-5 rounded-2xl bg-white/95 border border-slate-200">
          <div class="flex items-baseline justify-between gap-3">
            <p class="text-[14.5px] font-extrabold text-slate-900">สินค้าขายดี</p>
            <!-- Said plainly, because the other product screen in this app
                 does it the other way and the two will be compared. -->
            <p class="text-[11.5px] text-slate-400">นับจากคำสั่งซื้อที่ชำระแล้ว ไม่ใช่ประมาณการจากราคาปัจจุบัน</p>
          </div>

          <table class="w-full mt-3 text-sm">
            <thead>
              <tr class="text-left text-[11.5px] font-bold text-slate-500 border-b border-slate-200">
                <th class="py-2">สินค้า</th>
                <th class="py-2 w-20 text-right">ขายได้</th>
                <th class="py-2 w-32 text-right">ยอดขาย</th>
                <th class="py-2 w-32 text-right">กำไรขั้นต้น</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!data.top_products.length">
                <td colspan="4" class="py-4 text-[12.5px] text-slate-400">ยังไม่มีคำสั่งซื้อที่ชำระแล้วในช่วงนี้</td>
              </tr>
              <tr
                v-for="p in data.top_products"
                :key="p.product_id"
                class="border-b border-slate-100 last:border-0"
                :data-test="`overview-product-${p.product_id}`"
              >
                <td class="py-2.5 font-bold text-slate-900">
                  {{ p.product_name }}
                  <span v-if="p.uncosted_units > 0" class="block text-[11px] font-bold text-amber-700">
                    {{ p.uncosted_units }} รายการยังไม่ได้ตั้งต้นทุน
                  </span>
                </td>
                <td class="py-2.5 text-right tabular-nums text-slate-500">{{ p.units }}</td>
                <td class="py-2.5 text-right tabular-nums font-bold">{{ baht(p.revenue_satang) }}</td>
                <td class="py-2.5 text-right tabular-nums">
                  <!-- No cost at all for this product → the row says so and
                       links to the fix, instead of printing a margin equal to
                       the whole revenue. -->
                  <RouterLink
                    v-if="p.cost_satang === null"
                    :to="{ name: 'product-edit', params: { id: p.product_id } }"
                    class="text-[12px] font-bold text-amber-700 hover:underline"
                    :data-test="`overview-set-cost-${p.product_id}`"
                  >
                    ตั้งต้นทุน →
                  </RouterLink>
                  <span v-else class="font-bold text-emerald-600">
                    {{ baht(p.revenue_satang - p.cost_satang) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- ═══ 5. ตัวแทนที่ทำยอดสูงสุด — เรียงตามยอดขาย ═══ -->
        <div class="p-5 rounded-2xl bg-white/95 border border-slate-200">
          <p class="text-[14.5px] font-extrabold text-slate-900">ตัวแทนที่ทำยอดสูงสุด</p>
          <!-- The dashboard's own "top agents" ranks by commission already
               disbursed, which moves when payouts are run rather than when
               sales happen. Different question, different list. -->
          <p class="mt-0.5 text-[11.5px] text-slate-400">เรียงตามยอดขาย ไม่ใช่ค่าคอมที่จ่ายไปแล้ว</p>

          <div class="mt-3 space-y-1">
            <p v-if="!data.top_agents.length" class="text-[12.5px] text-slate-400">ยังไม่มียอดขายในช่วงนี้</p>
            <div
              v-for="(a, i) in data.top_agents"
              :key="a.agent_id"
              class="flex items-center gap-2.5 py-1.5 border-b border-slate-100 last:border-0"
            >
              <span
                class="w-5 h-5 rounded-md inline-flex items-center justify-center text-[11px] font-extrabold"
                :class="i === 0 ? 'bg-brand-700 text-white' : 'bg-slate-200 text-slate-600'"
              >{{ i + 1 }}</span>
              <span class="text-[12.5px] font-bold text-slate-900 truncate">{{ a.agent_name }}</span>
              <span class="ml-auto text-[13px] font-extrabold tabular-nums">{{ baht(a.revenue_satang) }}</span>
            </div>
          </div>

          <RouterLink :to="{ name: 'sales-team' }" class="mt-3 inline-flex items-center gap-1 text-[12.5px] font-bold text-brand-600 hover:underline">
            <Icon name="chart" :size="13" />
            ดูทีมขายทั้งหมด
          </RouterLink>
        </div>
      </div>
    </template>
  </main>
</template>
