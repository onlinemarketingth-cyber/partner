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

const objectUrlCache = new Map<string, string>()
const refCounts = new Map<string, number>()

async function fetchAsObjectUrl(sourceUrl: string): Promise<string> {
  const cached = objectUrlCache.get(sourceUrl)
  if (cached) return cached

  const headers = new Headers()
  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const res = await fetch(sourceUrl, { method: 'GET', headers, credentials: 'include' })
  if (!res.ok) throw new Error(`Failed to load media (${res.status})`)

  const blob = await res.blob()
  const objectUrl = URL.createObjectURL(blob)
  objectUrlCache.set(sourceUrl, objectUrl)
  return objectUrl
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
    release(currentTracked)
    currentTracked = null
    objectUrl.value = null
    error.value = ''

    if (!url) return

    loading.value = true
    try {
      const result = await fetchAsObjectUrl(url)
      refCounts.set(url, (refCounts.get(url) ?? 0) + 1)
      currentTracked = url
      objectUrl.value = result
    } catch {
      error.value = 'โหลดสื่อไม่สำเร็จ'
    } finally {
      loading.value = false
    }
  }

  watch(sourceUrl, (url) => load(url), { immediate: true })
  onUnmounted(() => release(currentTracked))

  return { objectUrl, loading, error }
}
