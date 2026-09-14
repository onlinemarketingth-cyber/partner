/**
 * Step 3's own order — "ตอนนี้แดงไปหมด หาไม่เจอต้องทำอะไรก่อนหลัง"
 * (owner, 2026-09-13, looking at a company with nothing configured).
 *
 * ── WHAT HE WAS LOOKING AT ──
 *
 * Six red things at once: the readiness banner, the 3.1 "ยังไม่มีค่าเริ่มต้น
 * ทั้งบริษัท" panel, and one red warning on each of four product rows in 3.2.
 * Nothing was broken in a way any test could see — every one of those six
 * sentences was TRUE. That is exactly why it failed: red was answering two
 * questions at once ("this is broken" and "you are here"), and the four row
 * warnings were not four problems but ONE problem quoted four times. With no
 * company default nothing resolves for anything, so every row said what the
 * panel above it had already said.
 *
 * ── THE RULE THESE TESTS KEEP ──
 *
 * At most ONE red thing on screen at a time, and it belongs to the thing you
 * must do NOW. Concretely, and each of these is a line of markup a tidy-up
 * could drop without failing anything else:
 *
 *   1. 3.1 IS FRAMED WHILE IT IS THE WORK — in the brand colour, not red, so
 *      "you are here" and "this is broken" stop sharing a colour.
 *   2. 3.2 IS LOCKED AND QUIET UNTIL 3.1 EXISTS — no per-row rate warnings, no
 *      rate controls, one line naming the cure.
 *   3. THE SELLING SWITCH IS EXEMPT. Opening a product for sale is a CATALOGUE
 *      action; locking it for a commission reason would invent a new dead end,
 *      which is the owner's explicit decision and the most likely thing for a
 *      future "consistency" pass to undo.
 *   4. STEP 3 IS NOT DONE WITHOUT A COMPANY DEFAULT, even when every product
 *      carries its own rate ("แดงตลอด ผมยังอยากให้ตั้งค่าบริษัทอยู่ดี") — the
 *      default is the safety net for the NEXT product added, and the server
 *      says the same thing (`company_default_missing`, blocking_step 3).
 *   5. THE START-HERE MODAL IS NARROW. A modal that appears every visit gets
 *      dismissed unread — this file's own subject screen deleted one on
 *      2026-09-11 for exactly that — so: only 'missing', only somebody who can
 *      fix it, once per day per company.
 *
 * The harness is CommissionStepFlow.spec.ts's, unchanged in shape: the same
 * get/put mocks and the same `rules` fixture, because the state under test is
 * entirely a question of which commission_rules rows exist.
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

/** A live company-wide agent rate — the thing 3.1 exists to produce. */
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
  }
}

/** The same row scoped to ONE product — a rate that is NOT a safety net. */
function productRule(productId: number, name: string, over: Record<string, unknown> = {}) {
  return { ...companyDefaultRule(), id: 100 + productId, product: { id: productId, name }, ...over }
}

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

interface Readiness {
  state: 'missing' | 'incomplete' | 'ready'
  blocking_step: 1 | 2 | 3 | 4 | null
  products_total: number
  products_covered: number
  issues: { code: string; label: string; count: number }[]
  can_fix: boolean
}

/**
 * The server's verdict, which this screen reads rather than recomputes.
 *
 * 'missing' carries `company_default_missing` because that is what
 * CommissionReadinessService reports as of 2026-09-13 — the banner, the step
 * pill and the modal all now say the same thing about the same row.
 */
const MISSING: Readiness = {
  state: 'missing',
  blocking_step: 3,
  products_total: 1,
  products_covered: 0,
  issues: [{ code: 'company_default_missing', label: 'ยังไม่ได้ตั้งค่าเริ่มต้นทั้งบริษัท', count: 1 }],
  can_fix: true,
}

/** Somebody IS paid — the state this modal deliberately does not interrupt for. */
const INCOMPLETE: Readiness = {
  state: 'incomplete',
  blocking_step: 4,
  products_total: 1,
  products_covered: 1,
  issues: [{ code: 'leader_rate_missing', label: 'สินค้า 1 รายการยังไม่มีอัตราหัวหน้าทีม', count: 1 }],
  can_fix: true,
}

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
}

async function mountView(fixture: Fixture = {}) {
  const { products = [product()], rules = [], overrides = [], readiness = READY } = fixture

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return readiness
    if (path.startsWith('/commission-settings')) return { data: { commission_basis: 'price' } }
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
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  // The modal's "once per day" flag is persisted, and a flag left over from
  // the previous test is an order-dependent pass.
  window.localStorage.clear()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('CommissionPlansView — while 3.1 is missing, 3.1 is the only thing shouting', () => {
  const noDefault = { products: [product()], rules: [] }

  it('frames 3.1 as the work to do now, and says so in words as well as colour', async () => {
    /*
     * A ring on its own is a colour, and a colour is exactly what stopped
     * working here. The badge is the half that survives being read by somebody
     * who does not separate two blues, or who is looking at the page in a
     * screenshot on a phone.
     */
    const wrapper = await mountView(noDefault)

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="substep-focus-3-1"]').text()).toBe('ทำตรงนี้ก่อน')
    // The frame is NOT red: red still means "this is broken" (the panel inside
    // it), and the whole complaint was those two jobs sharing one colour.
    const frame = wrapper.get('[data-test="step3-company-default"]').classes().join(' ')
    expect(frame).toContain('border-brand-500')
    expect(frame).not.toContain('border-rose')
    // And the panel it frames is untouched — both ways out of 3.1 still there.
    expect(wrapper.find('[data-test="add-company-default"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="copy-rates-open"]').exists()).toBe(true)
  })

  it('locks the product list with one line that names the cure rather than the refusal', async () => {
    // A lock with no way out is the dead end the 4-step redesign exists to
    // remove — the same rule stepLockHint() applies one level up.
    const wrapper = await mountView(noDefault)

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="substep-3-3-lock"]').text())
      .toContain('ตั้งค่าเริ่มต้นทั้งบริษัทที่ 3.1 ก่อน แล้วส่วนนี้จะเปิด')
    expect(wrapper.get('[data-test="step3-products"]').classes().join(' ')).toContain('opacity-60')
    // The product list is not where you are, so it carries no focus badge.
    // (It was 3.2 until 2026-09-14, when the category scope took that number
    // and the products became 3.3 — see the view's own note.)
    expect(wrapper.find('[data-test="substep-focus-3-3"]').exists()).toBe(false)
  })

  it('says nothing on the product rows that 3.1 has not already said', async () => {
    /*
     * THE FOUR RED ROWS, as the owner saw them. Four products, no company
     * default, four identical "ยังไม่มีอัตราค่าคอม" warnings — every one of
     * them downstream of the single missing row above. This is the assertion
     * that a future "but the row should warn too" change has to argue with.
     */
    const wrapper = await mountView({
      products: [
        product({ id: 1, name: 'สินค้า ก' }),
        product({ id: 2, name: 'สินค้า ข' }),
        product({ id: 3, name: 'สินค้า ค' }),
        product({ id: 4, name: 'สินค้า ง' }),
      ],
      rules: [],
    })

    await goToStep(wrapper, 3)

    const section = wrapper.get('[data-test="step3-products"]')
    expect(section.text()).not.toContain('ยังไม่มีอัตรา')
    for (const id of [1, 2, 3, 4]) {
      // The badge whose only possible text here is ยังไม่มีอัตรา is absent...
      expect(wrapper.find(`[data-test="product-layer-${id}"]`).exists()).toBe(false)
      // ...the rate reads as a quiet dash rather than a red sentence...
      expect(wrapper.get(`[data-test="product-rate-${id}"]`).text()).toBe('—')
      // ...and the row itself is not painted red either, which is where four
      // of the six red things actually lived.
      expect(wrapper.get(`[data-test="product-row-${id}"]`).classes().join(' ')).not.toContain('bg-rose-50')
    }
    // The row still says what it IS — name, price, plan — because reading the
    // catalogue is not what is locked.
    expect(wrapper.get('[data-test="product-row-1"]').text()).toContain('สินค้า ก')
    expect(wrapper.get('[data-test="product-row-1"]').text()).toContain('แผน Unilevel')
  })

  it('makes every rate control in 3.2 refuse, visibly, instead of disappearing', async () => {
    /*
     * Disabled rather than hidden, deliberately: the house rule hides what a
     * viewer may NEVER do ("อันไหนสิทธิ์ company admin ทำไม่ได้ต้องซ่อน") and
     * shows what they may do LATER, which is how the step tabs and the footer
     * button already behave. Hiding these would delete the evidence that 3.2
     * is where a per-product rate gets set.
     */
    const wrapper = await mountView(noDefault)

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="product-rate-button-1"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="simulate-1"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="add-product-rate"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="add-category-rate"]').attributes('disabled')).toBeDefined()
  })

  it('leaves the selling switch working, because selling is a catalogue decision', async () => {
    /*
     * THE OWNER'S EXPLICIT EXEMPTION, and the single most likely line in this
     * change to be "tidied" into consistency with its neighbours. Opening or
     * closing a product for sale has nothing to do with commission; refusing
     * it until a commission default exists would answer a catalogue question
     * with a commission answer — a brand new dead end, invented by the change
     * whose whole purpose was to remove one.
     */
    const wrapper = await mountView(noDefault)

    await goToStep(wrapper, 3)

    const toggle = wrapper.get('[data-test="selling-switch-1"]')
    expect(toggle.attributes('disabled')).toBeUndefined()

    await toggle.trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/products/1', { is_active: true })
    expect(wrapper.get('[data-test="selling-label-1"]').text()).toBe('เปิดขาย')
  })

  it('still distinguishes an expired rate, which is the one thing 3.1 cannot say', async () => {
    /*
     * The exception to "no per-row warnings", and it earns its place: an
     * expired rate carries a DATE, and telling the admin "ยังไม่มีอัตรา" for it
     * sends them to create a duplicate instead of to the row that needs one
     * field changed. It gives up RED while locked — red belongs to 3.1 — but
     * not the sentence.
     */
    const wrapper = await mountView({
      products: [product()],
      rules: [productRule(1, 'AIA Health Plus', { effective_to: '2020-06-30' })],
    })

    await goToStep(wrapper, 3)

    const expired = wrapper.get('[data-test="product-expired-1"]')
    expect(expired.text()).toContain('อัตราหมดอายุ')
    expect(expired.classes().join(' ')).not.toContain('text-rose-700')
  })
})

describe('CommissionPlansView — once 3.1 is done the attention moves down the ladder', () => {
  const withDefault = { products: [product()], rules: [companyDefaultRule()] }

  it('collapses 3.1 to one quiet line without hiding the way to change it', async () => {
    /*
     * Both halves matter. The "ตาข่ายกันพลาด" explanation is teaching material
     * for somebody who has not done it yet, and leaving it up afterwards is how
     * a screen ends up shouting all of its instructions at once. But collapsing
     * the EXPLANATION must never collapse the NUMBER: แก้ไข and ลบ stay one
     * click away, or an admin who set the wrong rate has no way back to it.
     */
    const wrapper = await mountView(withDefault)

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="substep-3-1-summary"]').text()).toContain('3.00%')
    expect(wrapper.get('[data-test="company-default-pill"]').text()).toBe('เรียบร้อย')
    expect(wrapper.find('[data-test="substep-focus-3-1"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="step3-company-default"]').classes().join(' ')).not.toContain('border-brand-500')

    const row = wrapper.get('[data-test="company-default-rule-10"]')
    expect(row.text()).toContain('แก้ไข')
    expect(row.text()).toContain('ลบ')
  })

  it('moves the frame to the product list and words its badge as guidance, not as an order', async () => {
    /*
     * 3.2 is an exception list — its own subtitle says ไม่ต้องแยกทุกตัว — so a
     * badge reading ทำตรงนี้ก่อน would be ordering an optional refinement. That
     * is the same lie as marking step 4 ยังไม่ครบ for being skippable, and it is
     * how a badge stops being read at all.
     */
    const wrapper = await mountView(withDefault)

    await goToStep(wrapper, 3)

    const badge = wrapper.get('[data-test="substep-focus-3-3"]').text()
    expect(badge).toBe('ทำต่อได้ตรงนี้')
    expect(badge).not.toContain('ก่อน')
    expect(wrapper.get('[data-test="step3-products"]').classes().join(' ')).toContain('border-brand-500')
    expect(wrapper.find('[data-test="substep-3-3-lock"]').exists()).toBe(false)
  })

  it('gives the rate controls back', async () => {
    const wrapper = await mountView(withDefault)

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="product-rate-button-1"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="simulate-1"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="add-product-rate"]').attributes('disabled')).toBeUndefined()
  })

  it('warns per row again, but only on the rows that genuinely have a problem', async () => {
    /*
     * The other half of the rule, and the reason suppressing the warnings while
     * locked is not the same as deleting them. Here the leader rate covers
     * product 1 and not product 2, so exactly one row warns — which is a
     * warning that means something, unlike four identical ones.
     */
    const wrapper = await mountView({
      products: [product({ id: 1, name: 'สินค้า ก' }), product({ id: 2, name: 'สินค้า ข' })],
      rules: [companyDefaultRule()],
      overrides: [leaderRule({ product: { id: 1, name: 'สินค้า ก' } })],
    })

    await goToStep(wrapper, 3)

    expect(wrapper.get('[data-test="product-row-1"]').text()).toContain('ตั้งค่าครบ พร้อมจ่าย')
    expect(wrapper.get('[data-test="product-row-2"]').text()).toContain('ยังไม่มีอัตราหัวหน้าทีม')
    // The layer badge is back on both rows, because both now resolve to a rate.
    expect(wrapper.get('[data-test="product-layer-2"]').text()).toBe('ใช้ค่าเริ่มต้นบริษัท')
  })
})

describe('CommissionPlansView — step 3 is not finished without a company default', () => {
  it('stays ยังไม่ครบ even when every product carries its own rate', async () => {
    /*
     * Owner, 2026-09-13: "แดงตลอด ผมยังอยากให้ตั้งค่าบริษัทอยู่ดี".
     *
     * Every product here resolves to a rate, so the old rule called step 3
     * finished — one row away from paying nobody. The next product anybody adds
     * (from the catalogue, from an import, from another admin in another tab)
     * arrives with no rate of its own and nothing underneath it to fall through
     * to, and the sale that follows is silent by design.
     */
    const wrapper = await mountView({
      products: [product({ id: 1, name: 'สินค้า ก' }), product({ id: 2, name: 'สินค้า ข' })],
      rules: [productRule(1, 'สินค้า ก'), productRule(2, 'สินค้า ข')],
    })

    await goToStep(wrapper, 3)
    // Both products DO have a usable rate — the premise of the test, not an
    // accident of the fixture.
    expect(wrapper.get('[data-test="product-rate-1"]').text()).toBe('3.00%')
    expect(wrapper.get('[data-test="product-rate-2"]').text()).toBe('3.00%')

    expect(pill(wrapper, 3)).toBe('ยังไม่ครบ')
    // And the gate above agrees: step 4 is still behind step 3.
    expect(wrapper.get('[data-test="step-tab-4"]').attributes('aria-disabled')).toBe('true')
  })

  it('finishes as soon as the default exists', async () => {
    // The control. A rule that never passes would satisfy the test above and
    // lock the screen permanently.
    const wrapper = await mountView({
      products: [product()],
      rules: [companyDefaultRule()],
    })

    expect(pill(wrapper, 3)).toBe('เสร็จแล้ว')
    expect(wrapper.get('[data-test="step-tab-4"]').attributes('aria-disabled')).toBe('false')
  })
})

describe('CommissionPlansView — the start-here modal, and everything it refuses to be', () => {
  const missing = { products: [product()], rules: [], readiness: MISSING }

  it('opens once, says the money consequence, and offers one way forward', async () => {
    const wrapper = await mountView(missing)

    const modal = wrapper.get('[data-test="start-here-modal"]')
    expect(modal.get('[data-test="start-here-title"]').text()).toContain('ยังจ่ายค่าคอมให้ใครไม่ได้')
    expect(modal.text()).toContain('ไม่มีใครได้เงิน')
    // ONE primary action. A dialog with two equal buttons makes the reader
    // choose before they have read anything.
    expect(modal.findAll('button.btn-primary')).toHaveLength(1)
  })

  it('puts the admin on step 3 and closes, rather than explaining at them', async () => {
    const wrapper = await mountView(missing)

    await wrapper.get('[data-test="start-here-go"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="start-here-modal"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="step-panel-3"]').exists()).toBe(true)
    // ...on the sub-step that is actually the work.
    expect(wrapper.find('[data-test="substep-focus-3-1"]').exists()).toBe(true)
  })

  it('does not interrupt for ไม่ครบ, where somebody IS still being paid', async () => {
    /*
     * The line between the two states is the whole justification for opening by
     * itself. 'incomplete' means the agent is paid and the leader is not —
     * worth a banner, never worth a dialog, because an interruption spent on
     * the cheaper problem teaches the reader to close this one by reflex.
     */
    const wrapper = await mountView({ ...missing, readiness: INCOMPLETE })

    expect(wrapper.find('[data-test="start-here-modal"]').exists()).toBe(false)
  })

  it('never shows a Company Admin a dialog about something only a Super Admin can fix', async () => {
    /*
     * Commission config is Super-Admin-only to WRITE since 2026-09-11, and a
     * Company Admin still sees the banner and every number — reading is not a
     * write. A modal is different in kind: it takes the screen away until it is
     * dismissed, and the only action it offers is one they do not have.
     */
    useAuthStore().user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never

    const wrapper = await mountView(missing)

    expect(wrapper.find('[data-test="start-here-modal"]').exists()).toBe(false)
    // ...and the banner, which they CAN act on by asking, is untouched.
    expect(wrapper.find('[data-test="readiness-banner"]').exists()).toBe(true)
  })

  it('does not come back the same day once it has been waved away', async () => {
    /*
     * The property the deleted 2026-09-11 nag lacked. It used the same
     * per-company, per-state, per-day dismissal the readiness banner has always
     * used — its own surface suffix, so closing this dialog never silences the
     * strip above every page.
     */
    const first = await mountView(missing)
    await first.get('[data-test="start-here-dismiss"]').trigger('click')
    expect(first.find('[data-test="start-here-modal"]').exists()).toBe(false)

    const second = await mountView(missing)

    expect(second.find('[data-test="start-here-modal"]').exists()).toBe(false)
  })

  it('does not come back after the admin followed it, either', async () => {
    // "I am going to fix it" is at least as firm an acknowledgement as "not
    // now". Re-interrupting on the next entry — after a reload, after a company
    // switch — would rebuild the every-visit modal this one is careful not to
    // be. The banner keeps saying it all day; nothing goes silent.
    const first = await mountView(missing)
    await first.get('[data-test="start-here-go"]').trigger('click')
    await flushPromises()

    const second = await mountView(missing)

    expect(second.find('[data-test="start-here-modal"]').exists()).toBe(false)
  })

  it('stays shut when the server says everything is ready', async () => {
    const wrapper = await mountView({ products: [product()], rules: [companyDefaultRule()] })

    expect(wrapper.find('[data-test="start-here-modal"]').exists()).toBe(false)
  })
})
