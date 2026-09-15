<script setup lang="ts">
/**
 * AgentCommissionSummaryView — "ค่าคอมมิชชั่น" (TASK-043 §3), the new
 * sub-item under the "จัดการตัวแทน" pillar (see AdminNavigation.vue).
 *
 * Deliberately a different screen from CommissionManagementView.vue
 * (`/commission`, the flat ledger of individual rows) — this one is a
 * one-row-per-agent aggregate from the new
 * GET /agent-commission-summary endpoint (AgentCommissionSummaryService,
 * TASK-043 §3 backend). Read-only, no write actions here (mark-paid
 * stays on the ledger screen where individual rows live).
 *
 * BR-3: amounts come back as integer satang; divided by 100 only here,
 * at the display layer.
 *
 * TASK-046 — per-agent drill-down ("ดูรายละเอียด"): which products were
 * sold, which clients bought, and how each commission was calculated.
 * Reuses GET /commission-ledger (now accepts ?agent_id=, added this
 * task) rather than inventing a new endpoint — same data CommissionManagementView.vue
 * already renders per row, just filtered to one agent and using the
 * SAME date/status filters currently applied on this page. Deliberately
 * does NOT show a derived "rate × base price" — the base sale price
 * isn't stored on commission_ledger (only the final amount_satang and
 * the rate/cert-tier/earned_via SNAPSHOT are, per BR-4), so back-deriving
 * one would be an invented number (CLAUDE.md §8 guardrail #2). Instead
 * shows the real stored snapshot: rate, cert tier, earned_via (direct/
 * renewal/override/binary match/etc — see TASK-046 doc), and the
 * override source agent when applicable.
 *
 * TASK-044 §4: date-range (BuddhistDateInput, filters
 * commission_ledger.created_at server-side) + payment-status filters,
 * plus an "Export CSV" button that streams
 * GET /agent-commission-summary/export with the same filters applied
 * (bank payout file — includes missing_bank_info flag server-side).
 * Filter re-fetch is an explicit "กรอง" button, matching the existing
 * pattern on PolicyReportView.vue's Audit Log tab (applyAuditFilters())
 * rather than a debounced auto-refetch — this app has no other
 * debounced-filter precedent to follow instead.
 *
 * TASK-045 — Admin asked to be able to fill in an agent's bank account
 * directly from THIS screen (previously only possible from "จัดการตัวแทน"
 * / AgentManagementView.vue). Reuses that exact PUT /users/{id} pattern.
 *
 * TASK-047 (human-confirmed reversal of TASK-045's masking here —
 * "แสดงเลยครับ เพราะต้องใช้งาน", show it directly, needed for actual use;
 * a hide/show toggle is explicitly deferred to a future system-settings
 * task) — AgentCommissionSummaryService now returns the REAL
 * bank_account_number (see that Service's own comment for why this is
 * safe: this whole page is already Company-Admin/Super-Admin-only). The
 * bank-edit form now prefills from that real value on open, and after a
 * successful save the panel stays open showing the saved number instead
 * of closing — both per the human's explicit point 2 request. Point 1:
 * "บัญชีธนาคาร"/"ดูรายละเอียด" are real bordered buttons now, not plain
 * text links. Point 3: the drill-down is a proper <table> with a fixed
 * column order (date/client/product/sale price/promotion/commission/
 * status) instead of the TASK-046 card list. Point 4: the agent's own
 * profile (avatar/name/email/phone/join date/cert tier, from
 * GET /users/{id} — UserResource) renders above that table. Point 5: a
 * real avatar image when set, else a colored initial-circle — color
 * keyed off cert_tier.key (Basic=slate, Intermediate=brand blue,
 * High=amber; no tier passed yet=slate, same as Basic, since "no tier"
 * and "Basic" both mean the agent hasn't unlocked anything above BR-1's
 * own gate). Not word-for-word confirmed by the human — flagged as an
 * easy-to-change cosmetic default, same as TASK-045 handled non-blocking
 * cosmetic choices.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import CommissionLedgerPanel from './CommissionLedgerPanel.vue'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import DateRangeFilter from '@/design-system/components/DateRangeFilter.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

const route = useRoute()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()

interface AgentSummaryItem {
  agent_id: number
  agent_name: string | null
  /**
   * TASK-179 §3.7 (F-10) — NULL means "this bucket was excluded by the
   * payment-status filter, so nobody measured it". It is NOT zero.
   *
   * The old contract forced the excluded bucket to literal 0, so filtering
   * by "จ่ายแล้ว" rendered "รอจ่ายรวม 0 บาท" — visually identical to "we owe
   * our agents nothing", which is a statement about money nobody computed.
   *
   * A `?? 0` anywhere below re-creates that defect exactly. Render
   * "ไม่ได้แสดง" instead — see formatSatangOrUnmeasured().
   */
  total_paid_satang: number | null
  total_pending_satang: number | null
  entry_count: number
  bank_name: string | null
  // TASK-047 — renamed from bank_account_number_masked: the backend now
  // returns the REAL number here (see this file's top docblock).
  bank_account_number: string | null
  bank_account_holder_name: string | null
  /**
   * 2026-09-15 — this payee is the COMPANY, not one of its agents.
   *
   * A company can hold a seat at the top of its own hierarchy and be paid a
   * leader's override (ขั้นตอนที่ 4.2). This screen is a payout RUN, so the
   * seat cannot sit in the same list: it has no bank details, nothing is
   * transferred to it, and counting it as an agent overstates how many people
   * are owed money.
   */
  is_company_share?: boolean
  // TASK-047 follow-up — human feedback: the avatar must show on the row
  // itself without clicking "ดูรายละเอียด" first, so
  // AgentCommissionSummaryService::buildSummary() now returns these two
  // fields directly (bulk-loaded server-side, no N+1 — see that
  // Service's own comment).
  avatar_url: string | null
  cert_tier: { id: number; key: string; name: string } | null
}

// TASK-046 — same shape CommissionLedgerResource already returns (and
// CommissionManagementView.vue already renders), plus the two fields
// this task added: earned_via and override_source_agent.
// TASK-047 — plus sale_price_satang_at_time / applied_price_promotion,
// the immutable snapshot columns for the new table's "ราคาที่ขายได้" /
// "โปรโมชั่น" columns. Both null for row types that don't set them (see
// CommissionLedgerResource's own comment) — rendered as "—", never a
// derived/invented number (CLAUDE.md §8 guardrail #2, same reasoning
// TASK-046 already documented for why no derived base price is shown).
interface LedgerItem {
  id: number
  referral: { id: number; client: { id: number; name: string } | null } | null
  agent: { id: number; name: string } | null
  cert_tier_at_time: { id: number; key: string; name: string } | null
  product: { id: number; name: string } | null
  rate_type_applied: 'percentage' | 'fixed_satang'
  rate_applied: number
  amount_satang: number
  sale_price_satang_at_time: number | null
  applied_price_promotion: { id: number; note: string | null; discounted_price_satang: number } | null
  payment_status: 'pending' | 'paid'
  earned_via: 'direct' | 'renewal' | 'override' | 'binary_match' | 'matrix_override' | 'stairstep_override' | 'generation_override' | 'promotion_bonus'
  override_source_agent: { id: number; name: string } | null
  /** 2026-09-15 — the payee is the COMPANY itself; nothing is transferred. */
  is_company_share?: boolean
  paid_at: string | null
  created_at: string
}

/**
 * 2026-09-15 — "จ่ายแล้ว", HERE, on the row somebody is looking at.
 *
 * This drill-down used to be strictly read-only, and marking anything paid
 * meant leaving for a second screen in a different menu (see
 * CommissionLedgerPanel's docblock) — so a payout run was: read the totals
 * here, download the file here, then go somewhere else and find the same rows
 * again. That is the whole of what the owner asked to be one place.
 *
 * Reloads BOTH after a success: the row's own status, and the per-agent
 * summary above it, whose "รอจ่าย" total this just changed. Reloading only one
 * leaves the screen disagreeing with itself about the same money.
 */
const markingId = ref<number | null>(null)
const markError = ref('')

async function markEntryPaid(entry: LedgerItem, agent: AgentSummaryItem): Promise<void> {
  if (markingId.value !== null) return

  markingId.value = entry.id
  markError.value = ''
  try {
    await api.post(`/commission-ledger/${entry.id}/mark-paid`)
    await Promise.all([loadAll(), toggleDetail(agent, { keepOpen: true })])
  } catch (e) {
    markError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
  } finally {
    markingId.value = null
  }
}

/**
 * 2026-09-15 — "จ่ายทั้งหมดของคนนี้", the owner's request.
 *
 * A payout run is one person and a page of rows. Settling them one press at a
 * time is a run that can stop halfway, and — because a ledger row cannot be
 * corrected once written (BR-4) — halfway is a state nothing can tidy up
 * afterwards.
 *
 * ── TWO PRESSES, NOT ONE ──
 *
 * The first press only asks. It is the difference between a slip that scrolls
 * past and a slip that pays out forty thousand baht permanently, and the
 * confirm strip names the amount rather than saying "are you sure" — the
 * number is the thing being agreed to.
 *
 * ── THE AMOUNT ON SCREEN IS PART OF THE REQUEST ──
 *
 * `expected_total_satang` is what this row was showing. The server sums the
 * pending rows again and refuses the whole run if they disagree, so a sale
 * that completed while this page sat open cannot be swept into a press the
 * admin made before it existed. A 422 here is that refusal, and its message
 * says to refresh — which is why the server's own wording is shown rather
 * than a generic "บันทึกไม่สำเร็จ".
 */
const confirmPayoutId = ref<number | null>(null)
const payingAgentId = ref<number | null>(null)
const payoutError = ref('')
const payoutDone = ref<{ agent_id: number; count: number; satang: number } | null>(null)

/** Nothing measured, or nothing owed — either way there is no run to offer. */
function canPayAll(agent: AgentSummaryItem): boolean {
  return agent.is_company_share !== true
    && agent.total_pending_satang !== null
    && agent.total_pending_satang > 0
}

function askToPayAll(agent: AgentSummaryItem): void {
  payoutError.value = ''
  payoutDone.value = null
  confirmPayoutId.value = confirmPayoutId.value === agent.agent_id ? null : agent.agent_id
}

async function payAll(agent: AgentSummaryItem): Promise<void> {
  if (payingAgentId.value !== null || agent.total_pending_satang === null) return

  payingAgentId.value = agent.agent_id
  payoutError.value = ''
  try {
    const res = await api.post<{ data: { batch_id: string; paid_count: number; paid_satang: number } }>(
      '/commission-ledger/mark-paid',
      {
        agent_id: agent.agent_id,
        // The SAME range the total above was computed from. Sending one and
        // not the other would have the server settle a different set than the
        // number the admin just agreed to.
        ...(filters.value.date_from ? { date_from: filters.value.date_from } : {}),
        ...(filters.value.date_to ? { date_to: filters.value.date_to } : {}),
        expected_total_satang: agent.total_pending_satang,
      },
    )
    confirmPayoutId.value = null
    payoutDone.value = { agent_id: agent.agent_id, count: res.data.paid_count, satang: res.data.paid_satang }
    await loadAll()
    // Only when the drill-down for THIS person is open: reloading a panel the
    // admin has open for somebody else would swap it under them.
    if (detailAgentId.value === agent.agent_id) {
      await toggleDetail(agent, { keepOpen: true })
    }
  } catch (e) {
    // e.message is Laravel's own field error (see ApiError.extractMessage) —
    // for the 422 above that is the sentence naming both totals and telling
    // the reader to refresh, which no generic copy could replace.
    payoutError.value = e instanceof ApiError ? e.message : 'จ่ายทั้งหมดไม่สำเร็จ'
  } finally {
    payingAgentId.value = null
  }
}

// TASK-047 point 4/5 — agent profile header + avatar/initial-circle,
// from GET /users/{id} (UserResource — same Resource every other Admin
// screen already uses, so no new endpoint needed).
interface AgentDetail {
  id: number
  name: string
  email: string | null
  phone: string | null
  avatar_url: string | null
  created_at: string
  cert_tier: { id: number; key: string; name: string } | null
}

const CERT_TIER_COLORS: Record<string, { bg: string; text: string }> = {
  basic: { bg: 'bg-slate-400', text: 'text-white' },
  intermediate: { bg: 'bg-brand-500', text: 'text-white' },
  high: { bg: 'bg-amber-500', text: 'text-white' },
}
function tierColor(tierKey: string | undefined): { bg: string; text: string } {
  return (tierKey && CERT_TIER_COLORS[tierKey]) || { bg: 'bg-slate-400', text: 'text-white' }
}
function initial(name: string | null | undefined): string {
  return (name ?? '?').trim().charAt(0).toUpperCase() || '?'
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const summaries = ref<AgentSummaryItem[]>([])

/*
 * SPLIT, NOT FILTERED OUT. The company's share is a number an admin wants to
 * see — it is what the ขั้นตอนที่ 4.2 setting earned — it simply is not a
 * payee to transfer to. Dropping it would hide real money; leaving it in the
 * list would put a row with no bank account into a payout file.
 */
const companyShares = computed(() => summaries.value.filter((s) => s.is_company_share === true))
const agentSummaries = computed(() => summaries.value.filter((s) => s.is_company_share !== true))

// ── Filters (TASK-044) ──
const filters = ref({
  date_from: '',
  date_to: '',
  payment_status: '' as '' | 'pending' | 'paid',
})

function buildQuery(): string {
  const params = new URLSearchParams()
  if (filters.value.date_from) params.set('date_from', filters.value.date_from)
  if (filters.value.date_to) params.set('date_to', filters.value.date_to)
  if (filters.value.payment_status) params.set('payment_status', filters.value.payment_status)
  return params.toString()
}

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const query = buildQuery()
    const res = await api.get<{ data: AgentSummaryItem[]; computed_at: string }>(
      // TASK-209 — /agent-commission-summary has accepted company_id
      // server-side all along; it just was never sent.
      activeCompany.scopedPath(`/agent-commission-summary${query ? `?${query}` : ''}`),
    )
    summaries.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)
function applyFilters() {
  loadAll()
}

// ── Bank account (TASK-045, prefill/stay-open behavior changed in
// TASK-047 point 2) — the form now prefills from the agent's REAL
// current bank_account_number (backend no longer masks it here, see
// this file's top docblock). After a successful save the panel stays
// open and shows the newly-saved number instead of closing/hiding, so
// the Admin gets immediate confirmation of what was actually recorded.
const bankEditId = ref<number | null>(null)
const bankForm = ref({ bank_name: '', bank_account_number: '', bank_account_holder_name: '' })
const bankSaving = ref(false)
const bankSavedMessage = ref('')
function openBankEdit(agent: AgentSummaryItem) {
  const opening = bankEditId.value !== agent.agent_id
  bankEditId.value = opening ? agent.agent_id : null
  bankSavedMessage.value = ''
  if (opening) {
    bankForm.value = {
      bank_name: agent.bank_name ?? '',
      bank_account_number: agent.bank_account_number ?? '',
      bank_account_holder_name: agent.bank_account_holder_name ?? '',
    }
  }
}
async function submitBankAccount(agent: AgentSummaryItem) {
  const payload: Record<string, string> = {
    bank_name: bankForm.value.bank_name.trim(),
    bank_account_number: bankForm.value.bank_account_number.trim(),
    bank_account_holder_name: bankForm.value.bank_account_holder_name.trim(),
  }
  bankSaving.value = true
  bankSavedMessage.value = ''
  try {
    await api.put(`/users/${agent.agent_id}`, payload)
    // TASK-047 point 2 — do NOT close/hide the panel after saving; show
    // the just-saved account number in place instead.
    bankSavedMessage.value = `บันทึกสำเร็จ — เลขที่บัญชี ${payload.bank_account_number || '-'}`
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกบัญชีธนาคารไม่สำเร็จ (${e.status})` : 'บันทึกบัญชีธนาคารไม่สำเร็จ'
  } finally {
    bankSaving.value = false
  }
}

// ── Detail drill-down (TASK-046) — "ดูรายละเอียด": which products/
// clients an agent's commission_ledger entries came from, and how each
// was calculated. Independent toggle from bankEditId (an Admin could
// conceivably want both open, though in practice usually one at a
// time) — same per-row-toggle-button pattern as bankEditId itself and
// AgentManagementView.vue's resettingId/movingId.
const detailAgentId = ref<number | null>(null)
const detailLoading = ref(false)
const detailError = ref('')
const detailEntries = ref<LedgerItem[]>([])
const detailTotal = ref(0)
// TASK-047 point 4 — agent's own profile, fetched alongside the ledger
// rows whenever the detail panel opens (GET /users/{id}, UserResource —
// same Resource/endpoint AgentManagementView already uses, no new
// backend surface needed).
const agentDetail = ref<AgentDetail | null>(null)
const agentDetailLoading = ref(false)
/**
 * `keepOpen` — reload the open panel in place rather than toggling it shut.
 *
 * Added 2026-09-15 with the in-panel "จ่ายแล้ว": a write has to refresh the
 * rows it changed, and the toggle semantics would have closed the panel the
 * admin is working in on every single press.
 */
async function toggleDetail(agent: AgentSummaryItem, options: { keepOpen?: boolean } = {}) {
  if (detailAgentId.value === agent.agent_id && options.keepOpen !== true) {
    detailAgentId.value = null
    return
  }
  detailAgentId.value = agent.agent_id
  detailEntries.value = []
  detailError.value = ''
  detailLoading.value = true
  agentDetail.value = null
  agentDetailLoading.value = true
  try {
    const params = new URLSearchParams(buildQuery())
    params.set('agent_id', String(agent.agent_id))
    const [ledgerRes, userRes] = await Promise.all([
      api.get<{ data: LedgerItem[]; meta?: { total: number } }>(activeCompany.scopedPath(`/commission-ledger?${params.toString()}`)),
      api.get<{ data: AgentDetail }>(`/users/${agent.agent_id}`),
    ])
    detailEntries.value = ledgerRes.data
    detailTotal.value = ledgerRes.meta?.total ?? ledgerRes.data.length
    agentDetail.value = userRes.data
  } catch (e) {
    detailError.value = e instanceof ApiError ? `โหลดรายละเอียดไม่สำเร็จ (${e.status})` : 'โหลดรายละเอียดไม่สำเร็จ'
  } finally {
    detailLoading.value = false
    agentDetailLoading.value = false
  }
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

// ── Export CSV (TASK-044 §3) — date range currently applied on screen.
// Goes through api.download() (Sanctum-cookie fetch + blob), never a
// plain <a href>, per Section 5 rule 6 / the file-download convention
// already used by ClientManagementView.vue / ProductEditView.vue (grep
// confirmed: api.download(path, filename?)). No filename passed — the
// CSV has no per-row original_filename, so the server's
// Content-Disposition header (already confirmed to be set on the
// export endpoint) supplies it, same as the product-media download
// call sites.
//
// Human request (2026-07-23): "export ส่ง csv ส่งไปเฉพาะยอดที่ต้องจ่าย" —
// the export endpoint now ALWAYS forces payment_status=pending
// server-side (a payout file only ever needs money still owed — see
// AgentCommissionSummaryController::export()'s own docblock) and no
// longer accepts a payment_status query param at all. filters.payment_
// status is therefore deliberately NOT sent here (unlike the on-screen
// buildQuery() the table itself uses) — sending it would be silently
// ignored server-side and would misleadingly suggest the exported file
// respects the "จ่ายแล้ว/ทั้งหมด" dropdown, which it never does. Only
// the date range filter carries over to the export.
const exporting = ref(false)
async function exportCsv() {
  exporting.value = true
  errorMessage.value = ''
  try {
    const params = new URLSearchParams()
    if (filters.value.date_from) params.set('date_from', filters.value.date_from)
    if (filters.value.date_to) params.set('date_to', filters.value.date_to)
    const query = params.toString()
    await api.download(`/agent-commission-summary/export${query ? `?${query}` : ''}`)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ส่งออก CSV ไม่สำเร็จ (${e.status})` : 'ส่งออก CSV ไม่สำเร็จ'
  } finally {
    exporting.value = false
  }
}

/**
 * TASK-179 §3.7 (F-10) — the wording for a bucket that was never measured.
 *
 * Deliberately NOT a number and NOT "0 บาท": the point of the backend
 * returning null is that the UI must be unable to present an unmeasured
 * bucket as a monetary fact. Every render path for total_paid_satang /
 * total_pending_satang goes through here.
 */
const UNMEASURED_LABEL = 'ไม่ได้แสดง (ถูกกรองออก)'
function formatSatangOrUnmeasured(satang: number | null): string {
  return satang === null ? UNMEASURED_LABEL : formatSatang(satang)
}

/**
 * Sum a bucket across every agent — or report that it was not measured.
 *
 * Returns null the moment ANY row's bucket is null, rather than skipping
 * those rows: the filter excludes the bucket for the whole request, so a
 * partial sum would be a company-wide total assembled from a subset nobody
 * defined. (`.reduce()` with `?? 0` here is precisely the F-10 shape.)
 */
function sumBucket(pick: (s: AgentSummaryItem) => number | null): number | null {
  let total = 0
  for (const s of summaries.value) {
    const value = pick(s)
    if (value === null) return null
    total += value
  }
  return total
}

// Company-wide totals — same "one accent, slate-900 KPI values" rule
// (CLAUDE.md §6.5) as every other HeroHeader on this app.
const kpis = computed(() => [
  { label: 'จ่ายแล้วรวม', value: formatSatangOrUnmeasured(sumBucket((s) => s.total_paid_satang)) },
  { label: 'รอจ่ายรวม', value: formatSatangOrUnmeasured(sumBucket((s) => s.total_pending_satang)) },
  { label: 'จำนวน Agent', value: summaries.value.length },
])

/*
 * WHICH VIEW, AND WHERE THE STARTING ONE COMES FROM.
 *
 * `?view=` names it outright. `?tab=` is the OLD /commission link shape — the
 * dashboard's "ค่าคอมที่จ่ายให้ตัวแทนแล้ว" card still points at it — and a link
 * that names a ledger tab is asking for the ledger, so it lands there and the
 * panel reads its own `?tab=` as it always did. Read once at setup, never
 * watched: this is a starting point somebody handed over, and re-applying it
 * would fight the next click.
 */
const VIEWS = [
  { id: 'agents' as const, label: 'รายตัวแทน', hint: 'สำหรับรอบจ่ายเงิน — ยอดรวมต่อคน บัญชีธนาคาร และไฟล์ CSV' },
  { id: 'entries' as const, label: 'รายรายการ', hint: 'ทุกแถวในบัญชี สำหรับตรวจสอบดีลเดียว' },
]

const view = ref<'agents' | 'entries'>(
  route.query.view === 'entries' || (route.query.view === undefined && typeof route.query.tab === 'string')
    ? 'entries'
    : 'agents',
)

// BR-3 — satang in, baht out. Divide by 100 only here, at the display layer.
function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAll() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="money"
      title="จ่ายเงิน"
      subtitle="ค่าคอมมิชชั่นของบริษัทนี้"
      description="ยอดรวมต่อคน สำหรับรอบจ่ายเงิน · หรือดูทีละรายการเพื่อตรวจสอบดีลเดียว — ตัวเลขทั้งหมดมาจาก Commission Ledger จริง"
      :kpis="view === 'agents' ? kpis : []"
      accent-color="brand"
      storage-key="agent-commission-summary"
    />

    <CompanyScopeNotice action="ดูสรุปคอมมิชชั่น" />

    <!--
      TWO VIEWS OF THE SAME MONEY (2026-09-15).

      Owner: "ค่าคอมมิชชั่นมันกระจายอยู่หลายเมนูมาก ผมอยากรวมเป็น Menu ที่เดียว".

      These were two menu items in two different pillars — "ค่าคอมมิชชั่น" under
      จัดการตัวแทน and "จ่ายคอมมิชชั่น" under Commission — over the same ledger,
      and a payout run needed both: the totals, the bank details and the CSV
      here, the button that actually marks a row paid over there.

      NOT tabs over two data sets. One question asked two ways: รายตัวแทน is
      "who do I pay, and how much", รายรายการ is "why is this number what it
      is". Which is also why they keep separate fetches and filters — a shared
      query would make each of them worse at its own question.
    -->
    <div class="mt-4 flex flex-wrap items-center gap-1" role="group" aria-label="มุมมอง" data-test="payout-view-switch">
      <button
        v-for="v in VIEWS"
        :key="v.id"
        type="button"
        class="px-3.5 py-2 rounded-lg text-[13px] font-bold transition-colors"
        :class="view === v.id ? 'bg-brand-600 text-white' : 'text-slate-500 hover:bg-slate-100'"
        :aria-pressed="view === v.id"
        :data-test="`payout-view-${v.id}`"
        @click="view = v.id"
      >
        {{ v.label }}
      </button>
      <span class="ml-2 text-[12px] text-slate-400">{{ VIEWS.find((v) => v.id === view)?.hint }}</span>
    </div>

    <!-- รายรายการ — the old /commission page, now a view rather than a
         destination. It loads and filters itself; see its own docblock. -->
    <CommissionLedgerPanel v-if="view === 'entries'" />

    <template v-else>
    <div class="mt-4 p-4 rounded-xl bg-white/95 border border-slate-200 flex flex-wrap items-end gap-3">
      <DateRangeFilter v-model:date-from="filters.date_from" v-model:date-to="filters.date_to" :years-back="3" :years-forward="0" />
      <div>
        <label class="block text-xs font-bold text-slate-500 mb-1">สถานะการจ่าย</label>
        <select v-model="filters.payment_status" class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white min-w-[10rem]">
          <option value="">ทั้งหมด</option>
          <option value="paid">จ่ายแล้ว</option>
          <option value="pending">ค้างจ่าย</option>
        </select>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 text-white font-bold hover:bg-brand-700 shadow-sm text-sm whitespace-nowrap"
        @click="applyFilters"
      >
        <Icon name="filter" :size="16" />
        กรอง
      </button>
      <button
        type="button"
        :disabled="exporting"
        title="ไฟล์สำหรับโอนจ่ายจริง — มีเฉพาะยอดค้างจ่ายเท่านั้น ไม่รวมรายการที่จ่ายแล้ว"
        class="flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 text-slate-700 font-bold hover:bg-slate-50 shadow-sm text-sm whitespace-nowrap disabled:opacity-50"
        @click="exportCsv"
      >
        <Icon name="download" :size="16" />
        {{ exporting ? 'กำลังส่งออก...' : 'ส่งออก CSV' }}
      </button>
    </div>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!summaries.length" icon="money" title="ยังไม่มีข้อมูลคอมมิชชั่น" class="mt-4" />
      <template v-else>
        <!--
          THE COMPANY'S OWN SHARE, ABOVE THE PEOPLE (2026-09-15).

          Same numbers, deliberately not the same row shape: no avatar, no
          bank fields, no drill-down. Everything this screen does to an agent
          row is a step toward transferring money to them, and none of it
          applies to money the company already holds.
        -->
        <div
          v-for="c in companyShares"
          :key="'company-' + c.agent_id"
          class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/50 px-4 py-3 flex flex-wrap items-center gap-x-5 gap-y-2"
          :data-test="`company-share-row-${c.agent_id}`"
        >
          <div class="min-w-0">
            <p class="text-[11px] font-extrabold text-emerald-700">ส่วนของบริษัท</p>
            <p class="text-sm font-bold text-slate-900 truncate">{{ c.agent_name ?? '—' }}</p>
          </div>
          <p class="text-xs text-slate-500">{{ c.entry_count }} รายการ</p>
          <div class="ml-auto text-right">
            <p class="text-xs text-slate-400">รวม</p>
            <p class="text-sm font-bold text-emerald-700 tabular-nums">
              {{ formatSatang((c.total_paid_satang ?? 0) + (c.total_pending_satang ?? 0)) }}
            </p>
          </div>
          <p class="w-full text-[11.5px] text-slate-500">
            เงินก้อนนี้อยู่กับบริษัทอยู่แล้ว ไม่ต้องโอน และไม่อยู่ในไฟล์จ่ายเงิน
          </p>
        </div>

      <div class="space-y-2 mt-4">
        <div
          v-for="s in agentSummaries"
          :key="s.agent_id"
          class="bg-white/95 border border-slate-200 rounded-xl p-4 hover:shadow-sm transition-shadow"
        >
          <div class="flex items-center justify-between">
            <div class="flex items-start gap-3 min-w-0">
              <!-- TASK-047 follow-up — avatar/tier-colored initial-circle
                   shown directly on the row, no click required. -->
              <img
                v-if="s.avatar_url"
                :src="s.avatar_url"
                alt=""
                class="w-9 h-9 rounded-full object-cover shrink-0"
              />
              <div
                v-else
                class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold shrink-0"
                :class="[tierColor(s.cert_tier?.key).bg, tierColor(s.cert_tier?.key).text]"
              >
                {{ initial(s.agent_name) }}
              </div>
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 truncate">{{ s.agent_name ?? '—' }}</p>
                <p class="text-xs text-slate-400">{{ s.entry_count }} รายการ</p>
              </div>
            </div>
            <div class="flex items-center gap-4 shrink-0">
              <!-- §3.7 (F-10) — a bucket the payment-status filter excluded
                   renders as "ไม่ได้แสดง", in neutral slate, NOT as a
                   confident coloured "0 บาท". -->
              <div class="text-right">
                <p class="text-xs text-slate-400">จ่ายแล้ว</p>
                <p
                  class="text-sm font-bold"
                  :class="s.total_paid_satang === null ? 'text-slate-400 font-normal' : 'text-emerald-600'"
                >{{ formatSatangOrUnmeasured(s.total_paid_satang) }}</p>
              </div>
              <div class="text-right">
                <p class="text-xs text-slate-400">รอจ่าย</p>
                <p
                  class="text-sm font-bold"
                  :class="s.total_pending_satang === null ? 'text-slate-400 font-normal' : 'text-amber-600'"
                >{{ formatSatangOrUnmeasured(s.total_pending_satang) }}</p>
              </div>
              <!-- TASK-047 point 1 — real bordered buttons, not plain text links. -->
              <button
                type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 text-xs font-bold hover:bg-slate-50 whitespace-nowrap"
                @click="openBankEdit(s)"
              >
                <Icon name="credit_card" :size="14" />
                บัญชีธนาคาร
              </button>
              <button
                type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 text-xs font-bold hover:bg-slate-50 whitespace-nowrap"
                @click="toggleDetail(s)"
              >
                <Icon name="list" :size="14" />
                ดูรายละเอียด
              </button>
              <!--
                จ่ายทั้งหมดของคนนี้ (2026-09-15). Hidden, not disabled, when
                there is nothing owed or the filter excluded the bucket: a
                greyed-out payout button invites a click that would have to
                explain itself, and "ไม่ได้แสดง (ถูกกรองออก)" is not an amount
                anybody can agree to pay.
              -->
              <button
                v-if="canPayAll(s)"
                type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 whitespace-nowrap disabled:opacity-50"
                :disabled="payingAgentId !== null"
                :data-test="`pay-all-${s.agent_id}`"
                @click="askToPayAll(s)"
              >
                <Icon name="money" :size="14" />
                จ่ายทั้งหมด
              </button>
            </div>
          </div>

          <!--
            THE CONFIRM STRIP — the second press.

            It names the AMOUNT, not "are you sure". The number is what is
            being agreed to, and a ledger row cannot be corrected once written
            (BR-4), so this is the last moment at which the figure can be
            checked against the bank file.
          -->
          <div
            v-if="confirmPayoutId === s.agent_id"
            class="mt-3 pt-3 border-t border-slate-100"
            :data-test="`pay-all-confirm-${s.agent_id}`"
          >
            <p class="text-[12.5px] text-slate-700">
              บันทึกว่าจ่ายค่าคอมทั้งหมดของ <span class="font-bold">{{ s.agent_name ?? '—' }}</span>
              เป็นเงิน <span class="font-bold tabular-nums">{{ formatSatangOrUnmeasured(s.total_pending_satang) }}</span>
              ({{ s.entry_count }} รายการในช่วงที่กรองอยู่)
            </p>
            <p class="mt-1 text-[11.5px] text-slate-500">
              เป็นการบันทึกว่าโอนเงินแล้ว ระบบไม่ได้โอนเงินให้ และแก้ไขย้อนหลังไม่ได้
            </p>
            <div class="mt-2 flex items-center gap-2">
              <button
                type="button"
                class="px-3.5 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 disabled:opacity-50"
                :disabled="payingAgentId !== null"
                :data-test="`pay-all-submit-${s.agent_id}`"
                @click="payAll(s)"
              >
                {{ payingAgentId === s.agent_id ? 'กำลังบันทึก…' : 'ยืนยันจ่ายทั้งหมด' }}
              </button>
              <button
                type="button"
                class="px-3.5 py-1.5 rounded-lg border border-slate-200 text-slate-600 text-xs font-bold hover:bg-slate-50"
                :disabled="payingAgentId !== null"
                @click="confirmPayoutId = null"
              >
                ยกเลิก
              </button>
            </div>
            <p v-if="payoutError" class="mt-2 text-[12px] font-bold text-rose-600" :data-test="`pay-all-error-${s.agent_id}`">
              {{ payoutError }}
            </p>
          </div>

          <p
            v-if="payoutDone && payoutDone.agent_id === s.agent_id"
            class="mt-2 text-[12px] font-bold text-emerald-600"
            :data-test="`pay-all-done-${s.agent_id}`"
          >
            บันทึกแล้ว {{ payoutDone.count }} รายการ รวม {{ formatSatang(payoutDone.satang) }}
          </p>
          <div v-if="bankEditId === s.agent_id" class="mt-3 pt-3 border-t border-slate-100">
            <p v-if="bankSavedMessage" class="text-xs font-bold text-emerald-600 mb-2">{{ bankSavedMessage }}</p>
            <p v-else class="text-xs text-slate-400 mb-2">
              ปัจจุบัน: ธนาคาร {{ s.bank_name || '-' }} · เลขบัญชี {{ s.bank_account_number || '-' }} · ชื่อบัญชี {{ s.bank_account_holder_name || '-' }}
              — แก้ไขช่องที่ต้องการเปลี่ยนแล้วกดบันทึก
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <input
                v-model="bankForm.bank_name"
                type="text"
                placeholder="ธนาคาร"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
              />
              <input
                v-model="bankForm.bank_account_number"
                type="text"
                inputmode="numeric"
                placeholder="เลขที่บัญชี"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
              />
              <input
                v-model="bankForm.bank_account_holder_name"
                type="text"
                placeholder="ชื่อบัญชี"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
              />
            </div>
            <button
              type="button"
              :disabled="bankSaving"
              class="mt-2 btn-primary"
              @click="submitBankAccount(s)"
            >
              {{ bankSaving ? 'กำลังบันทึก...' : 'บันทึกบัญชีธนาคาร' }}
            </button>
          </div>

          <!-- TASK-046/TASK-047 — commission detail drill-down: agent
               profile (point 4) above a fixed-column <table> (point 3)
               of products sold, clients bought, and the stored
               calculation snapshot per entry (never a derived/invented
               number, see this file's top docblock). -->
          <div v-if="detailAgentId === s.agent_id" class="mt-3 pt-3 border-t border-slate-100">
            <!-- Point 4 — agent's own personal details, above the table.
                 Avatar + name are already shown on the row above (TASK-047
                 follow-up — human feedback: "นำชื่อออกจากดูรายละเอียดซ้ำซ้อน"),
                 so only the SUPPLEMENTARY info (email/phone/join date/cert
                 tier) is shown here, not repeated. -->
            <div v-if="agentDetailLoading" class="text-xs text-slate-400 mb-3">กำลังโหลดข้อมูลตัวแทน...</div>
            <div v-else-if="agentDetail" class="flex items-center gap-2 mb-3 pb-3 border-b border-slate-100 text-xs text-slate-500">
              <span>{{ agentDetail.email || '—' }}</span>
              <span v-if="agentDetail.phone">· {{ agentDetail.phone }}</span>
              <span>· เข้าร่วมเมื่อ {{ formatDate(agentDetail.created_at) }}</span>
              <span
                class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold"
                :class="[tierColor(agentDetail.cert_tier?.key).bg, tierColor(agentDetail.cert_tier?.key).text]"
              >
                {{ agentDetail.cert_tier?.name ?? 'ยังไม่ผ่านเกณฑ์' }}
              </span>
            </div>

            <!-- OUTSIDE the load/empty chain below, not inside it: a failed
                 "จ่ายแล้ว" is about the press that just happened, and slipping
                 it into that v-if chain silently turned "no entries" into
                 "else if the last write failed". -->
            <p v-if="markError" class="mb-2 text-[12px] font-bold text-rose-600" data-test="detail-mark-error">{{ markError }}</p>

            <div v-if="detailLoading" class="text-xs text-slate-400">กำลังโหลด...</div>
            <div v-else-if="detailError" class="text-xs text-rose-600">{{ detailError }}</div>
            <div v-else-if="!detailEntries.length" class="text-xs text-slate-400">ไม่มีรายการคอมมิชชั่น</div>
            <div v-else class="overflow-x-auto">
              <table class="w-full text-xs">
                <thead>
                  <tr class="text-left text-slate-400 border-b border-slate-100">
                    <th class="py-1.5 pr-3 font-bold">วันที่ขาย</th>
                    <th class="py-1.5 pr-3 font-bold">ชื่อลูกค้า</th>
                    <th class="py-1.5 pr-3 font-bold">ชื่อสินค้า</th>
                    <th class="py-1.5 pr-3 font-bold text-right">ราคาที่ขายได้</th>
                    <th class="py-1.5 pr-3 font-bold">โปรโมชั่น</th>
                    <th class="py-1.5 pr-3 font-bold text-right">ค่าคอม</th>
                    <th class="py-1.5 font-bold">สถานะ</th>
                    <th class="py-1.5 font-bold text-right"><span class="sr-only">จ่ายเงิน</span></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="e in detailEntries" :key="e.id" class="border-b border-slate-50 last:border-0">
                    <td class="py-2 pr-3 text-slate-500 whitespace-nowrap">{{ formatDate(e.created_at) }}</td>
                    <td class="py-2 pr-3 text-slate-700 font-bold">{{ e.referral?.client?.name ?? '—' }}</td>
                    <td class="py-2 pr-3 text-slate-500">{{ e.product?.name ?? '—' }}</td>
                    <td class="py-2 pr-3 text-slate-700 text-right whitespace-nowrap">
                      {{ e.sale_price_satang_at_time !== null ? formatSatang(e.sale_price_satang_at_time) : '—' }}
                    </td>
                    <td class="py-2 pr-3 text-slate-500">
                      {{ e.applied_price_promotion ? (e.applied_price_promotion.note || formatSatang(e.applied_price_promotion.discounted_price_satang)) : '—' }}
                    </td>
                    <td class="py-2 pr-3 text-slate-900 font-bold text-right whitespace-nowrap">{{ formatSatang(e.amount_satang) }}</td>
                    <td class="py-2 whitespace-nowrap">
                      <span
                        class="text-[10px] font-bold px-1.5 py-0.5 rounded"
                        :class="e.payment_status === 'paid' ? 'text-emerald-600 bg-emerald-50' : 'text-amber-600 bg-amber-50'"
                      >
                        {{ e.payment_status === 'paid' ? 'จ่ายแล้ว' : 'รอจ่าย' }}
                      </span>
                    </td>
                    <!--
                      The action that used to live on a different menu. Same
                      two exclusions as the ledger panel: an already-paid row
                      has nothing to do, and the company's own share is money
                      that never moves, so there is no transfer to record.
                    -->
                    <td class="py-2 text-right whitespace-nowrap">
                      <span v-if="e.is_company_share" class="text-[10px] text-slate-400">ไม่ต้องโอน</span>
                      <button
                        v-else-if="e.payment_status === 'pending'"
                        type="button"
                        class="px-2.5 py-1 rounded-lg bg-brand-600 text-white text-[11px] font-bold hover:bg-brand-700 disabled:opacity-50"
                        :disabled="markingId !== null"
                        :data-test="`detail-mark-paid-${e.id}`"
                        @click="markEntryPaid(e, s)"
                      >
                        {{ markingId === e.id ? 'กำลังบันทึก…' : 'จ่ายแล้ว' }}
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <p v-if="detailTotal > detailEntries.length" class="text-[11px] text-slate-400 pt-2">
                แสดง {{ detailEntries.length }} จาก {{ detailTotal }} รายการทั้งหมด
              </p>
            </div>
          </div>
        </div>
      </div>
      </template>
    </template>
    </template>
  </main>
</template>
