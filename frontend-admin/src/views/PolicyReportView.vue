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
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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
import { fetchAllPages } from './agentEdit'
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
const route = useRoute()
const router = useRouter()

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
  'settings.commission_withdrawal_minimum_updated': 'แก้ไขยอดขั้นต่ำในการเบิกค่าคอม',
  // ADR-027 — the payment gateway's own reports. These have no actor: they
  // are things a provider told us, not things a person did here.
  'order.gateway_payment_failed': 'ชำระเงินไม่สำเร็จ (ผ่านช่องทางออนไลน์)',
  'order.gateway_checkout_expired': 'ลูกค้าไม่ได้ชำระเงินภายในเวลาที่กำหนด',
  'order.gateway_refund_reported': 'ผู้ให้บริการแจ้งการคืนเงิน',
  'order.gateway_payment_unmatched': 'มีการชำระเงินที่จับคู่คำสั่งซื้อไม่ได้',
  'webhook.unhandled_event_type': 'ได้รับ event ที่ระบบยังไม่มีตัวจัดการ',
  'webhook.unmatched_non_payment': 'ได้รับ event ที่จับคู่คำสั่งซื้อไม่ได้ (ไม่ใช่การชำระเงิน)',
  // TASK-237/238/240 — the account and session events. An action with no
  // label here still shows its raw key rather than being hidden, which is
  // why an unmapped one is a cosmetic gap and never a missing row.
  'user.created': 'สร้างผู้ใช้',
  'user.role_changed': 'เปลี่ยนบทบาท',
  'user.team_leader_changed': 'เปลี่ยนสถานะหัวหน้าทีม',
  'user.manager_changed': 'เปลี่ยนหัวหน้า',
  'user.deactivated': 'ปิดบัญชี',
  'user.restored': 'กู้คืนบัญชี',
  'user.password_changed': 'เจ้าของเปลี่ยนรหัสผ่านเอง',
  'user.password_reset_by_admin': 'แอดมินรีเซ็ตรหัสผ่านให้',
  'user.api_tokens_revoked': 'ถอนสิทธิ์เข้าใช้งานที่ค้างอยู่ (token)',
  'user.super_admin_created': 'สร้าง Super Admin (จากบรรทัดคำสั่ง)',
  'user.super_admin_granted': 'เลื่อนเป็น Super Admin (จากบรรทัดคำสั่ง)',
  'user.bank_account_updated': 'แก้ไขบัญชีธนาคาร',
  'user.national_id_updated': 'แก้ไขเอกสารยืนยันตัวตน',
  'user.view_super_admin_record': 'เปิดดูข้อมูลของ Super Admin',
  'team_client_file.view': 'หัวหน้าทีมเปิดแฟ้มลูกค้าของลูกทีม',
  'auth.login': 'เข้าสู่ระบบสำเร็จ',
  'auth.login_failed': 'เข้าสู่ระบบไม่สำเร็จ',
  'auth.login_blocked': 'รหัสผ่านถูกต้อง แต่บัญชีเข้าใช้งานไม่ได้',
  'auth.lockout': 'ถูกล็อกชั่วคราวจากการพยายามเข้าสู่ระบบหลายครั้ง',
  // TASK-242 — the trail records reads of itself. Deliberately worded as an
  // action somebody took, not as a system event.
  'audit_log.exported': 'ส่งออกบันทึกการตรวจสอบเป็นไฟล์ CSV',
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

/**
 * TASK-241 — `?actor=<id>`, read at SETUP TIME.
 *
 * This ran in onMounted() first, and the deep link did not work: the
 * tab-dispatch watch at the bottom of this file is `{ immediate: true }`, so
 * the first /audit-logs request is fired while setup() is still running —
 * before any onMounted hook. Arriving from a "ดูกิจกรรมของผู้ใช้นี้" link
 * therefore fetched the WHOLE platform's trail, then fetched again narrowed:
 * two requests, and one paint of rows about people the viewer never asked to
 * read. Found by the test, not by opening the screen — both fetches are fast
 * and the final picture is correct.
 *
 * Anything that is not a positive integer means no filter at all: a truncated
 * or hand-edited URL must read as "everybody", never as `NaN` in a query
 * string.
 */
function actorFromQuery(): number | '' {
  const actor = Number(route.query.actor)

  return Number.isInteger(actor) && actor > 0 ? actor : ''
}

const auditFilters = ref({
  action: '',
  date_from: '',
  date_to: '',
  /*
   * TASK-240 — "which user did what", the question this screen could not
   * answer. `actor_user_id` has been on every row since the table existed
   * and nothing read it back. '' = everybody, never "nobody".
   */
  actor_user_id: actorFromQuery(),
})

/**
 * The people who can appear as an actor.
 *
 * fetchAllPages is the pattern four other admin screens already use for a
 * person dropdown — same endpoint, same shape. Note /users excludes Super
 * Admins by design, so one Super Admin cannot pick another out of this list;
 * that is the same boundary Ability::UserViewSuperAdminRecord draws
 * everywhere else, not an omission.
 *
 * NO scopedPath() HERE. fetchAllPages applies it internally (see its body),
 * so wrapping the path a second time sends `company_id` twice — which PHP
 * resolves to the last one and is therefore invisible until the day the two
 * differ. `include_inactive=1` on purpose: a deactivated account is exactly
 * the one somebody comes to this screen to ask about.
 */
interface ActorOption { id: number; name: string }
const actorOptions = ref<ActorOption[]>([])

async function loadActorOptions() {
  try {
    actorOptions.value = await fetchAllPages<ActorOption>('/users?include_inactive=1')
  } catch {
    // A dropdown that will not load must not take the trail down with it —
    // the log itself is why anybody opened this page.
    actorOptions.value = []
  }
}

/**
 * The filters, without the page — shared by the table and the CSV.
 *
 * TASK-242: an export is taken away and shown to somebody (an auditor, a
 * lawyer). A file that quietly answers a slightly WIDER question than the
 * screen it came from is worse than no export at all, because the difference
 * is found by the person it was handed to. One builder means the two cannot
 * drift; the backend shares its filter builder between the same two callers
 * for the same reason.
 */
function auditFilterParams(): URLSearchParams {
  const params = new URLSearchParams()
  if (isSuperAdmin.value && activeCompany.companyId) params.set('company_id', String(activeCompany.companyId))
  if (auditFilters.value.actor_user_id !== '') {
    params.set('actor_user_id', String(auditFilters.value.actor_user_id))
  }
  if (auditFilters.value.action) params.set('action', auditFilters.value.action)
  if (auditFilters.value.date_from) params.set('date_from', auditFilters.value.date_from)
  if (auditFilters.value.date_to) params.set('date_to', auditFilters.value.date_to)

  return params
}

function auditQuery(page: number): string {
  const params = auditFilterParams()
  params.set('page', String(page))

  return params.toString()
}

/*
 * TASK-242 — the trail as a file.
 *
 * api.download(), never an <a href> (Section 5 rule 6): the request has to
 * carry the same session cookie and XSRF token as every other call, and the
 * server records who took the copy.
 *
 * The server refuses a range wider than a year and says by how much; that
 * message is what ApiError carries, so it is shown verbatim rather than
 * replaced with a generic failure the admin cannot act on.
 */
const auditExporting = ref(false)

async function exportAuditCsv() {
  auditExporting.value = true
  auditError.value = ''
  try {
    const query = auditFilterParams().toString()
    await api.download(`/audit-logs/export${query ? `?${query}` : ''}`)
  } catch (e) {
    auditError.value = apiErrorMessage(e, 'ส่งออก CSV ไม่สำเร็จ')
  } finally {
    auditExporting.value = false
  }
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
/**
 * TASK-240 — the actor filter lives in the URL.
 *
 * Two reasons, both about how this screen gets used: the question is asked
 * FROM somewhere else ("what did this agent do?", clicked on the roster),
 * and the answer is usually shown to somebody else. A filter that exists
 * only in component state can be neither linked to nor sent.
 *
 * `replace`, not `push`: refining a filter is not a place in history, and
 * pushing would make Back walk through every refinement instead of leaving
 * the page.
 *
 * Only the id travels, never the name — a person's name in a URL ends up in
 * browser history and in the Referer of everything the next page loads (§6).
 */
function syncAuditFiltersToUrl() {
  const query: Record<string, string> = { ...route.query as Record<string, string> }

  if (auditFilters.value.actor_user_id === '') delete query.actor
  else query.actor = String(auditFilters.value.actor_user_id)

  void router.replace({ query })
}

function applyAuditFilters() {
  syncAuditFiltersToUrl()
  loadAuditLog(1)
}

/** Cleared from the chip above the table, without touching the other filters. */
function clearActorFilter() {
  auditFilters.value.actor_user_id = ''
  applyAuditFilters()
}

const selectedActorName = computed(
  () => actorOptions.value.find((a) => a.id === auditFilters.value.actor_user_id)?.name ?? '',
)
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
  // The people who can appear as an actor are a per-company list too.
  void loadActorOptions()
})

/*
 * TASK-240 — arriving from somewhere else with a person already in mind.
 *
 * The FILTER itself is read in actorFromQuery() during setup (see the note
 * there — the first fetch happens before this hook runs). What is left here
 * is only what can safely wait a tick: the dropdown of names, which nothing
 * on screen depends on.
 */
onMounted(() => {
  void loadActorOptions()
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
      <!-- 2026-09-05 — this notice used to list four actions and say the
           coverage was "งานต่อเนื่อง". It had been out of date for a while:
           the trail now records around forty actions across users, money,
           orders, PDPA reads and Academy. Rewritten to say what is true,
           including the one thing that is still genuinely missing, rather
           than deleted — a reader deserves to know where the edge is. -->
      <div class="mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
        บันทึกนี้เก็บ <strong>การเปลี่ยนแปลง</strong> ที่กระทบสิทธิ์ เงิน คำสั่งซื้อ และข้อมูลส่วนบุคคล
        รวมถึงการเข้าสู่ระบบ (สำเร็จ / ไม่สำเร็จ / ถูกล็อก)
        — <strong>ยังไม่เก็บการ “เปิดดู” ข้อมูลทั่วไป</strong> ยกเว้น 2 กรณีที่บันทึกไว้แล้ว
        (เปิดดูข้อมูล Super Admin และหัวหน้าทีมเปิดแฟ้มลูกค้าของลูกทีม)
      </div>

      <div class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 flex flex-wrap items-end gap-3">
        <!-- TASK-240 — the filter this screen was missing. Names, not ids:
             nobody remembers a user id, and the whole question is about a
             person. -->
        <div>
          <label class="block text-xs font-bold text-slate-500 mb-1">ผู้ทำรายการ</label>
          <select
            v-model="auditFilters.actor_user_id"
            data-test="actor-filter"
            class="px-3 py-2 rounded-lg border border-slate-200 text-sm min-w-[14rem] bg-white"
          >
            <option value="">ทุกคน</option>
            <option v-for="actor in actorOptions" :key="actor.id" :value="actor.id">{{ actor.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-500 mb-1">การกระทำ</label>
          <input v-model="auditFilters.action" placeholder="เช่น commission_rule.created" class="px-3 py-2 rounded-lg border border-slate-200 text-sm min-w-[14rem]" />
        </div>
        <DateRangeFilter v-model:date-from="auditFilters.date_from" v-model:date-to="auditFilters.date_to" :years-back="3" :years-forward="0" />
        <button class="btn-primary" @click="applyAuditFilters">
          กรอง
        </button>
        <!-- TASK-242 — the same filters, as a file. Beside "กรอง" on purpose:
             the file is what is on screen, and a button anywhere else reads
             as "export everything". -->
        <button
          class="btn-secondary inline-flex items-center gap-1.5"
          data-test="export-audit-csv"
          :disabled="auditExporting"
          @click="exportAuditCsv"
        >
          <Icon name="download" :size="14" />
          {{ auditExporting ? 'กำลังส่งออก…' : 'ส่งออก CSV' }}
        </button>
        <p class="w-full text-[11px] text-slate-400">
          ไฟล์ที่ส่งออกใช้ตัวกรองเดียวกับตารางด้านล่าง · ส่งออกได้ครั้งละไม่เกิน 1 ปี
          (ถ้าไม่ระบุวันที่ จะได้ข้อมูลย้อนหลัง 1 ปีล่าสุด) · ระบบบันทึกทุกครั้งที่มีการส่งออก
        </p>
      </div>

      <!-- Says out loud that the table is narrowed to one person. Without
           it, arriving from a "ดูกิจกรรมของผู้ใช้นี้" link looks identical to
           a quiet day on the whole platform. -->
      <div
        v-if="auditFilters.actor_user_id !== ''"
        class="mb-3 px-4 py-2.5 rounded-xl bg-brand-50 border border-brand-200 text-sm text-brand-800 flex items-center gap-2"
      >
        <Icon name="user" :size="16" />
        <span>กำลังดูเฉพาะกิจกรรมของ <strong>{{ selectedActorName || `ผู้ใช้ #${auditFilters.actor_user_id}` }}</strong></span>
        <button class="ml-auto text-xs font-bold underline hover:no-underline" @click="clearActorFilter">
          ดูของทุกคน
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
