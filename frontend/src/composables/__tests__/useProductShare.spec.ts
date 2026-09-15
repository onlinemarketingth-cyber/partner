/**
 * useProductShare — one minted link, two things to do with it.
 *
 * Owner, 2026-09-14: "ส่วน Frontend ให้เพิ่มปุ่มสั่งซื้อได้เลยเอาไว้คู่กับปุ่ม
 * แชร์". "สั่งซื้อ" opens the page the CUSTOMER sees — /p/<code>, where the
 * checkout lives — so the agent can take the order with the customer in front
 * of them rather than sending a link and waiting.
 *
 * ── WHAT THESE TESTS ARE GUARDING ──
 *
 * 1. THE SHORT URL IS PREFERRED, THE LONG ONE IS THE FALLBACK. Links minted
 *    before TASK-235 have no short code. Swap the preference round and those
 *    agents are sent to /p/<64 characters>; nothing else fails, and nobody
 *    notices until a customer is looking at the URL.
 * 2. IT IS A ROUTER PUSH, NOT A PAGE LOAD. /p/:token is a route of this app.
 *    `window.location.assign` would throw the SPA away and reload everything
 *    for a page already in hand — and in a test it does not even happen, which
 *    is exactly why this needs an assertion rather than a look.
 * 3. THE ERROR PATH IS SHARED WITH แชร์. That 422 handling exists because a raw
 *    FormRequest message once reached an agent's screen. A second button with
 *    its own copy of the POST would be the copy that misses the next fix.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
const push = vi.fn()

vi.mock('@/api/client', () => ({
  api: { post: (...args: unknown[]) => post(...args) },
  ApiError: class ApiError extends Error {
    status: number
    body: unknown
    constructor(status: number, body: unknown) {
      super('api')
      this.status = status
      this.body = body
    }
  },
}))

vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))

import { useProductShare } from '../useProductShare'
import { ApiError } from '@/api/client'

const PRODUCT = { id: 42, name: 'GENESENN Health Tracker V8' }

/*
 * The portal serves /p/:token itself, so in real life these URLs are on the
 * page's own origin — which is what lets the composable route-push instead of
 * reloading. The fixture has to say so, or every test here would exercise the
 * cross-origin fallback by accident and the push would never be covered.
 */
const ORIGIN = window.location.origin

function link(over: Record<string, unknown> = {}) {
  return {
    id: 7,
    product_id: PRODUCT.id,
    public_url: `${ORIGIN}/p/aaaabbbbccccdddd`,
    short_url: `${ORIGIN}/p/R4TB8WM2XK`,
    ...over,
  }
}

function setUp(canShare = true) {
  return useProductShare({ canShare: () => canShare })
}

beforeEach(() => {
  post.mockReset()
  push.mockReset()
  post.mockResolvedValue({ data: link() })
})

describe('openBuyPage', () => {
  it('mints the same idempotent link แชร์ does, then navigates to it', async () => {
    const { openBuyPage } = setUp()

    await openBuyPage(PRODUCT)

    expect(post).toHaveBeenCalledWith('/product-shares', { product_id: 42 }, undefined)
    expect(push).toHaveBeenCalledWith('/p/R4TB8WM2XK')
  })

  it('falls back to the long URL for a link minted before short codes', async () => {
    post.mockResolvedValue({ data: link({ short_url: null }) })
    const { openBuyPage } = setUp()

    await openBuyPage(PRODUCT)

    expect(push).toHaveBeenCalledWith('/p/aaaabbbbccccdddd')
  })

  it('hands a link on another host to the browser instead of the router', async () => {
    /*
     * A portal configured onto a different domain is not this router's to
     * resolve: pushing the PATH would land on this app's own /p/:token with
     * somebody else's code. The fallback is a real navigation, and it is the
     * branch nobody will ever see in development.
     *
     * Asserted as "the router is NOT used", not as "assign was called with
     * this URL": jsdom refuses to let `window.location.assign` be redefined,
     * so there is nothing to spy on. The half that can be checked is the half
     * that can be broken by a refactor — a push here sends the agent to the
     * wrong page on the right host, which is worse than not navigating at all.
     */
    post.mockResolvedValue({ data: link({ short_url: 'https://other.example/p/R4TB8WM2XK' }) })
    const { openBuyPage } = setUp()

    await openBuyPage(PRODUCT)

    expect(push).not.toHaveBeenCalled()
  })

  it('does not open the share sheet — that is the other button', async () => {
    const { openBuyPage, showShareModal } = setUp()

    await openBuyPage(PRODUCT)

    expect(showShareModal.value).toBe(false)
  })

  it('refuses before the request when BR-1 has not been met', async () => {
    const { openBuyPage } = setUp(false)

    await openBuyPage(PRODUCT)

    expect(post).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
  })

  it('goes nowhere when the mint fails, and says why once', async () => {
    // The 422 path is the whole reason this composable exists. Navigating
    // anyway would land the agent on a dead page with the error left behind
    // on the one they just left.
    post.mockRejectedValue(new ApiError(422, { errors: { agent_id: ['BR-1: not certified yet'] } }))
    const { openBuyPage, shareError } = setUp()

    await openBuyPage(PRODUCT)

    expect(push).not.toHaveBeenCalled()
    expect(shareError.value).toBe('BR-1: not certified yet')
  })

  it('never shows a raw FormRequest message, the same as แชร์', async () => {
    post.mockRejectedValue(new ApiError(422, { errors: { agent_id: ['The agent id field is required.'] } }))
    const { openBuyPage, shareError } = setUp()

    await openBuyPage(PRODUCT)

    expect(shareError.value).not.toContain('field is required')
    expect(shareError.value).toContain('ลองใหม่')
  })
})

describe('the two buttons do not race', () => {
  it('reports which product is busy on its own ref', async () => {
    const { openBuyPage, shareProduct, buyingProductId, sharingProductId } = setUp()

    await openBuyPage(PRODUCT)
    expect(buyingProductId.value).toBeNull()
    expect(sharingProductId.value).toBeNull()

    await shareProduct(PRODUCT)
    expect(sharingProductId.value).toBeNull()
  })

  it('ignores a second press while the first is still in flight', async () => {
    let release: (value: unknown) => void = () => {}
    post.mockReturnValue(new Promise((resolve) => { release = resolve }))
    const { openBuyPage, shareProduct } = setUp()

    const first = openBuyPage(PRODUCT)
    // The share press lands while the buy POST is open. One endpoint, one
    // request — a second would mint nothing twice and leave the card with two
    // spinners for one answer.
    await shareProduct(PRODUCT)
    expect(post).toHaveBeenCalledTimes(1)

    release({ data: link() })
    await first
  })
})

describe('shareProduct still does what it did', () => {
  it('opens the sheet on the minted link rather than navigating', async () => {
    const { shareProduct, showShareModal, shareLink, shareHeading } = setUp()

    await shareProduct(PRODUCT)

    expect(showShareModal.value).toBe(true)
    expect(shareLink.value?.short_url).toBe(`${ORIGIN}/p/R4TB8WM2XK`)
    expect(shareHeading.value).toBe(PRODUCT.name)
    expect(push).not.toHaveBeenCalled()
  })
})
