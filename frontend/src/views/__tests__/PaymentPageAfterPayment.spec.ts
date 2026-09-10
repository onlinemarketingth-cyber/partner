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

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { token: 'O3DT8iAEPKmHgdqOdw41W5LcDVgrsddTirLHaAjV' } }),
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
    gateway: { payment_received: true, intent: null, test_mode: false },
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
    // The other half of the same rule — and the case that must not regress,
    // because bank transfer is how most of this platform's customers pay.
    const w = await mountPage({ gateway: { payment_received: false, intent: null, test_mode: false } })

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

  it('leaves an unpaid order alone', async () => {
    // No countdown, no exit button: this customer still has something to do
    // here, and a page that navigated away from a half-finished payment
    // would be a new bug rather than a fix.
    const w = await mountPage({ gateway: { payment_received: false, intent: null, test_mode: false } })

    expect(w.find('[data-test="back-home"]').exists()).toBe(false)
    expect(w.find('[data-test="auto-return-countdown"]').exists()).toBe(false)
  })
})
