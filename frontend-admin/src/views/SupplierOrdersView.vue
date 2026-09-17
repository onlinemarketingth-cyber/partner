<script setup lang="ts">
/**
 * SupplierOrdersView — "คำสั่งซื้อสินค้าของฉัน", for a Company Partner.
 *
 * Owner, 2026-09-16: "จะมีอีก 1 หน้าที่สำหรับการ ดูว่าลูกค้าสั่งสินค้าอะไรของ
 * ตนเองบ้าง" — and, later, "supplier เป็นคนส่งเอง".
 *
 * ── THIS SCREEN SHOWS ONE COMPANY ANOTHER COMPANY'S CUSTOMERS ──
 *
 * Which is a thing BR-6 forbids everywhere else in this product. It is here
 * because the owner ruled suppliers ship their own goods, and you cannot post
 * a parcel to somebody whose address you may not read.
 *
 * So the screen is built to the smallest shape that does the job. It shows the
 * customer's name, and — only for products that actually ship — their phone
 * and address. It does not show who sold it, what we paid the seller, or what
 * we kept: the server does not send those fields at all
 * (SupplierOrderResource), so this file could not display them if it tried.
 *
 * ── THE DEFAULT FILTER IS "รอจัดส่ง" ──
 *
 * The reason a supplier opens this page is to find out what they have to send
 * today. Showing every order they have ever fulfilled and making them hunt
 * would bury that under history on the second day of trading.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { formatDateTime, formatMoney } from '@/composables/useClientFile'

interface SupplierOrder {
  id: number
  order_number: string
  status: string
  status_label: string
  paid_at: string | null
  created_at: string | null
  product_name: string | null
  sale_price_satang: number
  customer_name: string | null
  requires_shipping: boolean
  shipping_status: string | null
  shipping_status_label: string | null
  tracking_number: string | null
  shipped_at: string | null
  /**
   * ABSENT — not null — on a product that does not ship.
   *
   * The server omits the keys entirely rather than sending nulls, so a
   * supplier of services never receives a payload shaped as though contact
   * details exist and happen to be empty. Optional here to match.
   */
  shipping_recipient_name?: string
  shipping_phone?: string
  shipping_address?: string
}

const orders = ref<SupplierOrder[]>([])
const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const onlyPending = ref(true)

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const query = onlyPending.value ? '?shipping_status=pending' : ''
    const res = await api.get<{ data: SupplierOrder[] }>(`/supplier/orders${query}`)
    orders.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError
      ? `โหลดคำสั่งซื้อไม่สำเร็จ (${e.status})`
      : 'โหลดคำสั่งซื้อไม่สำเร็จ'
    orders.value = []
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

onMounted(load)

async function toggleFilter(pending: boolean): Promise<void> {
  if (onlyPending.value === pending) return
  onlyPending.value = pending
  await load()
}

/* ── Recording a shipment ───────────────────────────────────────────────── */

const shipTargetId = ref<number | null>(null)
const trackingInput = ref('')
const shipping = ref(false)

function openShip(order: SupplierOrder): void {
  shipTargetId.value = order.id
  trackingInput.value = ''
}

async function confirmShip(): Promise<void> {
  if (shipTargetId.value === null) return
  shipping.value = true
  errorMessage.value = ''
  try {
    await api.post(`/supplier/orders/${shipTargetId.value}/ship`, {
      tracking_number: trackingInput.value.trim(),
    })
    shipTargetId.value = null
    await load()
  } catch (e) {
    // In full — the server's refusal names which of the three conditions
    // failed (not shippable, not paid, already sent), and each has a
    // different answer.
    errorMessage.value = e instanceof ApiError
      ? `บันทึกการจัดส่งไม่สำเร็จ: ${e.message}`
      : 'บันทึกการจัดส่งไม่สำเร็จ'
  } finally {
    shipping.value = false
  }
}

const pendingCount = computed(() => orders.value.filter((o) => o.shipping_status === 'pending' && o.requires_shipping).length)

const kpis = computed(() => [
  { label: 'รอจัดส่ง', value: pendingCount.value },
  { label: 'รายการที่แสดง', value: orders.value.length },
])
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="box"
      title="คำสั่งซื้อสินค้าของฉัน"
      subtitle="คำสั่งซื้อที่ชำระเงินแล้วสำหรับสินค้าที่คุณเป็นผู้จัดหา"
      :kpis="kpis"
      accent-color="brand"
      storage-key="supplier-orders"
    />

    <div class="mt-4 flex items-center gap-2">
      <button
        type="button"
        data-test="filter-pending"
        class="min-h-[36px] px-3 rounded-lg text-xs font-bold border transition"
        :class="onlyPending ? 'bg-amber-50 border-amber-400 text-amber-700' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
        @click="toggleFilter(true)"
      >รอจัดส่ง</button>
      <button
        type="button"
        data-test="filter-all"
        class="min-h-[36px] px-3 rounded-lg text-xs font-bold border transition"
        :class="!onlyPending ? 'bg-slate-100 border-slate-400 text-slate-700' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
        @click="toggleFilter(false)"
      >ทั้งหมด</button>
    </div>

    <p
      v-if="errorMessage"
      class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-300 text-sm text-rose-800"
      data-test="error"
    >{{ errorMessage }}</p>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" class="mt-4" />

    <EmptyState
      v-else-if="orders.length === 0"
      class="mt-4"
      icon="box"
      :title="onlyPending ? 'ไม่มีรายการรอจัดส่ง' : 'ยังไม่มีคำสั่งซื้อสำหรับสินค้าของคุณ'"
    />

    <div v-else class="mt-4 space-y-3">
      <!--
        CARDS, NOT A TABLE — the opposite choice from the payments screen, and
        for the opposite reason. That screen is scanned down a column of
        figures; this one is worked one order at a time, and the address is a
        block of text nobody can read out of a table cell.
      -->
      <article
        v-for="order in orders"
        :key="order.id"
        class="bg-white/95 border border-slate-200 rounded-xl p-4"
        data-test="supplier-order"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="font-bold text-slate-900">{{ order.product_name ?? '—' }}</p>
            <p class="text-xs text-slate-500 mt-0.5">
              {{ order.order_number }} · ชำระเมื่อ {{ order.paid_at ? formatDateTime(order.paid_at) : '—' }}
            </p>
          </div>
          <div class="text-right">
            <p class="font-bold text-slate-900">฿{{ formatMoney(order.sale_price_satang) }}</p>
            <span
              v-if="order.requires_shipping"
              class="inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-bold"
              :class="order.shipping_status === 'pending'
                ? 'bg-amber-100 text-amber-700'
                : 'bg-emerald-100 text-emerald-700'"
            >{{ order.shipping_status_label }}</span>
          </div>
        </div>

        <p class="text-sm text-slate-600 mt-2">ลูกค้า: {{ order.customer_name ?? 'ไม่ระบุ' }}</p>

        <!-- Only rendered when the product ships. The keys are absent
             otherwise, so this cannot show an empty address block for a
             service appointment. -->
        <div
          v-if="order.requires_shipping"
          class="mt-3 p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm"
          data-test="shipping-block"
        >
          <p class="text-xs font-bold text-slate-500 mb-1">ที่อยู่จัดส่ง</p>
          <p class="text-slate-800">{{ order.shipping_recipient_name || '—' }}</p>
          <p class="text-slate-600">{{ order.shipping_phone || '—' }}</p>
          <p class="text-slate-600 whitespace-pre-line">{{ order.shipping_address || '—' }}</p>

          <p v-if="order.tracking_number" class="mt-2 text-xs text-emerald-700 font-bold">
            เลขพัสดุ {{ order.tracking_number }}
            <span v-if="order.shipped_at" class="font-normal text-slate-500">
              · ส่งเมื่อ {{ formatDateTime(order.shipped_at) }}
            </span>
          </p>
        </div>

        <div v-if="order.requires_shipping && order.shipping_status === 'pending'" class="mt-3">
          <button
            v-if="shipTargetId !== order.id"
            type="button"
            data-test="open-ship"
            class="min-h-[38px] px-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 transition"
            @click="openShip(order)"
          >
            <Icon name="upload" :size="14" /> บันทึกการจัดส่ง
          </button>

          <!-- The tracking number is required, and the label says why it is
               being asked for rather than simply demanding it. -->
          <div v-else class="p-3 rounded-lg bg-brand-50 border border-brand-200" data-test="ship-panel">
            <label class="block">
              <span class="text-xs font-bold text-slate-600">เลขพัสดุ</span>
              <input
                v-model="trackingInput"
                type="text"
                data-test="tracking-input"
                placeholder="เช่น TH1234567890"
                class="mt-1 w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm"
              />
            </label>
            <div class="flex items-center gap-2 mt-3">
              <button
                type="button"
                data-test="confirm-ship"
                :disabled="shipping || trackingInput.trim() === ''"
                class="min-h-[38px] px-4 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 disabled:opacity-60 transition"
                @click="confirmShip"
              >{{ shipping ? 'กำลังบันทึก...' : 'ยืนยันว่าจัดส่งแล้ว' }}</button>
              <button
                type="button"
                data-test="cancel-ship"
                class="min-h-[38px] px-4 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-white transition"
                @click="shipTargetId = null"
              >ยกเลิก</button>
            </div>
          </div>
        </div>
      </article>
    </div>
  </main>
</template>
