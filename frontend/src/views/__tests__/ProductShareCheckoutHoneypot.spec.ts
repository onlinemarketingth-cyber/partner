/**
 * 2026-09-16 — the honeypot that was refusing real customers.
 *
 * A buyer on apps.liveto100club.com was told "ขออภัย ขณะนี้ไม่สามารถทำรายการ
 * สั่งซื้อจากลิงก์นี้ได้". The server log now names the reason; this file is
 * about the reason itself.
 *
 * The hidden anti-bot field was labelled `Company` and positioned off-screen
 * rather than hidden. Both are things a password manager fills: `Company` is
 * the word identity autofill searches for, and off-screen fields are filled
 * because real forms use that trick too. So a customer who could not see the
 * input and never typed in it arrived at the server looking exactly like a
 * bot — and the endpoint is designed to say nothing about why it refuses, so
 * nobody could tell.
 *
 * These are regression tests for the three specific properties that made it
 * happen. Each one would have failed against the old markup:
 *
 *   1. the field is display:none, not merely off-screen;
 *   2. it carries no label, name or autocomplete hint an identity autofiller
 *      recognises — above all not "company"/"organization";
 *   3. it is still THERE and still sends `hp_field`, because the point was
 *      never to delete the trap.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { token: 'NLt4PkJRKn' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    patch: vi.fn(),
  },
  ApiError: class extends Error {
    constructor(readonly status: number) {
      super(`api ${status}`)
    }
  },
}))

vi.mock('@/stores/theme', () => ({
  useThemeStore: () => ({ applyResolved: vi.fn() }),
}))

import ProductShareView from '../ProductShareView.vue'

const SHARE = {
  company_name: 'Live to 100 Club',
  agent_name: 'สมชาย ใจดี',
  agent_phone: null,
  agent_email: null,
  theme: null,
  product: {
    id: 1,
    name: 'GENESENN 1-Year Vital Blueprint',
    description: null,
    spec_description: null,
    price_satang: 2990000,
    payable_price_satang: 2990000,
    promotional_price_satang: null,
    can_checkout: true,
    specs: [],
    media: [],
    sales_materials: [],
  },
}

/**
 * The honeypot lives INSIDE the checkout sheet (`v-if="checkoutOpen"`), so
 * every test here has to open it first — which is also the only state in
 * which a password manager could ever have reached the field.
 */
async function mountShare() {
  get.mockResolvedValue({ data: SHARE })
  const wrapper = mount(ProductShareView, {
    global: { stubs: { RichText: true, AttachmentLightbox: true, Icon: true } },
    attachTo: document.body,
  })
  await flushPromises()

  await wrapper.find('[data-test="buy-now"]').trigger('click')
  await flushPromises()

  return wrapper
}

function honeypot(wrapper: Awaited<ReturnType<typeof mountShare>>) {
  return wrapper.find('[data-test="checkout-honeypot"]')
}

describe('the public checkout honeypot', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('is still in the DOM — the trap was not deleted, only hidden properly', async () => {
    const wrapper = await mountShare()

    expect(honeypot(wrapper).exists()).toBe(true)
    expect((honeypot(wrapper).element as HTMLInputElement).name).toBe('hp_field')
  })

  it('is display:none rather than positioned off-screen', async () => {
    // The old markup was `absolute -left-[9999px]`, which renders — and a
    // rendered field is a field a password manager fills. A test on the
    // computed style rather than on the class name, because the next person
    // to "tidy the layout" will change the class.
    //
    // Asserted through the `hidden` ATTRIBUTE rather than a class name: this
    // suite runs in jsdom with no Tailwind stylesheet, so a utility class
    // would compute to `display: block` here and the test would be checking
    // the string in the markup rather than the behaviour. `hidden` comes
    // from the user-agent stylesheet, which jsdom does implement — and it is
    // the reason the production markup uses the attribute too.
    const wrapper = await mountShare()
    const el = honeypot(wrapper).element as HTMLElement
    const box = el.parentElement as HTMLElement

    expect(box.hasAttribute('hidden')).toBe(true)
    expect(window.getComputedStyle(box).display).toBe('none')
    // And nothing left of the old approach.
    expect(box.className).not.toContain('-left-[9999px]')
    expect(box.getAttribute('style') ?? '').not.toContain('-9999px')
  })

  it('carries nothing an identity autofiller recognises', async () => {
    const wrapper = await mountShare()
    const el = honeypot(wrapper).element as HTMLInputElement

    // The specific regression: `<label for="checkout-hp">Company</label>`.
    expect(document.querySelector('label[for="checkout-hp"]')).toBeNull()

    // And nothing else in its wrapper naming an identity field either.
    const text = (el.parentElement?.textContent ?? '').toLowerCase()
    for (const magnet of ['company', 'organization', 'organisation', 'address', 'name', 'email']) {
      expect(text).not.toContain(magnet)
    }

    expect(el.getAttribute('autocomplete')).toBe('off')
    expect(el.getAttribute('tabindex')).toBe('-1')
  })

  it('is hidden from screen readers, so nobody is ever told to fill it', async () => {
    const wrapper = await mountShare()

    expect(honeypot(wrapper).element.closest('[aria-hidden="true"]')).not.toBeNull()
  })

  it('starts empty, because the whole trap is "empty means human"', async () => {
    const wrapper = await mountShare()

    expect((honeypot(wrapper).element as HTMLInputElement).value).toBe('')
  })
})
