/**
 * Step 4.2 — ใครเป็นผู้รับค่าคอมหัวหน้าทีม.
 *
 * Owner, 2026-09-15: "หัวหน้าทีมในที่นี้มีได้ 2 ความหมาย คือหัวหน้าทีมที่เป็น
 * user จริงในระบบ กับหัวหน้าทีมที่เป็นตัวบริษัทเองที่ได้ค่าคอมจากการขาย".
 *
 * The company can now take a seat at the top of its own hierarchy and be paid
 * a leader's share. Switching that on is the most consequential button on this
 * screen: it attaches every agent who had no upline, it changes what every
 * seller takes home on their next sale, and the ledger rows it produces cannot
 * be corrected afterwards (BR-4).
 *
 * So the tests here are not about the box rendering. They are about the three
 * things that would make it dangerous:
 *
 *   1. IT NEVER WRITES ON ONE CLICK. The confirmation names both hard-to-undo
 *      consequences before the request is sent.
 *   2. IT SAYS WHETHER THE SEAT IS ACTUALLY EARNING. "on" alone cannot tell a
 *      working seat from one nobody reports to, and the difference is silent.
 *   3. THE CEILING UPDATES WITH IT. Turning it on deepens every chain by one,
 *      and 4.3–4.5 offer that number as a maximum somebody then types into.
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

const AIA = { id: 2, name: 'Thai Life insurance', slug: 'thai-life' }

const READY = {
  state: 'ready',
  blocking_step: null,
  products_total: 1,
  products_covered: 1,
  issues: [],
  can_fix: true,
}

const BASE_SETTINGS = {
  commission_plan_type: 'unilevel',
  commission_basis: 'price',
  commission_override_mode: 'deduct_from_sale',
  deepest_manager_chain: 0,
  commission_house_account: null,
}

/*
 * Step 4 is only reachable once step 3 is finished, so every fixture here
 * carries one sellable product and one company-wide agent rate. They are
 * scenery — nothing below asserts on them — but without them the tab is
 * disabled and every test fails on a missing element rather than on what it
 * is about.
 */
const PRODUCT = {
  id: 1,
  company_id: AIA.id,
  name: 'GENESENN 1-Year Vital Blueprint',
  category: null,
  price_satang: 2990000,
  commission_plan_type: null,
  effective_plan_type: 'unilevel',
  commission_rate_type: null,
  is_sellable_here: true,
  permissions: { update: true, delete: true, set_commission_rule: true },
}

const AGENT_RULE = {
  id: 10,
  company_id: AIA.id,
  cert_tier: null,
  product: null,
  product_category: null,
  rate_type: 'percentage',
  rate_value: 500,
  effective_from: '2020-01-01T00:00:00.000000Z',
  effective_to: null,
  renewal_rate_type: null,
  renewal_rate_value: null,
  renewal_recurs: false,
}

function house(over: Record<string, unknown> = {}) {
  return { id: 90, name: 'Thai Life insurance', agents_under: 4, earned_satang: 134550, ...over }
}

async function mountView(settings: Record<string, unknown> = {}) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) return { data: { ...BASE_SETTINGS, ...settings } }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: [PRODUCT] }
    if (path.startsWith('/commission-override-rules')) return { data: [] }
    if (path.startsWith('/commission-rules')) return { data: [AGENT_RULE] }

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

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  del.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('no seat yet', () => {
  it('says what happens today, rather than only that a setting is off', async () => {
    const wrapper = await mountView()
    await goToStep4(wrapper)

    const box = wrapper.get('[data-test="step4-house-account"]').text()
    expect(box).toContain('เฉพาะหัวหน้าทีมที่เป็นคนจริง')
    // The consequence, not the state: an agent with no upline earns the
    // company nothing, and that is the whole reason to press the button.
    expect(box).toContain('ตัวแทนที่ไม่มีหัวหน้า')
  })

  it('does not write on the first click', async () => {
    /*
     * This attaches every unmanaged agent and starts deducting from the next
     * sale. A button that did it immediately would be a structural edit
     * nobody confirmed — and ledger rows cannot be corrected after the fact.
     */
    const wrapper = await mountView()
    await goToStep4(wrapper)

    await wrapper.get('[data-test="house-account-enable"]').trigger('click')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="house-account-confirm"]').exists()).toBe(true)
  })

  it('names both hard-to-undo consequences in the confirmation', async () => {
    const wrapper = await mountView()
    await goToStep4(wrapper)
    await wrapper.get('[data-test="house-account-enable"]').trigger('click')

    const confirm = wrapper.get('[data-test="house-account-confirm"]').text()
    expect(confirm).toContain('ตัวแทนที่ยังไม่มีหัวหน้าทุกคนจะถูกผูกเข้าสายงาน')
    // The one the owner had to be shown three times before accepting it.
    expect(confirm).toContain('ทุกดีล')
    expect(confirm).toContain('เพดานอัตราหัวหน้าทีม')
  })

  it('offers the company name already filled in', async () => {
    // Nine admins in ten want exactly this, so the common case is one button
    // rather than a form.
    const wrapper = await mountView()
    await goToStep4(wrapper)
    await wrapper.get('[data-test="house-account-enable"]').trigger('click')

    expect((wrapper.get('[data-test="house-account-name"]').element as HTMLInputElement).value)
      .toBe('Thai Life insurance')
  })

  it('sends the confirmed name and repaints from the answer', async () => {
    post.mockResolvedValue({ data: { commission_house_account: house({ name: 'ไทยประกันชีวิต', agents_under: 4 }), deepest_manager_chain: 1 } })
    const wrapper = await mountView()
    await goToStep4(wrapper)
    await wrapper.get('[data-test="house-account-enable"]').trigger('click')
    await wrapper.get('[data-test="house-account-name"]').setValue('ไทยประกันชีวิต')

    await wrapper.get('[data-test="house-account-confirm-save"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-house-account', { display_name: 'ไทยประกันชีวิต', company_id: AIA.id })
    expect(wrapper.get('[data-test="house-account-name-shown"]').text()).toBe('ไทยประกันชีวิต')
  })

  it('takes the new chain depth from the answer, not from a guess', async () => {
    /*
     * 4.3–4.5 offer a maximum leader rate derived from this number. A screen
     * that kept the old depth would offer a ceiling the save-time guard then
     * refuses — the exact failure the depth is sent from the server to avoid.
     */
    post.mockResolvedValue({ data: { commission_house_account: house(), deepest_manager_chain: 1 } })
    const wrapper = await mountView()
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="override-mode-chain"]').text()).toContain('ยังไม่มีสายงาน')

    await wrapper.get('[data-test="house-account-enable"]').trigger('click')
    await wrapper.get('[data-test="house-account-confirm-save"]').trigger('click')
    await flushPromises()

    const chain = wrapper.get('[data-test="override-mode-chain"]').text()
    expect(chain).not.toContain('ยังไม่มีสายงาน')
    expect(chain).toContain('รวมบัญชีบริษัท 1 ชั้น')
  })
})

describe('the seat exists', () => {
  it('reports who reports to it and what it has earned', async () => {
    // "On" cannot tell a working seat from one attached to nobody, and the
    // difference is silent — the company simply earns nothing.
    const wrapper = await mountView({ commission_house_account: house(), deepest_manager_chain: 2 })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="house-account-agents"]').text()).toBe('4 คน')
    // formatSatang's own rendering — asserted as the screen prints it rather
    // than as a number this test formatted a second way.
    expect(wrapper.get('[data-test="house-account-earned"]').text()).toContain('1,345.5')
  })

  it('warns when nobody reports to it', async () => {
    const wrapper = await mountView({ commission_house_account: house({ agents_under: 0 }), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="step4-house-account"]').text()).toContain('ยังไม่มีใครขึ้นตรงกับบัญชีนี้')
  })

  it('turns it off and says what survives', async () => {
    /*
     * Detaching is reversible; the rows the company was already paid are not.
     * The screen has to say which is which, or an admin presses this expecting
     * an undo.
     */
    del.mockResolvedValue({ data: { commission_house_account: null, deepest_manager_chain: 0 } })
    const wrapper = await mountView({ commission_house_account: house(), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="step4-house-account"]').text()).toContain('ค่าคอมที่บริษัทได้รับไปแล้วยังอยู่ตามเดิม')

    await wrapper.get('[data-test="house-account-disable"]').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/commission-house-account', { company_id: AIA.id })
    expect(wrapper.get('[data-test="house-account-state"]').text()).toBe('เฉพาะหัวหน้าทีมที่เป็นคนจริง')
  })

  it('says so in a visible line when the write fails, and does not lie about the state', async () => {
    del.mockRejectedValue(new Error('boom'))
    const wrapper = await mountView({ commission_house_account: house(), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    await wrapper.get('[data-test="house-account-disable"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="house-account-error"]').text()).toContain('ปิดบัญชีบริษัทไม่สำเร็จ')
    expect(wrapper.get('[data-test="house-account-state"]').text()).toBe('บริษัทรับด้วย')
  })
})

describe('a Company Admin sees it and cannot change it', () => {
  beforeEach(() => {
    useAuthStore().user = { id: 2, name: 'ผู้ดูแลบริษัท', role: 'company_admin', company: AIA } as never
  })

  it('shows the state with no controls — the house rule, hide not disable', async () => {
    const wrapper = await mountView({ commission_house_account: house(), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="house-account-agents"]').text()).toBe('4 คน')
    expect(wrapper.find('[data-test="house-account-disable"]').exists()).toBe(false)
  })

  it('is not offered the button when there is no seat either', async () => {
    const wrapper = await mountView()
    await goToStep4(wrapper)

    expect(wrapper.find('[data-test="house-account-enable"]').exists()).toBe(false)
  })
})
