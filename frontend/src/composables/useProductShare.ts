import { ref, type Ref } from 'vue'
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
  const sharingProductId = ref<number | null>(null)
  const shareError = ref('')
  const showShareModal = ref(false)
  const shareLink: Ref<ProductShareLinkItem | null> = ref(null)
  const shareHeading = ref('')

  async function shareProduct(product: ShareableProduct): Promise<void> {
    if (!options.canShare() || sharingProductId.value) return

    sharingProductId.value = product.id
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
      shareLink.value = res.data
      shareHeading.value = product.name
      showShareModal.value = true
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
      if (isAbortError(e)) return

      if (e instanceof ApiError && e.status === 422) {
        const body = e.body as { errors?: Record<string, string[]> }
        const rawMessage = body.errors?.agent_id?.[0] ?? body.errors?.product_id?.[0] ?? ''
        shareError.value = rawMessage.startsWith('BR-1')
          ? rawMessage
          : 'สร้างลิงก์แชร์ไม่สำเร็จ กรุณาลองใหม่ หรือติดต่อผู้ดูแลระบบหากยังไม่สามารถแชร์ได้'
      } else {
        shareError.value = apiErrorMessage(e, 'สร้างลิงก์แชร์ไม่สำเร็จ')
      }
    } finally {
      sharingProductId.value = null
    }
  }

  return { sharingProductId, shareError, showShareModal, shareLink, shareHeading, shareProduct }
}
