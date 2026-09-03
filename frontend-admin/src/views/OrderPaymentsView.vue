<script setup lang="ts">
/**
 * OrderPaymentsView — "คำสั่งซื้อ / การชำระเงิน".
 *
 * ── WHY THIS SCREEN EXISTS (human, 2026-08-22) ──
 *
 * "ระบบตอนนี้ดูเฉพาะลูกค้าที่ชำระมา รอชำระที่ไหน" — and the honest answer was
 * nowhere. The Admin console had no order screen at all, across 38 views.
 * The Agent Portal's list called GET /orders bare, so even there the question
 * was answered by scrolling and reading status chips one row at a time. The
 * client modal added the same day answers it for ONE customer; nothing could
 * answer "who is waiting to pay".
 *
 * The API had been ready the whole time: GET /orders?status= already
 * filtered, and OrderPolicy already let a Company Admin see their company's
 * orders. Only the screen was missing.
 *
 * ── WHY "รอตรวจสลิป" IS THE FIRST TAB ──
 *
 * It is the only state blocked on OUR side. "รอชำระเงิน" waits on the
 * customer and no amount of staring moves it; a slip sitting unverified is
 * work somebody here has to do, and until they do it the deal cannot advance
 * and the agent cannot be paid. Ordering the tabs by status enum order would
 * have put it second, behind the queue nobody can act on.
 *
 * ── THE COUNTS COME FROM THE SERVER, NOT FROM THE ROWS ──
 *
 * A paginated list knows its own `total` but sums only the page, so totalling
 * money client-side produces a smaller number on a screen full of numbers —
 * the most plausible-looking wrong answer available. GET /orders/summary does
 * one GROUP BY over the same scoped query the list uses (OrderController::
 * scopedQuery), so a tab count is always the number of rows that tab shows.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ClientDetailModal from '@/design-system/components/ClientDetailModal.vue'
import { formatDateTime, formatMoney } from '@/composables/useClientFile'

interface OrderRow {
  id: number
  order_number: string
  status: string
  status_label: string
  amount_satang: number
  client_id: number | null
  client_name?: string | null
  product_name?: string | null
  agent?: { id: number | null; name: string | null } | null
  has_slip: boolean
  // ADR-027 (TASK-139) — the order's OWN gateway stamp, not the company's
  // current setting: an order taken through a gateway since switched away
  // from must still say how it was actually collected.
  payment_provider: string | null
  payment_provider_label: string | null
  gateway_mode: string | null
  /**
   * Money arrived through a gateway.
   *
   * `has_slip` above is "there is proof a person must judge"; this is
   * "there is proof a machine already produced". Both belong on this
   * screen, because an order with this true and status not paid is the one
   * case where somebody has been charged and the sale is not closed.
   */
  gateway_payment_received: boolean
  /**
   * The attempts that did NOT succeed.
   *
   * Without these an order a customer has failed to pay three times looks
   * identical to one nobody has opened — and those two call for opposite
   * actions from whoever is reading this screen.
   */
  last_payment_error: string | null
  last_payment_error_at: string | null
  /**
   * The GATEWAY says it refunded. NOT this company's own reversal — that one
   * is made by a person and carries the commission ledger with it. This is a
   * claim from outside that a person still has to act on, and the two must
   * not look the same on screen.
   */
  refund_reported_at: string | null
  refund_reported_satang: number | null
  paid_at: string | null
  verified_by?: { id: number; name: string } | null
  created_at: string
}

interface SummaryRow {
  status: string
  status_label: string
  count: number
  total_satang: number
}

/*
 * ADR-027 (TASK-139) — THE TAB THAT IS NOT A STATUS.
 *
 * GatewayPaymentService claims a charge id before confirming an order, and
 * swallows a confirmation that refuses rather than letting it become a
 * webhook retry loop or a "payment failed" shown to somebody already
 * charged. Right in both cases, and it leaves a residue: an order holding a
 * receipt for money the system could not finish acting on.
 *
 * Every other tab here is a status. This one is a QUERY
 * (?needs_attention=1), because the condition spans statuses — it is
 * "money in, sale not closed", which is exactly the thing that would never
 * be noticed if it were only a log line.
 */
const NEEDS_ATTENTION = 'needs_attention' as const

/*
 * TAB ORDER IS A PRODUCT DECISION, not the enum's declaration order.
 *
 * Sorted by "how much does this need a human right now":
 *   awaiting_verification — a slip nobody has checked. Blocked on US.
 *   pending               — waiting on the customer.
 *   paid                  — done; here to be looked up, not worked.
 *   cancelled / refunded  — history.
 */
const TABS = [
  { status: 'awaiting_verification', label: 'รอตรวจสลิป', tone: 'amber' },
  { status: 'pending', label: 'รอชำระเงิน', tone: 'rose' },
  { status: 'paid', label: 'ชำระแล้ว', tone: 'emerald' },
  { status: 'cancelled', label: 'ยกเลิก', tone: 'slate' },
  { status: 'refunded', label: 'คืนเงิน', tone: 'slate' },
  // Last, not first: it is normally EMPTY, and a tab that is usually empty
  // sitting at the front trains people to skip past the front. When it is
  // not empty it announces itself in red below.
  { status: NEEDS_ATTENTION, label: 'ได้รับเงินแล้วแต่ยืนยันไม่สำเร็จ', tone: 'rose' },
] as const

type TabStatus = (typeof TABS)[number]['status']

const activeStatus = ref<TabStatus>('awaiting_verification')
const orders = ref<OrderRow[]>([])
const summary = ref<SummaryRow[]>([])
/** Rides on /orders/summary — see NEEDS_ATTENTION's docblock for why. */
const needsAttentionCount = ref(0)
const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const openClientId = ref<number | null>(null)

function countFor(status: string): number {
  if (status === NEEDS_ATTENTION) return needsAttentionCount.value
  return summary.value.find((s) => s.status === status)?.count ?? 0
}
function totalFor(status: string): number {
  // No money total for the attention tab: summing it would put a figure on
  // screen that looks like revenue and is the opposite — money taken for
  // sales that did not close.
  if (status === NEEDS_ATTENTION) return 0
  return summary.value.find((s) => s.status === status)?.total_satang ?? 0
}

const activeTab = computed(() => TABS.find((t) => t.status === activeStatus.value) ?? TABS[0])

/**
 * The two requests are deliberately independent.
 *
 * The summary is cheap and whole-set; the list is paginated and per-tab.
 * Failing to load one must not blank the other — an admin who can see "4
 * waiting" but hit a list error still knows there is work, which is more
 * useful than an empty screen.
 */
async function loadSummary(): Promise<void> {
  try {
    const res = await api.get<{ data: SummaryRow[]; needs_attention?: number }>('/orders/summary')
    summary.value = res.data
    // Optional and defaulted, not required. A frontend deployed ahead of its
    // backend would otherwise render `undefined` into the tab badge — and
    // the honest answer when the server does not report this yet is zero,
    // not a blank.
    needsAttentionCount.value = res.needs_attention ?? 0
  } catch {
    // A failed count is a missing badge, not a broken page. The list below
    // carries the real content and reports its own errors.
    summary.value = []
    needsAttentionCount.value = 0
  }
}

async function loadOrders(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    // The attention tab is a query, not a status — see NEEDS_ATTENTION.
    const query =
      activeStatus.value === NEEDS_ATTENTION ? 'needs_attention=1' : `status=${activeStatus.value}`
    const res = await api.get<{ data: OrderRow[] }>(`/orders?${query}`)
    orders.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดคำสั่งซื้อไม่สำเร็จ (${e.status})` : 'โหลดคำสั่งซื้อไม่สำเร็จ'
    orders.value = []
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

async function selectTab(status: TabStatus): Promise<void> {
  if (activeStatus.value === status) return
  activeStatus.value = status
  await loadOrders()
}

onMounted(async () => {
  await Promise.all([loadSummary(), loadOrders()])
})

const kpis = computed(() => [
  { label: 'รอตรวจสลิป', value: countFor('awaiting_verification') },
  { label: 'รอชำระเงิน', value: countFor('pending') },
  { label: 'ชำระแล้ว', value: countFor('paid') },
])

async function viewSlip(order: OrderRow): Promise<void> {
  try {
    await api.download(`/orders/${order.id}/slip`, `slip-${order.order_number}.jpg`)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `เปิดสลิปไม่สำเร็จ (${e.status})` : 'เปิดสลิปไม่สำเร็จ'
  }
}

/**
 * A confirmed payment changes BOTH the row and the counts.
 *
 * Reloading only the list would leave the tab still reading "4" over three
 * rows — the exact count/list disagreement the shared server-side scope was
 * built to prevent, reintroduced on the client.
 */
async function refreshAll(): Promise<void> {
  await Promise.all([loadSummary(), loadOrders()])
}

function tabClasses(status: TabStatus, tone: string): string {
  const active = activeStatus.value === status
  if (!active) return 'border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-50'

  switch (tone) {
    case 'amber':
      return 'border-amber-400 text-amber-700 bg-amber-50'
    case 'rose':
      return 'border-rose-400 text-rose-700 bg-rose-50'
    case 'emerald':
      return 'border-emerald-400 text-emerald-700 bg-emerald-50'
    default:
      return 'border-slate-400 text-slate-700 bg-slate-100'
  }
}

function badgeClasses(tone: string): string {
  switch (tone) {
    case 'amber':
      return 'bg-amber-100 text-amber-700'
    case 'rose':
      return 'bg-rose-100 text-rose-700'
    case 'emerald':
      return 'bg-emerald-100 text-emerald-700'
    default:
      return 'bg-slate-200 text-slate-600'
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="money"
      title="คำสั่งซื้อ / การชำระเงิน"
      subtitle="ติดตามว่าใครชำระแล้ว ใครรอชำระ และรายการไหนรอตรวจสลิป"
      :kpis="kpis"
      accent-color="brand"
      storage-key="admin-order-payments"
    />

    <!-- ADR-027 (TASK-139) — announced, not merely available.
         This is money a customer has been charged for a sale that did not
         close. It is normally zero and its tab sits last, so without this
         banner it would sit unread behind four tabs nobody scrolls past. -->
    <button
      v-if="needsAttentionCount > 0 && activeStatus !== NEEDS_ATTENTION"
      type="button"
      class="mt-4 w-full text-left px-4 py-3 rounded-xl bg-rose-50 border border-rose-300 text-sm text-rose-800 flex items-center gap-3 hover:bg-rose-100 transition"
      @click="selectTab(NEEDS_ATTENTION)"
    >
      <Icon name="alert" :size="18" class="shrink-0" />
      <span class="flex-1">
        <span class="font-bold">{{ needsAttentionCount }} รายการได้รับเงินแล้วแต่ระบบยืนยันคำสั่งซื้อไม่สำเร็จ</span>
        <span class="block text-xs text-rose-600">ลูกค้าถูกตัดเงินไปแล้ว ต้องมีคนตรวจสอบและปิดการขายด้วยตนเอง</span>
      </span>
      <Icon name="chevron_right" :size="16" class="shrink-0" />
    </button>

    <!-- Tabs. The count rides ON the tab rather than inside the panel: the
         point of this screen is knowing there IS work before choosing to
         look at it. -->
    <div class="mt-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
      <button
        v-for="tab in TABS"
        :key="tab.status"
        type="button"
        class="shrink-0 min-h-[44px] px-4 border-b-2 rounded-t-lg text-sm font-bold transition flex items-center gap-2"
        :class="tabClasses(tab.status, tab.tone)"
        @click="selectTab(tab.status)"
      >
        {{ tab.label }}
        <span
          class="text-[11px] font-bold px-1.5 py-0.5 rounded-full min-w-[20px]"
          :class="activeStatus === tab.status ? badgeClasses(tab.tone) : 'bg-slate-100 text-slate-500'"
        >
          {{ countFor(tab.status) }}
        </span>
      </button>
    </div>

    <!-- The money for the tab you are looking at. Server-summed over the
         whole set, never over the visible page. -->
    <p class="mt-3 text-sm text-slate-500">
      {{ activeTab.label }}:
      <span class="font-bold text-slate-800">{{ countFor(activeStatus) }} รายการ</span>
      <!-- No money figure on the attention tab: a total there would read as
           revenue and is the opposite of it. -->
      <template v-if="activeStatus !== NEEDS_ATTENTION">
        · รวม
        <span class="font-bold text-slate-800">฿{{ formatMoney(totalFor(activeStatus)) }}</span>
      </template>
    </p>

    <div
      v-if="errorMessage"
      class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700 flex items-center justify-between gap-3"
    >
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[36px] px-3 rounded-lg text-xs font-bold text-rose-700 bg-rose-100 hover:bg-rose-200 transition"
        @click="refreshAll"
      >
        ลองใหม่
      </button>
    </div>

    <LoadingSkeleton v-else-if="loading && !hasLoadedOnce" type="list" :rows="5" class="mt-4" />

    <EmptyState
      v-else-if="orders.length === 0"
      icon="money"
      :title="`ไม่มีรายการ${activeTab.label}`"
      class="mt-6"
    />

    <div v-else class="mt-4 space-y-2">
      <div
        v-for="order in orders"
        :key="order.id"
        class="bg-white/95 border border-slate-200 rounded-xl p-4"
      >
        <div class="flex items-start gap-3 flex-wrap">
          <div class="min-w-0 flex-1">
            <p class="font-bold text-slate-900 truncate">
              {{ order.client_name ?? 'ไม่ระบุลูกค้า' }}
            </p>
            <p class="text-xs text-slate-400 truncate mt-0.5">
              {{ order.order_number }}
              <template v-if="order.product_name"> · {{ order.product_name }}</template>
              <template v-if="order.agent?.name"> · Agent: {{ order.agent.name }}</template>
            </p>
          </div>

          <div class="text-right shrink-0">
            <p class="font-bold text-slate-900">฿{{ formatMoney(order.amount_satang) }}</p>
            <p class="text-xs text-slate-400">{{ formatDateTime(order.created_at) }}</p>
          </div>
        </div>

        <!-- ADR-027 (TASK-139) — how this order was collected. A test-mode
             charge looks exactly like revenue everywhere else in this
             product unless the row itself says otherwise. -->
        <div class="mt-2 flex items-center gap-2 flex-wrap text-[11px] font-bold">
          <span
            v-if="order.payment_provider && order.payment_provider !== 'manual'"
            class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"
          >{{ order.payment_provider_label }}</span>
          <span
            v-if="order.gateway_mode === 'test'"
            class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700"
          >โหมดทดสอบ</span>
          <span
            v-if="order.gateway_payment_received"
            class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700"
          >ตัดบัตรสำเร็จแล้ว</span>
        </div>

        <p
          v-if="order.gateway_payment_received && order.status !== 'paid'"
          class="mt-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700"
        >
          ลูกค้าชำระเงินเรียบร้อยแล้ว แต่ระบบปิดการขายอัตโนมัติไม่สำเร็จ — กรุณาตรวจสอบขั้นตอนของรายการอ้างอิงนี้แล้วยืนยันด้วยตนเอง
        </p>

        <!--
          2026-09-03 — the gateway said money went back.

          Rose, above the amber failure line, and worded as a claim awaiting a
          decision rather than a fact about this company's books: the order is
          NOT marked refunded and no commission has moved. Doing either from a
          webhook would take money out of an agent's balance on an event
          nobody here reviewed.
        -->
        <p
          v-if="order.refund_reported_at"
          class="mt-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700"
        >
          ผู้ให้บริการแจ้งว่ามีการคืนเงิน
          <template v-if="order.refund_reported_satang">
            ฿{{ formatMoney(order.refund_reported_satang) }}
          </template>
          เมื่อ {{ formatDateTime(order.refund_reported_at) }} — ระบบยังไม่ได้กลับรายการขายหรือค่าคอมมิชชั่นให้
          กรุณาตรวจสอบและตัดสินใจด้วยตนเอง
        </p>

        <!--
          A failed or expired attempt. Amber, not rose: nothing is broken and
          nobody has lost money — the order is still open and the customer can
          pay on the same link. It is here so that "ยังไม่จ่าย" stops being one
          undifferentiated state.
        -->
        <p
          v-if="order.last_payment_error"
          class="mt-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-800"
        >
          {{ order.last_payment_error }}
          <template v-if="order.last_payment_error_at">
            · ล่าสุดเมื่อ {{ formatDateTime(order.last_payment_error_at) }}
          </template>
          <span class="font-bold"> — ลิงก์ชำระเงินเดิมยังใช้ได้ ลูกค้าลองใหม่ได้เลย</span>
        </p>

        <p v-if="order.paid_at" class="mt-2 text-xs text-emerald-700">
          ชำระเมื่อ {{ formatDateTime(order.paid_at) }}
          <template v-if="order.verified_by"> · ตรวจสอบโดย {{ order.verified_by.name }}</template>
          <!-- Never a fabricated name: verified_by is null both when nobody
               has confirmed and when the confirming user has been removed. -->
          <template v-else> · ตรวจสอบโดยไม่ทราบ</template>
        </p>

        <div class="mt-3 flex items-center gap-2 flex-wrap">
          <button
            v-if="order.has_slip"
            type="button"
            class="min-h-[36px] px-3 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-50 transition"
            @click="viewSlip(order)"
          >
            <Icon name="document" :size="14" /> ดูสลิป
          </button>

          <button
            v-if="order.client_id"
            type="button"
            class="min-h-[36px] px-3 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-50 transition"
            @click="openClientId = order.client_id"
          >
            <Icon name="user" :size="14" /> ดูลูกค้า
          </button>
        </div>
      </div>
    </div>

    <!-- Same modal the client list uses — one client detail surface, not a
         second copy that drifts. -->
    <ClientDetailModal :client-id="openClientId" @close="openClientId = null" />
  </main>
</template>
