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
  return {
    id: 90,
    name: 'Thai Life insurance',
    agents_under: 4,
    earned_satang: 134550,
    // 2026-09-15 (ครั้งที่สอง) — the seat is a payee now. Unbanked by default,
    // which is the state a company is in the moment it turns the seat on, and
    // the one the payout screen has to refuse.
    bank_name: null,
    bank_account_number: null,
    bank_account_holder_name: null,
    payout_details_complete: false,
    ...over,
  }
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

/**
 * 2026-09-15 — RENAMING THE SEAT.
 *
 * Every guard this feature added points the same way: the seat is not a
 * person, so UserPolicy refuses to update it and UserService refuses its
 * password, its move and its deactivation. Those refusals are right, and
 * together they closed the ONE edit that is legitimate — a name typed wrong
 * at setup otherwise sits on every payout row forever.
 *
 * So the door is here, and these tests are about it staying one field wide.
 */
describe('renaming the seat', () => {
  beforeEach(() => {
    useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
  })

  it('is offered only once a seat exists', async () => {
    const withoutSeat = await mountView()
    await goToStep4(withoutSeat)
    expect(withoutSeat.find('[data-test="house-account-rename-open"]').exists()).toBe(false)

    const withSeat = await mountView({ commission_house_account: house(), deepest_manager_chain: 1 })
    await goToStep4(withSeat)
    expect(withSeat.find('[data-test="house-account-rename-open"]').exists()).toBe(true)
  })

  it('opens prefilled with the name that is actually on the row', async () => {
    // Prefilled from the SERVER's value, not from the company name: those two
    // can differ precisely because somebody typed the wrong one, which is the
    // whole case this control exists for.
    const wrapper = await mountView({ commission_house_account: house({ name: 'ชื่อที่พิมพ์ผิด' }), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    await wrapper.get('[data-test="house-account-rename-open"]').trigger('click')

    expect((wrapper.get('[data-test="house-account-rename-input"]').element as HTMLInputElement).value)
      .toBe('ชื่อที่พิมพ์ผิด')
  })

  it('sends the name and the bank account together, and repaints from what came back', async () => {
    put.mockResolvedValue({
      data: { commission_house_account: house({ name: 'ไทยประกันชีวิต' }), deepest_manager_chain: 1 },
    })
    const wrapper = await mountView({ commission_house_account: house({ name: 'ชื่อเก่า' }), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    await wrapper.get('[data-test="house-account-rename-open"]').trigger('click')
    await wrapper.get('[data-test="house-account-rename-input"]').setValue('ไทยประกันชีวิต')
    await wrapper.get('[data-test="house-account-rename-save"]').trigger('click')
    await flushPromises()

    /*
     * 2026-09-15 (ครั้งที่สอง) — the three bank fields ride along.
     *
     * One PUT, one save button: the seat became a payee ("ให้เพิ่มทำจ่ายบริษัท
     * ให้เลือกได้ด้วย"), and splitting the name and the account into two
     * requests would let the screen end up holding a name that was written
     * and an account that was not.
     *
     * They are null here rather than absent because this fixture's seat has
     * no account yet — and null is what CLEARS a field, which is how an
     * account typed into the wrong company is taken back out.
     */
    expect(put).toHaveBeenCalledWith('/commission-house-account', {
      company_id: AIA.id,
      display_name: 'ไทยประกันชีวิต',
      bank_name: null,
      bank_account_number: null,
      bank_account_holder_name: null,
    })
    expect(wrapper.get('[data-test="house-account-name-shown"]').text()).toBe('ไทยประกันชีวิต')
    // Repainted from the response, not patched locally — the two counts beside
    // the name are money facts and must never be left showing a stale pair
    // next to a fresh label.
    expect(wrapper.get('[data-test="house-account-agents"]').text()).toBe('4 คน')
  })

  it('refuses to send an empty name rather than blanking the row', async () => {
    // `users.name` is DERIVED from this field server-side, so an empty save
    // would leave the payee labelled nothing at all on every ledger row.
    const wrapper = await mountView({ commission_house_account: house(), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    await wrapper.get('[data-test="house-account-rename-open"]').trigger('click')
    await wrapper.get('[data-test="house-account-rename-input"]').setValue('   ')

    expect((wrapper.get('[data-test="house-account-rename-save"]').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('says so when the write fails and keeps the old name on screen', async () => {
    put.mockRejectedValue(new Error('boom'))
    const wrapper = await mountView({ commission_house_account: house({ name: 'ชื่อเก่า' }), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    await wrapper.get('[data-test="house-account-rename-open"]').trigger('click')
    await wrapper.get('[data-test="house-account-rename-input"]').setValue('ชื่อใหม่')
    await wrapper.get('[data-test="house-account-rename-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="house-account-error"]').text()).toContain('เปลี่ยนชื่อบัญชีบริษัทไม่สำเร็จ')
    expect(wrapper.get('[data-test="house-account-name-shown"]').text()).toBe('ชื่อเก่า')
  })

  it('is not offered to a Company Admin', async () => {
    useAuthStore().user = { id: 2, name: 'ผู้ดูแลบริษัท', role: 'company_admin', company: AIA } as never
    const wrapper = await mountView({ commission_house_account: house(), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    expect(wrapper.find('[data-test="house-account-rename-open"]').exists()).toBe(false)
  })
})

/**
 * 2026-09-15 — THE LEADERS THIS PLAN WILL PAY NOTHING.
 *
 * ADR-035 makes a certification a GATE: an uncertified manager is SKIPPED by
 * the payout walk — no error, no log line, no ledger row. Every box on this
 * screen can be filled in correctly and the money still goes nowhere.
 *
 * The warning is computed server-side, so these tests are about the two
 * things the SCREEN can still get wrong: showing it when there is nothing to
 * say, and turning a capped list into a smaller-sounding problem.
 */
describe('the leaders who will silently be paid nothing', () => {
  beforeEach(() => {
    useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
  })

  function warning(over: Record<string, unknown> = {}) {
    return {
      total: 2,
      leaders: [
        { id: 7, name: 'สมชาย ใจดี', agents_under: 5 },
        { id: 8, name: 'สมหญิง รักดี', agents_under: 1 },
      ],
      ...over,
    }
  }

  it('says nothing at all when every leader is certified', async () => {
    // A permanent advisory about a thing that is not happening is a warning
    // people learn to scroll past — and this one has to still be readable on
    // the day it appears.
    const wrapper = await mountView({ leaders_missing_certification: { total: 0, leaders: [] } })
    await goToStep4(wrapper)

    expect(wrapper.find('[data-test="step4-uncertified-leaders"]').exists()).toBe(false)
  })

  it('names them, with the size of the team each one is costing', async () => {
    const wrapper = await mountView({ leaders_missing_certification: warning() })
    await goToStep4(wrapper)

    const box = wrapper.get('[data-test="step4-uncertified-leaders"]').text()
    expect(box).toContain('สมชาย ใจดี')
    expect(box).toContain('ลูกทีม 5 คน')
    // The consequence in words: skipped, not paid less, and with nothing said
    // at the time.
    expect(box).toContain('ข้ามไปเงียบ ๆ')
  })

  it('does not shrink the problem when the list is capped', async () => {
    // "3 of 47" is a different situation from "3", and a box that could only
    // render its sample would present the second.
    const wrapper = await mountView({ leaders_missing_certification: warning({ total: 47 }) })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="step4-uncertified-leaders"]').text()).toContain('47 คน')
    expect(wrapper.get('[data-test="uncertified-leaders-more"]').text()).toContain('45')
  })

  it('is shown to a Company Admin too', async () => {
    /*
     * The hide-don't-disable house rule is about CONTROLS a Company Admin may
     * not use. This is not a control — it is the reason their leaders are not
     * being paid, and they are the person who can get those people certified.
     */
    useAuthStore().user = { id: 2, name: 'ผู้ดูแลบริษัท', role: 'company_admin', company: AIA } as never
    const wrapper = await mountView({ leaders_missing_certification: warning() })
    await goToStep4(wrapper)

    expect(wrapper.find('[data-test="step4-uncertified-leaders"]').exists()).toBe(true)
  })
})

/**
 * 2026-09-15 (ครั้งที่สอง) — THE ACCOUNT THE COMPANY IS PAID INTO.
 *
 * Owner: "ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย". The seat stopped being a payee
 * that could never be paid and became one that can — which means it needs a
 * bank account, and this is the only screen that can give it one: PUT
 * /users/{id} refuses that row (UserPolicy::update, UserService), deliberately
 * and permanently, because the seat is not a person.
 */
describe('the company seat as a payee', () => {
  it('says on the summary row when it cannot be paid yet, and why that matters', async () => {
    const wrapper = await mountView({ commission_house_account: house(), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="house-account-bank-missing"]').text()).toContain('ตั้งจ่ายส่วนของบริษัทไม่ได้')
  })

  it('shows the account once it has one', async () => {
    const wrapper = await mountView({
      commission_house_account: house({
        bank_name: 'กสิกรไทย',
        bank_account_number: '1234567890',
        bank_account_holder_name: 'บริษัท ตัวอย่าง จำกัด',
        payout_details_complete: true,
      }),
      deepest_manager_chain: 1,
    })
    await goToStep4(wrapper)

    expect(wrapper.get('[data-test="house-account-bank-shown"]').text()).toContain('1234567890')
    expect(wrapper.find('[data-test="house-account-bank-missing"]').exists()).toBe(false)
  })

  it('prefills the form from what is stored, so a partial edit cannot wipe the rest', async () => {
    const wrapper = await mountView({
      commission_house_account: house({
        bank_name: 'กสิกรไทย',
        bank_account_number: '1234567890',
        bank_account_holder_name: 'บริษัท ตัวอย่าง จำกัด',
        payout_details_complete: true,
      }),
      deepest_manager_chain: 1,
    })
    await goToStep4(wrapper)

    await wrapper.get('[data-test="house-account-rename-open"]').trigger('click')

    expect((wrapper.get('[data-test="house-account-bank-number"]').element as HTMLInputElement).value).toBe('1234567890')
  })

  it('sends what was typed', async () => {
    put.mockResolvedValue({
      data: {
        commission_house_account: house({
          bank_name: 'กสิกรไทย',
          bank_account_number: '9876543210',
          bank_account_holder_name: 'บริษัท ตัวอย่าง จำกัด',
          payout_details_complete: true,
        }),
        deepest_manager_chain: 1,
      },
    })
    const wrapper = await mountView({ commission_house_account: house(), deepest_manager_chain: 1 })
    await goToStep4(wrapper)

    await wrapper.get('[data-test="house-account-rename-open"]').trigger('click')
    await wrapper.get('[data-test="house-account-bank-name"]').setValue('กสิกรไทย')
    await wrapper.get('[data-test="house-account-bank-number"]').setValue('9876543210')
    await wrapper.get('[data-test="house-account-bank-holder"]').setValue('บริษัท ตัวอย่าง จำกัด')
    await wrapper.get('[data-test="house-account-rename-save"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-house-account', {
      company_id: AIA.id,
      display_name: 'Thai Life insurance',
      bank_name: 'กสิกรไทย',
      bank_account_number: '9876543210',
      bank_account_holder_name: 'บริษัท ตัวอย่าง จำกัด',
    })
    // Repainted from the response rather than patched locally — the summary
    // row must not say "ยังไม่ได้กรอก" next to an account that was just saved.
    expect(wrapper.get('[data-test="house-account-bank-shown"]').text()).toContain('9876543210')
  })
})
