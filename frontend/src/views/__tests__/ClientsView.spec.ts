/**
 * ClientsView — the "สินค้าที่สนใจ" block inside the client drawer, as it
 * exists after TASK-169 Phase 2 (it is ReferralsView's deal row now, with
 * TASK-141's payment state and one-press "เก็บเงินเลย" attached).
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. THE DEAL LIST STOPS MATCHING THE CLIENT. A client can have zero, one
 *     or several referrals, and the human's explicit rule (2026-07-13) is
 *     that ALL of them are listed, never collapsed to one value. Rendering
 *     one row for a three-deal client, or an empty <AppList> shell for a
 *     zero-deal client, throws no error anywhere — it just quietly hides
 *     deals the agent is supposed to be working.
 *
 *  2. MONEY STOPS BEING COLLECTABLE FROM HERE. "เก็บเงินเลย" is one press
 *     that must POST /orders and open the share sheet on the RETURNED
 *     `public_pay_url`. If the modal stops being fed that URL the button
 *     still "works" — an order is created server-side and the agent simply
 *     never gets a link to send, which looks like nothing happened.
 *
 *  3. THE DUPLICATE-ORDER 422 SURFACES AS AN ERROR. Pressing collect twice
 *     means "give me the link", not "make me a duplicate"
 *     (useReferralOrders' comment). The recovery path re-looks-up the
 *     existing order via `?referral_id=` and shares THAT. If it regresses,
 *     the agent sees a red failure on a deal that is perfectly fine.
 *
 *  4. A PAID DEAL INVITES A SECOND PAY LINK. Re-sharing a settled link only
 *     confuses a customer who has already paid, so paid rows must offer no
 *     action at all.
 *
 *  5. BR-1 QUIETLY STOPS GATING. The quick-add form creates a real Referral
 *     through POST /referrals, so an agent who has not passed Basic must
 *     not even be offered it — and must be told why (the API would reject
 *     it anyway, but a dead-end 422 is not an explanation). Both halves are
 *     asserted: the trigger's ABSENCE without a cert, and its PRESENCE with
 *     one, because a gate stuck closed is as broken as one stuck open.
 *
 *  6. PDPA LEAKS. `health_notes` is sensitive personal data (Section 6). It
 *     belongs in the drawer for a client this agent can already view, and
 *     nowhere on the list behind it. A leak onto the roster is invisible in
 *     a diff and permanent in a screenshot.
 *
 *  7. THE SPLIT-COMMISSION CONTROL GOES MISSING (TASK-169 Phase 4a). This
 *     drawer is now the ONLY place in the Agent Portal that can set
 *     `co_agent_id` / `split_percentage` — Phase 4b deletes ReferralsView,
 *     where the control used to live, and ag-lead made rehoming it a hard
 *     blocker on that deletion (§5b item 1) because it decides who BR-4
 *     pays. A drawer that renders every deal row perfectly but no longer
 *     mounts this control looks completely healthy in a screenshot. The
 *     control's own rules are asserted in CoAgentEditor.spec.ts; what is
 *     asserted HERE is that the drawer reaches it and sends the same
 *     request from inside a client.
 *
 * The API is mocked at `@/api/client` (same shape as
 * AcademyLessonView.spec.ts) — these are the view's real wiring assertions,
 * not the backend's; tenant isolation for /clients and /orders is enforced
 * and tested server-side (BR-6, §5.4).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
// TASK-169 Phase 3 — the view mode is read from the URL, so the view now
// needs a real router. Every assertion below is about LIST mode, which is
// what `/clients` with no `?view=` resolves to.
import { createMemoryHistory, createRouter } from 'vue-router'

const get = vi.fn()
const post = vi.fn()
// TASK-169 Phase 4a — the split-commission write is a PATCH.
const patch = vi.fn()

/**
 * The real ApiError is exported from the module being mocked, so the 422
 * branch needs a stand-in that `instanceof` still recognises. vi.hoisted()
 * because the factory below is hoisted above the imports.
 */
const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
    ) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    patch: (...args: unknown[]) => patch(...args),
    put: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
    downloadAbsolute: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import ClientsView from '../ClientsView.vue'
// TASK-067 — the BR-1 gate asks "have *I* passed Basic", so the test has to
// say who "I" am; see SELF_ID.
import { useAuthStore, type AuthUser } from '@/stores/auth'
import AppList from '@/design-system/components/AppList.vue'
import ReferralRow from '@/design-system/components/ReferralRow.vue'
import CoAgentEditor from '@/design-system/components/CoAgentEditor.vue'
import ShareLinkModal from '@/design-system/components/ShareLinkModal.vue'

const HEALTH_NOTES = 'แพ้ยาเพนนิซิลิน'
/** This company's other agents — `GET /referrals/co-agent-options`. */
const CO_AGENTS = [{ id: 7, name: 'ตัวแทน ก' }]
/**
 * The logged-in agent. Certification rows carry a `user_id`, and the BR-1
 * gate compares it to THIS id (TASK-067) — so both sides must be real
 * numbers. Leaving either undefined would make the comparison
 * `undefined === undefined`, i.e. a gate that opens for everyone while the
 * test still passes: the exact tautology this constant exists to prevent.
 */
const SELF_ID = 9
const OTHER_AGENT_ID = 42

/** Puts a real logged-in user in the store the view reads. */
function signIn(id: number = SELF_ID) {
  useAuthStore().user = { id, name: 'ตัวแทน ทดสอบ' } as AuthUser
}

function referralFixture(id: number, productName: string, overrides: Record<string, unknown> = {}) {
  return {
    id,
    product: { id: id * 10, name: productName, price_satang: 890000 },
    branch: 'สาขาสีลม',
    preferred_time: null,
    current_stage: { key: 'complete_registered', label: 'Complete Registered' },
    co_agent: null,
    split_percentage: null,
    ...overrides,
  }
}

function clientFixture(referrals: unknown[]) {
  return {
    id: 1,
    name: 'คุณสมชาย ใจดี',
    phone: '0800000000',
    email: null,
    consent_given_at: null,
    health_notes: HEALTH_NOTES,
    referring_agent_id: 9,
    status: { key: 'new', label: 'New' },
    lead_source: null,
    date_of_birth: null,
    address: null,
    province: null,
    occupation: null,
    referrals,
    created_at: '2026-08-01T00:00:00Z',
    client_category_id: null,
    client_category_name: null,
  }
}

function orderFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    order_number: 'ORD-0001',
    status: 'pending',
    status_label: 'รอชำระเงิน',
    public_pay_url: 'https://pay.test/new',
    referral_id: 1,
    // TASK-191 §3.2 — needed by mostRecentPaidReferralId()'s sort. `null` by
    // default so every existing fixture (written before this field existed)
    // keeps meaning "not paid / no timestamp" unless a test says otherwise.
    paid_at: null,
    ...overrides,
  }
}

const stubs = {
  HeroHeader: true,
  LoadingSkeleton: true,
  FilterSheet: true,
  BuddhistDateInput: true,
  AuthenticatedMedia: true,
  ConfirmDialog: true,
}

interface WireOptions {
  referrals?: unknown[]
  /**
   * TASK-174 — what `GET /commission-split-settings` answers.
   *
   * Defaults to TRUE so every assertion above (written when the split was the
   * only behaviour) keeps testing the switched-ON world it was written for.
   * The switched-OFF world gets its own describe block at the bottom.
   */
  splitEnabled?: boolean
  /** What the up-front `GET /orders?page=N` sweep returns. */
  orders?: unknown[]
  /**
   * What the narrow `GET /orders?referral_id=N` recovery lookup returns.
   * Kept separate from `orders` on purpose: in the duplicate-order case the
   * sweep must come back EMPTY (otherwise the row would already show a chip
   * and never offer "เก็บเงินเลย" to press).
   */
  lookupOrders?: unknown[]
  /** BR-1: `[]` = has NOT passed Basic. */
  certifications?: unknown[]
}

function wire({
  referrals = [],
  orders = [],
  lookupOrders = [],
  certifications,
  splitEnabled = true,
}: WireOptions = {}) {
  const certs = certifications ?? [
    { id: 1, user_id: SELF_ID, cert_tier: { id: 1, key: 'basic', name: 'Basic' } },
  ]
  get.mockImplementation((path: string) => {
    if (path === '/clients' || path.startsWith('/clients?'))
      return Promise.resolve({ data: [clientFixture(referrals)] })
    // refreshSelectedClient() — the single-client re-read the drawer does
    // after a write (TASK-026's split, a status change, a new referral).
    if (/^\/clients\/\d+$/.test(path)) return Promise.resolve({ data: clientFixture(referrals) })
    if (path === '/commission-split-settings')
      return Promise.resolve({ data: { is_enabled: splitEnabled } })
    // TASK-174 — the server answers 403 here while the split is off, and the
    // view must not even ask. Throwing (rather than returning an empty list)
    // is what makes the "does not fetch the picker" assertion below real: a
    // view that still called this would fail loudly instead of silently
    // rendering an empty picker.
    if (path === '/referrals/co-agent-options') {
      if (!splitEnabled) throw new Error('403 — co-agent options must not be fetched when off')
      return Promise.resolve({ data: CO_AGENTS })
    }
    if (path === '/products') return Promise.resolve({ data: [] })
    if (path === '/user-certifications') return Promise.resolve({ data: certs })
    if (path === '/client-categories') return Promise.resolve({ data: [] })
    if (path.endsWith('/documents')) return Promise.resolve({ data: [] })
    if (path.endsWith('/activities')) return Promise.resolve({ data: [] })
    if (path.startsWith('/orders?referral_id=')) return Promise.resolve({ data: lookupOrders })
    if (path.startsWith('/orders'))
      return Promise.resolve({ data: orders, meta: { current_page: 1, last_page: 1 } })
    throw new Error(`unexpected GET ${path}`)
  })
}

async function mountList() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/clients', component: { template: '<div />' } }],
  })
  await router.push('/clients')
  await router.isReady()
  const wrapper = mount(ClientsView, { global: { stubs, plugins: [router] } })
  await flushPromises()
  return wrapper
}

/** The client roster row is the only interactive (AppCard) surface on the page. */
async function openDrawer(wrapper: Awaited<ReturnType<typeof mountList>>) {
  await wrapper.find('.cursor-pointer').trigger('click')
  await flushPromises()
  return wrapper
}

function collectButton(wrapper: Awaited<ReturnType<typeof mountList>>, index = 0) {
  // The row is asserted separately from the button so a missing ROW says so,
  // instead of surfacing as "no เก็บเงินเลย button" — two different failures
  // that used to produce the same message. (`noUncheckedIndexedAccess` is on,
  // so the index access is possibly-undefined and must be handled, not `!`-ed.)
  const row = wrapper.findAllComponents(ReferralRow)[index]
  if (!row) throw new Error(`no deal row at index ${index}`)
  const button = row.findAll('button').find((b) => b.text().includes('เก็บเงินเลย'))
  if (!button) throw new Error('no "เก็บเงินเลย" button on that deal row')
  return button
}

describe('ClientsView — client drawer deal block (TASK-169 Phase 2)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    signIn()
    vi.clearAllMocks()
  })

  it('renders the empty state and NO row for a client with zero deals', async () => {
    wire({ referrals: [] })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.findAllComponents(ReferralRow)).toHaveLength(0)
    expect(wrapper.text()).toContain('ยังไม่มีสินค้าที่สนใจ')
    // …and no empty list shell either. The deal <AppList> is the v-else of
    // that same empty state, so with zero deals the ONLY AppList on the page
    // is the client roster's — an empty rounded box under the heading would
    // read as "something failed to load".
    expect(wrapper.findAllComponents(AppList)).toHaveLength(1)
  })

  it('renders exactly one row for a client with one deal', async () => {
    wire({ referrals: [referralFixture(1, 'แพ็กเกจ A')] })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.findAllComponents(ReferralRow)).toHaveLength(1)
    expect(wrapper.text()).not.toContain('ยังไม่มีสินค้าที่สนใจ')
    // The roster's list plus the deal list — i.e. the container the previous
    // test asserts is absent.
    expect(wrapper.findAllComponents(AppList)).toHaveLength(2)
  })

  it('renders one row per deal for a client with several', async () => {
    wire({
      referrals: [
        referralFixture(1, 'แพ็กเกจ A'),
        referralFixture(2, 'แพ็กเกจ B'),
        referralFixture(3, 'แพ็กเกจ C'),
      ],
    })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.findAllComponents(ReferralRow)).toHaveLength(3)
    // Not just the count — every product is actually named (hide-client
    // means the product IS the hero line of each row).
    for (const name of ['แพ็กเกจ A', 'แพ็กเกจ B', 'แพ็กเกจ C']) {
      expect(wrapper.text()).toContain(name)
    }
  })

  it('เก็บเงินเลย posts the order and opens the share sheet on the returned pay link', async () => {
    wire({ referrals: [referralFixture(1, 'แพ็กเกจ A')] })
    post.mockResolvedValue({ data: orderFixture() })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.findComponent(ShareLinkModal).props('show')).toBe(false)

    await collectButton(wrapper).trigger('click')
    await flushPromises()

    // payment_method is always 'promptpay' — deliberately not asked for
    // (see useReferralOrders' comment); the customer picks how they pay ON
    // the pay page.
    expect(post).toHaveBeenCalledWith('/orders', { referral_id: 1, payment_method: 'promptpay' })

    const modal = wrapper.findComponent(ShareLinkModal)
    expect(modal.props('show')).toBe(true)
    expect(modal.props('url')).toBe('https://pay.test/new')
  })

  it('shares the EXISTING order on a duplicate-order 422 instead of reporting an error', async () => {
    wire({
      referrals: [referralFixture(1, 'แพ็กเกจ A')],
      lookupOrders: [orderFixture({ id: 9, order_number: 'ORD-0009', public_pay_url: 'https://pay.test/existing' })],
    })
    post.mockRejectedValue(new FakeApiError(422, { errors: { referral_id: ['มีคำสั่งซื้อที่ยังไม่ชำระอยู่แล้ว'] } }))
    const wrapper = await openDrawer(await mountList())

    get.mockClear()
    await collectButton(wrapper).trigger('click')
    await flushPromises()

    // The recovery is a NARROW server-side lookup, not a client-side scan of
    // every order the agent owns.
    expect(get).toHaveBeenCalledWith('/orders?referral_id=1', expect.anything())

    const modal = wrapper.findComponent(ShareLinkModal)
    expect(modal.props('show')).toBe(true)
    expect(modal.props('url')).toBe('https://pay.test/existing')
    // The 422 was recovered from, so the agent must see no failure at all.
    expect(wrapper.text()).not.toContain('สร้างลิงก์ชำระเงินไม่สำเร็จ')
  })

  it('shows the paid chip, offers no collect button, but DOES offer to re-share on a settled deal', async () => {
    // TASK-191 §3.1 REVERSES the old "paid: no action at all" rule. The
    // reasoning that used to justify hiding it ("re-sharing a settled pay
    // link only confuses a customer who has already paid") no longer holds:
    // TASK-189 made this same link the one place a paid VOUCHER renders, and
    // TASK-190 exists specifically because nothing else re-surfaces that
    // link to a customer after payment. Collecting a SECOND order is still
    // refused — that half of ReferralRow's fork is unchanged.
    wire({
      referrals: [referralFixture(1, 'แพ็กเกจ A')],
      orders: [orderFixture({ status: 'paid', status_label: 'ชำระเงินแล้ว' })],
    })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.text()).toContain('ชำระเงินแล้ว')
    expect(wrapper.text()).not.toContain('เก็บเงินเลย')
    expect(wrapper.text()).toContain('แชร์ลิงก์ชำระเงิน')
  })

  it('BR-1: hides the quick-add trigger and explains why when Basic has not been passed', async () => {
    wire({ referrals: [referralFixture(1, 'แพ็กเกจ A')], certifications: [] })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.text()).not.toContain('+ เพิ่มสินค้าที่สนใจ')
    expect(wrapper.text()).toContain('ต้องผ่านใบรับรอง Basic ก่อนจึงจะเพิ่มสินค้าที่สนใจได้ (BR-1)')
    // BR-1 gates SELLING NEW, not collecting on a deal that already exists —
    // the existing row keeps its payment action.
    expect(wrapper.text()).toContain('เก็บเงินเลย')
  })

  it('BR-1 + PDPA: with Basic the trigger is offered, and health_notes appear only once the drawer is open', async () => {
    wire({ referrals: [referralFixture(1, 'แพ็กเกจ A')] })
    const wrapper = await mountList()

    // Section 6: sensitive health data must not be on the roster behind the
    // drawer, even though the list response carries it.
    expect(wrapper.text()).not.toContain(HEALTH_NOTES)
    expect(wrapper.text()).not.toContain('บันทึกสุขภาพ (PDPA)')

    await openDrawer(wrapper)

    expect(wrapper.text()).toContain(HEALTH_NOTES)
    expect(wrapper.text()).toContain('บันทึกสุขภาพ (PDPA)')
    expect(wrapper.text()).toContain('+ เพิ่มสินค้าที่สนใจ')
    expect(wrapper.text()).not.toContain('ต้องผ่านใบรับรอง Basic')
  })

  /**
   * TASK-067, carried onto this screen by TASK-169 Phase 4b.
   *
   * `GET /user-certifications` returns the WHOLE company roster to a Company
   * Admin / Super Admin, so an unfiltered `.some(c => c.cert_tier?.key ===
   * 'basic')` answers "has anyone here passed Basic" and opens BR-1's gate
   * for a caller who has not. For a plain Agent the endpoint only ever
   * returns their own rows, which is exactly why this stayed invisible: it
   * cannot be reproduced as the role that uses the screen most.
   *
   * The fix (and this test) came from ReferralsView, which is deleted in this
   * phase — so without moving both across, the deletion would have taken the
   * only correct implementation AND the only record of the rule with it.
   */
  it('BR-1: someone ELSE’s Basic certification does not open the gate', async () => {
    wire({
      referrals: [referralFixture(1, 'แพ็กเกจ A')],
      certifications: [
        { id: 1, user_id: OTHER_AGENT_ID, cert_tier: { id: 1, key: 'basic', name: 'Basic' } },
      ],
    })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.text()).not.toContain('+ เพิ่มสินค้าที่สนใจ')
    expect(wrapper.text()).toContain('ต้องผ่านใบรับรอง Basic ก่อนจึงจะเพิ่มสินค้าที่สนใจได้ (BR-1)')
  })

  it('BR-1: a non-Basic tier of my OWN does not open the gate either', async () => {
    wire({
      referrals: [referralFixture(1, 'แพ็กเกจ A')],
      certifications: [
        { id: 1, user_id: SELF_ID, cert_tier: { id: 2, key: 'intermediate', name: 'Intermediate' } },
      ],
    })
    const wrapper = await openDrawer(await mountList())

    // Basic is the MANDATORY gate (CLAUDE.md §2) — a higher tier row without
    // it is not a substitute, and the predicate must test both halves.
    expect(wrapper.text()).not.toContain('+ เพิ่มสินค้าที่สนใจ')
    expect(wrapper.text()).toContain('ต้องผ่านใบรับรอง Basic ก่อนจึงจะเพิ่มสินค้าที่สนใจได้ (BR-1)')
  })
})

/**
 * TASK-191 §3.2 — the collapsed-card share button, next to the client's
 * name, BEFORE any drawer is opened.
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. IT APPEARS FOR A CLIENT WITH NO PAID ORDER. `mostRecentPaidReferralId`
 *     returning something for a client who has never paid would offer to
 *     share a link that either does not exist or is for the wrong deal.
 *  2. IT NEVER APPEARS AT ALL. Silently regresses the whole point of the
 *     task (TASK-189/190) — an agent has no way left to re-send a paid
 *     voucher without opening the drawer and hunting for the right row.
 *  3. THE WRONG DEAL IS SHARED. A client with 2+ paid referrals (renewals,
 *     multiple products) must resolve to the MOST RECENTLY paid one — the
 *     judgment call the component's own doc comment records. Picking the
 *     first instead of the newest silently sends a customer last year's
 *     voucher.
 *
 * `ensureOrdersLoaded()` runs from `onMounted()` (TASK-191 §3.2 revises the
 * old "defer to first drawer open" timing precisely so this button has data
 * before any drawer exists), so these tests deliberately do NOT open a
 * drawer — asserting the collapsed card alone is what proves the timing
 * change actually works, not just the button's own template guard.
 */
describe('ClientsView — collapsed-card share button (TASK-191 §3.2)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    signIn()
    vi.clearAllMocks()
  })

  /** The icon-only button next to the client's name — found by its title, not its (absent) text. */
  function collapsedShareButton(wrapper: Awaited<ReturnType<typeof mountList>>) {
    return wrapper.findAll('button').find((b) => b.attributes('title') === 'แชร์ลิงก์ชำระเงิน / ใบเสร็จ')
  }

  it('is absent when the client has no order at all', async () => {
    wire({ referrals: [referralFixture(1, 'แพ็กเกจ A')], orders: [] })
    const wrapper = await mountList()

    expect(collapsedShareButton(wrapper)).toBeUndefined()
  })

  it('is absent when the client has an order that is not yet paid', async () => {
    wire({
      referrals: [referralFixture(1, 'แพ็กเกจ A')],
      orders: [orderFixture({ status: 'pending', status_label: 'รอชำระเงิน' })],
    })
    const wrapper = await mountList()

    expect(collapsedShareButton(wrapper)).toBeUndefined()
  })

  it('appears once there is a paid order, and opens the share sheet on that order\'s own link — without opening the drawer', async () => {
    wire({
      referrals: [referralFixture(1, 'แพ็กเกจ A')],
      orders: [
        orderFixture({
          status: 'paid',
          status_label: 'ชำระเงินแล้ว',
          order_number: 'ORD-0777',
          public_pay_url: 'https://pay.test/voucher-777',
          paid_at: '2026-08-10T00:00:00Z',
          referral_id: 1,
        }),
      ],
    })
    const wrapper = await mountList()

    // No drawer opened — the roster row is the only thing on screen besides
    // the button itself.
    expect(wrapper.find('.drawer-panel').exists()).toBe(false)

    const button = collapsedShareButton(wrapper)
    if (!button) throw new Error('collapsed-card share button is missing for a client with a paid order')
    await button.trigger('click')

    const modal = wrapper.findComponent(ShareLinkModal)
    expect(modal.props('show')).toBe(true)
    expect(modal.props('url')).toBe('https://pay.test/voucher-777')
  })

  it('resolves to the MOST RECENTLY paid referral when the client has two', async () => {
    wire({
      referrals: [referralFixture(1, 'แพ็กเกจ A'), referralFixture(2, 'แพ็กเกจ B')],
      orders: [
        orderFixture({
          id: 10,
          order_number: 'ORD-0010',
          status: 'paid',
          status_label: 'ชำระเงินแล้ว',
          public_pay_url: 'https://pay.test/old',
          paid_at: '2026-08-01T00:00:00Z',
          referral_id: 1,
        }),
        orderFixture({
          id: 11,
          order_number: 'ORD-0011',
          status: 'paid',
          status_label: 'ชำระเงินแล้ว',
          public_pay_url: 'https://pay.test/newer',
          paid_at: '2026-08-15T00:00:00Z',
          referral_id: 2,
        }),
      ],
    })
    const wrapper = await mountList()

    const button = collapsedShareButton(wrapper)
    if (!button) throw new Error('collapsed-card share button is missing')
    await button.trigger('click')

    // referral 2's order (paid_at 08-15) wins over referral 1's (08-01).
    const modal = wrapper.findComponent(ShareLinkModal)
    expect(modal.props('show')).toBe(true)
    expect(modal.props('url')).toBe('https://pay.test/newer')
  })
})

/**
 * TASK-169 Phase 4a — the split-commission control, REACHED FROM THE CLIENT
 * DRAWER.
 *
 * CoAgentEditor.spec.ts owns the control's own rules (payload shape, the
 * clear, the 1–99 gate, the BR-4 cutoff, the 422 path). What is asserted
 * here is the thing Phase 4b's deletion actually depends on: that a deal
 * inside a client can still be shared, from the only screen that will be
 * left, with the co-agent list actually loaded — a picker mounted with an
 * empty options array renders and does nothing, which is indistinguishable
 * from a working control until an agent tries to use it.
 */
describe('ClientsView — TASK-026 co-agent editor in the drawer (TASK-169 Phase 4a)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    signIn()
    vi.clearAllMocks()
  })

  /** The editor for the Nth deal row in the open drawer. */
  function editorFor(wrapper: Awaited<ReturnType<typeof mountList>>, index = 0) {
    const editor = wrapper.findAllComponents(CoAgentEditor)[index]
    if (!editor) throw new Error('no CoAgentEditor on that deal row')
    return editor
  }

  /**
   * Choose the <option> at `index` on that editor's single <select>.
   *
   * These tests used `findAll('option')[i].setSelected()`, which stopped
   * type-checking when @vue/test-utils made `DOMWrapper.setSelected` private
   * (TS2341). Setting the <select>'s value is the supported equivalent and
   * fires the same `change`; the value is read OFF the option, so it is still
   * whatever the component bound rather than a literal repeated here.
   * Same helper as CoAgentEditor.spec.ts — deliberately local to each spec, so
   * neither file grows an import of the other's private test scaffolding.
   */
  async function selectOption(editor: ReturnType<typeof editorFor>, index: number) {
    const option = editor.findAll('option')[index]
    if (!option) throw new Error(`no <option> at index ${index}`)
    await editor.find('select').setValue((option.element as HTMLOptionElement).value)
  }

  it('mounts one editor per deal, populated from GET /referrals/co-agent-options', async () => {
    wire({ referrals: [referralFixture(1, 'แพ็กเกจ A'), referralFixture(2, 'แพ็กเกจ B')] })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.findAllComponents(CoAgentEditor)).toHaveLength(2)

    // Open one and check the picker is not an empty shell. The options come
    // from the API, are company-scoped server-side, and never include the
    // agent themselves (ReferralController::coAgentOptions) — BR-6 is not
    // something this list is trusted to enforce, but a list that never
    // arrived is a control that cannot be used.
    const editor = editorFor(wrapper)
    await editor.find('button').trigger('click')
    expect(editor.findAll('option').map((o) => o.text())).toEqual(['ไม่แบ่งคอมมิชชั่น', 'ตัวแทน ก'])
  })

  it('sends the same PATCH ReferralsView sends, then re-reads the open client', async () => {
    wire({ referrals: [referralFixture(1, 'แพ็กเกจ A')] })
    const wrapper = await openDrawer(await mountList())
    const editor = editorFor(wrapper)

    await editor.find('button').trigger('click')
    await selectOption(editor, 1)
    await editor.find('input[type="number"]').setValue('30')
    patch.mockResolvedValue({})

    get.mockClear()
    // The save control is AppButton's <button>; found by label so the test
    // does not depend on which button happens to come first in the DOM.
    const save = editor.findAll('button').find((b) => b.text() === 'บันทึก')
    if (!save) throw new Error('no save button in the open editor')
    await save.trigger('click')
    await flushPromises()

    expect(patch).toHaveBeenCalledWith('/referrals/1/co-agent', {
      co_agent_id: 7,
      split_percentage: 30,
    })
    // The drawer must show the new split without a full list reload — the
    // single-client re-read is what makes that true (and what silently
    // dropped the co-agent line before ClientController got one RELATIONS
    // constant, TASK-169 Phase 2).
    expect(get).toHaveBeenCalledWith('/clients/1', expect.anything())
  })

  it('shows an existing split on the row and offers to change it, not to add a second', async () => {
    wire({
      referrals: [
        referralFixture(1, 'แพ็กเกจ A', {
          co_agent: { id: 7, name: 'ตัวแทน ก' },
          split_percentage: 30,
        }),
      ],
    })
    const wrapper = await openDrawer(await mountList())

    // ReferralRow's own read-only line…
    expect(wrapper.text()).toContain('แบ่งคอมฯ กับ ตัวแทน ก (30%)')
    // …and the editor's, which is what tells the agent it is CHANGEABLE.
    expect(editorFor(wrapper).text()).toContain('แก้ไขคอมฯ ร่วม')
    expect(wrapper.text()).not.toContain('+ แบ่งคอมฯ')
  })

  it('clears a split from the drawer with both fields null', async () => {
    wire({
      referrals: [
        referralFixture(1, 'แพ็กเกจ A', {
          co_agent: { id: 7, name: 'ตัวแทน ก' },
          split_percentage: 30,
        }),
      ],
    })
    const wrapper = await openDrawer(await mountList())
    const editor = editorFor(wrapper)

    await editor.find('button').trigger('click')
    await selectOption(editor, 0)
    patch.mockResolvedValue({})

    const save = editor.findAll('button').find((b) => b.text() === 'บันทึก')
    if (!save) throw new Error('no save button in the open editor')
    await save.trigger('click')
    await flushPromises()

    expect(patch).toHaveBeenCalledWith('/referrals/1/co-agent', {
      co_agent_id: null,
      split_percentage: null,
    })
  })
})

/**
 * TASK-172 — after saving a new client, the drawer opens ON THAT CLIENT.
 *
 * Silent-failure risk: without this the save still "works" (toast, list
 * reloads) and the agent is simply dropped back on a server-sorted roster to
 * hunt for the person they just typed in. Until TASK-169 Phase 4 the deleted
 * ReferralsView did add-client and add-deal in one form, so this is the seam
 * where the merge could quietly cost a step and nothing would fail.
 *
 * `HeroHeader` is stubbed for real in this block: the "+ เพิ่มลูกค้าใหม่"
 * trigger lives in its #actions slot, so the default `true` stub would hide
 * the very control under test.
 */
describe('ClientsView — new client opens its own drawer (TASK-172)', () => {
  const headerStubs = {
    ...stubs,
    HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /></div>' },
  }

  async function mountWithHeader() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/clients', component: { template: '<div />' } }],
    })
    await router.push('/clients')
    await router.isReady()
    const wrapper = mount(ClientsView, { global: { stubs: headerStubs, plugins: [router] } })
    await flushPromises()
    return wrapper
  }

  async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof mountWithHeader>>) {
    // NavBarAction with an icon carries its name in aria-label, not in text
    // — an icon-only button is exactly the kind a text query silently misses.
    const trigger = wrapper
      .findAll('button')
      .find((b) => b.attributes('aria-label')?.includes('เพิ่มลูกค้าใหม่'))
    if (!trigger) throw new Error('the "+ เพิ่มลูกค้าใหม่" trigger is missing')
    await trigger.trigger('click')
    await flushPromises()

    // EXACT match, not `includes`: the roster's search box is placeholdered
    // "ค้นหาชื่อ, เบอร์โทร, อีเมล..." and a substring query silently types the
    // phone number into it instead, leaving the real field empty and the
    // submit blocked by validation with no obvious cause.
    const input = (placeholder: string) => {
      const el = wrapper.findAll('input').find((i) => i.attributes('placeholder') === placeholder)
      if (!el) throw new Error(`no input placeholdered exactly "${placeholder}"`)
      return el
    }
    await input('ชื่อลูกค้า').setValue('คุณสมชาย ใจดี')
    await input('เบอร์โทร').setValue('0800000000')

    const save = wrapper.findAll('button').find((b) => b.text().trim() === 'บันทึกลูกค้า')
    if (!save) throw new Error('no บันทึกลูกค้า button in the create form')
    await save.trigger('click')
    await flushPromises()
  }

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    signIn()
  })

  it('opens the drawer on the client it just created', async () => {
    wire({ referrals: [] })
    // The reloaded roster returns id 1, and so does the POST — the created
    // client IS in the list, which is the normal case.
    post.mockResolvedValue({ data: clientFixture([]) })

    const wrapper = await mountWithHeader()
    await fillAndSubmit(wrapper)

    expect(post).toHaveBeenCalledWith('/clients', expect.objectContaining({ name: 'คุณสมชาย ใจดี' }))
    // The drawer, and only the drawer, renders the PDPA health note.
    expect(wrapper.text()).toContain(HEALTH_NOTES)
  })

  it('does NOT open a drawer when the new client is absent from the reloaded list', async () => {
    wire({ referrals: [] })
    // A live search term or category filter can legitimately exclude the new
    // client. `selectedClient` is a lookup INTO the list, so opening anyway
    // would render an empty panel — worse than not opening.
    post.mockResolvedValue({ data: { ...clientFixture([]), id: 999 } })

    const wrapper = await mountWithHeader()
    await fillAndSubmit(wrapper)

    expect(post).toHaveBeenCalled()
    expect(wrapper.text()).not.toContain(HEALTH_NOTES)
  })
})

/**
 * TASK-174 — the co-agent split SWITCHED OFF for this company.
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. THE CONTROL COMES BACK. The whole task exists because the human asked
 *     for this to be hidden during early rollout ("ทำให้สับสนตรวจสอบยากในช่วง
 *     ขึ้นระบบแรกๆ"). The server refuses the write either way, so a
 *     resurrected editor does not corrupt data — it hands an agent a control
 *     whose only possible outcome is a 403, on the screen that decides who
 *     gets paid. Nothing throws; it just looks like a broken feature.
 *
 *  2. A DEAD 403 LANDS IN THE ERROR BANNER ON EVERY PAGE LOAD.
 *     `GET /referrals/co-agent-options` is refused while the split is off, so
 *     the picker must not be fetched at all. Fetching it anyway would name
 *     "การแบ่งคอมมิชชั่น" as a failed load on a screen where nothing is
 *     actually wrong — training agents to ignore that banner.
 *
 *  3. A STALE SPLIT IS STILL DISPLAYED. `co_agent_id` is deliberately NOT
 *     nulled in the database (spec §3 — reversible means reversible), so the
 *     ONLY thing hiding it is `ReferralResource` omitting the keys. The row
 *     must render nothing for an absent key rather than "แบ่งคอมฯ กับ
 *     undefined".
 *
 * The switched-ON assertions live in the block above; both directions are
 * asserted on purpose, because a control stuck hidden is as broken as one
 * stuck visible.
 */
describe('ClientsView — TASK-174 co-agent split switched OFF', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    signIn()
    vi.clearAllMocks()
  })

  /**
   * A referral EXACTLY as the API serialises it while the split is off: the
   * two keys are ABSENT, not null. Built by deletion from the shared fixture
   * so it cannot drift from the on-state shape it is contrasted with.
   */
  function referralWithoutSplitKeys(id: number, productName: string) {
    const r = referralFixture(id, productName) as Record<string, unknown>
    delete r.co_agent
    delete r.split_percentage
    return r
  }

  it('renders NO co-agent editor on any deal row', async () => {
    wire({
      splitEnabled: false,
      referrals: [
        referralWithoutSplitKeys(1, 'แพ็กเกจ A'),
        referralWithoutSplitKeys(2, 'แพ็กเกจ B'),
      ],
    })
    const wrapper = await openDrawer(await mountList())

    // The rows themselves are unaffected — this is a targeted removal, not a
    // drawer that failed to render. Asserting both halves is what stops this
    // test passing for the wrong reason (an empty drawer has no editor either).
    expect(wrapper.findAllComponents(ReferralRow)).toHaveLength(2)
    expect(wrapper.findAllComponents(CoAgentEditor)).toHaveLength(0)
    expect(wrapper.text()).not.toContain('+ แบ่งคอมฯ')
    expect(wrapper.text()).not.toContain('แบ่งคอมมิชชั่น')
  })

  it('does NOT fetch the co-agent picker, and reports no load failure', async () => {
    wire({ splitEnabled: false, referrals: [referralWithoutSplitKeys(1, 'แพ็กเกจ A')] })
    const wrapper = await openDrawer(await mountList())

    // The flag itself IS read — once per page load, not once per row.
    const settingsCalls = get.mock.calls.filter((c) => c[0] === '/commission-split-settings')
    expect(settingsCalls).toHaveLength(1)
    // …and the endpoint that would 403 is never touched.
    expect(get.mock.calls.map((c) => c[0])).not.toContain('/referrals/co-agent-options')
    // Nothing failed, so the drawer's "โหลดข้อมูลไม่สำเร็จ" banner stays away.
    expect(wrapper.text()).not.toContain('โหลดข้อมูลไม่สำเร็จ')
  })

  it('shows no stale split on the row when the API omits the fields', async () => {
    wire({ splitEnabled: false, referrals: [referralWithoutSplitKeys(1, 'แพ็กเกจ A')] })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.text()).toContain('แพ็กเกจ A')
    expect(wrapper.text()).not.toContain('แบ่งคอมฯ กับ')
    expect(wrapper.text()).not.toContain('undefined')
  })

  it('keeps the editor when the switch is ON — the same fixtures, one flag apart', async () => {
    // The control test for all three above: identical wiring except the flag,
    // so a change that hides the editor unconditionally cannot pass this file.
    wire({ splitEnabled: true, referrals: [referralFixture(1, 'แพ็กเกจ A')] })
    const wrapper = await openDrawer(await mountList())

    expect(wrapper.findAllComponents(CoAgentEditor)).toHaveLength(1)
    expect(get.mock.calls.map((c) => c[0])).toContain('/referrals/co-agent-options')
  })
})
