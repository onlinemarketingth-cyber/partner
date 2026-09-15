/**
 * The product x layer table — owner's ข้อเสนอ 2, 2026-09-14.
 *
 * He chose it over a read-only explainer for a reason he stated plainly:
 * "เห็นได้ง่ายชัดเจนทำให้แก้ไขได้เลย". So the tests here are about the two
 * halves of that sentence, plus the one property that makes the table safe to
 * believe:
 *
 *   1. IT SHOWS THE LOSERS. A struck-through 5% beside a winning 8% is the
 *      answer to "ทำไมตั้งแล้วไม่เปลี่ยน", and it is the thing a table of
 *      winners alone cannot say.
 *   2. IT IS EDITABLE FROM THE CELL. Clicking a rung opens the form already
 *      pointed at that scope and that product.
 *   3. IT NEVER GUESSES. Every number comes from GET /commission-resolution;
 *      when that read fails the table says so and shows nothing, because the
 *      browser's own copy of this ladder is what once paid a Thai Life rate
 *      on AIA.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import CommissionPlansView from '../CommissionPlansView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const AIA = { id: 2, name: 'AIA', slug: 'aia' }

function product(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    company_id: AIA.id,
    name: 'AIA Health Plus',
    category: { id: 7, name: 'Anti Aging' },
    price_satang: 1000000,
    effective_price_satang: 1000000,
    pv_satang: null,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    commission_rate_type: null,
    is_sellable_here: true,
    permissions: { update: true, delete: true, set_commission_rule: true },
    ...over,
  }
}

function companyDefaultRule() {
  return {
    id: 10,
    company_id: AIA.id,
    cert_tier: null,
    product: null,
    product_category: null,
    rate_type: 'percentage',
    rate_value: 300,
    effective_from: '2020-01-01',
    effective_to: null,
    renewal_rate_type: null,
    renewal_rate_value: null,
    renewal_recurs: false,
  }
}

function rung(rateValue: number, amount: number, ruleId: number) {
  return { rule_id: ruleId, rate_type: 'percentage', rate_value: rateValue, amount_satang: amount }
}

/** One product, all three agent rungs set: 3% company, 5% category, 8% product. */
function resolutionPayload(over: Record<string, unknown> = {}) {
  return {
    company_id: AIA.id,
    commission_basis: 'price',
    commission_override_mode: 'additive',
    deepest_manager_chain: 0,
    max_override_per_level_satang: null,
    products: [
      {
        product_id: 1,
        name: 'AIA Health Plus',
        // 2026-09-14 — the server sends closed products too, flagged, because
        // the switch that reopens one lives on its row.
        is_sellable: true,
        category: { id: 7, name: 'Anti Aging' },
        base_satang: 1000000,
        agent: {
          base_satang: 1000000,
          amount_base_satang: 1000000,
          company: rung(300, 30000, 10),
          category: rung(500, 50000, 12),
          product: rung(800, 80000, 14),
          winner: 'product',
          amount_satang: 80000,
        },
        leader: {
          base_satang: 1000000,
          amount_base_satang: 1000000,
          company: null,
          category: null,
          product: null,
          winner: null,
          amount_satang: null,
          override_mode: 'additive',
          override_mode_source: 'company',
        },
      },
    ],
    ...over,
  }
}

const READY = {
  state: 'ready',
  blocking_step: null,
  products_total: 1,
  products_covered: 1,
  issues: [],
  can_fix: true,
}

async function mountView(resolution: unknown = resolutionPayload()) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-resolution')) {
      if (resolution === 'fail') throw new Error('500')

      return { data: resolution }
    }
    if (path.startsWith('/commission-settings')) {
      return { data: { commission_plan_type: 'unilevel', commission_basis: 'price', commission_override_mode: 'additive', deepest_manager_chain: 0 } }
    }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: [product()] }
    if (path.startsWith('/commission-override-rules')) return { data: [] }
    if (path.startsWith('/commission-rules')) return { data: [companyDefaultRule()] }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [AIA]
  active.selectedId = AIA.id

  const wrapper = mount(CommissionPlansView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        CommissionSplitSettingCard: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

async function goToStep(wrapper: Wrapper, step: 3 | 4) {
  await wrapper.get(`[data-test="step-tab-${step}"]`).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('the table shows every rung, not just the winner', () => {
  it('renders all three candidates with the money each would pay', async () => {
    const wrapper = await mountView()
    await goToStep(wrapper, 3)

    const row = wrapper.get('[data-test="step3-resolution-row-1"]').text()
    expect(row).toContain('8.00%')
    expect(row).toContain('5.00%')
    expect(row).toContain('3.00%')
    // The consequence, not just the input — the number people are checking.
    expect(row).toContain('800.00')
  })

  it('says which rung the paid rate came from', async () => {
    const wrapper = await mountView()
    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="step3-resolution-row-1"]').text()).toContain('จากชั้นสินค้า')
  })

  it('names a product nobody can be paid on, in the one place anybody sees it', async () => {
    /*
     * A closed deal on this product pays NOBODY — silently and deliberately,
     * because a config gap must never block a sale. This row is where that is
     * discovered before an agent asks where their money went.
     */
    const payload = resolutionPayload()
    const row = payload.products[0]!
    row.agent = { ...row.agent, company: null, category: null, product: null, winner: null, amount_satang: null } as never

    const wrapper = await mountView(payload)
    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="step3-resolution-unpaid-1"]').text()).toContain('ไม่มีใครได้เงิน')
    expect(wrapper.get('[data-test="step3-resolution-unpaid"]').text()).toContain('1 สินค้า')
  })
})

describe('the table is where you fix it, not just where you see it', () => {
  it('opens the existing rate for a rung that is already set', async () => {
    const wrapper = await mountView()
    await goToStep(wrapper, 3)
    await wrapper.get('[data-test="step3-resolution-cell-1-company"]').find('button').trigger('click')
    await flushPromises()

    // The company-wide rate id 10 is the one loaded from /commission-rules.
    expect(wrapper.find('[data-test="rule-period-summary"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="step-panel-3"]').exists()).toBe(true)
  })

  it('refuses to invite a category rate onto a product that has no category', async () => {
    /*
     * The one cell that is neither set nor settable. A category rule can never
     * reach an uncategorised product, so an inviting "+ ตั้ง" there would
     * produce a rate that resolves to nothing and reads as a bug in the system
     * rather than in the configuration.
     */
    const payload = resolutionPayload()
    const row = payload.products[0]!
    row.category = null as never
    row.agent = { ...row.agent, category: null } as never

    const wrapper = await mountView(payload)
    await goToStep(wrapper, 3)

    const cell = wrapper.get('[data-test="step3-resolution-cell-1-category"]')
    expect(cell.find('button').exists()).toBe(false)
    expect(cell.text()).toBe('—')
  })

  it('shows no clickable cells at all to a company admin', async () => {
    // The house rule: a control somebody cannot use is not shown. The TABLE
    // stays — what their agents earn is the half they are allowed to know.
    useAuthStore().user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never

    const wrapper = await mountView()
    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="step3-resolution"]').exists()).toBe(true)
    const cell = wrapper.get('[data-test="step3-resolution-cell-1-product"]')
    expect(cell.find('button').attributes('disabled')).toBeDefined()
  })
})

describe('the table never guesses', () => {
  it('shows nothing and says so when the server read fails', async () => {
    /*
     * THE TEST THAT PROTECTS THE WHOLE DESIGN. This screen still carries a
     * JavaScript copy of the resolution ladder for its badges, so falling back
     * to it here would be one line of code and would reintroduce exactly the
     * class of bug that paid a Thai Life rate on AIA. A table that is wrong is
     * worse than no table, because a table gets believed.
     */
    const wrapper = await mountView('fail')
    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="step3-resolution-failed"]').text()).toContain('ไม่สำเร็จ')
    expect(wrapper.find('[data-test="step3-resolution-row-1"]').exists()).toBe(false)
  })

  it('re-reads the server after a rate is deleted', async () => {
    // Deleting is the change most likely to drop a product to a broader rung
    // or to nobody at all — the table saying otherwise for even a moment is
    // the table being wrong about money.
    const wrapper = await mountView()
    await goToStep(wrapper, 3)
    const before = get.mock.calls.filter((c) => String(c[0]).startsWith('/commission-resolution')).length

    vi.spyOn(window, 'confirm').mockReturnValue(true)
    await wrapper.get('[data-test="company-default-rule-10"]').findAll('button')[1]!.trigger('click')
    await flushPromises()

    const after = get.mock.calls.filter((c) => String(c[0]).startsWith('/commission-resolution')).length
    expect(after).toBeGreaterThan(before)
  })
})

describe('step 4 gets the same table about the leader', () => {
  it('reports the leader ladder separately from the agent one', async () => {
    const wrapper = await mountView()
    await goToStep(wrapper, 4)

    expect(wrapper.get('[data-test="step4-resolution"]').text()).toContain('หัวหน้าทีมได้เท่าไหร่')
    expect(wrapper.get('[data-test="step4-resolution-unpaid-1"]').text()).toContain('หัวหน้าไม่ได้')
  })
})

/**
 * 2026-09-14 — THE TABLE ABSORBED STEP 3'S PRODUCT LIST.
 *
 * Owner: "3.3 กับต่างผลลัพธ์ … รวมเป็นการแสดงผลเดียวได้ไหม", then, on the
 * mockup: "สินค้าที่ปิดขายจะหายไปจากตาราง — แสดงไว้ ใช้แบบเดียวกับ 3.3 เดิม
 * คือปรับค่าคอมได้ เปิดปิดได้" and "ทำตามแบบร่าง".
 *
 * Two properties came out of that and neither is obvious from reading the
 * component, so both are pinned here:
 *
 *   1. EVERY PRODUCT IS A ROW, ALWAYS. The old collapse showed only the rows
 *      the table judged interesting, which is right for an explainer and wrong
 *      for the list where an admin checks that nothing was missed — the
 *      product silently omitted is precisely the one nobody notices is unset.
 *   2. THE READER NARROWS IT, not the table. Filter chips, defaulting to
 *      ทั้งหมด, with counts that say what they will show before they are
 *      clicked.
 */
describe('the table is the product list, so it never hides a product by itself', () => {
  /** Three rows: one inheriting, one with its own rate, one closed and unrated. */
  function threeProducts() {
    const base = resolutionPayload() as ReturnType<typeof resolutionPayload>
    const inheriting = {
      ...base.products[0]!,
      product_id: 1,
      name: 'สินค้าใช้ค่าเริ่มต้น',
      agent: { ...base.products[0]!.agent, category: null, product: null, winner: 'company', amount_satang: 30000 },
    }
    const ownRate = { ...base.products[0]!, product_id: 2, name: 'สินค้าตั้งเอง' }
    const closed = {
      ...base.products[0]!,
      product_id: 3,
      name: 'สินค้าปิดขาย',
      is_sellable: false,
      agent: { ...base.products[0]!.agent, company: null, category: null, product: null, winner: null, amount_satang: null },
    }

    return { ...base, products: [inheriting, ownRate, closed] }
  }

  it('shows every product at once, with no collapse to open', async () => {
    const wrapper = await mountView(threeProducts())
    await goToStep(wrapper, 3)

    for (const id of [1, 2, 3]) {
      expect(wrapper.find(`[data-test="step3-resolution-row-${id}"]`).exists()).toBe(true)
    }
    expect(wrapper.get('[data-test="step3-resolution-showing"]').text()).toContain('แสดง 3 จาก 3')
  })

  it('narrows to the rows the reader asked for, and says how many that is', async () => {
    const wrapper = await mountView(threeProducts())
    await goToStep(wrapper, 3)

    await wrapper.get('[data-test="step3-resolution-filter-own"]').trigger('click')
    expect(wrapper.findAll('[data-test^="step3-resolution-row-"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="step3-resolution-row-2"]').exists()).toBe(true)

    await wrapper.get('[data-test="step3-resolution-filter-closed"]').trigger('click')
    expect(wrapper.find('[data-test="step3-resolution-row-3"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="step3-resolution-row-1"]').exists()).toBe(false)

    await wrapper.get('[data-test="step3-resolution-filter-all"]').trigger('click')
    expect(wrapper.findAll('[data-test^="step3-resolution-row-"]')).toHaveLength(3)
  })

  it('does not count a closed product as one nobody gets paid on', async () => {
    /*
     * A product nobody sells paying nobody is a tautology, not a problem, and
     * counting it would put a red number on a screen with nothing to fix. The
     * closed row is the ONLY unpaid one in this fixture, so the badge must be
     * absent entirely — and the ปิดขายอยู่ filter must still find it.
     */
    const wrapper = await mountView(threeProducts())
    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="step3-resolution-unpaid"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="step3-resolution-filter-unpaid"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="step3-resolution-filter-closed"]').text()).toContain('1')
  })

  it('explains the ladder in sentences for the one row somebody opened', async () => {
    /*
     * The columns answer "what does it pay"; they cannot say WHY a rung was
     * skipped rather than beaten. That distinction is the whole of the owner's
     * original complaint, and it belongs on the row being asked about rather
     * than on all forty at once.
     */
    const wrapper = await mountView(threeProducts())
    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="step3-resolution-detail-1"]').exists()).toBe(false)

    await wrapper.get('[data-test="step3-resolution-expand-1"]').trigger('click')

    const detail = wrapper.get('[data-test="step3-resolution-detail-1"]').text()
    expect(detail).toContain('ยังไม่ได้ตั้ง จึงเลื่อนไปชั้นถัดไป')
    expect(detail).toContain('ใช้อันนี้ และหยุดที่นี่')
  })
})
