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
 * 2. `.stop.prevent` GOES MISSING FROM THE SHARE BUTTON. The button sits
 *    INSIDE the link, so without it every share press also navigates: the
 *    POST fires, the modal opens, and the page it opened on is already
 *    leaving. The visible symptom is "sometimes the share sheet flashes and
 *    disappears" — timing-dependent, unreproducible on a fast machine, and
 *    absolutely not traceable back to a missing modifier.
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

function mountCard(props: Record<string, unknown> = {}) {
  return mount(ProductCard, {
    props: { product: PRODUCT, hasPassedBasic: true, sharing: false, ...props },
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

    await wrapper.find('button').trigger('click')

    expect(wrapper.emitted('share')?.[0]?.[0]).toEqual(PRODUCT)
  })

  it('does NOT navigate when the share button is pressed', async () => {
    // The button lives inside the link. Without .stop.prevent the modal would
    // open on a page that is already navigating away.
    const wrapper = mountCard()

    await wrapper.find('button').trigger('click')

    expect(wrapper.findComponent(RouterLinkStub).emitted('navigate')).toBeUndefined()
  })

  it('locks the share button until Basic is passed (BR-1)', async () => {
    const wrapper = mountCard({ hasPassedBasic: false })
    const button = wrapper.find('button')

    expect(button.attributes('disabled')).toBeDefined()
    await button.trigger('click')
    expect(wrapper.emitted('share')).toBeUndefined()
  })

  it('still opens the product when sharing is locked', async () => {
    // BR-1 gates SHARING, not reading. An agent who has not passed Basic yet
    // is exactly the one who most needs to read what they will be selling.
    const link = mountCard({ hasPassedBasic: false }).findComponent(RouterLinkStub)

    expect(link.props('to')).toEqual({ name: 'product-detail', params: { id: 42 } })
  })

  it('shows the minting state on the share button', () => {
    expect(mountCard({ sharing: true }).find('button').text()).toContain('กำลังสร้าง')
  })
})

describe('ProductCard — the share button stays reachable', () => {
  it('keeps a 44px minimum tap target', () => {
    // TASK-079 Phase 3 found this at ~36px. It is inside a link now, so a
    // too-small target does not just miss — it navigates instead.
    expect(mountCard().find('button').classes()).toContain('min-h-[44px]')
  })

})
