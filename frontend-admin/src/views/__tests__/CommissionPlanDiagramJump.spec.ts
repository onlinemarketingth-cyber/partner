/**
 * Pressing a box in the plan diagram takes you to the setting behind it.
 *
 * ═══ WHY THIS FILE EXISTS ═══
 *
 * Owner, looking at the Generation chart on step 2: "สามารถคลิ๊กที่ฝั่งในแต่ละ
 * ตำแหน่งเพื่อพาไป setup ค่าคอมได้สะดวก แก้ UI ได้หรือไม่".
 *
 * The chart already showed who gets paid what. What it did not show was where
 * any of those numbers came from — and the worst case was the box reading
 * คนขาย, whose rate is not on step 2 at all. An admin looking at it had no way
 * to guess that the number in it was set two steps away.
 *
 * The feature splits across two files, and the split is the thing most likely
 * to be tidied away by somebody who does not know why it is there:
 *
 *   · PlanShapePreview names a DESTINATION and nothing else. It does not know
 *     which step owns a control, it never navigates, and it goes inert unless
 *     a parent is listening.
 *   · CommissionPlansView is the half that knows where things are.
 *
 * So the tests below are about the CONTRACT between them, plus the three
 * things that turn "clickable" into something a person can actually use: it
 * answers the keyboard, it says why it is inert when it is, and it lands on
 * the right rung of a ladder whose levels are not 1,2,3.
 */
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
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

function product(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    company_id: AIA.id,
    name: 'AIA Health Plus',
    category: null,
    price_satang: 890000,
    effective_price_satang: 890000,
    pv_satang: 700000,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    commission_rate_type: null,
    is_sellable_here: true,
    permissions: { update: true, delete: true, set_commission_rule: true },
    ...overrides,
  }
}

function sellerRule(rateValue = 1000) {
  return {
    id: 11,
    company_id: AIA.id,
    cert_tier: null,
    product: null,
    product_category: null,
    rate_type: 'percentage',
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
}

function mockApi(opts: World = {}) {
  const {
    planType = 'unilevel',
    basis = 'price',
    products = [product()],
    sellerRules = [sellerRule()],
    leaderRates = [leaderRate(21, 1, 500)],
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
          max_override_depth: null,
          override_compression: false,
          plan_locked_by_sales: { locked: false, ledger_rows: 0, first_ledger_at: null },
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

/*
 * attachTo: document.body, because the jump looks its destination up with
 * document.querySelector and then focuses it. A wrapper mounted off-document
 * would pass every assertion about the emit and fail silently on the half
 * that matters.
 */
async function mountStep2() {
  const wrapper = mount(CommissionPlansView, {
    attachTo: document.body,
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

let wrappers: { unmount: () => void }[] = []

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  del.mockReset()
  del.mockResolvedValue({})
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never

  // jsdom implements neither, and both are called on every jump.
  Element.prototype.scrollIntoView = vi.fn()
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }),
  })

  wrappers = []
})

afterEach(() => {
  // attachTo leaves the markup in the document; a second mount would then see
  // two copies of every data-test and focus the wrong one.
  wrappers.forEach((w) => w.unmount())
  document.body.innerHTML = ''
})

async function step2() {
  const wrapper = await mountStep2()
  wrappers.push(wrapper)

  return wrapper
}

// ── The boxes are buttons, and only where that is true ──────────────────────

describe('a box is pressable only when it stands for a real setting', () => {
  it('marks the company\'s own plan up as buttons', async () => {
    mockApi()

    const wrapper = await step2()
    const seller = wrapper.get('[data-test="plan-node-seller-rate"]')

    expect(seller.attributes('role')).toBe('button')
    expect(seller.attributes('tabindex')).toBe('0')
    // The aria-label has to name the destination: "คนขาย" alone tells a screen
    // reader what the box IS, not what pressing it does.
    expect(seller.attributes('aria-label')).toContain('ไปตั้งค่า')
    expect(seller.attributes('aria-label')).toContain('อัตราของคนขาย')
  })

  it('leaves a plan the company does not run inert, and says why', async () => {
    /*
     * The agreed default. In sandbox mode every figure is invented, so
     * "ไปตั้งค่าอัตรานี้" would point at a setting that has nothing to do with
     * the box that was pressed — and a chart that is live on one chip and
     * inert on five, with no line saying which, reads as a bug in the five.
     */
    mockApi({ planType: 'unilevel' })

    const wrapper = await step2()
    await wrapper.get('[data-test="plan-chip-binary"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="plan-node-binary"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="plan-node-hint"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="plan-node-hint-inert"]').text()).toContain('บริษัทยังไม่ได้ใช้')
  })

  it('does not make a box pressable when there is nothing behind it to open', async () => {
    /*
     * A matrix seat is a PERSON, not a rate: what a seat is paid depends on
     * its depth, and the seats drawn side by side are all on the same one.
     * Pointing them all at the level table would teach that the seat and the
     * rate are the same thing.
     */
    mockApi({ planType: 'matrix', products: [product({ effective_plan_type: 'matrix' })] })

    const wrapper = await step2()

    const boxes = wrapper.findAll('svg g')
    const pressable = wrapper.findAll('svg g[role="button"]')

    expect(wrapper.find('[data-test="plan-node-matrix-levels"]').exists()).toBe(true)
    // Exactly two of them: ชั้นบนสุด (the level table) and คนขาย (their own
    // rate). Everything else on a matrix chart is a seat.
    expect(pressable).toHaveLength(2)
    expect(boxes.length).toBeGreaterThan(pressable.length)
  })
})

// ── Where a press lands ─────────────────────────────────────────────────────

describe('a press lands on the control, not near it', () => {
  it('focuses the rate input for the rung that was pressed', async () => {
    mockApi({ leaderRates: [leaderRate(21, 1, 500), leaderRate(22, 2, 300)] })

    const wrapper = await step2()
    await wrapper.get('[data-test="plan-node-level:2"]').trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.get('[data-test="ladder-rate-2"]').element)
  })

  it('resolves a rung against the ladder as saved, not by counting from 1', async () => {
    /*
     * THE BUG THIS EXISTS TO PREVENT. The diagram is drawn from a LIST — it
     * carries no level numbers — so its second box is the second rung, which
     * on this company's ladder is level 3. A jump that assumed level === rung
     * would silently focus a box that does not exist, or worse, the wrong
     * rate.
     */
    mockApi({ leaderRates: [leaderRate(21, 1, 500), leaderRate(23, 3, 200)] })

    const wrapper = await step2()
    await wrapper.get('[data-test="plan-node-level:2"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="ladder-rate-2"]').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('[data-test="ladder-rate-3"]').element)
  })

  it('crosses to step 3 for the seller rate, which is not on step 2 at all', async () => {
    // The jump worth having: nothing on step 2 says where the คนขาย number
    // came from.
    mockApi()

    const wrapper = await step2()
    expect(wrapper.find('[data-test="step-panel-2"]').exists()).toBe(true)

    await wrapper.get('[data-test="plan-node-seller-rate"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="step-panel-3"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="step3-company-default"]').exists()).toBe(true)
  })

  it('points the ghost row at the rank ladder, not at the generation rates', async () => {
    /*
     * The one box somebody actually wants to press: "ยังไม่ถึงขั้น" is the
     * only thing on the chart that explains why a real person got nothing,
     * and whether they count is decided by the rank ladder — not by the
     * generation table the money came out of.
     */
    mockApi({ planType: 'generation', products: [product({ effective_plan_type: 'generation' })] })

    const wrapper = await step2()
    const ghost = wrapper.get('[data-test="plan-node-rank-ladder"]')

    expect(ghost.attributes('aria-label')).toContain('เกณฑ์ขั้นตัดสาย')
  })
})

// ── It answers the keyboard ─────────────────────────────────────────────────

describe('the keyboard reaches every box the mouse does', () => {
  it('opens the destination on Enter', async () => {
    // This screen is used on a tablet and with a keyboard. An SVG shape that
    // only answers a pointer would put the one convenient route to every rate
    // behind a mouse.
    mockApi({ leaderRates: [leaderRate(21, 1, 500)] })

    const wrapper = await step2()
    await wrapper.get('[data-test="plan-node-level:1"]').trigger('keydown.enter')
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.get('[data-test="ladder-rate-1"]').element)
  })

  it('opens the destination on Space', async () => {
    mockApi({ leaderRates: [leaderRate(21, 1, 500)] })

    const wrapper = await step2()
    await wrapper.get('[data-test="plan-node-level:1"]').trigger('keydown.space')
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.get('[data-test="ladder-rate-1"]').element)
  })

  it('draws a focus ring, because SVG gives us no :focus-visible to rely on', async () => {
    mockApi()

    const wrapper = await step2()
    const seller = wrapper.get('[data-test="plan-node-seller-rate"]')

    expect(wrapper.find('[data-test="plan-node-focus-ring"]').exists()).toBe(false)
    await seller.trigger('focus')
    expect(wrapper.find('[data-test="plan-node-focus-ring"]').exists()).toBe(true)

    await seller.trigger('blur')
    expect(wrapper.find('[data-test="plan-node-focus-ring"]').exists()).toBe(false)
  })
})

// ── The press that cannot land ──────────────────────────────────────────────

describe('a jump into a locked step says so instead of doing nothing', () => {
  it('names the step when step 3 is still behind the gate', async () => {
    /*
     * goToStep refuses an unreachable step, and silence there would read as a
     * dead control on the one chart the admin was just told to press. Step 2
     * is incomplete here because a PV-basis company has a product with no PV
     * — which is exactly the state in which somebody would be studying the
     * chart.
     */
    mockApi({ basis: 'pv', products: [product({ pv_satang: null })] })

    const wrapper = await step2()
    await wrapper.get('[data-test="plan-node-seller-rate"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="step-panel-3"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="plan-jump-note"]').text()).toContain('ขั้นที่ 3')
  })
})
