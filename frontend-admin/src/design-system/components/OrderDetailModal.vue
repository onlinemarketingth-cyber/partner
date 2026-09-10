<script setup lang="ts">
/**
 * OrderDetailModal — one order, in full, and printable.
 *
 * 2026-09-10 (human: "ปุ่มดูรายละเอียด [ดูออเดอร์พร้อมแบบฟอร์มสำหรับพิมพ์]").
 *
 * ── WHY A PRINTABLE SHEET AT ALL ──
 *
 * A card list answers "which orders are there". It cannot answer "what
 * exactly did this customer buy, from whom, for how much, and when" in a
 * form that can go in an envelope, into a delivery box, or onto a desk next
 * to the goods. That question gets asked of every sale, and until now it was
 * answered by copying figures off the screen by hand.
 *
 * ── WHY IT PRINTS THE PAGE RATHER THAN OPENING A NEW ONE ──
 *
 * A second window is another route to keep in step, another place the order
 * shape has to be read, and it is what popup blockers eat on the exact tap
 * that matters. Instead this sheet is ALREADY the print layout: a
 * `@media print` block hides everything on the page except it, and
 * `window.print()` prints what the person is looking at. What they see is
 * what comes out.
 */
import { computed, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from './Icon.vue'
import { formatDateTime, formatMoney } from '@/composables/useClientFile'

const props = defineProps<{ orderId: number | null }>()
const emit = defineEmits<{ (e: 'close'): void }>()

interface OrderDetail {
  id: number
  order_number: string
  status: string
  status_label: string
  payment_method_label: string | null
  payment_provider: string | null
  payment_provider_label: string | null
  gateway_mode: string | null
  gateway_payment_received: boolean
  amount_satang: number
  client_name: string | null
  client_email: string | null
  product_name: string | null
  agent: { id: number | null; name: string | null } | null
  paid_at: string | null
  verified_by: { id: number; name: string } | null
  created_at: string
}

const order = ref<OrderDetail | null>(null)
const loading = ref(false)
const error = ref('')

/** Printed at the top of the sheet, so a page on a desk says whose it is. */
const printedAt = ref('')

async function load(id: number) {
  loading.value = true
  error.value = ''
  order.value = null

  try {
    const res = await api.get<{ data: OrderDetail }>(`/orders/${id}`)
    order.value = res.data
    printedAt.value = formatDateTime(new Date().toISOString())
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลคำสั่งซื้อไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

watch(
  () => props.orderId,
  (id) => {
    if (id !== null) void load(id)
  },
  { immediate: true },
)

const isOpen = computed(() => props.orderId !== null)

/**
 * A test-mode charge is not revenue, and a sheet that goes in an envelope
 * must not be able to be mistaken for one.
 */
const isTestMode = computed(() => order.value?.gateway_mode === 'test')

function print() {
  window.print()
}
</script>

<template>
  <Teleport to="body">
    <div v-if="isOpen" class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto print-root">
      <div class="absolute inset-0 bg-slate-900/40 no-print" @click="emit('close')" />

      <div class="relative w-full max-w-2xl my-8 bg-white rounded-2xl shadow-2xl print-sheet">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 no-print">
          <h2 class="text-sm font-bold text-slate-800">รายละเอียดคำสั่งซื้อ</h2>
          <div class="flex items-center gap-2">
            <button
              v-if="order"
              type="button"
              data-test="print-order"
              class="min-h-[36px] px-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700"
              @click="print"
            >
              <Icon name="document" :size="14" /> พิมพ์
            </button>
            <button
              type="button"
              class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
              @click="emit('close')"
            >
              <Icon name="x" :size="18" />
            </button>
          </div>
        </div>

        <div v-if="loading" class="px-5 py-10 text-center text-sm text-slate-400">กำลังโหลด...</div>
        <p v-else-if="error" class="px-5 py-10 text-center text-sm font-bold text-rose-600">{{ error }}</p>

        <div v-else-if="order" class="p-6 sm:p-8 space-y-6">
          <!-- The sheet's own heading. Printed, unlike the modal chrome
               above it, because a page with no title is a page nobody can
               file. -->
          <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
            <div>
              <p class="text-lg font-bold text-slate-900">ใบคำสั่งซื้อ</p>
              <p class="mt-1 text-2xl font-bold tracking-wide text-slate-900">{{ order.order_number }}</p>
            </div>
            <div class="text-right text-xs text-slate-500">
              <p>วันที่สั่งซื้อ: <span class="font-bold text-slate-800">{{ formatDateTime(order.created_at) }}</span></p>
              <p v-if="printedAt" class="mt-0.5">พิมพ์เมื่อ: {{ printedAt }}</p>
            </div>
          </div>

          <p
            v-if="isTestMode"
            class="px-3 py-2 rounded-lg bg-amber-50 border border-amber-300 text-xs font-bold text-amber-800"
          >
            โหมดทดสอบ — รายการนี้ไม่ใช่ยอดขายจริง
          </p>

          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
            <div class="sm:col-span-2 flex justify-between gap-4 border-b border-slate-100 pb-3">
              <dt class="text-slate-500">สินค้า</dt>
              <dd class="font-bold text-slate-900 text-right">{{ order.product_name ?? '—' }}</dd>
            </div>
            <div class="sm:col-span-2 flex justify-between gap-4 border-b border-slate-100 pb-3">
              <dt class="text-slate-500">ยอดชำระ</dt>
              <dd class="text-xl font-bold text-slate-900">฿{{ formatMoney(order.amount_satang) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">ผู้ซื้อ</dt>
              <dd class="font-bold text-slate-800 text-right">{{ order.client_name ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">อีเมล</dt>
              <dd class="text-slate-800 text-right break-all">{{ order.client_email ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">ตัวแทน</dt>
              <dd class="text-slate-800 text-right">{{ order.agent?.name ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">สถานะ</dt>
              <dd class="font-bold text-slate-800 text-right">{{ order.status_label }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">ช่องทางชำระเงิน</dt>
              <dd class="text-slate-800 text-right">
                {{ order.payment_provider && order.payment_provider !== 'manual'
                  ? order.payment_provider_label
                  : (order.payment_method_label ?? '—') }}
              </dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">ชำระเมื่อ</dt>
              <dd class="text-slate-800 text-right">{{ order.paid_at ? formatDateTime(order.paid_at) : '—' }}</dd>
            </div>
            <div v-if="order.verified_by" class="sm:col-span-2 flex justify-between gap-4">
              <dt class="text-slate-500">ตรวจสอบโดย</dt>
              <dd class="text-slate-800 text-right">{{ order.verified_by.name }}</dd>
            </div>
          </dl>

          <!-- Money in, sale not closed. On the printed sheet as well as on
               screen: this is the single most important thing anyone
               handling this order needs to know about it. -->
          <p
            v-if="order.gateway_payment_received && order.status !== 'paid'"
            class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-300 text-xs font-bold text-rose-700"
          >
            ลูกค้าชำระเงินแล้ว แต่ระบบยังปิดการขายไม่สำเร็จ — ต้องตรวจสอบและยืนยันด้วยตนเอง
          </p>

          <div class="pt-8 grid grid-cols-2 gap-8 text-xs text-slate-500">
            <div class="text-center">
              <div class="h-12 border-b border-slate-300"></div>
              <p class="mt-1">ผู้ส่งมอบ</p>
            </div>
            <div class="text-center">
              <div class="h-12 border-b border-slate-300"></div>
              <p class="mt-1">ผู้รับสินค้า / วันที่</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style>
/*
 * NOT scoped: `print-root` is teleported to <body>, and the rule that has to
 * hide the REST of the page cannot be scoped to this component at all.
 *
 * Everything is hidden, then the sheet and its descendants are put back —
 * `visibility` rather than `display`, because hiding an ancestor by display
 * takes the sheet with it however visible the sheet itself is.
 */
@media print {
  body * {
    visibility: hidden;
  }

  .print-root,
  .print-root * {
    visibility: visible;
  }

  .print-root {
    position: absolute;
    inset: 0;
    overflow: visible;
    padding: 0;
  }

  .print-sheet {
    box-shadow: none;
    border-radius: 0;
    margin: 0;
    max-width: none;
  }

  .no-print {
    display: none !important;
  }
}
</style>
