/**
 * 2026-09-10 (human: "ปุ่มดูรายละเอียด [ดูออเดอร์พร้อมแบบฟอร์มสำหรับพิมพ์]").
 *
 * The sheet a person hands over with the goods, or files. What is pinned
 * here is the part that fails silently: it is not enough for this to LOOK
 * printable — the page it is teleported into has to disappear when the print
 * dialog opens, and the sheet has to survive.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
  ApiError: class extends Error {},
}))

import OrderDetailModal from '../OrderDetailModal.vue'

const ORDER = {
  id: 1,
  order_number: 'ORD-MWJTV2QV',
  status: 'pending',
  status_label: 'รอชำระเงิน',
  payment_method_label: 'โอนเงิน',
  payment_provider: 'stripe',
  payment_provider_label: 'Stripe (บัตรเครดิต / เดบิต)',
  gateway_mode: 'live',
  gateway_payment_received: true,
  amount_satang: 890000,
  client_name: 'kreangyot ohuyhannapa',
  client_email: 'buyer@example.com',
  product_name: 'GENESENN Health Tracker V5 Vital Blueprint',
  agent: { id: 3, name: 'เกรียงยศ อุยเทินนบภา' },
  paid_at: null,
  verified_by: null,
  created_at: '2026-09-10T02:05:00Z',
}

async function mountSheet(overrides: Record<string, unknown> = {}) {
  get.mockImplementation(async () => ({ data: { ...ORDER, ...overrides } }))

  const wrapper = mount(OrderDetailModal, {
    props: { orderId: 1 },
    global: { stubs: { Icon: true } },
    attachTo: document.body,
  })
  await flushPromises()

  return wrapper
}

describe('the printable order sheet', () => {
  beforeEach(() => {
    get.mockReset()
    document.body.innerHTML = ''
  })

  it('carries everything the sheet has to say on its own', async () => {
    // A page on a desk has no tooltips and no columns to scroll back to.
    const w = await mountSheet()
    const text = document.body.textContent ?? ''

    expect(text).toContain('ORD-MWJTV2QV')
    expect(text).toContain('GENESENN Health Tracker V5 Vital Blueprint')
    expect(text).toContain('8,900.00')
    expect(text).toContain('kreangyot ohuyhannapa')
    expect(text).toContain('เกรียงยศ อุยเทินนบภา')

    w.unmount()
  })

  it('prints what the person is looking at', async () => {
    /*
     * Not a second window: another route to keep in step, another place the
     * order shape is read, and the thing popup blockers eat on the exact tap
     * that matters. The sheet IS the print layout.
     */
    const print = vi.fn()
    Object.defineProperty(window, 'print', { configurable: true, value: print })

    const w = await mountSheet()
    await document.querySelector<HTMLButtonElement>('[data-test="print-order"]')?.click()

    expect(print).toHaveBeenCalled()

    w.unmount()
  })

  it('marks the modal chrome as not-printed and the sheet as printed', async () => {
    /*
     * The half that fails silently: without `print-root` on the container
     * and `no-print` on the toolbar, the printed page comes out with a close
     * button on it and the rest of the admin console behind it.
     */
    const w = await mountSheet()

    expect(document.querySelector('.print-root')).not.toBeNull()
    expect(document.querySelector('.print-sheet')).not.toBeNull()
    expect(document.querySelector('[data-test="print-order"]')?.closest('.no-print')).not.toBeNull()

    w.unmount()
  })

  it('says outright when a charge was made in test mode', async () => {
    // A sheet that goes in an envelope must not be able to be mistaken for
    // a record of real revenue.
    const w = await mountSheet({ gateway_mode: 'test' })

    expect(document.body.textContent).toContain('โหมดทดสอบ')

    w.unmount()
  })

  it('keeps "money in, sale not closed" on the printed page', async () => {
    // The one thing anyone handling this order most needs to know, and the
    // order this whole day started from.
    const w = await mountSheet()

    expect(document.body.textContent).toContain('ระบบยังปิดการขายไม่สำเร็จ')

    w.unmount()
  })
})
