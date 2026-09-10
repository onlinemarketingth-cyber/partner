/**
 * 2026-09-10 — two faults reported from one real card payment
 * (apps.liveto100club.com/pay/…?stripe=success):
 *
 *   1. "ผมชำระเงินผ่านบัตรเครดิต เลข stripe ที่โอนเงินไม่ควรแสดง ควรแสดง
 *      เฉพาะโอนเงิน" — the money was already in, and the page was still
 *      showing a bank account to transfer to. Under a notice begging them
 *      not to pay twice.
 *
 *   2. "เมื่อชำระแล้วไม่มีปุ่มไปไหนเลย ต้องมีปุ่มกลับหน้า Frontend ตั้งเวลา
 *      90 วินาทีกลับอัตโนมัติ" — nothing to press. The last screen of a
 *      purchase was a dead end whose only exit was the browser's back
 *      button, which leads back to the payment provider.
 *
 * Both are pinned from the state they were reported in: `payment_received`
 * — charged, order not yet marked paid — which is the window a customer
 * actually sits in and stares at.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

/*
 * `query` is part of this mock as of 2026-09-10: the page now reads
 * `?stripe=cancelled` to notice a customer who came back from the gateway
 * without paying. A route stub without it throws inside a computed, which
 * surfaces as the whole page stuck on "กำลังโหลด" rather than as a missing
 * property — worth a note, because that is a confusing way to fail.
 */
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { token: 'O3DT8iAEPKmHgdqOdw41W5LcDVgrsddTirLHaAjV' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: class extends Error {
    constructor(readonly status: number) {
      super(`api ${status}`)
    }
  },
}))

// The QR renderer touches canvas, which jsdom does not have. The QR is not
// what this file is about.
vi.mock('qrcode', () => ({ default: { toDataURL: async () => 'data:image/png;base64,' } }))
vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: async () => '' }))

import PaymentPageView from '../PaymentPageView.vue'

/**
 * An order as the server sends it right after a successful card charge:
 * money in, confirmation pending, and — importantly — the company's bank
 * details still on the payload, because they are a property of the company,
 * not of how this particular customer paid.
 */
function orderPayload(overrides: Record<string, unknown> = {}) {
  return {
    order_number: 'ORD-MWJTV2QV',
    product_name: 'GENESENN Health Tracker V5 Vital Blueprint',
    amount_baht: 8900,
    amount_satang: 890000,
    status: 'awaiting_verification',
    payment_method: 'bank_transfer',
    requires_shipping: false,
    voucher: null,
    promptpay_payload: null,
    company_payment: {
      bank_name: 'ธนาคารกสิกรไทย',
      bank_account_name: 'บริษัท ไลฟ์ ทู 100 จำกัด',
      bank_account_number: '123-4-56789-0',
      promptpay_id: null,
    },
    gateway: { payment_received: true, intent: null, test_mode: false, last_error: null, last_error_at: null },
    ...overrides,
  }
}

async function mountPage(overrides: Record<string, unknown> = {}) {
  get.mockImplementation(async () => ({ data: orderPayload(overrides) }))

  const wrapper = mount(PaymentPageView, {
    global: { stubs: { Icon: true, AppLogo: true } },
  })
  await flushPromises()

  return wrapper
}

describe('after the money is in', () => {
  beforeEach(() => {
    // The page reads the tenant theme store on setup; same as every other
    // view spec in this app.
    setActivePinia(createPinia())
    vi.useRealTimers()
    get.mockReset()
  })

  it('stops showing an account number to transfer to', async () => {
    /*
     * The exact complaint. A customer who has just been charged, reading a
     * notice that says "do not pay again", with a bank account printed
     * directly underneath it.
     */
    const w = await mountPage()

    expect(w.text()).toContain('ได้รับการชำระเงินของคุณแล้ว')
    expect(w.text()).not.toContain('123-4-56789-0')
  })

  it('still shows the account number when nobody has paid yet', async () => {
    /*
     * The other half of the same rule — and the case that must not regress,
     * because bank transfer is how most of this platform's customers pay.
     *
     * 2026-09-10: it is now one tap in. The page opens on a list of methods
     * and only the chosen one expands, so the account number is behind
     * "โอนเข้าบัญชีธนาคาร" rather than printed on arrival.
     */
    const w = await mountPage({
      status: 'pending',
      gateway: { payment_received: false, intent: null, test_mode: false, last_error: null, last_error_at: null },
    })

    await w.find('[data-test="method-bank"]').trigger('click')

    expect(w.text()).toContain('123-4-56789-0')
  })

  it('offers a way off the page', async () => {
    const w = await mountPage()

    expect(w.find('[data-test="back-home"]').exists()).toBe(true)
  })

  it('counts down, and leaves on its own after 90 seconds', async () => {
    vi.useFakeTimers()
    const assign = vi.fn()
    // jsdom refuses a real navigation; the call is what matters.
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, assign },
    })

    const w = await mountPage()

    expect(w.find('[data-test="auto-return-countdown"]').text()).toContain('90')

    await vi.advanceTimersByTimeAsync(89_000)
    expect(assign).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(2_000)
    expect(assign).toHaveBeenCalledWith('/')
  })

  it('stops the countdown the moment the customer touches the page', async () => {
    /*
     * A paid order can carry a voucher — a redemption code and a QR the
     * customer is meant to keep. Pulling that off the screen mid-photograph
     * would be a worse failure than the dead end this fixes, so the timer
     * yields to any sign of a person being there.
     */
    vi.useFakeTimers()
    const assign = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, assign },
    })

    const w = await mountPage()

    window.dispatchEvent(new Event('pointerdown'))
    await flushPromises()

    await vi.advanceTimersByTimeAsync(120_000)

    expect(assign).not.toHaveBeenCalled()
    expect(w.find('[data-test="auto-return-countdown"]').exists()).toBe(false)
    expect(w.find('[data-test="back-home"]').exists()).toBe(true)
  })

  /**
   * 2026-09-10 (human: "หลังจากชำระเงินสำเร็จใน frontend แล้ว ลูกค้าจะได้รหัส
   * ยืนยันใช้บริการได้อย่างไร").
   *
   * The voucher is minted when staff CONFIRM the payment, not when the money
   * lands (ADR-033 §2.2/B1). So there is a real window — this one — in which
   * the customer has paid and there genuinely is no code yet. What goes in
   * that gap decides whether they wait or start phoning.
   */
  it('says where the code will appear, in the window where there is not one yet', async () => {
    const w = await mountPage()

    const note = w.find('[data-test="voucher-pending-note"]')
    expect(note.exists()).toBe(true)
    expect(note.text()).toContain('รหัสเข้ารับบริการ')
  })

  it('does not say it on an order nobody has paid', async () => {
    // Before payment the sentence would be describing something that has not
    // been bought.
    const w = await mountPage({ gateway: { payment_received: false, intent: null, test_mode: false, last_error: null, last_error_at: null } })

    expect(w.find('[data-test="voucher-pending-note"]').exists()).toBe(false)
  })

  it('leaves an unpaid order alone', async () => {
    // No countdown, no exit button: this customer still has something to do
    // here, and a page that navigated away from a half-finished payment
    // would be a new bug rather than a fix.
    const w = await mountPage({ gateway: { payment_received: false, intent: null, test_mode: false, last_error: null, last_error_at: null } })

    expect(w.find('[data-test="back-home"]').exists()).toBe(false)
    expect(w.find('[data-test="auto-return-countdown"]').exists()).toBe(false)
  })
})

/**
 * 2026-09-10 (human: "Admin ที่ใช้บัตร voucher นั้นต้องใช้วิธี Key
 * ทำให้รหัสสั้นลงไม่เกิน 6 ตัวได้หรือไม่").
 *
 * The code used to be forty characters, which forced this page to render it as
 * small wrapped monospace — legible only as something to scan, never as
 * something to read out. Six characters lets it be what it actually is: the
 * thing the customer shows at the counter.
 */
describe('the voucher code on the paid screen', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useRealTimers()
    get.mockReset()
  })

  const voucher = (code: string) => ({
    status: 'paid',
    voucher: {
      code,
      status: 'active',
      status_label: 'ใช้ได้',
      usage_quota: 1,
      used_count: 0,
      quota_remaining: 1,
      expires_at: null,
    },
  })

  it('prints a short code grouped, so it can be copied in one glance', async () => {
    const w = await mountPage(voucher('AB1234'))

    expect(w.find('[data-test="voucher-code"]').text()).toBe('AB1-234')
  })

  it('leaves a code issued before the change exactly as it was', async () => {
    // Nothing rewrites the codes already out there, and a 40-character token
    // cut into groups of three is less readable, not more.
    const legacy = 'a1B2'.repeat(10)
    const w = await mountPage(voucher(legacy))

    expect(w.find('[data-test="voucher-code"]').text()).toBe(legacy)
  })
})

/**
 * 2026-09-10 (human, testing with Stripe's card_declined number
 * 4000000000000002: "ผมทดสอบ stripe แบบ card_declined ให้ผิด แต่หน้า frontend
 * ยังขึ้นให้บัตรอยู่").
 *
 * The refusal was recorded and the agent was told; the customer — the only
 * person who can do anything about it — was told nothing. They came back to a
 * page identical to the one they left, still offering the card button, so the
 * obvious next move was to try the same card again.
 */
describe('after a card is refused', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useRealTimers()
    get.mockReset()
  })

  const declined = (over: Record<string, unknown> = {}) => ({
    status: 'pending',
    gateway: {
      payment_received: false,
      intent: null,
      test_mode: false,
      last_error: 'บัตรถูกปฏิเสธโดยธนาคารผู้ออกบัตร',
      last_error_at: '2026-09-10T09:00:00Z',
    },
    ...over,
  })

  it('tells the customer, in the words the gateway used', async () => {
    const w = await mountPage(declined())

    expect(w.find('[data-test="payment-failed-notice"]').exists()).toBe(true)
    expect(w.find('[data-test="payment-failed-reason"]').text()).toContain('บัตรถูกปฏิเสธ')
  })

  it('says the order is still unpaid and names the other way to pay', async () => {
    // A refusal with no way forward is just bad news. Bank transfer is on the
    // same page and works for everyone.
    const w = await mountPage(declined())

    expect(w.find('[data-test="payment-failed-notice"]').text()).toContain('ยังไม่ได้ชำระเงิน')
  })

  it('never shows a stale refusal over a paid order', async () => {
    /*
     * THE ONE THAT MATTERS. A failed attempt followed by a successful one
     * leaves the old message on the row. "Your card was declined" printed
     * above a paid order and a voucher is worse than saying nothing.
     */
    const w = await mountPage(declined({
      status: 'paid',
      gateway: {
        payment_received: true,
        intent: null,
        test_mode: false,
        last_error: 'บัตรถูกปฏิเสธโดยธนาคารผู้ออกบัตร',
        last_error_at: '2026-09-10T09:00:00Z',
      },
    }))

    expect(w.find('[data-test="payment-failed-notice"]').exists()).toBe(false)
  })

  it('stays quiet on an order nobody has tried to pay', async () => {
    const w = await mountPage({ status: 'pending', gateway: { payment_received: false, intent: null, test_mode: false, last_error: null, last_error_at: null } })

    expect(w.find('[data-test="payment-failed-notice"]').exists()).toBe(false)
  })
})
