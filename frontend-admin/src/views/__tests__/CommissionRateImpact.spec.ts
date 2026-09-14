/**
 * "บันทึกแล้วจะเกิดอะไรขึ้น" — the preview inside the rate form (owner's
 * ข้อเสนอ 3, 2026-09-14).
 *
 * The form already refuses invalid input. What it cannot refuse are VALID
 * rates that do something other than what the admin pictured, and those are
 * the ones that cost money: a category rate touching products they forgot were
 * in that category, a company default that changes nothing because everything
 * overrides it, a rate that reaches nothing at all.
 *
 * These tests hold the three properties that make the panel worth having:
 *
 *   1. IT ASKS THE SERVER, with the same numbers the save will send. A preview
 *      that rounds differently from the write is wrong about the one figure it
 *      exists to show.
 *   2. IT STAYS QUIET WHEN IT CANNOT KNOW. An incomplete scope previews
 *      nothing rather than previewing a scope nobody chose.
 *   3. A FAILED PREVIEW IS NOT AN ERROR ABOUT THE RATE. The save is still
 *      valid; saying otherwise sends an admin hunting for a fault that is not
 *      in their configuration.
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

function product() {
  return {
    id: 1,
    company_id: AIA.id,
    name: 'AIA Health Plus',
    category: { id: 7, name: 'Anti Aging' },
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
}

const IMPACT = {
  layer: 'category',
  changed: [
    { product_id: 1, name: 'AIA Health Plus', before_satang: 30000, after_satang: 50000, unchanged: false },
  ],
  blocked: [
    { product_id: 2, name: 'Almond Chips', blocked_by: 'product', amount_satang: 4720 },
  ],
  changed_count: 1,
  blocked_count: 1,
  reaches_nothing: false,
}

async function mountView(impact: unknown = IMPACT) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) {
      return { state: 'ready', blocking_step: null, products_total: 1, products_covered: 1, issues: [], can_fix: true }
    }
    if (path.startsWith('/commission-resolution')) return { data: { products: [] } }
    if (path.startsWith('/commission-settings')) {
      return { data: { commission_plan_type: 'unilevel', commission_basis: 'price', commission_override_mode: 'additive', deepest_manager_chain: 0 } }
    }
    if (path.startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: [product()] }
    if (path.startsWith('/commission-override-rules')) return { data: [] }
    if (path.startsWith('/commission-rules')) return { data: [companyDefaultRule()] }

    return { data: [] }
  })

  post.mockImplementation(async (path: string) => {
    if (path === '/commission-rate-impact') {
      if (impact === 'fail') throw new Error('500')

      return { data: impact }
    }

    return { data: {} }
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
  await wrapper.get('[data-test="step-tab-3"]').trigger('click')
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

/**
 * Open the agent rate form on the existing company default and type a rate,
 * then let the debounce run.
 *
 * Edits the live rule rather than creating a new one because "+ ตั้งค่าเริ่มต้น
 * ทั้งบริษัท" only exists while there ISN'T one — and a company with no rates
 * at all is the case where the preview has least to say.
 */
async function openForm(wrapper: Wrapper) {
  await wrapper.get('[data-test="company-default-rule-10"]').findAll('button')[0]!.trigger('click')
  await flushPromises()
}

async function typeRate(wrapper: Wrapper, value: string) {
  await openForm(wrapper)
  await wrapper.findAll('input[type="number"]')[0]!.setValue(value)
  vi.advanceTimersByTime(500)
  await flushPromises()
}

function impactCalls() {
  return post.mock.calls.filter((c) => c[0] === '/commission-rate-impact')
}

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('the form says what the save will do', () => {
  it('asks the server with the converted rate, not the typed one', async () => {
    // 5 in the box is 500 basis points on the wire — the same conversion the
    // save performs. Off by 100 here and the panel is confidently wrong about
    // every figure it prints.
    const wrapper = await mountView()
    await typeRate(wrapper, '5')

    expect(impactCalls().length).toBeGreaterThan(0)
    const body = impactCalls()[impactCalls().length - 1]![1] as Record<string, unknown>
    expect(body.rate_value).toBe(500)
    expect(body.kind).toBe('agent')
    expect(body.company_id).toBe(AIA.id)
  })

  it('names the products that change and the ones a narrower rate shields', async () => {
    const wrapper = await mountView()
    await typeRate(wrapper, '5')

    const panel = wrapper.get('[data-test="rate-impact"]').text()
    expect(panel).toContain('1 สินค้าจะเปลี่ยน')
    expect(panel).toContain('AIA Health Plus')
    expect(panel).toContain('1 สินค้าไม่กระทบ')
    // A count says something is shielded; only the name says whether that was
    // the intention.
    expect(panel).toContain('Almond Chips')
  })

  it('calls out a rate that would reach nothing at all', async () => {
    // Never an error, almost never intended, and nothing else on the screen
    // would mention it.
    const wrapper = await mountView({ ...IMPACT, changed: [], blocked: [], changed_count: 0, blocked_count: 0, reaches_nothing: true })
    await typeRate(wrapper, '5')

    expect(wrapper.get('[data-test="rate-impact-nothing"]').text()).toContain('ยังไม่มีผลกับสินค้าตัวไหนเลย')
  })
})

describe('the panel stays quiet when it cannot know', () => {
  it('previews nothing before a rate is typed', async () => {
    const wrapper = await mountView()
    await openForm(wrapper)
    // Cleared, which is what an admin sees the moment they select the field
    // and start retyping — the panel must not preview the old number.
    await wrapper.findAll('input[type="number"]')[0]!.setValue('')
    vi.advanceTimersByTime(500)
    await flushPromises()

    expect(wrapper.find('[data-test="rate-impact-idle"]').exists()).toBe(true)
  })

  it('treats a failed preview as unknown, not as a problem with the rate', async () => {
    /*
     * The save is still perfectly valid. An error styled like a validation
     * failure would send an admin looking for a fault in their configuration
     * that is not there.
     */
    const wrapper = await mountView('fail')
    await typeRate(wrapper, '5')

    const panel = wrapper.get('[data-test="rate-impact-failed"]').text()
    expect(panel).toContain('บันทึกได้ตามปกติ')
  })
})
