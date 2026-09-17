<script setup lang="ts">
/**
 * SupplierManagementView — "จัดการคู่ค้า".
 *
 * ── WHY THIS SCREEN EXISTS, AND WHY IT IS NOT A PANEL ON จัดการบริษัท ──
 *
 * The supplier feature shipped with no way to create a supplier. The first fix
 * added a "ตั้งค่าคู่ค้า" panel to the company screen, and the owner rejected
 * it outright:
 *
 *   "Company Partner = Supplier ต้องแยกจาก Company เดิม แต่คุณเอา UI ไปใส่ที่
 *    company เดิมที่เป็นค่าคอม ผิดทั้งหมดเลย"
 *
 * He is describing a real distinction, not a preference about menus:
 *
 *   บริษัท (Company)  a TENANT. Sells through us. We pay COMMISSION to their
 *                     agents. Its screen is about commission plans and ranks.
 *   คู่ค้า (Supplier)  a COUNTERPARTY. Supplies goods we sell. We pay them the
 *                     sale price less commission less our GP. Its screen is
 *                     about GP, when money becomes payable, tax and a bank
 *                     account.
 *
 * Money flows in opposite directions and not one field means the same thing in
 * both. Editing the second on the first's screen teaches everybody the wrong
 * model of the business, which is the expensive part — the misplaced form is
 * only the symptom.
 *
 * ── WHAT THIS LIST SHOWS, AND WHY THE BALANCE IS ON IT ──
 *
 * The question this screen actually gets opened for is "who are we behind
 * with". Putting ยอดค้างจ่าย one click away per row means nobody looks, so it
 * is on the row — alongside the thing that BLOCKS a payment, spelled out
 * rather than implied by a greyed button.
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import SupplierForm from './supplier/SupplierForm.vue'
import { formatMoney } from '@/composables/useClientFile'
import {
  GP_MODE_LABELS,
  RELEASE_TRIGGER_LABELS,
  type SupplierFormValue,
  emptySupplier,
  fieldErrors,
  formatGp,
} from './supplier/supplierTerms'

interface SupplierRow extends SupplierFormValue {
  id: number
  products_count: number
  payable_satang: number
  unreleased_satang: number
  terms_complete: boolean
  missing_terms: string[]
}

const suppliers = ref<SupplierRow[]>([])
const loading = ref(true)
const errorMessage = ref('')

const search = ref('')
/** '' = ทั้งหมด. Tri-state, so the default really is "all" rather than "active". */
const activeFilter = ref<'' | '1' | '0'>('')

const creating = ref(false)
const saving = ref(false)
const draft = ref<SupplierFormValue>(emptySupplier())
const formErrors = ref<Record<string, string[]>>({})

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const params = new URLSearchParams()
    if (search.value.trim()) params.set('search', search.value.trim())
    if (activeFilter.value !== '') params.set('is_active', activeFilter.value)
    const qs = params.toString()
    const res = await api.get<{ data: SupplierRow[] }>(`/suppliers${qs ? `?${qs}` : ''}`)
    suppliers.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function startCreate(): void {
  draft.value = emptySupplier()
  formErrors.value = {}
  creating.value = true
}

async function saveNew(): Promise<void> {
  saving.value = true
  formErrors.value = {}
  errorMessage.value = ''
  try {
    await api.post('/suppliers', draft.value)
    creating.value = false
    await load()
  } catch (e) {
    // Field errors go back to the field. The whole point of the paired GP
    // rules is that the message names WHICH half is missing, and flattening
    // them into one banner throws that away.
    const fields = fieldErrors(e)
    if (fields) {
      formErrors.value = fields
    } else {
      errorMessage.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
    }
  } finally {
    saving.value = false
  }
}

/**
 * What is stopping us paying this supplier — the same sentences the payout
 * screen shows, because they are the same blockage and a person who sees two
 * different wordings for one problem assumes they are two problems.
 */
function blockedReason(row: SupplierRow): string | null {
  if (row.missing_terms.includes('gp')) return 'ยังไม่ได้ตั้ง GP'
  if (row.missing_terms.includes('release_trigger')) return 'ยังไม่ได้ตั้งจังหวะเบิก'
  if (row.missing_terms.includes('bank')) return 'ยังไม่ได้กรอกบัญชีรับเงิน'
  return null
}

const kpis = computed(() => [
  { label: 'คู่ค้าทั้งหมด', value: suppliers.value.length },
  { label: 'พร้อมจ่าย', value: suppliers.value.filter((s) => s.terms_complete).length },
  {
    label: 'ยอดค้างจ่ายรวม',
    value: `฿${formatMoney(suppliers.value.reduce((sum, s) => sum + s.payable_satang, 0))}`,
  },
])

onMounted(load)
</script>

<template>
  <div class="p-4 sm:p-6 lg:p-8">
    <HeroHeader
      icon="handshake"
      title="จัดการคู่ค้า"
      subtitle="บริษัทที่นำสินค้าเข้ามาขายในระบบเรา — คนละรายการกับบริษัทที่รับค่าแนะนำ"
      :kpis="kpis"
      storage-key="admin-suppliers"
    >
      <template #actions>
        <button
          type="button"
          data-test="add-supplier"
          class="min-h-[40px] px-4 inline-flex items-center gap-2 rounded-lg bg-brand-600 text-white text-sm font-bold hover:bg-brand-700 transition"
          @click="startCreate"
        >
          <Icon name="plus" :size="16" />
          เพิ่มคู่ค้า
        </button>
      </template>
    </HeroHeader>

    <p
      v-if="errorMessage"
      data-test="error"
      class="mt-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3"
    >{{ errorMessage }}</p>

    <!-- ── Create ────────────────────────────────────────────────────── -->
    <section
      v-if="creating"
      data-test="create-panel"
      class="mt-6 bg-white/95 border border-slate-200 rounded-xl p-4 sm:p-6"
    >
      <h2 class="text-sm font-bold text-slate-800 mb-4">เพิ่มคู่ค้าใหม่</h2>

      <SupplierForm v-model="draft" :errors="formErrors" />

      <div class="mt-5 flex items-center gap-2">
        <button
          type="button"
          data-test="save-supplier"
          :disabled="saving"
          class="min-h-[40px] px-4 rounded-lg bg-brand-600 text-white text-sm font-bold hover:bg-brand-700 disabled:opacity-60 transition"
          @click="saveNew"
        >{{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
        <button
          type="button"
          class="min-h-[40px] px-4 rounded-lg border border-slate-300 text-slate-600 text-sm font-bold hover:bg-slate-50 transition"
          @click="creating = false"
        >ยกเลิก</button>
        <!-- Said out loud because the form looks incomplete when it is
             perfectly savable, and somebody who does not know that keeps the
             deal in a notebook until every field is agreed. -->
        <span class="text-xs text-slate-500">กรอกเฉพาะที่ตกลงกันแล้วก็บันทึกได้ — เงื่อนไขที่เหลือมาเติมทีหลังได้</span>
      </div>
    </section>

    <!-- ── Filters ───────────────────────────────────────────────────── -->
    <div class="mt-6 flex flex-wrap items-center gap-2">
      <input
        v-model="search"
        type="search"
        data-test="search"
        placeholder="ค้นหาชื่อ / ชื่อนิติบุคคล / ผู้ติดต่อ / เลขผู้เสียภาษี"
        class="min-h-[40px] flex-1 min-w-[240px] px-3 rounded-lg border border-slate-300 text-sm"
        @keyup.enter="load"
      />
      <select
        v-model="activeFilter"
        data-test="active-filter"
        class="min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm"
        @change="load"
      >
        <option value="">ทั้งหมด</option>
        <option value="1">ใช้งานอยู่</option>
        <option value="0">ปิดใช้งาน</option>
      </select>
      <button
        type="button"
        class="min-h-[40px] px-4 rounded-lg border border-slate-300 text-slate-600 text-sm font-bold hover:bg-slate-50 transition"
        @click="load"
      >ค้นหา</button>
    </div>

    <!-- ── List ──────────────────────────────────────────────────────── -->
    <section class="mt-4">
      <LoadingSkeleton v-if="loading" :rows="4" />

      <EmptyState
        v-else-if="suppliers.length === 0"
        icon="handshake"
        title="ยังไม่มีคู่ค้า"
        message="กด “เพิ่มคู่ค้า” เพื่อบันทึกบริษัทที่นำสินค้ามาให้เราขาย จากนั้นจึงผูกสินค้าและสร้างบัญชีผู้ใช้ให้เขา"
      />

      <div v-else class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm border-collapse" data-test="suppliers-table">
          <thead>
            <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
              <th class="px-4 py-3">คู่ค้า</th>
              <th class="px-4 py-3">GP</th>
              <th class="px-4 py-3">จังหวะเบิก</th>
              <th class="px-4 py-3 text-right">สินค้า</th>
              <th class="px-4 py-3 text-right whitespace-nowrap">ยอดค้างจ่าย</th>
              <th class="px-4 py-3">สถานะการตั้งค่า</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in suppliers"
              :key="row.id"
              class="border-b border-slate-100 last:border-0 hover:bg-slate-50/70 align-top"
            >
              <td class="px-4 py-3">
                <RouterLink
                  :to="{ name: 'supplier-detail', params: { id: row.id } }"
                  class="font-bold text-brand-700 hover:underline"
                  data-test="supplier-link"
                >{{ row.name }}</RouterLink>
                <span
                  v-if="!row.is_active"
                  class="ml-2 align-middle rounded px-1.5 py-0.5 bg-slate-100 text-slate-500 text-[10px] font-bold"
                >ปิดใช้งาน</span>
                <div v-if="row.contact_name" class="text-xs text-slate-500 mt-0.5">{{ row.contact_name }}</div>
              </td>
              <td class="px-4 py-3 text-slate-700 whitespace-nowrap">
                <template v-if="row.gp_mode && row.gp_value !== null">
                  {{ formatGp(row.gp_mode, row.gp_value) }}
                  <div class="text-xs text-slate-400">{{ GP_MODE_LABELS[row.gp_mode] }}</div>
                </template>
                <span v-else class="text-rose-600 text-xs">ยังไม่ได้ตั้ง</span>
              </td>
              <td class="px-4 py-3 text-slate-700 text-xs">
                {{ row.release_trigger ? RELEASE_TRIGGER_LABELS[row.release_trigger] : '—' }}
              </td>
              <td class="px-4 py-3 text-right text-slate-600">{{ row.products_count }}</td>
              <td
                class="px-4 py-3 text-right font-bold whitespace-nowrap"
                :class="row.payable_satang < 0 ? 'text-rose-600' : 'text-slate-900'"
              >฿{{ formatMoney(row.payable_satang) }}</td>
              <td class="px-4 py-3 text-xs">
                <span v-if="blockedReason(row)" data-test="blocked-reason" class="text-amber-700">
                  {{ blockedReason(row) }}
                </span>
                <span v-else class="text-emerald-700">พร้อมจ่าย</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
