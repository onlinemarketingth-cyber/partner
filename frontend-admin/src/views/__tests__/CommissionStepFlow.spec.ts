/**
 * The 4-step commission flow — "ผู้ใช้ไม่รู้ว่าต้องกรอกอะไรหลัง".
 *
 * That was the owner's whole complaint about the screen this replaced, and it
 * is worth being precise about what it meant, because the old screen was not
 * broken in any way a functional test could see. It had six tabs — กฎคอมมิชชั่น,
 * Binary, Matrix, อันดับ, Generation, Affiliate — plus a
 * ภาพรวมสินค้า/การตั้งค่าทั้งหมด toggle on top. Every tab worked. Every tab had
 * passed UAT. And every tab looked exactly as urgent, and exactly as optional,
 * as the other five: an admin who finished one was told nothing about what came
 * next, or whether anything came next at all.
 *
 * That silence is expensive here in a way it would not be on most screens.
 * Thirteen code paths in the commission services let a closed deal pay NOBODY,
 * every one of them silently and deliberately (the sale must never be blocked
 * by a config gap, TASK-213 Phase 1). So an admin who stops early does not see
 * an error — they see a working system, until an agent asks where their money
 * went. "I did not know there was more to fill in" and "nobody got paid" are
 * the same sentence on this screen.
 *
 * The redesign answers it in three places, and this file exists to keep all
 * three answers present, because each one is a single line of markup that a
 * future refactor could quietly drop without failing anything else:
 *
 *   1. THE STEPS ARE NUMBERED AND NAMED. Four of them, in the order the work
 *      actually has to happen.
 *   2. EVERY STEP CARRIES A STATUS PILL — เสร็จแล้ว / ยังไม่ครบ / ข้ามได้ — so
 *      "am I done" is answerable without opening anything.
 *   3. THE NEXT BUTTON SAYS THE NEXT STEP'S NAME. Not "ถัดไป", not an arrow:
 *      "ขั้นที่ 3 ตั้งอัตราตัวแทนผู้ขาย →". This is the literal answer to the
 *      literal question that was asked.
 *
 * Plus the banner above all of it, which names the step that is blocking in
 * money terms — and the 2026-09-11 rule that a Company Admin, who may now read
 * every one of these numbers and write none of them, is shown no control that
 * would 403.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
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

function product(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    company_id: AIA.id,
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

/** A live company-wide agent rate — the "ค่าเริ่มต้นทั้งบริษัท" of step 3.1. */
function companyDefaultRule(over: Record<string, unknown> = {}) {
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
    ...over,
  }
}

/** A live company-wide LEADER rate — step 4's card. */
function leaderRule(over: Record<string, unknown> = {}) {
  return {
    id: 20,
    company_id: AIA.id,
    product: null,
    product_category: null,
    manager_cert_tier: null,
    rate_type: 'percentage',
    rate_value: 150,
    effective_from: '2020-01-01',
    effective_to: null,
    ...over,
  }
}

/**
 * The server's readiness verdict, as GET /commission-readiness returns it.
 *
 * 2026-09-11 — the banner at the top of this screen no longer derives its own
 * answer from the rows below it; it reads the same `commissionReadiness` store
 * the app-shell banner reads, so that "is anybody being paid" has ONE answer
 * on the screen where it gets fixed. These fixtures are therefore the SERVER's
 * answer, spelled out per test, rather than something this file recomputes —
 * recomputing it here would be a third implementation of the rule, and the
 * point of the change was to stop having a second.
 */
interface Readiness {
  state: 'missing' | 'incomplete' | 'ready'
  blocking_step: 1 | 2 | 3 | 4 | null
  products_total: number
  products_covered: number
  issues: { code: string; label: string; count: number }[]
  can_fix: boolean
}

/** Everything is configured — the default, so tests about the STEPS say nothing about the banner. */
const READY: Readiness = {
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
  overrides?: unknown[]
  readiness?: Readiness
  /**
   * Paths whose response is the empty string. 204/'' is how every structural
   * settings singleton says "never configured" (see
   * CommissionBinarySettingController's own note on why it is not a null
   * resource), and it is what makes step 2 read ยังไม่ครบ.
   */
  unconfigured?: string[]
}

async function mountView(fixture: Fixture = {}) {
  const { products = [product()], rules = [], overrides = [], unconfigured = [], readiness = READY } = fixture

  get.mockImplementation(async (path: string) => {
    // Before the `unconfigured` check: a readiness verdict is never '' — the
    // endpoint always answers, even for a company that has configured nothing.
    if (path.startsWith('/commission-readiness')) return readiness
    if (unconfigured.some((u) => path.startsWith(u))) return ''
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: products }
    if (path.startsWith('/commission-rules')) return { data: rules }
    if (path.startsWith('/commission-override-rules')) return { data: overrides }

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

const pill = (w: Wrapper, step: number) => w.get(`[data-test="step-pill-${step}"]`).text()

beforeEach(() => {
  get.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('CommissionPlansView — the four steps are named and in order', () => {
  it('renders exactly four step tabs, numbered 1 to 4', async () => {
    const wrapper = await mountView()

    const tabs = wrapper.findAll('[data-test^="step-tab-"]')
    expect(tabs).toHaveLength(4)
    expect(tabs.map((t) => t.text())).toEqual([
      expect.stringContaining('เลือกบริษัท'),
      expect.stringContaining('เลือกแผนคอมมิชชั่น'),
      expect.stringContaining('ตั้งอัตราตัวแทนผู้ขาย'),
      expect.stringContaining('ส่วนเพิ่มเติม'),
    ])
  })

  it('opens on step 1, so the flow starts where it starts', async () => {
    /*
     * Deliberately NOT the blocking step. Landing somewhere different on every
     * visit is the same disorientation the redesign removes; the banner's jump
     * button serves the admin who only came to fix one thing.
     */
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="step-panel-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="step-panel-3"]').exists()).toBe(false)
  })

  it('swaps the panel when a step is clicked', async () => {
    const wrapper = await mountView()

    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="step-panel-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="step-panel-3"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('ตัวแทนผู้ขายได้กี่เปอร์เซ็นต์')
  })

  it('never disables a step tab — the order is advice, not a gate', async () => {
    /*
     * An admin who came back only to add a leader rate must not be made to
     * re-walk steps 1–3, and a click that silently does nothing cannot explain
     * itself. The pills carry the "you are not finished" message instead.
     */
    const wrapper = await mountView()

    for (const step of [1, 2, 3, 4]) {
      expect(wrapper.get(`[data-test="step-tab-${step}"]`).attributes('disabled')).toBeUndefined()
    }

    await goToStep(wrapper, 4)
    expect(wrapper.find('[data-test="step-panel-4"]').exists()).toBe(true)
  })
})

describe('CommissionPlansView — the status pills answer "am I done"', () => {
  it('marks a picked company done and an unrated catalogue not done', async () => {
    const wrapper = await mountView({ products: [product()], rules: [] })

    expect(pill(wrapper, 1)).toBe('เสร็จแล้ว')
    expect(pill(wrapper, 3)).toBe('ยังไม่ครบ')
  })

  it('marks step 3 done once every product resolves to a rate', async () => {
    const wrapper = await mountView({ products: [product()], rules: [companyDefaultRule()] })

    expect(pill(wrapper, 3)).toBe('เสร็จแล้ว')
  })

  it('marks step 2 not done when a plan in use has no structure configured', async () => {
    const wrapper = await mountView({
      products: [product({ effective_plan_type: 'binary' })],
      rules: [companyDefaultRule()],
      unconfigured: ['/commission-binary-settings'],
    })

    expect(pill(wrapper, 2)).toBe('ยังไม่ครบ')
  })

  it('always calls step 4 ข้ามได้, because it genuinely is', async () => {
    /*
     * Not a softer "ยังไม่ครบ". The three settings on step 4 are optional by
     * design — the system pays commission correctly without any of them — and
     * telling an admin they are incomplete for skipping something skippable is
     * how a warning stops being read at all.
     */
    const wrapper = await mountView({ products: [product()], rules: [] })

    expect(pill(wrapper, 4)).toBe('ข้ามได้')
  })
})

describe('CommissionPlansView — the next button names the next step', () => {
  it('says "ขั้นที่ 2 เลือกแผนคอมมิชชั่น" while on step 1', async () => {
    // The literal answer to "ผู้ใช้ไม่รู้ว่าต้องกรอกอะไรหลัง". A bare "ถัดไป"
    // here would pass any test about navigation working and still leave the
    // original complaint entirely unaddressed.
    const wrapper = await mountView()

    expect(wrapper.get('[data-test="step-next"]').text()).toContain('ขั้นที่ 2 เลือกแผนคอมมิชชั่น')
  })

  it('says "ขั้นที่ 3 ตั้งอัตราตัวแทนผู้ขาย" once you are on step 2', async () => {
    const wrapper = await mountView()

    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="step-next"]').text()).toContain('ขั้นที่ 3 ตั้งอัตราตัวแทนผู้ขาย')
  })

  it('names the step behind you too, so going back is not a guess', async () => {
    const wrapper = await mountView()

    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="step-back"]').text()).toContain('ขั้นที่ 1 เลือกบริษัท')
  })

  it('offers no next button on the last step', async () => {
    const wrapper = await mountView()

    await goToStep(wrapper, 4)

    expect(wrapper.find('[data-test="step-next"]').exists()).toBe(false)
  })

  it('moves to the named step when pressed', async () => {
    const wrapper = await mountView()

    await wrapper.get('[data-test="step-next"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="step-panel-2"]').exists()).toBe(true)
  })
})

/*
 * ── 2026-09-11: THE BANNER IS NOW THE SERVER'S SENTENCE, NOT THIS SCREEN'S ──
 *
 * Every test below used to build a fixture of PRODUCTS AND RULES and let the
 * screen work out the verdict. It now passes the verdict itself, because that
 * is what changed: the owner asked for the same warning on every page
 * ("ให้แจ้งเตือนในทุกหน้า"), the app shell fetches GET /commission-readiness to
 * show it, and a screen that computed a second answer from the rows it happened
 * to have loaded would sooner or later contradict the shell — over a rate that
 * expired at midnight, or a product added in another tab — in front of the one
 * person who opened this screen to fix it.
 *
 * So these fixtures ARE the contract with the server, written out. The rules
 * and products stay in each fixture where the rest of the screen needs them
 * (the pills and the product cards are still local, and deliberately so — they
 * answer "what is wrong with THIS row", which the endpoint does not carry).
 */
describe('CommissionPlansView — the readiness banner names the blocking step', () => {
  it('says the money consequence, not just "incomplete"', async () => {
    const wrapper = await mountView({
      products: [product()],
      rules: [],
      readiness: {
        state: 'missing',
        blocking_step: 3,
        products_total: 1,
        products_covered: 0,
        issues: [{ code: 'products_without_rate', label: 'สินค้า 1 จาก 1 รายการยังไม่มีอัตราค่าคอมที่ใช้ได้', count: 1 }],
        can_fix: true,
      },
    })

    expect(wrapper.get('[data-test="readiness-headline"]').text())
      .toBe('ยังไม่พร้อมจ่ายค่าคอม — ดีลที่ปิดได้จะไม่มีใครได้เงิน')
  })

  it('names step 3 and counts the products with no usable rate', async () => {
    const wrapper = await mountView({
      products: [product(), product({ id: 2, name: 'AIA Life' })],
      rules: [],
      readiness: {
        state: 'missing',
        blocking_step: 3,
        products_total: 2,
        products_covered: 0,
        issues: [{ code: 'products_without_rate', label: 'สินค้า 2 จาก 2 รายการยังไม่มีอัตราค่าคอมที่ใช้ได้', count: 2 }],
        can_fix: true,
      },
    })

    const detail = wrapper.get('[data-test="readiness-detail"]').text()
    expect(detail).toContain('ติดอยู่ที่ขั้นที่ 3')
    // "อัตราค่าคอม", not "อัตรา": the count now arrives already worded by the
    // server, so the banner renders the sentence money's own service wrote
    // rather than a paraphrase of it composed here.
    expect(detail).toContain('สินค้า 2 จาก 2 รายการยังไม่มีอัตราค่าคอมที่ใช้ได้')
  })

  it('names step 2 when the rates are fine but the plan structure is missing', async () => {
    /*
     * The ordering matters and is the server's: "no rate at all" outranks "no
     * structure", because a product with no rate pays nobody and a product
     * with no structure still pays the agent who closed the deal. A banner
     * that pointed at step 2 first would send an admin to fix the cheaper
     * problem. That ordering now lives in CommissionReadinessService's
     * resolveBlockingStep() — this test pins that the screen RENDERS whatever
     * step the server named, which is the half it is still responsible for.
     */
    const wrapper = await mountView({
      products: [product({ effective_plan_type: 'binary' })],
      rules: [companyDefaultRule()],
      unconfigured: ['/commission-binary-settings'],
      readiness: {
        state: 'incomplete',
        blocking_step: 2,
        products_total: 1,
        products_covered: 1,
        issues: [{ code: 'plan_structure_missing', label: 'ยังไม่ได้ตั้งค่าโครงสร้าง Binary — ตัวแทนผู้ขายได้ แต่ชั้นบนจะไม่ได้อะไร', count: 1 }],
        can_fix: true,
      },
    })

    expect(wrapper.get('[data-test="readiness-detail"]').text()).toContain('ติดอยู่ที่ขั้นที่ 2')
  })

  it('names step 4, in amber, when only the leader is unpaid', async () => {
    // Amber and not red on purpose: the agent who closed the deal IS paid.
    // Painting that the same colour as "nobody is paid" is how a red banner
    // becomes wallpaper.
    const wrapper = await mountView({
      products: [product()],
      rules: [companyDefaultRule()],
      overrides: [],
      readiness: {
        state: 'incomplete',
        blocking_step: 4,
        products_total: 1,
        products_covered: 1,
        issues: [{ code: 'leader_rate_missing', label: 'สินค้า 1 รายการยังไม่มีอัตราหัวหน้าทีม — หัวหน้าจะไม่ได้ส่วนแบ่งจากดีลนั้น', count: 1 }],
        can_fix: true,
      },
    })

    expect(wrapper.get('[data-test="readiness-detail"]').text()).toContain('ติดอยู่ที่ขั้นที่ 4')
    expect(wrapper.get('[data-test="readiness-banner"]').classes()).toContain('bg-amber-50')
  })

  it('turns green and names no step once everything resolves', async () => {
    const wrapper = await mountView({
      products: [product()],
      rules: [companyDefaultRule()],
      overrides: [leaderRule()],
    })

    expect(wrapper.get('[data-test="readiness-headline"]').text()).toBe('พร้อมจ่ายค่าคอมแล้ว — ทุกสินค้ามีอัตราที่ใช้ได้')
    expect(wrapper.get('[data-test="readiness-banner"]').classes()).toContain('bg-emerald-50')
    expect(wrapper.find('[data-test="readiness-jump"]').exists()).toBe(false)
  })

  it('jumps to the blocking step when pressed', async () => {
    const wrapper = await mountView({
      products: [product()],
      rules: [],
      readiness: {
        state: 'missing',
        blocking_step: 3,
        products_total: 1,
        products_covered: 0,
        issues: [{ code: 'products_without_rate', label: 'สินค้า 1 จาก 1 รายการยังไม่มีอัตราค่าคอมที่ใช้ได้', count: 1 }],
        can_fix: true,
      },
    })

    expect(wrapper.get('[data-test="readiness-jump"]').text()).toContain('ไปที่ขั้นที่ 3')
    await wrapper.get('[data-test="readiness-jump"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="step-panel-3"]').exists()).toBe(true)
  })
})

describe('CommissionPlansView — step 2 shows the plan without switching to it', () => {
  it('marks the plan the company is actually on', async () => {
    const wrapper = await mountView({ products: [product({ effective_plan_type: 'matrix' })] })

    await goToStep(wrapper, 2)

    expect(wrapper.get('[data-test="plan-chip-matrix"]').classes()).toContain('bg-brand-600')
    // The other five stay dimmed and dashed — "กดดูรายละเอียดได้ แต่ยังไม่มีผล".
    expect(wrapper.get('[data-test="plan-chip-binary"]').classes()).toContain('border-dashed')
  })

  it('reveals a dimmed plan\'s structural form on click, without claiming it is in use', async () => {
    const wrapper = await mountView({ products: [product()] })

    await goToStep(wrapper, 2)
    await wrapper.get('[data-test="plan-chip-binary"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="plan-structure-binary"]').exists()).toBe(true)
    // Still not the company's plan: the chip did not fill in.
    expect(wrapper.get('[data-test="plan-chip-binary"]').classes()).not.toContain('bg-brand-600')
  })

  it('says the switch only affects future sales', async () => {
    const wrapper = await mountView()

    await goToStep(wrapper, 2)

    expect(wrapper.text()).toContain('การสลับแผนมีผลกับการขายครั้งถัดไปเท่านั้น')
  })
})

describe('CommissionPlansView — step 3 says which layer each rate came from', () => {
  it('labels a product falling through to the company default', async () => {
    /*
     * Without this badge, "3.00%" on a product row is indistinguishable from a
     * rate somebody chose for that product — and deleting the company default
     * then changes it silently.
     */
    const wrapper = await mountView({ products: [product()], rules: [companyDefaultRule()] })

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="product-rate-1"]').text()).toBe('3.00%')
    expect(wrapper.get('[data-test="product-layer-1"]').text()).toBe('ใช้ค่าเริ่มต้นบริษัท')
  })

  it('labels a product with its own rate', async () => {
    const wrapper = await mountView({
      products: [product()],
      rules: [companyDefaultRule(), companyDefaultRule({ id: 11, product: { id: 1, name: 'AIA Health Plus' }, rate_value: 500 })],
    })

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="product-rate-1"]').text()).toBe('5.00%')
    expect(wrapper.get('[data-test="product-layer-1"]').text()).toBe('ตั้งเฉพาะสินค้านี้')
  })

  it('distinguishes an EXPIRED rate from a rate that was never set', async () => {
    /*
     * The cruellest version of "no rate": the admin set one, saw it work, and
     * it stopped on a date nobody was reminded of. "ยังไม่มีอัตรา" alone sends
     * them to create a duplicate instead of to the row that needs one field
     * changed.
     */
    const wrapper = await mountView({
      products: [product()],
      rules: [companyDefaultRule({ product: { id: 1, name: 'AIA Health Plus' }, effective_to: '2020-06-30' })],
    })

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="product-expired-1"]').text()).toContain('อัตราหมดอายุ')
  })

  it('draws the resolution ladder inline instead of interrupting with it', async () => {
    /*
     * This used to be a modal that auto-opened on every entry to the rules tab
     * and had to be dismissed (with a persisted "ไม่ต้องแสดงอีก" checkbox). It
     * is drawn now, permanently, above the rows it explains — and the modal is
     * only opened on request.
     */
    const wrapper = await mountView()

    await goToStep(wrapper, 3)

    const ladder = wrapper.get('[data-test="resolution-ladder"]').text()
    expect(ladder).toContain('1 · อัตราของสินค้านั้น')
    expect(ladder).toContain('2 · อัตราของหมวดหมู่')
    expect(ladder).toContain('3 · ค่าเริ่มต้นทั้งบริษัท')
  })

  it('does not pop the resolution-order modal unasked', async () => {
    const wrapper = await mountView()

    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="close-resolution-order"]').exists()).toBe(false)

    await wrapper.get('[data-test="open-resolution-order"]').trigger('click')
    expect(wrapper.find('[data-test="close-resolution-order"]').exists()).toBe(true)
  })
})

describe('CommissionPlansView — step 4 is honest about leaving the page', () => {
  it('links the two settings that live on other routes and says so', async () => {
    const wrapper = await mountView({ products: [product()], rules: [companyDefaultRule()] })

    await goToStep(wrapper, 4)

    expect(wrapper.get('[data-test="link-split-settings"]').text()).toContain('ตั้งค่าที่หน้าจออื่น')
    expect(wrapper.get('[data-test="link-withdrawal-settings"]').text()).toContain('ตั้งค่าที่หน้าจออื่น')
  })

  it('keeps the leader rate itself editable in place', async () => {
    const wrapper = await mountView({ products: [product()], rules: [companyDefaultRule()], overrides: [leaderRule()] })

    await goToStep(wrapper, 4)

    expect(wrapper.find('[data-test="leader-rule-20"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="add-leader-rate"]').exists()).toBe(true)
  })
})

describe('CommissionPlansView — a Company Admin sees every step and no write control', () => {
  /*
   * 2026-09-11, owner: "อันไหนสิทธิ์ company admin ทำไม่ได้ต้องซ่อน ไม่ใช่ให้
   * error 403". Commission writes are Super Admin's alone now, and the reads
   * were deliberately left open — so the test has to check both halves, on
   * every step. A screen that hid the values along with the buttons would pass
   * a "no write controls" assertion and still be wrong.
   */
  const WRITE_CONTROLS = [
    'step1-company-select',
    'change-plan-link',
    'save-binary',
    'add-level-rate',
    'add-company-default',
    'add-product-rate',
    'add-category-rate',
    'open-wizard',
    'add-leader-rate',
  ]

  async function mountAsCompanyAdmin() {
    useAuthStore().user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never

    return mountView({
      products: [product({ effective_plan_type: 'binary' })],
      rules: [companyDefaultRule()],
      overrides: [leaderRule()],
    })
  }

  it('offers no write control on any of the four steps', async () => {
    const wrapper = await mountAsCompanyAdmin()

    for (const step of [1, 2, 3, 4] as const) {
      await goToStep(wrapper, step)
      // Step 2's forms only render for the chip in view; binary is this
      // company's plan, so its save button is the one that would be there.
      for (const control of WRITE_CONTROLS) {
        expect(wrapper.find(`[data-test="${control}"]`).exists()).toBe(false)
      }
    }
  })

  it('still shows the rate, the layer it comes from and the leader rate', async () => {
    const wrapper = await mountAsCompanyAdmin()

    await goToStep(wrapper, 3)
    expect(wrapper.get('[data-test="product-rate-1"]').text()).toBe('3.00%')
    expect(wrapper.get('[data-test="product-layer-1"]').text()).toBe('ใช้ค่าเริ่มต้นบริษัท')

    await goToStep(wrapper, 4)
    expect(wrapper.get('[data-test="leader-rule-20"]').text()).toContain('1.50%')
  })

  it('shows the structural settings read-only rather than hiding them', async () => {
    const wrapper = await mountAsCompanyAdmin()

    await goToStep(wrapper, 2)

    // The section is there (a Company Admin has to be able to see the config
    // their agents are paid under) — `get` throws if it is not...
    const form = wrapper.get('[data-test="plan-structure-binary"]')
    // ...and every input in it is inert.
    expect(form.get('fieldset').attributes('disabled')).toBeDefined()
  })

  it('still gets the readiness banner, because reading it is not a write', async () => {
    const wrapper = await mountAsCompanyAdmin()

    expect(wrapper.find('[data-test="readiness-banner"]').exists()).toBe(true)
  })
})
