/**
 * THE OVERVIEW SITS BESIDE THE STEPS, NOT INSTEAD OF THEM.
 *
 * ═══ THE TWO THINGS THAT CAN ONLY BE TESTED HERE ═══
 *
 * CommissionOverview's own spec proves it groups and emits correctly. What it
 * cannot see is the screen around it:
 *
 *   1. THE STEP FLOW MUST STAY MOUNTED. It is hidden with v-show, not v-if,
 *      because unmounting a panel takes any half-typed form in it with no
 *      word to the admin — the same hazard the page-level modal block was
 *      created for, and switching modes is one click away.
 *
 *   2. "แก้ไข" MUST LAND SOMEWHERE. Every row hands a card id back, and this
 *      view maps it onto focusPlanControl()'s existing destinations. A card
 *      with no destination is a button that does nothing, which on a settings
 *      screen reads as a bug in the setting rather than in the link.
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

const CO = { id: 2, name: 'AIA', slug: 'aia' }
const READY = { state: 'ready', blocking_step: null, products_total: 1, products_covered: 1, issues: [], can_fix: true }

/** The UAT ladder — an entry rung at ฿0 and a breakaway rung at 20%. */
const LADDER = [
  { id: 1, company_id: CO.id, name: 'UAT ขั้นเริ่มต้น', volume_threshold: 0, sort_order: 1, rate_type: 'percentage', rate_value: 500, is_breakaway_rank: false },
  { id: 2, company_id: CO.id, name: 'UAT ขั้นผู้จัดการ', volume_threshold: 20_000_000, sort_order: 2, rate_type: 'percentage', rate_value: 2_000, is_breakaway_rank: true },
]

function mockApi({
  sellerRate = 500,
  planType = 'stairstep_breakaway',
  ranks = LADDER,
}: { sellerRate?: number; planType?: string; ranks?: unknown[] } = {}) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      return {
        data: {
          commission_plan_type: planType,
          commission_basis: 'price',
          commission_override_mode: 'additive',
          deepest_manager_chain: 3,
          max_override_depth: null,
          override_compression: false,
          plan_locked_by_sales: { locked: false, ledger_rows: 0, first_ledger_at: null },
        },
      }
    }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null, wht_rate: null }
    if (path.startsWith('/companies')) return { data: [CO] }
    if (path.startsWith('/products')) {
      return {
        data: [{
          id: 7, company_id: CO.id, name: 'UAT Package', category: null,
          price_satang: 1_000_000, effective_price_satang: 1_000_000, pv_satang: null,
          commission_plan_type: null, effective_plan_type: planType,
          commission_rate_type: null, is_sellable_here: true,
          permissions: { update: true, delete: true, set_commission_rule: true },
        }],
      }
    }
    if (path.startsWith('/agent-rank-settings')) {
      return { data: { company_id: CO.id, trailing_window_days: 90, volume_scope: 'personal', recalculation_frequency: 'monthly' } }
    }
    if (path.startsWith('/agent-ranks')) return { data: ranks }
    if (path.startsWith('/commission-rules')) {
      return {
        data: [{
          id: 11, company_id: CO.id, cert_tier: null, product: null, product_category: null,
          rate_type: 'percentage', rate_value: sellerRate,
          effective_from: '2020-01-01T00:00:00.000000Z', effective_to: null,
          renewal_rate_type: null, renewal_rate_value: null, renewal_recurs: false,
        }],
      }
    }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [CO]
  active.selectedId = CO.id
}

/**
 * Opening the overview FETCHES — it speaks for tabs the admin never opened,
 * so it holds a skeleton until they land. Every test goes through this helper
 * rather than a bare click, because a bare click asserts against the skeleton.
 */
async function openOverview(wrapper: { get: (s: string) => { trigger: (e: string) => Promise<void> } }) {
  await wrapper.get('[data-test="view-mode-overview"]').trigger('click')
  await flushPromises()
}

async function mountView() {
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

  return wrapper
}

let wrappers: { unmount: () => void }[] = []

beforeEach(() => {
  get.mockReset()
  put.mockReset(); put.mockResolvedValue({ data: {} })
  post.mockReset(); post.mockResolvedValue({ data: {} })
  del.mockReset(); del.mockResolvedValue({})
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never

  Element.prototype.scrollIntoView = vi.fn()
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }),
  })
})

afterEach(() => {
  wrappers.forEach((w) => w.unmount())
  wrappers = []
})

describe('the two modes', () => {
  it('opens on the step flow and switches to the overview on demand', async () => {
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    expect(wrapper.find('[data-test="commission-overview"]').exists()).toBe(false)

    await openOverview(wrapper)

    expect(wrapper.find('[data-test="commission-overview"]').exists()).toBe(true)
  })

  it('keeps the step flow mounted while the overview is showing', async () => {
    /*
     * v-show, not v-if. Unmounting the step panels would discard a
     * half-typed rate form the moment somebody glanced at the overview —
     * the hazard the page-level modal block exists to avoid, one click away.
     */
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)

    expect(wrapper.find('[data-test="step-tab-2"]').exists()).toBe(true)
  })
})

describe('what the overview says about this company', () => {
  it('reads the company\'s real ladder and rates, not a sandbox', async () => {
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)

    expect(wrapper.get('[data-test="overview-row-rank-ladder"]').text()).toContain('5% / 20%')
    expect(wrapper.get('[data-test="overview-row-seller-default-rate"]').text()).toContain('5%')
  })

  it('with a breakaway rung below the top, names the cut the chain actually pays', async () => {
    /*
     * UAT-017's ladder: 5 / 12 / 20 (breakaway) / 25. The top rung is a
     * ceiling; a chain passing the 20% holder stops at ฿2,000. The strip
     * said ฿2,500 flat until 2026-09-25.
     */
    mockApi({
      ranks: [
        { id: 1, company_id: CO.id, name: 'เริ่มต้น', volume_threshold: 0, sort_order: 1, rate_type: 'percentage', rate_value: 500, is_breakaway_rank: false },
        { id: 2, company_id: CO.id, name: 'ผู้นำ', volume_threshold: 2_000_000, sort_order: 2, rate_type: 'percentage', rate_value: 1_200, is_breakaway_rank: false },
        { id: 3, company_id: CO.id, name: 'ผู้จัดการ', volume_threshold: 4_000_000, sort_order: 3, rate_type: 'percentage', rate_value: 2_000, is_breakaway_rank: true },
        { id: 4, company_id: CO.id, name: 'ผู้อำนวยการ', volume_threshold: 6_000_000, sort_order: 4, rate_type: 'percentage', rate_value: 2_500, is_breakaway_rank: false },
      ],
    })
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)

    expect(wrapper.get('[data-test="overview-total"]').text()).toContain('2,500')
    expect(wrapper.get('[data-test="overview-breakaway-cut"]').text()).toContain('2,000')
  })

  it('states the payout Stairstep guarantees — the highest rung in the chain', async () => {
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)

    // ฿10,000 at the top rung's 20% = ฿2,000, whoever sells it.
    expect(wrapper.get('[data-test="overview-total"]').text()).toContain('2,000')
  })

  it('flags the seller rate and the entry rung disagreeing', async () => {
    // The pair the four-step screen keeps two tabs apart. 10% against a 5%
    // entry rung pushes the chain's total off the top rung.
    mockApi({ sellerRate: 1_000 })
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)

    expect(wrapper.get('[data-test="overview-seller-pairing"]').text()).toContain('ไม่ตรงกัน')
  })

  it('shows the personal-scope warning with its consequence spelled out', async () => {
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)

    expect(wrapper.get('[data-test="overview-why-rank-volume-scope"]').text()).toContain('ปั้นทีม')
  })
})

describe('ผังการจ่าย — the third mode (จอ 4)', () => {
  it('shows the worked example on its own, with the step flow still mounted', async () => {
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await wrapper.get('[data-test="view-mode-flow"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="commission-flow"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="commission-overview"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="step-tab-2"]').exists()).toBe(true)
  })

  it('leaves nothing of itself behind when the mode closes', async () => {
    /*
     * The step copy of this chart keeps its own sandbox state. Two
     * scratchpads quietly disagreeing about a demo price is how somebody
     * comes to doubt a real number, so this one is UNMOUNTED on the way out
     * (v-if) and comes back on the company's seed — the opposite choice from
     * the step panel beside it, which stays mounted to protect a half-typed
     * form.
     *
     * Asserted as absence from the DOM rather than as a fresh instance: an
     * earlier version keyed the component to force this and a mutation
     * proved the key changed nothing, because v-if was already doing it.
     */
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await wrapper.get('[data-test="view-mode-flow"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="commission-flow"]').exists()).toBe(true)

    await wrapper.get('[data-test="view-mode-steps"]').trigger('click')
    await flushPromises()

    // Not merely hidden — gone, so there is no second sandbox to drift.
    expect(wrapper.find('[data-test="commission-flow"]').exists()).toBe(false)
  })
})

describe('the step rail (จอ 2/3)', () => {
  it('rides beside the steps and names what this step still asks', async () => {
    mockApi({ sellerRate: 500 })
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await wrapper.get('[data-test="step-tab-2"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="step-checklist"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="step-checklist-rank-ladder"]').exists()).toBe(true)
  })

  it('is absent on the company picker, which asks no card', async () => {
    // An empty rail beside step 1 would read as "this step asks nothing"
    // rather than "this step is where you choose the company".
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await wrapper.get('[data-test="step-tab-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="step-checklist"]').exists()).toBe(false)
  })

  it('opens the control a row names, same jump as the overview', async () => {
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await wrapper.get('[data-test="step-tab-2"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="step-checklist-rank-ladder"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="plan-structure-ranks"]').exists()).toBe(true)
  })
})

describe('every card is filed under the step that actually holds it', () => {
  /*
   * THE INVARIANT THAT CAUGHT จอ 5.
   *
   * A card's `step` is not a claim about which table it writes — it is where
   * the admin finds the control. `leader-level-rates` writes step 4's
   * commission_override_rules but its editor moved into step 2 on
   * 2026-09-24, and while it stayed filed under 4 the rail named a setting
   * that was not on step 4 and said nothing on step 2, where the admin was
   * looking straight at it. stepProgress() counted both steps wrong too.
   *
   * Pressing a row must therefore never change the step. Anything that does
   * is a card filed in the wrong place, whatever its comment says.
   */
  for (const step of [2, 3, 4] as const) {
    it(`keeps the admin on step ${step} when a row there is pressed`, async () => {
      mockApi({ planType: 'unilevel' })
      const wrapper = await mountView()
      wrappers.push(wrapper)

      await wrapper.get(`[data-test="step-tab-${step}"]`).trigger('click')
      await flushPromises()

      const rows = wrapper
        .findAll('[data-test^="step-checklist-"]')
        .filter((el) => /step-checklist-[a-z]/.test(el.attributes('data-test') ?? ''))
        .map((el) => el.attributes('data-test')!)
        .filter((name) => name !== 'step-checklist-progress' && name !== 'step-checklist-next')

      expect(rows.length).toBeGreaterThan(0)

      for (const row of rows) {
        await wrapper.get(`[data-test="${row}"]`).trigger('click')
        await flushPromises()

        expect(
          wrapper.get(`[data-test="step-tab-${step}"]`).attributes('aria-selected'),
          `${row} sent the admin off step ${step}`,
        ).toBe('true')
      }
    })
  }
})

describe('no company chosen (จอ 6)', () => {
  it('makes no claim about settings in the overview', async () => {
    /*
     * The mode switch sits ABOVE step 1's gate. Without this the overview
     * would render a full list of ยังไม่ตั้ง in red — about the settings of
     * no company in particular.
     */
    mockApi()
    const active = useActiveCompanyStore()
    active.selectedId = null
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)

    expect(wrapper.find('[data-test="overview-no-company"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="commission-overview"]').exists()).toBe(false)
  })

  it('draws no payout chart either', async () => {
    mockApi()
    const active = useActiveCompanyStore()
    active.selectedId = null
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await wrapper.get('[data-test="view-mode-flow"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="flow-no-company"]').exists()).toBe(true)
  })
})

describe('the edit buttons', () => {
  it('returns to the step flow and opens the step that owns the setting', async () => {
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)
    await wrapper.get('[data-test="overview-edit-rank-ladder"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="commission-overview"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="plan-structure-ranks"]').exists()).toBe(true)
  })

  it('gives every row on this plan a destination', async () => {
    /*
     * A button that does nothing reads, on a settings screen, as a broken
     * setting rather than a broken link. Every visible row is pressed and
     * the screen is required to leave the overview for each one.
     */
    mockApi()
    const wrapper = await mountView()
    wrappers.push(wrapper)

    await openOverview(wrapper)

    const ids = wrapper
      .findAll('[data-test^="overview-edit-"]')
      .map((button) => button.attributes('data-test')!.replace('overview-edit-', ''))

    expect(ids.length).toBeGreaterThan(5)

    for (const id of ids) {
      await openOverview(wrapper)
      await wrapper.get(`[data-test="overview-edit-${id}"]`).trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="commission-overview"]').exists(), `${id} left the overview open`).toBe(false)
    }
  })
})
