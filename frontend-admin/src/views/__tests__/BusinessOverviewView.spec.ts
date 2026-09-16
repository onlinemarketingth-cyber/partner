/**
 * ภาพรวมธุรกิจ — the screen an owner makes decisions on. 2026-09-16.
 *
 * The server already refuses to invent the figures this page cannot measure:
 * a ratio over no sales comes back `null`, a product nobody costed comes back
 * with `cost_satang: null`, and three "could not measure" counts ride along.
 *
 * All of that is worth nothing if this layer writes `?? 0`. The rendered
 * result would be byte-for-byte the defect the null contract exists to
 * prevent — a confident number, in large type, that nobody computed. So the
 * assertions below are mostly about what is NOT printed.
 *
 * (The same reasoning, and the same failure mode, as
 * CommissionPayoutsView.spec.ts's F-10 block. It has happened twice.)
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), postForm: vi.fn(), download: vi.fn(),
  },
  ApiError: class extends Error {
    constructor(public status: number) { super(`API error ${status}`) }
  },
}))

import BusinessOverviewView from '../BusinessOverviewView.vue'

// BR-3 — integer satang from the API.
function overview(over: Record<string, unknown> = {}) {
  return {
    window: { from: '2026-09-01', to: '2026-09-30', axis: 'orders.paid_at' },
    money: {
      revenue_satang: 100000000,
      orders_paid: 40,
      commission_satang: 11600000,
      cost_satang: 40000000,
      gross_profit_satang: 48400000,
      commission_ratio: 11.6,
      gross_margin_ratio: 48.4,
      average_order_satang: 2500000,
    },
    commission_pipeline: { unpaid_satang: 4383000, scheduled_satang: 2140000, paid_satang: 14850000 },
    monthly: [
      { month: '2026-07', revenue_satang: 50000000, orders: 20 },
      { month: '2026-08', revenue_satang: 0, orders: 0 },
      { month: '2026-09', revenue_satang: 100000000, orders: 40 },
    ],
    top_products: [
      { product_id: 3, product_name: 'GENESENN Vital Blueprint', units: 31, revenue_satang: 92690000, cost_satang: 40000000, uncosted_units: 0 },
    ],
    top_agents: [
      { agent_id: 42, agent_name: 'เกรียงยศ อุยเหินนภา', orders: 20, revenue_satang: 61240000 },
    ],
    clients: { new_clients: 41, deals_closed: 63 },
    disclosures: { orders_with_reported_refund: 0, closed_deals_without_paid_order: 0, orders_without_cost: 0 },
    ...over,
  }
}

async function mountView(body: Record<string, unknown> = overview()) {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/business-overview')) return { data: body }
    throw new Error(`unexpected GET ${path}`)
  })

  const wrapper = mount(BusinessOverviewView, {
    global: { stubs: { HeroHeader: true, CompanyScopeNotice: true, LoadingSkeleton: true, Icon: true } },
  })
  await flushPromises()

  return wrapper
}

/**
 * "A zero appears as a whole figure somewhere."
 *
 * `toContain('0')` would be useless — every real amount here contains a zero.
 * This requires the zero to be the entire number.
 */
const STANDALONE_ZERO = /(^|[^\d,.])0([^\d,.%]|$)/

beforeEach(() => {
  get.mockReset()
})

describe('the subtraction the screen is built around', () => {
  it('prints all four figures of เก็บได้ − ค่าแนะนำ − ต้นทุน = กำไร', async () => {
    const wrapper = await mountView()

    expect(wrapper.get('[data-test="overview-revenue"]').text()).toBe('1,000,000')
    expect(wrapper.get('[data-test="overview-commission"]').text()).toBe('116,000')
    expect(wrapper.get('[data-test="overview-cost"]').text()).toBe('400,000')
    expect(wrapper.get('[data-test="overview-profit"]').text()).toBe('484,000')
  })

  it('prints the ratio nothing in this app used to compute', async () => {
    const wrapper = await mountView()

    expect(wrapper.get('[data-test="overview-ratio"]').text()).toBe('11.6%')
  })

  it('asks the server for a window, and says which dates it got back', async () => {
    const wrapper = await mountView()

    expect(get).toHaveBeenCalledWith(expect.stringContaining('date_from='))
    expect(wrapper.get('[data-test="overview-axis"]').text()).toContain('วันที่รับเงินจริง')
  })
})

describe('what it refuses to invent', () => {
  it('shows a dash, not 0%, when there were no sales to compute a ratio over', async () => {
    /*
     * THE ONE THAT MATTERS. "0.0%" in the commission-ratio slot reads as a
     * measured fact — a month where commission cost nothing — rather than as
     * a month with no sales at all.
     */
    const wrapper = await mountView(overview({
      money: {
        revenue_satang: 0, orders_paid: 0, commission_satang: 0, cost_satang: 0, gross_profit_satang: 0,
        commission_ratio: null, gross_margin_ratio: null, average_order_satang: null,
      },
    }))

    expect(wrapper.get('[data-test="overview-ratio"]').text()).toBe('—')
    expect(wrapper.text()).not.toContain('0.0%')
  })

  it('offers to set a cost instead of reporting an uncosted product as pure profit', async () => {
    /*
     * With no cost recorded, revenue − cost would equal revenue, and this row
     * would claim 100% margin on every sale of it. Every product in the
     * system is in that state today.
     */
    const wrapper = await mountView(overview({
      top_products: [
        { product_id: 7, product_name: 'Metabolic Reset', units: 6, revenue_satang: 18000000, cost_satang: null, uncosted_units: 6 },
      ],
    }))

    const row = wrapper.get('[data-test="overview-product-7"]')

    expect(wrapper.find('[data-test="overview-set-cost-7"]').exists()).toBe(true)
    expect(row.text()).toContain('180,000')
    // ...and nowhere does it claim the revenue as profit.
    expect(row.text()).not.toContain('100%')
  })

  it('renders nothing at all rather than a half-filled dashboard when the load fails', async () => {
    // Blank tiles on a money screen read as zeros.
    get.mockRejectedValue(new Error('boom'))
    const wrapper = mount(BusinessOverviewView, {
      global: { stubs: { HeroHeader: true, CompanyScopeNotice: true, LoadingSkeleton: true, Icon: true } },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="overview-money"]').exists()).toBe(false)
    expect(wrapper.text()).not.toMatch(STANDALONE_ZERO)
  })
})

describe('what it says it could not measure', () => {
  it('names every disclosure the server returned', async () => {
    const wrapper = await mountView(overview({
      disclosures: { orders_with_reported_refund: 1, closed_deals_without_paid_order: 4, orders_without_cost: 2 },
    }))

    const box = wrapper.get('[data-test="overview-disclosures"]').text()

    expect(box).toContain('4 ดีลปิดการขายแล้วแต่ไม่มีคำสั่งซื้อ')
    expect(box).toContain('1 รายการที่ธนาคาร')
    expect(box).toContain('2 รายการที่สินค้ายังไม่ได้ตั้งต้นทุน')
  })

  it('says nothing when there is nothing to disclose', async () => {
    // A permanently-visible warning box stops being read.
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="overview-disclosures"]').exists()).toBe(false)
  })
})

describe('the money owed to agents', () => {
  it('says outright that it ignores the date filter', async () => {
    /*
     * It is a balance, not a flow. Sitting beside three figures that DO move
     * with the window, a figure that does not would otherwise be read as a
     * bug — or worse, as the debt shrinking when somebody narrows the dates.
     */
    const wrapper = await mountView()

    expect(wrapper.get('[data-test="overview-pipeline"]').text()).toContain('ไม่ขึ้นกับช่วงเวลาที่เลือก')
  })

  it('warns that two of its three figures overlap', async () => {
    // "ตั้งจ่ายแล้ว รอโอน" is a subset of "ยังไม่ได้ตั้งจ่าย" — a raised payout
    // reserves ledger rows without settling them. Adding them double-counts.
    const wrapper = await mountView()

    expect(wrapper.get('[data-test="overview-pipeline"]').text()).toContain('ไม่ต้องบวกกัน')
  })
})

describe('the window', () => {
  it('re-asks the server when the period changes', async () => {
    const wrapper = await mountView()
    get.mockClear()

    await wrapper.get('[data-test="overview-preset-year"]').trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledWith(expect.stringContaining(`date_from=${new Date().getFullYear()}-01-01`))
  })

  it('draws every month it was given, including the empty one', async () => {
    // A chart that omits an empty month draws a straight line across it,
    // which reads as continuity rather than as a month with no sales.
    const wrapper = await mountView()

    expect(wrapper.get('[data-test="overview-chart"]').findAll('div.flex-1')).toHaveLength(3)
  })
})
