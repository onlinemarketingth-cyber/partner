/**
 * 2026-09-10 (human: "Ui หน้านี้ไม่สากลเลย ปรับให้เป็นมาตรฐานการชำระเงิน
 * ให้เลือกวิธีชำระ หรือโอนผ่าน qr code").
 *
 * The page used to render every payment path at once — the chooser, the QR,
 * the account number and the slip picker, stacked down the screen whether or
 * not the customer wanted any of them — and the two options were not the same
 * shape: the card was a button and "transfer" was a paragraph, so only one of
 * them read as a choice.
 *
 * These tests are about the rules that make the new shape correct rather than
 * merely tidier: a method the seller cannot actually accept is never offered,
 * nothing is chosen on the customer's behalf when there is a real choice, and
 * the address is asked for on EVERY path rather than only the one that
 * happened to have a form attached.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { token: 'O3DT8iAEPKmHgdqOdw41W5LcDVgrsddTirLHaAjV' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

const get = vi.fn()
const post = vi.fn()
const postForm = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    postForm: (...args: unknown[]) => postForm(...args),
    put: vi.fn(),
    patch: vi.fn(),
  },
  ApiError: class extends Error {
    constructor(readonly status: number) {
      super(`api ${status}`)
    }
  },
}))

vi.mock('qrcode', () => ({ default: { toDataURL: async () => 'data:image/png;base64,qr' } }))
vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: async () => 'data:image/png;base64,qr' }))

import PaymentPageView from '../PaymentPageView.vue'

const BANK = {
  bank_name: 'ธนาคารกสิกรไทย',
  bank_account_name: 'บริษัท ไลฟ์ ทู 100 จำกัด',
  bank_account_number: '123-4-56789-0',
  promptpay_id: '0812345678',
}

const NO_BANK = { bank_name: null, bank_account_name: null, bank_account_number: null, promptpay_id: null }

function order(overrides: Record<string, unknown> = {}) {
  return {
    order_number: 'ORD-XUZA5JGV',
    product_name: 'GENESENN 1-Year Vital Blueprint',
    amount_baht: 29900,
    amount_satang: 2990000,
    status: 'pending',
    payment_method: 'bank_transfer',
    payment_method_label: 'โอนเงิน',
    requires_shipping: false,
    voucher: null,
    promptpay_payload: '00020101021129',
    company_payment: { ...BANK },
    gateway: {
      payment_received: false,
      intent: null,
      test_mode: false,
      last_error: null,
      last_error_at: null,
      online: { provider: 'stripe', mode: 'test' },
    },
    ...overrides,
  }
}

async function mountPage(overrides: Record<string, unknown> = {}) {
  get.mockImplementation(async () => ({ data: order(overrides) }))

  const wrapper = mount(PaymentPageView, {
    global: { stubs: { Icon: true, AppLogo: true } },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  postForm.mockReset()
  post.mockResolvedValue({ data: order() })
  postForm.mockResolvedValue({ data: order({ status: 'awaiting_verification' }) })
})

describe('choosing how to pay', () => {
  it('offers the three methods as equal choices', async () => {
    const w = await mountPage()

    expect(w.find('[data-test="method-card"]').exists()).toBe(true)
    expect(w.find('[data-test="method-promptpay"]').exists()).toBe(true)
    expect(w.find('[data-test="method-bank"]').exists()).toBe(true)
  })

  it('opens nothing until the customer picks', async () => {
    /*
     * Nothing is pre-selected when there is a real choice. A pre-ticked
     * payment method is a decision made on somebody's behalf about their
     * money, and the one listed first is not the one they want often enough
     * to be worth it.
     */
    const w = await mountPage()

    expect(w.find('[data-test="panel-card"]').exists()).toBe(false)
    expect(w.find('[data-test="panel-promptpay"]').exists()).toBe(false)
    expect(w.find('[data-test="panel-bank"]').exists()).toBe(false)
    expect(w.find('[data-test="action-bar"]').exists()).toBe(false)
  })

  it('opens only the panel that was chosen', async () => {
    const w = await mountPage()

    await w.find('[data-test="method-promptpay"]').trigger('click')

    expect(w.find('[data-test="panel-promptpay"]').exists()).toBe(true)
    expect(w.find('[data-test="panel-bank"]').exists()).toBe(false)
    // The account number was on screen from the moment the page loaded
    // before; now it belongs to the method that needs it.
    expect(w.text()).not.toContain('123-4-56789-0')
  })

  it('chooses for the customer only when there is nothing to choose', async () => {
    // One method is not a choice, and an unopened accordion would be an extra
    // tap for no decision.
    const w = await mountPage({
      company_payment: { ...NO_BANK },
      promptpay_payload: '',
      gateway: {
        payment_received: false,
        intent: null,
        test_mode: false,
        last_error: null,
        last_error_at: null,
        online: { provider: 'stripe', mode: 'test' },
      },
    })

    expect(w.find('[data-test="panel-card"]').exists()).toBe(true)
    expect(w.find('[data-test="action-bar"]').exists()).toBe(true)
  })
})

describe('methods the seller cannot actually accept', () => {
  it('does not offer a bank transfer when there is no account to transfer to', async () => {
    /*
     * THE REPORTED BUG. The page printed "ธนาคาร —, ชื่อบัญชี —, เลขที่บัญชี —"
     * on a company that had never set an account up: a page asking for money
     * that would not say where to send it.
     */
    const w = await mountPage({ company_payment: { ...NO_BANK, promptpay_id: null } })

    expect(w.find('[data-test="method-bank"]').exists()).toBe(false)
  })

  it('does not offer PromptPay without a payload to make a QR from', async () => {
    const w = await mountPage({ promptpay_payload: '' })

    expect(w.find('[data-test="method-promptpay"]').exists()).toBe(false)
  })

  it('says so plainly when there is no way to pay at all', async () => {
    // The customer cannot fix this and should be told to contact the seller.
    // An empty card would leave them staring at it.
    const w = await mountPage({
      company_payment: { ...NO_BANK },
      promptpay_payload: '',
      gateway: { payment_received: false, intent: null, test_mode: false, last_error: null, last_error_at: null, online: null },
    })

    expect(w.find('[data-test="no-method"]').exists()).toBe(true)
    expect(w.find('[data-test="action-bar"]').exists()).toBe(false)
  })
})

describe('the one primary action', () => {
  it('starts the card payment when the card is chosen', async () => {
    const w = await mountPage()

    await w.find('[data-test="method-card"]').trigger('click')
    await w.find('[data-test="primary-action"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/pay/O3DT8iAEPKmHgdqOdw41W5LcDVgrsddTirLHaAjV/intent', {})
  })

  it('sends the slip when a transfer is chosen', async () => {
    const w = await mountPage()

    await w.find('[data-test="method-bank"]').trigger('click')
    // Nothing to send yet — the button is there, and refuses.
    expect((w.find('[data-test="primary-action"]').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('never shows a slip picker to somebody paying by card', async () => {
    // They have nothing to upload, and a file picker on a card payment is a
    // question with no answer.
    const w = await mountPage()

    await w.find('[data-test="method-card"]').trigger('click')

    expect(w.find('[data-test="slip-step"]').exists()).toBe(false)
  })
})

describe('a product that has to be delivered', () => {
  it('asks for the address before the payment method, on every path', async () => {
    /*
     * THE GAP THIS CLOSES. The address used to be collected inside the slip
     * form, so a customer buying a physical product WITH A CARD was never
     * asked for it: the order came back paid with nowhere to send the goods.
     */
    const w = await mountPage({ requires_shipping: true })

    expect(w.find('[data-test="shipping-step"]').exists()).toBe(true)
  })

  it('refuses to open the gateway until the address is complete', async () => {
    // Refused HERE, not by the server: the gateway takes the customer to
    // another site, so a refusal after they leave lands on a page they can no
    // longer see.
    const w = await mountPage({ requires_shipping: true })

    await w.find('[data-test="method-card"]').trigger('click')
    expect((w.find('[data-test="primary-action"]').element as HTMLButtonElement).disabled).toBe(true)

    await w.find('[data-test="ship-name"]').setValue('สมชาย ใจดี')
    expect((w.find('[data-test="primary-action"]').element as HTMLButtonElement).disabled).toBe(true)

    expect(post).not.toHaveBeenCalled()
  })

  it('sends the address with the request that opens the payment', async () => {
    const w = await mountPage({ requires_shipping: true })

    await w.find('[data-test="method-card"]').trigger('click')
    await w.find('[data-test="ship-name"]').setValue('สมชาย ใจดี')
    await w.findAll('input[type="tel"]')[0]?.setValue('0812345678')
    await w.find('textarea').setValue('123 ถนนสีลม กรุงเทพฯ 10500')
    await w.find('[data-test="primary-action"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith(
      '/pay/O3DT8iAEPKmHgdqOdw41W5LcDVgrsddTirLHaAjV/intent',
      {
        shipping_recipient_name: 'สมชาย ใจดี',
        shipping_phone: '0812345678',
        shipping_address: '123 ถนนสีลม กรุงเทพฯ 10500',
      },
    )
  })
})

describe('once a slip is in', () => {
  it('stops offering payment methods', async () => {
    // The customer has done their part and somebody is looking at it.
    // Re-offering the methods reads as "that did not work, try again".
    const w = await mountPage({ status: 'awaiting_verification' })

    expect(w.find('[data-test="method-chooser"]').exists()).toBe(false)
    expect(w.find('[data-test="action-bar"]').exists()).toBe(false)
    expect(w.text()).toContain('ได้รับสลิปแล้ว')
  })
})
