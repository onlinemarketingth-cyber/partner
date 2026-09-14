/**
 * "จุดที่คนเข้าใจผิดบ่อยที่สุด — 2% ไม่ได้หักจาก 300 ของสมชาย · เรื่องนี้ต้อง
 * ทำให้ชัดเจน และปรับได้ทั้งหักจากสมชายปิดการขาย และบริษัทจ่ายเพิ่ม [ทำ UI ให้
 * ผู้ใช้เข้าใจก่อนเลือกแบบใดแบบหนึ่ง]" (owner, 2026-09-13).
 *
 * The misunderstanding is arithmetic, not wording. On one 10,000 baht sale
 * with a 3% seller rate and a 2% leader rate, the three modes pay:
 *
 *   บริษัทจ่ายเพิ่ม              seller 300 · leader 200 · company 500
 *   หัก — คิดจากยอดขาย           seller 100 · leader 200 · company 300
 *   หัก — คิดจากค่าคอมตัวแทน      seller 294 · leader   6 · company 300
 *
 * The same two rates, three different answers, and the two deducting ones
 * differ by 33x. Nothing on the screen said which was in force, so the fix is
 * not a better label — it is showing ALL THREE ANSWERS IN BAHT BEFORE the
 * admin chooses.
 *
 * Every test here defends a property a plausible simplification would remove
 * while leaving the selector looking finished:
 *
 *   1. THE NUMBERS COME FROM THIS COMPANY'S OWN RATES, and they agree with
 *      CommissionService — the same figures OverrideModeTest pins server-side.
 *   2. NO RATES YET STILL EXPLAINS THE CHOICE, and says out loud that its
 *      numbers are an illustration (BR-7: the system never passes an invented
 *      business value off as a real one).
 *   3. A FAILED READ MARKS NOTHING AS CHOSEN. The default is 'additive', and
 *      rendering that as the selected answer after a 403 would state a wrong
 *      fact about somebody's pay.
 *   4. THE CHAIN AND THE CEILING ARE SHOWN BEFORE ANYBODY TYPES — the same
 *      maximum OverrideDeductionGuard refuses with afterwards.
 *   5. A COMPANY ADMIN SEES THE EXPLANATION AND NOT THE BUTTONS (owner's house
 *      rule: hide, never 403).
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

/** 10,000 baht — the sale the owner's own example is about. */
function product(over: Record<string, unknown> = {}) {
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
    ...over,
  }
}

/** 3% to whoever closes the deal. */
function agentRule(over: Record<string, unknown> = {}) {
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
    ...over,
  }
}

/** 2% to the leader — the number the whole complaint is about. */
function leaderRule(over: Record<string, unknown> = {}) {
  return {
    id: 21,
    company_id: AIA.id,
    manager_cert_tier: null,
    product: null,
    product_category: null,
    rate_type: 'percentage',
    rate_value: 200,
    effective_from: '2020-01-01T00:00:00.000000Z',
    effective_to: null,
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

interface Fixture {
  products?: unknown[]
  rules?: unknown[]
  overrideRules?: unknown[]
  /** The /commission-settings payload, or 'fail' to make the read throw. */
  settings?: Record<string, unknown> | 'fail'
}

async function mountView(fixture: Fixture = {}) {
  const {
    products = [product()],
    rules = [agentRule()],
    overrideRules = [leaderRule()],
    settings = { commission_plan_type: 'unilevel', commission_basis: 'price', commission_override_mode: 'additive', deepest_manager_chain: 1 },
  } = fixture

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      if (settings === 'fail') throw new Error('403')

      return { data: settings }
    }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: products }
    if (path.startsWith('/commission-override-rules')) return { data: overrideRules }
    if (path.startsWith('/commission-rules')) return { data: rules }

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
        CommissionSplitSettingCard: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

async function goToStep4(wrapper: Wrapper) {
  await wrapper.get('[data-test="step-tab-4"]').trigger('click')
  await flushPromises()
}

/** The three money columns of one mode's row, as rendered. */
function row(wrapper: Wrapper, mode: string): string[] {
  return wrapper
    .get(`[data-test="override-mode-row-${mode}"]`)
    .findAll('td')
    .slice(1)
    .map((td) => td.text())
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('CommissionPlansView — step 4.2 shows all three answers before the choice', () => {
  it('computes each mode from the company real rates, and agrees with the server', async () => {
    /*
     * THE TEST THIS WHOLE CARD EXISTS FOR. These are the exact figures
     * OverrideModeTest asserts against CommissionService on the same inputs —
     * if the screen and the calculation ever disagree, the explanation is
     * worse than no explanation, because it is believed.
     */
    const wrapper = await mountView()
    await goToStep4(wrapper)

    expect(row(wrapper, 'additive')).toEqual(['300 บาท', '200 บาท', '500 บาท'])
    expect(row(wrapper, 'deduct_from_sale')).toEqual(['100 บาท', '200 บาท', '300 บาท'])
    expect(row(wrapper, 'deduct_from_commission')).toEqual(['294 บาท', '6 บาท', '300 บาท'])
  })

  it('names the product the numbers came from, and does not call them hypothetical', async () => {
    const wrapper = await mountView()
    await goToStep4(wrapper)

    const example = wrapper.get('[data-test="override-mode-example"]')
    expect(example.text()).toContain('AIA Health Plus')
    expect(wrapper.find('[data-test="override-example-hypothetical"]').exists()).toBe(false)
  })

  it('still explains the choice with no leader rate yet, and says the numbers are an illustration', async () => {
    /*
     * The ordinary case, not an exotic one: 4.1 sits directly above 4.2, so
     * every company reaches this card before it has a leader rate — and that
     * is exactly when it has to understand the modes. So the card does not
     * disappear.
     *
     * BR-7 is satisfied by the label, not by silence: the figure is never
     * saved, never sent and never resolved against — it is the text of an
     * explanation, and it says so on screen.
     */
    const wrapper = await mountView({ overrideRules: [] })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="override-example-hypothetical"]').text()).toContain('ตัวอย่างสมมติ')
    expect(row(wrapper, 'additive')).toEqual(['300 บาท', '200 บาท', '500 บาท'])
  })

  it('marks the mode the company is actually on', async () => {
    const wrapper = await mountView({
      settings: { commission_plan_type: 'unilevel', commission_basis: 'price', commission_override_mode: 'deduct_from_commission', deepest_manager_chain: 1 },
    })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="override-mode-current"]').text()).toContain('หักจากตัวแทน')
    expect(wrapper.get('[data-test="override-mode-row-deduct_from_commission"]').text()).toContain('ใช้อยู่')
  })
})

describe('CommissionPlansView — step 4.2 shows the ceiling before anybody types', () => {
  it('names the chain depth and the maximum per level', async () => {
    // 3% of 10,000 is 300 baht of pool; two levels means 150 baht each, which
    // is the same arithmetic OverrideDeductionGuard refuses with afterwards.
    const wrapper = await mountView({
      settings: { commission_plan_type: 'unilevel', commission_basis: 'price', commission_override_mode: 'additive', deepest_manager_chain: 2 },
    })
    await goToStep4(wrapper)

    const chain = wrapper.get('[data-test="override-mode-chain"]').text()
    expect(chain).toContain('2 ชั้น')
    expect(chain).toContain('150 บาท ต่อชั้น')
  })

  it('says plainly that no hierarchy means no leader is paid at all', async () => {
    // Zero is a real and common answer, not a missing one — and an admin
    // agonising over a deduction that cannot happen yet is wasted worry.
    const wrapper = await mountView({
      settings: { commission_plan_type: 'unilevel', commission_basis: 'price', commission_override_mode: 'additive', deepest_manager_chain: 0 },
    })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="override-mode-chain"]').text()).toContain('ยังไม่มีสายงาน')
  })
})

describe('CommissionPlansView — step 4.2 writes through the commission door', () => {
  it('sends the mode to /commission-settings and takes the answer from the response', async () => {
    /*
     * Never optimistic. The server refuses a switch that would make an
     * existing leader rate over-deduct, and a card that showed the new mode
     * before that refusal arrived would leave an admin believing a switch that
     * never happened.
     */
    put.mockResolvedValue({ data: { commission_override_mode: 'deduct_from_commission' } })

    const wrapper = await mountView()
    await goToStep4(wrapper)
    await wrapper.get('[data-test="override-mode-pick-deduct_from_commission"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-settings', {
      commission_override_mode: 'deduct_from_commission',
      company_id: AIA.id,
    })
    expect(wrapper.get('[data-test="override-mode-current"]').text()).toContain('หักจากตัวแทน')
  })

  it('shows the refusal instead of the new mode when the server says no', async () => {
    put.mockRejectedValue(new Error('422'))

    const wrapper = await mountView()
    await goToStep4(wrapper)
    await wrapper.get('[data-test="override-mode-pick-deduct_from_sale"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="override-mode-error"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="override-mode-current"]').text()).toContain('บริษัทจ่ายเพิ่ม')
  })
})

describe('CommissionPlansView — step 4.2 is honest when it does not know', () => {
  it('marks nothing as chosen after a failed read', async () => {
    /*
     * The column defaults to 'additive', so a screen that rendered the default
     * as the ANSWER would tell a company that deducts from its agents that it
     * does not. Same defence as step 2's basis, one card further on.
     */
    const wrapper = await mountView({ settings: 'fail' })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="override-mode-unknown"]').text()).toContain('อ่านค่าปัจจุบันไม่สำเร็จ')
    expect(wrapper.find('[data-test="override-mode-current"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="override-mode-picker"]').exists()).toBe(false)
  })
})

describe('CommissionPlansView — step 4.2 and the company admin', () => {
  it('explains the company own mode but offers no buttons', async () => {
    // The write is SettingsCommissionPlanUpdate (Super Admin only), and the
    // house rule is hide rather than 403. The TABLE stays: what their company
    // does with their agents pay is the half they are allowed to know.
    useAuthStore().user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never

    const wrapper = await mountView()
    await goToStep4(wrapper)

    expect(wrapper.find('[data-test="override-mode-example"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="override-mode-picker"]').exists()).toBe(false)
  })
})
