import { ref, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'

/**
 * Minting (or reusing) an agent's public share link for a product, plus the
 * modal state that follows it.
 *
 * ── WHY THIS IS A COMPOSABLE AND NOT COPIED INTO THE SECOND VIEW ──
 *
 * ProductDetailView (2026-08-21) is the second screen with a "แชร์" button
 * on a product. The flow it needs is forty lines long and almost none of
 * that length is the happy path — it is the 422 handling below, which exists
 * because of a specific production incident: the raw FormRequest message
 * "The agent id field is required" reached an agent's screen. The fix was to
 * show ONLY the known BR-1 sentence or a safe generic one, never a raw
 * validation string.
 *
 * A copy of that in a second view would look identical on the day it was
 * written and would be the copy that misses the next correction. So the
 * error handling lives here once, and both views render the same `shareError`.
 *
 * WHAT THIS DELIBERATELY DOES NOT OWN: the BR-1 certification check. The
 * caller passes `canShare`, because each screen already knows its own answer
 * — ProductBrowseView derives it from certifications it loads with everything
 * else, and re-fetching them here would double the request on that page. The
 * server is the real gate either way (ProductShareLinkService::create()); the
 * flag only decides whether the button is live.
 *
 * ── 2026-09-14: A SECOND THING TO DO WITH THE SAME LINK ──
 *
 * Owner: "ส่วน Frontend ให้เพิ่มปุ่มสั่งซื้อได้เลยเอาไว้คู่กับปุ่มแชร์".
 *
 * "สั่งซื้อ" opens the page the CUSTOMER would see — /p/<code>, where the
 * "ซื้อเลย" checkout lives — so the agent can put the order through with the
 * customer in front of them instead of sending a link and waiting.
 *
 * That page is reached by a share link and nothing else, so both buttons mint
 * the same idempotent link and differ only in what they do with it: แชร์ opens
 * the share sheet, สั่งซื้อ navigates. They therefore share `mintLink()` below
 * — including the 422 handling this file exists for — rather than the second
 * button growing its own copy of the POST.
 *
 * BR-1 GATES BOTH, and that is not a UI choice: ProductShareLinkService
 * REFUSES to mint for an agent who has not passed Basic, so a สั่งซื้อ button
 * that ignored `canShare` would be a button whose only possible outcome is an
 * error. (Owner asked for it ungated, then chose to keep the lock once the
 * server's answer was on the table.)
 */

export interface ProductShareLinkItem {
  id: number
  product_id: number
  public_url: string
  /** TASK-235 — /p/<code>. Null before the feature; fall back, never swap. */
  short_url: string | null
}

export interface ShareableProduct {
  id: number
  name: string
}

export function useProductShare(options: {
  /** BR-1: has this agent passed Basic? Read at click time, not at setup. */
  canShare: () => boolean
  /** The page's AbortController signal, so an unmount cancels the POST. */
  signal?: AbortSignal
}) {
  const router = useRouter()
  const sharingProductId = ref<number | null>(null)
  const buyingProductId = ref<number | null>(null)
  const shareError = ref('')
  const showShareModal = ref(false)
  const shareLink: Ref<ProductShareLinkItem | null> = ref(null)
  const shareHeading = ref('')

  /**
   * Mint (or reuse) this agent's link for the product, or return null and
   * leave `shareError` saying why.
   *
   * `busy` is the caller's own ref so the two buttons spin independently —
   * a card whose แชร์ press greyed out its สั่งซื้อ would read as one broken
   * control rather than two working ones. Both are blocked while EITHER is in
   * flight, though: they hit the same idempotent endpoint, and letting them
   * race would mint nothing twice and confuse the reader for no gain.
   */
  async function mintLink(product: ShareableProduct, busy: Ref<number | null>): Promise<ProductShareLinkItem | null> {
    if (!options.canShare() || sharingProductId.value || buyingProductId.value) return null

    busy.value = product.id
    shareError.value = ''

    try {
      // POST /product-shares is idempotent per (agent, product): a second
      // press returns the SAME link rather than minting a rival one, which
      // is what lets every card press this without bookkeeping.
      const res = await api.post<{ data: ProductShareLinkItem }>(
        '/product-shares',
        { product_id: product.id },
        options.signal,
      )

      return res.data
    } catch (e) {
      /*
       * NEVER SURFACE A RAW FormRequest MESSAGE. (Bug fix 2026-08-01,
       * human-reported: 'The agent id field is required' leaking to the UI.)
       *
       * That message had been assumed to always be
       * ProductShareLinkService::create()'s friendly BR-1 sentence. It is
       * not: StoreProductShareLinkRequest ALSO puts a
       * `requiredIf(! isAgent())` rule on agent_id, so when the acting
       * session is not recognised as an Agent — wrong role, or a role change
       * with a stale Basic-cert row still making canShare() true — Laravel's
       * own generic auto-message lands on the same key.
       *
       * So only a message that actually starts with "BR-1" is shown through;
       * anything else becomes the safe generic sentence.
       */
      // An unmount cancelled the POST. Not a failure to report — and not a
      // link either, so the caller stops here rather than navigating a page
      // that is already leaving.
      if (isAbortError(e)) return null

      if (e instanceof ApiError && e.status === 422) {
        const body = e.body as { errors?: Record<string, string[]> }
        const rawMessage = body.errors?.agent_id?.[0] ?? body.errors?.product_id?.[0] ?? ''
        shareError.value = rawMessage.startsWith('BR-1')
          ? rawMessage
          : 'สร้างลิงก์แชร์ไม่สำเร็จ กรุณาลองใหม่ หรือติดต่อผู้ดูแลระบบหากยังไม่สามารถแชร์ได้'
      } else {
        shareError.value = apiErrorMessage(e, 'สร้างลิงก์แชร์ไม่สำเร็จ')
      }

      return null
    } finally {
      busy.value = null
    }
  }

  async function shareProduct(product: ShareableProduct): Promise<void> {
    const link = await mintLink(product, sharingProductId)
    if (!link) return

    shareLink.value = link
    shareHeading.value = product.name
    showShareModal.value = true
  }

  /**
   * Open the page the customer would see, on this agent's own link.
   *
   * ROUTER-PUSHED WHEN IT CAN BE. `public_url` and `short_url` are absolute
   * URLs on the portal's own origin, and /p/:token is a route of THIS app, so
   * a full page load would throw away the SPA and the agent's session warm-up
   * for a page they are already holding. Same origin → push the path; anything
   * else → hand it to the browser, because a portal configured onto a
   * different host is not this router's to resolve.
   *
   * `short_url` first, `public_url` as the fallback — never the other way
   * round: links minted before TASK-235 have no short code, and swapping the
   * preference would send those agents to `/p/<64 characters>`.
   */
  async function openBuyPage(product: ShareableProduct): Promise<void> {
    const link = await mintLink(product, buyingProductId)
    if (!link) return

    const target = link.short_url ?? link.public_url
    const parsed = new URL(target, window.location.origin)

    if (parsed.origin === window.location.origin) {
      await router.push(parsed.pathname + parsed.search)

      return
    }

    window.location.assign(target)
  }

  return {
    sharingProductId,
    buyingProductId,
    shareError,
    showShareModal,
    shareLink,
    shareHeading,
    shareProduct,
    openBuyPage,
  }
}
