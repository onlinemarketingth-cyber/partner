import { computed, ref } from 'vue'
import { api, ApiError } from '@/api/client'

/**
 * Everything the client file KNOWS, separated from how it is drawn.
 *
 * ── WHY THIS EXISTS (2026-08-22) ──
 *
 * The client detail is now rendered by two surfaces: ClientFileView (the
 * full page, still the deep-link target from SalesTeamView) and
 * ClientDetailModal (opened from the client list, so an admin can check
 * several people without navigating back each time — the reported
 * complaint).
 *
 * Two surfaces means the loading, the derivations and the formatting are
 * about to exist twice. That is precisely the shape of the bug fixed
 * earlier the same day in the notification resolver: two copies of one rule,
 * one of which nobody updated. So the rules live here once, and each surface
 * owns only its own layout.
 *
 * What is deliberately NOT here: markup, modal state, edit-form state. A
 * composable that knows it is sometimes a modal is not a composable.
 */

export interface ReferralOrder {
  id: number
  order_number: string
  status: string
  status_label: string
  amount_satang: number
  public_pay_url: string | null
  has_slip: boolean
  paid_at: string | null
  verified_by: { id: number; name: string } | null
}

export interface ReferralItem {
  id: number
  product: { id: number; name: string; price_satang: number } | null
  agent: { id: number; name: string } | null
  /*
   * TASK-174 — OPTIONAL, not merely nullable. ReferralResource OMITS both
   * keys while the company's commission split is switched off (it does not
   * null them), so `undefined` here IS the server's answer and every
   * `v-if="r.co_agent"` simply does not render. Declaring these `| null`
   * would tell TS a missing field is a real value, which is how a stale
   * split gets rendered as "แบ่ง undefined%".
   */
  co_agent?: { id: number; name: string } | null
  split_percentage?: number | null
  branch: string
  preferred_time: string | null
  current_stage: { key: string; label: string }
  /*
   * The sales journey for THIS referral, and the stage it is waiting to
   * reach. Same optionality reasoning as co_agent: absent when the resource
   * did not compute it, which must render as "no answer" rather than as a
   * fabricated next step.
   */
  pipeline?: {
    stages: Array<{ key: string; label: string }>
    next_stage: { key: string; label: string } | null
  } | null
  /*
   * ABSENT unless the endpoint eager-loaded `referrals.orders`. This was the
   * whole reason an admin could not tell whether a customer had paid: the
   * block existed in ReferralResource and the relation was never loaded, so
   * the key never appeared. `undefined` = nobody asked; `null` = asked, and
   * this referral has no actionable order.
   */
  order?: ReferralOrder | null
  meeting_number: number | null
  submitted_at: string
}

export interface ClientDetail {
  id: number
  company_id?: number
  referring_agent_id: number
  name: string
  phone: string
  email: string | null
  national_id_masked: string | null
  /** Only present for privileged viewers (Section 6) — may be absent. */
  national_id?: string | null
  consent_given_at: string | null
  health_notes: string | null
  status: { key: string; label: string }
  lead_source: string | null
  client_category_id: number | null
  client_category_name?: string | null
  date_of_birth: string | null
  address: string | null
  province: string | null
  occupation: string | null
  referrals: ReferralItem[]
  created_at: string
}

export interface ClientDocumentItem {
  id: number
  original_filename: string
  size_bytes: number
}

export interface ClientActivityItem {
  id: number
  logged_by_name: string
  type: { key: string; label: string }
  summary: string
  occurred_at: string
  follow_up_at: string | null
}

export interface StageLogItem {
  id: number
  from_stage: { key: string; label: string } | null
  to_stage: { key: string; label: string }
  changed_by: { id: number; name: string } | null
  changed_at: string
}

export interface RelatedAgent {
  id: number
  name: string
  isReferring: boolean
  roles: string[]
}

/**
 * The one-line answer to "what is happening with this person right now?"
 *
 * Derived rather than stored, and derived HERE rather than in a template,
 * because the reasoning is the feature: an admin looking at a customer wants
 * to know where they are, whether the money arrived, and who owes the next
 * move — and until now had to infer all three from a stage label.
 */
export interface ReferralSummary {
  referral: ReferralItem
  /** Where the deal is. */
  stageLabel: string
  /** What has to happen next, or null when the journey is finished. */
  nextLabel: string | null
  /**
   * Payment, reduced to the four states that change what somebody DOES:
   *   'paid'      — money confirmed, nothing to chase
   *   'checking'  — customer sent a slip, an agent has to verify it
   *   'awaiting'  — an order exists and is unpaid
   *   'no_order'  — no order yet; too early to talk about payment
   */
  payment: 'paid' | 'checking' | 'awaiting' | 'no_order'
  paymentLabel: string
  amountSatang: number | null
  /** Who is being waited on. The actual "รอทำอะไร" answer. */
  waitingOn: string
}

export function useClientFile() {
  const loading = ref(false)
  const hasLoadedOnce = ref(false)
  const errorMessage = ref('')
  const client = ref<ClientDetail | null>(null)
  const documents = ref<ClientDocumentItem[]>([])
  const activities = ref<ClientActivityItem[]>([])

  const expandedReferralId = ref<number | null>(null)
  const stageLogsByReferral = ref<Record<number, StageLogItem[]>>({})
  const loadingLogsFor = ref<number | null>(null)

  function reset(): void {
    client.value = null
    documents.value = []
    activities.value = []
    errorMessage.value = ''
    hasLoadedOnce.value = false
    // Cached timelines belong to the PREVIOUS client. Keeping them would
    // show one customer's sales history under another's name — the worst
    // failure this screen could have.
    expandedReferralId.value = null
    stageLogsByReferral.value = {}
  }

  async function load(clientId: number): Promise<void> {
    loading.value = true
    errorMessage.value = ''
    try {
      const [c, docs, acts] = await Promise.all([
        api.get<{ data: ClientDetail }>(`/clients/${clientId}`),
        api.get<{ data: ClientDocumentItem[] }>(`/clients/${clientId}/documents`),
        api.get<{ data: ClientActivityItem[] }>(`/clients/${clientId}/activities`),
      ])
      client.value = c.data
      documents.value = docs.data
      activities.value = acts.data
    } catch (e) {
      errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
    } finally {
      loading.value = false
      hasLoadedOnce.value = true
    }
  }

  async function toggleStageLogs(referralId: number): Promise<void> {
    if (expandedReferralId.value === referralId) {
      expandedReferralId.value = null

      return
    }
    expandedReferralId.value = referralId
    if (stageLogsByReferral.value[referralId]) return

    loadingLogsFor.value = referralId
    try {
      const res = await api.get<{ data: StageLogItem[] }>(`/referrals/${referralId}/stage-logs`)
      stageLogsByReferral.value[referralId] = res.data
    } catch (e) {
      errorMessage.value = e instanceof ApiError ? `โหลดประวัติไม่สำเร็จ (${e.status})` : 'โหลดประวัติไม่สำเร็จ'
    } finally {
      loadingLogsFor.value = null
    }
  }

  /**
   * Distinct agents touching this client: every referral's selling agent +
   * co-agent, plus the referring agent, deduped by id. Visually answers
   * "1 ลูกค้า มีหลาย agent". The referring agent has no name in the payload
   * beyond an id, so it shows as "#id" unless that same person also appears
   * as a seller or co-seller on some referral.
   */
  const relatedAgents = computed<RelatedAgent[]>(() => {
    const c = client.value
    if (!c) return []

    const byId = new Map<number, RelatedAgent>()
    const add = (id: number, name: string, role: string) => {
      const existing = byId.get(id)
      if (existing) {
        if (!existing.roles.includes(role)) existing.roles.push(role)
      } else {
        byId.set(id, { id, name, isReferring: false, roles: [role] })
      }
    }

    for (const r of c.referrals) {
      if (r.agent) add(r.agent.id, r.agent.name, 'ผู้ขาย')
      if (r.co_agent) add(r.co_agent.id, r.co_agent.name, 'ผู้ขายร่วม')
    }

    const referring = byId.get(c.referring_agent_id)
    if (referring) {
      referring.isReferring = true
      if (!referring.roles.includes('ผู้แนะนำ')) referring.roles.push('ผู้แนะนำ')
    } else {
      byId.set(c.referring_agent_id, {
        id: c.referring_agent_id,
        name: `#${c.referring_agent_id}`,
        isReferring: true,
        roles: ['ผู้แนะนำ'],
      })
    }

    return [...byId.values()]
  })

  const nationalIdDisplay = computed(() => {
    const c = client.value
    if (!c) return 'ยังไม่ระบุ'

    return c.national_id ?? c.national_id_masked ?? 'ยังไม่ระบุ'
  })

  /**
   * One row per deal, answering stage / paid / waiting-on.
   *
   * `order === undefined` and `order === null` are kept apart on purpose.
   * Undefined means the endpoint did not load orders at all — the state this
   * whole change exists to eliminate — and must NOT be reported as "not paid
   * yet", which would be a confident wrong answer. It reports "ไม่ทราบ"
   * instead.
   */
  const referralSummaries = computed<ReferralSummary[]>(() =>
    (client.value?.referrals ?? []).map((referral) => {
      const order = referral.order
      const nextLabel = referral.pipeline?.next_stage?.label ?? null

      let payment: ReferralSummary['payment'] = 'no_order'
      let paymentLabel = 'ยังไม่มีคำสั่งซื้อ'
      let waitingOn = nextLabel ? `รอ: ${nextLabel}` : 'จบขั้นตอนแล้ว'

      if (order === undefined) {
        paymentLabel = 'ไม่ทราบสถานะการชำระเงิน'
      } else if (order === null) {
        paymentLabel = 'ยังไม่มีคำสั่งซื้อ'
      } else if (order.paid_at !== null) {
        payment = 'paid'
        paymentLabel = 'ชำระแล้ว'
      } else if (order.has_slip) {
        payment = 'checking'
        paymentLabel = 'แนบสลิปแล้ว รอตรวจสอบ'
        // A slip on an unpaid order is somebody's job, and it outranks the
        // pipeline's next stage: the deal cannot move until an agent looks.
        waitingOn = 'รอ Agent ตรวจสอบสลิป'
      } else {
        payment = 'awaiting'
        paymentLabel = 'รอชำระเงิน'
        waitingOn = 'รอลูกค้าชำระเงิน'
      }

      return {
        referral,
        stageLabel: referral.current_stage.label,
        nextLabel,
        payment,
        paymentLabel,
        amountSatang: order?.amount_satang ?? referral.product?.price_satang ?? null,
        waitingOn,
      }
    }),
  )

  return {
    loading,
    hasLoadedOnce,
    errorMessage,
    client,
    documents,
    activities,
    expandedReferralId,
    stageLogsByReferral,
    loadingLogsFor,
    relatedAgents,
    nationalIdDisplay,
    referralSummaries,
    load,
    reset,
    toggleStageLogs,
  }
}

/* ── Formatting ─────────────────────────────────────────────────────────
 * Plain functions, not part of the composable: they hold no state and both
 * surfaces plus the edit form need them. */

export function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export function formatDateTime(iso: string | null): string {
  if (!iso) return 'ยังไม่ระบุ'

  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

export function formatDate(iso: string | null): string {
  if (!iso) return 'ยังไม่ระบุ'

  return new Date(iso).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' })
}

/** BR-3: amounts are integer satang — divided by 100 only for display. */
export function formatMoney(satang: number): string {
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function statusBadgeClasses(statusKey: string): string {
  switch (statusKey) {
    case 'interested':
      return 'bg-emerald-50 text-emerald-700'
    case 'not_interested':
      return 'bg-rose-50 text-rose-600'
    case 'contacted':
      return 'bg-brand-50 text-brand-700'
    default:
      return 'bg-slate-100 text-slate-600'
  }
}

export function paymentBadgeClasses(payment: ReferralSummary['payment']): string {
  switch (payment) {
    case 'paid':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'checking':
      // Amber, not green: a slip is a CLAIM of payment, and rendering it as
      // settled is how an unverified transfer gets treated as money in.
      return 'bg-amber-50 text-amber-700 border-amber-200'
    case 'awaiting':
      return 'bg-rose-50 text-rose-600 border-rose-200'
    default:
      return 'bg-slate-100 text-slate-600 border-slate-200'
  }
}
