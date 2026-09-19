/**
 * The admin screens for the five settings shipped on 2026-09-19.
 *
 * Every one of them existed only as an API until now, which is the same
 * failure the withdrawal floor had before it moved onto step 4: a setting the
 * server enforces and no screen can reach is a setting nobody uses, and the
 * first person to notice is an agent asking where their money went.
 *
 * What this file pins, and why each one is a regression on its own:
 *
 *   THE FIELDS LOAD WHAT IS SET. An empty depth field means "no cap" on this
 *   screen, so rendering one over a live cap would state the opposite of the
 *   truth. Same shape of bug as the floor's, one card over.
 *
 *   THE FIELDS WRITE. Wired-looking-but-unwired is worse than absent: the
 *   admin types, nothing complains, and they walk away believing it saved.
 *
 *   EMPTY AND ZERO STAY DIFFERENT. On the level, the depth and the tax rate,
 *   empty is a real instruction ("all levels" / "no cap" / "no withholding")
 *   and 0 is either refused or a different promise entirely.
 *
 *   THE LADDER IS UNILEVEL-ONLY. It is the one plan whose payout walks the
 *   chain level by level; offering the controls elsewhere is a knob that does
 *   nothing.
 *
 *   THE TAX NEVER TOUCHES THE GROSS ON SCREEN. The big number stays what the
 *   agent earned; the net is stated beside it. A screen that showed only one
 *   of the two would either misstate the ledger or misstate the transfer.
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

/** A leader rate. `level` null is the catch-all every rate on this system is today. */
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

interface Settings {
  planType?: string
  maxOverrideDepth?: number | null
  overrideCompression?: boolean
  whtRate?: number | null
  leaderRates?: ReturnType<typeof leaderRate>[]
  withdrawalReadFails?: boolean
}

function mockApi(opts: Settings = {}) {
  const {
    planType = 'unilevel',
    maxOverrideDepth = null,
    overrideCompression = false,
    whtRate = null,
    leaderRates = [],
    withdrawalReadFails = false,
  } = opts

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      return {
        data: {
          commission_plan_type: planType,
          commission_basis: 'price',
          commission_override_mode: 'additive',
          deepest_manager_chain: 4,
          max_override_depth: maxOverrideDepth,
          override_compression: overrideCompression,
        },
      }
    }
    if (path.startsWith('/commission-withdrawal-settings')) {
      if (withdrawalReadFails) throw new Error('500')

      return { min_withdrawal_satang: 100000, wht_rate: whtRate }
    }
    if (path.startsWith('/commission-withdrawals')) return { data: [] }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: [product()] }
    if (path.startsWith('/commission-override-rules')) return { data: leaderRates }
    if (path.startsWith('/commission-rules')) return { data: [companyDefaultRule()] }
    if (path.startsWith('/agent-rank-settings')) {
      return { data: { trailing_window_days: 90, volume_scope: 'personal', recalculation_frequency: 'monthly' } }
    }
    if (path.startsWith('/agent-ranks')) return { data: [] }

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
        PlanShapePreview: true,
        RateResolutionMatrix: true,
        RateImpactPreview: true,
      },
    },
  })
  await flushPromises()
  await wrapper.get('[data-test="step-tab-4"]').trigger('click')
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

// ── R2 · how many levels, and what each one gets ────────────────────────────

describe('the level ladder (Unilevel)', () => {
  it('loads the current cap into the field', async () => {
    mockApi({ maxOverrideDepth: 3 })

    const wrapper = await mountStep4()

    expect((wrapper.get('[data-test="override-depth-input"]').element as HTMLInputElement).value).toBe('3')
  })

  it('renders an EMPTY field when there is no cap, because empty is what "no cap" means here', async () => {
    mockApi({ maxOverrideDepth: null })

    const wrapper = await mountStep4()

    expect((wrapper.get('[data-test="override-depth-input"]').element as HTMLInputElement).value).toBe('')
  })

  it('saves the cap, and an emptied field clears it rather than saving zero', async () => {
    /*
     * A cap of 0 would mean "pay nobody", which is a different instruction
     * entirely from "pay all the way up" — and it is reachable by clearing a
     * field whose empty state already means the second one.
     */
    mockApi({ maxOverrideDepth: 3 })

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="override-depth-input"]').setValue('')
    await wrapper.get('[data-test="override-depth-save"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-settings', { company_id: 2, max_override_depth: null })
    expect(wrapper.get('[data-test="override-depth-message"]').text()).toContain('ทั้งสาย')
  })

  it('refuses a cap of zero without calling the server', async () => {
    mockApi()

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="override-depth-input"]').setValue('0')
    await wrapper.get('[data-test="override-depth-save"]').trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="override-depth-message"]').text()).toContain('1–100')
  })

  it('shows nothing at all on a plan whose payout does not walk the chain', async () => {
    // Matrix has its own structure tab with its own per-level rates. A second
    // depth control here would be a knob that does nothing.
    mockApi({ planType: 'matrix' })

    const wrapper = await mountStep4()

    expect(wrapper.find('[data-test="step4-level-ladder"]').exists()).toBe(false)
  })

  it('labels every leader rate with its level, including the catch-all', async () => {
    /*
     * A badge only on the levelled rows would leave an admin reading the
     * unlabelled ones as level 1 — the opposite of what null means, and the
     * difference between paying one person and paying the whole chain.
     */
    mockApi({ leaderRates: [leaderRate(1, 1, 500), leaderRate(2, null, 100)] })

    const wrapper = await mountStep4()

    expect(wrapper.get('[data-test="leader-rule-level-1"]').text()).toBe('ชั้นที่ 1')
    expect(wrapper.get('[data-test="leader-rule-level-2"]').text()).toBe('ทุกชั้น')
  })

  it('warns about a level nobody priced, when there is no catch-all to cover it', async () => {
    // Levels 1 and 3 priced, 2 missing: the server pays 1 and 3 as normal and
    // nobody at 2, which is almost never what was meant and has no row to
    // look at.
    mockApi({ leaderRates: [leaderRate(1, 1, 500), leaderRate(3, 3, 100)] })

    const wrapper = await mountStep4()

    expect(wrapper.get('[data-test="level-gap-warning"]').text()).toContain('ชั้นที่ 2')
  })

  it('does not warn about a gap when a catch-all covers it', async () => {
    mockApi({ leaderRates: [leaderRate(1, 1, 500), leaderRate(3, 3, 100), leaderRate(9, null, 50)] })

    const wrapper = await mountStep4()

    expect(wrapper.find('[data-test="level-gap-warning"]').exists()).toBe(false)
  })
})

// ── R3 · compression ────────────────────────────────────────────────────────

describe('compression', () => {
  it('renders the switch in its true position', async () => {
    mockApi({ overrideCompression: true })

    const wrapper = await mountStep4()

    expect((wrapper.get('[data-test="override-compression-toggle"]').element as HTMLInputElement).checked).toBe(true)
  })

  it('saves the switch on its own, without touching the cap', async () => {
    // Two settings on one endpoint: sending the depth alongside would re-assert
    // a cap the admin never opened, and absence is how this endpoint says
    // "leave it alone".
    mockApi({ overrideCompression: false })

    const wrapper = await mountStep4()
    const toggle = wrapper.get('[data-test="override-compression-toggle"]')
    ;(toggle.element as HTMLInputElement).checked = true
    await toggle.trigger('change')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-settings', { company_id: 2, override_compression: true })
  })
})

// ── The tax ─────────────────────────────────────────────────────────────────

describe('withholding tax', () => {
  it('loads the rate as a percent, not as basis points', async () => {
    mockApi({ whtRate: 300 })

    const wrapper = await mountStep4()

    expect((wrapper.get('[data-test="wht-input"]').element as HTMLInputElement).value).toBe('3')
  })

  it('saves a percent as basis points, and carries the untouched floor with it', async () => {
    /*
     * The endpoint requires min_withdrawal_satang to be present, so the tax
     * save has to send the floor — UNCHANGED. Sending null to satisfy the rule
     * would silently delete a floor nobody touched.
     */
    mockApi({ whtRate: null })

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="wht-input"]').setValue('3')
    await wrapper.get('[data-test="wht-save"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-withdrawal-settings?company_id=2', {
      min_withdrawal_satang: 100000,
      wht_rate: 300,
    })
  })

  it('an emptied field clears the rate rather than saving zero', async () => {
    mockApi({ whtRate: 300 })

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="wht-input"]').setValue('')
    await wrapper.get('[data-test="wht-save"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-withdrawal-settings?company_id=2', {
      min_withdrawal_satang: 100000,
      wht_rate: null,
    })
    expect(wrapper.get('[data-test="wht-message"]').text()).toContain('ไม่หักภาษี')
  })

  it('refuses a rate above 100% without calling the server', async () => {
    mockApi()

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="wht-input"]').setValue('150')
    await wrapper.get('[data-test="wht-save"]').trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="wht-message"]').text()).toContain('0–100')
  })

  it('hides the field when the read failed, instead of showing an empty one', async () => {
    // Empty MEANS "no withholding" on this control, so rendering one after a
    // failed read would state a tax position the screen never learned.
    mockApi({ withdrawalReadFails: true })

    const wrapper = await mountStep4()

    expect(wrapper.find('[data-test="wht-input"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="wht-unknown"]').text()).toContain('อ่านค่าปัจจุบันไม่สำเร็จ')
  })

  it('will not save the tax while the floor beside it is unreadable', async () => {
    /*
     * The floor travels with this request. A floor the admin has typed and
     * left invalid would otherwise be sent as null and DELETE a setting they
     * were not editing.
     */
    mockApi({ whtRate: null })

    const wrapper = await mountStep4()
    await wrapper.get('[data-test="withdrawal-min-input"]').setValue('-5')
    await wrapper.get('[data-test="wht-input"]').setValue('3')
    await wrapper.get('[data-test="wht-save"]').trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="wht-message"]').text()).toContain('ยอดขั้นต่ำ')
  })
})

// ── The tax, where the money is actually moved ──────────────────────────────

function withdrawalRequest(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    agent_id: 3,
    agent_name: 'สมชาย ใจดี',
    amount_satang: 150000,
    wht_rate_at_time: null,
    wht_satang: 0,
    net_transfer_satang: 150000,
    status: 'approved',
    status_label: 'รอโอน',
    rejection_reason: null,
    decided_at: null,
    decided_by: null,
    transferred_at: null,
    transfer_reference: null,
    bank_name: null,
    bank_account_number_masked: null,
    bank_account_holder_name: null,
    item_count: 1,
    created_at: '2026-09-19T00:00:00.000000Z',
    ...overrides,
  }
}

async function mountQueue(rows: ReturnType<typeof withdrawalRequest>[]) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-withdrawals')) return { data: rows }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null, wht_rate: null }

    return { data: [] }
  })

  const wrapper = mount(CommissionWithdrawalsView, {
    global: { stubs: { HeroHeader: true, EmptyState: true, Icon: true, LoadingSkeleton: true } },
  })
  await flushPromises()

  return wrapper
}

describe('the payout queue states gross and net', () => {
  it('shows what leaves the bank beside what the agent earned', async () => {
    const wrapper = await mountQueue([
      withdrawalRequest({ wht_rate_at_time: 300, wht_satang: 4500, net_transfer_satang: 145500 }),
    ])

    const line = wrapper.get('[data-test="withdrawal-wht-7"]').text()
    expect(line).toContain('3%')
    expect(line).toContain('โอนจริง')
    // The GROSS is still the headline: it is what the agent earned and what
    // the ledger will settle.
    expect(wrapper.text()).toContain('1,500.00')
    expect(line).toContain('1,455.00')
  })

  it('says nothing at all when nothing was withheld', async () => {
    // A "0.00 tax" line on every row of every company that does not withhold
    // is noise, and noise on a payout screen is how a real deduction stops
    // being read.
    const wrapper = await mountQueue([withdrawalRequest()])

    expect(wrapper.find('[data-test="withdrawal-wht-7"]').exists()).toBe(false)
  })
})
