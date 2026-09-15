/**
 * ProductCard is a LINK to the product, with a share button that does not
 * follow it.
 *
 * ── THE TWO THINGS THAT BREAK SILENTLY ──
 *
 * 1. THE CARD STOPS BEING A LINK. Turn the RouterLink back into a div — the
 *    tidiest-looking refactor in the world — and tapping a product simply
 *    does nothing. No error, no console, nothing to report except "the app
 *    feels broken", which is the hardest kind of bug report to act on. This
 *    is the whole of the 2026-08-21 request.
 *
 * 2. `.stop.prevent` GOES MISSING FROM A BUTTON. The buttons sit INSIDE the
 *    link, so without it every press also navigates: the POST fires, the
 *    modal opens, and the page it opened on is already leaving. The visible
 *    symptom is "sometimes the share sheet flashes and disappears" —
 *    timing-dependent, unreproducible on a fast machine, and absolutely not
 *    traceable back to a missing modifier.
 *
 * 2026-09-14 — there are TWO such buttons now (owner: "ให้เพิ่มปุ่มสั่งซื้อได้
 * เลยเอาไว้คู่กับปุ่มแชร์"), so both hazards are asserted for each. The
 * selectors are data-test attributes rather than `find('button')` for the
 * same reason: with two buttons in the card, position is no longer a name.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProductCard from '../ProductCard.vue'

const PRODUCT = {
  id: 42,
  name: 'GENESENN Health Tracker V5 Vital Blueprint',
  price_satang: 890000,
  thumbnail_url: null,
  category: { id: 3, name: 'ANTI AGING' },
}

/** A RouterLink stub that records the navigation instead of performing one. */
const RouterLinkStub = {
  name: 'RouterLink',
  props: ['to'],
  template: '<a @click="$emit(\'navigate\')"><slot /></a>',
}

const SHARE = '[data-test="product-share-button"]'
const BUY = '[data-test="product-buy-button"]'

function mountCard(props: Record<string, unknown> = {}) {
  return mount(ProductCard, {
    props: { product: PRODUCT, hasPassedBasic: true, sharing: false, buying: false, ...props },
    global: {
      stubs: { Icon: true, AuthenticatedMedia: true, RouterLink: RouterLinkStub },
    },
  })
}

describe('ProductCard', () => {
  it('links to the product detail page', () => {
    const link = mountCard().findComponent(RouterLinkStub)

    expect(link.exists()).toBe(true)
    expect(link.props('to')).toEqual({ name: 'product-detail', params: { id: 42 } })
  })

  it('emits share when the share button is pressed', async () => {
    const wrapper = mountCard()

    await wrapper.find(SHARE).trigger('click')

    expect(wrapper.emitted('share')?.[0]?.[0]).toEqual(PRODUCT)
  })

  it('emits buy when the order button is pressed', async () => {
    const wrapper = mountCard()

    await wrapper.find(BUY).trigger('click')

    expect(wrapper.emitted('buy')?.[0]?.[0]).toEqual(PRODUCT)
    // Two buttons, two meanings: pressing one must never fire the other.
    expect(wrapper.emitted('share')).toBeUndefined()
  })

  it('does NOT navigate when either button is pressed', async () => {
    // The buttons live inside the link. Without .stop.prevent the sheet would
    // open, or the buy page load, on a page that is already navigating away.
    for (const selector of [SHARE, BUY]) {
      const wrapper = mountCard()

      await wrapper.find(selector).trigger('click')

      expect(wrapper.findComponent(RouterLinkStub).emitted('navigate')).toBeUndefined()
    }
  })

  it('locks BOTH buttons until Basic is passed (BR-1)', async () => {
    /*
     * The buy button is gated for a reason that is not symmetry: it opens the
     * agent's own share link, and ProductShareLinkService REFUSES to mint one
     * for an agent who has not passed Basic. An unlocked button here would be
     * a button whose only possible outcome is an error message.
     */
    const wrapper = mountCard({ hasPassedBasic: false })

    for (const selector of [SHARE, BUY]) {
      const button = wrapper.find(selector)
      expect(button.attributes('disabled')).toBeDefined()
      await button.trigger('click')
    }

    expect(wrapper.emitted('share')).toBeUndefined()
    expect(wrapper.emitted('buy')).toBeUndefined()
  })

  it('holds both buttons while either one is mid-flight', async () => {
    // They hit the same idempotent endpoint; letting them race would mint
    // nothing twice and leave the card showing two spinners for one request.
    expect(mountCard({ sharing: true }).find(BUY).attributes('disabled')).toBeDefined()
    expect(mountCard({ buying: true }).find(SHARE).attributes('disabled')).toBeDefined()
  })

  it('still opens the product when sharing is locked', async () => {
    // BR-1 gates SHARING, not reading. An agent who has not passed Basic yet
    // is exactly the one who most needs to read what they will be selling.
    const link = mountCard({ hasPassedBasic: false }).findComponent(RouterLinkStub)

    expect(link.props('to')).toEqual({ name: 'product-detail', params: { id: 42 } })
  })

  it('shows the minting state on the share button', () => {
    expect(mountCard({ sharing: true }).find(SHARE).text()).toContain('กำลังสร้าง')
  })

  it('shows its own state on the buy button, not the share one', () => {
    const wrapper = mountCard({ buying: true })

    expect(wrapper.find(BUY).text()).toContain('กำลังเปิด')
    expect(wrapper.find(SHARE).text()).toContain('แชร์')
  })
})

describe('ProductCard — both buttons stay reachable', () => {
  it('keeps a 44px minimum tap target on each', () => {
    // TASK-079 Phase 3 found the share button at ~36px. They are inside a
    // link, so a too-small target does not just miss — it navigates instead,
    // and putting two of them on a ~160px card is exactly the change that
    // tempts somebody to shrink them.
    const wrapper = mountCard()

    expect(wrapper.find(SHARE).classes()).toContain('min-h-[44px]')
    expect(wrapper.find(BUY).classes()).toContain('min-h-[44px]')
  })

  it('lets neither label decide how wide the card is', () => {
    // Two Thai labels side by side in a two-column phone grid. Without the
    // truncate/min-w-0 pair, "กำลังสร้าง..." pushes the card wider than its
    // grid track and the whole row goes ragged.
    const wrapper = mountCard()

    for (const selector of [SHARE, BUY]) {
      expect(wrapper.find(selector).classes()).toContain('min-w-0')
      expect(wrapper.find(`${selector} span`).classes()).toContain('truncate')
    }
  })
})
