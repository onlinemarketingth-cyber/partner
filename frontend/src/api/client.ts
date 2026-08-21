/**
 * Base API client for Sync Vision Agent.
 *
 * Talks to the Laravel API (Sanctum, cookie-based SPA auth) at
 * VITE_API_BASE_URL. All endpoints live under /api/v1 per CLAUDE.md
 * Section 3 (API versioning).
 *
 * Business logic must never live here or in any Vue component — this
 * file only handles transport (CSRF cookie handshake, headers, JSON).
 */

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL as string

function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))
  return match?.[2] ? decodeURIComponent(match[2]) : null
}

/**
 * Delete a stale XSRF-TOKEN left over from the parent-domain cookie era.
 *
 * ── THE INCIDENT THIS FIXES (2026-08-21, production) ──
 *
 * ADR-039 step 5 removed SESSION_DOMAIN, so Laravel stopped issuing the
 * XSRF-TOKEN scoped to `.partner.syncvision.io` and started issuing a
 * host-only one. Every browser that had visited before was then holding
 * BOTH, with the same name and different scopes.
 *
 * `document.cookie` returns both, getCookie() above matches the FIRST, and
 * the first is whichever the browser feels like — in practice the stale one.
 * The client then sent a token belonging to a cookie the server no longer
 * issues, and every login died with 419 CSRF mismatch. Read-only pages were
 * unaffected, which is what made it look like a login bug rather than a
 * cookie bug.
 *
 * ── WHY THE APP HAS TO DO THIS AND NOT THE SERVER ──
 *
 * The server cannot delete a cookie it no longer scopes: a Set-Cookie that
 * clears the old one would have to name the old Domain, which is exactly
 * the thing we just stopped doing. The browser holding it is the only party
 * that can drop it, and JS may delete a parent-domain cookie by naming the
 * same domain. So this runs here, once, at module load.
 *
 * ── SELF-LIMITING ON PURPOSE ──
 *
 * Only fires when there is genuinely MORE THAN ONE cookie of this name. In
 * the steady state — everyone past the migration, or a browser that never
 * saw the old scope — it touches nothing at all and costs one string split.
 * Cookie-deleting code that runs unconditionally, forever, is the kind of
 * thing that quietly logs somebody out three years from now.
 *
 * Safe to delete this function once no active user can still be carrying a
 * cookie from before 2026-08-21.
 */
function purgeDuplicateXsrfCookies(): void {
  try {
    if (typeof document === 'undefined') return

    const duplicates = document.cookie.split('; ').filter((c) => c.startsWith('XSRF-TOKEN=')).length
    if (duplicates < 2) return

    // Walk the parent domains of the current host and clear the name at each
    // scope. The host-only cookie carries no Domain attribute, so none of
    // these match it and the good one survives. Stops at two labels: a
    // browser rejects a cookie set on a public suffix anyway.
    const labels = window.location.hostname.split('.')
    for (let i = 0; i <= labels.length - 2; i++) {
      const domain = labels.slice(i).join('.')
      document.cookie = `XSRF-TOKEN=; Max-Age=0; path=/; domain=.${domain}`
    }
  } catch {
    // A browser that will not let us read or write cookies is a browser
    // where the app has larger problems than a stale token. Never throw
    // from module scope — that white-screens everything downstream.
  }
}

purgeDuplicateXsrfCookies()

/** Must be called once (e.g. before login) to obtain the XSRF-TOKEN cookie. */
export async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${API_BASE_URL}/sanctum/csrf-cookie`, {
    credentials: 'include',
  })
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: unknown,
  ) {
    super(`API error ${status}`)
  }
}

// Bug fix: the router guard only calls authStore.fetchUser() ONCE per
// page load (status flips 'idle' -> 'ready' and stays there) — so if
// the Sanctum session expires mid-use (cookie times out, backend
// restarts, etc.), every subsequent API call 401s but the SPA keeps
// showing stale page content with a confusing raw "(401)" error
// instead of sending the person back to login. This is a transport-
// layer concern (detecting the session died), not business logic, so
// it's a plain callback hook here — main.ts wires it to the auth store
// + router once, at boot. Excludes /me itself: a 401 there during the
// normal "am I logged in?" check is expected and already handled by
// authStore.fetchUser()'s own catch block, not an error to react to.
let unauthorizedHandler: (() => void) | null = null
export function setUnauthorizedHandler(handler: () => void): void {
  unauthorizedHandler = handler
}
function notifyIfUnauthorized(path: string, status: number): void {
  if (status === 401 && path !== '/me') {
    unauthorizedHandler?.()
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')

  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
    ...options,
    headers,
    credentials: 'include',
  })

  notifyIfUnauthorized(path, res.status)

  const isJson = res.headers.get('content-type')?.includes('application/json')
  const body = isJson ? await res.json() : await res.text()

  if (!res.ok) throw new ApiError(res.status, body)
  return body as T
}

/** multipart/form-data POST (file uploads) — never JSON.stringify a FormData body, and never set Content-Type manually (the browser must add the multipart boundary itself). */
async function requestForm<T>(path: string, formData: FormData): Promise<T> {
  const headers = new Headers()
  headers.set('Accept', 'application/json')

  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
    method: 'POST',
    headers,
    body: formData,
    credentials: 'include',
  })

  notifyIfUnauthorized(path, res.status)

  const isJson = res.headers.get('content-type')?.includes('application/json')
  const body = isJson ? await res.json() : await res.text()

  if (!res.ok) throw new ApiError(res.status, body)
  return body as T
}

/**
 * Streams an authenticated file download and saves it under the given
 * filename. Never link a raw/public file URL (Section 5 rule 6) — the
 * browser must carry the same session cookie + XSRF token as any other
 * API call, so this goes through fetch(), not a plain <a href>.
 */
async function requestDownload(path: string, filename: string): Promise<void> {
  const headers = new Headers()
  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
    method: 'GET',
    headers,
    credentials: 'include',
  })

  notifyIfUnauthorized(path, res.status)

  if (!res.ok) {
    const isJson = res.headers.get('content-type')?.includes('application/json')
    throw new ApiError(res.status, isJson ? await res.json() : await res.text())
  }

  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

/**
 * TASK-144 / ADR-028 §2.2 — same save-a-file behaviour as
 * requestDownload(), but for an ABSOLUTE URL.
 *
 * `stream_url` / `inline_url` come back from ModuleLessonResource as
 * full absolute URLs (Laravel's route() helper), not `/api/v1`-relative
 * paths, so requestDownload()'s prefixing would produce a broken URL.
 * Everything else is identical and for the same reason: the file lives
 * behind an authenticated route (§5 rule 6), so a plain `<a href>` —
 * which cannot carry the Sanctum session cookie cross-origin — is not
 * an option. Same absolute-URL exception useAuthenticatedMedia.ts and
 * PdfViewerModal.vue already make.
 *
 * The filename is taken from the server's Content-Disposition when the
 * caller does not supply one: uploaded lesson files are stored as
 * `{uuid}.{ext}`, and the endpoint is the only thing that knows it.
 */
async function requestDownloadAbsolute(url: string, filename?: string): Promise<void> {
  const headers = new Headers()
  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const res = await fetch(url, { method: 'GET', headers, credentials: 'include' })

  if (!res.ok) {
    const isJson = res.headers.get('content-type')?.includes('application/json')
    throw new ApiError(res.status, isJson ? await res.json() : await res.text())
  }

  const header = res.headers.get('content-disposition') ?? ''
  const match = header.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)
  const resolved = filename ?? (match?.[1] ? decodeURIComponent(match[1]) : 'download')

  const blob = await res.blob()
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = resolved
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(objectUrl)
}

/**
 * Fetches a sanctum-protected binary endpoint (video/image stream,
 * thumbnail) as a Blob. Unlike requestDownload(), this does NOT trigger
 * a file save — the caller turns the blob into an object URL for
 * inline <img>/<video> display (ADR-007). Cross-origin + credentialed,
 * same reasoning as requestDownload(): a plain <img src="..."> cannot
 * carry the Sanctum session cookie here, so every authenticated media
 * element must go through fetch() first. Ported verbatim from
 * frontend-admin/src/api/client.ts (Agent Portal needs the same
 * product media gallery + Academy video playback).
 */
async function requestBlob(path: string): Promise<Blob> {
  const headers = new Headers()
  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
    method: 'GET',
    headers,
    credentials: 'include',
  })

  notifyIfUnauthorized(path, res.status)

  if (!res.ok) {
    const isJson = res.headers.get('content-type')?.includes('application/json')
    throw new ApiError(res.status, isJson ? await res.json() : await res.text())
  }

  return res.blob()
}

/**
 * TASK-079 Phase 4 (2026-08-03, UX audit) — every method takes an
 * optional AbortSignal as its LAST argument.
 *
 * The audit found zero AbortControllers anywhere in src/: navigating away
 * mid-load left every in-flight request running, resolving into a view
 * that had already unmounted — wasted bandwidth on a phone connection,
 * and (worse) a stale error banner/toast firing for a screen the agent
 * had already left. A view now creates one controller for its lifetime,
 * passes `.signal` to its loads, and aborts in onUnmounted.
 *
 * Transport-only: a signal changes nothing about WHICH request is made or
 * what it sends (CLAUDE.md §7 — no business logic in the client). The
 * rejection it produces is a DOMException named 'AbortError', which
 * utils/apiError.ts::isAbortError() detects so it is never shown to the
 * user as a failure.
 */
export const api = {
  get: <T>(path: string, signal?: AbortSignal) => request<T>(path, { method: 'GET', signal }),
  post: <T>(path: string, data?: unknown, signal?: AbortSignal) =>
    request<T>(path, { method: 'POST', body: data ? JSON.stringify(data) : undefined, signal }),
  postForm: <T>(path: string, formData: FormData) => requestForm<T>(path, formData),
  put: <T>(path: string, data?: unknown, signal?: AbortSignal) =>
    request<T>(path, { method: 'PUT', body: data ? JSON.stringify(data) : undefined, signal }),
  // TASK-026 — PATCH /referrals/{id}/co-agent. `data ? ... : undefined`
  // deliberately still stringifies an explicit `null` value (e.g.
  // { co_agent_id: null }) since `null` is truthy-checked-falsy but
  // JSON.stringify(null) = "null", not undefined — only a genuinely
  // omitted `data` argument skips the body.
  patch: <T>(path: string, data?: unknown, signal?: AbortSignal) =>
    request<T>(path, { method: 'PATCH', body: data !== undefined ? JSON.stringify(data) : undefined, signal }),
  delete: <T>(path: string, signal?: AbortSignal) => request<T>(path, { method: 'DELETE', signal }),
  download: (path: string, filename: string) => requestDownload(path, filename),
  /** TASK-144 — download from an absolute, authenticated URL (stream_url). */
  downloadAbsolute: (url: string, filename?: string) => requestDownloadAbsolute(url, filename),
  getBlob: (path: string) => requestBlob(path),
}
