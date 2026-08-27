/**
 * Base API client for Sync Vision Agent.
 *
 * ── 2026-08-27: COOKIE SESSION -> BEARER TOKEN ──
 *
 * This app is served from more than one first-party host
 * (partner.syncvision.io and the Parked Domain alias
 * apps.liveto100club.com). A Sanctum session cookie is HOST-ONLY by
 * design, so a cookie set while visiting one of those hosts can never be
 * read or sent by a page loaded from the other — that is a browser
 * boundary, not a CORS setting, and no server config can move it.
 *
 * So the agent portal now authenticates with a Sanctum personal access
 * token: /login returns one (the request opts in via X-Auth-Mode, see
 * authHeaders below), it is stored client-side, and every subsequent call
 * carries it in an Authorization header instead of relying on a cookie.
 * Tokens are host-agnostic, so the same build works on any first-party
 * domain.
 *
 * THE ADMIN CONSOLE (frontend-admin) IS DELIBERATELY UNCHANGED — it has
 * its own copy of this file and stays on cookie-session auth. It runs on
 * exactly one host, so it never had this problem, and leaving it alone
 * keeps the blast radius of this change to one app.
 *
 * ── WHAT THIS TRADES AWAY ──
 *
 * An httpOnly cookie cannot be read by JavaScript; a token in
 * localStorage can. That makes any XSS in this app a full session theft
 * rather than a scoped nuisance, which is a real cost in an app that
 * moves money. The mitigations that come with it: the token carries a
 * server-side 12h expiry (Sanctum's default is none at all), logout
 * revokes it server-side rather than merely forgetting it, and any 401
 * purges it immediately (see notifyIfUnauthorized). None of that
 * substitutes for not having XSS.
 *
 * All endpoints live under /api/v1 per CLAUDE.md Section 3 (API
 * versioning). Business logic must never live here or in any Vue
 * component — this file only handles transport (auth header, headers,
 * JSON).
 */

/**
 * TASK-241 — falls back to '' (relative / same-origin) when unset, rather
 * than trusting the env var is always a real host string.
 *
 * With token auth this may safely be an ABSOLUTE, cross-origin URL: there
 * is no cookie to be blocked and no CSRF handshake to complete, only a
 * CORS allowance on the server (config/cors.php's CORS_EXTRA_ORIGINS).
 */
const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? ''

/**
 * Where the token lives between page loads.
 *
 * localStorage (not sessionStorage) so a refresh or a second tab does not
 * throw the person back to the login screen. Every read/write is wrapped:
 * a browser with storage blocked (private mode on some platforms, a
 * hardened profile) must still run the app for the length of one page
 * view rather than white-screen at module scope.
 */
const TOKEN_KEY = 'sva_token'

let token: string | null = (() => {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
})()

/** The token this tab is currently using, if any. */
export function getToken(): string | null {
  return token
}

/**
 * Store (or, with null, forget) the auth token.
 *
 * Called by stores/auth.ts on login and logout, and by
 * notifyIfUnauthorized() below the moment the server tells us the token
 * is no longer good.
 */
export function setToken(next: string | null): void {
  token = next
  try {
    if (next) localStorage.setItem(TOKEN_KEY, next)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    // In-memory `token` above is still correct for this page view, which
    // is the most this browser can offer. Never throw from here.
  }
}

/**
 * KEPT AS A NO-OP ON PURPOSE — do not delete, and do not "clean up" the
 * four call sites that still await it (LoginView, RegisterView x3).
 *
 * Those calls exist because Sanctum applied CSRF verification to every
 * request coming from a stateful domain, including the pre-login public
 * POSTs (register, resolve-invite-code, resend-verification). The agent
 * portal's hosts are no longer stateful domains, so no CSRF token is
 * required for any of them and there is nothing left to fetch.
 *
 * Leaving the function as a resolved promise means this transport change
 * touches zero view files: fewer files changed is fewer places for a
 * merge or a rebase to go wrong, and every one of those call sites is
 * still CORRECT to call it if the auth mode is ever revisited. Delete it
 * only when removing the call sites too, in a change that is about the
 * views rather than about auth.
 */
export async function ensureCsrfCookie(): Promise<void> {
  // Intentionally empty — see the docblock above.
}

function authHeaders(headers: Headers): Headers {
  // The server mints a token ONLY for a request that asks for one
  // (AuthController::login / LoginRequest::authenticate). The admin
  // console never sends this, which is exactly how the two apps' auth
  // modes stay apart on one shared backend. Harmless on every other
  // endpoint, which simply ignores it.
  headers.set('X-Auth-Mode', 'token')
  if (token) headers.set('Authorization', `Bearer ${token}`)

  return headers
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
// the token expires or is revoked mid-use, every subsequent API call
// 401s but the SPA keeps showing stale page content with a confusing
// raw "(401)" error instead of sending the person back to login. This is
// a transport-layer concern (detecting the session died), not business
// logic, so it's a plain callback hook here — main.ts wires it to the
// auth store + router once, at boot. Excludes /me itself: a 401 there
// during the normal "am I logged in?" check is expected and already
// handled by authStore.fetchUser()'s own catch block, not an error to
// react to.
let unauthorizedHandler: (() => void) | null = null

export function setUnauthorizedHandler(handler: () => void): void {
  unauthorizedHandler = handler
}

function notifyIfUnauthorized(path: string, status: number): void {
  if (status !== 401) return

  // 2026-08-27 — a 401 means this token is dead (expired, revoked, or
  // revoked from another device). Drop it here rather than in the store:
  // a stale value left in localStorage would be replayed on every
  // subsequent page load and 401 again, which reads as "the app is
  // broken" instead of "please sign in". Runs for /me too — the token is
  // just as dead there — while the handler below still stays out of that
  // path, which fetchUser() handles itself.
  setToken(null)

  if (path !== '/me') unauthorizedHandler?.()
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')
  authHeaders(headers)

  const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
    ...options,
    headers,
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
  authHeaders(headers)

  const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
    method: 'POST',
    headers,
    body: formData,
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
 * request must carry the same Authorization header as any other API
 * call, and a plain <a href> cannot, so this goes through fetch().
 */
async function requestDownload(path: string, filename: string): Promise<void> {
  const headers = new Headers()
  authHeaders(headers)

  const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
    method: 'GET',
    headers,
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
 * which cannot carry the Authorization header — is not an option. Same
 * absolute-URL exception useAuthenticatedMedia.ts and PdfViewerModal.vue
 * already make.
 *
 * The filename is taken from the server's Content-Disposition when the
 * caller does not supply one: uploaded lesson files are stored as
 * `{uuid}.{ext}`, and the endpoint is the only thing that knows it.
 */
async function requestDownloadAbsolute(url: string, filename?: string): Promise<void> {
  const headers = new Headers()
  authHeaders(headers)

  const res = await fetch(url, { method: 'GET', headers })

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
 * Fetches an authenticated binary endpoint (video/image stream,
 * thumbnail) as a Blob. Unlike requestDownload(), this does NOT trigger
 * a file save — the caller turns the blob into an object URL for inline
 * <img>/<video> display (ADR-007).
 *
 * A plain <img src> / <video src> cannot carry an Authorization header,
 * so every authenticated media element must go through fetch() first.
 * That constraint is unchanged by the move to tokens — only the header
 * being carried is different.
 */
async function requestBlob(path: string): Promise<Blob> {
  const headers = new Headers()
  authHeaders(headers)

  const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
    method: 'GET',
    headers,
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
