/**
 * 2026-09-10 — two changes to the agent's own orders screen, from one
 * question the human asked about it ("การให้ agent กดยืนยันชำระเงินเอง
 * จะเกิดช่องโหว่ไหม"):
 *
 *   1. "ใน Frontend มีการกดยืนยันชำระเงินเองอยู่ นำออก"
 *   2. "ดูสลิปยังเป็นแบบ Download แก้เป็นดูแบบ Modal"
 *
 * ── WHY THE CONFIRM BUTTON MUST STAY GONE ──
 *
 * It never worked here: OrderPolicy::confirm allows a Super Admin or a
 * Company Admin of the order's own company and nobody else, so an agent
 * tapping it got a 403 under their own sale. This screen offered it on
 * ORDER STATUS alone and never asked whether the person could do it.
 *
 * And the refusal behind that 403 is deliberate — a hole found in the
 * 2026-08-21 security audit: an agent could create a client, submit a
 * referral, walk their own pipeline, mint the order, confirm it, and hold an
 * immutable BR-4 commission row for a sale nobody paid for. Whoever earns
 * from a sale must not also be the one who attests the money arrived.
 *
 * So this file asserts an ABSENCE, which is the kind of thing that quietly
 * comes back: someone sees an agent stuck at "รอตรวจสลิป", adds the obvious
 * button, and reopens the hole. Cancel stays — that one genuinely is theirs.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()
const download = vi.fn()
const getBlob = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    delete: vi.fn(),
    download: (...args: unknown[]) => download(...args),
    getBlob: (...args: unknown[]) => getBlob(...args),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import OrdersView from '../OrdersView.vue'

const ORDER = {
  id: 1,
  order_number: 'ORD-8CDEHHGY',
  status: 'awaiting_verification',
  status_label: 'รอตรวจสอบสลิป',
  amount_satang: 59000,
  amount_baht: 590,
  product_name: 'Almod Chips',
  client_name: 'kreangyot ohuyhannapa',
  has_slip: true,
  public_pay_url: 'https://apps.liveto100club.com/pay/U',
  short_pay_url: null,
  paid_at: null,
  created_at: '2026-09-04T07:00:00Z',
}

async function mountView(orders: Record<string, unknown>[] = [ORDER]) {
  get.mockImplementation(async () => ({ data: orders }))

  const wrapper = mount(OrdersView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        ConfirmDialog: true,
        AppButton: { template: '<button v-bind="$attrs"><slot /></button>' },
        AppSelect: true,
        AppInput: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

describe('an agent may not confirm their own sale', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
    download.mockReset()
    getBlob.mockReset()
    getBlob.mockResolvedValue(new Blob(['slip'], { type: 'image/jpeg' }))
  })

  it('offers no confirm button on an order awaiting verification', async () => {
    /*
     * The state where the temptation is greatest: the slip is in, the agent
     * is waiting, and the obvious "help" is a button. That button is the
     * hole.
     */
    const wrapper = await mountView()

    // Asserted over BUTTONS, not over the page text: the note below the card
    // legitimately contains the same words ("รอผู้ดูแล…และยืนยันการชำระเงิน"),
    // and what matters is that nothing here is pressable to that end.
    const labels = wrapper.findAll('button').map((b) => b.text())

    expect(labels.some((label) => label.includes('ยืนยันการชำระเงิน'))).toBe(false)
  })

  it('never calls the confirm endpoint from this screen', async () => {
    // Belt and braces: a button could be reintroduced under another label.
    const wrapper = await mountView()

    for (const button of wrapper.findAll('button')) {
      await button.trigger('click')
    }
    await flushPromises()

    expect(post.mock.calls.every(([path]) => !String(path).endsWith('/confirm'))).toBe(true)
  })

  it('says who the order is waiting for instead', async () => {
    // "Nothing here" reads as the app having forgotten the order. This says
    // where it actually is.
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="awaiting-admin-note"]').text()).toContain('รอผู้ดูแลตรวจสอบ')
  })

  it('keeps cancel, which genuinely is the agent\'s own action', async () => {
    // OrderPolicy::cancel includes the order's agent, unlike confirm.
    // Removing this too would take away something they actually have.
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ยกเลิก')
  })
})

describe('looking at a slip', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
    download.mockReset()
    getBlob.mockReset()
    getBlob.mockResolvedValue(new Blob(['slip'], { type: 'image/jpeg' }))
  })

  it('opens a modal instead of putting a file on the phone', async () => {
    /*
     * On a phone, "download" means several taps through a file manager, a
     * comparison made against a card no longer on screen, and a customer's
     * bank slip left on the device afterwards.
     */
    const wrapper = await mountView()

    await wrapper.find('[data-test="view-slip"]').trigger('click')
    await flushPromises()

    expect(download).not.toHaveBeenCalled()
    expect(wrapper.findComponent({ name: 'SlipViewerModal' }).props('orderId')).toBe(1)
  })

  it('offers the slip only when there is one', async () => {
    // A button that opens an empty modal reads as "the slip failed to load"
    // rather than "no slip was attached".
    const wrapper = await mountView([{ ...ORDER, has_slip: false }])

    expect(wrapper.find('[data-test="view-slip"]').exists()).toBe(false)
  })
})
