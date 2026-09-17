<script setup lang="ts">
/**
 * SupplierSettlementsView — "ยอดค้างรับ", for a Company Partner.
 *
 * ── WHY A SUPPLIER GETS THIS SCREEN AT ALL ──
 *
 * Nobody asked for it. It is here because the alternative is a company that
 * can be paid by us and has no way of knowing what for — every question about
 * an amount becomes a telephone call to our accountant, and every
 * disagreement is unanswerable because only one side can see the workings.
 *
 * ── THE THREE NUMBERS ──
 *
 *   ยอดที่พร้อมจ่าย     released, not yet in a payout
 *   อยู่ในรอบจ่ายแล้ว    raised, waiting on our bank
 *   ยังไม่ถึงกำหนด      earned, trigger not yet fired
 *
 * The third is the one this screen exists for. "You owe me more than that" is
 * almost always money whose release condition — payment, redemption, or
 * delivery, depending on their own deal — has not happened yet, and showing it
 * turns an argument into a sentence.
 *
 * ── WHAT IS NOT HERE ──
 *
 * The commission we paid our members, and the GP we kept. Those are our side
 * of the arithmetic; the server does not send them to a supplier at all. A
 * supplier sees the sale price and what they receive — the difference is our
 * margin, which a supplier who agreed a percentage already knows.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { formatDateTime, formatMoney } from '@/composables/useClientFile'

interface Balance {
  payable_satang: number
  reserved_satang: number
  unreleased_satang: number
}

interface SettlementRow {
  id: number
  order_number: string | null
  product_name: string | null
  sale_price_satang: number
  amount_satang: number
  released_at: string | null
  payment_status: string
  created_at: string | null
}

interface PayoutRow {
  id: number
  status: string
  gross_satang: number
  wht_satang: number
  net_satang: number
  wht_certificate_no: string | null
  transferred_at: string | null
  transfer_reference: string | null
  rejection_reason: string | null
  created_at: string | null
}

const balance = ref<Balance | null>(null)
const rows = ref<SettlementRow[]>([])
const payouts = ref<PayoutRow[]>([])
const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const [b, s, p] = await Promise.all([
      api.get<{ data: Balance }>('/supplier/balance'),
      api.get<{ data: SettlementRow[] }>('/supplier/settlements'),
      api.get<{ data: PayoutRow[] }>('/supplier/payouts'),
    ])
    balance.value = b.data
    rows.value = s.data
    payouts.value = p.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError
      ? `โหลดข้อมูลไม่สำเร็จ (${e.status})`
      : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

onMounted(load)

const kpis = computed(() => [
  { label: 'พร้อมจ่าย', value: balance.value ? `฿${formatMoney(balance.value.payable_satang)}` : '—' },
  { label: 'อยู่ในรอบจ่าย', value: balance.value ? `฿${formatMoney(balance.value.reserved_satang)}` : '—' },
  { label: 'ยังไม่ถึงกำหนด', value: balance.value ? `฿${formatMoney(balance.value.unreleased_satang)}` : '—' },
])

function statusLabel(status: string): string {
  switch (status) {
    case 'pending_review': return 'รอตรวจสอบ'
    case 'approved': return 'รอโอน'
    case 'transferred': return 'โอนแล้ว'
    case 'rejected': return 'ปฏิเสธ'
    case 'cancelled': return 'ยกเลิก'
    default: return status
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="money"
      title="ยอดค้างรับ"
      subtitle="ยอดค่าสินค้าที่คุณจะได้รับ แยกตามคำสั่งซื้อ"
      :kpis="kpis"
      accent-color="brand"
      storage-key="supplier-settlements"
    />

    <p
      v-if="errorMessage"
      class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-300 text-sm text-rose-800"
      data-test="error"
    >{{ errorMessage }}</p>

    <!-- Says out loud what "ยังไม่ถึงกำหนด" means, because a figure with no
         explanation beside it is what starts the phone call this screen was
         built to prevent. -->
    <p
      v-if="balance && balance.unreleased_satang > 0"
      class="mt-4 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-sm text-amber-800"
      data-test="unreleased-note"
    >
      มียอด ฿{{ formatMoney(balance.unreleased_satang) }} ที่บันทึกไว้แล้วแต่ยังไม่ถึงกำหนดจ่ายตามเงื่อนไขในสัญญา
      (เช่น รอจัดส่ง หรือรอลูกค้าใช้สิทธิ์) — จะย้ายมาอยู่ในยอดพร้อมจ่ายเมื่อเงื่อนไขครบ
    </p>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" class="mt-4" />

    <template v-else>
      <section class="mt-6">
        <h2 class="text-sm font-bold text-slate-700 mb-2">รายการขาย</h2>

        <EmptyState v-if="rows.length === 0" icon="money" title="ยังไม่มีรายการขายสินค้าของคุณ" />

        <div v-else class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
          <table class="w-full text-sm border-collapse" data-test="settlements-table">
            <thead>
              <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
                <th class="px-4 py-3 whitespace-nowrap">วันที่</th>
                <th class="px-4 py-3 whitespace-nowrap">เลขที่คำสั่งซื้อ</th>
                <th class="px-4 py-3">สินค้า</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">ราคาขาย</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">ยอดที่คุณได้รับ</th>
                <th class="px-4 py-3 whitespace-nowrap">สถานะ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 last:border-0">
                <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                  {{ row.created_at ? formatDateTime(row.created_at) : '—' }}
                </td>
                <td class="px-4 py-3 font-bold text-slate-900 whitespace-nowrap">{{ row.order_number ?? '—' }}</td>
                <td class="px-4 py-3 text-slate-700">{{ row.product_name ?? '—' }}</td>
                <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(row.sale_price_satang) }}</td>
                <!-- Can be negative: where our commission and GP together
                     exceeded the sale price, the shortfall is carried by the
                     supplier and nets off against their other sales. Coloured,
                     rather than hidden, because a number that reduces a
                     statement has to be visible on it. -->
                <td
                  class="px-4 py-3 text-right font-bold whitespace-nowrap"
                  :class="row.amount_satang < 0 ? 'text-rose-600' : 'text-slate-900'"
                >฿{{ formatMoney(row.amount_satang) }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                  <span v-if="row.payment_status === 'paid'" class="text-emerald-700 text-xs font-bold">จ่ายแล้ว</span>
                  <span v-else-if="row.released_at" class="text-slate-600 text-xs">พร้อมจ่าย</span>
                  <span v-else class="text-amber-700 text-xs">ยังไม่ถึงกำหนด</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="payouts.length > 0" class="mt-8">
        <h2 class="text-sm font-bold text-slate-700 mb-2">ประวัติการรับเงิน</h2>

        <div class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
          <table class="w-full text-sm border-collapse" data-test="payouts-table">
            <thead>
              <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
                <th class="px-4 py-3 whitespace-nowrap">วันที่โอน</th>
                <th class="px-4 py-3 whitespace-nowrap">สถานะ</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">ยอดก่อนหักภาษี</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">หัก ณ ที่จ่าย</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">ยอดที่โอน</th>
                <th class="px-4 py-3">อ้างอิง</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in payouts" :key="p.id" class="border-b border-slate-100 last:border-0">
                <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                  {{ p.transferred_at ? formatDateTime(p.transferred_at) : '—' }}
                </td>
                <td class="px-4 py-3 text-slate-600 whitespace-nowrap">{{ statusLabel(p.status) }}</td>
                <!-- All three, always. A supplier sees `net` arrive in their
                     bank and has to tie it back to the `gross` on their
                     invoice; the withholding is the difference, and showing
                     one figure alone makes that impossible. -->
                <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(p.gross_satang) }}</td>
                <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(p.wht_satang) }}</td>
                <td class="px-4 py-3 text-right font-bold text-slate-900 whitespace-nowrap">฿{{ formatMoney(p.net_satang) }}</td>
                <td class="px-4 py-3 text-slate-600 text-xs">
                  {{ p.transfer_reference || '—' }}
                  <span v-if="p.wht_certificate_no" class="block text-slate-400">
                    หนังสือรับรอง: {{ p.wht_certificate_no }}
                  </span>
                  <span v-if="p.rejection_reason" class="block text-rose-600">{{ p.rejection_reason }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </main>
</template>
