/**
 * Base API client for Live to 100 Club.
 *
 * Talks to the Laravel API (Sanctum, cookie-based SPA auth) at
 * VITE_API_BASE_URL. All endpoints live under /api/v1 per CLAUDE.md
 * Section 3 (API versioning).
 *
 * Business logic must never live here or in any Vue component — this
 * file only handles transport (CSRF cookie handshake, headers, JSON).
 */

import { readPersistedActiveCompanyId } from '@/utils/activeCompanyStorage'

/**
 * TASK-241 — falls back to '' (relative / same-origin) when unset. See
 * frontend/src/api/client.ts's identical fallback for the full reasoning;
 * this app has no build targeting a new host today, but the two clients
 * are meant to stay in step (docblock elsewhere in this file already
 * notes code "ported verbatim" between them).
 */
const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? ''

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

/**
 * Must be called once (e.g. before login) to obtain the XSRF-TOKEN cookie.
 *
 * 2026-09-10 — under the same deadline as everything else. This one is the
 * first request of a login, so a hang here is a login button that spins
 * forever with nothing to click.
 */
export async function ensureCsrfCookie(): Promise<void> {
  const deadline = withDeadline(REQUEST_TIMEOUT_MS)

  try {
    await fetch(`${API_BASE_URL}/sanctum/csrf-cookie`, {
      credentials: 'include',
      signal: deadline.signal,
    })
  } catch (error) {
    throw transportError(error, deadline.timedOut())
  } finally {
    deadline.done()
  }
}

/**
 * 2026-09-10 (human: "ปิดหน้า admin ค้างไว้ ... กดปุ่มทำงานอะไรไม่ได้
 * ต้องกดปุ่ม refresh ถึงกลับมาทำงานได้").
 *
 * ── WHY A TIMEOUT AT ALL ──
 *
 * `fetch()` HAS NO TIMEOUT. A request issued over a connection that has
 * since died — the laptop slept, the Wi-Fi changed, the phone left the
 * building — is not refused and does not fail; it simply never settles.
 *
 * Every screen in this console awaits that promise: `loading` stays true,
 * `saving` stays true, and every button bound to `:disabled="saving"` stays
 * dead. Nothing is logged, nothing is shown, and the page looks exactly like
 * a working page that has stopped caring. The only way out is a reload, which
 * is precisely what was reported.
 *
 * With a deadline the same situation surfaces as an error message the screen
 * already knows how to render, and the buttons come back.
 *
 * ── TWO NUMBERS ──
 *
 * 30s for JSON. Long enough that a slow report never trips it (the slowest
 * endpoint in this app answers well inside a second), short enough that a
 * person has not yet decided the app is broken.
 *
 * 120s for transfers. Uploads and media streams legitimately take minutes on
 * a bad connection, and cutting off a real upload at 30 seconds would be a
 * new bug rather than a fix.
 */
const REQUEST_TIMEOUT_MS = 30_000
const TRANSFER_TIMEOUT_MS = 120_000

interface Deadline {
  signal: AbortSignal
  /** True once the deadline itself fired — see transportError(). */
  timedOut: () => boolean
  done: () => void
}

/**
 * The deadline stays armed until the BODY has been read, not just until the
 * headers arrive — a response whose stream stalls half way through hangs the
 * caller exactly as thoroughly as one that never starts.
 *
 * `external` is a caller's own signal. Nothing in THIS app passes one today
 * (the Agent Portal's copy of this file does, for views that abort their loads
 * on unmount), but it is chained rather than replaced so the first caller to
 * want cancellation does not have to discover that the deadline silently
 * disabled it.
 */
function withDeadline(timeoutMs: number, external?: AbortSignal | null): Deadline {
  const controller = new AbortController()
  let timedOut = false

  const timer = setTimeout(() => {
    timedOut = true
    controller.abort()
  }, timeoutMs)

  const forward = () => controller.abort()
  if (external?.aborted) forward()
  external?.addEventListener('abort', forward)

  return {
    signal: controller.signal,
    timedOut: () => timedOut,
    done: () => {
      clearTimeout(timer)
      external?.removeEventListener('abort', forward)
    },
  }
}

/**
 * Turn "the request never happened" into something the UI can show.
 *
 * A deadline firing and a caller cancelling both arrive here as an AbortError
 * and mean opposite things: a cancellation is not a failure and is handed back
 * untouched, while a timeout is the failure this whole change exists to make
 * visible.
 *
 * Status 0 on purpose: it is not an HTTP answer, and it must never be
 * mistaken for one — notifyIfUnauthorized() only reacts to 401/419, so a
 * dropped connection can never sign somebody out.
 */
function transportError(error: unknown, timedOut: boolean): unknown {
  if (error instanceof ApiError) return error

  // `name`, not `instanceof Error`: a browser's AbortError is a DOMException,
  // which does NOT inherit from Error.
  const aborted = typeof error === 'object' && error !== null && (error as { name?: string }).name === 'AbortError'
  if (aborted && !timedOut) return error

  const offline = typeof navigator !== 'undefined' && navigator.onLine === false

  return new ApiError(0, {
    message: offline
      ? 'อุปกรณ์นี้ไม่ได้เชื่อมต่ออินเทอร์เน็ตอยู่ — เชื่อมต่อแล้วลองใหม่อีกครั้ง'
      : 'เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จหรือใช้เวลานานเกินไป — กรุณาลองใหม่อีกครั้ง',
  })
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: unknown,
  ) {
    super(ApiError.extractMessage(status, body))
  }

  // Bug fix 2026-07-20 (human-reported: sales-material upload failed
  // with no usable reason shown): this used to always be the generic
  // `API error ${status}`, discarding Laravel's actual validation
  // message/errors body entirely. Every upload surface in the app reads
  // ApiError.message to show the user why something failed —
  // MediaUploadModal.vue's per-file error row, and this file's own
  // apiErrorMessage() helper — so fixing it here propagates the real
  // reason (e.g. "The file field must not be greater than 15360
  // kilobytes.") everywhere at once instead of guessing per call site.
  private static extractMessage(status: number, body: unknown): string {
    if (body && typeof body === 'object') {
      const b = body as { message?: string; errors?: Record<string, string[]> }
      const firstFieldError = b.errors ? Object.values(b.errors)[0]?.[0] : undefined
      if (firstFieldError) return firstFieldError
      if (b.message) return b.message
    }

    // TASK-092 (2026-08-03, human uploaded a 44MB clip and got the raw
    // "API error 413"). A 413 never reaches Laravel's JSON error handler:
    // PHP itself rejects the request once Content-Length exceeds
    // `post_max_size`, so the body is empty or HTML and the block above
    // finds nothing to show. The status is the whole message, and
    // "API error 413" tells an admin nothing actionable — this does.
    if (status === 413) {
      return 'ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้ (PHP post_max_size / upload_max_filesize) — กรุณาย่อไฟล์ก่อน หรือให้ผู้ดูแลเพิ่มค่าใน php.ini'
    }

    return `API error ${status}`
  }
}

// Bug fix (ported from frontend/src/api/client.ts): the router guard
// only calls authStore.fetchUser() ONCE per page load, so a session
// that expires mid-use (cookie times out, backend restarts, etc.)
// left every subsequent call 401ing with the SPA still showing stale
// page content instead of returning to login. Transport-layer concern
// (detecting the session died), not business logic — a plain callback
// hook, wired to the auth store + router once from main.ts. Excludes
// /me itself: a 401 there during the normal "am I logged in?" check is
// expected and already handled by authStore.fetchUser()'s own catch.
let unauthorizedHandler: (() => void) | null = null
export function setUnauthorizedHandler(handler: () => void): void {
  unauthorizedHandler = handler
}
/**
 * 2026-09-09 (human: "ตอนนี้ session หมดอายุ ไม่เด้งไปที่ login").
 *
 * 419 counts, and it is the one that was actually happening.
 *
 * This console authenticates with a Laravel SESSION COOKIE, and the CSRF
 * token is derived from that session. When the session times out, a GET
 * answers 401 — handled since the original fix — but a POST/PUT/DELETE
 * answers **419 CSRF token mismatch** before authentication is even reached.
 * So browsing after an expiry bounced correctly and SAVING did not: the admin
 * got a raw "(419)" over a form still full of their work, with no way to tell
 * that the only thing wrong was that they had been signed out.
 *
 * That is also why this was the complaint about saving rather than about
 * reading, and why it looked intermittent.
 */
function notifyIfUnauthorized(path: string, status: number): void {
  if ((status === 401 || status === 419) && path !== '/me') {
    unauthorizedHandler?.()
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')

  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const deadline = withDeadline(REQUEST_TIMEOUT_MS, options.signal)

  try {
    const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
      ...options,
      headers,
      credentials: 'include',
      signal: deadline.signal,
    })

    notifyIfUnauthorized(path, res.status)

    const isJson = res.headers.get('content-type')?.includes('application/json')
    const body = isJson ? await res.json() : await res.text()

    if (!res.ok) throw new ApiError(res.status, body)

    return body as T
  } catch (error) {
    throw transportError(error, deadline.timedOut())
  } finally {
    deadline.done()
  }
}

/** multipart/form-data POST (file uploads) — never JSON.stringify a FormData body, and never set Content-Type manually (the browser must add the multipart boundary itself). Ported from frontend/src/api/client.ts — profile avatar/background-image uploads need this here too. */
async function requestForm<T>(path: string, formData: FormData): Promise<T> {
  const headers = new Headers()
  headers.set('Accept', 'application/json')

  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  // TRANSFER_TIMEOUT_MS, not the JSON one: a real upload on a bad connection
  // legitimately takes minutes.
  const deadline = withDeadline(TRANSFER_TIMEOUT_MS)

  try {
    const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
      method: 'POST',
      headers,
      body: formData,
      credentials: 'include',
      signal: deadline.signal,
    })

    notifyIfUnauthorized(path, res.status)

    const isJson = res.headers.get('content-type')?.includes('application/json')
    const body = isJson ? await res.json() : await res.text()

    if (!res.ok) throw new ApiError(res.status, body)

    return body as T
  } catch (error) {
    throw transportError(error, deadline.timedOut())
  } finally {
    deadline.done()
  }
}

function filenameFromContentDisposition(res: Response): string {
  const header = res.headers.get('content-disposition') ?? ''
  const match = header.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)
  return match?.[1] ? decodeURIComponent(match[1]) : 'download'
}

/**
 * Streams an authenticated file download and saves it under the given
 * filename. Never link a raw/public file URL (Section 5 rule 6) — the
 * browser must carry the same session cookie + XSRF token as any other
 * API call, so this goes through fetch(), not a plain <a href>. Ported
 * verbatim from frontend/src/api/client.ts (Phase 8 — Admin needs to
 * view client documents too).
 *
 * `filename` is optional — omit it for endpoints whose model has no
 * original_filename field (e.g. product media / spec attachments, which
 * store an internally generated UUID filename); the server's
 * Content-Disposition header (Storage::download()'s own filename) is
 * used instead (human-requested 2026-07-19 — download icon on
 * image/PDF tiles).
 */
async function requestDownload(path: string, filename?: string): Promise<void> {
  const headers = new Headers()
  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const deadline = withDeadline(TRANSFER_TIMEOUT_MS)

  let res: Response
  let blob: Blob
  let resolvedFilename: string
  try {
    res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
      method: 'GET',
      headers,
      credentials: 'include',
      signal: deadline.signal,
    })

    notifyIfUnauthorized(path, res.status)

    if (!res.ok) {
      const isJson = res.headers.get('content-type')?.includes('application/json')
      throw new ApiError(res.status, isJson ? await res.json() : await res.text())
    }

    resolvedFilename = filename ?? filenameFromContentDisposition(res)
    blob = await res.blob()
  } catch (error) {
    throw transportError(error, deadline.timedOut())
  } finally {
    deadline.done()
  }

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = resolvedFilename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

/**
 * Fetches a sanctum-protected binary endpoint (video/image stream,
 * thumbnail) as a Blob. Unlike requestDownload(), this does NOT trigger
 * a file save — the caller turns the blob into an object URL for
 * inline <img>/<video> display (ADR-007). Cross-origin + credentialed,
 * same reasoning as requestDownload(): a plain <img src="..."> cannot
 * carry the Sanctum session cookie here, so every authenticated media
 * element must go through fetch() first.
 */
async function requestBlob(path: string): Promise<Blob> {
  const headers = new Headers()
  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

  const deadline = withDeadline(TRANSFER_TIMEOUT_MS)

  try {
    const res = await fetch(`${API_BASE_URL}/api/v1${path}`, {
      method: 'GET',
      headers,
      credentials: 'include',
      signal: deadline.signal,
    })

    notifyIfUnauthorized(path, res.status)

    if (!res.ok) {
      const isJson = res.headers.get('content-type')?.includes('application/json')
      throw new ApiError(res.status, isJson ? await res.json() : await res.text())
    }

    return await res.blob()
  } catch (error) {
    throw transportError(error, deadline.timedOut())
  } finally {
    deadline.done()
  }
}

/**
 * Represents an in-flight upload — returned instead of a bare Promise so
 * the caller can also call .abort() (e.g. a Cancel button on an
 * upload-progress row).
 */
export interface ProgressUpload<T> {
  promise: Promise<T>
  abort: () => void
}

/**
 * multipart/form-data POST with real byte-level progress events — the
 * rest of this client is fetch()-based (no upload progress API in fetch
 * without a ReadableStream request body, which Safari doesn't support
 * for uploads), so this one path deliberately uses XMLHttpRequest
 * instead. Added for the media-gallery upload modal (percentage +
 * cancel button, matching the reference design) — every other upload in
 * the app keeps using the plain api.postForm() (no progress UI needed
 * there).
 */
function requestFormWithProgress<T>(path: string, formData: FormData, onProgress: (fraction: number) => void): ProgressUpload<T> {
  const xhr = new XMLHttpRequest()

  const promise = new Promise<T>((resolve, reject) => {
    xhr.open('POST', `${API_BASE_URL}/api/v1${path}`)
    xhr.withCredentials = true
    xhr.setRequestHeader('Accept', 'application/json')
    const xsrfToken = getCookie('XSRF-TOKEN')
    if (xsrfToken) xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken)

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) onProgress(e.loaded / e.total)
    }

    xhr.onload = () => {
      notifyIfUnauthorized(path, xhr.status)
      const isJson = xhr.getResponseHeader('content-type')?.includes('application/json')
      let body: unknown
      try {
        body = isJson ? JSON.parse(xhr.responseText) : xhr.responseText
      } catch {
        body = xhr.responseText
      }
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(body as T)
      } else {
        reject(new ApiError(xhr.status, body))
      }
    }
    xhr.onerror = () => reject(new ApiError(0, null))
    xhr.onabort = () => reject(new ApiError(0, null))

    xhr.send(formData)
  })

  return { promise, abort: () => xhr.abort() }
}

/**
 * TASK-094 — send one file as a sequence of small requests and return the
 * server-issued token that stands in for it.
 *
 * WHY: PHP enforces `post_max_size` PER REQUEST, so a 44MB video 413'd on
 * both MAMP and the production host (Hostinger shared). Slicing it means
 * no environment has to raise a PHP limit — which was the explicit
 * constraint from the human ("ถ้าไปปรับขนาดจะมีปัญหากับ production").
 *
 * Chunks go SEQUENTIALLY, not in parallel: the server appends each one to
 * a single .part file (one inode per upload, deliberate — the production
 * host is at 306K of a 600K inode quota), so order matters and two
 * concurrent appends would interleave bytes.
 *
 * `is_last` is sent explicitly on the final chunk rather than letting the
 * server infer completion from a byte count. Inference breaks the moment
 * a chunk is retried, and a half-finished file that claims to be complete
 * is far worse than one that stalls.
 *
 * Returns a ProgressUpload so the existing modal keeps its progress bar
 * and Cancel button; `abort()` stops before the next chunk is sent.
 */
function uploadInChunks(
  file: File,
  onProgress: (fraction: number) => void,
): ProgressUpload<string> {
  let aborted = false

  const promise = (async (): Promise<string> => {
    // TASK-226 - a Super Admin belongs to no company, so the server cannot
    // read the size ceiling off the session alone; without this it fell
    // back to the platform default (200 MB) and ignored whatever the
    // company's own "ขนาดไฟล์สูงสุด" setting said. Read straight from
    // storage rather than the store: stores/activeCompany imports THIS
    // module, so importing it back would be a cycle - see
    // utils/activeCompanyStorage for the full reasoning.
    // null = "ทุกบริษัท": send nothing and let the platform default apply.
    const activeCompanyId = readPersistedActiveCompanyId()

    const init = await request<{ data: { token: string; chunk_bytes: number } }>('/uploads/init', {
      method: 'POST',
      body: JSON.stringify({
        filename: file.name,
        mime_type: file.type || null,
        size_bytes: file.size,
        ...(activeCompanyId === null ? {} : { company_id: activeCompanyId }),
      }),
    })

    const { token, chunk_bytes: chunkBytes } = init.data

    for (let offset = 0; offset < file.size; offset += chunkBytes) {
      if (aborted) throw new ApiError(0, null)

      const end = Math.min(offset + chunkBytes, file.size)
      const form = new FormData()
      form.append('chunk', file.slice(offset, end))
      if (end >= file.size) form.append('is_last', '1')

      await requestForm(`/uploads/${token}/chunk`, form)

      // Progress is per-chunk, not per-byte: XHR byte events would only
      // describe the 5MB slice in flight, which would make the bar reset
      // on every chunk instead of advancing across the whole file.
      onProgress(end / file.size)
    }

    return token
  })()

  return { promise, abort: () => { aborted = true } }
}

/**
 * TASK-094 — create a media record from a file of any size.
 *
 * Small files keep the original single-request path: init + chunk +
 * create would be three round trips to upload a 90KB thumbnail. Anything
 * that could plausibly exceed a default `post_max_size` goes through the
 * chunked transport and hands the create endpoint an `upload_token`
 * instead of a `file` — the ResolveChunkedUpload middleware turns it back
 * into a normal uploaded file before validation, so the endpoint's mime
 * and size rules apply identically either way.
 *
 * DIRECT_LIMIT_BYTES was 4MB in the first version and that was wrong: a
 * 2.0MB mp4 still failed with "The file failed to upload." because PHP's
 * stock `upload_max_filesize` is 2M — a SECOND limit, applied per file,
 * that the 4MB figure (derived from post_max_size) ignored entirely.
 *
 * 1MB now, comfortably under the smallest default either limit takes.
 * Anything larger goes through the chunked path, where the server tells
 * us the real per-chunk ceiling it can accept (see /uploads/init) rather
 * than the client guessing a second time.
 */
const DIRECT_LIMIT_BYTES = 1024 * 1024

function postFileWithProgress<T>(
  path: string,
  file: File,
  fields: Record<string, string>,
  onProgress: (fraction: number) => void,
): ProgressUpload<T> {
  if (file.size <= DIRECT_LIMIT_BYTES) {
    const form = new FormData()
    Object.entries(fields).forEach(([k, v]) => form.append(k, v))
    form.append('file', file)

    return requestFormWithProgress<T>(path, form, onProgress)
  }

  const chunked = uploadInChunks(file, (f) => onProgress(f * 0.95))

  const promise = chunked.promise.then(async (token) => {
    const form = new FormData()
    Object.entries(fields).forEach(([k, v]) => form.append(k, v))
    form.append('upload_token', token)

    const created = await requestForm<T>(path, form)
    onProgress(1)

    return created
  })

  return { promise, abort: chunked.abort }
}

export const api = {
  get: <T>(path: string) => request<T>(path, { method: 'GET' }),
  post: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'POST', body: data ? JSON.stringify(data) : undefined }),
  postForm: <T>(path: string, formData: FormData) => requestForm<T>(path, formData),
  postFormWithProgress: <T>(path: string, formData: FormData, onProgress: (fraction: number) => void) =>
    requestFormWithProgress<T>(path, formData, onProgress),
  /** TASK-094 — chunked transport for large files; resolves to an upload_token. */
  uploadInChunks: (file: File, onProgress: (fraction: number) => void) =>
    uploadInChunks(file, onProgress),
  /** TASK-094 — create-with-file that transparently chunks anything large. */
  postFileWithProgress: <T>(
    path: string,
    file: File,
    fields: Record<string, string>,
    onProgress: (fraction: number) => void,
  ) => postFileWithProgress<T>(path, file, fields, onProgress),
  put: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'PUT', body: data ? JSON.stringify(data) : undefined }),
  patch: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'PATCH', body: data ? JSON.stringify(data) : undefined }),
  // `data` is optional — every pre-existing caller passes none and keeps
  // working unchanged. Added for DELETE /products/{product}/catalog-link
  // (ADR-036 unlink), which per the backend contract must carry a JSON
  // body (the product's new standalone name/brand_id/category_id) — the
  // one DELETE endpoint in this app that isn't just "delete by id".
  delete: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'DELETE', body: data ? JSON.stringify(data) : undefined }),
  download: (path: string, filename?: string) => requestDownload(path, filename),
  getBlob: (path: string) => requestBlob(path),
}

