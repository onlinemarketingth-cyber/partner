/**
 * Step 2 shows THIS company's money moving, and lets the ladder be set there.
 *
 * ═══ WHY THIS FILE EXISTS ═══
 *
 * The owner approved a prototype and then asked, looking at the live screen,
 * "ทำไม UI ที่คุยกับไว้ ... กับที่ร่างให้ผมถึงไม่เหมือนกัน". Three things had
 * drifted between the prototype and the port, none of them recorded as a
 * decision:
 *
 *   · the chart was collapsed behind a "ดูตัวอย่าง" button;
 *   · its figures were invented, and its own subtitle said so;
 *   · the per-level rates beside it saved nothing — the real form was two
 *     steps away, on step 4.
 *
 * Every test below pins one of those back. They are behavioural rather than
 * cosmetic on purpose: "the card is open" is a layout opinion that will be
 * revisited, but "the number on screen is the number that will be paid" is
 * the property the owner actually asked for, and the one that quietly rots.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
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

const READY = {
  state: 'ready',
  blocking_step: null,
  products_total: 1,
  products_covered: 1,
  issues: [],
  can_fix: true,
}

/** A product this company really sells — the one the chart names. */
function product(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    company_id: AIA.id,
    name: 'AIA Health Plus',
    category: null,
    price_satang: 890000,
    effective_price_satang: 890000,
    pv_satang: null,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    commission_rate_type: null,
    is_sellable_here: true,
    permissions: { update: true, delete: true, set_commission_rule: true },
    ...overrides,
  }
}

/** The company-wide default agent rate — what step 3 edits. */
function sellerRule(rateValue = 1000, rateType = 'percentage') {
  return {
    id: 11,
    company_id: AIA.id,
    cert_tier: null,
    product: null,
    product_category: null,
    rate_type: rateType,
    rate_value: rateValue,
    effective_from: '2020-01-01T00:00:00.000000Z',
    effective_to: null,
    renewal_rate_type: null,
    renewal_rate_value: null,
    renewal_recurs: false,
  }
}

function leaderRate(id: number, level: number | null, rateValue: number) {
  return {
    id,
    company_id: AIA.id,
    product: null,
    product_category: null,
    manager_cert_tier: null,
    level,
    rate_type: 'percentage',
    rate_value: rateValue,
    override_mode: null,
    effective_from: '2020-01-01T00:00:00.000000Z',
    effective_to: null,
  }
}

interface World {
  planType?: string
  basis?: string
  products?: ReturnType<typeof product>[]
  sellerRules?: ReturnType<typeof sellerRule>[]
  leaderRates?: ReturnType<typeof leaderRate>[]
  maxOverrideDepth?: number | null
}

function mockApi(opts: World = {}) {
  const {
    planType = 'unilevel',
    basis = 'price',
    products = [product()],
    sellerRules = [sellerRule()],
    leaderRates = [],
    maxOverrideDepth = null,
  } = opts

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      return {
        data: {
          commission_plan_type: planType,
          commission_basis: basis,
          commission_override_mode: 'additive',
          deepest_manager_chain: 3,
          max_override_depth: maxOverrideDepth,
          override_compression: false,
        },
      }
    }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null, wht_rate: null }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: products }
    if (path.startsWith('/commission-override-rules')) return { data: leaderRates }
    if (path.startsWith('/commission-rules')) return { data: sellerRules }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [AIA]
  active.selectedId = AIA.id
}

/** Step 2 is where the chart lives. */
async function mountStep2() {
  const wrapper = mount(CommissionPlansView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        BuddhistDateInput: true,
        CalendarDatePicker: true,
        CommissionSplitSettingCard: true,
        RateResolutionMatrix: true,
        RateImpactPreview: true,
        InfoPopover: true,
      },
    },
  })
  await flushPromises()
  await wrapper.get('[data-test="step-tab-2"]').trigger('click')
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  del.mockReset()
  del.mockResolvedValue({})
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

// ── It is on screen at all ──────────────────────────────────────────────────

describe('the chart is not hidden behind a button', () => {
  it('is open on arrival', async () => {
    // It shipped collapsed, with a comment arguing step 2 is "a decision
    // screen, not a playground". The decision IS the numbers.
    mockApi()

    const wrapper = await mountStep2()

    expect(wrapper.find('[data-test="plan-shape-rows"]').exists()).toBe(true)
  })
})

// ── The figures belong to this company ──────────────────────────────────────

describe('the numbers are the company\'s own', () => {
  it('seeds the price from a product this company actually sells, and names it', async () => {
    mockApi({ products: [product({ id: 9, name: 'AIA Prime', price_satang: 990000, effective_price_satang: 990000 })] })

    const wrapper = await mountStep2()

    expect((wrapper.get('[data-test="sample-price"]').element as HTMLInputElement).value).toBe('9900')
    expect(wrapper.get('[data-test="plan-shape-subtitle"]').text()).toContain('AIA Prime')
  })

  it('prefers the price the customer actually pays over the list price', async () => {
    // effective_price_satang carries the active promotion. Seeding the list
    // price would draw a chart of money nobody is paying.
    mockApi({ products: [product({ price_satang: 990000, effective_price_satang: 750000 })] })

    const wrapper = await mountStep2()

    expect((wrapper.get('[data-test="sample-price"]').element as HTMLInputElement).value).toBe('7500')
  })

  it('seeds the seller rate from the company-wide default', async () => {
    mockApi({ sellerRules: [sellerRule(750)] }) // 7.50%

    const wrapper = await mountStep2()

    expect((wrapper.get('[data-test="sample-seller-rate"]').element as HTMLInputElement).value).toBe('7.5')
  })

  it('keeps the sandbox rate when the company default is a fixed amount, rather than inventing a percentage', async () => {
    /*
     * A fixed-satang default cannot be drawn as "x% of the base" for every
     * product. Converting it against this one product would produce a
     * percentage that moves the moment anybody looks at a different one.
     */
    mockApi({ sellerRules: [sellerRule(50000, 'fixed_satang')] })

    const wrapper = await mountStep2()

    expect((wrapper.get('[data-test="sample-seller-rate"]').element as HTMLInputElement).value).toBe('10')
  })

  it('says the figures are the company\'s, not that they are invented', async () => {
    // The shipped card's own subtitle read "ตัวเลขสมมติทั้งหมด ไม่ใช่ค่าที่
    // บริษัทตั้งไว้" over what is now the company's real configuration.
    mockApi()

    const wrapper = await mountStep2()

    expect(wrapper.get('[data-test="plan-shape-subtitle"]').text()).not.toContain('ตัวเลขสมมติทั้งหมด')
    expect(wrapper.get('[data-test="plan-shape-subtitle"]').text()).toContain('ใช้ค่าจริงของบริษัท')
  })
})

// ── The ladder is editable where it is shown ────────────────────────────────

describe('the ladder is set beside the chart', () => {
  it('loads the company\'s saved rates as the rungs', async () => {
    mockApi({ leaderRates: [leaderRate(1, 1, 500), leaderRate(2, 2, 300)] })

    const wrapper = await mountStep2()

    expect((wrapper.get('[data-test="ladder-rate-1"]').element as HTMLInputElement).value).toBe('5')
    expect((wrapper.get('[data-test="ladder-rate-2"]').element as HTMLInputElement).value).toBe('3')
  })

  it('says so plainly when no level has been priced', async () => {
    // The state that most needs a picture: nobody above the seller is paid.
    mockApi({ leaderRates: [] })

    const wrapper = await mountStep2()

    expect(wrapper.get('[data-test="level-ladder-empty"]').text()).toContain('ยังไม่ได้ตั้งอัตราตามชั้น')
  })

  it('moves the money on the chart as the rate is typed, before anything is saved', async () => {
    /*
     * THE POINT OF PUTTING THEM SIDE BY SIDE. 5% of 8,900 is 445; typing 2
     * must redraw it as 178 immediately — and must NOT have written anything.
     */
    mockApi({ leaderRates: [leaderRate(1, 1, 500)] })

    const wrapper = await mountStep2()
    expect(wrapper.get('[data-test="level-ladder-editor"]').text()).toContain('445')

    await wrapper.get('[data-test="ladder-rate-1"]').setValue('2')
    await flushPromises()

    expect(wrapper.get('[data-test="level-ladder-editor"]').text()).toContain('178')
    expect(put).not.toHaveBeenCalled()
    expect(post).not.toHaveBeenCalled()
  })

  it('saves the whole ladder only when asked, as basis points', async () => {
    mockApi({ leaderRates: [leaderRate(4, 1, 500)] })

    const wrapper = await mountStep2()
    await wrapper.get('[data-test="ladder-rate-1"]').setValue('2.5')
    await wrapper.get('[data-test="ladder-save"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-override-rules/4', expect.objectContaining({
      level: 1,
      rate_value: 250,
      // Never sent with a level — the server refuses the pair, because one
      // walk up one chain has one funding model.
      override_mode: null,
      product_id: null,
      product_category_id: null,
    }))
  })

  it('creates a rung that did not exist before', async () => {
    mockApi({ leaderRates: [leaderRate(4, 1, 500)] })

    const wrapper = await mountStep2()
    await wrapper.get('[data-test="ladder-add"]').trigger('click')
    await wrapper.get('[data-test="ladder-rate-2"]').setValue('3')
    await wrapper.get('[data-test="ladder-save"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-override-rules', expect.objectContaining({
      company_id: 2,
      level: 2,
      rate_value: 300,
    }))
  })

  it('deletes a rung the admin removed', async () => {
    mockApi({ leaderRates: [leaderRate(4, 1, 500), leaderRate(5, 2, 300)] })

    const wrapper = await mountStep2()
    await wrapper.get('[data-test="ladder-remove"]').trigger('click')
    await wrapper.get('[data-test="ladder-save"]').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/commission-override-rules/5', undefined)
  })

  it('will not save until something has changed', async () => {
    // A save button that is always live invites a write nobody meant, onto a
    // table whose every change is audited.
    mockApi({ leaderRates: [leaderRate(4, 1, 500)] })

    const wrapper = await mountStep2()

    expect((wrapper.get('[data-test="ladder-save"]').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('refuses a rate above 100% without calling the server', async () => {
    mockApi({ leaderRates: [leaderRate(4, 1, 500)] })

    const wrapper = await mountStep2()
    await wrapper.get('[data-test="ladder-rate-1"]').setValue('150')
    await wrapper.get('[data-test="ladder-save"]').trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="ladder-message"]').text()).toContain('0–100')
  })

  it('warns when the ladder is deeper than the cap set on step 4', async () => {
    /*
     * The one contradiction the two steps could hide from each other: the
     * bottom rungs are priced, saved, and never paid. Nothing else would say
     * so — the rows look correct, because they are.
     */
    mockApi({
      maxOverrideDepth: 1,
      leaderRates: [leaderRate(1, 1, 500), leaderRate(2, 2, 300), leaderRate(3, 3, 100)],
    })

    const wrapper = await mountStep2()

    expect(wrapper.get('[data-test="ladder-beyond-cap"]').text()).toContain('อีก 2 ชั้น')
  })
})

// ── The sandbox still exists for plans being browsed ────────────────────────

describe('a plan the company does not run', () => {
  it('stays a sandbox, and says so', async () => {
    /*
     * The chips exist so somebody can see the SHAPE of a plan they are
     * considering. Dressing an unrelated plan in this company's rates would
     * be a lie in both directions — about the plan, and about the rates.
     */
    mockApi({ planType: 'unilevel', leaderRates: [leaderRate(1, 1, 500)] })

    const wrapper = await mountStep2()
    await wrapper.get('[data-test="plan-chip-matrix"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="level-ladder-editor"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="plan-shape-subtitle"]').text()).toContain('ตัวเลขสมมติทั้งหมด')
  })
})
