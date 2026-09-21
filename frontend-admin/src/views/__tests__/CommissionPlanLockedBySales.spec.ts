/**
 * Step 2 stops offering a switch the server will refuse.
 *
 * ═══ WHY THIS FILE EXISTS ═══
 *
 * The refusal has been enforced server-side since 2026-09-19, and for two
 * days the screen contradicted it: the "ใช้แผนนี้" button stayed, under a
 * banner reading "การสลับแผนมีผลกับการขายครั้งถัดไปเท่านั้น", and the admin
 * found out by pressing.
 *
 * Then on 2026-09-21 the owner found the second half of the same bug against
 * their own production data — "คือมันมี Order ไง". The lock counted
 * commission_ledger alone, so a company with fourteen orders marked
 * ชำระเงินแล้ว and nothing booked was still offered the switch. The rule is
 * now EITHER a paid order OR a commission row, and the screen has to be able
 * to say WHICH, because "มีค่าแนะนำลงบัญชีแล้ว 0 รายการ" over fourteen paid
 * orders is the sentence that sent them looking for a bug in the lock.
 *
 * So these tests are about the two things the screen owes the reader: the
 * control is gone BEFORE the press, and the sentence describes the situation
 * they are actually in.
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

function product() {
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
  }
}

function sellerRule() {
  return {
    id: 11,
    company_id: AIA.id,
    cert_tier: null,
    product: null,
    product_category: null,
    rate_type: 'percentage',
    rate_value: 1000,
    effective_from: '2020-01-01T00:00:00.000000Z',
    effective_to: null,
    renewal_rate_type: null,
    renewal_rate_value: null,
    renewal_recurs: false,
  }
}

/** `undefined` means the key is absent from the payload entirely. */
function mockApi(lock: Record<string, unknown> | undefined) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      const data: Record<string, unknown> = {
        commission_plan_type: 'unilevel',
        commission_basis: 'price',
        commission_override_mode: 'additive',
        deepest_manager_chain: 3,
        max_override_depth: null,
        override_compression: false,
      }
      if (lock !== undefined) data.plan_locked_by_sales = lock

      return { data }
    }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null, wht_rate: null }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: [product()] }
    if (path.startsWith('/commission-override-rules')) return { data: [] }
    if (path.startsWith('/commission-rules')) return { data: [sellerRule()] }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [AIA]
  active.selectedId = AIA.id
}

const UNLOCKED = { locked: false, paid_orders: 0, ledger_rows: 0, first_sale_at: null }

/**
 * Step 2, looking at a plan the company does NOT run — which is the only
 * state in which the switch is on offer, and therefore the only state in
 * which taking it away is visible.
 */
async function browsingAnotherPlan() {
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
  await wrapper.get('[data-test="plan-chip-binary"]').trigger('click')
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

// ── Before the first sale, it is still a setup screen ───────────────────────

describe('nothing sold yet', () => {
  it('still offers the switch', async () => {
    // The lock must not spread to a company that has not sold anything —
    // that would leave an admin unable to finish their own first setup.
    mockApi(UNLOCKED)

    const wrapper = await browsingAnotherPlan()

    expect(wrapper.find('[data-test="use-this-plan"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="plan-locked-badge"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="plan-switch-note"]').exists()).toBe(true)
  })

  it('treats a payload with no lock key at all as unlocked', async () => {
    /*
     * The defensive half, and it matters right now: the admin build ships
     * ahead of the API on this deploy, so for a while the screen will be
     * reading responses that predate the field. Guessing "locked" there would
     * freeze every company's setup screen on an older backend.
     */
    mockApi(undefined)

    const wrapper = await browsingAnotherPlan()

    expect(wrapper.find('[data-test="use-this-plan"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="plan-locked-badge"]').exists()).toBe(false)
  })
})

// ── Once it has sold, the control is gone before the press ──────────────────

describe('locked by sales', () => {
  it('takes the switch away and says so in its place', async () => {
    mockApi({ locked: true, paid_orders: 14, ledger_rows: 0, first_sale_at: '2026-08-22T11:27:00+07:00' })

    const wrapper = await browsingAnotherPlan()

    expect(wrapper.find('[data-test="use-this-plan"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="plan-locked-badge"]').text()).toContain('เปลี่ยนไม่ได้แล้ว')
  })

  it('names the orders when no commission has been booked against them', async () => {
    /*
     * THE OWNER'S BUG, in one assertion. Fourteen paid orders and an empty
     * ledger: the banner must say what it can see, not report a zero.
     */
    mockApi({ locked: true, paid_orders: 14, ledger_rows: 0, first_sale_at: '2026-08-22T11:27:00+07:00' })

    const wrapper = await browsingAnotherPlan()
    const note = wrapper.get('[data-test="plan-locked-note"]').text()

    expect(note).toContain('ออเดอร์ที่ชำระเงินแล้ว 14 รายการ')
    expect(note).not.toContain('0 รายการ')
    expect(wrapper.find('[data-test="plan-switch-note"]').exists()).toBe(false)
  })

  it('names both when there are orders and commission rows', async () => {
    mockApi({ locked: true, paid_orders: 14, ledger_rows: 3, first_sale_at: '2026-08-22T11:27:00+07:00' })

    const note = (await browsingAnotherPlan()).get('[data-test="plan-locked-note"]').text()

    expect(note).toContain('14 ออเดอร์')
    expect(note).toContain('3 รายการ')
  })

  it('names the commission rows when there is no order behind them', async () => {
    // Reachable without any order: a renewal commission, or a promotion bonus
    // written straight to the ledger.
    mockApi({ locked: true, paid_orders: 0, ledger_rows: 2, first_sale_at: '2026-08-22T11:27:00+07:00' })

    const note = (await browsingAnotherPlan()).get('[data-test="plan-locked-note"]').text()

    expect(note).toContain('ค่าแนะนำที่ลงบัญชีไปแล้ว 2 รายการ')
    expect(note).not.toContain('ออเดอร์')
  })

  it('locks the basis buttons too, because the same rule governs them', async () => {
    // The basis decides what every percentage is a percentage OF. Changing it
    // after a sale is the same contradiction as changing the plan, and the
    // server refuses both — so both have to be off the screen.
    mockApi({ locked: true, paid_orders: 14, ledger_rows: 0, first_sale_at: '2026-08-22T11:27:00+07:00' })

    const wrapper = await browsingAnotherPlan()

    expect(wrapper.get('[data-test="basis-option-pv"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="basis-option-price"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="basis-locked-note"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="basis-switch-note"]').exists()).toBe(false)
  })
})
