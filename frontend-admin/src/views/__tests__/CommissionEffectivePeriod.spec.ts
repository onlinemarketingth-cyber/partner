/**
 * "แก้ UI ให้มีการตั้งใช้ตลอดระยะเวลาเป็นค่าเริ่มต้น และการเลือกเวลาเป็น Option
 * กับการตั้งค่า commission ทุกประเภท" (owner, 2026-09-14).
 *
 * Every commission form used to open with two date pickers side by side — six
 * <select>s — which is backwards twice over. The common answer is "from now
 * until I change it", so the normal case was the one you had to work for; and
 * the pickers INVITED an end date, which is the most dangerous thing on the
 * form: an expired rate does not stop paying, it silently drops the product
 * down the resolution ladder to the category or company rate, and the ledger
 * rows it produced can never be corrected (BR-4).
 *
 * So "ใช้ตลอด" is the default and the dates are behind a deliberate choice.
 * These tests pin the three properties that make that safe rather than merely
 * tidier:
 *
 *   1. THE DEFAULT REALLY SAVES open-ended. A pretty toggle that still posts
 *      an end date would be worse than the two pickers.
 *   2. EDITING KEEPS THE ORIGINAL START DATE. Re-stamping an existing rate
 *      with today, in the course of changing its percentage, quietly rewrites
 *      when it began — a fact reports are read against.
 *   3. A RATE THAT IS NOT "ALWAYS" OPENS AS NOT-ALWAYS. An end date, or a
 *      start date in the future, is a scheduled rate; showing it as ใช้ตลอด
 *      would tell the admin it is in force when it pays nobody yet.
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
const TODAY = new Date().toISOString().slice(0, 10)
const NEXT_YEAR = `${new Date().getFullYear() + 1}-10-01`

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

function agentRule() {
  return {
    id: 11,
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
}

function leaderRule(over: Record<string, unknown> = {}) {
  return {
    id: 21,
    company_id: AIA.id,
    manager_cert_tier: null,
    product: null,
    product_category: null,
    rate_type: 'percentage',
    rate_value: 200,
    override_mode: null,
    effective_from: '2020-01-01',
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

async function mountView(overrideRules: unknown[] = [leaderRule()]) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      return { data: { commission_plan_type: 'unilevel', commission_basis: 'price', commission_override_mode: 'additive', deepest_manager_chain: 1 } }
    }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: [product()] }
    if (path.startsWith('/commission-override-rules')) return { data: overrideRules }
    if (path.startsWith('/commission-rules')) return { data: [agentRule()] }

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
        CommissionSplitSettingCard: true,
      },
    },
  })
  await flushPromises()
  await wrapper.get('[data-test="step-tab-4"]').trigger('click')
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

// Array.prototype.at() is ES2022 and this project's lib target predates it —
// caught by `npm run type-check`, which type-checks the specs, and not by
// vitest, which only transpiles them.
function lastBody(calls: unknown[][]): Record<string, unknown> {
  return calls[calls.length - 1]?.[1] as Record<string, unknown>
}

function lastOverridePost(): Record<string, unknown> {
  return lastBody(post.mock.calls.filter((c) => c[0] === '/commission-override-rules'))
}

function lastOverridePut(): Record<string, unknown> {
  return lastBody(put.mock.calls.filter((c) => String(c[0]).startsWith('/commission-override-rules/')))
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('a new commission rate is open-ended by default', () => {
  it('opens on ใช้ตลอด, with no date pickers in the way', async () => {
    const wrapper = await mountView()
    await wrapper.get('[data-test="add-leader-rate-company"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="override-period-summary"]').text()).toContain('ไม่มีวันสิ้นสุด')
    expect(wrapper.find('[data-test="override-period-dates"]').exists()).toBe(false)
  })

  it('actually saves it open-ended, starting today', async () => {
    // THE TEST THAT MAKES THE TOGGLE MORE THAN DECORATION.
    const wrapper = await mountView()
    await wrapper.get('[data-test="add-leader-rate-company"]').trigger('click')
    await wrapper.get('[data-test="override-form-rate-value"]').setValue('2')
    await wrapper.get('[data-test="override-form"]').trigger('submit')
    await flushPromises()

    expect(lastOverridePost().effective_from).toBe(TODAY)
    expect(lastOverridePost().effective_to).toBeNull()
  })

  it('reveals the pickers only when the admin asks for a period', async () => {
    const wrapper = await mountView()
    await wrapper.get('[data-test="add-leader-rate-company"]').trigger('click')
    await wrapper.get('[data-test="override-period-custom"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="override-period-dates"]').exists()).toBe(true)
    // And it warns about the thing the form cannot show: an end date is not a
    // stop button, it is a handover to the next rung of the ladder.
    expect(wrapper.get('[data-test="override-period-dates"]').text()).toContain('ตกไปใช้อัตราชั้นที่กว้างกว่า')
  })

  it('clears any end date when the admin goes back to ใช้ตลอด', async () => {
    const wrapper = await mountView()
    await wrapper.get('[data-test="add-leader-rate-company"]').trigger('click')
    await wrapper.get('[data-test="override-period-custom"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="override-period-always"]').trigger('click')
    await wrapper.get('[data-test="override-form-rate-value"]').setValue('2')
    await wrapper.get('[data-test="override-form"]').trigger('submit')
    await flushPromises()

    expect(lastOverridePost().effective_to).toBeNull()
  })
})

describe('editing an existing rate never re-stamps when it began', () => {
  it('shows the original start date rather than today', async () => {
    const wrapper = await mountView([leaderRule({ effective_from: '2020-01-01', effective_to: null })])
    await wrapper.get('[data-test="edit-leader-rule-21"]').trigger('click')
    await flushPromises()

    const summary = wrapper.get('[data-test="override-period-summary"]').text()
    expect(summary).toContain('ไม่มีวันสิ้นสุด')
    expect(summary).not.toContain('วันนี้')
  })

  it('saves the stored start date back, unchanged', async () => {
    /*
     * The regression this exists to catch: "ใช้ตลอด" resolving to today would
     * move a rate's start date every time somebody opened it to change the
     * percentage — silently, and in a direction nobody asked for.
     */
    const wrapper = await mountView([leaderRule({ effective_from: '2020-01-01', effective_to: null })])
    await wrapper.get('[data-test="edit-leader-rule-21"]').trigger('click')
    await wrapper.get('[data-test="override-form-rate-value"]').setValue('3')
    await wrapper.get('[data-test="override-form"]').trigger('submit')
    await flushPromises()

    expect(lastOverridePut().effective_from).toBe('2020-01-01')
    expect(lastOverridePut().effective_to).toBeNull()
  })
})

describe('a rate that is not open-ended opens as not open-ended', () => {
  it('shows the period controls for a rate with an end date', async () => {
    const wrapper = await mountView([leaderRule({ effective_from: '2020-01-01', effective_to: '2030-12-31' })])
    await wrapper.get('[data-test="edit-leader-rule-21"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="override-period-dates"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="override-period-summary"]').exists()).toBe(false)
  })

  it('treats a future start date as scheduled, not as ใช้ตลอด', async () => {
    // It has no end date, so the naive rule would call it "always" — and tell
    // the admin a rate that pays nobody yet is in force.
    const wrapper = await mountView([leaderRule({ effective_from: NEXT_YEAR, effective_to: null })])
    await wrapper.get('[data-test="edit-leader-rule-21"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="override-period-dates"]').exists()).toBe(true)
  })
})

describe('the same default reaches every commission form', () => {
  it('applies to the agent rate in step 3', async () => {
    const wrapper = await mountView()
    await wrapper.get('[data-test="step-tab-3"]').trigger('click')
    await flushPromises()
    // "+ เพิ่มอัตราของสินค้า" stood here until 2026-09-14, when the per-product
    // card list merged into the resolution table and its per-row สินค้า cell
    // became the only door to a product-scoped rate. The category button opens
    // the same form with a different scope, which is what this test is about.
    await wrapper.get('[data-test="add-category-rate"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="rule-period-summary"]').text()).toContain('ไม่มีวันสิ้นสุด')
  })
})
