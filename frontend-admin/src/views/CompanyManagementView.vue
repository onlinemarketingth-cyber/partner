<script setup lang="ts">
/**
 * CompanyManagementView — "จัดการบริษัท" (Phase 7). Super Admin only —
 * the route guard + AdminHomeView's card both already gate this on
 * auth.user.role, and the backend's CompanyPolicy is the real
 * enforcement either way (Section 5).
 *
 * A Company is the tenant boundary itself (CLAUDE.md §2) — this screen
 * is deliberately minimal (name/slug/active), no cascading actions on
 * deactivate/delete are implemented here since none are defined
 * anywhere in CLAUDE.md yet (see CompanyService's own flagged note).
 */
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
// TASK-209 P4 — this screen ignores the header company scope on purpose.
import PlatformScopeBadge from '@/design-system/components/PlatformScopeBadge.vue'

// ADR-006 Round 3/4 → ADR-011 (TASK-034 update): one commission plan
// type per company. All 6 enum values now have a working
// CommissionService engine (Unilevel: original; Binary: TASK-029;
// Matrix: TASK-030; StairstepBreakaway/Generation: TASK-031; Affiliate:
// TASK-032/033) — the earlier "ไบนารี (อยู่ระหว่างพัฒนา)" framing here
// predates TASK-029 and is now stale for all 5 non-Unilevel types, not
// just Binary; removed rather than left half-corrected. A company still
// needs at least one config row (agent_ranks, commission_binary_settings,
// etc. — see the new "แผนคอมมิชชั่น" screen, CommissionPlansView.vue)
// before its chosen plan type actually calculates anything — that's a
// config-completeness concern, not an "under development" one, so it's
// surfaced as a warning banner there instead of blocking selection here.
type CommissionPlanType = 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'

interface CompanyItem {
  id: number
  name: string
  slug: string
  is_active: boolean
  commission_plan_type: CommissionPlanType
  user_count: number
  created_at: string
}

/**
 * 2026-09-17 — THE SUPPLIER DEAL, WHICH HAD NOWHERE TO BE SET.
 *
 * The supplier feature shipped with every column built and no screen to fill
 * them in, so the whole chain was unreachable from its first step: no company
 * could be made a supplier, which left the product form's supplier picker
 * empty, which meant no supplied products and nothing to pay.
 *
 * It belongs on THIS screen rather than a new one because `is_supplier` is a
 * property of a company, and this is the only screen that lists companies —
 * and the only one a Super Admin already has to visit to set one up.
 *
 * Loaded per company, on demand: most companies are not suppliers and fetching
 * terms for all of them would be a request per row for data almost nobody
 * opens.
 */
interface SupplierTerms {
  id: number
  name: string
  is_supplier: boolean
  supplier_gp_mode: string | null
  supplier_gp_value: number | null
  supplier_release_trigger: string | null
  supplier_min_withdrawal_satang: number | null
  supplier_wht_rate: number | null
  supplier_payout_bank_name: string | null
  supplier_payout_bank_account_number: string | null
  supplier_payout_bank_account_name: string | null
}

/** Percent in the box, basis points on the wire — 30 ⇄ 3000. */
const BASIS_POINTS_PER_PERCENT = 100

const termsOpenFor = ref<number | null>(null)
const termsForm = ref<SupplierTerms | null>(null)
const termsLoading = ref(false)
const termsSaving = ref(false)

async function openTerms(company: CompanyItem): Promise<void> {
  if (termsOpenFor.value === company.id) {
    termsOpenFor.value = null

    return
  }

  termsOpenFor.value = company.id
  termsForm.value = null
  termsLoading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: SupplierTerms }>(`/supplier-payouts/${company.id}/terms`)
    termsForm.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError
      ? `โหลดเงื่อนไขคู่ค้าไม่สำเร็จ (${e.status})`
      : 'โหลดเงื่อนไขคู่ค้าไม่สำเร็จ'
    termsOpenFor.value = null
  } finally {
    termsLoading.value = false
  }
}

async function saveTerms(): Promise<void> {
  if (!termsForm.value) return
  termsSaving.value = true
  errorMessage.value = ''
  try {
    const f = termsForm.value
    await api.put(`/supplier-payouts/${f.id}/terms`, {
      is_supplier: f.is_supplier,
      supplier_gp_mode: f.supplier_gp_mode || null,
      supplier_gp_value: f.supplier_gp_value,
      supplier_release_trigger: f.supplier_release_trigger || null,
      supplier_min_withdrawal_satang: f.supplier_min_withdrawal_satang,
      supplier_wht_rate: f.supplier_wht_rate,
      supplier_payout_bank_name: f.supplier_payout_bank_name || null,
      supplier_payout_bank_account_number: f.supplier_payout_bank_account_number || null,
      supplier_payout_bank_account_name: f.supplier_payout_bank_account_name || null,
    })
    termsOpenFor.value = null
    await loadCompanies()
  } catch (e) {
    // In full: the server refuses a half-set GP and refuses to un-flag a
    // supplier we still owe, and each message says which.
    errorMessage.value = e instanceof ApiError
      ? `บันทึกเงื่อนไขคู่ค้าไม่สำเร็จ: ${e.message}`
      : 'บันทึกเงื่อนไขคู่ค้าไม่สำเร็จ'
  } finally {
    termsSaving.value = false
  }
}

const planTypeLabels: Record<CommissionPlanType, string> = {
  unilevel: 'มาตรฐาน (Unilevel)',
  binary: 'ไบนารี (Binary)',
  matrix: 'เมทริกซ์ (Matrix)',
  stairstep_breakaway: 'Stairstep/Breakaway',
  generation: 'Generation',
  affiliate: 'พันธมิตร (Affiliate)',
}
const planTypeOptions = Object.keys(planTypeLabels) as CommissionPlanType[]

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const companies = ref<CompanyItem[]>([])

async function loadCompanies() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: CompanyItem[] }>('/companies')
    companies.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadCompanies)

function slugify(name: string): string {
  return name
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}

// ── Create form ──
const showCreateForm = ref(false)
const createForm = ref<{ name: string; slug: string; commission_plan_type: CommissionPlanType }>({
  name: '',
  slug: '',
  commission_plan_type: 'unilevel',
})
const creating = ref(false)
async function submitCreate() {
  creating.value = true
  errorMessage.value = ''
  try {
    await api.post('/companies', {
      name: createForm.value.name,
      slug: createForm.value.slug || slugify(createForm.value.name),
      commission_plan_type: createForm.value.commission_plan_type,
    })
    createForm.value = { name: '', slug: '', commission_plan_type: 'unilevel' }
    showCreateForm.value = false
    await loadCompanies()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `สร้างไม่สำเร็จ (${e.status})` : 'สร้างไม่สำเร็จ'
  } finally {
    creating.value = false
  }
}

async function toggleActive(company: CompanyItem) {
  try {
    await api.put(`/companies/${company.id}`, { is_active: !company.is_active })
    await loadCompanies()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `อัปเดตไม่สำเร็จ (${e.status})` : 'อัปเดตไม่สำเร็จ'
  }
}

/**
 * 2026-09-12 — writes through PUT /commission-settings, not PUT /companies.
 *
 * `commission_plan_type` left the companies resource that day. Two reasons,
 * both recorded in full on CommissionSettingService: it is not the same
 * question as "may you administer this company" (and borrowing that gate would
 * hand the power to change who gets paid to anybody ever granted company
 * administration), and while the write lived there, step 2 of the commission
 * screen could only LINK here to change a plan — a bounce the owner reported
 * as "ทำให้ UI สับสน".
 *
 * This screen keeps its dropdown: a platform owner looking at every tenant at
 * once is a real view, and it is the same actor behind the same Ability. What
 * changed is which door it knocks on, so there is exactly one.
 */
async function changePlanType(company: CompanyItem, planType: CommissionPlanType) {
  if (planType === company.commission_plan_type) return
  try {
    // company_id is required here (the endpoint scopes a Super Admin by it),
    // where PUT /companies/{id} carried the company in the path.
    await api.put('/commission-settings', { company_id: company.id, commission_plan_type: planType })
    await loadCompanies()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `อัปเดตไม่สำเร็จ (${e.status})` : 'อัปเดตไม่สำเร็จ'
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="building"
      title="จัดการบริษัท"
      subtitle="รายชื่อบริษัท (Tenant) ทั้งแพลตฟอร์ม"
      description="มองเห็นได้เฉพาะ Super Admin — ข้ามบริษัททั้งแพลตฟอร์ม (Section 5)"
      accent-color="brand"
      storage-key="company-management"
    >
      <template #actions>
        <button
          class="btn-primary"
          @click="showCreateForm = !showCreateForm"
        >
          + เพิ่มบริษัท
        </button>
      </template>
    </HeroHeader>

    <PlatformScopeBadge reason="จัดการบริษัททั้งหมดในระบบ" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <form
      v-if="showCreateForm"
      class="mt-4 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3"
      @submit.prevent="submitCreate"
    >
      <div>
        <label class="text-xs font-bold text-slate-500">ชื่อบริษัท</label>
        <input v-model="createForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">Slug (ไม่บังคับ — สร้างอัตโนมัติจากชื่อ)</label>
        <input v-model="createForm.slug" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div class="col-span-2">
        <label class="text-xs font-bold text-slate-500">รูปแบบค่าแนะนำ (เลือกได้ 1 แบบต่อบริษัท)</label>
        <select v-model="createForm.commission_plan_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
          <option v-for="pt in planTypeOptions" :key="pt" :value="pt">{{ planTypeLabels[pt] }}</option>
        </select>
        <p v-if="createForm.commission_plan_type !== 'unilevel'" class="mt-1 text-xs text-slate-400">
          ต้องตั้งค่าที่หน้า "แผนค่าแนะนำ" ก่อน ระบบจึงจะคำนวณค่าแนะนำตามรูปแบบนี้ได้
        </p>
      </div>
      <div class="col-span-2 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="showCreateForm = false">ยกเลิก</button>
        <button type="submit" :disabled="creating" class="btn-primary">
          {{ creating ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
    </form>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="3" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!companies.length" icon="building" title="ยังไม่มีบริษัทในระบบ" class="mt-4" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="c in companies" :key="c.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-start gap-3 min-w-0">
            <Icon name="building" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900">{{ c.name }}</p>
              <p class="text-xs text-slate-400">/{{ c.slug }} · {{ c.user_count }} ผู้ใช้งาน</p>
              <div class="mt-1.5 flex items-center gap-1.5">
                <select
                  :value="c.commission_plan_type"
                  class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 bg-white text-slate-600"
                  @change="changePlanType(c, ($event.target as HTMLSelectElement).value as CommissionPlanType)"
                >
                  <option v-for="pt in planTypeOptions" :key="pt" :value="pt">{{ planTypeLabels[pt] }}</option>
                </select>
                <RouterLink
                  v-if="c.commission_plan_type !== 'unilevel'"
                  :to="{ name: 'commission-plan-settings' }"
                  class="text-[11px] font-bold px-1.5 py-0.5 rounded bg-slate-50 text-slate-500 hover:bg-slate-100 hover:text-brand-600"
                >
                  ตั้งค่าแผนค่าแนะนำ
                </RouterLink>
              </div>
            </div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <!-- 2026-09-17 — the way into the supplier deal. Collapsed by
                 default: most companies are not suppliers, and a panel of
                 money settings open on every row would bury the list this
                 screen is for. -->
            <button
              type="button"
              data-test="supplier-terms-toggle"
              class="text-xs font-bold px-2 py-1 rounded-lg text-slate-600 bg-slate-100 hover:bg-slate-200"
              @click="openTerms(c)"
            >ตั้งค่าคู่ค้า</button>
            <button
              class="text-xs font-bold px-2 py-1 rounded-lg"
              :class="c.is_active ? 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' : 'text-slate-400 bg-slate-100 hover:bg-slate-200'"
              @click="toggleActive(c)"
            >
              {{ c.is_active ? 'ใช้งานอยู่' : 'ปิดใช้งาน' }}
            </button>
          </div>
        </div>

        <!-- ── THE SUPPLIER DEAL ───────────────────────────────────────── -->
        <div v-if="termsOpenFor === c.id" class="mt-4 pt-4 border-t border-slate-200" data-test="supplier-terms-panel">
          <p v-if="termsLoading" class="text-sm text-slate-400">กำลังโหลด...</p>

          <template v-else-if="termsForm">
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="termsForm.is_supplier" type="checkbox" data-test="is-supplier" class="w-5 h-5 rounded border-slate-300 accent-brand-600" />
              <span class="text-sm font-bold text-slate-700">เป็นบริษัทคู่ค้า (นำสินค้าเข้ามาขายผ่านเรา)</span>
            </label>

            <!-- Everything below only means anything for a supplier, and
                 showing it otherwise invites somebody to fill in a GP for a
                 company that will never have one. -->
            <div v-if="termsForm.is_supplier" class="mt-4 grid gap-3 sm:grid-cols-2">
              <div>
                <label class="text-xs font-bold text-slate-500">รูปแบบ GP ที่เราหัก</label>
                <select v-model="termsForm.supplier_gp_mode" data-test="gp-mode" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option :value="null">ยังไม่กำหนด</option>
                  <option value="percent_of_sale">% ของราคาขาย</option>
                  <option value="percent_of_net">% ของยอดหลังหักค่าแนะนำ</option>
                  <option value="fixed_per_unit">จำนวนเงินคงที่ต่อชิ้น</option>
                </select>
              </div>

              <div>
                <label class="text-xs font-bold text-slate-500">
                  {{ termsForm.supplier_gp_mode === 'fixed_per_unit' ? 'ค่า GP (บาทต่อชิ้น)' : 'ค่า GP (%)' }}
                </label>
                <input
                  :value="termsForm.supplier_gp_value === null ? '' : termsForm.supplier_gp_value / 100"
                  type="number"
                  min="0"
                  step="0.01"
                  data-test="gp-value"
                  placeholder="ยังไม่กำหนด"
                  class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
                  @input="termsForm.supplier_gp_value = ($event.target as HTMLInputElement).value === ''
                    ? null
                    : Math.round(Number(($event.target as HTMLInputElement).value) * BASIS_POINTS_PER_PERCENT)"
                />
                <!-- The server refuses one without the other; saying so here
                     saves a round trip. -->
                <p class="mt-1 text-xs text-slate-400">ต้องกรอกคู่กับรูปแบบ GP หรือเว้นว่างทั้งคู่</p>
              </div>

              <div>
                <label class="text-xs font-bold text-slate-500">คู่ค้าเบิกได้เมื่อไหร่</label>
                <select v-model="termsForm.supplier_release_trigger" data-test="release-trigger" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
                  <option :value="null">ยังไม่กำหนด</option>
                  <option value="on_payment">เมื่อลูกค้าชำระเงิน</option>
                  <option value="on_redeemed">เมื่อตัดสิทธิ์บัตรกำนัลแล้ว</option>
                  <option value="on_delivered">เมื่อจัดส่งแล้ว</option>
                </select>
                <!-- The risk dial, named. on_payment can pay a supplier before
                     they have delivered anything. -->
                <p class="mt-1 text-xs text-slate-400">
                  "เมื่อชำระเงิน" คือจ่ายเร็วที่สุด แต่เราแบกความเสี่ยงถ้าลูกค้าขอคืนเงินภายหลัง
                </p>
              </div>

              <div>
                <label class="text-xs font-bold text-slate-500">ขั้นต่ำที่คู่ค้าขอเบิกได้ (บาท)</label>
                <input
                  :value="termsForm.supplier_min_withdrawal_satang === null ? '' : termsForm.supplier_min_withdrawal_satang / 100"
                  type="number"
                  min="0"
                  step="0.01"
                  data-test="min-withdrawal"
                  placeholder="ไม่มีขั้นต่ำ"
                  class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
                  @input="termsForm.supplier_min_withdrawal_satang = ($event.target as HTMLInputElement).value === ''
                    ? null
                    : Math.round(Number(($event.target as HTMLInputElement).value) * 100)"
                />
                <p class="mt-1 text-xs text-slate-400">บังคับเฉพาะตอนคู่ค้าขอเบิกเอง — เราตั้งจ่ายเท่าไหร่ก็ได้</p>
              </div>

              <div class="sm:col-span-2">
                <label class="text-xs font-bold text-slate-500">ภาษีหัก ณ ที่จ่าย (%)</label>
                <input
                  :value="termsForm.supplier_wht_rate === null ? '' : termsForm.supplier_wht_rate / 100"
                  type="number"
                  min="0"
                  step="0.01"
                  data-test="wht-rate"
                  placeholder="ไม่หัก"
                  class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
                  @input="termsForm.supplier_wht_rate = ($event.target as HTMLInputElement).value === ''
                    ? null
                    : Math.round(Number(($event.target as HTMLInputElement).value) * BASIS_POINTS_PER_PERCENT)"
                />
                <!-- The rate depends on goods vs services, which is a property
                     of the PRODUCT — so this is the deal's default and each
                     product can override it. -->
                <p class="mt-1 text-xs text-slate-400">
                  ค่าเริ่มต้นของสัญญา · ขายสินค้าโดยทั่วไปไม่หัก ค่าบริการมักหัก 3% — ตั้งทับรายสินค้าได้ที่หน้าสินค้า
                </p>
              </div>

              <div class="sm:col-span-2 p-3 rounded-lg bg-slate-50 border border-slate-200">
                <p class="text-xs font-bold text-slate-600">บัญชีธนาคารที่เราจะโอนคืนยอดให้</p>
                <!-- Deliberately NOT the account this company receives customer
                     payments into (ตั้งค่าการชำระเงิน). Two accounts, because
                     in practice they are two accounts — and because this one is
                     edited by us, not by them. -->
                <p class="text-xs text-slate-400 mt-0.5">คนละบัญชีกับที่บริษัทใช้รับเงินจากลูกค้า</p>
                <div class="grid gap-3 sm:grid-cols-3 mt-2">
                  <input v-model="termsForm.supplier_payout_bank_name" data-test="bank-name" placeholder="ธนาคาร" class="px-3 py-2 rounded-lg border border-slate-200 text-sm" />
                  <input v-model="termsForm.supplier_payout_bank_account_number" data-test="bank-number" placeholder="เลขที่บัญชี" class="px-3 py-2 rounded-lg border border-slate-200 text-sm" />
                  <input v-model="termsForm.supplier_payout_bank_account_name" data-test="bank-holder" placeholder="ชื่อบัญชี" class="px-3 py-2 rounded-lg border border-slate-200 text-sm" />
                </div>
              </div>
            </div>

            <div class="mt-4 flex items-center gap-2">
              <button type="button" data-test="save-terms" :disabled="termsSaving" class="btn-primary" @click="saveTerms">
                {{ termsSaving ? 'กำลังบันทึก...' : 'บันทึกเงื่อนไขคู่ค้า' }}
              </button>
              <button type="button" class="btn-secondary" @click="termsOpenFor = null">ยกเลิก</button>
            </div>
          </template>
        </div>
        </div>
      </TransitionGroup>
    </template>
  </main>
</template>
