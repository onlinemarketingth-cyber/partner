/**
 * THE ORDER OF STEP 2, AND WHERE THE UNILEVEL RATE FORM LIVES.
 *
 * ═══ WHAT THIS IS GUARDING ═══
 *
 * 2026-09-24. The owner: "การ Setup ต่างๆ ของ UI มันต้องทำการตั้งค่าก่อน Demo
 * และการ Demo ตัวอย่างต้องชัดเจน และตอนนี้การตั้งค่าก็กระโดดไปมา".
 *
 * Step 2 used to run: plan → basis → EXAMPLE → PV table → structure. The
 * example was drawn from settings that appeared two screens below it, so an
 * admin met the consequence before any of its causes. On a Stairstep company
 * that produced a concrete misreading — the card's read-only rank ladder was
 * taken for the plan's settings, and the percentages reported as hardcoded.
 *
 * It now runs: plan → basis → PV → structure → EXAMPLE.
 *
 * ═══ AND THE EXCEPTION THAT MADE IT WORSE ═══
 *
 * Unilevel's real rate form rendered INSIDE the example card, through a
 * `rates` slot. So on the default plan "the settings are in the example
 * card" was true, and five other plans inherited a lesson that was false for
 * them. The form moved out beside every other plan's structure.
 *
 * Both facts are invisible to a component test — PlanShapePreview cannot see
 * what is rendered around it — so they are asserted here, against the real
 * template, by document order.
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

const UAT_LADDER = [
  { id: 1, company_id: CO.id, name: 'UAT ขั้นเริ่มต้น', volume_threshold: 0, sort_order: 1, rate_type: 'percentage', rate_value: 500, is_breakaway_rank: false },
  { id: 2, company_id: CO.id, name: 'UAT ขั้นผู้จัดการ', volume_threshold: 20_000_000, sort_order: 2, rate_type: 'percentage', rate_value: 2_000, is_breakaway_rank: true },
]

function mockApi(planType: 'stairstep_breakaway' | 'unilevel') {
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
    if (path.startsWith('/agent-ranks')) return { data: planType === 'stairstep_breakaway' ? UAT_LADDER : [] }
    if (path.startsWith('/commission-override-rules')) {
      return {
        data: [{
          id: 31, company_id: CO.id, product: null, product_category: null, level: 1,
          rate_type: 'percentage', rate_value: 500,
          effective_from: '2020-01-01T00:00:00.000000Z', effective_to: null,
        }],
      }
    }
    if (path.startsWith('/commission-rules')) {
      return {
        data: [{
          id: 11, company_id: CO.id, cert_tier: null, product: null, product_category: null,
          rate_type: 'percentage', rate_value: 500,
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

async function mountStep2() {
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

  return wrapper
}

/** Where a marker sits in the rendered document, or -1. */
function positionOf(html: string, testId: string): number {
  return html.indexOf(`data-test="${testId}"`)
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

describe('step 2 puts the settings before the example', () => {
  beforeEach(() => mockApi('stairstep_breakaway'))

  it('renders the Stairstep ladder above the preview card', async () => {
    const wrapper = await mountStep2()
    wrappers.push(wrapper)
    const html = wrapper.html()

    const settings = positionOf(html, 'plan-structure-ranks')
    const example = positionOf(html, 'plan-shape-preview')

    expect(settings).toBeGreaterThan(-1)
    expect(example).toBeGreaterThan(-1)
    // Document order IS the reading order here — both are in the same
    // single-column step panel, neither is absolutely positioned.
    expect(settings).toBeLessThan(example)
  })

  it('renders the plan chooser above the settings', async () => {
    // The order the whole step depends on: you cannot configure a structure
    // before choosing which structure you mean.
    const wrapper = await mountStep2()
    wrappers.push(wrapper)
    const html = wrapper.html()

    expect(positionOf(html, 'plan-explainer')).toBeLessThan(positionOf(html, 'plan-structure-ranks'))
  })

  it('keeps the jump button, which is the way back up from the example', async () => {
    /*
     * With the example last, the settings are ABOVE it — so the button in
     * its header scrolls backwards. That is the point: an admin who reaches
     * the bottom, dislikes a figure, and wants the control that produced it
     * should not have to hunt upward for it.
     */
    const wrapper = await mountStep2()
    wrappers.push(wrapper)

    expect(wrapper.find('[data-test="plan-shape-structure-jump"]').exists()).toBe(true)
  })
})

describe('the Unilevel rate form', () => {
  beforeEach(() => mockApi('unilevel'))

  it('renders inside the structure block, not inside the example card', async () => {
    const wrapper = await mountStep2()
    wrappers.push(wrapper)
    const html = wrapper.html()

    const structure = positionOf(html, 'plan-structure-unilevel')
    const editor = positionOf(html, 'level-ladder-editor')
    const example = positionOf(html, 'plan-shape-preview')

    expect(structure).toBeGreaterThan(-1)
    expect(editor).toBeGreaterThan(-1)
    // Between the block that opens it and the card that follows: inside the
    // former, outside the latter.
    expect(editor).toBeGreaterThan(structure)
    expect(editor).toBeLessThan(example)
  })

  it('prices the baht column against the real product, not the sandbox', async () => {
    /*
     * The one behaviour that changed with the move. Inside the card the
     * column followed the sandbox price box, so figures beside a SAVED rate
     * moved when somebody nudged the demo. It now states its own base.
     */
    const wrapper = await mountStep2()
    wrappers.push(wrapper)

    const note = wrapper.get('[data-test="ladder-base-note"]').text()

    expect(note).toContain('ราคาขาย')
    expect(note).toContain('UAT Package')
    // ฿10,000 — the product's real price, not the sandbox's default 9,900.
    expect(note).toContain('10,000')
  })
})

describe('browsing a plan the company does not run', () => {
  it('shows no live Unilevel editor on a Stairstep company', async () => {
    /*
     * Inside the card this form was gated on `levelsAreLive`, false while
     * merely browsing the Unilevel chip. Moving it out would have handed a
     * live editor for commission_override_rules — the table Unilevel AND
     * Affiliate are paid from — to somebody looking at a plan this company
     * does not use. The gate moved with the form.
     */
    mockApi('stairstep_breakaway')
    const wrapper = await mountStep2()
    wrappers.push(wrapper)

    await wrapper.get('[data-test="plan-chip-unilevel"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="unilevel-browse-only"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="level-ladder-editor"]').exists()).toBe(false)
  })
})
