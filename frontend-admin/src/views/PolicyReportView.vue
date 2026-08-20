<script setup lang="ts">
/**
 * PolicyReportView — "นโยบายและรายงาน" (TASK-041, มุมที่ 4).
 *
 * 4 read-only report tabs, each backed by its own already-shipped GET
 * endpoint (Audit Log, Platform Report, Compliance Report, Config
 * Health Report — see task spec TASK-041 / BR-6 / Section 6 / PDPA).
 * This view is frontend-only — every endpoint below already exists and
 * was verified working server-side before this screen was built.
 *
 * Tab-lazy loading: same idiom as ProductPerformanceView.vue's
 * abcLoadedOnce/promoLoadedOnce — each tab fetches its own data only
 * the first time it's activated (not all 4 on mount), tracked with a
 * per-tab xLoadedOnce ref.
 *
 * TabFilterBar.vue does not exist in this app (frontend-admin) — it
 * was only ever ported to frontend/ (Agent Portal), see design-system
 * duplication note in CLAUDE.md Section 7. CommissionPlansView.vue —
 * the only other tabbed view in this app — doesn't use it either; its
 * real `#tabs` slot content is a plain button row. This view copies
 * that actual working pattern instead of importing a component that
 * isn't part of this app yet.
 *
 * BR-3: every satang field is divided by 100 only at display via
 * formatSatang(), reused verbatim from ProductPerformanceView.vue.
 */
import { computed, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
// TASK-208 / ADR-038 — reports follow the header's company scope too; the
// two local "บริษัท" dropdowns this page used to own are gone.
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import DateRangeFilter from '@/design-system/components/DateRangeFilter.vue'
// TASK-209 P4 — the platform tab is cross-company by definition.
import PlatformScopeBadge from '@/design-system/components/PlatformScopeBadge.vue'

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}
function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}
function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

// TASK-208 — the Audit Log and Config Health tabs both used to carry their
// own "บริษัท" <select> (each with its own "ทุกบริษัท" option, each forgetting
// the choice on navigation). Both now read the global scope: null there means
// exactly what "ทุกบริษัท" meant here, so no behaviour is lost.
const activeCompany = useActiveCompanyStore()

// ══════════════════════════ Tabs ══════════════════════════
type Tab = 'audit' | 'platform' | 'compliance' | 'config'
const activeTab = ref<Tab>('audit')
const allTabDefs: { key: Tab; label: string; icon: string; superAdminOnly?: boolean }[] = [
  { key: 'audit', label: 'บันทึกการตรวจสอบ', icon: 'document' },
  { key: 'platform', label: 'รายงานภาพรวมแพลตฟอร์ม', icon: 'globe', superAdminOnly: true },
  { key: 'compliance', label: 'PDPA / Compliance', icon: 'shield_check' },
  { key: 'config', label: 'สถานะการตั้งค่า', icon: 'cog' },
]
// Platform Report tab must not even render as an option for a non
// Super Admin (task spec: "hide this tab entirely").
const tabDefs = computed(() => allTabDefs.filter((t) => !t.superAdminOnly || isSuperAdmin.value))

// ══════════════════════════ Tab 1: Audit Log ══════════════════════════
interface AuditLogRow {
  id: number
  company_id: number | null
  actor_name: string | null
  action: string
  auditable_type: string
  auditable_id: number
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  created_at: string
}
interface PaginationMeta { current_page: number; last_page: number; total: number; per_page: number }

// Known action → Thai label map (task spec — anything unmapped shows
// the raw string rather than being silently hidden).
const ACTION_LABELS: Record<string, string> = {
  'commission_rule.created': 'สร้างกฎคอมมิชชั่น',
  'commission_rule.updated': 'แก้ไขกฎคอมมิชชั่น',
  'agent_approval.approved': 'อนุมัติ agent',
  'agent_approval.rejected': 'ปฏิเสธ agent',
  move_to_company: 'ย้ายบริษัท',
}
function actionLabel(action: string): string {
  return ACTION_LABELS[action] ?? action
}

const auditRows = ref<AuditLogRow[]>([])
const auditMeta = ref<PaginationMeta | null>(null)
const auditLoading = ref(false)
const auditLoadedOnce = ref(false)
const auditError = ref('')
const expandedAuditRowId = ref<number | null>(null)

const auditFilters = ref({
  action: '',
  date_from: '',
  date_to: '',
})

function auditQuery(page: number): string {
  const params = new URLSearchParams()
  params.set('page', String(page))
  if (isSuperAdmin.value && activeCompany.companyId) params.set('company_id', String(activeCompany.companyId))
  if (auditFilters.value.action) params.set('action', auditFilters.value.action)
  if (auditFilters.value.date_from) params.set('date_from', auditFilters.value.date_from)
  if (auditFilters.value.date_to) params.set('date_to', auditFilters.value.date_to)
  return params.toString()
}

async function loadAuditLog(page = 1) {
  auditLoading.value = true
  auditError.value = ''
  try {
    const res = await api.get<{ data: AuditLogRow[]; meta: PaginationMeta }>(`/audit-logs?${auditQuery(page)}`)
    auditRows.value = res.data
    auditMeta.value = res.meta
  } catch (e) {
    auditError.value = apiErrorMessage(e, 'โหลดบันทึกการตรวจสอบไม่สำเร็จ')
  } finally {
    auditLoading.value = false
    auditLoadedOnce.value = true
  }
}
function applyAuditFilters() {
  loadAuditLog(1)
}
function goToAuditPage(page: number) {
  if (!auditMeta.value || page < 1 || page > auditMeta.value.last_page) return
  loadAuditLog(page)
}
function toggleAuditRow(row: AuditLogRow) {
  expandedAuditRowId.value = expandedAuditRowId.value === row.id ? null : row.id
}

// ══════════════════════════ Tab 2: Platform Report (Super Admin only) ══════════════════════════
interface PlatformReportRow {
  company_id: number
  company_name: string
  agent_count: number
  pending_agent_approvals: number
  total_referrals: number
  referrals_completed_payment: number
  commission_paid_satang: number
  commission_pending_satang: number
}
const platformRows = ref<PlatformReportRow[]>([])
const platformComputedAt = ref('')
const platformLoading = ref(false)
const platformLoadedOnce = ref(false)
const platformError = ref('')

async function loadPlatformReport() {
  if (!isSuperAdmin.value) return
  platformLoading.value = true
  platformError.value = ''
  try {
    const res = await api.get<{ data: PlatformReportRow[]; computed_at: string }>('/platform-report')
    platformRows.value = res.data
    platformComputedAt.value = res.computed_at
  } catch (e) {
    platformError.value = apiErrorMessage(e, 'โหลดรายงานภาพรวมแพลตฟอร์มไม่สำเร็จ')
  } finally {
    platformLoading.value = false
    platformLoadedOnce.value = true
  }
}

// ══════════════════════════ Tab 3: Compliance Report ══════════════════════════
interface ComplianceReport {
  total_clients: number
  clients_with_consent: number
  clients_without_consent: number
  consent_rate_percent: number
  clients_missing_consent: { id: number; name: string; referring_agent: string | null; created_at: string }[]
}
const complianceData = ref<ComplianceReport | null>(null)
const complianceComputedAt = ref('')
const complianceLoading = ref(false)
const complianceLoadedOnce = ref(false)
const complianceError = ref('')

async function loadComplianceReport() {
  complianceLoading.value = true
  complianceError.value = ''
  try {
    const res = await api.get<{ data: ComplianceReport; computed_at: string }>('/compliance-report')
    complianceData.value = res.data
    complianceComputedAt.value = res.computed_at
  } catch (e) {
    complianceError.value = apiErrorMessage(e, 'โหลดรายงาน Compliance ไม่สำเร็จ')
  } finally {
    complianceLoading.value = false
    complianceLoadedOnce.value = true
  }
}

// ══════════════════════════ Tab 4: Config Health Report ══════════════════════════
interface ConfigHealthRow {
  company_id: number
  company_name: string
  commission_rules_count: number
  has_commission_rules: boolean
  gamification_overrides_count: number
  has_gamification_overrides: boolean
  academy_modules_count: number
  products_count: number
}
const configRows = ref<ConfigHealthRow[]>([])
const configComputedAt = ref('')
const configLoading = ref(false)
const configLoadedOnce = ref(false)
const configError = ref('')

async function loadConfigHealthReport() {
  configLoading.value = true
  configError.value = ''
  try {
    const query = isSuperAdmin.value && activeCompany.companyId ? `?company_id=${activeCompany.companyId}` : ''
    const res = await api.get<{ data: ConfigHealthRow[]; computed_at: string }>(`/config-health-report${query}`)
    configRows.value = res.data
    configComputedAt.value = res.computed_at
  } catch (e) {
    configError.value = apiErrorMessage(e, 'โหลดรายงานสถานะการตั้งค่าไม่สำเร็จ')
  } finally {
    configLoading.value = false
    configLoadedOnce.value = true
  }
}
// TASK-208 — both tabs refetch when the header scope changes.
watch(() => activeCompany.companyId, () => {
  loadConfigHealthReport()
  loadAuditLog(1)
})

// commission_rules has NO platform-default fallback (a company with
// zero rules genuinely has no commission configured — more urgent),
// while gamification_overrides falsy just means "using the platform
// default", which is a legitimate, non-urgent state. Copy/color
// deliberately differ to reflect that (task spec).
function commissionHealthBadgeClass(has: boolean): string {
  return has ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
}
function commissionHealthBadgeLabel(has: boolean): string {
  return has ? 'กำหนดแล้ว' : 'ยังไม่มีกฎคอมมิชชั่นเลย'
}
function gamificationHealthBadgeClass(has: boolean): string {
  return has ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'
}
function gamificationHealthBadgeLabel(has: boolean): string {
  return has ? 'กำหนดแล้ว' : 'ยังไม่กำหนด — ใช้ค่า default ของแพลตฟอร์ม'
}

// ── Tab-lazy loading dispatcher ──
function loadTabIfNeeded(tab: Tab) {
  if (tab === 'audit' && !auditLoadedOnce.value) loadAuditLog(1)
  else if (tab === 'platform' && !platformLoadedOnce.value) loadPlatformReport()
  else if (tab === 'compliance' && !complianceLoadedOnce.value) loadComplianceReport()
  else if (tab === 'config' && !configLoadedOnce.value) loadConfigHealthReport()
}
watch(
  activeTab,
  (tab) => {
    loadTabIfNeeded(tab)
    if (isSuperAdmin.value) activeCompany.loadCompanies()
  },
  { immediate: true },
)
</script>

<template>
  <div class="px-4 lg:px-6 pb-4 lg:pb-6 w-full" style="font-family: Kanit, sans-serif;">
    <HeroHeader
      icon="shield"
      title="นโยบายและรายงาน"
      subtitle="Audit Log, รายงานภาพรวม, PDPA/Compliance, สถานะการตั้งค่า"
      accent-color="brand"
      storage-key="policy-report"
    >
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in tabDefs"
            :key="t.key"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key"
          >
            <Icon :name="t.icon" :size="16" />
            {{ t.label }}
          </button>
        </div>
      </template>
    </HeroHeader>

    <!-- ═══════════ Tab 1: บันทึกการตรวจสอบ (Audit Log) ═══════════ -->
    <section v-if="activeTab === 'audit'" class="mt-4">
      <div class="mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
        บันทึกนี้ยังไม่ครอบคลุมทุก action ตามที่ Section 6 กำหนด (เงิน/คอมมิชชั่น/สถานะ/ใบรับรอง/สิทธิ์) — ปัจจุบันมีการบันทึกเฉพาะ:
        ย้ายบริษัท, สร้าง/แก้ไขกฎคอมมิชชั่น, อนุมัติ/ปฏิเสธ agent เท่านั้น การขยายให้ครอบคลุมทุก action เป็นงานต่อเนื่อง
      </div>

      <div class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-bold text-slate-500 mb-1">การกระทำ</label>
          <input v-model="auditFilters.action" placeholder="เช่น commission_rule.created" class="px-3 py-2 rounded-lg border border-slate-200 text-sm min-w-[14rem]" />
        </div>
        <DateRangeFilter v-model:date-from="auditFilters.date_from" v-model:date-to="auditFilters.date_to" :years-back="3" :years-forward="0" />
        <button class="btn-primary" @click="applyAuditFilters">
          กรอง
        </button>
      </div>

      <div v-if="auditError" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ auditError }}</div>

      <LoadingSkeleton v-if="auditLoading && !auditLoadedOnce" type="list" :rows="5" />
      <template v-else>
        <EmptyState v-if="!auditRows.length" icon="document" title="ยังไม่มีบันทึกการตรวจสอบตามเงื่อนไขนี้" />
        <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white/95">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left">
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 whitespace-nowrap">เวลา</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">ผู้ทำรายการ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">การกระทำ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">เป้าหมาย</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 whitespace-nowrap">IP</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-center">รายละเอียด</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="row in auditRows" :key="row.id">
                <tr class="border-b border-slate-50 last:border-0">
                  <td class="px-4 py-2.5 text-xs text-slate-500 whitespace-nowrap">{{ formatDateTime(row.created_at) }}</td>
                  <td class="px-4 py-2.5 text-slate-700">{{ row.actor_name ?? '—' }}</td>
                  <td class="px-4 py-2.5">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ actionLabel(row.action) }}</span>
                  </td>
                  <td class="px-4 py-2.5 text-slate-500 text-xs whitespace-nowrap">{{ row.auditable_type }} #{{ row.auditable_id }}</td>
                  <td class="px-4 py-2.5 text-xs text-slate-400 whitespace-nowrap">{{ row.ip_address ?? '—' }}</td>
                  <td class="px-4 py-2.5 text-center">
                    <button class="text-slate-400 hover:text-brand-600" @click="toggleAuditRow(row)">
                      <Icon :name="expandedAuditRowId === row.id ? 'chevron_up' : 'chevron_down'" :size="16" />
                    </button>
                  </td>
                </tr>
                <tr v-if="expandedAuditRowId === row.id" class="border-b border-slate-50 bg-slate-50/60">
                  <td colspan="6" class="px-4 py-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                      <div>
                        <p class="text-xs font-bold text-slate-500 mb-1">ค่าเดิม (old_values)</p>
                        <pre class="text-xs bg-white border border-slate-200 rounded-lg p-2 overflow-x-auto">{{ row.old_values ? JSON.stringify(row.old_values, null, 2) : '—' }}</pre>
                      </div>
                      <div>
                        <p class="text-xs font-bold text-slate-500 mb-1">ค่าใหม่ (new_values)</p>
                        <pre class="text-xs bg-white border border-slate-200 rounded-lg p-2 overflow-x-auto">{{ row.new_values ? JSON.stringify(row.new_values, null, 2) : '—' }}</pre>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div v-if="auditMeta && auditMeta.last_page > 1" class="mt-3 flex items-center justify-between text-xs text-slate-500">
          <span>หน้า {{ auditMeta.current_page }} / {{ auditMeta.last_page }} (ทั้งหมด {{ auditMeta.total.toLocaleString('th-TH') }} รายการ)</span>
          <div class="flex gap-1">
            <button
              class="px-3 py-1.5 rounded-lg border border-slate-200 font-bold disabled:opacity-40"
              :disabled="auditMeta.current_page <= 1"
              @click="goToAuditPage(auditMeta.current_page - 1)"
            >
              ก่อนหน้า
            </button>
            <button
              class="px-3 py-1.5 rounded-lg border border-slate-200 font-bold disabled:opacity-40"
              :disabled="auditMeta.current_page >= auditMeta.last_page"
              @click="goToAuditPage(auditMeta.current_page + 1)"
            >
              ถัดไป
            </button>
          </div>
        </div>
      </template>
    </section>

    <!-- ═══════════ Tab 2: รายงานภาพรวมแพลตฟอร์ม (Super Admin only) ═══════════ -->
    <section v-else-if="activeTab === 'platform'" class="mt-4">
      <!-- TASK-209 P4 — this ONE tab ignores the header scope (the other
           three follow it); say so where the difference is visible. -->
      <PlatformScopeBadge reason="รายงานนี้เปรียบเทียบข้ามบริษัทเป็นหลัก จึงไม่กรองตามบริษัทที่เลือก" />
      <p v-if="platformComputedAt" class="text-xs text-slate-400 mb-3">คำนวณล่าสุดเมื่อ {{ formatDateTime(platformComputedAt) }}</p>

      <div v-if="platformError" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ platformError }}</div>

      <LoadingSkeleton v-if="platformLoading && !platformLoadedOnce" type="list" :rows="4" />
      <template v-else>
        <EmptyState v-if="!platformRows.length" icon="globe" title="ยังไม่มีข้อมูลบริษัทในระบบ" />
        <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white/95">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left">
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">บริษัท</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">จำนวน Agent</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">รออนุมัติ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">Referral ทั้งหมด</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">ชำระเงินสำเร็จ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">คอมมิชชั่นจ่ายแล้ว</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right whitespace-nowrap">คอมมิชชั่นค้างจ่าย</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in platformRows" :key="row.company_id" class="border-b border-slate-50 last:border-0">
                <td class="px-4 py-2.5 font-bold text-slate-900">{{ row.company_name }}</td>
                <td class="px-4 py-2.5 text-right text-slate-600">{{ row.agent_count.toLocaleString('th-TH') }}</td>
                <td class="px-4 py-2.5 text-right text-slate-600">{{ row.pending_agent_approvals.toLocaleString('th-TH') }}</td>
                <td class="px-4 py-2.5 text-right text-slate-600">{{ row.total_referrals.toLocaleString('th-TH') }}</td>
                <td class="px-4 py-2.5 text-right text-slate-600">{{ row.referrals_completed_payment.toLocaleString('th-TH') }}</td>
                <td class="px-4 py-2.5 text-right text-slate-900 font-bold whitespace-nowrap">{{ formatSatang(row.commission_paid_satang) }}</td>
                <td class="px-4 py-2.5 text-right text-slate-500 whitespace-nowrap">{{ formatSatang(row.commission_pending_satang) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <!-- ═══════════ Tab 3: PDPA / Compliance Report ═══════════ -->
    <section v-else-if="activeTab === 'compliance'" class="mt-4">
      <div class="mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
        รายงานนี้แสดงเฉพาะสถานะ consent แบบ field เดียว (ให้ความยินยอมแล้ว/ยังไม่ให้) — ยังไม่มีระบบเก็บ log เวอร์ชัน consent หรือประวัติการเพิกถอนความยินยอมแบบละเอียด
      </div>
      <div v-if="isSuperAdmin" class="mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
        Super Admin เห็นข้อมูลรวมทุกบริษัท (ยังไม่มีตัวกรองแยกบริษัทในหน้านี้)
      </div>
      <p v-if="complianceComputedAt" class="text-xs text-slate-400 mb-3">คำนวณล่าสุดเมื่อ {{ formatDateTime(complianceComputedAt) }}</p>

      <div v-if="complianceError" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ complianceError }}</div>

      <LoadingSkeleton v-if="complianceLoading && !complianceLoadedOnce" type="dashboard" />
      <template v-else-if="complianceData">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
          <div class="p-4 rounded-xl bg-white/95 border border-slate-200">
            <p class="text-xs text-slate-400">ลูกค้าทั้งหมด</p>
            <p class="text-xl font-bold text-slate-900 mt-1">{{ complianceData.total_clients.toLocaleString('th-TH') }}</p>
          </div>
          <div class="p-4 rounded-xl bg-white/95 border border-slate-200">
            <p class="text-xs text-slate-400">อัตราการให้ consent</p>
            <p class="text-xl font-bold text-slate-900 mt-1">{{ complianceData.consent_rate_percent.toFixed(1) }}%</p>
          </div>
          <div class="p-4 rounded-xl bg-white/95 border border-slate-200">
            <p class="text-xs text-slate-400">ยังไม่ให้ consent</p>
            <p class="text-xl font-bold text-rose-600 mt-1">{{ complianceData.clients_without_consent.toLocaleString('th-TH') }}</p>
          </div>
        </div>

        <p class="text-sm font-bold text-slate-900 mb-2">รายชื่อลูกค้าที่ยังไม่ให้ consent (เก่าสุดก่อน)</p>
        <EmptyState v-if="!complianceData.clients_missing_consent.length" icon="shield_check" title="ลูกค้าทุกรายให้ consent ครบแล้ว" />
        <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white/95">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left">
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">ชื่อลูกค้า</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">ตัวแทนผู้แนะนำ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 whitespace-nowrap">วันที่สร้าง</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in complianceData.clients_missing_consent" :key="c.id" class="border-b border-slate-50 last:border-0">
                <td class="px-4 py-2.5 font-bold text-slate-900">{{ c.name }}</td>
                <td class="px-4 py-2.5 text-slate-600">{{ c.referring_agent ?? '—' }}</td>
                <td class="px-4 py-2.5 text-slate-500 whitespace-nowrap">{{ formatDate(c.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <!-- ═══════════ Tab 4: สถานะการตั้งค่า (Config Health Report) ═══════════ -->
    <section v-else-if="activeTab === 'config'" class="mt-4">
      <div class="mb-3 flex items-center justify-between gap-3 flex-wrap">
        <p v-if="configComputedAt" class="text-xs text-slate-400">คำนวณล่าสุดเมื่อ {{ formatDateTime(configComputedAt) }}</p>
      </div>

      <div v-if="configError" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ configError }}</div>

      <LoadingSkeleton v-if="configLoading && !configLoadedOnce" type="list" :rows="3" />
      <template v-else>
        <EmptyState v-if="!configRows.length" icon="cog" title="ยังไม่มีข้อมูลบริษัทในระบบ" />
        <div v-else class="space-y-2">
          <div v-for="row in configRows" :key="row.company_id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
              <p class="text-sm font-bold text-slate-900">{{ row.company_name }}</p>
              <div class="flex flex-wrap gap-1.5">
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="commissionHealthBadgeClass(row.has_commission_rules)">
                  คอมมิชชั่น: {{ commissionHealthBadgeLabel(row.has_commission_rules) }}
                </span>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="gamificationHealthBadgeClass(row.has_gamification_overrides)">
                  Gamification: {{ gamificationHealthBadgeLabel(row.has_gamification_overrides) }}
                </span>
              </div>
            </div>
            <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs text-slate-500">
              <div>
                <p class="text-slate-400">กฎคอมมิชชั่น</p>
                <p class="font-bold text-slate-700">{{ row.commission_rules_count.toLocaleString('th-TH') }}</p>
              </div>
              <div>
                <p class="text-slate-400">Gamification overrides</p>
                <p class="font-bold text-slate-700">{{ row.gamification_overrides_count.toLocaleString('th-TH') }}</p>
              </div>
              <div>
                <p class="text-slate-400">โมดูล Academy</p>
                <p class="font-bold text-slate-700">{{ row.academy_modules_count.toLocaleString('th-TH') }}</p>
              </div>
              <div>
                <p class="text-slate-400">สินค้า</p>
                <p class="font-bold text-slate-700">{{ row.products_count.toLocaleString('th-TH') }}</p>
              </div>
            </div>
          </div>
        </div>
      </template>
    </section>
  </div>
</template>
