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
}

function makeRow(overrides: RowOverrides = {}) {
  return {
    agent_id: overrides.agent_id ?? 1,
    agent_name: overrides.agent_name ?? 'สมชาย',
    total_paid_satang: overrides.total_paid_satang === undefined ? FIFTEEN_HUNDRED_BAHT : overrides.total_paid_satang,
    total_pending_satang:
      overrides.total_pending_satang === undefined ? TWO_THOUSAND_BAHT : overrides.total_pending_satang,
    entry_count: 3,
    bank_name: null,
    bank_account_number: null,
    bank_account_holder_name: null,
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

async function mountView(rows: ReturnType<typeof makeRow>[]) {
  wireApi(rows)
  const wrapper = mount(CommissionPayoutsView, { global: { stubs: { CommissionLedgerPanel: true } } })
  await flushPromises()
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
 * 2026-09-15 — THE COMPANY'S OWN SHARE IS NOT A PAYEE.
 *
 * A company can hold a seat at the top of its own hierarchy and be paid a
 * leader's override (ขั้นตอนที่ 4.2). This screen is a payout RUN: every row
 * on it is somebody a transfer is about to be made to, and it carries their
 * bank details and a drill-down for exactly that reason.
 *
 * The company's row belongs to neither half of that. It is real money the
 * admin wants to see, and it is money that never moves — so it is SPLIT OUT
 * rather than filtered away, which would hide it, or left in place, which
 * would queue it.
 */
describe('the company share is reported apart from the agents', () => {
  it('lifts it out of the agent list into its own row', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 1, agent_name: 'สมชาย' }),
      makeRow({ agent_id: 90, agent_name: 'Thai Life insurance', is_company_share: true }),
    ])

    const companyRow = wrapper.get('[data-test="company-share-row-90"]')
    expect(companyRow.text()).toContain('ส่วนของบริษัท')
    expect(companyRow.text()).toContain('Thai Life insurance')
    // ...and it says why there is nothing to do with it, which is the whole
    // reason it cannot sit in the list above.
    expect(companyRow.text()).toContain('ไม่ต้องโอน')
  })

  it('leaves the agent rows alone', async () => {
    const wrapper = await mountView([
      makeRow({ agent_id: 1, agent_name: 'สมชาย' }),
      makeRow({ agent_id: 90, agent_name: 'Thai Life insurance', is_company_share: true }),
    ])

    expect(wrapper.text()).toContain('สมชาย')
    expect(wrapper.find('[data-test="company-share-row-1"]').exists()).toBe(false)
  })

  it('shows nothing extra for a company that has no seat', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1, agent_name: 'สมชาย' })])

    expect(wrapper.find('[data-test="company-share-row-1"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('ส่วนของบริษัท')
  })
})

/**
 * 2026-09-15 — ONE MENU, TWO VIEWS.
 *
 * Owner: "ค่าคอมมิชชั่นมันกระจายอยู่หลายเมนูมาก ผมอยากรวมเป็น Menu ที่เดียว".
 *
 * Two screens in two different pillars used to show the same ledger: this one
 * grouped by agent with the bank details and the CSV, and /commission as flat
 * rows with the only button that marks anything paid. A payout run needed
 * both. They are one screen now.
 *
 * What is pinned here is the part a refactor loses silently: the deep link.
 * The dashboard's "ค่าคอมที่จ่ายให้ตัวแทนแล้ว" card still points at
 * /commission?tab=paid, and that link means "show me the paid LEDGER ROWS" —
 * so it has to land on the row view, not on the per-agent default.
 */
describe('the two views of one payout screen', () => {
  it('opens on the per-agent view, because that is what a payout run needs', async () => {
    const wrapper = await mountView([makeRow()])

    expect(wrapper.get('[data-test="payout-view-agents"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('commission-ledger-panel-stub').exists()).toBe(false)
  })

  it('switches to the row view without leaving the page', async () => {
    const wrapper = await mountView([makeRow()])

    await wrapper.get('[data-test="payout-view-entries"]').trigger('click')

    expect(wrapper.find('commission-ledger-panel-stub').exists()).toBe(true)
  })
})

/**
 * 2026-09-15 — "ตั้งจ่าย" (แนวทาง C).
 *
 * Owner: "ระบบเราใช้วิธีโอนเองผ่านระบบการทำงาน Bank เราไม่ได้ Payment auto ซึ่ง
 * ต้องได้รับข้อมูลจากฝ่ายบัญชีก่อนว่าโอนแล้วจึงมากดยืนยัน."
 *
 * The button that stood here for one day settled the commission rows on the
 * press and emailed the agent that their money had arrived — days before
 * accounting actually sent it, and permanently (BR-4). It now RAISES a payout
 * into the รอบจ่าย queue instead and touches no ledger row.
 *
 * So these tests are mostly about what the press does NOT do. A screen that
 * still talks about settling, or that still posts to the endpoint which
 * settles, would be the same defect with a new label.
 */
describe('ตั้งจ่าย', () => {
  it('does not write on the first press', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1 })])

    await wrapper.get('[data-test="pay-all-1"]').trigger('click')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="pay-all-confirm-1"]').exists()).toBe(true)
  })

  it('names the amount, and says plainly that nothing is settled yet', async () => {
    /*
     * The old copy here read "บันทึกว่าโอนเงินแล้ว … แก้ไขย้อนหลังไม่ได้",
     * which was true of the button this replaced and is now the opposite of
     * what happens. Wrong-but-reassuring copy on a money button is worse than
     * none.
     */
    const wrapper = await mountView([makeRow({ agent_id: 1, agent_name: 'สมชาย' })])

    await wrapper.get('[data-test="pay-all-1"]').trigger('click')

    const confirm = wrapper.get('[data-test="pay-all-confirm-1"]').text()
    expect(confirm).toContain('สมชาย')
    expect(confirm).toContain('2,000 บาท')
    expect(confirm).toContain('ยังไม่ปิดรายการค่าคอม')
    expect(confirm).toContain('รอบจ่าย')
    expect(confirm).not.toContain('แก้ไขย้อนหลังไม่ได้')
  })

  it('raises a payout instead of settling the ledger', async () => {
    /*
     * THE ONE THAT MATTERS. /commission-ledger/mark-paid closes the rows on
     * the spot; /commission-withdrawals/payout puts them in a queue that
     * waits for the bank. Posting to the wrong one would restore the exact
     * behaviour แนวทาง C exists to remove, with the new wording on top of it.
     */
    const wrapper = await mountView([makeRow({ agent_id: 42 })])

    await wrapper.get('[data-test="pay-all-42"]').trigger('click')
    await wrapper.get('[data-test="pay-all-submit-42"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/payout', {
      agent_id: 42,
      expected_total_satang: TWO_THOUSAND_BAHT,
    })
  })

  it('sends the total that was on screen, so the server can refuse a stale press', async () => {
    // Between the page loading and the press, a sale can complete. Sending
    // the figure the admin actually saw is what lets the server refuse rather
    // than raise a payout larger than the one authorised.
    const wrapper = await mountView([makeRow({ agent_id: 42, total_pending_satang: 123_400 })])

    await wrapper.get('[data-test="pay-all-42"]').trigger('click')
    await wrapper.get('[data-test="pay-all-submit-42"]').trigger('click')
    await flushPromises()

    expect(post.mock.calls[0]?.[1]).toMatchObject({ expected_total_satang: 123_400 })
  })

  it('reports what the server raised, and points at where it went', async () => {
    post.mockResolvedValue({ data: { id: 9, amount_satang: 987_600 } })
    const wrapper = await mountView([makeRow({ agent_id: 42 })])

    await wrapper.get('[data-test="pay-all-42"]').trigger('click')
    await wrapper.get('[data-test="pay-all-submit-42"]').trigger('click')
    await flushPromises()

    const done = wrapper.get('[data-test="pay-all-done-42"]').text()
    expect(done).toContain('9,876 บาท')
    expect(done).toContain('รอบจ่าย')
  })

  it("shows the server's own refusal, because it is the one that says what to do", async () => {
    post.mockRejectedValue(new FakeApiError(422, {
      errors: { expected_total_satang: ['ยอดค้างจ่ายของคนนี้เปลี่ยนไปแล้ว — กรุณารีเฟรชหน้าจอแล้วลองใหม่'] },
    }))
    const wrapper = await mountView([makeRow({ agent_id: 42 })])

    await wrapper.get('[data-test="pay-all-42"]').trigger('click')
    await wrapper.get('[data-test="pay-all-submit-42"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="pay-all-error-42"]').text()).toContain('กรุณารีเฟรชหน้าจอ')
    // Still open, so the reader can act on what they were just told.
    expect(wrapper.find('[data-test="pay-all-confirm-42"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="pay-all-done-42"]').exists()).toBe(false)
  })

  it('goes away under a date filter, and says why', async () => {
    /*
     * A payout is raised for the agent's WHOLE outstanding balance. Under a
     * date filter the figure on the row is a slice of it, so a button there
     * would either pay a different number from the one beside it or have to
     * explain itself after the press.
     */
    const wrapper = await mountView([makeRow({ agent_id: 1 })])

    const dates = wrapper.findComponent(DateRangeFilter)
    dates.vm.$emit('update:dateFrom', '2026-01-01')
    await flushPromises()

    expect(wrapper.find('[data-test="pay-all-1"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payout-date-filter-note"]').text()).toContain('ยอดค้างทั้งหมด')
  })

  it('is not offered when nothing is owed', async () => {
    const wrapper = await mountView([makeRow({ agent_id: 1, total_pending_satang: 0 })])

    expect(wrapper.find('[data-test="pay-all-1"]').exists()).toBe(false)
  })

  it('is not offered when the filter excluded the pending bucket', async () => {
    // §3.7 (F-10) again, with money attached: null is "nobody measured this".
    const wrapper = await mountView([makeRow({ agent_id: 1, total_pending_satang: null })])

    expect(wrapper.find('[data-test="pay-all-1"]').exists()).toBe(false)
  })

  it("is not offered on the company's own share", async () => {
    // That money is already with the company; there is no transfer to raise.
    const wrapper = await mountView([
      makeRow({ agent_id: 90, agent_name: 'Thai Life insurance', is_company_share: true }),
    ])

    expect(wrapper.find('[data-test="pay-all-90"]').exists()).toBe(false)
  })
})
