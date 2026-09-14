/**
 * "ยอดขั้นต่ำในการเบิก ปรับมาเป็น UI หน้านี้หน้าเดียวให้จบ นำของเก่าออกเลย"
 * (owner, 2026-09-13).
 *
 * The floor used to be edited on คำขอเบิกค่าคอม — an approval queue — while
 * แผนคอมมิชชั่น step 4 carried a link card pointing at it. The comment
 * defending that arrangement argued that moving the field over "would leave
 * two places to change one number", which was the right worry and the wrong
 * conclusion: the right count is ONE, and the one belongs with every other
 * commission setting, on the setup flow.
 *
 * So the move has two halves and this file pins both, because either half
 * alone is a regression:
 *
 *   STEP 4 WRITES IT. If the input were there but not wired, an admin would
 *   type a floor, see nothing complain, and walk away from a setting that
 *   never saved.
 *
 *   THE QUEUE READS IT AND ONLY READS IT. The number still belongs on that
 *   screen — "why was that request refused" gets asked there — but a second
 *   input is the two-doors problem the original comment correctly feared.
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
import CommissionWithdrawalsView from '../CommissionWithdrawalsView.vue'
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

function product() {
  return {
    id: 1,
    company_id: AIA.id,
    name: 'AIA Health Plus',
    category: null,
    price_satang: 1000000,
    effective_price_satang: 1000000,
    pv_satang: null,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    commission_rate_type: null,
    is_sellable_here: true,
    permissions: { update: true, delete: true, set_commission_rule: true },
  }
}

function companyDefaultRule() {
  return {
    id: 11,
    company_id: AIA.id,
    cert_tier: null,
    product: null,
    product_category: null,
    rate_type: 'percentage',
    rate_value: 300,
    effective_from: '2020-01-01T00:00:00.000000Z',
    effective_to: null,
    renewal_rate_type: null,
    renewal_rate_value: null,
    renewal_recurs: false,
  }
}

/** @param minimum satang, null for "no minimum", or 'fail' to make the read throw. */
function mockApi(minimum: number | null | 'fail') {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      return { data: { commission_plan_type: 'unilevel', commission_basis: 'price', commission_override_mode: 'additive', deepest_manager_chain: 0 } }
    }
    if (path.startsWith('/commission-withdrawal-settings')) {
      if (minimum === 'fail') throw new Error('500')

      return { min_withdrawal_satang: minimum }
    }
    if (path.startsWith('/commission-withdrawals')) return { data: [] }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: [product()] }
    if (path.startsWith('/commission-override-rules')) return { data: [] }
    if (path.startsWith('/commission-rules')) return { data: [companyDefaultRule()] }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [AIA]
  active.selectedId = AIA.id
}

async function mountStep4() {
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
      },
    },
  })
  await flushPromises()
  await wrapper.get('[data-test="step-tab-4"]').trigger('click')
  await flushPromises()

  return wrapper
}

async function mountQueue() {
  const wrapper = mount(CommissionWithdrawalsView, {
    global: {
      stubs: {
        HeroHeader: true,
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({})
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('step 4.4 — the withdrawal floor is set here now', () => {
  it('loads the current floor into the field', async () => {
    mockApi(100000)

    const wrapper = await mountStep4()

    expect((wrapper.get('[data-test="withdrawal-min-input"]').element as HTMLInputElement).value).toBe('1000.00')
  })

  it('saves in satang, and an empty field saves null rather than zero', async () => {
    /*
     * EMPTY MEANS NO MINIMUM and that is a real setting. A "" that collapsed
     * into 0 would be saved back as a floor of zero baht — which reads the
     * same on screen and is a different promise to every agent.
     */
    mockApi(100000)

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="withdrawal-min-input"]').setValue('')
    await wrapper.get('[data-test="withdrawal-min-save"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-withdrawal-settings?company_id=2', { min_withdrawal_satang: null })
    expect(wrapper.get('[data-test="withdrawal-min-message"]').text()).toContain('ไม่มีขั้นต่ำ')
  })

  it('converts baht to satang without a float creeping in', async () => {
    // BR-3 — satang is an integer end to end. 1,234.56 has bitten this exact
    // conversion before in other screens.
    mockApi(null)

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="withdrawal-min-input"]').setValue('1234.56')
    await wrapper.get('[data-test="withdrawal-min-save"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-withdrawal-settings?company_id=2', { min_withdrawal_satang: 123456 })
  })

  it('refuses nonsense without calling the server', async () => {
    mockApi(null)

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="withdrawal-min-input"]').setValue('-5')
    await wrapper.get('[data-test="withdrawal-min-save"]').trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="withdrawal-min-message"]').text()).toContain('ไม่ถูกต้อง')
  })

  it('hides the field when the read failed, instead of showing an empty one', async () => {
    // An empty field MEANS "no minimum" on this control, so rendering one
    // after a failed read would state a floor the screen never learned.
    mockApi('fail')

    const wrapper = await mountStep4()

    expect(wrapper.find('[data-test="withdrawal-min-input"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="withdrawal-min-unknown"]').text()).toContain('อ่านค่าปัจจุบันไม่สำเร็จ')
  })
})

describe('the approval queue reads the floor and no longer owns it', () => {
  it('shows the number that explains a refusal', async () => {
    mockApi(100000)

    const wrapper = await mountQueue()

    expect(wrapper.get('[data-test="withdrawal-minimum-readout"]').text()).toContain('1,000.00 บาท')
  })

  it('says "no minimum" rather than leaving a blank', async () => {
    mockApi(null)

    const wrapper = await mountQueue()

    expect(wrapper.get('[data-test="withdrawal-minimum-readout"]').text()).toContain('ไม่มีขั้นต่ำ')
  })

  it('does not explain a refusal with a fact it failed to read', async () => {
    mockApi('fail')

    const wrapper = await mountQueue()

    const readout = wrapper.get('[data-test="withdrawal-minimum-readout"]').text()
    expect(readout).toContain('อ่านค่าไม่สำเร็จ')
    expect(readout).not.toContain('ไม่มีขั้นต่ำ')
  })

  it('offers no way to change it, and points at the one place that can', async () => {
    // THE HALF THAT MAKES IT ONE DOOR. An input here is the regression.
    mockApi(100000)

    const wrapper = await mountQueue()

    expect(wrapper.findAll('input').length).toBe(0)
    expect(wrapper.get('[data-test="link-commission-step4"]').text()).toContain('ขั้นที่ 4')
  })
})
