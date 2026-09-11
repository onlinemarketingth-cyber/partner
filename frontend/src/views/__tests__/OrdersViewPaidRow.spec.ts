/**
 * 2026-09-11 (human, looking at their own orders list: "สถานะชำระสำเร็จแล้ว
 * ไม่ควรมีลิงก์ชำระเงินอีกครั้ง").
 *
 * Every row printed the pay link and the "แชร์ลิงก์ชำระเงิน" button, on order
 * status ALONE — the same shape of bug as the confirm button this screen lost
 * a day earlier (OrdersViewNoSelfConfirm.spec.ts): a control rendered because
 * the row exists, not because the action makes sense for it.
 *
 * The cost is paid by the customer rather than the agent. The obvious action
 * on a paid order is "share the link", and following it sends somebody who
 * has already paid a page headed ชำระเงิน. They then have to read carefully
 * enough to notice it says paid, having first been made to wonder whether
 * their money went somewhere.
 *
 * So this file, like its neighbour, asserts an ABSENCE — and one control over
 * from it, the presence of something in its place, because a row that merely
 * loses its buttons reads as the app having lost the order.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    download: vi.fn(),
    getBlob: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import OrdersView from '../OrdersView.vue'

const PAY_URL = 'https://apps.liveto100club.com/pay/CqqkQsFlNVFpELU0dJpGea2ACct7Ag99pmsopyxc'

const ORDER = {
  id: 1,
  order_number: 'ORD-AKTWP2YK',
  status: 'pending',
  status_label: 'รอชำระเงิน',
  amount_satang: 59000,
  amount_baht: 590,
  product_name: 'Almod Chips',
  client_name: 'kreangyot ohuyhannapa',
  has_slip: false,
  public_pay_url: PAY_URL,
  short_pay_url: null,
  paid_at: null,
  created_at: '2026-09-08T07:00:00Z',
}

async function mountView(overrides: Record<string, unknown> = {}) {
  get.mockImplementation(async () => ({ data: [{ ...ORDER, ...overrides }] }))

  const wrapper = mount(OrdersView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        ConfirmDialog: true,
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

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
})

describe('an order that has been paid', () => {
  it('offers no payment link', async () => {
    const wrapper = await mountView({ status: 'paid', status_label: 'ชำระแล้ว', paid_at: '2026-09-08T09:14:00Z' })

    expect(wrapper.find('[data-test="pay-link-row"]').exists()).toBe(false)
    // Asserted over the URL itself too, not only the row: the point is that
    // the link is not on the page, however it might be rendered later.
    expect(wrapper.html()).not.toContain(PAY_URL)
  })

  it('offers no way to send that link to the customer', async () => {
    // The more expensive half. An agent can copy a URL they can see; the
    // share sheet is the one that puts it in front of the customer.
    const wrapper = await mountView({ status: 'paid', status_label: 'ชำระแล้ว' })

    expect(wrapper.find('[data-test="share-pay-link"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some((b) => b.text().includes('แชร์ลิงก์ชำระเงิน'))).toBe(false)
  })

  it('says the sale is closed, rather than simply going blank', async () => {
    const wrapper = await mountView({ status: 'paid', status_label: 'ชำระแล้ว', paid_at: '2026-09-08T09:14:00Z' })

    expect(wrapper.find('[data-test="paid-note"]').text()).toContain('ชำระเงินเรียบร้อยแล้ว')
  })

  it('claims nothing about an email it cannot know was sent', async () => {
    /*
     * The mailer skips silently when the customer has no address on file or
     * platform mail is off. A note promising "ส่งอีเมลให้ลูกค้าแล้ว" would
     * therefore be a lie the agent repeats to the customer, and the customer
     * would then go looking for an email that does not exist.
     */
    const wrapper = await mountView({ status: 'paid', status_label: 'ชำระแล้ว' })

    expect(wrapper.find('[data-test="paid-note"]').text()).not.toContain('อีเมล')
  })
})

describe('an order that was cancelled', () => {
  it('does not offer a link that no longer works', async () => {
    // The cancel dialog on this very screen says the link stops working.
    // Continuing to offer it afterwards contradicts what the agent was told
    // as they pressed the button.
    const wrapper = await mountView({ status: 'cancelled', status_label: 'ยกเลิก' })

    expect(wrapper.find('[data-test="pay-link-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="paid-note"]').exists()).toBe(false)
  })
})

describe('an order still waiting for money', () => {
  it('keeps the link and the share button exactly as they were', async () => {
    // The control. Without it, this whole file would also pass if the link
    // had been removed from every row.
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="pay-link-row"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="share-pay-link"]').exists()).toBe(true)
    expect(wrapper.html()).toContain(PAY_URL)
  })

  it('keeps it while a slip is being checked, too', async () => {
    /*
     * Deliberately NOT hidden at awaiting_verification. The slip can be
     * rejected, and an agent whose customer needs to pay again would
     * otherwise have no link to send — the state where they most need one.
     */
    const wrapper = await mountView({ status: 'awaiting_verification', status_label: 'รอตรวจสลิป', has_slip: true })

    expect(wrapper.find('[data-test="pay-link-row"]').exists()).toBe(true)
  })
})
