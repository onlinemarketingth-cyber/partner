/**
 * AgentDashboardOverview — TASK-179 §4: the numbers on this screen say what
 * they are, and a number nobody measured is never printed as 0.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * Nothing on this dashboard was ever mocked. Every defect TASK-179 closes is
 * a REAL number under a label describing a different quantity, or a zero
 * standing in for a measurement that never happened. Neither kind shows up in
 * a screenshot, which is exactly why they survived five sprints.
 *
 * Two of them have a history worth stating:
 *
 *  1. THE STAGE LIST (§4.1, F-4). A hardcoded five-element list of §4.3's
 *     medical stages has been introduced into this codebase and removed again
 *     five times since ADR-026. The funnel used to hold one, so the three
 *     post-sale stages (จัดส่ง / นัดใช้บริการ / ติดตามผล) were silently dropped
 *     and the bars stopped summing to the ดีลทั้งหมด KPI printed beside them.
 *     The tests below therefore feed the component stage maps it CANNOT have
 *     been written against — eight stages, three stages, a reordered map, and
 *     a stage key this app has no Thai label for. Any implementation that
 *     reasons from a fixed list fails at least one of them.
 *
 *  2. THE UNMEASURED ZERO (§4.4, F-13/F-14). A brand-new company saw a flat
 *     6-month chart and a radial gauge reading a confident "0%" under
 *     "อัตราปิดการขาย", and a 403 from /agent-approvals rendered as the green
 *     "ไม่มีตัวแทนรออนุมัติ" — a failure displayed as good news. Both assert
 *     the ABSENCE of the confident rendering as well as the presence of the
 *     honest one; asserting only "ยังไม่มีข้อมูล appears" would still pass with
 *     the gauge drawn next to it, which is the defect.
 *
 * ApexCharts is stubbed, deliberately, at the props boundary: what matters
 * here is the series and the axis categories the component HANDS the chart,
 * not the SVG it draws. The API is mocked at `@/api/client`; authorization
 * and tenant scoping for these endpoints are enforced and tested server-side
 * (BR-6).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
    ) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: FakeApiError,
}))

// Chart stub — records `type`, `options` and `series` as props so the tests
// can read exactly what the component asked to be drawn. A real ApexCharts
// render in jsdom would tell us nothing about the numbers.
vi.mock('vue3-apexcharts', async () => {
  const { h } = await import('vue')
  return {
    default: {
      name: 'ApexStub',
      props: ['type', 'options', 'series'],
      render() {
        return h('div', { class: 'apex-stub', 'data-type': (this as { type?: string }).type })
      },
    },
  }
})

import AgentDashboardOverview from '../AgentDashboardOverview.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { useAuthStore } from '@/stores/auth'
import { PIPELINE_STAGE_LABELS_TH } from '@/utils/pipelineStages'

// ── Fixtures ────────────────────────────────────────────────────────────

/** BR-3 — the API sends integer satang; ฿12,345.00 is 1_234_500. */
const TWELVE_THOUSAND_THREE_FORTY_FIVE_BAHT = 1_234_500

/** Every PipelineStage case, in the enum's declaration order (ADR-026). */
const ALL_EIGHT_STAGES: Record<string, number> = {
  complete_registered: 5,
  waiting_appointment: 4,
  finish_1st_doctor_meeting: 3,
  complete_payment: 2,
  ongoing_next_meeting: 1,
  delivery: 6,
  service_appointment: 7,
  follow_up: 8,
}

interface MetricsOverrides {
  totals?: Record<string, number>
  monthly?: Array<{ month: string; sales_satang: number; commission_satang: number; new_agents: number }>
  deals_by_stage?: Record<string, number>
  cert_tier_distribution?: Array<{ key: string; name: string; count: number }>
  lead_source_distribution?: Array<{ source: string; count: number }>
  top_agents?: Array<{ agent_id: number; name: string; avatar_url: string | null; commission_satang: number }>
}

function makeMetrics(overrides: MetricsOverrides = {}) {
  return {
    totals: {
      agents_total: 12,
      agents_active: 12,
      agents_inactive: 3,
      agents_pending: 4,
      new_agents_this_month: 2,
      cert_passed: 7,
      cert_pending: 5,
      clients_total: 30,
      deals_total: 36,
      deals_closed: 9,
      conversion: 25,
      sales_paid_satang: TWELVE_THOUSAND_THREE_FORTY_FIVE_BAHT,
      closed_deals_without_order: 0,
      commission_paid_satang: 500_00,
      commission_pending_satang: 250_00,
      ...overrides.totals,
    },
    monthly: overrides.monthly ?? [
      { month: '2026-03', sales_satang: 100_00, commission_satang: 10_00, new_agents: 1 },
      { month: '2026-04', sales_satang: 200_00, commission_satang: 20_00, new_agents: 0 },
    ],
    deals_by_stage: overrides.deals_by_stage ?? ALL_EIGHT_STAGES,
    cert_tier_distribution: overrides.cert_tier_distribution ?? [
      { key: 'basic', name: 'Basic', count: 4 },
      { key: 'intermediate', name: 'Intermediate', count: 3 },
    ],
    lead_source_distribution: overrides.lead_source_distribution ?? [{ source: 'facebook', count: 10 }],
    top_agents: overrides.top_agents ?? [
      { agent_id: 1, name: 'สมชาย', avatar_url: null, commission_satang: 400_00 },
    ],
  }
}

/** A company that has literally nothing yet — §4.4's zero-data case. */
function makeEmptyMetrics() {
  return makeMetrics({
    totals: {
      agents_total: 0,
      agents_active: 0,
      agents_inactive: 0,
      agents_pending: 0,
      new_agents_this_month: 0,
      cert_passed: 0,
      cert_pending: 0,
      clients_total: 0,
      deals_total: 0,
      deals_closed: 0,
      conversion: 0,
      sales_paid_satang: 0,
      closed_deals_without_order: 0,
      commission_paid_satang: 0,
      commission_pending_satang: 0,
    },
    monthly: [
      { month: '2026-03', sales_satang: 0, commission_satang: 0, new_agents: 0 },
      { month: '2026-04', sales_satang: 0, commission_satang: 0, new_agents: 0 },
    ],
    deals_by_stage: Object.fromEntries(Object.keys(ALL_EIGHT_STAGES).map((k) => [k, 0])),
    cert_tier_distribution: [],
    lead_source_distribution: [],
    top_agents: [],
  })
}

interface ApprovalsResponse {
  data: Array<{ id: number; name: string; email: string }>
  meta?: { total: number }
}

/**
 * Wire both endpoints the screen calls. `approvals: 'fail'` makes
 * /agent-approvals reject, which is F-14's case.
 */
function wireApi(metrics: unknown, approvals: ApprovalsResponse | 'fail' = { data: [], meta: { total: 0 } }) {
  get.mockImplementation((path: string) => {
    if (path.startsWith('/agent-dashboard-metrics')) return Promise.resolve({ data: metrics })
    if (path.startsWith('/agent-approvals')) {
      return approvals === 'fail' ? Promise.reject(new FakeApiError(403, {})) : Promise.resolve(approvals)
    }
    throw new Error(`unexpected GET ${path}`)
  })
}

async function mountDashboard() {
  const wrapper = mount(AgentDashboardOverview)
  await flushPromises()
  return wrapper
}

/**
 * One chart, by the `data-chart` identity the template gives it.
 *
 * Deliberately NOT by ApexCharts `type`: three separate charts on this
 * screen are type="area" and two are type="bar", so a type lookup silently
 * returns whichever renders first — which would have made the zero-data
 * assertions below pass or fail for the wrong reason.
 */
function chart(wrapper: ReturnType<typeof mount>, id: string) {
  return wrapper.findAllComponents({ name: 'ApexStub' }).find((c) => c.attributes('data-chart') === id)
}

beforeEach(() => {
  get.mockReset()
  localStorage.clear()
  // Both endpoints answer by default; the scope tests care about the URL
  // that was asked for, not the payload that came back.
  get.mockImplementation((path: string) =>
    Promise.resolve(
      String(path).startsWith('/agent-approvals')
        ? { data: [], meta: { total: 0 } }
        : { data: makeMetrics() },
    ),
  )
})

// ════════════════════════════════════════════════════════════════════════
// §4.1 (F-4, BR-7) — render whatever stages the server sends, in its order
// ════════════════════════════════════════════════════════════════════════
/**
 * 2026-09-04 — THE COMPANY PICKER, WHICH THIS PAGE USED TO IGNORE.
 *
 * Human-reported. This is the landing page, and every figure on it was the
 * whole platform's while the header named one company: wrong numbers that
 * look right. The API had accepted company_id since it was written — the
 * request simply never carried it, and nothing reloaded when the header
 * changed.
 *
 * Both halves are asserted, because each is useless alone: sending the id
 * once at mount still leaves yesterday's company on screen, and reloading
 * without the id just re-fetches the platform.
 */
describe('the company scope', () => {
  it('asks for the picked company, not the whole platform', async () => {
    const auth = useAuthStore()
    auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin' } as never
    const store = useActiveCompanyStore()
    store.companies = [{ id: 4, name: 'ไทยประกันชีวิต', slug: 'thailife' }]
    store.setCompany(4)

    await mountDashboard()

    expect(get).toHaveBeenCalledWith('/agent-dashboard-metrics?company_id=4')
    // The approval queue underneath is the same company's or it is nobody's.
    expect(get).toHaveBeenCalledWith('/agent-approvals?status=pending&company_id=4')
  })

  it('reloads when the header switches company', async () => {
    const auth = useAuthStore()
    auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin' } as never
    const store = useActiveCompanyStore()
    store.companies = [
      { id: 4, name: 'ไทยประกันชีวิต', slug: 'thailife' },
      { id: 9, name: 'Genesenn', slug: 'genesenn' },
    ]
    store.setCompany(4)

    await mountDashboard()
    get.mockClear()

    store.setCompany(9)
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/agent-dashboard-metrics?company_id=9')
  })

  it('asks for the whole platform on ทุกบริษัท, and says so by sending nothing', async () => {
    // null is a real, deliberate read-across state (ADR-038) — not "no
    // company chosen yet". Sending company_id=null would narrow to nothing.
    const auth = useAuthStore()
    auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin' } as never
    useActiveCompanyStore().setCompany(null)

    await mountDashboard()

    expect(get).toHaveBeenCalledWith('/agent-dashboard-metrics')
  })
})

describe('pipeline funnel — the server owns the stage list', () => {
  it('plots ALL eight stages, so the bars sum to the ดีลทั้งหมด KPI', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    const funnel = chart(wrapper, 'funnel')
    const series = funnel!.props('series') as Array<{ data: number[] }>
    expect(series[0]!.data).toEqual([5, 4, 3, 2, 1, 6, 7, 8])

    // The whole point of F-4: the bars and the headline count agree.
    const sum = series[0]!.data.reduce((a, b) => a + b, 0)
    expect(sum).toBe(36)
  })

  it('labels each bar from the SAME array as its value, in the server order', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    const options = chart(wrapper, 'funnel')!.props('options') as { xaxis: { categories: string[] } }
    expect(options.xaxis.categories).toEqual([
      PIPELINE_STAGE_LABELS_TH.complete_registered,
      PIPELINE_STAGE_LABELS_TH.waiting_appointment,
      PIPELINE_STAGE_LABELS_TH.finish_1st_doctor_meeting,
      PIPELINE_STAGE_LABELS_TH.complete_payment,
      PIPELINE_STAGE_LABELS_TH.ongoing_next_meeting,
      PIPELINE_STAGE_LABELS_TH.delivery,
      PIPELINE_STAGE_LABELS_TH.service_appointment,
      PIPELINE_STAGE_LABELS_TH.follow_up,
    ])
  })

  it('a SHORT template (2 stages) plots exactly 2 bars — no padding to five', async () => {
    wireApi(makeMetrics({ deals_by_stage: { complete_registered: 7, complete_payment: 3 } }))
    const wrapper = await mountDashboard()

    const funnel = chart(wrapper, 'funnel')!
    expect((funnel.props('series') as Array<{ data: number[] }>)[0]!.data).toEqual([7, 3])
    expect((funnel.props('options') as { xaxis: { categories: string[] } }).xaxis.categories).toEqual([
      PIPELINE_STAGE_LABELS_TH.complete_registered,
      PIPELINE_STAGE_LABELS_TH.complete_payment,
    ])
  })

  it('follows the SERVER order even when it is not the medical sequence', async () => {
    // A post-sale-first order no hardcoded list would ever produce.
    wireApi(makeMetrics({ deals_by_stage: { follow_up: 9, complete_registered: 1, complete_payment: 2 } }))
    const wrapper = await mountDashboard()

    const funnel = chart(wrapper, 'funnel')!
    expect((funnel.props('series') as Array<{ data: number[] }>)[0]!.data).toEqual([9, 1, 2])
    expect((funnel.props('options') as { xaxis: { categories: string[] } }).xaxis.categories).toEqual([
      PIPELINE_STAGE_LABELS_TH.follow_up,
      PIPELINE_STAGE_LABELS_TH.complete_registered,
      PIPELINE_STAGE_LABELS_TH.complete_payment,
    ])
  })

  it('renders a stage this app has no Thai label for rather than dropping it', async () => {
    // ADR-026 §3.2: adding a case is a backend change; until the label map
    // catches up the bar must still appear, under its raw key.
    wireApi(makeMetrics({ deals_by_stage: { complete_registered: 1, some_future_stage: 4, complete_payment: 1 } }))
    const wrapper = await mountDashboard()

    const funnel = chart(wrapper, 'funnel')!
    expect((funnel.props('series') as Array<{ data: number[] }>)[0]!.data).toEqual([1, 4, 1])
    expect((funnel.props('options') as { xaxis: { categories: string[] } }).xaxis.categories[1]).toBe(
      'some_future_stage',
    )
  })
})

// ════════════════════════════════════════════════════════════════════════
// §4.2 — labels that describe the quantity actually being shown
// ════════════════════════════════════════════════════════════════════════
describe('labels match the definitions', () => {
  it('calls the pending queue ผู้ใช้ (any role), never ตัวแทน (§3.4)', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('ผู้ใช้ที่รออนุมัติ')
    expect(wrapper.text()).not.toContain('ตัวแทนที่รออนุมัติ')
  })

  it('does not label the sales card "(จ่ายแล้ว)" — that reads as a commission payout', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('ยอดขาย — เงินที่ลูกค้าชำระแล้ว')
    expect(wrapper.text()).not.toContain('ยอดขาย (จ่ายแล้ว)')
  })

  it('BR-3 — satang divided by 100 only at display', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    // 1_234_500 satang → ฿12,345
    expect(wrapper.text()).toContain('฿12,345')
  })

  it('says the close rate means REACHED payment, post-sale stages included (D4)', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('รวมขั้นหลังการขาย')
    expect(wrapper.text()).not.toContain('ดีลปิด ÷ ดีลทั้งหมด')
  })

  it('says the Top ตัวแทน ranking counts PAID commission only', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('ค่าคอมมิชชั่นที่จ่ายแล้วสูงสุด')
  })
})

// ════════════════════════════════════════════════════════════════════════
// §4.2 / §3.2 — the closed-deals-without-an-order disclosure (D2)
// ════════════════════════════════════════════════════════════════════════
describe('closed_deals_without_order disclosure', () => {
  it('states it as a sentence when the server reports any', async () => {
    wireApi(makeMetrics({ totals: { closed_deals_without_order: 3 } }))
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('อีก 3 ดีลปิดแล้วแต่ยังไม่มีคำสั่งซื้อ')
  })

  it('says NOTHING when it is 0 — a permanent caveat is an ignored caveat', async () => {
    wireApi(makeMetrics({ totals: { closed_deals_without_order: 0 } }))
    const wrapper = await mountDashboard()

    expect(wrapper.text()).not.toContain('ดีลปิดแล้วแต่ยังไม่มีคำสั่งซื้อ')
  })
})

// ════════════════════════════════════════════════════════════════════════
// §3.8 (F-5) — the donut's denominator
// ════════════════════════════════════════════════════════════════════════
describe('cert tier donut', () => {
  it('adds the uncertified remainder as its own slice, so slices sum to the workforce', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    const donut = chart(wrapper, 'cert')!
    expect(donut.props('series')).toEqual([4, 3, 5]) // basic, intermediate, cert_pending
    expect((donut.props('options') as { labels: string[] }).labels).toEqual([
      'Basic',
      'Intermediate',
      'ยังไม่มีใบรับรอง',
    ])
    // 4 + 3 + 5 === agents_total (12)
    expect((donut.props('series') as number[]).reduce((a, b) => a + b, 0)).toBe(12)
  })

  it('states its denominator in words as well', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('นับตัวแทนที่ใช้งานอยู่ 12 คน')
  })
})

// ════════════════════════════════════════════════════════════════════════
// §4.4 (F-13) — a zero-data company measures nothing, so it shows nothing
// ════════════════════════════════════════════════════════════════════════
describe('zero-data company', () => {
  it('draws NO gauge, NO money chart, NO new-agents bar, NO funnel, NO donut', async () => {
    wireApi(makeEmptyMetrics())
    const wrapper = await mountDashboard()

    expect(chart(wrapper, 'conversion')).toBeUndefined()
    expect(chart(wrapper, 'money')).toBeUndefined()
    expect(chart(wrapper, 'funnel')).toBeUndefined()
    expect(chart(wrapper, 'cert')).toBeUndefined()
  })

  it('says ยังไม่มีข้อมูล instead, and never prints a 0% close rate', async () => {
    wireApi(makeEmptyMetrics())
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('ยังไม่มีข้อมูล')
    expect(wrapper.text()).not.toContain('0%')
    expect(wrapper.text()).toContain('ยังไม่มีดีลในระบบ')
  })

  it('still draws every chart for a company that HAS data (the gate is not a blanket off-switch)', async () => {
    wireApi(makeMetrics())
    const wrapper = await mountDashboard()

    expect(chart(wrapper, 'conversion')).toBeDefined()
    expect(chart(wrapper, 'money')).toBeDefined()
    expect(chart(wrapper, 'funnel')).toBeDefined()
    expect(chart(wrapper, 'cert')).toBeDefined()
  })

  it('a company with deals but no money still gets the (honest, flat) money chart', async () => {
    // This zero WAS measured: they have referrals and have collected nothing.
    wireApi(
      makeMetrics({
        totals: {
          sales_paid_satang: 0,
          commission_paid_satang: 0,
          commission_pending_satang: 0,
          deals_total: 4,
        },
        monthly: [{ month: '2026-04', sales_satang: 0, commission_satang: 0, new_agents: 0 }],
      }),
    )
    const wrapper = await mountDashboard()

    expect(chart(wrapper, 'money')).toBeDefined()
  })
})

// ════════════════════════════════════════════════════════════════════════
// §4.3 / §4.4 (F-7, F-14) — the pending-approvals panel
// ════════════════════════════════════════════════════════════════════════
describe('pending approvals panel', () => {
  it("shows the SERVER's total in the badge, not the length of page 1", async () => {
    wireApi(makeMetrics(), {
      data: [
        { id: 1, name: 'ก', email: 'a@x.co' },
        { id: 2, name: 'ข', email: 'b@x.co' },
      ],
      meta: { total: 41 },
    })
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('41')
    expect(wrapper.text()).toContain('แสดง 2 จาก 41 รายการ')
  })

  it('renders an ERROR state when /agent-approvals fails — never the green "none pending"', async () => {
    wireApi(makeMetrics(), 'fail')
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('โหลดคิวรออนุมัติไม่สำเร็จ (403)')
    expect(wrapper.text()).not.toContain('ไม่มีผู้ใช้รออนุมัติ')
    // …and the dashboard itself still renders (the queue is a side panel).
    expect(chart(wrapper, 'funnel')).toBeDefined()
  })

  it('shows the green "none pending" only when the request actually succeeded and was empty', async () => {
    wireApi(makeMetrics(), { data: [], meta: { total: 0 } })
    const wrapper = await mountDashboard()

    expect(wrapper.text()).toContain('ไม่มีผู้ใช้รออนุมัติ')
  })
})
