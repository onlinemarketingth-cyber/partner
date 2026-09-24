/**
 * What the screen hands the chart — and the one value it must never claim.
 *
 * ═══ THE BUG THIS FILE WAS WRITTEN FOR ═══
 *
 * 2026-09-23. The plan chart had just been taught to draw each company's real
 * settings instead of its own constants. The Stairstep half shipped with a
 * rule that read well and was wrong: an EMPTY rank ladder was passed through
 * as a real answer, on the reasoning that a company running Stairstep with no
 * ranks pays no override and the chart ought to say so.
 *
 * The premise is false on this screen. CommissionPlansView loads each plan's
 * data LAZILY — `loadTab`, one fetch the first time a tab is opened — so
 * `agentRanks` is `[]` for every moment before the ranks tab is fetched, and
 * nothing at the seed line can tell that from "none configured".
 *
 * What the owner opened: a chart reporting บริษัทจ่ายออกรวม ฿0 on the UAT
 * Stairstep tenant, both rank dropdowns empty and ไม่มีขั้น under both boxes,
 * while the payout screen for the same company listed ฿2,000 across three
 * people.
 *
 * ═══ WHY IT IS TESTED HERE AND NOT IN THE COMPONENT ═══
 *
 * PlanShapePreview's own spec can only assert what it does with a seed it is
 * handed. The defect was in what the VIEW hands it — a distinction the
 * component cannot make and a test at that level cannot see, which is exactly
 * why the component suite stayed green through the whole thing.
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
import PlanShapePreview from '@/design-system/components/PlanShapePreview.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const AIA = { id: 2, name: 'AIA', slug: 'aia' }

const READY = { state: 'ready', blocking_step: null, products_total: 1, products_covered: 1, issues: [], can_fix: true }

function rank(id: number, name: string, sortOrder: number, rateValue: number, breakaway = false) {
  return {
    id,
    company_id: AIA.id,
    name,
    volume_threshold: 0,
    sort_order: sortOrder,
    rate_type: 'percentage',
    rate_value: rateValue,
    is_breakaway_rank: breakaway,
  }
}

/** The UAT Stairstep ladder, as /agent-ranks returns it. */
const UAT_LADDER = [
  rank(1, 'UAT ขั้นเริ่มต้น', 1, 500),
  rank(2, 'UAT ขั้นผู้นำ', 2, 1200),
  rank(3, 'UAT ขั้นผู้จัดการ', 3, 2000, true),
  rank(4, 'UAT ขั้นผู้อำนวยการ', 4, 2500),
]

function mockApi({ ranks = UAT_LADDER }: { ranks?: typeof UAT_LADDER } = {}) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      return {
        data: {
          commission_plan_type: 'stairstep_breakaway',
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
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) {
      return {
        data: [{
          id: 7, company_id: AIA.id, name: 'UAT Package', category: null,
          price_satang: 1_000_000, effective_price_satang: 1_000_000, pv_satang: null,
          commission_plan_type: null, effective_plan_type: 'stairstep_breakaway',
          commission_rate_type: null, is_sellable_here: true,
          permissions: { update: true, delete: true, set_commission_rule: true },
        }],
      }
    }
    if (path.startsWith('/agent-ranks')) return { data: ranks }
    if (path.startsWith('/commission-rules')) {
      return {
        data: [{
          id: 11, company_id: AIA.id, cert_tier: null, product: null, product_category: null,
          rate_type: 'percentage', rate_value: 500,
          effective_from: '2020-01-01T00:00:00.000000Z', effective_to: null,
          renewal_rate_type: null, renewal_rate_value: null, renewal_recurs: false,
        }],
      }
    }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [AIA]
  active.selectedId = AIA.id
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

/** The `seed` prop as the chart receives it. */
function seedOf(wrapper: Awaited<ReturnType<typeof mountStep2>>) {
  return wrapper.findComponent(PlanShapePreview).props('seed') as Record<string, unknown> | null
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
  wrappers.forEach(w => w.unmount())
  wrappers = []
})

describe('the rank ladder handed to the chart', () => {
  it('carries the company\'s real ranks once they have been fetched', async () => {
    mockApi()
    const wrapper = await mountStep2()
    wrappers.push(wrapper)

    expect(seedOf(wrapper)?.ranks).toEqual([
      { name: 'UAT ขั้นเริ่มต้น', thresholdSatang: 0, ratePct: 5, breakaway: false },
      { name: 'UAT ขั้นผู้นำ', thresholdSatang: 0, ratePct: 12, breakaway: false },
      { name: 'UAT ขั้นผู้จัดการ', thresholdSatang: 0, ratePct: 20, breakaway: true },
      { name: 'UAT ขั้นผู้อำนวยการ', thresholdSatang: 0, ratePct: 25, breakaway: false },
    ])
  })

  it('is NULL, never an empty list, when no ranks are in hand', async () => {
    /*
     * The regression. An empty array reaching the chart makes it draw a
     * company that pays nothing — and on a lazily-loaded screen an empty
     * array is mostly just "not fetched yet".
     *
     * Null means "keep the sandbox", which is the honest fallback: the chart
     * then also withholds its "these figures are the company's" note, so
     * nothing on screen claims the invented ladder is real.
     */
    mockApi({ ranks: [] })
    const wrapper = await mountStep2()
    wrappers.push(wrapper)

    expect(seedOf(wrapper)?.ranks).toBeNull()
  })

  it('draws a payout, not ฿0, for a company whose ladder is configured', async () => {
    // The end of the chain the owner was actually looking at: whatever the
    // seed contains, the number on screen must not read zero for a company
    // whose ledger pays.
    mockApi()
    const wrapper = await mountStep2()
    wrappers.push(wrapper)

    expect(wrapper.get('[data-test="plan-shape-total"]').text()).not.toContain('0 บาท · 0%')
  })
})
