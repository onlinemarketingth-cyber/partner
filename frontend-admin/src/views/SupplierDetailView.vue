<script setup lang="ts">
/**
 * SupplierDetailView — one supplier, four tabs.
 *
 *   ภาพรวม        the deal itself, editable in place, plus what we owe
 *   สินค้า         what they bring in, and the GP that actually applies to each
 *   รายการตั้งหนี้  every sale and what it left us owing
 *   บัญชีผู้ใช้     their logins, created from here
 *
 * ── WHY THE ACCOUNTS TAB IS ON THIS PAGE ──
 *
 * The original setup path was: จัดการบริษัท → flip a switch → จัดการผู้ใช้ →
 * pick a role → hope the company was flagged. Three screens, in an order
 * nobody could guess, with the failure ("บริษัทนี้ยังไม่ได้ตั้งเป็นคู่ค้า")
 * arriving after a password had been typed.
 *
 * Creating the login where the supplier is means the person who just set up
 * the deal can finish the job without knowing that `users` and `suppliers` are
 * separate tables. The account is still created by POST /users — same policy,
 * same password rules, same audit row — because a second creation path would
 * be a second place for all three to drift.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ──
 *
 * No "pay this supplier" button. Raising a payout lives on จ่ายคืนคู่ค้า with
 * the rest of the queue, because paying is a batch errand done against a list
 * and not something to fire from whichever supplier page happens to be open.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import SupplierForm from './supplier/SupplierForm.vue'
import { formatDateTime, formatMoney } from '@/composables/useClientFile'
import {
  type SupplierFormValue,
  emptySupplier,
  fieldErrors,
  formatGp,
  formatWht,
} from './supplier/supplierTerms'

interface SupplierDetail extends SupplierFormValue {
  id: number
  terms_complete: boolean
  missing_terms: string[]
  payable_satang: number
  reserved_satang: number
  unreleased_satang: number
  paid_satang: number
}

interface SupplierProduct {
  id: number
  name: string
  is_active: boolean
  price_satang: number
  gp_mode: string | null
  gp_value: number | null
  gp_is_override: boolean
  wht_rate: number | null
  wht_is_override: boolean
}

interface SettlementRow {
  id: number
  order_number: string | null
  product_name: string | null
  sold_by_company: string | null
  sale_price_satang: number
  commission_satang: number
  gp_satang: number
  amount_satang: number
  wht_rate: number | null
  released_at: string | null
  payment_status: string
}

interface SupplierUser {
  id: number
  name: string
  email: string
  phone: string | null
  role: string
  is_partner_role: boolean
  created_at: string | null
}

const route = useRoute()
const supplierId = computed(() => Number(route.params.id))

type Tab = 'overview' | 'products' | 'settlements' | 'users'
const tab = ref<Tab>('overview')
const TABS: { key: Tab; label: string }[] = [
  { key: 'overview', label: 'ภาพรวม' },
  { key: 'products', label: 'สินค้า' },
  { key: 'settlements', label: 'รายการตั้งหนี้' },
  { key: 'users', label: 'บัญชีผู้ใช้' },
]

const supplier = ref<SupplierDetail | null>(null)
const draft = ref<SupplierFormValue>(emptySupplier())
const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const savedNotice = ref('')
const formErrors = ref<Record<string, string[]>>({})

const products = ref<SupplierProduct[]>([])
const settlements = ref<SettlementRow[]>([])
const users = ref<SupplierUser[]>([])
const tabLoading = ref(false)

/** The create-a-login form on the accounts tab. */
const newUser = ref({ first_name: '', last_name: '', email: '', phone: '', password: '' })
const creatingUser = ref(false)
const userErrors = ref<Record<string, string[]>>({})
const userNotice = ref('')

/**
 * Strip the read-only figures before editing.
 *
 * The detail payload carries balances alongside the deal terms; sending them
 * back on a PUT would be rejected field by field, and quietly dropping them in
 * the component is how a form ends up saving a shape nobody meant.
 */
function toFormValue(row: SupplierDetail): SupplierFormValue {
  return {
    name: row.name,
    legal_name: row.legal_name,
    tax_id: row.tax_id,
    contact_name: row.contact_name,
    contact_phone: row.contact_phone,
    contact_email: row.contact_email,
    address: row.address,
    is_active: row.is_active,
    gp_mode: row.gp_mode,
    gp_value: row.gp_value,
    release_trigger: row.release_trigger,
    min_withdrawal_satang: row.min_withdrawal_satang,
    wht_rate: row.wht_rate,
    payout_bank_name: row.payout_bank_name,
    payout_bank_account_number: row.payout_bank_account_number,
    payout_bank_account_name: row.payout_bank_account_name,
  }
}

async function loadSupplier(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: SupplierDetail }>(`/suppliers/${supplierId.value}`)
    supplier.value = res.data
    draft.value = toFormValue(res.data)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลคู่ค้าไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true
  formErrors.value = {}
  errorMessage.value = ''
  savedNotice.value = ''
  try {
    const res = await api.put<{ data: SupplierDetail }>(`/suppliers/${supplierId.value}`, draft.value)
    // Re-seeded from the response rather than left as typed: the server is
    // what decides what was stored, and a form that keeps showing the typed
    // value after a partial save is a form that lies.
    supplier.value = { ...supplier.value, ...res.data } as SupplierDetail
    draft.value = toFormValue(res.data)
    savedNotice.value = 'บันทึกแล้ว'
  } catch (e) {
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

async function loadTab(which: Tab): Promise<void> {
  if (which === 'overview') return

  tabLoading.value = true
  errorMessage.value = ''
  try {
    if (which === 'products') {
      products.value = (await api.get<{ data: SupplierProduct[] }>(`/suppliers/${supplierId.value}/products`)).data
    } else if (which === 'settlements') {
      settlements.value = (await api.get<{ data: SettlementRow[] }>(`/supplier-payouts/${supplierId.value}/settlements`)).data
    } else {
      users.value = (await api.get<{ data: SupplierUser[] }>(`/suppliers/${supplierId.value}/users`)).data
    }
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    tabLoading.value = false
  }
}

async function createUser(): Promise<void> {
  creatingUser.value = true
  userErrors.value = {}
  userNotice.value = ''
  try {
    await api.post('/users', {
      supplier_id: supplierId.value,
      role: 'company_partner',
      first_name: newUser.value.first_name,
      last_name: newUser.value.last_name,
      email: newUser.value.email,
      phone: newUser.value.phone || undefined,
      password: newUser.value.password,
    })
    /*
     * The password is cleared and NEVER echoed back. Whoever created the
     * account has it on screen once, gives it to the supplier out of band and
     * that is the end of it — the same handling every other account creation
     * in this console uses.
     */
    newUser.value = { first_name: '', last_name: '', email: '', phone: '', password: '' }
    userNotice.value = 'สร้างบัญชีแล้ว — แจ้งรหัสผ่านให้คู่ค้าทราบ แล้วให้เปลี่ยนรหัสเมื่อเข้าใช้ครั้งแรก'
    await loadTab('users')
  } catch (e) {
    const fields = fieldErrors(e)
    if (fields) {
      userErrors.value = fields
    } else {
      // Onto the email field rather than into a banner: it is the field that
      // fails most often here (already taken) and the one somebody will fix.
      userErrors.value = { email: [e instanceof ApiError ? e.message : 'สร้างบัญชีไม่สำเร็จ'] }
    }
  } finally {
    creatingUser.value = false
  }
}

function userError(field: string): string | null {
  return userErrors.value[field]?.[0] ?? null
}

function statusLabel(status: string): string {
  switch (status) {
    case 'pending': return 'ยังไม่จ่าย'
    case 'paid': return 'จ่ายแล้ว'
    default: return status
  }
}

const kpis = computed(() => {
  const s = supplier.value
  if (!s) return []

  return [
    { label: 'ตั้งจ่ายได้', value: `฿${formatMoney(s.payable_satang)}` },
    { label: 'รอฝ่ายบัญชีโอน', value: `฿${formatMoney(s.reserved_satang)}` },
    { label: 'ยังไม่ถึงกำหนด', value: `฿${formatMoney(s.unreleased_satang)}` },
    { label: 'จ่ายไปแล้วสะสม', value: `฿${formatMoney(s.paid_satang)}` },
  ]
})

watch(tab, (t) => void loadTab(t))
onMounted(async () => {
  await loadSupplier()
})

const inputClass = 'min-h-[40px] w-full px-3 rounded-lg border border-slate-300 text-sm focus:border-brand-500 focus:outline-none'
</script>

<template>
  <div class="p-4 sm:p-6 lg:p-8">
    <HeroHeader
      icon="handshake"
      :title="supplier?.name ?? 'คู่ค้า'"
      :subtitle="supplier?.legal_name ?? ''"
      :kpis="kpis"
      back-page="supplier-management"
      back-label="จัดการคู่ค้า"
      storage-key="admin-supplier-detail"
    />

    <p
      v-if="errorMessage"
      data-test="error"
      class="mt-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3"
    >{{ errorMessage }}</p>

    <LoadingSkeleton v-if="loading" class="mt-6" :rows="4" />

    <template v-else-if="supplier">
      <!-- The one thing worth interrupting for: a deal that cannot be paid. -->
      <p
        v-if="!supplier.terms_complete"
        data-test="incomplete-banner"
        class="mt-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3"
      >
        เงื่อนไขดีลยังไม่ครบ — ยอดยังตั้งจ่ายไม่ได้จนกว่าจะกรอก GP และจังหวะเบิกที่แท็บภาพรวม
      </p>

      <!-- ── Tabs ─────────────────────────────────────────────────────── -->
      <nav class="mt-6 flex gap-1 border-b border-slate-200" data-test="supplier-tabs">
        <button
          v-for="t in TABS"
          :key="t.key"
          type="button"
          :data-test="`tab-${t.key}`"
          class="px-4 py-2 text-sm font-bold border-b-2 -mb-px transition"
          :class="tab === t.key
            ? 'border-brand-600 text-brand-700'
            : 'border-transparent text-slate-500 hover:text-slate-700'"
          @click="tab = t.key"
        >{{ t.label }}</button>
      </nav>

      <!-- ── ภาพรวม ───────────────────────────────────────────────────── -->
      <section v-if="tab === 'overview'" class="mt-6 bg-white/95 border border-slate-200 rounded-xl p-4 sm:p-6">
        <SupplierForm v-model="draft" :errors="formErrors" />

        <div class="mt-5 flex items-center gap-3">
          <button
            type="button"
            data-test="save-supplier"
            :disabled="saving"
            class="min-h-[40px] px-4 rounded-lg bg-brand-600 text-white text-sm font-bold hover:bg-brand-700 disabled:opacity-60 transition"
            @click="save"
          >{{ saving ? 'กำลังบันทึก...' : 'บันทึกการแก้ไข' }}</button>
          <span v-if="savedNotice" data-test="saved-notice" class="text-sm text-emerald-700">{{ savedNotice }}</span>
        </div>
      </section>

      <!-- ── สินค้า ───────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'products'" class="mt-6">
        <LoadingSkeleton v-if="tabLoading" :rows="3" />
        <EmptyState
          v-else-if="products.length === 0"
          icon="cube"
          title="ยังไม่มีสินค้าของคู่ค้ารายนี้"
          message="ผูกสินค้ากับคู่ค้าได้ที่หน้าแก้ไขสินค้า — เลือกได้เฉพาะสินค้าของแพลตฟอร์มเท่านั้น"
        />
        <div v-else class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
          <table class="w-full text-sm border-collapse" data-test="products-table">
            <thead>
              <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
                <th class="px-4 py-3">สินค้า</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">ราคาขาย</th>
                <th class="px-4 py-3">GP ที่ใช้จริง</th>
                <th class="px-4 py-3">หัก ณ ที่จ่าย</th>
                <th class="px-4 py-3">สถานะ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in products" :key="p.id" class="border-b border-slate-100 last:border-0">
                <td class="px-4 py-3 font-bold text-slate-900">{{ p.name }}</td>
                <td class="px-4 py-3 text-right whitespace-nowrap">฿{{ formatMoney(p.price_satang) }}</td>
                <td class="px-4 py-3">
                  <template v-if="p.gp_mode && p.gp_value !== null">
                    {{ formatGp(p.gp_mode as never, p.gp_value) }}
                    <!-- Whether this came from the deal or from the product is
                         the question this tab exists to answer — a figure with
                         no provenance sends somebody to the wrong screen to
                         change it. -->
                    <span
                      class="ml-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold"
                      :class="p.gp_is_override ? 'bg-violet-50 text-violet-700' : 'bg-slate-100 text-slate-500'"
                    >{{ p.gp_is_override ? 'ตั้งเฉพาะสินค้านี้' : 'ตามดีล' }}</span>
                  </template>
                  <span v-else class="text-rose-600 text-xs">ยังไม่ได้ตั้ง</span>
                </td>
                <td class="px-4 py-3 text-slate-600">
                  {{ formatWht(p.wht_rate) }}
                  <span v-if="p.wht_is_override" class="ml-1 text-[10px] text-violet-700 font-bold">เฉพาะสินค้านี้</span>
                </td>
                <td class="px-4 py-3 text-xs">
                  <span :class="p.is_active ? 'text-emerald-700' : 'text-slate-400'">
                    {{ p.is_active ? 'เปิดขาย' : 'ปิดขาย' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── รายการตั้งหนี้ ────────────────────────────────────────────── -->
      <section v-else-if="tab === 'settlements'" class="mt-6">
        <LoadingSkeleton v-if="tabLoading" :rows="3" />
        <EmptyState
          v-else-if="settlements.length === 0"
          icon="invoice"
          title="ยังไม่มีรายการตั้งหนี้"
          message="รายการจะขึ้นเมื่อมีลูกค้าซื้อสินค้าของคู่ค้ารายนี้และชำระเงินแล้ว"
        />
        <div v-else class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
          <table class="w-full text-sm border-collapse" data-test="settlements-table">
            <thead>
              <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
                <th class="px-4 py-3">คำสั่งซื้อ</th>
                <th class="px-4 py-3">สินค้า</th>
                <th class="px-4 py-3">ขายโดย</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">ราคาขาย</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">ค่าแนะนำ</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">GP ของเรา</th>
                <th class="px-4 py-3 text-right whitespace-nowrap">ยอดคู่ค้า</th>
                <th class="px-4 py-3">สถานะ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in settlements" :key="r.id" class="border-b border-slate-100 last:border-0">
                <td class="px-4 py-3 text-slate-700">{{ r.order_number ?? '—' }}</td>
                <td class="px-4 py-3 text-slate-700">{{ r.product_name ?? '—' }}</td>
                <!-- Which of OUR companies sold it. A supplier statement that
                     cannot say this is unauditable. -->
                <td class="px-4 py-3 text-slate-600 text-xs">{{ r.sold_by_company ?? '—' }}</td>
                <td class="px-4 py-3 text-right whitespace-nowrap">฿{{ formatMoney(r.sale_price_satang) }}</td>
                <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(r.commission_satang) }}</td>
                <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">฿{{ formatMoney(r.gp_satang) }}</td>
                <td
                  class="px-4 py-3 text-right font-bold whitespace-nowrap"
                  :class="r.amount_satang < 0 ? 'text-rose-600' : 'text-slate-900'"
                >฿{{ formatMoney(r.amount_satang) }}</td>
                <td class="px-4 py-3 text-xs">
                  {{ statusLabel(r.payment_status) }}
                  <!-- Earned but not yet payable is the figure suppliers argue
                       about; saying so on the row answers it in advance. -->
                  <div v-if="!r.released_at" class="text-amber-700">ยังไม่ถึงกำหนดเบิก</div>
                  <div v-else class="text-slate-400">{{ formatDateTime(r.released_at) }}</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── บัญชีผู้ใช้ ──────────────────────────────────────────────── -->
      <section v-else class="mt-6 space-y-6">
        <div class="bg-white/95 border border-slate-200 rounded-xl p-4 sm:p-6">
          <h2 class="text-sm font-bold text-slate-800 mb-1">สร้างบัญชีให้คู่ค้า</h2>
          <p class="text-xs text-slate-500 mb-4">
            บัญชีนี้จะเห็นเฉพาะคำสั่งซื้อสินค้าของคู่ค้ารายนี้ ยอดค้างรับของตัวเอง และหน้าตัดสิทธิ์บัตรกำนัล
          </p>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <label class="block">
              <span class="block text-xs font-bold text-slate-600 mb-1">ชื่อ</span>
              <input v-model="newUser.first_name" data-test="new-user-first-name" type="text" :class="inputClass" />
              <span v-if="userError('first_name')" class="block text-xs text-rose-600 mt-1">{{ userError('first_name') }}</span>
            </label>
            <label class="block">
              <span class="block text-xs font-bold text-slate-600 mb-1">นามสกุล</span>
              <input v-model="newUser.last_name" data-test="new-user-last-name" type="text" :class="inputClass" />
              <span v-if="userError('last_name')" class="block text-xs text-rose-600 mt-1">{{ userError('last_name') }}</span>
            </label>
            <label class="block">
              <span class="block text-xs font-bold text-slate-600 mb-1">อีเมล (ใช้เข้าสู่ระบบ)</span>
              <input v-model="newUser.email" data-test="new-user-email" type="email" :class="inputClass" />
              <span v-if="userError('email')" class="block text-xs text-rose-600 mt-1">{{ userError('email') }}</span>
            </label>
            <label class="block">
              <span class="block text-xs font-bold text-slate-600 mb-1">เบอร์โทร</span>
              <input v-model="newUser.phone" type="tel" :class="inputClass" />
            </label>
            <label class="block sm:col-span-2">
              <span class="block text-xs font-bold text-slate-600 mb-1">รหัสผ่านชั่วคราว</span>
              <input v-model="newUser.password" data-test="new-user-password" type="text" :class="inputClass" />
              <span v-if="userError('password')" class="block text-xs text-rose-600 mt-1">{{ userError('password') }}</span>
            </label>
          </div>

          <div class="mt-4 flex items-center gap-3">
            <button
              type="button"
              data-test="create-partner-user"
              :disabled="creatingUser || !supplier.is_active"
              class="min-h-[40px] px-4 inline-flex items-center gap-2 rounded-lg bg-brand-600 text-white text-sm font-bold hover:bg-brand-700 disabled:opacity-60 transition"
              @click="createUser"
            >
              <Icon name="user_plus" :size="16" />
              {{ creatingUser ? 'กำลังสร้าง...' : 'สร้างบัญชี' }}
            </button>
            <span v-if="userNotice" data-test="user-notice" class="text-sm text-emerald-700">{{ userNotice }}</span>
            <span v-else-if="!supplier.is_active" class="text-sm text-amber-700">
              คู่ค้ารายนี้ปิดการใช้งานอยู่ — เปิดใช้งานที่แท็บภาพรวมก่อนจึงจะสร้างบัญชีได้
            </span>
          </div>
        </div>

        <LoadingSkeleton v-if="tabLoading" :rows="2" />
        <EmptyState
          v-else-if="users.length === 0"
          icon="users"
          title="ยังไม่มีบัญชีผู้ใช้ของคู่ค้ารายนี้"
          message="สร้างบัญชีด้านบนเพื่อให้คู่ค้าเข้ามาดูคำสั่งซื้อและยอดค้างรับของตัวเองได้"
        />
        <div v-else class="bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
          <table class="w-full text-sm border-collapse" data-test="users-table">
            <thead>
              <tr class="text-left text-xs font-bold text-slate-500 border-b border-slate-200">
                <th class="px-4 py-3">ชื่อ</th>
                <th class="px-4 py-3">อีเมล</th>
                <th class="px-4 py-3">เบอร์โทร</th>
                <th class="px-4 py-3">สร้างเมื่อ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in users" :key="u.id" class="border-b border-slate-100 last:border-0">
                <td class="px-4 py-3 font-bold text-slate-900">
                  {{ u.name }}
                  <!-- A row here whose role drifted off company_partner still
                       carries supplier_id, and would otherwise sit in this
                       list looking like a working partner login. -->
                  <span
                    v-if="!u.is_partner_role"
                    class="ml-2 rounded px-1.5 py-0.5 bg-amber-50 text-amber-700 text-[10px] font-bold"
                  >สิทธิ์ไม่ใช่คู่ค้า</span>
                </td>
                <td class="px-4 py-3 text-slate-600">{{ u.email }}</td>
                <td class="px-4 py-3 text-slate-600">{{ u.phone ?? '—' }}</td>
                <td class="px-4 py-3 text-slate-500 text-xs">{{ u.created_at ? formatDateTime(u.created_at) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
