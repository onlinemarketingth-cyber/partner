/**
 * TASK-257 / ADR-040 — the price an AGENT sees for a shared product.
 *
 * The API resolves two different numbers for the same row and the difference
 * is somebody's money:
 *
 *   price_satang            the row's own — for a PLATFORM product, the
 *                           CENTRAL price, which no company necessarily charges
 *   effective_price_satang  what THIS agent's company charges: its own price
 *                           if it set one, the central one if not, and an
 *                           active promotion above either
 *
 * These screens read the first. That was correct for every product that exists
 * today, because a company-owned product IS its own company — and becomes
 * wrong the moment `catalog:promote-products` runs, at which point the browse
 * grid quotes one number and the order takes another, in front of the agent
 * who has to explain it to the customer.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ProductCard from '@/design-system/components/ProductCard.vue'

function mountCard(product: Record<string, unknown>) {
  return mount(ProductCard, {
    props: { product, hasPassedBasic: true, sharing: false } as never,
    global: { stubs: { Icon: true, RouterLink: { template: '<a><slot /></a>' }, AuthenticatedMedia: true } },
  })
}

const SHARED = {
  id: 1,
  name: 'Vital Blueprint V5',
  // The platform's central price…
  price_satang: 890000,
  // …and what this agent's company actually charges.
  effective_price_satang: 790000,
  thumbnail_url: null,
  category: null,
}

describe('ProductCard — whose price is on the card', () => {
  it('shows what this agent\'s company charges, not the central price', async () => {
    const wrapper = mountCard(SHARED)

    expect(wrapper.text()).toContain('7,900')
    expect(wrapper.text()).not.toContain('8,900')
  })

  it('falls back to the row price when the API did not resolve one', async () => {
    /*
     * Not defensive noise: every caller today reads /products, which sends the
     * field. The fallback means a future caller that does not is wrong by a
     * stale number a human once typed, rather than rendering NaN at a customer.
     */
    const wrapper = mountCard({ ...SHARED, effective_price_satang: undefined })

    expect(wrapper.text()).toContain('8,900')
  })

  it('treats a deliberate zero as a price, not as missing', async () => {
    // `??`, not `||` — a free onboarding item is a real price a Super Admin
    // typed, and `||` would silently quote the central one instead.
    const wrapper = mountCard({ ...SHARED, effective_price_satang: 0 })

    expect(wrapper.text()).not.toContain('8,900')
  })
})
