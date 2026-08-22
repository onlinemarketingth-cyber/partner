/**
 * PipelineBoard — TASK-177 §5: the Agent Portal's "รับชำระเงินแล้ว" door,
 * and the rule that there is only ever one of it.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * Two actions on this board can end in a BR-4 commission ledger row: the
 * advance button (`POST /referrals/{id}/advance`, which fires
 * `recordForReferral()` on the way past Complete Payment) and the confirm
 * (`POST /orders/{id}/confirm`, which additionally marks the order paid,
 * stamps `verified_by_user_id`, and closes the customer's public
 * /pay/{token} page). TASK-177 §4.3 is that they are NEVER on the same row
 * at the same time — an agent must not have to work out which of two
 * buttons books the money.
 *
 * That rule lives in a single `v-if` / `v-else-if` chain in the template, so
 * it holds by construction. This file is what notices when somebody "tidies"
 * that chain into two independent `v-if`s, or adds a third caller. Every
 * door assertion below therefore checks BOTH buttons — the present one AND
 * the absent one. A test that only asserted the confirm button appears would
 * still pass with both buttons showing, which is exactly the defect.
 *
 * ── THE OTHER THING GUARDED HERE (ADR-026) ──
 *
 * Whether a row is "at or past payment" is a property of THAT REFERRAL'S OWN
 * journey, not of a global stage order. A hardcoded copy of §4.3's five
 * medical stages has been introduced into this codebase and removed again
 * three times since ADR-026. The pair of tests named "…same stage, different
 * journey…" is the trap for a fourth: two referrals sitting at the SAME
 * stage with the SAME order get DIFFERENT doors purely because their
 * templates differ. Any implementation that reasons from a fixed stage list
 * gets one of them wrong.
 *
 * ── DELIBERATELY A SEPARATE FILE ──
 *
 * PipelineBoard.spec.ts is TASK-169/171's file about journeys, grouping and
 * the two filter axes; nothing in it sends an `order` key. Keeping the
 * order/confirm subject here means the ADR-026 filter tests keep exercising
 * the "no order at all" shape that most rows on a real board have, instead
 * of every fixture quietly growing a field it does not care about.
 *
 * The API is mocked at `@/api/client`. Authorization, tenant isolation and
 * the 422 for confirming too early are enforced and tested server-side
 * (BR-6, ReferralOrderTest) — this file is about which affordance the board
 * offers, and what it sends when tapped.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()

const download = vi.fn()

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
    delete: vi.fn(),
    postForm: vi.fn(),
    download: (...args: unknown[]) => download(...args),
    downloadAbsolute: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import PipelineBoard from '../PipelineBoard.vue'
import { PAYMENT_STAGE_KEY, type PipelineStageRef } from '@/utils/pipelineStages'

// jsdom implements no scrolling at all, and TabFilterBar centres its active
// tab with el.scrollTo() once a bar has more than 3 tabs. Same shim as
// PipelineBoard.spec.ts.
Element.prototype.scrollTo = function () {} as Element['scrollTo']

// ── Fixtures ────────────────────────────────────────────────────────────
// Stage refs exactly as ReferralResource sends them: `{ key, label }` with an
// ENGLISH label (the Thai wording is the UI's, in pipelineStages.ts).
const REGISTERED: PipelineStageRef = { key: 'complete_registered', label: 'Complete Registered' }
const WAITING: PipelineStageRef = { key: 'waiting_appointment', label: 'Waiting Appointment' }
const MEETING: PipelineStageRef = { key: 'finish_1st_doctor_meeting', label: 'Finish 1st Doctor Meeting' }
const PAYMENT: PipelineStageRef = { key: PAYMENT_STAGE_KEY, label: 'Complete Payment' }
const ONGOING: PipelineStageRef = { key: 'ongoing_next_meeting', label: 'Ongoing Next Meeting' }
// ADR-026 §5 Q1 — a post-sale stage. Deliberately one that exists in NO
// medical template, so a predicate reasoning from §4.3's five cannot rank it.
const DELIVERY: PipelineStageRef = { key: 'delivery', label: 'Delivery' }

/** ADR-026's two seeded templates, plus a post-sale one. */
const MEDICAL_JOURNEY = [REGISTERED, WAITING, MEETING, PAYMENT, ONGOING]
const DIRECT_SALE_JOURNEY = [REGISTERED, PAYMENT]
const POST_SALE_JOURNEY = [REGISTERED, PAYMENT, DELIVERY]

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
    agent: null,
    product: { id: 5, name: 'แพ็กเกจสุขภาพ', price_satang: EIGHT_NINE_HUNDRED_SATANG },
    branch: 'สีลม',
    preferred_time: null,
    current_stage: current,
    meeting_number: null,
    pipeline: {
      stages: journey,
      next_stage:
        overrides.next !== undefined ? overrides.next : (journey[journey.indexOf(current) + 1] ?? null),
    },
    submitted_at: '2026-08-01T03:00:00.000000Z',
  }
  // `order` is OPTIONAL on the wire, not merely nullable: absent when the
  // backend did not eager-load `orders`. Only set the key when asked to.
  if ('order' in overrides) referral.order = overrides.order
  return referral
}

async function mountBoard(referrals: Record<string, unknown>[]) {
  get.mockImplementation((path: string) => {
    if (path === '/referrals') return Promise.resolve({ data: referrals })
    if (String(path).endsWith('/stage-logs')) return Promise.resolve({ data: [] })
    return Promise.reject(new FakeApiError(404, null))
  })

  // ConfirmDialog is deliberately NOT stubbed — §4.4's wording and the
  // "nothing is posted until you confirm" rule are the subject here.
  const wrapper = mount(PipelineBoard)
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountBoard>>

/** The list row for a client, wherever its journey/stage group put it. */
function card(wrapper: Wrapper, clientName: string) {
  const found = wrapper.findAll('[data-test="referral-card"]').find((c) => c.text().includes(clientName))
  if (!found) throw new Error(`no row on the board for "${clientName}"`)
  return found
}

/**
 * Which doors this row is offering. Both halves are always read, because
 * §4.3 is a statement about the pair — not about either button alone.
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

/** The ConfirmDialog's own "ยืนยัน" button (not the row's). */
function dialogConfirm(wrapper: Wrapper) {
  const button = wrapper.findAll('button').find((b) => b.text() === 'ยืนยัน')
  if (!button) throw new Error('the ConfirmDialog is not open')
  return button
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  // No `localStorage.clear()` here. The `localStorage` this environment
  // provides is not a working Storage — `clear` is not a function, and neither
  // is `getItem` (which is why every module that reads a saved preference now
  // goes through `utils/safeStorage`). Nothing in this suite writes to storage
  // anyway: the board reads a language and a font size once, at import time,
  // and per-test clearing could not affect that even where storage worked.
  nextId = 1
})

describe('§4.3 — one door, never two', () => {
  it('shows ONLY the confirm button on a row with a live order at the payment gate', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'มานี', order: makeOrder({ status: 'awaiting_verification' }) }),
    ])

    expect(doors(wrapper, 'มานี')).toEqual({ confirm: true, advance: false })
    expect(confirmButton(wrapper, 'มานี').text()).toBe('รับชำระเงินแล้ว')
    // …and the advance label is gone from that row entirely, not merely
    // hidden behind a data-test rename.
    expect(card(wrapper, 'มานี').text()).not.toContain('ไป: ')
  })

  it('shows ONLY the advance button on a row with no order', async () => {
    const wrapper = await mountBoard([makeReferral({ name: 'มานะ', order: null })])

    expect(doors(wrapper, 'มานะ')).toEqual({ confirm: false, advance: true })
  })

  it('treats an ABSENT order key exactly like a null one', async () => {
    // No `order` key at all — what a ReferralResource that did not
    // eager-load `orders` sends (the nested ClientResource uses).
    const wrapper = await mountBoard([makeReferral({ name: 'ปิติ' })])

    expect(doors(wrapper, 'ปิติ')).toEqual({ confirm: false, advance: true })
  })

  it('offers no confirm on a cancelled order, and no confirm on an already-paid one', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'ชูใจ', order: makeOrder({ status: 'cancelled' }) }),
      makeReferral({
        name: 'วีระ',
        current: PAYMENT,
        order: makeOrder({ id: 78, status: 'paid', paid_at: '2026-08-12T04:00:00.000000Z' }),
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
    // moved on, which is the TASK-176 §2 defect this task exists to close.
    expect(doors(wrapper, 'อารี')).toEqual({ confirm: true, advance: false })
  })

  it('offers NEITHER door on a referral whose journey cannot be read (fail-closed)', async () => {
    // ReferralResource sends both arrays empty when the template was deleted
    // or emptied. An order hanging off such a row must not resurrect a
    // confirm button: nothing here can say where in a journey it sits, so
    // the row keeps its "เส้นทางไม่ถูกต้อง" flag and offers no write at all.
    const broken = {
      ...makeReferral({ name: 'เส้นทางพัง', order: makeOrder() }),
      pipeline: { stages: [], next_stage: null },
    }
    const wrapper = await mountBoard([broken])

    expect(doors(wrapper, 'เส้นทางพัง')).toEqual({ confirm: false, advance: false })
    expect(wrapper.text()).toContain('เส้นทางไม่ถูกต้อง')
  })

  /*
   * ══════════ THE ADR-026 TRAP ══════════
   * Same stage. Same order. Different journey. Different door.
   * A predicate built from a hardcoded stage list cannot pass both of these,
   * because `complete_registered` is one step from payment on one template
   * and three steps away on the other.
   */
  it('same stage, different journey: a DIRECT-SALE row at complete_registered gets the confirm', async () => {
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

  it('same stage, different journey: a MEDICAL row at complete_registered gets the advance', async () => {
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

  it('offers the confirm on a POST-SALE stage that exists in no medical template', async () => {
    // The sharpest form of the ADR-026 trap. `delivery` is not one of §4.3's
    // five, so a predicate that ranks stages against a fixed medical list
    // scores this row as "not yet at payment" (index -1) and offers it the
    // advance — or, since its template has nothing after delivery, no door at
    // all. Read against the referral's OWN journey it is plainly past
    // payment, with a bill still open: exactly the TASK-176 §2 defect.
    const wrapper = await mountBoard([
      makeReferral({
        name: 'ส่งของแล้วยังไม่ปิดบิล',
        journey: POST_SALE_JOURNEY,
        current: DELIVERY,
        order: makeOrder(),
      }),
    ])

    expect(doors(wrapper, 'ส่งของแล้วยังไม่ปิดบิล')).toEqual({ confirm: true, advance: false })
  })

  it('gives the two journeys different doors ON THE SAME BOARD', async () => {
    // Both at complete_registered, both with a live order, rendered together.
    // The strongest form of the trap: one fixed stage list has to answer for
    // both rows at once, and cannot.
    const wrapper = await mountBoard([
      makeReferral({ name: 'ตรงดิ่ง', journey: DIRECT_SALE_JOURNEY, current: REGISTERED, order: makeOrder() }),
      makeReferral({
        name: 'ทางยาว',
        journey: MEDICAL_JOURNEY,
        current: REGISTERED,
        order: makeOrder({ id: 78 }),
      }),
    ])

    expect(doors(wrapper, 'ตรงดิ่ง')).toEqual({ confirm: true, advance: false })
    expect(doors(wrapper, 'ทางยาว')).toEqual({ confirm: false, advance: true })
  })
})

/*
 * ══════════════════════════════════════════════════════════════════════
 * TASK-177 §1 — THIS BOARD HAS NO DRAG, AND MUST NOT GROW ONE SILENTLY.
 *
 * On the admin Kanban the drag gesture is a SECOND door to
 * `POST /referrals/{id}/advance`, and TASK-176's §4.1 follow-up had to close
 * it against the same predicate. Here the button is the only affordance, so
 * there was one door to fix rather than three — a claim worth pinning down
 * rather than trusting, since adding `draggable` to these rows later would
 * silently reopen the §2 defect on exactly the rows offering the confirm.
 * ══════════════════════════════════════════════════════════════════════
 */
describe('§1 — no drag affordance on this board', () => {
  it('renders no draggable element, on a confirm-door row or any other', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'มานี', order: makeOrder() }),
      makeReferral({ name: 'มานะ', order: null }),
    ])

    expect(doors(wrapper, 'มานี')).toEqual({ confirm: true, advance: false })
    expect(wrapper.findAll('[draggable="true"]')).toHaveLength(0)
    expect(card(wrapper, 'มานี').attributes('draggable')).toBeUndefined()
    expect(card(wrapper, 'มานะ').attributes('draggable')).toBeUndefined()
  })
})

describe('§4.4 — the action goes through the confirmation dialog', () => {
  it('posts NOTHING on the row tap, and only confirms after the dialog is accepted', async () => {
    const wrapper = await mountBoard([makeReferral({ id: 42, name: 'มานี', order: makeOrder({ id: 77 }) })])

    await confirmButton(wrapper, 'มานี').trigger('click')
    // The dialog is up; the ledger has not been touched.
    expect(post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ยืนยันว่าได้รับเงิน 8,900.00 บาท สำหรับ ORD-TEST01 แล้ว?')
    expect(wrapper.text()).toContain('ระบบจะบันทึกคอมมิชชั่นทันทีและแก้ไขภายหลังไม่ได้ (BR-4)')

    await dialogConfirm(wrapper).trigger('click')
    await flushPromises()

    // The ORDER endpoint, not the referral advance one — that distinction is
    // the whole of TASK-176 §2.
    expect(post).toHaveBeenCalledTimes(1)
    expect(post.mock.calls[0]?.[0]).toBe('/orders/77/confirm')
    // BR-3 §4.6 — no amount is echoed back to the server at all, so there is
    // no opportunity for a baht float to reach the API.
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

  it('does not open the audit drawer when the confirm button is tapped', async () => {
    // The whole row is a click target for the stage-history drawer, so the
    // button carries @click.stop. Without it the agent gets a drawer over
    // the dialog they just opened.
    const wrapper = await mountBoard([makeReferral({ name: 'มานี', order: makeOrder() })])

    await confirmButton(wrapper, 'มานี').trigger('click')
    await flushPromises()

    expect(get.mock.calls.map((c) => String(c[0]))).not.toContainEqual(
      expect.stringContaining('/stage-logs'),
    )
    expect(wrapper.text()).not.toContain('ประวัติการเปลี่ยนสถานะ')
  })

  it('surfaces a failed confirm without pretending it worked', async () => {
    const wrapper = await mountBoard([makeReferral({ name: 'มานี', order: makeOrder({ id: 77 }) })])
    // What OrderService::confirmPayment actually throws when the referral is
    // not yet at the payment gate: a Laravel ValidationException whose own
    // Thai message is more specific than anything this component could
    // invent. apiErrorMessage() prefers it (TASK-079 Phase 2), so THAT is
    // what the agent must see — not a status code, and not our fallback.
    post.mockRejectedValueOnce(
      new FakeApiError(422, { errors: { status: ['ยืนยันการชำระเงินได้เมื่อถึงขั้นชำระเงินแล้วเท่านั้น'] } }),
    )

    await confirmButton(wrapper, 'มานี').trigger('click')
    await dialogConfirm(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('ยืนยันการชำระเงินได้เมื่อถึงขั้นชำระเงินแล้วเท่านั้น')
    // The dialog is gone, so the failure is not left looking in-flight…
    expect(wrapper.text()).not.toContain('ยืนยันว่าได้รับเงิน')
    // …and a failed confirm must not silently re-read the board as if it had
    // succeeded: only the mount load happened.
    expect(get.mock.calls.filter((c) => c[0] === '/referrals')).toHaveLength(1)
    // The row still offers the confirm — nothing was optimistically marked
    // paid on the client.
    expect(doors(wrapper, 'มานี')).toEqual({ confirm: true, advance: false })
  })
})

describe('§4.5 / §4.6 — what the agent can see before deciding', () => {
  it('names the amount, the order and its status on the row (BR-3: satang ÷ 100 at display only)', async () => {
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

  it('never leaks the raw satang integer anywhere on the board, dialog included', async () => {
    const wrapper = await mountBoard([
      makeReferral({ name: 'มานี', order: makeOrder({ amount_satang: 990050 }) }),
    ])

    await confirmButton(wrapper, 'มานี').trigger('click')

    expect(wrapper.text()).toContain('ยืนยันว่าได้รับเงิน 9,900.50 บาท')
    expect(wrapper.text()).not.toContain('990050')
  })

  it('offers a way to OPEN the slip, not just a note that one exists', async () => {
    // Was: the card printed "· มีสลิปแนบ" as plain text. It named a document
    // and gave the agent nothing to press — reported 2026-08-21 as
    // "ลูกค้าแนบสลิปแล้วแต่ Agent เช็คไม่ได้". The note is now the button.
    const wrapper = await mountBoard([
      makeReferral({ name: 'มีสลิป', order: makeOrder({ has_slip: true }) }),
      makeReferral({ name: 'ไม่มีสลิป', order: makeOrder({ id: 78, has_slip: false }) }),
    ])

    expect(card(wrapper, 'มีสลิป').text()).toContain('ดูสลิป')
    // No slip means no button — never one that downloads a 404.
    expect(card(wrapper, 'ไม่มีสลิป').text()).not.toContain('ดูสลิป')
  })

  it('actually fetches the slip through the access-checked endpoint', async () => {
    // api.download and not an <a href>: the slip is on the private disk
    // behind GET /orders/{order}/slip (OrderPolicy::view), so a plain link
    // would 401 rather than open. A button that looks right and 401s is the
    // failure this asserts against.
    download.mockClear()
    const wrapper = await mountBoard([
      makeReferral({ name: 'มีสลิป', order: makeOrder({ has_slip: true }) }),
    ])

    await card(wrapper, 'มีสลิป').findAll('button').find((b) => b.text().includes('ดูสลิป'))!.trigger('click')

    expect(download).toHaveBeenCalledWith(
      expect.stringMatching(/^\/orders\/\d+\/slip$/),
      expect.stringContaining('slip-'),
    )
  })

  it('shows ยืนยันโดย + when, on a paid order', async () => {
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
