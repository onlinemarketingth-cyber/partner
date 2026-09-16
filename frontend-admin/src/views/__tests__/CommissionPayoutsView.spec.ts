/**
 * CommissionPayoutsView — จ่ายค่าแนะนำ, the whole payout lifecycle on one page.
 *
 * ── WHAT THIS FILE IS ABOUT ──
 *
 * 1. TASK-179 §3.7 (F-10): a bucket the filter excluded is NOT zero.
 *
 *    `AgentCommissionSummaryService` used to force the excluded bucket to
 *    literal 0, so filtering by "จ่ายแล้ว" rendered "รอจ่ายรวม 0 บาท" —
 *    indistinguishable from "we owe our agents nothing", on the screen an admin
 *    uses to decide what to pay. Phase 1 fixed the source: the excluded bucket
 *    comes back `null`. That fix is worth nothing if this layer writes `?? 0`,
 *    so the assertions below are about what is NOT printed.
 *
 * 2. 2026-09-16 — ตั้งจ่าย AND รอบจ่าย WERE MERGED INTO THIS SCREEN.
 *
 *    Owner: "การตั้งจ่าย กับรอบจ่าย มันแทบจะแทนกันได้แล้ว" → "รวมเป็นหน้าเดียว
 *    จริงๆ". One band, one errand:
 *
 *      ① ตั้งจ่ายได้เลย (คน) → ② รอคุณตรวจสอบ (ใบ) → ③ รอฝ่ายบัญชีโอน (ใบ)
 *
 *    ── THE ONE PROPERTY WORTH MOST OF THIS FILE ──
 *
 *    The unit of a row CHANGES at step ②: steps ① and ติดปัญหา list people,
 *    steps ② and ③ list requests. The same agent can legitimately be a row in
 *    ① for 500 and a row in ③ for 1,500 at once. A reader who assumed the band
 *    followed the same people would read that as a contradiction, so the unit
 *    badge is pinned here rather than treated as decoration.
 *
 * ── WHAT CAME FROM CommissionPayoutQueue.spec.ts (deleted 2026-09-16) ──
 *
 * That file tested รอบจ่าย's panel. Two of its assertions survive here, because
 * they are about the WORK and the work moved: every request row says whose
 * decision it was (an agent's approved request and a company payout sit side by
 * side in ③ and answer to different people), and the empty state says nothing
 * arrives by itself. The rest were about a page that no longer exists.
 *
 * The API is mocked at `@/api/client`. Authorization, tenant scoping and the
 * money rules themselves are enforced and tested server-side (BR-6).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
// Captured, not an anonymous vi.fn() in the factory: the batch presses below
// assert on the exact body sent, and an unreachable mock can only be asserted
// to have been called.
const post = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  /*
   * This double extracts the message the way the real ApiError does (see
   * ApiError.extractMessage). It used to always be `API error ${status}`, which
   * is the shape the real class was FIXED away from — so a screen that surfaces
   * the server's own sentence could not be tested here at all, and a double
   * that behaves differently from the real thing in exactly the way a test is
   * about is worse than no double.
   */
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
    ) {
      const b = (body ?? {}) as { message?: string; errors?: Record<string, string[]> }
      const firstFieldError = b.errors ? Object.values(b.errors)[0]?.[0] : undefined
      super(firstFieldError ?? b.message ?? `API error ${status}`)
    }
  },
}))

/*
 * This view reads `?view=` / `?tab=` at setup to choose which step opens. An
 * unmocked router makes `useRoute()` undefined and every test here dies in
 * setup, on a line that has nothing to do with what it asserts.
 */
const routeQuery: { value: Record<string, unknown> } = { value: {} }

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: routeQuery.value }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import CommissionPayoutsView from '../CommissionPayoutsView.vue'

// ── Fixtures ────────────────────────────────────────────────────────────
// BR-3 — the API sends integer satang. 1,500.00 THB = 150_000 satang.
const FIFTEEN_HUNDRED_BAHT = 150_000
const TWO_THOUSAND_BAHT = 200_000

interface RowOverrides {
  agent_id?: number
  agent_name?: string
  total_paid_satang?: number | null
  total_pending_satang?: number | null
  is_company_share?: boolean
  payout_details_complete?: boolean
  bank_account_number?: string | null
  reserved_satang?: number
  available_satang?: number | null
}

/** A PERSON row, as /agent-commission-summary returns one. */
function makeRow(overrides: RowOverrides = {}) {
  return {
    agent_id: overrides.agent_id ?? 1,
    agent_name: overrides.agent_name ?? 'สมชาย',
    total_paid_satang: overrides.total_paid_satang === undefined ? FIFTEEN_HUNDRED_BAHT : overrides.total_paid_satang,
    total_pending_satang:
      overrides.total_pending_satang === undefined ? TWO_THOUSAND_BAHT : overrides.total_pending_satang,
    entry_count: 3,
    bank_name: 'กสิกรไทย',
    bank_account_number: '1234567890',
    bank_account_holder_name: 'สมชาย ใจดี',
    /*
     * The SERVER's answer to "can this payee be paid at all". Default true so
     * the rows here are ordinary payable people; the tests about the refusals
     * pass false explicitly. A default of false would move every row out of the
     * step the test is looking at.
     */
    payout_details_complete: true,
    reserved_satang: 0,
    available_satang:
      overrides.available_satang === undefined
        ? (overrides.total_pending_satang === undefined ? TWO_THOUSAND_BAHT : overrides.total_pending_satang)
        : overrides.available_satang,
    avatar_url: null,
    cert_tier: null,
    ...overrides,
  }
}

/** A REQUEST row, as /commission-withdrawals returns one. */
function makeRequest(over: Record<string, unknown> = {}) {
  return {
    id: 501,
    agent_id: 1,
    agent_name: 'สมชาย',
    source: 'company_payout',
    source_label: 'บริษัทตั้งจ่าย',
    amount_satang: TWO_THOUSAND_BAHT,
    status: 'approved',
    status_label: 'อนุมัติแล้ว รอโอน',
    rejection_reason: null,
    decided_at: null,
    decided_by: null,
    transferred_at: null,
    transfer_reference: null,
    bank_name: 'กสิกรไทย',
    bank_account_number_masked: '••••7890',
    bank_account_holder_name: 'สมชาย ใจดี',
    item_count: 2,
    created_at: '2026-09-15T00:00:00Z',
    ...over,
  }
}

type Row = ReturnType<typeof makeRow>
type Request = ReturnType<typeof makeRequest>

interface World {
  people?: Row[]
  review?: Request[]
  transfer?: Request[]
  totals?: Record<string, { count: number; satang: number }>
  /** Simulate the band's own endpoint failing while the lists still load. */
  totalsFail?: boolean
}

/**
 * Three endpoints, three meanings, answered separately on purpose.
 *
 * A double that returned one list for all of them could not tell a step that
 * lists PEOPLE from one that lists REQUESTS — which is the distinction most of
 * this file is about.
 */
function wireApi(world: World) {
  get.mockImplementation((path: string) => {
    const p = String(path)

    if (p.startsWith('/agent-commission-summary')) {
      return Promise.resolve({ data: world.people ?? [], computed_at: '2026-09-16T00:00:00Z' })
    }
    if (p.startsWith('/commission-withdrawals/summary')) {
      return world.totalsFail
        ? Promise.reject(new FakeApiError(500, {}))
        : Promise.resolve({ data: world.totals ?? {} })
    }
    if (p.startsWith('/commission-withdrawal-settings')) {
      return Promise.resolve({ min_withdrawal_satang: 50_000 })
    }
    if (p.startsWith('/commission-withdrawals?status=pending_review')) {
      return Promise.resolve({ data: world.review ?? [] })
    }
    if (p.startsWith('/commission-withdrawals?status=approved')) {
      return Promise.resolve({ data: world.transfer ?? [] })
    }
    if (p.startsWith('/commission-ledger') || p.startsWith('/users/')) {
      return Promise.resolve({ data: [] })
    }

    throw new Error(`unexpected GET ${p}`)
  })
}

/**
 * @param open which step to land on. The screen OPENS on ① (the work), so every
 *             other step is reached the way a reader reaches it: by pressing a
 *             step on the band, or — for ติดปัญหา, which deliberately has no
 *             step — the button in the amber bar.
 */
async function mountView(world: World, open: 'payable' | 'blocked' | 'review' | 'transfer' = 'payable') {
  wireApi(world)
  const wrapper = mount(CommissionPayoutsView)
  await flushPromises()

  if (open === 'blocked') {
    await wrapper.get('[data-test="payout-blocked-open"]').trigger('click')
    await flushPromises()
  } else if (open !== 'payable') {
    await wrapper.get(`[data-test="payout-step-${open}"]`).trigger('click')
    await flushPromises()
  }

  return wrapper
}

/**
 * "A zero baht figure appears somewhere in this text."
 *
 * A plain `toContain('0 บาท')` is USELESS here: "1,500 บาท" ends in "0 บาท", so
 * the negative assertion passes for the wrong reason on every row that has
 * money on it. The leading boundary requires the zero to be the WHOLE amount.
 */
const STANDALONE_ZERO_BAHT = /(^|[^\d])0 บาท/

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  post.mockResolvedValue({ data: [] })
  routeQuery.value = {}
})

describe('§3.7 / F-10 — a bucket nobody measured is never printed as a figure', () => {
  it('prints the measured bucket as money', async () => {
    const wrapper = await mountView({ people: [makeRow()] })

    expect(wrapper.text()).toContain('2,000 บาท')
    expect(wrapper.text()).not.toContain('ไม่ได้แสดง')
  })

  it('prints a REAL zero as 0 บาท — a measured nothing is still a number', async () => {
    /*
     * The case the null contract must not swallow. Nobody is payable, the
     * bucket WAS measured, and it came out empty: step ① has to say so in
     * money, because "we can raise nothing today" is a fact worth stating.
     */
    const wrapper = await mountView({
      people: [makeRow({ total_pending_satang: 0, available_satang: 0 })],
    })

    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).toMatch(STANDALONE_ZERO_BAHT)
    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).not.toContain('ไม่ได้แสดง')
  })

  it('renders ไม่ได้แสดง on step ① when the pending bucket came back null', async () => {
    const wrapper = await mountView({
      people: [makeRow({ total_pending_satang: null, available_satang: null })],
    })

    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).toContain('ไม่ได้แสดง')
    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).not.toMatch(STANDALONE_ZERO_BAHT)
  })

  it('refuses to print zeros on the band when the band’s own figures could not be read', async () => {
    /*
     * THE ONE THAT MATTERS about the band. "รอฝ่ายบัญชีโอน 0 บาท" is a
     * statement that nothing is waiting, on the screen where somebody decides
     * whether a payout round is finished. A failed read must say so.
     */
    const wrapper = await mountView({ people: [makeRow()], totalsFail: true })

    expect(wrapper.get('[data-test="payout-step-amount-review"]').text()).toContain('ไม่ได้แสดง')
    expect(wrapper.get('[data-test="payout-step-amount-transfer"]').text()).not.toMatch(STANDALONE_ZERO_BAHT)
    // ...and the lists underneath still work.
    expect(wrapper.find('[data-test="payout-band-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payout-row-1"]').exists()).toBe(true)
  })

  it('the header KPI refuses to total an unmeasured column rather than under-reporting it', async () => {
    const wrapper = await mountView({
      people: [
        makeRow({ agent_id: 1, total_pending_satang: null, available_satang: null }),
        makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: TWO_THOUSAND_BAHT }),
      ],
    })

    const kpis = wrapper.findComponent({ name: 'HeroHeader' }).props('kpis') as Array<{ label: string; value: string }>

    // Not 2,000 (a partial sum over the rows that happened to have a value),
    // and not 0. Stated as unmeasured.
    expect(kpis[0]?.value).toContain('ไม่ได้แสดง')
  })
})

/**
 * ══════════════════════════════════════════════════════════════════════════
 * THE MERGE. One band, one errand, and a row that changes meaning at step ②.
 * ══════════════════════════════════════════════════════════════════════════
 */
describe('one screen, one errand', () => {
  const READY = makeRow({ agent_id: 1, agent_name: 'พร้อมจ่าย' })
  const NO_BANK = makeRow({
    agent_id: 3,
    agent_name: 'ไม่มีบัญชี',
    payout_details_complete: false,
    bank_account_number: null,
  })
  const ASKED = makeRequest({ id: 601, agent_name: 'ขอเอง', status: 'pending_review', source: 'agent_request', source_label: 'ตัวแทนขอเอง' })
  const WAITING = makeRequest({ id: 701, agent_name: 'รอโอน' })

  const WORLD: World = {
    people: [READY, NO_BANK],
    review: [ASKED],
    transfer: [WAITING],
    totals: {
      pending_review: { count: 1, satang: 140_000 },
      approved: { count: 1, satang: TWO_THOUSAND_BAHT },
      transferred: { count: 4, satang: 1_485_000 },
    },
  }

  it('opens on the work step', async () => {
    const wrapper = await mountView(WORLD)

    expect(wrapper.get('[data-test="payout-group-heading"]').text()).toContain('ตั้งจ่ายได้เลย')
    expect(wrapper.find('[data-test="payout-row-1"]').exists()).toBe(true)
  })

  it('lists PEOPLE in step ① and REQUESTS in steps ② and ③', async () => {
    // THE ONE THAT MATTERS about the merge. The two halves of the old pair
    // listed different objects, and joining them did not change that.
    const work = await mountView(WORLD, 'payable')
    expect(work.get('[data-test="payout-unit-badge"]').text()).toContain('คน')
    expect(work.find('[data-test="payout-row-1"]').exists()).toBe(true)

    const review = await mountView(WORLD, 'review')
    expect(review.get('[data-test="payout-unit-badge"]').text()).toContain('ใบคำขอ')
    expect(review.find('[data-test="payout-request-601"]').exists()).toBe(true)

    const transfer = await mountView(WORLD, 'transfer')
    expect(transfer.get('[data-test="payout-unit-badge"]').text()).toContain('ใบคำขอ')
    expect(transfer.find('[data-test="payout-request-701"]').exists()).toBe(true)
  })

  it('takes the two request steps’ figures from the server, not from the loaded page', async () => {
    /*
     * The queue loads one page at a time. A band counting the rows it happens
     * to have would describe twenty rows and be read as describing the step —
     * invisible until the day somebody has twenty-one approved payouts.
     */
    const wrapper = await mountView({
      ...WORLD,
      transfer: [],
      totals: { ...WORLD.totals, approved: { count: 9, satang: 900_000 } },
    })

    expect(wrapper.get('[data-test="payout-step-amount-transfer"]').text()).toContain('9,000 บาท')
  })

  it('sends โอนแล้ว to the report instead of listing it as a fourth step', async () => {
    // Nothing on it can be acted on. Rendering it as a step would put a list
    // with no actions back in the middle of a screen that exists to be acted
    // on — the dead end this merge removed.
    const wrapper = await mountView(WORLD)

    const tile = wrapper.get('[data-test="payout-transferred-tile"]')

    expect(tile.text()).toContain('14,850 บาท')
    expect(tile.text()).toContain('ดูรายงานการจ่าย')
    expect(wrapper.find('[data-test="payout-step-transferred"]').exists()).toBe(false)
  })

  it('fetches a request list the first time its step is opened, and not again on the way back', async () => {
    const wrapper = await mountView(WORLD, 'transfer')

    const approvedCalls = () => get.mock.calls.filter(([p]) => String(p).includes('status=approved')).length
    expect(approvedCalls()).toBe(1)

    await wrapper.get('[data-test="payout-step-payable"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="payout-step-transfer"]').trigger('click')
    await flushPromises()

    expect(approvedCalls()).toBe(1)
  })

  it('opens on the review step when a link asks for the queue', async () => {
    /*
     * ?view=queue used to mean "the รอบจ่าย page". The notification that says
     * "มีคำขอเบิกรออนุมัติ" still carries it, and it has to land where the
     * approve button now is.
     */
    routeQuery.value = { view: 'queue' }

    const wrapper = await mountView(WORLD)

    expect(wrapper.get('[data-test="payout-group-heading"]').text()).toContain('รอคุณตรวจสอบ')
  })

  it('says nothing arrives in the review step by itself', async () => {
    // Carried over from the deleted queue spec: the one fact a number cannot
    // carry is that this list fills itself only when an agent presses something.
    const wrapper = await mountView({ ...WORLD, review: [] }, 'review')

    expect(wrapper.get('[data-test="payout-group-empty"]').text()).toContain('ตัวแทนกดขอเบิกเอง')
  })

  it('keeps the withdrawal minimum where the refusal it explains happens', async () => {
    // Read-only, and only on the step where "why was that request refused" is
    // a question somebody asks. It is edited on ตั้งค่าค่าแนะนำ.
    const review = await mountView(WORLD, 'review')
    expect(review.get('[data-test="withdrawal-minimum-readout"]').text()).toContain('500 บาท')

    const work = await mountView(WORLD, 'payable')
    expect(work.find('[data-test="withdrawal-minimum-readout"]').exists()).toBe(false)
  })
})

describe('กลุ่มติดปัญหา — the amber bar', () => {
  const NO_BANK = makeRow({
    agent_id: 3,
    agent_name: 'ไม่มีบัญชี',
    payout_details_complete: false,
    bank_account_number: null,
  })

  it('says how many and how much, and names them', async () => {
    const wrapper = await mountView({ people: [makeRow({ agent_id: 1 }), NO_BANK] })

    const bar = wrapper.get('[data-test="payout-blocked-alert"]').text()

    expect(bar).toContain('1 รายมีค่าคอม แต่ตั้งจ่ายไม่ได้')
    expect(bar).toContain('2,000 บาท')
    expect(bar).toContain('ไม่มีบัญชี')
  })

  it('is not there at all on a day with no problems', async () => {
    // A permanent warning bar teaches people to stop reading warning bars.
    const wrapper = await mountView({ people: [makeRow({ agent_id: 1 })] })

    expect(wrapper.find('[data-test="payout-blocked-alert"]').exists()).toBe(false)
  })

  it('is not a step on the band, because these people never entered the conveyor', async () => {
    const wrapper = await mountView({ people: [makeRow({ agent_id: 1 }), NO_BANK] })

    expect(wrapper.find('[data-test="payout-step-blocked"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payout-blocked-open"]').exists()).toBe(true)
  })

  it('opens the list from ดูรายชื่อ, with the reason on each row', async () => {
    const wrapper = await mountView({ people: [makeRow({ agent_id: 1 }), NO_BANK] })

    await wrapper.get('[data-test="payout-blocked-open"]').trigger('click')

    expect(wrapper.get('[data-test="payout-group-heading"]').text()).toContain('ติดปัญหา')
    expect(wrapper.get('[data-test="payout-blocked-3"]').text()).toContain('บัญชีธนาคาร')
    expect(wrapper.find('[data-test="payout-fix-bank-3"]').exists()).toBe(true)
  })

  it('offers the company-settings link only when the company seat is one of the blocked', async () => {
    /*
     * The one repair that cannot be made from this screen: the seat is not a
     * person and PUT /users/{id} refuses it. Offering that link for a blocked
     * AGENT would send an admin to a settings page that cannot fix them.
     */
    const agentOnly = await mountView({ people: [NO_BANK] })
    expect(agentOnly.find('[data-test="payout-blocked-company-link"]').exists()).toBe(false)

    const withCompany = await mountView({
      people: [makeRow({
        agent_id: 90,
        agent_name: 'Thai Life insurance',
        is_company_share: true,
        payout_details_complete: false,
        bank_account_number: null,
      })],
    })
    expect(withCompany.find('[data-test="payout-blocked-company-link"]').exists()).toBe(true)
  })
})

describe('step ① — raising payouts', () => {
  it('adds the row to a running total that is printed before the press', async () => {
    const wrapper = await mountView({
      people: [
        makeRow({ agent_id: 1, total_pending_satang: TWO_THOUSAND_BAHT }),
        makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT }),
      ],
    })

    await wrapper.get('[data-test="payout-select-1"]').trigger('change')
    await wrapper.get('[data-test="payout-select-2"]').trigger('change')

    // 2,000 + 1,500. The figure an admin checks against what accounting is
    // about to be asked to transfer — so it is theirs to see BEFORE pressing.
    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('3,500 บาท')
  })

  it('shows the bar with an instruction, not a total, before anything is ticked', async () => {
    const wrapper = await mountView({ people: [makeRow()] })

    expect(wrapper.find('[data-test="payout-selection-bar"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="payout-selection-empty"]').text()).toContain('คลิกที่แถว')
    expect(wrapper.find('[data-test="payout-selection-total"]').exists()).toBe(false)
  })

  it('does not write on the first press', async () => {
    const wrapper = await mountView({ people: [makeRow({ agent_id: 42 })] })
    await wrapper.get('[data-test="payout-select-42"]').trigger('change')

    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="payout-batch-confirm"]').exists()).toBe(true)
  })

  it('says plainly that the press settles nothing and tells nobody', async () => {
    const wrapper = await mountView({ people: [makeRow({ agent_id: 42 })] })
    await wrapper.get('[data-test="payout-select-42"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')

    const confirm = wrapper.get('[data-test="payout-batch-confirm"]').text()

    expect(confirm).toContain('2,000 บาท')
    expect(confirm).toContain('ยังไม่ปิดรายการค่าคอม')
    expect(confirm).toContain('ขั้นที่ 3')
  })

  it('sends every ticked payee in ONE request, each with the total that was shown', async () => {
    /*
     * A loop over the single-payee endpoint fails on the third of five and
     * leaves an admin with two payouts raised, three not, and no way to tell
     * which.
     */
    const wrapper = await mountView({
      people: [
        makeRow({ agent_id: 42, total_pending_satang: TWO_THOUSAND_BAHT }),
        makeRow({ agent_id: 43, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT }),
      ],
    })
    await wrapper.get('[data-test="payout-select-42"]').trigger('change')
    await wrapper.get('[data-test="payout-select-43"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/commission-withdrawals/payout-batch', {
      payees: [
        { agent_id: 42, expected_total_satang: TWO_THOUSAND_BAHT },
        { agent_id: 43, expected_total_satang: FIFTEEN_HUNDRED_BAHT },
      ],
    })
  })

  it('sends the AVAILABLE figure, which is the one the server checks', async () => {
    /*
     * The root of the September loop. This sent total_pending_satang, and the
     * server compares against availableSatang — identical only until the first
     * payout exists, and permanently different afterwards.
     */
    const wrapper = await mountView({
      people: [makeRow({
        agent_id: 42,
        total_pending_satang: TWO_THOUSAND_BAHT,
        reserved_satang: FIFTEEN_HUNDRED_BAHT,
        available_satang: 50_000,
      })],
    })

    await wrapper.get('[data-test="payout-select-42"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/payout-batch', {
      payees: [{ agent_id: 42, expected_total_satang: 50_000 }],
    })
  })

  it('explains the gap when only PART of the balance is in flight', async () => {
    const wrapper = await mountView({
      people: [makeRow({
        agent_id: 42,
        total_pending_satang: TWO_THOUSAND_BAHT,
        reserved_satang: FIFTEEN_HUNDRED_BAHT,
        available_satang: 50_000,
      })],
    })

    const note = wrapper.get('[data-test="payout-reserved-42"]').text()

    expect(note).toContain('ตั้งจ่ายแล้วรอโอน')
    expect(note).toContain('1,500')
    expect(note).toContain('500')
  })

  it('drops a fully-reserved payee out of step ① entirely', async () => {
    // A tickable row whose press can only be refused is worse than a disabled
    // one: the refusal arrives after the confirmation.
    const wrapper = await mountView({
      people: [makeRow({
        agent_id: 42,
        total_pending_satang: TWO_THOUSAND_BAHT,
        reserved_satang: TWO_THOUSAND_BAHT,
        available_satang: 0,
      })],
    })

    expect(wrapper.find('[data-test="payout-row-42"]').exists()).toBe(false)
  })

  it("shows the server's own refusal, because it is the one that says what to do", async () => {
    const wrapper = await mountView({ people: [makeRow({ agent_id: 42 })] })
    await wrapper.get('[data-test="payout-select-42"]').trigger('change')
    post.mockRejectedValueOnce(new FakeApiError(422, {
      errors: { 'payees.0.expected_total_satang': ['ยอดค้างจ่ายของคนนี้เปลี่ยนไปแล้ว — กรุณารีเฟรชหน้าจอแล้วลองใหม่'] },
    }))

    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="payout-batch-error"]').text()).toContain('กรุณารีเฟรชหน้าจอ')
    // The selection survives a refusal: nothing was raised, so the admin's work
    // of choosing who to pay must not have to be redone.
    expect(wrapper.find('[data-test="payout-batch-confirm"]').exists()).toBe(true)
  })

  it('offers the bank file here and nowhere else', async () => {
    /*
     * It is a list of PEOPLE to pay, with full account numbers, made to be
     * acted on. It has no place under a list of documents already raised — and
     * it is not the report's export, which is one row per request and masked.
     */
    const work = await mountView({ people: [makeRow()] })
    expect(work.find('[data-test="payout-export"]').exists()).toBe(true)

    const transfer = await mountView({ people: [makeRow()], transfer: [makeRequest()] }, 'transfer')
    expect(transfer.find('[data-test="payout-export"]').exists()).toBe(false)
  })
})

describe('step ② — approving what agents asked for', () => {
  const ASKED = makeRequest({
    id: 601,
    agent_name: 'ขอเอง',
    status: 'pending_review',
    status_label: 'รอตรวจสอบ',
    source: 'agent_request',
    source_label: 'ตัวแทนขอเอง',
  })

  it('says whose decision each row was', async () => {
    /*
     * Carried over from the deleted queue spec. In ขั้นที่ 3 an agent's
     * approved request and a payout the company raised sit side by side, and
     * they answer to different people.
     */
    const wrapper = await mountView({ review: [ASKED] }, 'review')

    expect(wrapper.get('[data-test="payout-source-601"]').text()).toContain('ตัวแทนขอเอง')
  })

  it('approves in one press', async () => {
    const wrapper = await mountView({ review: [ASKED] }, 'review')

    await wrapper.get('[data-test="payout-approve-601"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/601/approve', {})
  })

  it('asks for the refusal reason in the row, not in a browser prompt', async () => {
    /*
     * THE ONE THAT MATTERS about this step's redesign. The reason is required
     * by the server and shown to the agent verbatim, which makes it a piece of
     * writing — window.prompt gives one line, no wrapping, no editing, and
     * hides the request it is about.
     */
    const wrapper = await mountView({ review: [ASKED] }, 'review')

    await wrapper.get('[data-test="payout-reject-601"]').trigger('click')

    expect(wrapper.find('[data-test="payout-reject-panel-601"]').exists()).toBe(true)
    expect(post).not.toHaveBeenCalled()
  })

  it('will not send an empty reason', async () => {
    // The server requires one and the agent reads it. A blank would be a 422
    // arriving after the press, explaining nothing.
    const wrapper = await mountView({ review: [ASKED] }, 'review')

    await wrapper.get('[data-test="payout-reject-601"]').trigger('click')
    await wrapper.get('[data-test="payout-reject-submit-601"]').trigger('click')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="payout-error"]').text()).toContain('เหตุผล')
  })

  it('sends the reason verbatim', async () => {
    const wrapper = await mountView({ review: [ASKED] }, 'review')

    await wrapper.get('[data-test="payout-reject-601"]').trigger('click')
    await wrapper.get('[data-test="payout-reject-reason-601"]').setValue('ยอดไม่ตรงกับที่ตรวจสอบ')
    await wrapper.get('[data-test="payout-reject-submit-601"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/601/reject', {
      rejection_reason: 'ยอดไม่ตรงกับที่ตรวจสอบ',
    })
  })

  it('offers no ticking at all — this step is decided one at a time', async () => {
    // A tick means "do this step's action to these rows", and "อนุมัติ" is a
    // judgement about one request. Batching it would be a batch of judgements
    // nobody made individually.
    const wrapper = await mountView({ review: [ASKED] }, 'review')

    expect(wrapper.find('[data-test="payout-request-select-601"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payout-selection-bar"]').exists()).toBe(false)
  })
})

describe('step ③ — recording what accounting transferred', () => {
  const A = makeRequest({ id: 701, agent_name: 'รายแรก', amount_satang: TWO_THOUSAND_BAHT })
  const B = makeRequest({ id: 702, agent_name: 'รายสอง', amount_satang: FIFTEEN_HUNDRED_BAHT })

  it('takes a whole round in one press', async () => {
    /*
     * THE ONE THAT MATTERS about this step. Accounting transfers in rounds and
     * reports back in batches; this used to be ten presses and ten browser
     * prompts, each asking for the reference the admin had just typed.
     */
    const wrapper = await mountView({ transfer: [A, B] }, 'transfer')

    await wrapper.get('[data-test="payout-request-select-701"]').trigger('change')
    await wrapper.get('[data-test="payout-request-select-702"]').trigger('change')
    await wrapper.get('[data-test="payout-transfer-reference"]').setValue('TRF-690916-01')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/commission-withdrawals/mark-transferred-batch', {
      withdrawal_request_ids: [701, 702],
      transfer_reference: 'TRF-690916-01',
    })
  })

  it('totals the selection in money before the press', async () => {
    const wrapper = await mountView({ transfer: [A, B] }, 'transfer')

    await wrapper.get('[data-test="payout-request-select-701"]').trigger('change')
    await wrapper.get('[data-test="payout-request-select-702"]').trigger('change')

    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('3,500 บาท')
  })

  it('keeps the reference optional', async () => {
    // Not every transfer produces one worth recording, and a required field
    // here only invites made-up values that look like evidence.
    const wrapper = await mountView({ transfer: [A] }, 'transfer')

    await wrapper.get('[data-test="payout-request-select-701"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/mark-transferred-batch', {
      withdrawal_request_ids: [701],
      transfer_reference: null,
    })
  })

  it('warns that THIS press is the one that closes rows and emails people', async () => {
    /*
     * The two presses on this screen are not the same act, and the difference
     * is invisible from the button alone. Step ① raises something that settles
     * nothing; this one closes commission ledger rows and sends the
     * "เงินเข้าแล้ว" email.
     */
    const wrapper = await mountView({ transfer: [A] }, 'transfer')

    await wrapper.get('[data-test="payout-request-select-701"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')

    const confirm = wrapper.get('[data-test="payout-batch-confirm"]').text()

    expect(confirm).toContain('ปิดรายการค่าคอมจริง')
    expect(confirm).toContain('ส่งอีเมลแจ้งตัวแทน')
  })

  it('does not write on the first press', async () => {
    const wrapper = await mountView({ transfer: [A] }, 'transfer')

    await wrapper.get('[data-test="payout-request-select-701"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')

    expect(post).not.toHaveBeenCalled()
  })

  it('offers a single-row shortcut that goes through the same confirmation', async () => {
    const wrapper = await mountView({ transfer: [A, B] }, 'transfer')

    await wrapper.get('[data-test="payout-transfer-one-702"]').trigger('click')

    expect(post).not.toHaveBeenCalled()
    // REPLACES the selection rather than adding to it: pressing it on one row
    // while others are ticked has to mean what it says.
    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('1,500 บาท')
  })
})

describe('the selection never crosses a step', () => {
  it('is dropped when the step changes', async () => {
    /*
     * A tick is an agreement about a named row and a named amount, and the VERB
     * differs per step — "จะจ่ายคนนี้" in ①, "คนนี้โอนแล้ว" in ③. Carried
     * across, it would be confirmed from a screen showing different names, a
     * different total and a different action.
     */
    const wrapper = await mountView({
      people: [makeRow({ agent_id: 1 })],
      transfer: [makeRequest({ id: 701 })],
    })

    await wrapper.get('[data-test="payout-select-1"]').trigger('change')
    expect(wrapper.find('[data-test="payout-selection-total"]').exists()).toBe(true)

    await wrapper.get('[data-test="payout-step-transfer"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payout-selection-total"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payout-selection-empty"]').exists()).toBe(true)
  })

  it('keeps the two id spaces apart', async () => {
    /*
     * Agent 701 and withdrawal request 701 are different things with the same
     * number. One Set holding both would silently mark the wrong row the first
     * time they were both on screen in a session.
     */
    const wrapper = await mountView({
      people: [makeRow({ agent_id: 701, agent_name: 'คนเลข 701' })],
      transfer: [makeRequest({ id: 701, agent_name: 'ใบเลข 701' })],
    })

    await wrapper.get('[data-test="payout-select-701"]').trigger('change')
    await wrapper.get('[data-test="payout-step-transfer"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payout-selection-empty"]').exists()).toBe(true)
  })
})

/**
 * 2026-09-15 (ครั้งที่สอง) — THE COMPANY'S OWN SHARE IS A PAYEE.
 *
 * It used to be lifted out of the list into a green box that explained why it
 * could never be paid. The owner decided the company's share is transferred out
 * like anybody else's ("ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย"), so it is a row.
 *
 * What survives from the old rule is the LABEL. An admin reconciling a bank file
 * still has to be able to tell at a glance which line is not a person — and that
 * row's bank details live on ตั้งค่าค่าแนะนำ, because PUT /users/{id} refuses the
 * seat and always will.
 */
describe("the company's own share", () => {
  const COMPANY = { agent_id: 90, agent_name: 'Thai Life insurance', is_company_share: true }

  it('sits in the table, labelled, rather than in a box above it', async () => {
    const wrapper = await mountView({ people: [makeRow({ agent_id: 1 }), makeRow(COMPANY)] })

    const row = wrapper.get('[data-test="company-share-row-90"]')

    expect(row.text()).toContain('ส่วนของบริษัท')
    // The sentence that used to be the whole point of the box is gone: this
    // money DOES move now.
    expect(wrapper.text()).not.toContain('ไม่ต้องโอน')
  })

  it('can be ticked and paid like anybody else', async () => {
    const wrapper = await mountView({ people: [makeRow(COMPANY)] })

    await wrapper.get('[data-test="payout-select-90"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/payout-batch', {
      payees: [{ agent_id: 90, expected_total_satang: TWO_THOUSAND_BAHT }],
    })
  })

  it('points at the settings screen for its account, not at an inline form', async () => {
    const wrapper = await mountView({
      people: [makeRow({ ...COMPANY, payout_details_complete: false, bank_account_number: null })],
    }, 'blocked')

    expect(wrapper.find('[data-test="payout-company-bank-link"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payout-bank-edit-90"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payout-blocked-90"]').text()).toContain('บัญชีรับเงินของบริษัท')
  })
})

describe('finding out that you have to select', () => {
  it('labels the checkbox column instead of leaving it bare', async () => {
    const wrapper = await mountView({ people: [makeRow()] })

    expect(wrapper.get('thead').text()).toContain('เลือก')
  })

  it('selects when the ROW is clicked, not only the 16px checkbox', async () => {
    const wrapper = await mountView({ people: [makeRow({ agent_id: 1 })] })

    await wrapper.get('[data-test="payout-row-1"]').trigger('click')

    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('2,000 บาท')
  })

  it('does not select when a control INSIDE the row is clicked', async () => {
    /*
     * Without the stopped bubble, opening a drill-down or editing a bank
     * account would silently tick that person for payment — a selection nobody
     * made, agreed to at the confirmation by somebody reading the total rather
     * than the names.
     */
    const wrapper = await mountView({ people: [makeRow({ agent_id: 1 })] })

    await wrapper.get('[data-test="payout-detail-1"]').trigger('click')

    expect(wrapper.find('[data-test="payout-selection-total"]').exists()).toBe(false)
  })

  it('offers a whole-list shortcut from the bar', async () => {
    const wrapper = await mountView({
      people: [
        makeRow({ agent_id: 1 }),
        makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT, available_satang: FIFTEEN_HUNDRED_BAHT }),
      ],
    })

    await wrapper.get('[data-test="payout-select-all-shortcut"]').trigger('click')

    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('3,500 บาท')
  })

  it('REPLACES the selection when ตั้งจ่ายคนนี้ is pressed on another row', async () => {
    /*
     * Pressing it on one row while others are ticked has to mean what it says.
     * Adding would open a confirmation naming a total the presser never chose —
     * and the confirmation is the last line of defence before rows BR-4 makes
     * permanent.
     */
    const wrapper = await mountView({
      people: [
        makeRow({ agent_id: 1 }),
        makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT, available_satang: FIFTEEN_HUNDRED_BAHT }),
      ],
    })

    await wrapper.get('[data-test="payout-select-1"]').trigger('change')
    await wrapper.get('[data-test="payout-pay-one-2"]').trigger('click')

    expect(wrapper.get('[data-test="payout-batch-confirm"]').text()).toContain('1 คน')
    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('1,500 บาท')
  })
})
