/**
 * useAuthenticatedMedia — renders a Sanctum-protected media URL (product
 * image/video stream or thumbnail, ADR-007) inline as an <img>/<video>
 * src. A plain `<img :src="stream_url">` cannot carry the session
 * cookie cross-origin (see api/client.ts's requestDownload/getBlob
 * comments), so every authenticated media element must be fetched via
 * fetch()+credentials:'include' and turned into a blob object URL.
 *
 * These `stream_url`/`thumbnail_url` values come back from the API as
 * FULL absolute URLs (Laravel's route() helper), not relative paths —
 * unlike every other endpoint this app calls through api/client.ts — so
 * this composable fetches the absolute URL directly rather than going
 * through api.getBlob()'s path-prefixed request().
 *
 * Object URLs are cached by source URL and revoked on unmount to avoid
 * leaking memory across the expandable product rows this is used in.
 */
import { onUnmounted, ref, watch, type Ref } from 'vue'

function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))
  return match?.[2] ? decodeURIComponent(match[2]) : null
}

/*
 * ═══ TASK-223 — WHY THERE ARE THREE MAPS AND NOT ONE ═══
 *
 * Human-reported 2026-08-20 (Safari, production): product images
 * "แสดงบ้างไม่แสดงบ้าง". The files were fine — both media rows answered
 * 200 image/jpeg with real byte counts when fetched directly. The bug was
 * here, and it needs all three of these to fix.
 *
 * The trigger is TWO COMPONENTS SHOWING THE SAME IMAGE at once, which the
 * product screens do constantly: the "แนะนำสำหรับคุณ" card and the grid
 * below it can be the same product.
 *
 * What went wrong, in order:
 *   1. Card A mounts, starts fetching URL X. Nothing is cached yet.
 *   2. Card B mounts on the SAME X. `objectUrlCache` is still empty
 *      because A's fetch has not resolved, so B starts a SECOND fetch.
 *      -> `inFlight` now exists so the second caller awaits the first.
 *   3. Both resolve. Each called createObjectURL, and the second
 *      `objectUrlCache.set(X, ...)` OVERWROTE the first. A was left
 *      displaying a blob URL that the cache no longer knew about, and
 *      that blob could never be revoked — a leak AND a dangling handle.
 *      -> the cache is now written once and never overwritten.
 *   4. The reference count was incremented AFTER the await. Between the
 *      fetch starting and finishing the count was 0, so any release() in
 *      that window revoked a blob another card was about to use — or was
 *      already using. That is the "sometimes" in "sometimes shows".
 *      -> retain() now runs BEFORE the await, and the previous URL is
 *         released only after, so re-loading the SAME url can never dip
 *         the count to zero.
 *
 * Safari surfaced it and Chrome mostly did not, which is a hint rather
 * than a difference in correctness: a revoked object URL is invalid
 * everywhere, Safari just stops honouring the handle sooner.
 */
const objectUrlCache = new Map<string, string>()
const refCounts = new Map<string, number>()
/** Fetches that have started but not finished, keyed by source URL. */
const inFlight = new Map<string, Promise<string>>()

function retain(sourceUrl: string): void {
  refCounts.set(sourceUrl, (refCounts.get(sourceUrl) ?? 0) + 1)
}

function fetchAsObjectUrl(sourceUrl: string): Promise<string> {
  const cached = objectUrlCache.get(sourceUrl)
  if (cached) return Promise.resolve(cached)

  // De-duplicate: two cards asking for the same image at the same moment
  // share one request and one blob, instead of racing to overwrite each
  // other's cache entry.
  const pending = inFlight.get(sourceUrl)
  if (pending) return pending

  const request = (async () => {
    const headers = new Headers()
    const xsrfToken = getCookie('XSRF-TOKEN')
    if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

    const res = await fetch(sourceUrl, { method: 'GET', headers, credentials: 'include' })
    if (!res.ok) throw new Error(`Failed to load media (${res.status})`)

    const objectUrl = URL.createObjectURL(await res.blob())

    // Everyone who wanted this unmounted while it was in flight. Caching
    // it would hand a later caller a handle nobody will ever revoke.
    if ((refCounts.get(sourceUrl) ?? 0) === 0) {
      URL.revokeObjectURL(objectUrl)

      return objectUrl
    }

    objectUrlCache.set(sourceUrl, objectUrl)

    return objectUrl
  })()

  inFlight.set(sourceUrl, request)
  void request.catch(() => {}).then(() => inFlight.delete(sourceUrl))

  return request
}

function release(sourceUrl: string | null): void {
  if (!sourceUrl) return
  const count = (refCounts.get(sourceUrl) ?? 1) - 1
  if (count <= 0) {
    refCounts.delete(sourceUrl)
    const objectUrl = objectUrlCache.get(sourceUrl)
    if (objectUrl) {
      URL.revokeObjectURL(objectUrl)
      objectUrlCache.delete(sourceUrl)
    }
  } else {
    refCounts.set(sourceUrl, count)
  }
}

/**
 * Reactive: pass a ref (or plain string) holding the protected URL, get
 * back a ref holding the displayable blob object URL (null while
 * loading or if sourceUrl is null). Automatically re-fetches when the
 * source URL changes and cleans up on unmount.
 */
export function useAuthenticatedMedia(sourceUrl: Ref<string | null>) {
  const objectUrl = ref<string | null>(null)
  const loading = ref(false)
  const error = ref('')
  let currentTracked: string | null = null

  async function load(url: string | null) {
    const previous = currentTracked
    currentTracked = null
    objectUrl.value = null
    error.value = ''

    if (!url) {
      release(previous)

      return
    }

    // ORDER MATTERS (see the block comment above): retain first, release
    // the old one second. Reversed — or with retain after the await —
    // re-loading the same URL drops its count to zero and revokes a blob
    // that other components are still displaying.
    retain(url)
    currentTracked = url
    release(previous)

    loading.value = true
    try {
      const result = await fetchAsObjectUrl(url)
      // A newer load() may have started while this one was in flight;
      // that one owns `currentTracked` now, and writing here would show
      // a stale image.
      if (currentTracked === url) objectUrl.value = result
    } catch {
      error.value = 'โหลดสื่อไม่สำเร็จ'
      if (currentTracked === url) {
        release(url)
        currentTracked = null
      }
    } finally {
      loading.value = false
    }
  }

  watch(sourceUrl, (url) => load(url), { immediate: true })
  onUnmounted(() => release(currentTracked))

  return { objectUrl, loading, error }
}
