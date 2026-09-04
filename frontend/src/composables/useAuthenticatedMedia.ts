/**
 * useAuthenticatedMedia — renders a Sanctum-protected media URL (product
 * image/video stream/thumbnail, Academy module video stream, ADR-007)
 * inline as an <img>/<video> src. A plain `<img :src="stream_url">`
 * cannot carry the session cookie cross-origin (see api/client.ts's
 * requestDownload/getBlob comments), so every authenticated media
 * element must be fetched via fetch()+credentials:'include' and turned
 * into a blob object URL.
 *
 * These `stream_url`/`thumbnail_url` values come back from the API as
 * FULL absolute URLs (Laravel's route() helper), not relative paths —
 * unlike every other endpoint this app calls through api/client.ts — so
 * this composable fetches the absolute URL directly rather than going
 * through api.getBlob()'s path-prefixed request().
 *
 * ── IT STILL HAS TO PROVE WHO IT IS (fixed 2026-09-04) ──
 *
 * Fetching the URL by hand does NOT mean fetching it anonymously. This file
 * was ported from the admin console, which authenticates with a cookie, and
 * kept sending only the XSRF header after the AGENT PORTAL moved to bearer
 * tokens. Every one of these URLs is behind auth:sanctum, so each one
 * answered 401 — and because a 401 is an ANSWER rather than a blip, the
 * retry logic below correctly refused to retry it. The result was a product
 * grid of "ลองใหม่" buttons that could never succeed, on a page whose data
 * had loaded perfectly.
 *
 * The headers now come from api/client.ts, which is the one place that
 * knows how this app proves who it is.
 *
 * Object URLs are cached by source URL and revoked on unmount to avoid
 * leaking memory. Ported verbatim from
 * frontend-admin/src/composables/useAuthenticatedMedia.ts (Agent Portal
 * needs the same product gallery + Academy video playback).
 */
import { onUnmounted, ref, watch, type Ref } from 'vue'
import { authHeaders } from '@/api/client'

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

/**
 * TASK-224 — a failure carries its HTTP status so the caller can tell a
 * BLIP from an ANSWER.
 *
 * 404 and 403 are answers: the file is gone, or this user may not have it.
 * Retrying them costs three requests per image to arrive at the same
 * place, and on a grid of twelve missing thumbnails that is thirty-six.
 * A dropped connection, a 429 from the rate limiter, or a 502 while the
 * server restarts are blips — those are worth asking again.
 */
class MediaFetchError extends Error {
  /** null = the request never got a response (network/DNS/abort). */
  constructor(readonly status: number | null) {
    super(`Failed to load media (${status ?? 'network'})`)
  }

  get isTransient(): boolean {
    return this.status === null
      || this.status === 408
      || this.status === 429
      || this.status >= 500
  }
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
    // Bearer token (portal) — and the XSRF cookie is still sent alongside
    // it, so this same file keeps working if a host is ever stateful again.
    const headers = authHeaders(new Headers())
    const xsrfToken = getCookie('XSRF-TOKEN')
    if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

    let res: Response
    try {
      res = await fetch(sourceUrl, { method: 'GET', headers, credentials: 'include' })
    } catch {
      // fetch() only rejects when there was no HTTP answer at all — the
      // single most retryable thing that can happen here.
      throw new MediaFetchError(null)
    }

    if (!res.ok) throw new MediaFetchError(res.status)

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
 * TASK-224 — retry a TRANSIENT failure a couple of times before giving up.
 *
 * Human-reported after TASK-223: one failed fetch left a red triangle that
 * never went away until the component happened to remount. The most common
 * cause of that on a phone is not a broken file — it is one request out of
 * a dozen losing its connection.
 *
 * THREE ATTEMPTS, NOT MORE. A product grid can hold a dozen images; every
 * extra attempt multiplies by twelve. Two short retries recover a blip
 * without turning a genuinely-down server into a stampede.
 *
 * `stillWanted` is checked between attempts so a card that scrolled away,
 * or whose url changed, stops immediately instead of finishing a retry
 * chain nobody is waiting for.
 */
const RETRY_DELAYS_MS = [400, 1200]

async function attemptWithRetries(url: string, stillWanted: () => boolean): Promise<string> {
  let lastError: unknown

  for (let attempt = 0; attempt <= RETRY_DELAYS_MS.length; attempt++) {
    try {
      return await fetchAsObjectUrl(url)
    } catch (e) {
      lastError = e

      // An ANSWER, not a blip — asking again produces the same answer.
      if (e instanceof MediaFetchError && ! e.isTransient) break

      const delay = RETRY_DELAYS_MS[attempt]
      if (delay === undefined || ! stillWanted()) break

      await new Promise((resolve) => setTimeout(resolve, delay))

      if (! stillWanted()) break
    }
  }

  throw lastError
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
      const result = await attemptWithRetries(url, () => currentTracked === url)
      // A newer load() may have started while this one was in flight;
      // that one owns `currentTracked` now, and writing here would show
      // a stale image.
      if (currentTracked === url) objectUrl.value = result
    } catch (e) {
      error.value = e instanceof MediaFetchError && ! e.isTransient
        ? 'ไม่พบไฟล์สื่อนี้'
        : 'โหลดสื่อไม่สำเร็จ'
      if (currentTracked === url) {
        release(url)
        currentTracked = null
      }
    } finally {
      loading.value = false
    }
  }

  /**
   * TASK-224 — re-attempt the CURRENT source url by hand.
   *
   * Auto-retry above covers a blip. This covers everything it deliberately
   * does not: a 404 the admin has since fixed by re-uploading, a 403 that
   * a re-login cleared, or simply a user who wants to try again rather
   * than reload the whole page. Safe to call at any time — it goes through
   * the same load(), so it retains/releases correctly and cannot
   * double-count a reference.
   */
  function retry(): void {
    void load(sourceUrl.value)
  }

  watch(sourceUrl, (url) => load(url), { immediate: true })
  onUnmounted(() => release(currentTracked))

  return { objectUrl, loading, error, retry }
}
