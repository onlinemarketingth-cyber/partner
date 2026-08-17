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
    put: (...args: unknown[]) => put(...args),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import AgentCommissionSummaryView from '../AgentCommissionSummaryView.vue'

// ── Fixtures ────────────────────────────────────────────────────────────
// BR-3 — the API sends integer satang. 1,500.00 THB = 150_000 satang.
const FIFTEEN_HUNDRED_BAHT = 150_000
const TWO_THOUSAND_BAHT = 200_000

interface RowOverrides {
  agent_id?: number
  agent_name?: string
  total_paid_satang?: number | null
  total_pending_satang?: number | null
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
  const wrapper = mount(AgentCommissionSummaryView)
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
