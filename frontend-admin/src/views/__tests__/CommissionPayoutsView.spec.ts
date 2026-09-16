/**
 * CommissionPayoutsView — the ตั้งจ่าย screen.
 *
 * ── WHAT THIS FILE IS ABOUT ──
 *
 * Two things, and they are related.
 *
 * 1. TASK-179 §3.7 (F-10): a bucket the filter excluded is NOT zero.
 *
 *    `AgentCommissionSummaryService` used to force the excluded bucket to
 *    literal 0, so filtering the screen by "จ่ายแล้ว" rendered "รอจ่ายรวม 0
 *    บาท" — visually indistinguishable from "we owe our agents nothing". It is
 *    a statement about money that nobody computed, sitting on the screen an
 *    admin uses to decide what to pay.
 *
 *    Phase 1 fixed the source: the excluded bucket comes back as `null`. That
 *    fix is worth exactly nothing if this layer writes `?? 0` — the rendered
 *    result would be byte-for-byte the old defect with a green tick on the
 *    backend PR. So the assertions below are about what is NOT printed.
 *
 * 2. 2026-09-16 — THE THREE GROUPS (ร่าง 1).
 *
 *    Owner: "สถานะการแสดงผล กับการตั้งจ่าย กับการรอจ่าย มันควรแยกกัน ดูง่าย
 *    และตัวที่พบปัญหา มีค่าคอมแต่ตั้งจ่ายไม่ได้ … ควรแยกเป็น 3 กลุ่ม" → "ทำ
 *    ร่าง 1 เลย".
 *
 *    The ค้างจ่าย / จ่ายแล้ว / ทั้งหมด tab strip split the list on the wrong
 *    axis: history from work, leaving three kinds of work piled together. It is
 *    replaced by a 3-step money band that is also the switcher, plus an amber
 *    bar above it for the people who are not on the conveyor at all.
 *
 * The two meet at step 3 of the band, which is measured by a request that has
 * not been made until somebody opens it — so it must say "กดเพื่อดู" and never
 * "0 บาท". That is F-10 applied to a bucket nobody has asked for YET rather
 * than to one a filter excluded.
 *
 * The API is mocked at `@/api/client`. Authorization, tenant scoping and the
 * filter semantics themselves are enforced and tested server-side (BR-6).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
// 2026-09-15 — captured, not an anonymous vi.fn() in the factory: the bulk
// payout below asserts on the exact body sent, and an unreachable mock can
// only be asserted to have been called.
const post = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  /*
   * 2026-09-15 — this double now extracts the message the way the real
   * ApiError does (see ApiError.extractMessage). It used to always be
   * `API error ${status}`, which is the shape the real class was FIXED away
   * from — so a screen that surfaces the server's own sentence could not be
   * tested here at all, and a double that behaves differently from the real
   * thing in exactly the way a test is about is worse than no double.
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
 * 2026-09-15 — this view reads `?view=` / `?tab=` at setup to choose which
 * group opens (see CommissionPayoutsView). An unmocked router makes
 * `useRoute()` undefined and every test here dies in setup, on a line that has
 * nothing to do with what it asserts.
 */
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
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
// Driven through its v-model events below rather than by typing into its
// three selects: this file is about what the payout button sends, not about
// how a Buddhist-era date picker assembles an ISO string.
import DateRangeFilter from '@/design-system/components/DateRangeFilter.vue'

// ── Fixtures ────────────────────────────────────────────────────────────
// BR-3 — the API sends integer satang. 1,500.00 THB = 150_000 satang.
const FIFTEEN_HUNDRED_BAHT = 150_000
const TWO_THOUSAND_BAHT = 200_000

interface RowOverrides {
  agent_id?: number
  agent_name?: string
  total_paid_satang?: number | null
  total_pending_satang?: number | null
  /** 2026-09-15 — this payee is the company itself, not one of its agents. */
  is_company_share?: boolean
  payout_details_complete?: boolean
  bank_account_number?: string | null
  reserved_satang?: number
  available_satang?: number | null
}

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
     * 2026-09-15 — the SERVER's answer to "can this payee be paid at all".
     *
     * Default true so the rows in this file are ordinary payable people; the
     * tests about the refusals pass false explicitly. A default of false would
     * make every selection test here fail for a reason none of them is about
     * — and, since 2026-09-16, would move every row out of the group the test
     * is looking at.
     */
    payout_details_complete: true,
    /*
     * 2026-09-16 — what a NEW payout may be raised for. Equal to the pending
     * balance until something is already in flight; the tests about the bug
     * the owner hit set them apart on purpose.
     */
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

type Row = ReturnType<typeof makeRow>

/**
 * The screen makes TWO different requests against one endpoint, and they mean
 * different things — so the double answers them differently rather than
 * handing back one list for both. A test that let payment_status=paid return
 * the pending rows could not tell a cached fetch from a missing one.
 */
function wireApi(pending: Row[], paid: Row[] = []) {
  get.mockImplementation((path: string) => {
    if (typeof path === 'string' && path.startsWith('/agent-commission-summary')) {
      return Promise.resolve({
        data: path.includes('payment_status=paid') ? paid : pending,
        computed_at: '2026-08-13T00:00:00Z',
      })
    }
    throw new Error(`unexpected GET ${path}`)
  })
}

/**
 * @param open which group to land on. The screen OPENS on ตั้งจ่ายได้เลย (the
 *             work group), so every other group is reached the way a reader
 *             reaches it: by pressing a step on the band, or — for ติดปัญหา,
 *             which deliberately has no step — the button in the amber bar.
 */
async function mountView(
  pending: Row[],
  open: 'payable' | 'reserved' | 'blocked' | 'paid' = 'payable',
  paid: Row[] = [],
) {
  wireApi(pending, paid)
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
 * A plain `toContain('0 บาท')` is USELESS here and was wrong in the first
 * draft of this file: "1,500 บาท" ends in "0 บาท", so the negative assertion
 * passed for the wrong reason on every row that had money on it. The
 * leading `[^\d]` requires the zero to be the WHOLE amount, not the last
 * digit of a real one.
 */
const STANDALONE_ZERO_BAHT = /(^|[^\d])0 บาท/

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  post.mockResolvedValue({ data: { id: 1, amount_satang: TWO_THOUSAND_BAHT } })
})

describe('§3.7 / F-10 — a bucket nobody measured is never printed as a figure', () => {
  it('prints the measured bucket as money', async () => {
    const wrapper = await mountView([makeRow()])

    expect(wrapper.text()).toContain('2,000 บาท')
    expect(wrapper.text()).not.toContain('ไม่ได้แสดง')
  })

  it('prints a REAL zero as 0 บาท — a measured nothing is still a number', async () => {
    /*
     * This is the case the null contract must not swallow. Nobody is payable,
     * the bucket WAS measured, and it came out empty: step 1 has to say so in
     * money, because "we can raise nothing today" is a fact worth stating.
     */
    const wrapper = await mountView([makeRow({ total_pending_satang: 0, available_satang: 0 })])

    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).toMatch(STANDALONE_ZERO_BAHT)
    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).not.toContain('ไม่ได้แสดง')
  })

  it('refuses to put a figure on step 3 before the request that measures it has run', async () => {
    /*
     * THE ONE THAT MATTERS about the band. Step 3's bucket is fetched the first
     * time somebody opens it. Until then it is not zero, it is unknown — and
     * this screen has exactly one way of getting that wrong, which is printing
     * the sum of an empty array.
     */
    const wrapper = await mountView([makeRow()])

    const step3 = wrapper.get('[data-test="payout-step-amount-paid"]').text()

    expect(step3).toContain('กดเพื่อดู')
    expect(step3).not.toMatch(STANDALONE_ZERO_BAHT)
  })

  it('renders ไม่ได้แสดง on the row and the step when the bucket came back null', async () => {
    // payment_status=paid → the server measures paid and returns null for
    // pending. The screen cannot invent the missing side.
    const wrapper = await mountView(
      [makeRow({ total_paid_satang: FIFTEEN_HUNDRED_BAHT, total_pending_satang: null, available_satang: null })],
      'paid',
      [makeRow({ total_paid_satang: FIFTEEN_HUNDRED_BAHT, total_pending_satang: null, available_satang: null })],
    )

    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).toContain('ไม่ได้แสดง')
    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).not.toMatch(STANDALONE_ZERO_BAHT)
    // The measured side still shows its real figure.
    expect(wrapper.text()).toContain('1,500 บาท')
  })

  it('the header KPI refuses to total an unmeasured column rather than under-reporting it', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 1, total_pending_satang: null, available_satang: null }),
      makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: TWO_THOUSAND_BAHT }),
    ])

    const kpis = wrapper.findComponent({ name: 'HeroHeader' }).props('kpis') as Array<{ label: string; value: string }>
    const owed = kpis.find((k) => k.label === 'ค้างจ่ายรวมทั้งหมด')

    // Not 2,000 (a partial sum over the rows that happened to have a value),
    // and not 0. Stated as unmeasured.
    expect(owed?.value).toContain('ไม่ได้แสดง')
  })
})

/**
 * ══════════════════════════════════════════════════════════════════════════
 * 2026-09-16 — THE THREE GROUPS.
 *
 * One list used to hold four different situations under one heading, told
 * apart only by a line of small text on the row. Each group is now a list of
 * its own, and the band above says how much money is in each.
 * ══════════════════════════════════════════════════════════════════════════
 */
describe('the groups', () => {
  const READY = makeRow({ agent_id: 1, agent_name: 'พร้อมจ่าย' })
  const IN_FLIGHT = makeRow({
    agent_id: 2,
    agent_name: 'รอโอน',
    total_pending_satang: FIFTEEN_HUNDRED_BAHT,
    reserved_satang: FIFTEEN_HUNDRED_BAHT,
    available_satang: 0,
  })
  const NO_BANK = makeRow({
    agent_id: 3,
    agent_name: 'ไม่มีบัญชี',
    payout_details_complete: false,
    bank_account_number: null,
  })

  it('opens on the work group and asks only for what is still owed', async () => {
    // THE ONE THAT MATTERS for the owner's original complaint: work and
    // history looked identical because the screen opened on "ทั้งหมด".
    wireApi([READY])
    mount(CommissionPayoutsView)
    await flushPromises()

    expect(get).toHaveBeenCalledWith(expect.stringContaining('payment_status=pending'))
    expect(get).not.toHaveBeenCalledWith(expect.stringContaining('payment_status=paid'))
  })

  it('puts each of the three situations in exactly one list', async () => {
    const payable = await mountView([READY, IN_FLIGHT, NO_BANK], 'payable')
    expect(payable.find('[data-test="payout-row-1"]').exists()).toBe(true)
    expect(payable.find('[data-test="payout-row-2"]').exists()).toBe(false)
    expect(payable.find('[data-test="payout-row-3"]').exists()).toBe(false)

    const reserved = await mountView([READY, IN_FLIGHT, NO_BANK], 'reserved')
    expect(reserved.find('[data-test="payout-row-2"]').exists()).toBe(true)
    expect(reserved.find('[data-test="payout-row-1"]').exists()).toBe(false)

    const blocked = await mountView([READY, IN_FLIGHT, NO_BANK], 'blocked')
    expect(blocked.find('[data-test="payout-row-3"]').exists()).toBe(true)
    expect(blocked.find('[data-test="payout-row-1"]').exists()).toBe(false)
  })

  it('keeps a part-raised payee in BOTH lists, because both sentences are true of them', async () => {
    /*
     * 1,500 waiting at รอบจ่าย and 500 still raisable. Dropping them from
     * either list would make that list wrong, so they are in both and the row
     * itself reconciles the two figures.
     */
    const PART = makeRow({
      agent_id: 7,
      total_pending_satang: TWO_THOUSAND_BAHT,
      reserved_satang: FIFTEEN_HUNDRED_BAHT,
      available_satang: 50_000,
    })

    expect((await mountView([PART], 'payable')).find('[data-test="payout-row-7"]').exists()).toBe(true)
    expect((await mountView([PART], 'reserved')).find('[data-test="payout-row-7"]').exists()).toBe(true)
  })

  it('totals each step in money, not in people', async () => {
    const wrapper = await mountView([READY, IN_FLIGHT, NO_BANK])

    // Step 1 is what can be RAISED (2,000), not what is owed across the list.
    expect(wrapper.get('[data-test="payout-step-amount-payable"]').text()).toContain('2,000 บาท')
    // Step 2 is what accounting is holding.
    expect(wrapper.get('[data-test="payout-step-amount-reserved"]').text()).toContain('1,500 บาท')
  })

  it('fetches history the first time step 3 is opened, and not again on the way back', async () => {
    const wrapper = await mountView([READY], 'paid', [makeRow({ agent_id: 9, total_paid_satang: TWO_THOUSAND_BAHT })])

    const paidCalls = () => get.mock.calls.filter(([p]) => String(p).includes('payment_status=paid')).length
    expect(paidCalls()).toBe(1)

    await wrapper.get('[data-test="payout-step-payable"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="payout-step-paid"]').trigger('click')
    await flushPromises()

    // Kept, not re-requested — and so step 3 never loses the figure it showed.
    expect(paidCalls()).toBe(1)
    expect(wrapper.get('[data-test="payout-step-amount-paid"]').text()).toContain('2,000 บาท')
  })

  it('offers ticking in the work group and nowhere else', async () => {
    /*
     * This is the whole reason the groups exist. A tick means "pay this person
     * this amount" and there must be no list on this screen where it could
     * mean anything else.
     */
    expect((await mountView([READY], 'payable')).find('[data-test="payout-select-1"]').exists()).toBe(true)
    expect((await mountView([READY, IN_FLIGHT], 'reserved')).find('[data-test="payout-select-2"]').exists()).toBe(false)
    expect((await mountView([READY, NO_BANK], 'blocked')).find('[data-test="payout-select-3"]').exists()).toBe(false)
  })

  it('names the list it is showing, in words, above the table', async () => {
    // The band says it in numbers; ติดปัญหา has no step on the band at all, so
    // without this heading that group would be the only unlabelled list.
    const wrapper = await mountView([READY, NO_BANK], 'blocked')

    expect(wrapper.get('[data-test="payout-group-heading"]').text()).toContain('ติดปัญหา')
  })

  it('shows history in its own column, and never mixes it with what is owed', async () => {
    const wrapper = await mountView([READY], 'paid', [
      makeRow({ agent_id: 9, total_paid_satang: FIFTEEN_HUNDRED_BAHT, total_pending_satang: null, available_satang: null }),
    ])

    expect(wrapper.get('thead').text()).toContain('จ่ายแล้ว')
    expect(wrapper.get('thead').text()).not.toContain('ยอดค้างจ่าย')
  })
})

/**
 * กลุ่มติดปัญหา is not a step on the band — someone with no bank account never
 * entered the conveyor, which is WHY they are stuck. It is the amber bar above
 * it, which is also the only thing on this screen that has to be noticed
 * without being looked for.
 */
describe('the amber bar', () => {
  const NO_BANK = makeRow({
    agent_id: 3,
    agent_name: 'ไม่มีบัญชี',
    payout_details_complete: false,
    bank_account_number: null,
  })

  it('says how many and how much, and names them', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 }), NO_BANK])

    const bar = wrapper.get('[data-test="payout-blocked-alert"]').text()

    expect(bar).toContain('1 รายมีค่าคอม แต่ตั้งจ่ายไม่ได้')
    expect(bar).toContain('2,000 บาท')
    expect(bar).toContain('ไม่มีบัญชี')
  })

  it('is not there at all on a day with no problems', async () => {
    // A permanent warning bar teaches people to stop reading warning bars.
    const wrapper = await mountView([makeRow({ agent_id: 1 })])

    expect(wrapper.find('[data-test="payout-blocked-alert"]').exists()).toBe(false)
  })

  it('opens the list from ดูรายชื่อ', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 }), NO_BANK])

    await wrapper.get('[data-test="payout-blocked-open"]').trigger('click')

    expect(wrapper.get('[data-test="payout-group-heading"]').text()).toContain('ติดปัญหา')
    expect(wrapper.get('[data-test="payout-blocked-3"]').text()).toContain('บัญชีธนาคาร')
  })

  it('offers the company-settings link only when the company seat is one of the blocked', async () => {
    /*
     * The one repair that cannot be made from this screen: the seat is not a
     * person and PUT /users/{id} refuses it. Offering that link for a blocked
     * AGENT would send an admin to a settings page that cannot fix them.
     */
    const agentOnly = await mountView([NO_BANK])
    expect(agentOnly.find('[data-test="payout-blocked-company-link"]').exists()).toBe(false)

    const withCompany = await mountView([
      makeRow({ agent_id: 90, agent_name: 'Thai Life insurance', is_company_share: true, payout_details_complete: false, bank_account_number: null }),
    ])
    expect(withCompany.find('[data-test="payout-blocked-company-link"]').exists()).toBe(true)
  })

  it('carries the repair on the row too, so the list is a list of fixes', async () => {
    const wrapper = await mountView([NO_BANK], 'blocked')

    expect(wrapper.find('[data-test="payout-fix-bank-3"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payout-pay-one-3"]').exists()).toBe(false)
  })
})

describe('the selection', () => {
  it('adds the row to a running total that is printed before the press', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 1, total_pending_satang: TWO_THOUSAND_BAHT }),
      makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT }),
    ])

    await wrapper.get('[data-test="payout-select-1"]').trigger('change')
    await wrapper.get('[data-test="payout-select-2"]').trigger('change')

    // 2,000 + 1,500. The figure an admin checks against what accounting is
    // about to be asked to transfer — so it is theirs to see BEFORE pressing.
    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('3,500 บาท')
  })

  it('shows the bar with an instruction, not a total, before anything is ticked', async () => {
    /*
     * 2026-09-16 — REVERSED ON PURPOSE. This asserted the bar was HIDDEN until
     * something was selected, which hid the one control that would have
     * explained the screen from exactly the person who had not worked it out
     * (owner: "ผู้ใช้ไม่รู้ Action ในการติ๊กเครื่องหมายถูก").
     */
    const wrapper = await mountView([makeRow()])

    expect(wrapper.find('[data-test="payout-selection-bar"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="payout-selection-empty"]').text()).toContain('คลิกที่แถว')
    // ...and no money figure, because nothing has been chosen to total.
    expect(wrapper.find('[data-test="payout-selection-total"]').exists()).toBe(false)
  })

  it('hides the bar when there is nobody who could be selected at all', async () => {
    // The control for the change above: an instruction to tick somebody, on a
    // list where nobody is tickable, is noise.
    const wrapper = await mountView([makeRow({ agent_id: 1, total_pending_satang: 0, available_satang: 0 })])

    expect(wrapper.find('[data-test="payout-selection-bar"]').exists()).toBe(false)
  })

  it('select-all takes everybody in the group, all of whom can be paid', async () => {
    /*
     * The old version of this test needed a third row that select-all had to
     * SKIP (an agent with no bank account). That row is not in this list any
     * more — it is in กลุ่มติดปัญหา — which is the point of the split: the
     * skip cannot be got wrong if the row is not there to skip.
     */
    const wrapper = await mountView([
      makeRow({ agent_id: 1, total_pending_satang: TWO_THOUSAND_BAHT }),
      makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT }),
      makeRow({ agent_id: 3, agent_name: 'ไม่มีบัญชี', payout_details_complete: false }),
    ])

    await wrapper.get('[data-test="payout-select-all"]').trigger('change')

    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('3,500 บาท')
  })

  it('is dropped when the group changes', async () => {
    /*
     * A tick is an agreement to pay a named person a named amount. Carrying it
     * while the reader browses another group would leave it to be confirmed
     * from a screen showing different names and a different total.
     */
    const wrapper = await mountView([
      makeRow({ agent_id: 1 }),
      makeRow({ agent_id: 2, agent_name: 'รอโอน', reserved_satang: FIFTEEN_HUNDRED_BAHT, available_satang: 0 }),
    ])
    await wrapper.get('[data-test="payout-select-1"]').trigger('change')
    expect(wrapper.find('[data-test="payout-selection-total"]').exists()).toBe(true)

    await wrapper.get('[data-test="payout-step-reserved"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="payout-step-payable"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payout-selection-total"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payout-selection-empty"]').exists()).toBe(true)
  })
})

describe('the press', () => {
  async function ticked(rows: Row[] = [makeRow({ agent_id: 42 })]) {
    const first = rows[0]!
    const wrapper = await mountView(rows)
    await wrapper.get(`[data-test="payout-select-${first.agent_id}"]`).trigger('change')
    return wrapper
  }

  it('does not write on the first press', async () => {
    const wrapper = await ticked()

    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="payout-batch-confirm"]').exists()).toBe(true)
  })

  it('names the amount, and says plainly that nothing is settled yet', async () => {
    const wrapper = await ticked()
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')

    const confirm = wrapper.get('[data-test="payout-batch-confirm"]').text()

    expect(confirm).toContain('2,000 บาท')
    expect(confirm).toContain('ยังไม่ปิดรายการค่าคอม')
    expect(confirm).toContain('รอบจ่าย')
    // The copy this replaced said "แก้ไขย้อนหลังไม่ได้", which was true of the
    // button that settled the ledger and is now the opposite of what happens.
    expect(confirm).not.toContain('แก้ไขย้อนหลังไม่ได้')
  })

  it('sends every ticked payee in ONE request, each with the total that was shown', async () => {
    /*
     * THE ONE THAT MATTERS. A loop over the single-payee endpoint fails on the
     * third of five and leaves an admin with two payouts raised, three not,
     * and no way to tell which.
     */
    const wrapper = await mountView([
      makeRow({ agent_id: 42, total_pending_satang: TWO_THOUSAND_BAHT }),
      makeRow({ agent_id: 43, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT }),
    ])
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

  it('raises payouts rather than settling the ledger', async () => {
    const wrapper = await ticked()
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    // The endpoint that settles a ledger row is never touched by this screen.
    expect(post).not.toHaveBeenCalledWith('/commission-ledger/mark-paid', expect.anything())
  })

  it('reports what was raised, and points at where it went', async () => {
    const wrapper = await ticked()
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    const done = wrapper.get('[data-test="payout-batch-done"]').text()

    expect(done).toContain('2,000 บาท')
    expect(done).toContain('รอบจ่าย')
  })

  it("shows the server's own refusal, because it is the one that says what to do", async () => {
    const wrapper = await ticked()
    post.mockRejectedValueOnce(new FakeApiError(422, {
      errors: { 'payees.0.expected_total_satang': ['ยอดค้างจ่ายของคนนี้เปลี่ยนไปแล้ว — กรุณารีเฟรชหน้าจอแล้วลองใหม่'] },
    }))

    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="payout-batch-error"]').text()).toContain('กรุณารีเฟรชหน้าจอ')
    // The selection survives a refusal: nothing was raised, so the admin's
    // work of choosing who to pay must not have to be redone.
    expect(wrapper.find('[data-test="payout-batch-confirm"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payout-batch-done"]').exists()).toBe(false)
  })

  it('goes away under a date filter, and says why', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 })])

    await wrapper.get('[data-test="payout-toggle-dates"]').trigger('click')
    const dates = wrapper.findComponent(DateRangeFilter)
    dates.vm.$emit('update:dateFrom', '2026-01-01')
    await flushPromises()

    expect(wrapper.find('[data-test="payout-select-1"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payout-date-filter-note"]').text()).toContain('ยอดค้างทั้งหมด')
  })
})

/**
 * 2026-09-15 (ครั้งที่สอง) — THE COMPANY'S OWN SHARE IS A PAYEE NOW.
 *
 * It used to be lifted out of the list into a green box that explained why it
 * could never be paid, and the tests here asserted exactly that. The owner
 * decided the company's share is transferred out like anybody else's
 * ("ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย"), so it is a row in the table.
 *
 * What survives from the old rule is the LABEL. An admin reconciling a bank
 * file still has to be able to tell at a glance which line is not a person —
 * and that row's bank details live on ตั้งค่าค่าแนะนำ, because PUT /users/{id}
 * refuses the seat and always will.
 */
describe("the company's own share", () => {
  const COMPANY = {
    agent_id: 90,
    agent_name: 'Thai Life insurance',
    is_company_share: true,
  }

  it('sits in the table, labelled, rather than in a box above it', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 }), makeRow(COMPANY)])

    const row = wrapper.get('[data-test="company-share-row-90"]')

    expect(row.text()).toContain('ส่วนของบริษัท')
    expect(row.text()).toContain('Thai Life insurance')
    // The sentence that used to be the whole point of the box is gone: this
    // money DOES move now.
    expect(wrapper.text()).not.toContain('ไม่ต้องโอน')
  })

  it('can be ticked and paid like anybody else', async () => {
    const wrapper = await mountView([makeRow(COMPANY)])

    await wrapper.get('[data-test="payout-select-90"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/payout-batch', {
      payees: [{ agent_id: 90, expected_total_satang: TWO_THOUSAND_BAHT }],
    })
  })

  it('points at the settings screen for its account, not at an inline form', async () => {
    /*
     * The seat is not a person. UserPolicy::update and UserService both refuse
     * that row, so the "แก้ไข" link every agent row carries would open a form
     * whose save can only fail.
     */
    const wrapper = await mountView([
      makeRow({ ...COMPANY, payout_details_complete: false, bank_account_number: null }),
    ], 'blocked')

    expect(wrapper.find('[data-test="payout-company-bank-link"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payout-bank-edit-90"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payout-blocked-90"]').text()).toContain('บัญชีรับเงินของบริษัท')
  })

  it('shows nothing extra for a company that has no seat', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 })])

    expect(wrapper.find('[data-test="company-share-row-1"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('ส่วนของบริษัท')
  })
})


/**
 * ═══ 2026-09-16 — THE PRESS THAT LOOKED LIKE IT DID NOTHING ═══
 *
 * Owner: "ผมกดยืนยันการจ่ายไปแล้ว แต่ปัญหาคือหน้าจอ Ui ยังขึ้นค้างจ่ายอยู่".
 *
 * แนวทาง C leaves the ledger alone when a payout is raised — the money has not
 * moved — so the pending balance is UNCHANGED afterwards. This screen reloaded,
 * drew the identical row, and left it selectable; the server meanwhile compares
 * a press against availableSatang, which DOES subtract what the open payout
 * reserved. Every further press was refused forever with "0.00 ไม่ตรงกับ
 * 1,046.50": two numbers, one name, and a loop with no way out.
 *
 * The groups are the third answer to the same complaint: a fully-raised payee
 * is not merely un-tickable now, they are not in the work list at all.
 */
describe('after a payout has been raised', () => {
  const RAISED = {
    agent_id: 42,
    total_pending_satang: TWO_THOUSAND_BAHT,
    reserved_satang: TWO_THOUSAND_BAHT,
    available_satang: 0,
  }

  it('leaves the work list, and shows up under รอฝ่ายบัญชีโอน instead', async () => {
    // THE ONE THAT MATTERS. A tickable row whose press can only be refused is
    // worse than a disabled one: the refusal arrives after the confirmation.
    const work = await mountView([makeRow(RAISED)])
    expect(work.find('[data-test="payout-row-42"]').exists()).toBe(false)

    const waiting = await mountView([makeRow(RAISED)], 'reserved')
    expect(waiting.find('[data-test="payout-row-42"]').exists()).toBe(true)
  })

  it('still shows what the agent is OWED — the ledger is untouched until the transfer', async () => {
    const wrapper = await mountView([makeRow(RAISED)], 'reserved')

    expect(wrapper.get('[data-test="payout-row-42"]').text()).toContain('2,000 บาท')
    expect(wrapper.get('[data-test="payout-blocked-42"]').text()).toContain('รอโอนอยู่ที่ รอบจ่าย')
  })

  it('explains the gap when only PART of the balance is in flight', async () => {
    // Otherwise the ยอดค้างจ่าย column and the amount the press raises differ
    // with nothing on screen accounting for the difference.
    const wrapper = await mountView([
      makeRow({ agent_id: 42, total_pending_satang: TWO_THOUSAND_BAHT, reserved_satang: FIFTEEN_HUNDRED_BAHT, available_satang: 50_000 }),
    ])

    const note = wrapper.get('[data-test="payout-reserved-42"]').text()

    expect(note).toContain('ตั้งจ่ายแล้วรอโอน')
    expect(note).toContain('1,500')
    expect(note).toContain('500')
  })

  it('sends the AVAILABLE figure, which is the one the server checks', async () => {
    /*
     * The root of the loop. This sent total_pending_satang, and the server
     * compares against availableSatang — identical only until the first payout
     * exists, and permanently different afterwards.
     */
    const wrapper = await mountView([
      makeRow({ agent_id: 42, total_pending_satang: TWO_THOUSAND_BAHT, reserved_satang: FIFTEEN_HUNDRED_BAHT, available_satang: 50_000 }),
    ])

    await wrapper.get('[data-test="payout-select-42"]').trigger('change')
    await wrapper.get('[data-test="payout-batch-submit"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/payout-batch', {
      payees: [{ agent_id: 42, expected_total_satang: 50_000 }],
    })
  })

  it('totals the selection by what will be raised, not by what is owed', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 42, total_pending_satang: TWO_THOUSAND_BAHT, reserved_satang: FIFTEEN_HUNDRED_BAHT, available_satang: 50_000 }),
    ])

    await wrapper.get('[data-test="payout-select-42"]').trigger('change')

    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('500 บาท')
  })
})


/**
 * ═══ 2026-09-16 — MAKING THE SELECTION DISCOVERABLE (owner: ข้อ 2 + ข้อ 3) ═══
 *
 * Owner: "แบบที่เราเลือกพบปัญหาการใช้งานอย่างหนึ่ง ผู้ใช้ไม่รู้ Action ในการติ๊ก
 * เครื่องหมายถูกหน้ารายชื่อที่ต้องการโอนค่าคอม ทำไห้ผู้ใช้ไม่เข้าใจในการใช้งาน".
 *
 * Two answers at different layers: the screen now teaches (a labelled column,
 * a one-line hint, a whole-row target, a bar that is present before anything
 * is ticked), and it offers a shortcut for the case that is actually common
 * here — paying one person.
 */
describe('finding out that you have to select', () => {
  it('labels the column instead of leaving a bare checkbox', async () => {
    const wrapper = await mountView([makeRow()])

    expect(wrapper.get('thead').text()).toContain('เลือก')
  })

  it('says how the screen works while nothing is ticked', async () => {
    const wrapper = await mountView([makeRow()])

    expect(wrapper.get('[data-test="payout-select-hint"]').text()).toContain('คลิกที่แถว')
  })

  it('stops saying it once somebody has selected a row', async () => {
    // A permanent instruction is a permanent admission that the screen did
    // not explain itself.
    const wrapper = await mountView([makeRow({ agent_id: 1 })])

    await wrapper.get('[data-test="payout-select-1"]').trigger('change')

    expect(wrapper.find('[data-test="payout-select-hint"]').exists()).toBe(false)
  })

  it('selects when the ROW is clicked, not only the 16px checkbox', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 })])

    await wrapper.get('[data-test="payout-row-1"]').trigger('click')

    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('2,000 บาท')
  })

  it('does not select when a control INSIDE the row is clicked', async () => {
    /*
     * THE ONE THAT MATTERS about widening the target. Without the stopped
     * bubble, opening a drill-down or editing a bank account would silently
     * tick that person for payment — a selection nobody made, agreed to at the
     * confirmation by somebody reading the total rather than the names.
     */
    const wrapper = await mountView([makeRow({ agent_id: 1 })])

    await wrapper.get('[data-test="payout-detail-1"]').trigger('click')

    expect(wrapper.find('[data-test="payout-selection-total"]').exists()).toBe(false)
  })

  it('offers a whole-list shortcut from the bar', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 1 }),
      makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT, available_satang: FIFTEEN_HUNDRED_BAHT }),
    ])

    await wrapper.get('[data-test="payout-select-all-shortcut"]').trigger('click')

    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('3,500 บาท')
  })
})

describe('ตั้งจ่ายคนนี้ — the per-row shortcut', () => {
  it('writes nothing; it opens the SAME confirmation', async () => {
    /*
     * A per-row button existed before แบบ C and was removed on purpose: two
     * controls on one row, one collecting a selection and one writing
     * immediately, is the duplication this screen was consolidated to remove.
     *
     * This is a shortcut INTO the one flow, not a second flow — so what is
     * pinned is that it still goes through the confirmation and still writes
     * nothing on the first press.
     */
    const wrapper = await mountView([makeRow({ agent_id: 42 })])

    await wrapper.get('[data-test="payout-pay-one-42"]').trigger('click')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="payout-batch-confirm"]').exists()).toBe(true)
  })

  it('REPLACES the selection rather than adding to it', async () => {
    /*
     * Pressing "ตั้งจ่ายคนนี้" on one row while others are ticked has to mean
     * what it says. Adding would open a confirmation naming a total the
     * presser never chose — and the confirmation is the last line of defence
     * before rows that BR-4 makes permanent.
     */
    const wrapper = await mountView([
      makeRow({ agent_id: 1 }),
      makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT, available_satang: FIFTEEN_HUNDRED_BAHT }),
    ])

    await wrapper.get('[data-test="payout-select-1"]').trigger('change')
    await wrapper.get('[data-test="payout-pay-one-2"]').trigger('click')

    expect(wrapper.get('[data-test="payout-batch-confirm"]').text()).toContain('1 ราย')
    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('1,500 บาท')
  })

  it('goes through the same endpoint and payload as the batch press', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 42 })])

    await wrapper.get('[data-test="payout-pay-one-42"]').trigger('click')
    await wrapper.get('[data-test="payout-batch-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/payout-batch', {
      payees: [{ agent_id: 42, expected_total_satang: TWO_THOUSAND_BAHT }],
    })
  })
})
