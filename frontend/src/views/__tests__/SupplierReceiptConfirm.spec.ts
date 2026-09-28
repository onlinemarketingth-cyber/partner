/**
 * ADR-048 (2026-09-27) — "ผู้รับกดรับสินค้า".
 *
 * On a deliver-before-pay supplier deal the supplier's money is released when
 * the RECIPIENT confirms the parcel arrived — the selling agent on ออเดอร์ของฉัน
 * or the customer on the link they paid on — not by the supplier's own
 * "shipped" click. These specs pin the two buttons: shown only when the server
 * says the order can be confirmed, asking before acting, and calling the
 * right endpoint.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
    getBlob: vi.fn(),
  },
  ApiError: class extends Error {
    constructor(readonly status: number = 422) {
      super(`api ${status}`)
    }
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { token: 'TOKEN1234567890' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('qrcode', () => ({ default: { toDataURL: async () => 'data:image/png;base64,' } }))
vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: async () => '' }))

import OrdersView from '../OrdersView.vue'
import PaymentPageView from '../PaymentPageView.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
})

/* ── The agent ─────────────────────────────────────────────────────────── */

const SHIPPED = {
  id: 9,
  order_number: 'ORD-SHIP0001',
  status: 'paid',
  status_label: 'ชำระแล้ว',
  amount_satang: 100000,
  amount_baht: 1000,
  product_name: 'สินค้าคู่ค้า',
  client_name: 'ลูกค้า',
  has_slip: false,
  public_pay_url: 'https://x/pay/abc',
  short_pay_url: null,
  paid_at: '2026-09-20T09:00:00Z',
  created_at: '2026-09-20T08:00:00Z',
  requires_shipping: true,
  shipping_status: 'shipped',
  tracking_number: 'TH123',
  received_at: null,
  receipt_confirmed_via: null,
  permissions: { confirm_receipt: true },
}

async function mountOrders(overrides: Record<string, unknown> = {}) {
  get.mockImplementation(async () => ({ data: [{ ...SHIPPED, ...overrides }] }))
  const wrapper = mount(OrdersView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        SlipViewerModal: true,
        ShareLinkModal: true,
        AppButton: { template: '<button v-bind="$attrs"><slot /></button>' },
        AppSelect: true,
        AppInput: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

describe('the selling agent', () => {
  it('sees the tracking number and a receipt button once the supplier has shipped', async () => {
    const wrapper = await mountOrders()

    expect(wrapper.find('[data-test="shipping-row"]').text()).toContain('TH123')
    expect(wrapper.find('[data-test="confirm-receipt"]').exists()).toBe(true)
  })

  it('is asked before anything is sent, then confirms through the order endpoint', async () => {
    post.mockResolvedValue({ data: {} })
    const wrapper = await mountOrders()

    await wrapper.find('[data-test="confirm-receipt"]').trigger('click')
    await flushPromises()
    expect(post).not.toHaveBeenCalled()

    // The real ConfirmDialog: its second button confirms.
    const buttons = wrapper.find('.fixed.inset-0').findAll('button')
    await buttons[buttons.length - 1]!.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/orders/9/receipt')
  })

  it('gets no button when the server says this order cannot be confirmed by them', async () => {
    const wrapper = await mountOrders({ permissions: { confirm_receipt: false } })

    expect(wrapper.find('[data-test="confirm-receipt"]').exists()).toBe(false)
  })

  it('reads who confirmed once it is done', async () => {
    const wrapper = await mountOrders({
      shipping_status: 'delivered',
      received_at: '2026-09-25T09:00:00Z',
      receipt_confirmed_via: 'auto',
      permissions: { confirm_receipt: false },
    })

    expect(wrapper.find('[data-test="received-note"]').text()).toContain('ระบบยืนยันรับสินค้าให้อัตโนมัติ')
  })
})

/* ── The customer ─────────────────────────────────────────────────────── */

function paidPayload(overrides: Record<string, unknown> = {}) {
  return {
    order_number: 'ORD-SHIP0001',
    product_name: 'สินค้าคู่ค้า',
    amount_baht: 1000,
    amount_satang: 100000,
    status: 'paid',
    payment_method: 'bank_transfer',
    requires_shipping: true,
    shipping_status: 'shipped',
    tracking_number: 'TH123',
    received_at: null,
    can_confirm_receipt: true,
    voucher: null,
    promptpay_payload: null,
    company_payment: { bank_name: null, bank_account_name: null, bank_account_number: null, promptpay_id: null },
    gateway: { payment_received: false, intent: null, test_mode: false, last_error: null, last_error_at: null },
    ...overrides,
  }
}

async function mountPay(overrides: Record<string, unknown> = {}) {
  get.mockImplementation(async () => ({ data: paidPayload(overrides) }))
  const wrapper = mount(PaymentPageView, { global: { stubs: { Icon: true, AppLogo: true } } })
  await flushPromises()

  return wrapper
}

describe('the customer on the pay link', () => {
  it('confirms in two taps, not one', async () => {
    post.mockResolvedValue({ data: paidPayload({ can_confirm_receipt: false, received_at: '2026-09-25T09:00:00Z', shipping_status: 'delivered' }) })
    const wrapper = await mountPay()

    expect(wrapper.find('[data-test="shipping-status"]').text()).toContain('TH123')
    await wrapper.find('[data-test="confirm-receipt"]').trigger('click')
    expect(post).not.toHaveBeenCalled()

    await wrapper.find('[data-test="confirm-receipt-yes"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/pay/TOKEN1234567890/receipt')
    expect(wrapper.find('[data-test="received-note"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="confirm-receipt"]').exists()).toBe(false)
  })

  it('has no button before the supplier ships', async () => {
    const wrapper = await mountPay({ shipping_status: 'pending', tracking_number: null, can_confirm_receipt: false })

    expect(wrapper.find('[data-test="confirm-receipt"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="shipping-status"]').text()).toContain('กำลังเตรียมจัดส่ง')
  })
})
