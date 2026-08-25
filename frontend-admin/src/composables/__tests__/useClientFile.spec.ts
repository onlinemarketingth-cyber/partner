/**
 * The one-glance answer: stage, paid, waiting on whom.
 *
 * ── WHY THIS IS TESTED AND THE LAYOUT IS NOT ──
 *
 * "ผมเช็คได้ยังไงว่าลูกค้าคนนี้อยู่ในสถานะใด จ่ายเงินหรือยัง รอทำอะไร"
 * (2026-08-22). The answer is a derivation, not a component: four payment
 * states, each changing what a person actually does next. Every one of them
 * fails silently — a wrong badge is still a badge, and the admin acts on it.
 *
 * The three that matter most:
 *
 * 1. `undefined` MUST NOT READ AS "NOT PAID". An absent `order` key means
 *    the endpoint did not load orders — which is the exact bug this change
 *    fixed. Reporting that as "ยังไม่มีคำสั่งซื้อ" would be a confident wrong
 *    answer, and it would have hidden the original bug forever: the screen
 *    would have looked like it was working.
 *
 * 2. A SLIP IS NOT A PAYMENT. `has_slip` with no `paid_at` means the
 *    customer says they sent money and nobody has checked. Collapsing that
 *    into "paid" is how an unverified transfer gets treated as revenue.
 *
 * 3. THE SLIP OUTRANKS THE PIPELINE'S NEXT STAGE. When a slip is waiting,
 *    "รอ Agent ตรวจสอบสลิป" is the true blocker — the deal cannot advance
 *    until someone looks, whatever the template says comes next.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), put: vi.fn(), download: vi.fn() },
  ApiError: class extends Error {},
}))

import { paymentBadgeClasses, useClientFile, type ClientDetail, type ReferralItem } from '../useClientFile'

function referral(over: Partial<ReferralItem> = {}): ReferralItem {
  return {
    id: 1,
    product: { id: 9, name: 'Vital Blueprint', price_satang: 890000 },
    agent: { id: 3, name: 'เกรียงยศ' },
    branch: 'สาขาหลัก',
    preferred_time: null,
    current_stage: { key: 'complete_registered', label: 'ลงทะเบียนแล้ว' },
    pipeline: { stages: [], next_stage: { key: 'complete_payment', label: 'ชำระเงิน' } },
    meeting_number: null,
    submitted_at: '2026-08-01T00:00:00Z',
    ...over,
  }
}

function clientWith(referrals: ReferralItem[]): ClientDetail {
  return {
    id: 1,
    referring_agent_id: 3,
    name: 'ลูกค้า 2',
    phone: '0926361565',
    email: null,
    national_id_masked: 'x-xxxx-xxxxx-xx-3',
    consent_given_at: null,
    health_notes: null,
    status: { key: 'new', label: 'ใหม่' },
    lead_source: null,
    client_category_id: null,
    date_of_birth: null,
    address: null,
    province: null,
    occupation: null,
    referrals,
    created_at: '2026-08-01T00:00:00Z',
  }
}

function summarise(r: ReferralItem) {
  const file = useClientFile()
  file.client.value = clientWith([r])

  const first = file.referralSummaries.value[0]
  if (!first) throw new Error('Expected one referral summary.')

  return first
}

describe('referralSummaries — payment state', () => {
  it('reports a confirmed payment', () => {
    const s = summarise(
      referral({
        order: {
          id: 5,
          order_number: 'ORD-1',
          status: 'paid',
          status_label: 'ชำระแล้ว',
          amount_satang: 890000,
          public_pay_url: null,
          has_slip: true,
          paid_at: '2026-08-10T03:00:00Z',
          verified_by: { id: 3, name: 'เกรียงยศ' },
        },
      }),
    )

    expect(s.payment).toBe('paid')
    expect(s.amountSatang).toBe(890000)
  })

  it('treats an attached slip as a CLAIM, not as money in', () => {
    const s = summarise(
      referral({
        order: {
          id: 5,
          order_number: 'ORD-1',
          status: 'awaiting_verification',
          status_label: 'รอตรวจสอบ',
          amount_satang: 890000,
          public_pay_url: null,
          has_slip: true,
          paid_at: null,
          verified_by: null,
        },
      }),
    )

    expect(s.payment).toBe('checking')
    expect(s.payment).not.toBe('paid')
    // The blocker is a person, and it outranks whatever the pipeline says
    // comes next — the deal cannot move until someone looks at the slip.
    expect(s.waitingOn).toContain('ตรวจสอบสลิป')
  })

  it('says the customer owes money when an order is unpaid with no slip', () => {
    const s = summarise(
      referral({
        order: {
          id: 5,
          order_number: 'ORD-1',
          status: 'pending',
          status_label: 'รอชำระ',
          amount_satang: 890000,
          public_pay_url: null,
          has_slip: false,
          paid_at: null,
          verified_by: null,
        },
      }),
    )

    expect(s.payment).toBe('awaiting')
    expect(s.waitingOn).toContain('ลูกค้าชำระเงิน')
  })

  it('distinguishes "no order yet" from "we did not ask"', () => {
    // THE ONE THAT HID THE ORIGINAL BUG. null = asked, none exists.
    // undefined = the endpoint never loaded orders, which must never be
    // reported as a payment fact.
    const noOrder = summarise(referral({ order: null }))
    const notLoaded = summarise(referral({ order: undefined }))

    expect(noOrder.payment).toBe('no_order')
    expect(noOrder.paymentLabel).toBe('ยังไม่มีคำสั่งซื้อ')

    expect(notLoaded.payment).toBe('no_order')
    expect(notLoaded.paymentLabel).toContain('ไม่ทราบ')
    expect(notLoaded.paymentLabel).not.toBe(noOrder.paymentLabel)
  })

  it('falls back to the product price when there is no order to price it', () => {
    expect(summarise(referral({ order: null })).amountSatang).toBe(890000)
  })
})

describe('referralSummaries — what is being waited on', () => {
  it('names the next pipeline stage when payment is not the blocker', () => {
    const s = summarise(referral({ order: null }))

    expect(s.nextLabel).toBe('ชำระเงิน')
    expect(s.waitingOn).toContain('ชำระเงิน')
  })

  it('says the journey is finished when there is no next stage', () => {
    const s = summarise(referral({ order: null, pipeline: { stages: [], next_stage: null } }))

    expect(s.nextLabel).toBeNull()
    expect(s.waitingOn).toContain('จบขั้นตอน')
  })

  it('does not invent a next step when the pipeline is absent', () => {
    // Same rule as the order key: an absent pipeline is "no answer", never a
    // fabricated one.
    const s = summarise(referral({ order: null, pipeline: undefined }))

    expect(s.nextLabel).toBeNull()
  })
})

describe('paymentBadgeClasses', () => {
  it('never paints an unverified slip green', () => {
    // The colour IS the claim. Amber says "someone must look at this";
    // emerald says "settled, nothing to do".
    expect(paymentBadgeClasses('checking')).toContain('amber')
    expect(paymentBadgeClasses('checking')).not.toContain('emerald')
    expect(paymentBadgeClasses('paid')).toContain('emerald')
  })
})

describe('useClientFile — switching clients', () => {
  it('drops the previous client entirely on reset', () => {
    // The modal reuses one instance across every row the admin clicks. A
    // cached stage-log timeline surviving a switch would show one customer's
    // sales history under another's name.
    const file = useClientFile()
    file.client.value = clientWith([referral()])
    file.stageLogsByReferral.value = { 1: [] }
    file.expandedReferralId.value = 1

    file.reset()

    expect(file.client.value).toBeNull()
    expect(file.stageLogsByReferral.value).toEqual({})
    expect(file.expandedReferralId.value).toBeNull()
    expect(file.referralSummaries.value).toEqual([])
  })
})
