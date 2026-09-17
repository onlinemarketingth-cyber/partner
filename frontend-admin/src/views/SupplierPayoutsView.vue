<script setup lang="ts">
/**
 * SupplierPayoutsView — "จ่ายคืนคู่ค้า".
 *
 * Owner, 2026-09-16: "บัญชีเราจะต้องทำคืนยอดให้กับ Company Partner แบบเดียวกับ
 * Agent แต่รายละเอียดจะไม่ใช่ค่าคอม จะเป็นค่าสินค้าหัก GP".
 *
 * ── THE SAME SHAPE AS จ่ายค่าแนะนำ, ON PURPOSE ──
 *
 * One row per SUPPLIER COMPANY rather than per member, but the same three
 * steps and the same rule underneath: ตั้งจ่าย → รอฝ่ายบัญชีโอน → โอนแล้ว, and
 * the ledger settles only when somebody records that the money actually left
 * the bank (แนวทาง C). An approval is a decision; a transfer is an event; the
 * books follow the event.
 *
 * ── THE THREE NUMBERS, AND WHY NOT ONE ──
 *
 * A supplier looking at a statement asks three different questions and one
 * figure answers none of them well:
 *
 *   ตั้งจ่ายได้      released, unreserved — what can go out today
 *   รอฝ่ายบัญชีโอน   already raised, waiting on the bank
 *   ยังไม่ถึงกำหนด    earned, but the deal's trigger has not fired
 *
 * The third is the one that starts phone calls ("you owe me more than that").
 * Showing it means the screen answers instead of the accountant.
 *
 * ── AND THE OTHER THREE: gross / ภาษี / net ──
 *
 * This is the first place in this system that withholds tax at all. Accounting
 * transfers `net`, reconciles against `gross`, and issues a certificate for
 * the difference — so all three are on screen and in the CSV. A row showing
 * only one of them cannot be reconciled against a bank statement.
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { formatDateTime, formatMoney } from '@/composables/useClientFile'

interface SupplierRow {
  supplier_id: number
  supplier_name: string
  is_active: boolean
  bank_name: string | null
  bank_account_number: string | null
  bank_account_holder_name: string | null
  /**
   * Whether this supplier's deal has GP and a release trigger set.
   *
   * Sent by the server so the screen can say WHY a supplier cannot be paid
   * rather than merely not offering a button. "GP ยังไม่ได้ตั้ง" is something
   * somebody can go and fix; a greyed button with no explanation is a support
   * ticket.
   */
  terms_complete: boolean
  /** Which parts are blank: 'gp' | 'release_trigger' | 'bank'. */
  missing_terms: string[]
  payable_satang: number
  reserved_satang: number
  unreleased_satang: number
}

interface PayoutRequest {
  id: number
  supplier_id: number
  supplier_name: string | null
  status: string
  source: string
  gross_satang: number
  wht_rate_at_time: number | null
  wht_satang: number
  net_satang: number
  wht_certificate_no: string | null
  bank_name: string | null
  bank_account_number: string | null
  bank_account_holder_name: string | null
  transferred_at: string | null
  transfer_reference: string | null
  created_at: string | null
}

const suppliers = ref<SupplierRow[]>([])
const requests = ref<PayoutRequest[]>([])
const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const raisingId = ref<number | null>(null)

/** The two halves are independent — a failure in one must not blank the other. */
async function loadAll(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const [balances, queue] = await Promise.all([
      api.get<{ data: SupplierRow[] }>('/supplier-payouts'),
      api.get<{ data: PayoutRequest[] }>('/supplier-payouts/requests'),
    ])
    suppliers.value = balances.data
    requests.value = queue.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError
      ? `โหลดข้อมูลไม่สำเร็จ (${e.status})`
      : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

onMounted(loadAll)

/**
 * A supplier can be paid when there is something released AND it nets
 * positive.
 *
 * The second half is not a formality. A supplier's balance is the NET of
 * sales and shortfalls — where commission plus our GP exceeded the sale price,
 * the owner ruled the supplier carries the difference — so a balance can be
 * zero or below with plenty of rows in it. Offering the button there produces
 * a 422 and no explanation.
 */
function canRaise(row: SupplierRow): boolean {
  return row.terms_complete && !row.missing_terms.includes('bank') && row.payable_satang > 0
}

/**
 * Why this supplier cannot be paid today, in words somebody can act on.
 *
 * Ordered most-actionable first, and the bank line is separate from the terms
 * line on purpose: a balance is correct and worth showing without a bank
 * account, but accounting cannot transfer without one, and finding that out
 * after raising the payout is finding out too late.
 */
function blockedReason(row: SupplierRow): string | null {
  if (!row.terms_complete) return 'ยังไม่ได้ตั้งเงื่อนไข GP หรือจังหวะการเบิก — ตั้งค่าที่หน้าจัดการคู่ค้า'
  if (row.missing_terms.includes('bank')) return 'ยังไม่ได้กรอกบัญชีรับเงิน — กรอกที่หน้าจัดการคู่ค้า'
  if (row.payable_satang < 0) return 'ยอดคงเหลือติดลบ — จะหักกลบกับยอดขายรอบถัดไป'
  return null
}

async function raisePayout(row: SupplierRow): Promise<void> {
  raisingId.value = row.supplier_id
  errorMessage.value = ''
  try {
    await api.post('/supplier-payouts', { supplier_id: row.supplier_id })
    await loadAll()
  } catch (e) {
    // Shown in full: the server's refusal names the actual problem (terms
    // missing, nothing released, net negative) and flattening it throws away
    // the only sentence that says what to do.
    errorMessage.value = e instanceof ApiError ? `ตั้งจ่ายไม่สำเร็จ: ${e.message}` : 'ตั้งจ่ายไม่สำเร็จ'
  } finally {
    raisingId.value = null
  }
}

/* ── Recording the transfer ─────────────────────────────────────────────── */

const transferTarget = ref<PayoutRequest | null>(null)
const transferReference = ref('')
const certificateNo = ref('')
const transferring = ref(false)

function openTransfer(request: PayoutRequest): void {
  transferTarget.value = request
  transferReference.value = ''
  certificateNo.value = ''
}

async function confirmTransfer(): Promise<void> {
  if (!transferTarget.value) return
  transferring.value = true
  errorMessage.value = ''
  try {
    await api.post(`/supplier-payouts/${transferTarget.value.id}/mark-transferred`, {
      transfer_reference: transferReference.value.trim() || null,
      wht_certificate_no: certificateNo.value.trim() || null,
    })
    transferTarget.value = null
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError
      ? `บันทึกการโอนไม่สำเร็จ: ${e.message}`
      : 'บันทึกการโอนไม่สำเร็จ'
  } finally {
    transferring.value = false
  }
}

const awaitingTransfer = computed(() => requests.value.filter((r) => r.status === 'approved'))
const history = computed(() => requests.value.filter((r) => r.status !== 'approved'))

const kpis = computed(() => [
  { label: 'คู่ค้าที่ตั้งจ่ายได้', value: suppliers.value.filter(canRaise).length },
  { label: 'รอฝ่ายบัญชีโอน', value: awaitingTransfer.value.length },
])

function statusLabel(status: string): string {
  switch (status) {
    case 'pending_review': return 'รอตรวจสอบ'
    case 'approved': return 'รอฝ่ายบัญชีโอน'
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
      title="จ่ายคืนคู่ค้า"
      subtitle="ยอดค่าสินค้าหลังหักค่าแนะนำและ GP ที่ต้องคืนให้คู่ค้า"
      :kpis="kpis"
      accent-color="brand"
      storage-key="admin-supplier-payouts"
    />

    <p
      v-if="errorMessage"
      class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-300 text-sm text-rose-800"
      data-test="error"
    >{{ errorMessage }}</p>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" class="mt-4" />

    <!-- ── Step 1: who can be paid ─────────────────────────────────────── -->
    <section v-else class="mt-6">
      <h2 class="text-sm font-bold text-slate-700 mb-2">ยอดคงเหลือของคู่ค้า</h2>

      <EmptyState v-if="suppliers.length === 0" icon="money" title="ยังไม่มีคู่ค้าในระบบ" message="เพิ่มคู่ค้าที่หน้า ตั้งค่าระบบ → จัดการคู่ค้า ก่อน จึงจะมียอดให้จ่ายคืน" />

      <div v-else class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm border-collapse" data-test="suppliers-table">
          <thead>
            <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
              <th class="px-4 py-3">คู่ค้า</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">ตั้งจ่ายได้</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">รอฝ่ายบัญชีโอน</th>
              <!-- The figure that answers "you owe me more than that" before
                   anybody has to pick up a telephone. -->
              <th class="px-4 py-3 text-right whitespace-nowrap">ยังไม่ถึงกำหนด</th>
              <th class="px-4 py-3">บัญชีธนาคาร</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in suppliers"
              :key="row.supplier_id"
              class="border-b border-slate-100 last:border-0 hover:bg-slate-50/70 align-top"
            >
              <td class="px-4 py-3 font-bold text-slate-900">
                <!-- Into the supplier's own page: this screen says WHAT we owe,
                     that one says who they are and on what terms. -->
                <RouterLink
                  :to="{ name: 'supplier-detail', params: { id: row.supplier_id } }"
                  class="text-brand-700 hover:underline"
                  data-test="supplier-link"
                >{{ row.supplier_name }}</RouterLink>
                <!-- A deal that has ENDED still has a balance, and this screen
                     deliberately keeps listing it — the marker is so nobody
                     reads an inactive supplier as a current one. -->
                <span
                  v-if="!row.is_active"
                  class="ml-2 align-middle rounded px-1.5 py-0.5 bg-slate-100 text-slate-500 text-[10px] font-bold"
                >ปิดใช้งาน</span>
              </td>
              <td
                class="px-4 py-3 text-right font-bold whitespace-nowrap"
                :class="row.payable_satang < 0 ? 'text-rose-600' : 'text-slate-900'"
              >฿{{ formatMoney(row.payable_satang) }}</td>
              <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(row.reserved_satang) }}</td>
              <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(row.unreleased_satang) }}</td>
              <td class="px-4 py-3 text-slate-600 text-xs">
                <template v-if="row.bank_account_number">
                  {{ row.bank_name }}<br />{{ row.bank_account_number }}
                </template>
                <span v-else class="text-rose-600">ยังไม่ได้กรอกบัญชีธนาคาร</span>
              </td>
              <td class="px-4 py-3 whitespace-nowrap text-right">
                <button
                  v-if="canRaise(row)"
                  type="button"
                  data-test="raise-payout"
                  :disabled="raisingId !== null"
                  class="min-h-[36px] px-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 disabled:opacity-60 transition"
                  @click="raisePayout(row)"
                >
                  <Icon name="check" :size="14" />
                  {{ raisingId === row.supplier_id ? 'กำลังตั้งจ่าย...' : 'ตั้งจ่าย' }}
                </button>
                <!-- Says WHY rather than showing a dead button. -->
                <span v-else-if="blockedReason(row)" class="text-xs text-amber-700">{{ blockedReason(row) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ── Step 2: waiting on the bank ─────────────────────────────────── -->
    <section v-if="awaitingTransfer.length > 0" class="mt-8">
      <h2 class="text-sm font-bold text-slate-700 mb-2">รอฝ่ายบัญชีโอน</h2>

      <div class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm border-collapse" data-test="awaiting-table">
          <thead>
            <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
              <th class="px-4 py-3">คู่ค้า</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">ยอดก่อนหักภาษี</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">หัก ณ ที่จ่าย</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">ยอดโอนจริง</th>
              <th class="px-4 py-3">บัญชีปลายทาง</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="r in awaitingTransfer" :key="r.id">
            <tr class="border-b border-slate-100 last:border-0 align-top">
              <td class="px-4 py-3 font-bold text-slate-900">{{ r.supplier_name }}</td>
              <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(r.gross_satang) }}</td>
              <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">
                ฿{{ formatMoney(r.wht_satang) }}
                <span v-if="r.wht_rate_at_time !== null" class="text-[11px] text-slate-400">
                  ({{ (r.wht_rate_at_time / 100).toFixed(2) }}%)
                </span>
                <!-- Null rate means SEVERAL rates applied, never "no tax" —
                     goods and services in one payout are withheld differently
                     and there is no single figure to print. -->
                <span v-else-if="r.wht_satang > 0" class="text-[11px] text-slate-400">(หลายอัตรา)</span>
              </td>
              <!-- The number accounting actually types into the bank. -->
              <td class="px-4 py-3 text-right font-bold text-slate-900 whitespace-nowrap">฿{{ formatMoney(r.net_satang) }}</td>
              <td class="px-4 py-3 text-slate-600 text-xs">
                {{ r.bank_name }}<br />{{ r.bank_account_number }}<br />{{ r.bank_account_holder_name }}
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <button
                  v-if="transferTarget?.id !== r.id"
                  type="button"
                  data-test="mark-transferred"
                  class="min-h-[36px] px-3 inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700 transition"
                  @click="openTransfer(r)"
                >
                  <Icon name="check" :size="14" /> บันทึกว่าโอนแล้ว
                </button>
              </td>
            </tr>

            <!--
              THE CONFIRMATION IS AN INLINE ROW, NOT A MODAL.

              Recording a transfer settles the ledger rows behind it and cannot
              be undone, so it asks — but the two things the person types (the
              bank reference and the certificate number) are both copied off
              paperwork sitting beside the figures in this row. A modal would
              cover the row they are reading from.
            -->
            <tr v-if="transferTarget?.id === r.id" class="border-b border-slate-100">
              <td colspan="6" class="px-4 pb-4">
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200" data-test="transfer-panel">
                  <p class="text-sm text-emerald-900 font-bold">
                    ยืนยันว่าโอนให้ {{ r.supplier_name }} จำนวน ฿{{ formatMoney(r.net_satang) }} แล้ว
                  </p>
                  <p class="text-xs text-emerald-800 mt-0.5">
                    ยอดก่อนหักภาษี ฿{{ formatMoney(r.gross_satang) }} · หัก ณ ที่จ่าย ฿{{ formatMoney(r.wht_satang) }}
                  </p>

                  <div class="grid gap-3 sm:grid-cols-2 mt-3">
                    <label class="block">
                      <span class="text-xs font-bold text-slate-600">เลขที่อ้างอิงการโอน (ถ้ามี)</span>
                      <input
                        v-model="transferReference"
                        type="text"
                        data-test="transfer-reference"
                        class="mt-1 w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm"
                      />
                    </label>
                    <label class="block">
                      <span class="text-xs font-bold text-slate-600">เลขที่หนังสือรับรองหัก ณ ที่จ่าย (ถ้ามี)</span>
                      <input
                        v-model="certificateNo"
                        type="text"
                        data-test="wht-certificate"
                        class="mt-1 w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm"
                      />
                    </label>
                  </div>

                  <div class="flex items-center gap-2 mt-3">
                    <button
                      type="button"
                      data-test="confirm-transfer"
                      :disabled="transferring"
                      class="min-h-[38px] px-4 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700 disabled:opacity-60 transition"
                      @click="confirmTransfer"
                    >{{ transferring ? 'กำลังบันทึก...' : 'ยืนยันว่าโอนแล้ว' }}</button>
                    <button
                      type="button"
                      data-test="cancel-transfer"
                      class="min-h-[38px] px-4 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-white transition"
                      @click="transferTarget = null"
                    >ยกเลิก</button>
                  </div>
                </div>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ── History ─────────────────────────────────────────────────────── -->
    <section v-if="history.length > 0" class="mt-8">
      <h2 class="text-sm font-bold text-slate-700 mb-2">ประวัติการจ่าย</h2>

      <div class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm border-collapse" data-test="history-table">
          <thead>
            <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
              <th class="px-4 py-3">วันที่โอน</th>
              <th class="px-4 py-3">คู่ค้า</th>
              <th class="px-4 py-3">สถานะ</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">ก่อนหักภาษี</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">หัก ณ ที่จ่าย</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">โอนจริง</th>
              <th class="px-4 py-3">อ้างอิง</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in history" :key="r.id" class="border-b border-slate-100 last:border-0">
              <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                {{ r.transferred_at ? formatDateTime(r.transferred_at) : '—' }}
              </td>
              <td class="px-4 py-3 font-bold text-slate-900">{{ r.supplier_name }}</td>
              <td class="px-4 py-3 text-slate-600">{{ statusLabel(r.status) }}</td>
              <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(r.gross_satang) }}</td>
              <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(r.wht_satang) }}</td>
              <td class="px-4 py-3 text-right font-bold text-slate-900 whitespace-nowrap">฿{{ formatMoney(r.net_satang) }}</td>
              <td class="px-4 py-3 text-slate-600 text-xs">
                {{ r.transfer_reference || '—' }}
                <span v-if="r.wht_certificate_no" class="block text-slate-400">
                  หนังสือรับรอง: {{ r.wht_certificate_no }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</template>
