/**
 * ReferralPipelineManagementView — TASK-176 §4: the admin's
 * "รับชำระเงินแล้ว" door, and the rule that there is only ever one of it.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * Two buttons on this board can end in a BR-4 commission ledger row:
 * the blue advance (`POST /referrals/{id}/advance`, which fires
 * `recordForReferral()` on the way past Complete Payment) and the new
 * confirm (`POST /orders/{id}/confirm`, which additionally marks the
 * order paid, stamps `verified_by_user_id`, and closes the customer's
 * public /pay/{token} page). TASK-176 §4.1 is that they are NEVER on the
 * same card at the same time — an admin must not have to work out which
 * of two buttons books the money.
 *
 * That rule lives in a single `v-if` / `v-else-if` chain in the template,
 * so it holds by construction. This file is what notices when somebody
 * "tidies" that chain into two independent `v-if`s, or adds a third
 * caller. Every door assertion below therefore checks BOTH buttons — the
 * present one AND the absent one. A test that only asserted the confirm
 * button appears would still pass with both buttons showing, which is
 * exactly the defect.
 *
 * ── THE OTHER THING GUARDED HERE (ADR-026) ──
 *
 * Whether a card is "at or past payment" is a property of THAT
 * REFERRAL'S OWN journey, not of a global stage order. A hardcoded copy
 * of §4.3's five medical stages has been introduced into this codebase
 * and removed again three times since ADR-026. The pair of tests named
 * "…same stage, different journey…" is the trap for a fourth: two
 * referrals sitting at the SAME stage with the SAME order get DIFFERENT
 * doors purely because their templates differ. Any implementation that
 * reasons from a fixed stage list gets one of them wrong.
 *
 * The API is mocked at `@/api/client`. Authorization, tenant isolation
 * and the 422 for confirming too early are enforced and tested
 * server-side (BR-6, ReferralOrderTest) — this file is about which
 * affordance the board offers, and what it sends when clicked.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()

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
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: FakeApiError,
}))

// The view reads `route.query.open` (TASK-048 cross-link) and pushes to the
// Clients page; neither is what this file is about.
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
}))

import ReferralPipelineManagementView from '../ReferralPipelineManagementView.vue'
import { PAYMENT_STAGE_KEY, type PipelineStageRef } from '@/utils/pipelineStages'

// ── Fixtures ────────────────────────────────────────────────────────────
// Stage refs exactly as ReferralResource sends them: `{ key, label }` with
// an ENGLISH label (the Thai wording is the UI's, in pipelineStages.ts).
const REGISTERED: PipelineStageRef = { key: 'complete_registered', label: 'Complete Registered' }
const WAITING: PipelineStageRef = { key: 'waiting_appointment', label: 'Waiting Appointment' }
const MEETING: PipelineStageRef = { key: 'finish_1st_doctor_meeting', label: 'Finish 1st Doctor Meeting' }
const PAYMENT: PipelineStageRef = { key: PAYMENT_STAGE_KEY, label: 'Complete Payment' }
const ONGOING: PipelineStageRef = { key: 'ongoing_next_meeting', label: 'Ongoing Next Meeting' }

/** ADR-026's two seeded templates. */
const MEDICAL_JOURNEY = [REGISTERED, WAITING, MEETING, PAYMENT, ONGOING]
const DIRECT_SALE_JOURNEY = [REGISTERED, PAYMENT]

type OrderStatus = 'pending' | 'awaiting_verification' | 'paid' | 'cancelled'

interface OrderOverrides {
  id?: number
  order_number?: string
  status?: OrderStatus
  amount_satang?: number
  has_slip?: boolean
  paid_at?: string | null
  verified_by?: { id: number; name: string } | null
}

const STATUS_LABELS: Record<OrderStatus, string> = {
  pending: 'รอชำระเงิน',
  awaiting_verification: 'รอตรวจสอบสลิป',
  paid: 'ชำระเงินแล้ว',
  cancelled: 'ยกเลิก',
}

/** BR-3 — 8,900.00 THB as the integer satang the API actually sends. */
const EIGHT_NINE_HUNDRED_SATANG = 890000

function makeOrder(overrides: OrderOverrides = {}) {
  const status: OrderStatus = overrides.status ?? 'pending'
  return {
    id: overrides.id ?? 77,
    order_number: overrides.order_number ?? 'ORD-TEST01',
    status,
    status_label: STATUS_LABELS[status],
    amount_satang: overrides.amount_satang ?? EIGHT_NINE_HUNDRED_SATANG,
    has_slip: overrides.has_slip ?? false,
    paid_at: overrides.paid_at ?? null,
    verified_by: overrides.verified_by ?? null,
  }
}

interface ReferralOverrides {
  id?: number
  name?: string
  journey?: PipelineStageRef[]
  current?: PipelineStageRef
  next?: PipelineStageRef | null
  /** Omit the key entirely to model "the backend did not load orders". */
  order?: ReturnType<typeof makeOrder> | null
}

let nextId = 1

function makeReferral(overrides: ReferralOverrides = {}) {
  const journey = overrides.journey ?? MEDICAL_JOURNEY
  const current = overrides.current ?? MEETING
  const id = overrides.id ?? nextId++
  const referral: Record<string, unknown> = {
    id,
    client: { id: 100 + id, name: overrides.name ?? `ลูกค้า ${id}`, phone: '0800000000' },
    agent: { id: 9, name: 'ตัวแทน ก' },
    product: { id: 5, name: 'แพ็กเกจสุขภาพ', price_satang: EIGHT_NINE_HUNDRED_SATANG },
    branch: 'สีลม',
    current_stage: current,
    meeting_number: null,
    pipeline: {
      stages: journey,
      next_stage:
        overrides.next !== undefined
          ? overrides.next
          : (journey[journey.indexOf(current) + 1] ?? null),
    },
    submitted_at: '2026-08-01T03:00:00.000000Z',
  }
  // `order` is OPTIONAL on the wire, not merely nullable: absent when the
  // backend did not eager-load orders. Only set the key when asked to.
  if ('order' in overrides) referral.order = overrides.order
  return referral
}

async function mountBoard(referrals: Record<string, unknown>[]) {
  get.mockImplementation((path: string) => {
    if (path === '/referrals') return Promise.resolve({ data: referrals })
    if (String(path).endsWith('/stage-logs')) return Promise.resolve({ data: [] })
    return Promise.reject(new FakeApiError(404, null))
  })

  const wrapper = mount(ReferralPipelineManagementView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div />' },
        LoadingSkeleton: true,
        EmptyState: true,
        Icon: true,
        // ConfirmDialog is deliberately NOT stubbed — §4.2's wording and the
        // "nothing is posted until you confirm" rule are the subject here.
      },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountBoard>>

/**
 * The Kanban card for a client, found wherever its column happens to be.
 *
 * Selected by `data-test`, NOT by `[draggable="true"]`: since the drag
 * ruling (2026-08-13) `draggable` is exactly the thing under test on
 * these cards, and a locator that reads it would quietly stop finding
 * the cards it is supposed to be asserting about.
 */
function card(wrapper: Wrapper, clientName: string) {
  const found = wrapper.findAll('[data-test="referral-card"]').find((c) => c.text().includes(clientName))
  if (!found) throw new Error(`no card on the board for "${clientName}"`)
  return found
}

/** A Kanban column, by the stage key it renders. */
function column(wrapper: Wrapper, stageKey: string) {
  const found = wrapper
    .findAll('[data-test="column"]')
    .find((c) => c.attributes('data-stage-key') === stageKey)
  if (!found) throw new Error(`no column on the board for stage "${stageKey}"`)
  return found
}

/**
 * Which doors this card is offering. Both halves are always read, because
 * §4.1 is a statement about the pair — not about either button alone.
 */
function doors(wrapper: Wrapper, clientName: string) {
  const c = card(wrapper, clientName)
  return {
    confirm: c.find('[data-test="confirm-order"]').exists(),
    advance: c.find('[data-test="advance"]').exists(),
  }
}

function confirmButton(wrapper: Wrapper, clientName: string) {
  return card(wrapper, clientName).get('[data-test="confirm-order"]')
}

/** The ConfirmDialog's own "ยืนยัน" button (not the card's). */
function dialogConfirm(wrapper: Wrapper) {
  const button = wrapper.findAll('button').find((b) => b.text() === 'ยืนยัน')
  if (!button) throw new Error('the ConfirmDialog is not open')
  return button
}

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
  nextId = 1
})

describe('§4.1 — one door, never two', () => {
  it('shows ONLY the confirm button on a card with a live order at the payment gate', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'มานี', order: makeOrder({ status: 'awaiting_verification' }) }),
    ])

    expect(doors(wrapper, 'มานี')).toEqual({ confirm: true, advance: false })
    expect(confirmButton(wrapper, 'มานี').text()).toBe('รับชำระเงินแล้ว')
  })

  it('shows ONLY the advance button on a card with no order (§4.5)', async () => {
    const wrapper = await mountBoard([makeReferral({ name: 'มานะ', order: null })])

    expect(doors(wrapper, 'มานะ')).toEqual({ confirm: false, advance: true })
  })

  it('treats an ABSENT order key exactly like a null one', async () => {
    // No `order` key at all — what a ReferralResource that did not
    // eager-load `orders` sends.
    const wrapper = await mountBoard([makeReferral({ name: 'ปิติ' })])

    expect(doors(wrapper, 'ปิติ')).toEqual({ confirm: false, advance: true })
  })

  it('offers no confirm on a cancelled order, and no confirm on an already-paid one', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'ชูใจ', order: makeOrder({ status: 'cancelled' }) }),
      makeReferral({
        name: 'วีระ',
        current: PAYMENT,
        order: makeOrder({ status: 'paid', paid_at: '2026-08-12T04:00:00.000000Z' }),
      }),
    ])

    expect(doors(wrapper, 'ชูใจ')).toEqual({ confirm: false, advance: true })
    // Already paid: nothing left to confirm, but the journey continues.
    expect(doors(wrapper, 'วีระ')).toEqual({ confirm: false, advance: true })
  })

  it('offers no confirm two stages short of payment, even with a live order', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'สมชาย', current: WAITING, order: makeOrder() }),
    ])

    // next_stage is finish_1st_doctor_meeting — the server would 422 this.
    expect(doors(wrapper, 'สมชาย')).toEqual({ confirm: false, advance: true })
  })

  it('still offers confirm once the referral is already PAST payment (a stale unpaid order)', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'อารี', current: ONGOING, next: ONGOING, order: makeOrder() }),
    ])

    // isAtOrPastPayment() — the bill is still open even though the journey
    // moved on, which is the §2 defect this task exists to close.
    expect(doors(wrapper, 'อารี')).toEqual({ confirm: true, advance: false })
  })

  /*
   * ══════════ THE ADR-026 TRAP ══════════
   * Same stage. Same order. Different journey. Different door.
   * A predicate built from a hardcoded stage list cannot pass both of
   * these, because `complete_registered` is one step from payment on one
   * template and three steps away on the other.
   */
  it('same stage, different journey: a DIRECT-SALE card at complete_registered gets the confirm', async () => {
    const wrapper = await mountBoard([
      makeReferral({
        name: 'ตรงดิ่ง',
        journey: DIRECT_SALE_JOURNEY,
        current: REGISTERED,
        order: makeOrder(),
      }),
    ])

    expect(doors(wrapper, 'ตรงดิ่ง')).toEqual({ confirm: true, advance: false })
  })

  it('same stage, different journey: a MEDICAL card at complete_registered gets the advance', async () => {
    const wrapper = await mountBoard([
      makeReferral({
        name: 'ทางยาว',
        journey: MEDICAL_JOURNEY,
        current: REGISTERED,
        order: makeOrder(),
      }),
    ])

    expect(doors(wrapper, 'ทางยาว')).toEqual({ confirm: false, advance: true })
  })
})

/*
 * ══════════════════════════════════════════════════════════════════════
 * §4.1 follow-up (ag-lead ruling, 2026-08-13) — DRAG IS A DOOR TOO.
 *
 * The button chain above is only two thirds of "one door to the ledger".
 * Every card on this board is also a drag handle, and a drop on the next
 * column calls the SAME `POST /referrals/{id}/advance` — so on a card
 * where the confirm button has replaced the advance button, dragging it
 * would book BR-4 commission while the order stays `pending` and the
 * customer's public /pay/{token} page stays open forever. That is the §2
 * defect, reached by the affordance §4.1 forgot to close.
 *
 * A button and a gesture that both POST to /advance are the same door
 * twice. Both halves are asserted here: the card refuses to be dragged
 * (the affordance), AND the drop handler refuses it anyway (the
 * enforcement) — the same belt-and-braces pattern `advance()` already
 * uses for `next_stage`.
 * ══════════════════════════════════════════════════════════════════════
 */
describe('§4.1 follow-up — drag is a door too', () => {
  it('a card offering the confirm button is not draggable', async () => {
    const wrapper = await mountBoard([makeReferral({ id: 42, name: 'มานี', order: makeOrder({ id: 77 }) })])

    // Sanity: this is a confirm-door card, i.e. the state the ruling is about.
    expect(doors(wrapper, 'มานี')).toEqual({ confirm: true, advance: false })
    expect(card(wrapper, 'มานี').attributes('draggable')).toBe('false')
  })

  it('dropping such a card on its own next column does NOT advance it', async () => {
    const wrapper = await mountBoard([makeReferral({ id: 42, name: 'มานี', order: makeOrder({ id: 77 }) })])

    // A synthetic gesture gets past the `draggable` attribute exactly as a
    // stale card or a non-conforming browser would. The handler is the
    // half that has to hold.
    await card(wrapper, 'มานี').trigger('dragstart')
    await column(wrapper, PAYMENT_STAGE_KEY).trigger('drop')
    await flushPromises()

    // The ledger was not touched by ANY endpoint — not the advance, not
    // the confirm (a drag must not silently do the confirm's job either;
    // that one goes through ConfirmDialog, §4.2).
    expect(post).not.toHaveBeenCalled()
    // And the admin is told where the real door is, rather than nothing
    // visibly happening. (Fragments unique to the error line — the card
    // itself already says "รับชำระเงินแล้ว" on its button.)
    expect(wrapper.text()).toContain('มีบิลที่ยังไม่ปิด')
    expect(wrapper.text()).toContain('ลากเลื่อนไม่ได้')
  })

  it('a card WITHOUT the confirm button still drags and advances, exactly as before', async () => {
    // The control. §4.5 — no order means no confirm door, so drag-to-advance
    // is untouched for every card that is not at an open bill.
    const wrapper = await mountBoard([makeReferral({ id: 43, name: 'มานะ', order: null })])

    expect(doors(wrapper, 'มานะ')).toEqual({ confirm: false, advance: true })
    expect(card(wrapper, 'มานะ').attributes('draggable')).toBe('true')

    await card(wrapper, 'มานะ').trigger('dragstart')
    await column(wrapper, PAYMENT_STAGE_KEY).trigger('drop')
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/referrals/43/advance')
  })
})

describe('§4.2 — the action goes through ConfirmDialog', () => {
  it('posts NOTHING on the card click, and only confirms after the dialog is accepted', async () => {
    const wrapper = await mountBoard([makeReferral({ id: 42, name: 'มานี', order: makeOrder({ id: 77 }) })])

    await confirmButton(wrapper, 'มานี').trigger('click')
    // The dialog is up; the ledger has not been touched.
    expect(post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ยืนยันว่าได้รับเงิน 8,900.00 บาท สำหรับ ORD-TEST01 แล้ว?')
    expect(wrapper.text()).toContain('ระบบจะบันทึกคอมมิชชั่นทันทีและแก้ไขภายหลังไม่ได้ (BR-4)')

    await dialogConfirm(wrapper).trigger('click')
    await flushPromises()

    // The ORDER endpoint, not the referral advance one — that distinction
    // is the whole of §2.
    expect(post).toHaveBeenCalledTimes(1)
    expect(post.mock.calls[0]?.[0]).toBe('/orders/77/confirm')
    // BR-3 — no amount is echoed back to the server at all, so there is no
    // opportunity for a baht float to reach the API.
    expect(post.mock.calls[0]?.[1]).toBeUndefined()
    // Reloaded: once on mount, once after confirming.
    expect(get.mock.calls.filter((c) => c[0] === '/referrals')).toHaveLength(2)
  })

  it('never reaches the advance endpoint from the confirm door', async () => {
    const wrapper = await mountBoard([makeReferral({ id: 42, name: 'มานี', order: makeOrder({ id: 77 }) })])

    await confirmButton(wrapper, 'มานี').trigger('click')
    await dialogConfirm(wrapper).trigger('click')
    await flushPromises()

    expect(post.mock.calls.map((c) => c[0])).not.toContain('/referrals/42/advance')
  })

  it('closes the dialog and posts nothing when cancelled', async () => {
    const wrapper = await mountBoard([makeReferral({ name: 'มานี', order: makeOrder() })])

    await confirmButton(wrapper, 'มานี').trigger('click')
    const cancel = wrapper.findAll('button').find((b) => b.text() === 'ยกเลิก')
    expect(cancel).toBeDefined()
    await cancel!.trigger('click')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('ยืนยันว่าได้รับเงิน')
  })

  it('surfaces a failed confirm without pretending it worked', async () => {
    const wrapper = await mountBoard([makeReferral({ name: 'มานี', order: makeOrder({ id: 77 }) })])
    post.mockRejectedValueOnce(new FakeApiError(422, null))

    await confirmButton(wrapper, 'มานี').trigger('click')
    await dialogConfirm(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('ยืนยันการชำระเงินไม่สำเร็จ (422)')
    // A failed confirm must not silently re-read the board as if it had
    // succeeded: only the mount load happened.
    expect(get.mock.calls.filter((c) => c[0] === '/referrals')).toHaveLength(1)
  })
})

describe('§4.3 / §4.4 — what the admin can see before deciding', () => {
  it('names the amount, the order and its status on the card (BR-3: satang ÷ 100 at display only)', async () => {
    const wrapper = await mountBoard([
      makeReferral({
        name: 'มานี',
        order: makeOrder({ order_number: 'ORD-ABCD1234', amount_satang: 990050, status: 'pending' }),
      }),
    ])

    const text = card(wrapper, 'มานี').text()
    expect(text).toContain('ORD-ABCD1234')
    expect(text).toContain('9,900.50 บาท')
    expect(text).toContain('รอชำระเงิน')
    // The raw satang integer is never shown to a human.
    expect(text).not.toContain('990050')
  })

  it('says มีสลิปแนบ when there is a slip to check (§4.4)', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'มีสลิป', order: makeOrder({ has_slip: true }) }),
      makeReferral({ name: 'ไม่มีสลิป', order: makeOrder({ id: 78, has_slip: false }) }),
    ])

    expect(card(wrapper, 'มีสลิป').text()).toContain('มีสลิปแนบ')
    expect(card(wrapper, 'ไม่มีสลิป').text()).not.toContain('มีสลิปแนบ')
  })

  it('shows ยืนยันโดย + when, on a paid order (requirement #2)', async () => {
    const wrapper = await mountBoard([
      makeReferral({
        name: 'ปิดบิลแล้ว',
        current: PAYMENT,
        order: makeOrder({
          status: 'paid',
          paid_at: '2026-08-12T04:00:00.000000Z',
          verified_by: { id: 3, name: 'แอดมิน สมศรี' },
        }),
      }),
    ])

    expect(card(wrapper, 'ปิดบิลแล้ว').text()).toContain('ยืนยันโดย แอดมิน สมศรี')
  })

  it('says ไม่ทราบ rather than blank when the confirming user is unknown', async () => {
    const wrapper = await mountBoard([
      makeReferral({
        name: 'ไม่รู้ใคร',
        current: PAYMENT,
        order: makeOrder({ status: 'paid', paid_at: '2026-08-12T04:00:00.000000Z', verified_by: null }),
      }),
    ])

    // Never blank, and never a fabricated fallback name.
    expect(card(wrapper, 'ไม่รู้ใคร').text()).toContain('ยืนยันโดย: ไม่ทราบ')
  })

  it('shows no ยืนยันโดย line while the order is still unpaid', async () => {
    const wrapper = await mountBoard([makeReferral({ name: 'ยังไม่จ่าย', order: makeOrder() })])

    expect(card(wrapper, 'ยังไม่จ่าย').text()).not.toContain('ยืนยันโดย')
  })
})
