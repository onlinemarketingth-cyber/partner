/**
 * AgentCommissionSummaryView — TASK-179 §3.7 (F-10): a bucket the filter
 * excluded is NOT zero.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * `AgentCommissionSummaryService` used to force the excluded bucket to
 * literal 0, so filtering the screen by "จ่ายแล้ว" rendered "รอจ่ายรวม 0 บาท"
 * — visually indistinguishable from "we owe our agents nothing". It is a
 * statement about money that nobody computed, sitting on the screen an admin
 * uses to decide what to pay.
 *
 * Phase 1 fixed the source: the excluded bucket now comes back as `null`.
 * That fix is worth exactly nothing if this layer writes `?? 0` — the
 * rendered result would be byte-for-byte the old defect, with a green tick
 * on the backend PR. So the assertions below are about what is NOT printed:
 * every one of them checks that no "0 บาท" appears for an unmeasured bucket,
 * not merely that the words "ไม่ได้แสดง" appear somewhere.
 *
 * The KPI header gets the same treatment for a subtler reason: summing a
 * column where some rows are null with `?? 0` produces a company-wide total
 * assembled from a subset nobody defined, which reads as authoritative
 * precisely because it is a big number at the top of the page.
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
 * 2026-09-15 — this view reads `?view=` / `?tab=` at setup to choose which of
 * its two payout views opens (see CommissionPayoutsView). An unmocked router
 * makes `useRoute()` undefined and every test here dies in setup, on a line
 * that has nothing to do with what it asserts.
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
     * make every selection test here fail for a reason none of them is about.
     */
    payout_details_complete: true,
    avatar_url: null,
    cert_tier: null,
    ...overrides,
  }
}

function wireApi(rows: ReturnType<typeof makeRow>[]) {
  get.mockImplementation((path: string) => {
    if (path.startsWith('/agent-commission-summary')) {
      return Promise.resolve({ data: rows, computed_at: '2026-08-13T00:00:00Z' })
    }
    throw new Error(`unexpected GET ${path}`)
  })
}

/**
 * @param view which tab to land on. The screen OPENS on ค้างจ่าย now (the
 *             owner's "ที่จ่ายไปแล้ว ต้องไม่แสดง"), and that view deliberately
 *             does not render the จ่ายแล้ว column at all — so the tests about
 *             both buckets being printed have to ask for ทั้งหมด, exactly as a
 *             reader would.
 */
async function mountView(rows: ReturnType<typeof makeRow>[], view: 'pending' | 'paid' | 'all' = 'all') {
  wireApi(rows)
  const wrapper = mount(CommissionPayoutsView)
  await flushPromises()

  if (view !== 'pending') {
    await wrapper.get(`[data-test="payout-view-${view}"]`).trigger('click')
    await flushPromises()
  }

  return wrapper
}

/**
 * "A zero baht figure appears somewhere on the page."
 *
 * A plain `toContain('0 บาท')` is USELESS here and was wrong in the first
 * draft of this file: "1,500 บาท" ends in "0 บาท", so the negative assertion
 * passed for the wrong reason on every row that had money on it. The
 * leading `[^\d]` requires the zero to be the WHOLE amount, not the last
 * digit of a real one.
 */
const STANDALONE_ZERO_BAHT = /[^\d]0 บาท/

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  post.mockResolvedValue({ data: { id: 1, amount_satang: TWO_THOUSAND_BAHT } })
})

describe('unfiltered — both buckets were measured', () => {
  it('prints both amounts as money', async () => {
    const wrapper = await mountView([makeRow()])

    expect(wrapper.text()).toContain('1,500 บาท')
    expect(wrapper.text()).toContain('2,000 บาท')
    expect(wrapper.text()).not.toContain('ไม่ได้แสดง')
  })

  it('prints a REAL zero as 0 บาท — a measured nothing is still a number', async () => {
    // This is the case the null contract must not swallow: the filter was
    // not applied, the bucket WAS measured, and it came out empty.
    const wrapper = await mountView([makeRow({ total_pending_satang: 0 })])

    expect(wrapper.text()).toMatch(STANDALONE_ZERO_BAHT)
    expect(wrapper.text()).not.toContain('ไม่ได้แสดง')
  })
})

describe('filtered by payment_status — the excluded bucket was never measured', () => {
  it('renders ไม่ได้แสดง for the excluded bucket, and no 0 บาท anywhere', async () => {
    // payment_status=paid → the server measures paid and returns null for pending.
    const wrapper = await mountView([makeRow({ total_pending_satang: null })])

    expect(wrapper.text()).toContain('ไม่ได้แสดง')
    // The whole point of F-10: no fabricated zero on the row OR in the header.
    expect(wrapper.text()).not.toMatch(STANDALONE_ZERO_BAHT)
    // The measured side still shows its real figure.
    expect(wrapper.text()).toContain('1,500 บาท')
  })

  it('works the other way round too (payment_status=pending nulls the paid bucket)', async () => {
    const wrapper = await mountView([makeRow({ total_paid_satang: null })])

    expect(wrapper.text()).toContain('ไม่ได้แสดง')
    expect(wrapper.text()).not.toMatch(STANDALONE_ZERO_BAHT)
    expect(wrapper.text()).toContain('2,000 บาท')
  })

  it('the header KPI refuses to total an unmeasured column rather than under-reporting it', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 1, total_paid_satang: FIFTEEN_HUNDRED_BAHT, total_pending_satang: null }),
      makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_paid_satang: TWO_THOUSAND_BAHT, total_pending_satang: null }),
    ])

    // จ่ายแล้วรวม is measured on every row → a real sum, 1,500 + 2,000.
    expect(wrapper.text()).toContain('3,500 บาท')
    // รอจ่ายรวม is not → stated as unmeasured, never as 0 and never as a
    // partial sum over the rows that happened to have a value.
    expect(wrapper.text()).toContain('ไม่ได้แสดง')
    expect(wrapper.text()).not.toMatch(STANDALONE_ZERO_BAHT)
  })
})


/**
 * ══════════════════════════════════════════════════════════════════════════
 * 2026-09-15 — THE SCREEN BECAME แบบ C, AND TWO RULES CHANGED WITH IT.
 *
 * Owner: "คือตั้งจ่าย มันควรจะมีแต่ตั้งจ่าย ที่จ่ายไปแล้ว ต้องไม่แสดง เลือกเป็น
 * ตัวกรองได้" and "ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย".
 *
 * So the default view is ค้างจ่าย only, the press is a selection rather than a
 * per-row button, and the company's own share — which every test in the block
 * this replaces asserted could NEVER be paid — is a payee like anybody else.
 *
 * The old expectations are not deleted quietly. They are rewritten, because
 * "wasn't the company excluded on purpose?" is a question somebody will ask of
 * exactly this file, and the answer is: it was, and the owner changed it.
 * ══════════════════════════════════════════════════════════════════════════
 */

describe('ค้างจ่าย is the view the screen opens on', () => {
  it('asks the server for only what is still owed, without being told to', async () => {
    // THE ONE THAT MATTERS for the owner's complaint. Work and history looked
    // identical because the screen opened on "ทั้งหมด".
    wireApi([makeRow()])
    mount(CommissionPayoutsView)
    await flushPromises()

    expect(get).toHaveBeenCalledWith(expect.stringContaining('payment_status=pending'))
  })

  it('does not show a จ่ายแล้ว column in that view — it is history, not work', async () => {
    const wrapper = await mountView([makeRow()], 'pending')

    expect(wrapper.text()).toContain('2,000 บาท')
    expect(wrapper.text()).not.toContain('1,500 บาท')
  })

  it('จ่ายแล้ว is a tab, and asking for it refetches', async () => {
    const wrapper = await mountView([makeRow()], 'paid')

    expect(get).toHaveBeenCalledWith(expect.stringContaining('payment_status=paid'))
    // ...and nothing can be raised from a history view.
    expect(wrapper.find('[data-test="payout-select-1"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payout-paid-view-note"]').text()).toContain('ตั้งจ่ายจากหน้านี้ไม่ได้')
  })

  it('ทั้งหมด is still reachable, because "who have I paid at all" is a real question', async () => {
    const wrapper = await mountView([makeRow()], 'all')

    expect(wrapper.text()).toContain('1,500 บาท')
    expect(wrapper.text()).toContain('2,000 บาท')
  })
})

describe('the selection', () => {
  it('adds the row to a running total that is printed before the press', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 1, total_pending_satang: TWO_THOUSAND_BAHT }),
      makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT }),
    ], 'pending')

    await wrapper.get('[data-test="payout-select-1"]').trigger('change')
    await wrapper.get('[data-test="payout-select-2"]').trigger('change')

    // 2,000 + 1,500. The figure an admin checks against what accounting is
    // about to be asked to transfer — so it is theirs to see BEFORE pressing.
    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('3,500 บาท')
  })

  it('hides the bar entirely when nothing is ticked', async () => {
    const wrapper = await mountView([makeRow()], 'pending')

    expect(wrapper.find('[data-test="payout-selection-bar"]').exists()).toBe(false)
  })

  it('cannot tick somebody with nothing owed', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1, total_pending_satang: 0 })], 'pending')

    expect(wrapper.get('[data-test="payout-select-1"]').attributes('disabled')).toBeDefined()
  })

  it('cannot tick somebody the server would refuse, and says why on the row', async () => {
    /*
     * The refusal is the SERVER's (payout_details_complete), shown here rather
     * than discovered from a 422 after eight people were ticked. Re-deriving
     * it from the three bank fields would be a second copy of a rule that
     * differs per payee type.
     */
    const wrapper = await mountView([
      makeRow({ agent_id: 1, payout_details_complete: false, bank_account_number: null }),
    ], 'pending')

    expect(wrapper.get('[data-test="payout-select-1"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="payout-blocked-1"]').text()).toContain('บัญชีธนาคาร')
  })

  it('select-all takes everybody who can actually be paid, and nobody who cannot', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 1, total_pending_satang: TWO_THOUSAND_BAHT }),
      makeRow({ agent_id: 2, agent_name: 'สมหญิง', total_pending_satang: FIFTEEN_HUNDRED_BAHT }),
      makeRow({ agent_id: 3, agent_name: 'ไม่มีบัญชี', payout_details_complete: false }),
    ], 'pending')

    await wrapper.get('[data-test="payout-select-all"]').trigger('change')

    expect(wrapper.get('[data-test="payout-selection-total"]').text()).toContain('3,500 บาท')
  })

  it('is dropped when the view changes, because the amounts under it change too', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 })], 'pending')
    await wrapper.get('[data-test="payout-select-1"]').trigger('change')
    expect(wrapper.find('[data-test="payout-selection-bar"]').exists()).toBe(true)

    await wrapper.get('[data-test="payout-view-all"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payout-selection-bar"]').exists()).toBe(false)
  })
})

describe('the press', () => {
  async function ticked(rows: ReturnType<typeof makeRow>[] = [makeRow({ agent_id: 42 })]) {
    const first = rows[0]!
    const wrapper = await mountView(rows, 'pending')
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
    ], 'pending')
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
    const wrapper = await mountView([makeRow({ agent_id: 1 })], 'pending')

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
    const wrapper = await mountView([makeRow({ agent_id: 1 }), makeRow(COMPANY)], 'pending')

    const row = wrapper.get('[data-test="company-share-row-90"]')

    expect(row.text()).toContain('ส่วนของบริษัท')
    expect(row.text()).toContain('Thai Life insurance')
    // The sentence that used to be the whole point of the box is gone: this
    // money DOES move now.
    expect(wrapper.text()).not.toContain('ไม่ต้องโอน')
  })

  it('can be ticked and paid like anybody else', async () => {
    const wrapper = await mountView([makeRow(COMPANY)], 'pending')

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
    ], 'pending')

    expect(wrapper.find('[data-test="payout-company-bank-link"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="payout-bank-edit-90"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payout-blocked-90"]').text()).toContain('บัญชีรับเงินของบริษัท')
  })

  it('shows nothing extra for a company that has no seat', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 })], 'pending')

    expect(wrapper.find('[data-test="company-share-row-1"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('ส่วนของบริษัท')
  })
})
