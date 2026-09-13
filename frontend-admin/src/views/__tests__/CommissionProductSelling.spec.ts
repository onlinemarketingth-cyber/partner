/**
 * Step 3's per-row "เปิดขาย / ปิดขาย" switch — 2026-09-12, the owner's words:
 *
 *   "ให้แสดงผลเหมือนหน้าสินค้า ให้เห็นว่าสินค้าไหนเปิดหรือปิดอยู่ ขึ้นเป็นสีเทาไว้
 *    เรียงจากเปิดก่อน แต่ที่ปิดไว้ก็แก้ไขได้เหมือนเดิม เพิ่มการเปิดปิดสินค้าได้เลย
 *    จะได้ทำหน้าเดียวจบ แต่ทำแยกบริษัทได้"
 *
 * Three of those clauses are the sort of thing a refactor drops without failing
 * anything else, and each one is a real bug when it goes:
 *
 *   1. "ที่ปิดไว้ก็แก้ไขได้เหมือนเดิม" — greying a row is a COLOUR, not a lock.
 *      The reason to look at a closed product here is to give it a rate before
 *      it reopens. A `disabled` that creeps onto the rate button would look
 *      tidy and quietly remove the only reason the row is still in the list.
 *   2. THE ENDPOINT BRANCH. A shared product's on-sale state is the company's
 *      (PUT /products/{id}/company-settings); a company-owned product's is its
 *      own `is_active` (PUT /products/{id}), and the first endpoint answers 422
 *      for it on purpose. One `put` call in the wrong branch is a switch that
 *      throws for half the catalogue — and nothing type-checks that.
 *   3. THE HOUSE RULE (2026-09-11): a control somebody cannot use is not shown.
 *      A Company Admin may not open a SHARED product anywhere in this system,
 *      so they get the state and no switch — a switch you can see and cannot
 *      move reads as broken, not as forbidden.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
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

/**
 * A product this company owns. `is_shared: false` + its own company_id is the
 * branch whose on/off switch is the product's own `is_active`, gated per row
 * by `permissions.update`.
 */
function ownedProduct(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    company_id: AIA.id,
    is_shared: false,
    is_sellable_here: true,
    name: 'AIA Health Plus',
    category: null,
    price_satang: 890000,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    commission_rate_type: null,
    permissions: { update: true, delete: true, set_commission_rule: true },
    ...over,
  }
}

/**
 * A PLATFORM product (ADR-040): one row every company sells, `company_id`
 * null. Its on/off switch is the company's own settings row, Super-Admin-only.
 *
 * `permissions.update` is deliberately TRUE here in the Company Admin test
 * below as well — the point is that the shared branch must not consult it. A
 * fixture that said false would pass even if the code asked the wrong
 * question.
 */
function sharedProduct(over: Record<string, unknown> = {}) {
  return {
    id: 2,
    company_id: null,
    is_shared: true,
    is_sellable_here: true,
    name: 'แพ็กเกจกลาง',
    category: null,
    price_satang: 500000,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    commission_rate_type: null,
    permissions: { update: true, delete: true, set_commission_rule: true },
    ...over,
  }
}

/** A live company-wide agent rate, so step 3 is reachable and every row resolves. */
const COMPANY_RULE = {
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

/** A live company-wide LEADER rate, so no row reads ยังไม่ครบ for step 4's sake. */
const LEADER_RULE = {
  id: 20,
  company_id: AIA.id,
  product: null,
  product_category: null,
  manager_cert_tier: null,
  rate_type: 'percentage',
  rate_value: 150,
  effective_from: '2020-01-01',
  effective_to: null,
}

const READY = {
  state: 'ready' as const,
  blocking_step: null,
  products_total: 1,
  products_covered: 1,
  issues: [] as { code: string; label: string; count: number }[],
  can_fix: true,
}

async function mountView(opts: { products?: unknown[] } = {}) {
  const { products = [ownedProduct()] } = opts

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) return { data: { commission_basis: 'price' } }
    if (path.startsWith('/companies')) return { data: [AIA] }
    // startsWith, not equality: since 2026-09-12 this screen fetches
    // `/products?company_id=…` for a Super Admin, because `is_sellable_here`
    // has no answer without a company to resolve it against.
    if (path.startsWith('/products')) return { data: products }
    if (path.startsWith('/commission-rules')) return { data: [COMPANY_RULE] }
    if (path.startsWith('/commission-override-rules')) return { data: [LEADER_RULE] }

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
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

async function goToStep(wrapper: Wrapper, step: 1 | 2 | 3 | 4) {
  await wrapper.get(`[data-test="step-tab-${step}"]`).trigger('click')
  await flushPromises()
}

/** The PUT calls this screen made, as [path, body] pairs. */
const puts = () => put.mock.calls.map((c) => [c[0], c[1]] as [string, unknown])

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('step 3 — which products this company actually sells', () => {
  it('marks a closed row closed and an open row open', async () => {
    const wrapper = await mountView({
      products: [ownedProduct({ is_sellable_here: true }), ownedProduct({ id: 3, name: 'ปิดอยู่', is_sellable_here: false })],
    })
    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="product-row-1"]').attributes('data-selling')).toBe('open')
    expect(wrapper.get('[data-test="product-row-3"]').attributes('data-selling')).toBe('closed')
    // …and it is a COLOUR, not a status word somebody has to read: the closed
    // row carries the muted background the catalogue screen uses.
    expect(wrapper.get('[data-test="product-row-3"]').classes().join(' ')).toContain('bg-slate-50/60')
    expect(wrapper.get('[data-test="selling-label-3"]').text()).toBe('ปิดขาย')
  })

  it('puts the products on sale first, then orders each half by Thai name', async () => {
    /*
     * Sorted, never filtered. What is on sale is the day-to-day work; what is
     * closed is reference — and reference that has to stay findable, because
     * it is the row somebody is about to switch back on.
     */
    const wrapper = await mountView({
      products: [
        ownedProduct({ id: 1, name: 'ฟ', is_sellable_here: false }),
        ownedProduct({ id: 2, name: 'ฮ', is_sellable_here: true }),
        ownedProduct({ id: 3, name: 'ก', is_sellable_here: false }),
        ownedProduct({ id: 4, name: 'ข', is_sellable_here: true }),
      ],
    })
    await goToStep(wrapper, 3)

    const order = wrapper.findAll('[data-test^="product-row-"]').map((r) => r.attributes('data-test'))
    // ข before ฮ, then ก before ฟ — Thai alphabetical order inside each half,
    // which is the whole reason the comparator passes 'th' rather than
    // sorting by code point.
    expect(order).toEqual(['product-row-4', 'product-row-2', 'product-row-3', 'product-row-1'])
  })

  it('leaves the source list alone — the sort is on a copy', async () => {
    /*
     * A computed that sorts the ref it derives from makes every render depend
     * on the last one. The proof available from outside is that the order is
     * STABLE across a re-render that did not change the data.
     */
    const wrapper = await mountView({
      products: [ownedProduct({ id: 1, name: 'ฮ', is_sellable_here: true }), ownedProduct({ id: 2, name: 'ก', is_sellable_here: true })],
    })
    await goToStep(wrapper, 3)

    const first = wrapper.findAll('[data-test^="product-row-"]').map((r) => r.attributes('data-test'))
    await goToStep(wrapper, 1)
    await goToStep(wrapper, 3)
    expect(wrapper.findAll('[data-test^="product-row-"]').map((r) => r.attributes('data-test'))).toEqual(first)
  })
})

describe('step 3 — the switch writes to the endpoint that owns the state', () => {
  it('sends a SHARED product to the per-company settings route', async () => {
    const wrapper = await mountView({ products: [sharedProduct({ is_sellable_here: false })] })
    await goToStep(wrapper, 3)

    await wrapper.get('[data-test="selling-switch-2"]').trigger('click')
    await flushPromises()

    /*
     * company_id is REQUIRED and cannot be inferred server-side — the actor is
     * a Super Admin with no company of their own — and `price_satang` is
     * deliberately ABSENT: omitting it means "leave the price alone", and
     * opening a product for sale must not also decide what it costs.
     */
    expect(puts()).toContainEqual(['/products/2/company-settings', { company_id: AIA.id, is_active: true }])
    expect(puts().some(([path]) => path === '/products/2')).toBe(false)
  })

  it('sends a COMPANY-OWNED product to the product route, never the 422 one', async () => {
    /*
     * ProductController::updateCompanySetting() aborts 422 for a product that
     * is not shared: it already has exactly one company, and its price and
     * on/off switch live on the product itself. Sending it there would be a
     * switch that only ever throws.
     */
    const wrapper = await mountView({ products: [ownedProduct({ is_sellable_here: true })] })
    await goToStep(wrapper, 3)

    await wrapper.get('[data-test="selling-switch-1"]').trigger('click')
    await flushPromises()

    expect(puts()).toContainEqual(['/products/1', { is_active: false }])
    expect(puts().some(([path]) => path.includes('company-settings'))).toBe(false)
  })

  it('repaints the row from the patch, without refetching the catalogue', async () => {
    /*
     * A refetch here would blank every PV draft the admin has in progress one
     * step over (they are held per product id while they type). So the row is
     * patched in place — and the switch has to actually move, or the admin
     * clicks it twice.
     */
    const wrapper = await mountView({ products: [ownedProduct({ is_sellable_here: true })] })
    await goToStep(wrapper, 3)
    const getsBefore = get.mock.calls.filter((c) => String(c[0]).startsWith('/products')).length

    await wrapper.get('[data-test="selling-switch-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="selling-label-1"]').text()).toBe('ปิดขาย')
    expect(wrapper.get('[data-test="product-row-1"]').attributes('data-selling')).toBe('closed')
    expect(get.mock.calls.filter((c) => String(c[0]).startsWith('/products')).length).toBe(getsBefore)
  })

  it('says so in a visible line when the write fails, and does not lie about the state', async () => {
    const wrapper = await mountView({ products: [ownedProduct({ is_sellable_here: true })] })
    await goToStep(wrapper, 3)
    put.mockRejectedValueOnce(new Error('boom'))

    await wrapper.get('[data-test="selling-switch-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="selling-error"]').text()).toContain('เปิด/ปิดขายสินค้าไม่สำเร็จ')
    // The row never moved, because the server never agreed that it did.
    expect(wrapper.get('[data-test="product-row-1"]').attributes('data-selling')).toBe('open')
  })
})

describe('step 3 — a closed product is still fully editable', () => {
  it('keeps the rate, the layer badge and the rate button on a closed row', async () => {
    /*
     * The owner's clause, defended: "ที่ปิดไว้ก็แก้ไขได้เหมือนเดิม". Greying is
     * a colour and nothing else — a `disabled` creeping onto this button would
     * look tidy and remove the only reason a closed row is still in the list.
     */
    const wrapper = await mountView({ products: [ownedProduct({ is_sellable_here: false })] })
    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="product-rate-1"]').text()).toContain('3')
    expect(wrapper.find('[data-test="product-layer-1"]').exists()).toBe(true)

    const rateButton = wrapper.get('[data-test="product-row-1"] button.btn-primary')
    expect(rateButton.attributes('disabled')).toBeUndefined()

    await rateButton.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('อัตราค่าคอมตัวแทนผู้ขาย')
  })
})

describe('step 3 — a Company Admin sees the state, and only works the switch they own', () => {
  beforeEach(() => {
    useAuthStore().user = { id: 2, name: 'ผู้ดูแลบริษัท', role: 'company_admin', company: AIA } as never
  })

  it('shows a shared product as a read-only pill, never a switch that cannot move', async () => {
    /*
     * PUT /products/{id}/company-settings authorizes on isSuperAdmin() alone
     * (UpdateCompanyProductSettingRequest), so this switch would 403 for them
     * — note the fixture still says `permissions.update: true`, which is the
     * product's own edit right and NOT the question on this branch.
     */
    const wrapper = await mountView({ products: [sharedProduct({ is_sellable_here: false })] })
    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="selling-switch-2"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="selling-state-2"]').text()).toBe('ปิดขาย')
    expect(wrapper.get('[data-test="product-row-2"]').attributes('data-selling')).toBe('closed')
  })

  it('still works the switch on a product their own company owns', async () => {
    /*
     * The other half, and the reason this gate is not simply
     * `canEditCommissionConfig`: opening a product for sale is a catalogue
     * decision, not a commission number, and ProductPolicy::update says this
     * row is theirs.
     */
    const wrapper = await mountView({ products: [ownedProduct({ is_sellable_here: false })] })
    await goToStep(wrapper, 3)

    await wrapper.get('[data-test="selling-switch-1"]').trigger('click')
    await flushPromises()

    expect(puts()).toContainEqual(['/products/1', { is_active: true }])
  })

  it('shows the state only, for a product the server says they may not edit', async () => {
    const wrapper = await mountView({
      products: [ownedProduct({ permissions: { update: false, delete: false, set_commission_rule: true } })],
    })
    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="selling-switch-1"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="selling-state-1"]').text()).toBe('เปิดขาย')
  })
})
