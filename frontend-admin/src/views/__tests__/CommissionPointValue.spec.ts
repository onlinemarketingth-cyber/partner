/**
 * PV — "ทำแผน PV กับการตั้งค่าแบบคอม ขายตรง เก็บการคิดแบบ % และ Fix จำนวนเงิน
 * ไว้กับค่าคอมปรกติ" (owner, 2026-09-12).
 *
 * PV changes what every percentage on this screen is a percentage OF, which
 * makes the screen's job here almost entirely about saying so. The amounts are
 * computed server-side; what can go wrong in the browser is quieter and worse:
 *
 *   - a company that never asked for PV being shown it, or worse, moved onto it
 *   - an admin typing "5" into a field labelled "% ของยอดขาย" on a PV company
 *   - step 3's rates being filled in while some products still have no PV, so
 *     they silently pay on their price instead
 *   - a Company Admin being handed a control that will 403 (the 2026-09-11
 *     house rule)
 *
 * Each test below is one of those.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
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
    category: null,
    price_satang: 890000,
    pv_satang: null,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    commission_rate_type: null,
    permissions: { update: true, delete: true, set_commission_rule: true },
    ...over,
  }
}

/** A live company-wide agent rate, so step 3 is never the blocking step here. */
const COMPANY_RULE = {
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

/** A live company-wide leader rate, so step 4 is complete too. */
const LEADER_RULE = {
  id: 20,
  company_id: AIA.id,
  product: null,
  product_category: null,
  manager_cert_tier: null,
  rate_type: 'percentage',
  rate_value: 150,
  effective_from: '2020-01-01',
  effective_to: null,
}

const READY = {
  state: 'ready',
  blocking_step: null,
  products_total: 1,
  products_covered: 1,
  issues: [],
  can_fix: true,
}

async function mountView(opts: { basis?: 'price' | 'pv'; products?: unknown[]; failSettings?: boolean } = {}) {
  const { basis = 'price', products = [product()], failSettings = false } = opts

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    // 2026-09-12 — commission's own endpoint, not the platform companies
    // resource. See CommissionSettingService for why that dependency was
    // removed rather than documented.
    if (path.startsWith('/commission-settings')) {
      // Any failure at all — 403 from a tightened policy, 500, a dropped
      // connection. The screen's response must be the same for all of them.
      if (failSettings) throw new Error('unreachable')

      return { data: { commission_basis: basis } }
    }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: products }
    if (path.startsWith('/commission-rules')) return { data: [COMPANY_RULE] }
    if (path.startsWith('/commission-override-rules')) return { data: [LEADER_RULE] }

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
        BuddhistDateInput: true,
        CalendarDatePicker: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

async function goToStep(wrapper: Wrapper, step: 1 | 2 | 3 | 4) {
  await wrapper.get(`[data-test="step-tab-${step}"]`).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

/*
 * Nothing about PV is opt-out, and the price-basis tests scattered through the
 * blocks below are where that is enforced. The basis CARD is always shown —
 * the choice has to be discoverable or the feature does not exist — but a
 * company that has not made it sees no PV inputs, no PV warnings, no lock and
 * no change to any label.
 */
describe('PV — step 2', () => {
  it('shows both bases and marks the one the company is on', async () => {
    const wrapper = await mountView({ basis: 'pv' })
    await goToStep(wrapper, 2)

    expect(wrapper.find('[data-test="basis-option-price"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="basis-option-pv"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="basis-option-pv"]').classes().join(' ')).toContain('border-brand-600')
  })

  it('shows no PV table on the price basis', async () => {
    const wrapper = await mountView({ basis: 'price' })
    await goToStep(wrapper, 2)

    expect(wrapper.find('[data-test="pv-table"]').exists()).toBe(false)
  })

  it('lists every product on the PV basis, not only the ones missing a PV', async () => {
    /*
     * A list of gaps shrinks to nothing and then disappears — exactly when an
     * admin most needs to see what the numbers ARE. Comparing PV against price
     * across the catalogue is how anybody notices they typed 100 for 1,000.
     */
    const wrapper = await mountView({
      basis: 'pv',
      products: [product(), product({ id: 2, name: 'ครบแล้ว', pv_satang: 100000 })],
    })
    await goToStep(wrapper, 2)

    expect(wrapper.find('[data-test="pv-row-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="pv-row-2"]').exists()).toBe(true)
  })

  it('counts the products with no PV and says the system will use the price meanwhile', async () => {
    // The fallback is real and deliberate server-side (CommissionBasisResolver
    // pays on the price rather than paying 0), and it is SILENT where it
    // happens. This count is one of the three places that make it loud.
    const wrapper = await mountView({
      basis: 'pv',
      products: [product(), product({ id: 2, name: 'ครบแล้ว', pv_satang: 100000 })],
    })
    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="pv-missing-count"]').text()).toContain('1 รายการ')
    expect(wrapper.get('[data-test="pv-row-1"]').text()).toContain('ระบบจะคิดจากราคาขายไปก่อน')
  })

  it('does not count a PV of zero as missing', async () => {
    // Zero PV is a decision — a bundled item that pays nobody — and the server
    // honours it (`??`, never `?:`). Calling it a gap would nag forever about
    // a number somebody chose.
    const wrapper = await mountView({ basis: 'pv', products: [product({ pv_satang: 0 })] })
    await goToStep(wrapper, 2)

    expect(wrapper.find('[data-test="pv-missing-count"]').exists()).toBe(false)
  })

  it('saves one product PV as satang, not baht', async () => {
    // BR-3 lives on the server, but the conversion happens HERE, and a factor
    // of 100 in the base of every commission is not the kind of bug anybody
    // notices from a screenshot.
    const wrapper = await mountView({ basis: 'pv' })
    await goToStep(wrapper, 2)

    await wrapper.get('[data-test="pv-input-1"]').setValue('1000')
    await wrapper.get('[data-test="pv-save-1"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/products/1', { pv_satang: 100000 })
  })

  it('clears the PV back to null when the field is emptied', async () => {
    // A PV entered by mistake has to be removable, and null is exactly what
    // the warning is about — so "clear it" must be an honest thing to do,
    // not a no-op that silently keeps the old number.
    const wrapper = await mountView({ basis: 'pv', products: [product({ pv_satang: 100000 })] })
    await goToStep(wrapper, 2)

    await wrapper.get('[data-test="pv-input-1"]').setValue('')
    await wrapper.get('[data-test="pv-save-1"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/products/1', { pv_satang: null })
  })

  it("switches the basis through commission's own endpoint, not the companies resource", async () => {
    /*
     * 2026-09-12 — this asserted PUT /companies/2 for a few hours. Both the
     * read and the write moved to /commission-settings the same day, so the
     * screen stops depending on CompanyPolicy's own-company clause and the
     * write sits behind a commission ability rather than "may you rename this
     * company". See CommissionSettingService for the whole reasoning.
     *
     * Pinned as an exact call because the endpoint IS the point of the change:
     * a future edit that quietly routes it back through the companies
     * resource would otherwise look identical from the outside.
     */
    const wrapper = await mountView({ basis: 'price' })
    await goToStep(wrapper, 2)

    await wrapper.get('[data-test="basis-option-pv"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-settings', { commission_basis: 'pv', company_id: 2 })
  })
})

describe('PV — a basis the screen could not read is never rendered as an answer', () => {
  /*
   * THE FAILURE MODE THIS BLOCK EXISTS FOR, and it is not a crash.
   *
   * `commissionBasis` has to hold something for the template to bind to. Until
   * 2026-09-12 a failed read set it to 'price' and the screen drew ราคาขาย as
   * the selected option — in the same typeface, with the same tick, as a real
   * answer. An admin at a PV company would read a confident, wrong statement
   * about how their agents are paid, with nothing on screen to suggest
   * otherwise.
   *
   * Loud beats plausible. Every path that could fail — 403, 500, dropped
   * connection — now lands here.
   */
  it('says it could not read the basis instead of showing the default', async () => {
    const wrapper = await mountView({ failSettings: true })

    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="basis-unknown"]').text()).toContain('อ่านค่าฐานการคำนวณไม่สำเร็จ')
  })

  it('marks NEITHER option as chosen', async () => {
    // The tick and the highlight are the assertion the reader actually makes.
    // Leaving them on 'price' is the whole bug: it is not a missing warning,
    // it is a wrong answer rendered as a right one.
    const wrapper = await mountView({ failSettings: true })

    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="basis-option-price"]').classes().join(' ')).not.toContain('border-brand-600')
    expect(wrapper.get('[data-test="basis-option-pv"]').classes().join(' ')).not.toContain('border-brand-600')
  })

  it('refuses to let a Super Admin switch it blind', async () => {
    /*
     * A switch that works while the CURRENT value is unknown is how somebody
     * "fixes" a company onto the basis it was already on, or off the one it
     * needed — an unreadable state is not a state to make decisions from.
     */
    const wrapper = await mountView({ failSettings: true })

    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="basis-option-pv"]').attributes('disabled')).toBeDefined()
  })

  it('shows a real answer again as soon as one can be read', async () => {
    // The control: the error state is transient, not sticky. Without this a
    // screen that had failed once and then never recovered would pass every
    // assertion above.
    const wrapper = await mountView({ basis: 'pv' })

    await goToStep(wrapper, 2)

    expect(wrapper.find('[data-test="basis-unknown"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="basis-option-pv"]').classes().join(' ')).toContain('border-brand-600')
  })
})

describe('PV — the step order is enforced by it', () => {
  it('holds step 3 shut while any product has no PV', async () => {
    /*
     * THE REASON THIS IS A GATE AND NOT A HINT.
     *
     * Entering "5%" while three products silently pay on their price instead
     * of their PV is the precise mistake the step order exists to prevent —
     * and it produces no error, no warning and no failed save. It produces a
     * ledger row, at a number nobody chose, that BR-4 forbids correcting.
     */
    const wrapper = await mountView({ basis: 'pv' })

    expect(wrapper.get('[data-test="step-tab-3"]').attributes('aria-disabled')).toBe('true')
  })

  it('opens step 3 once every product has a PV', async () => {
    // The control. Without it the test above would also pass against a screen
    // that locked step 3 forever on the PV basis.
    const wrapper = await mountView({ basis: 'pv', products: [product({ pv_satang: 100000 })] })

    expect(wrapper.get('[data-test="step-tab-3"]').attributes('aria-disabled')).toBe('false')
  })

  it('never holds step 3 shut over PV on the price basis', async () => {
    const wrapper = await mountView({ basis: 'price' })

    expect(wrapper.get('[data-test="step-tab-3"]').attributes('aria-disabled')).toBe('false')
  })
})

describe('PV — step 3 says what the percentage is a percentage of', () => {
  it('tells the admin the rate is off PV, on the screen where they type it', async () => {
    const wrapper = await mountView({ basis: 'pv', products: [product({ pv_satang: 100000 })] })
    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="step3-basis-note"]').text()).toContain('คิด % จาก PV')
  })

  it('says nothing extra on the price basis, where it would only be noise', async () => {
    const wrapper = await mountView({ basis: 'price' })
    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="step3-basis-note"]').exists()).toBe(false)
  })
})

describe('PV — a Company Admin reads it and cannot touch it', () => {
  /*
   * The 2026-09-11 decision: commission configuration is Super-Admin-only to
   * WRITE, readable by the Company Admin who runs the company and will be the
   * first person an agent asks. A control that 403s is worse than no control,
   * so every write disappears rather than being disabled.
   */
  beforeEach(() => {
    useAuthStore().user = { id: 2, name: 'ผู้ดูแลบริษัท', role: 'company_admin', company: AIA } as never
  })

  it('shows the PV figures but no inputs and no save buttons', async () => {
    const wrapper = await mountView({ basis: 'pv', products: [product({ pv_satang: 100000 })] })
    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="pv-table"]').text()).toContain('1,000')
    expect(wrapper.find('[data-test="pv-input-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="pv-save-1"]').exists()).toBe(false)
  })

  it('cannot switch the basis, and is told who can', async () => {
    const wrapper = await mountView({ basis: 'price' })
    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="basis-option-pv"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="basis-card"]').text()).toContain('ติดต่อผู้ดูแลระบบ')
  })
})
