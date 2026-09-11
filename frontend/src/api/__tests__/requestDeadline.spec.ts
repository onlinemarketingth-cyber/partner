/**
 * 2026-09-10 — reported on the Admin console and true of this app for the same
 * reason ("ค้างไว้ ... กดปุ่มทำงานอะไรไม่ได้ ต้องกดปุ่ม refresh ถึงกลับมาทำงานได้").
 * An agent's phone sleeps constantly, so this app meets a dead connection more
 * often than the console does, not less.
 *
 * `fetch()` has no timeout. A request issued over a connection that has since
 * died never settles: it is not refused and it does not fail. Every view
 * awaits that promise, so `loading` stays true and every button bound to
 * `:disabled="saving"` stays dead, with nothing shown and nothing logged — a
 * page that looks like it is working and is not.
 *
 * The subtle half is the LAST test. This app deliberately aborts its own loads
 * on unmount (TASK-079 Phase 4) and treats an AbortError as silence. A timeout
 * arrives as an AbortError too, so a deadline that did not distinguish the two
 * would be swallowed by that same guard — the original silence with extra
 * steps.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError } from '../client'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'

const fetchMock = vi.fn()

beforeEach(() => {
  vi.useFakeTimers()
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: true })
})

/** A request that never settles unless its AbortSignal fires — the real
 *  behaviour of a socket to a host that has stopped answering. */
function hangUntilAborted(): void {
  fetchMock.mockImplementation((_url: string, init: RequestInit) => new Promise((_resolve, reject) => {
    init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
  }))
}

describe('a request that never answers', () => {
  it('gives up instead of hanging forever', async () => {
    hangUntilAborted()

    const pending = api.get('/clients').catch((e) => e)
    await vi.advanceTimersByTimeAsync(31_000)

    expect(await pending).toBeInstanceOf(ApiError)
  })

  it('says something the agent can act on', async () => {
    hangUntilAborted()

    const pending = api.get('/clients').catch((e) => e)
    await vi.advanceTimersByTimeAsync(31_000)

    expect(apiErrorMessage(await pending)).toContain('ลองใหม่')
  })

  it('names the real problem when the phone is simply offline', async () => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: false })
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    expect(apiErrorMessage(await api.get('/clients').catch((e) => e))).toContain('ไม่ได้เชื่อมต่ออินเทอร์เน็ต')
  })

  it('never signs anybody out over a dropped connection', async () => {
    /*
     * Status 0 is not an HTTP answer and must never be mistaken for one. If a
     * lost connection were treated like a 401, an agent's phone waking from
     * sleep would drop their token — and they would land on the login screen
     * holding a form they had not saved.
     */
    hangUntilAborted()

    const pending = api.get('/clients').catch((e) => e as ApiError)
    await vi.advanceTimersByTimeAsync(31_000)

    expect((await pending as ApiError).status).toBe(0)
  })

  it('leaves a real HTTP failure exactly as it was', async () => {
    // The deadline must not swallow or reword the server's own answer.
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ message: 'ไม่พบข้อมูล' }), {
      status: 404,
      headers: { 'content-type': 'application/json' },
    }))

    const error = (await api.get('/clients/9').catch((e) => e)) as ApiError

    expect(error.status).toBe(404)
    expect(apiErrorMessage(error)).toContain('ไม่พบข้อมูล')
  })

  it('does not cut off a slow upload at the JSON deadline', async () => {
    /*
     * A real upload on a bad connection legitimately takes minutes, so
     * transfers get their own, longer window. Cutting one off at 30 seconds
     * would be a new bug rather than a fix.
     */
    hangUntilAborted()

    let settled = false
    const pending = api.postForm('/clients/1/documents', new FormData()).catch(() => { settled = true })

    await vi.advanceTimersByTimeAsync(60_000)
    expect(settled).toBe(false)

    await vi.advanceTimersByTimeAsync(70_000)
    await pending
    expect(settled).toBe(true)
  })
})

describe('a load the view cancelled itself', () => {
  it('is still recognised as a cancellation, not reported as a failure', async () => {
    /*
     * Leaving a screen mid-load aborts its requests on purpose. That is not a
     * failure and must never reach the agent — if the deadline had replaced
     * the caller's signal instead of chaining onto it, or reworded its abort,
     * every screen change would flash "connection failed".
     */
    hangUntilAborted()

    const controller = new AbortController()
    const pending = api.get('/clients', controller.signal).catch((e) => e)
    controller.abort()

    const error = await pending
    expect(isAbortError(error)).toBe(true)
    expect(apiErrorMessage(error)).toBe('')
  })
})

/**
 * 2026-09-10 — a regression guard, added after causing the regression.
 *
 * `ApiError.retryAfterSeconds` is read by RegisterView to say "กรุณารออีก 47
 * วินาที" when the sign-up form is throttled. It was lost when this file's
 * transport was rewritten for the deadline above, and nothing here noticed:
 * RegisterView's own tests mock ApiError with their own class, so they kept
 * passing against a client that no longer produced the value. Only the
 * production type-check caught it, at deploy time.
 *
 * These two tests exercise the REAL ApiError through the REAL transport,
 * which is the gap that let it through.
 */
describe('a throttled request', () => {
  it('carries the wait from the server\'s own Retry-After header', async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ message: 'Too Many Attempts.' }), {
      status: 429,
      headers: { 'content-type': 'application/json', 'Retry-After': '47' },
    }))

    const error = (await api.post('/register', {}).catch((e) => e)) as ApiError

    expect(error.status).toBe(429)
    expect(error.retryAfterSeconds).toBe(47)
  })

  it('never puts the rate limiter\'s English in front of a customer', async () => {
    /*
     * 2026-09-11 — reported from the public payment page: "Too Many
     * Attempts." shown above ฿8,900 somebody was trying to pay. It is
     * Laravel's built-in string, so it arrives on any throttled endpoint, and
     * apiErrorMessage preferred it because a server-written `message`
     * is normally the most specific thing available.
     *
     * The API answers throttles in Thai now. This pins the client half, which
     * is what protects a browser holding an older cached build.
     */
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ message: 'Too Many Attempts.' }), {
      status: 429,
      headers: { 'content-type': 'application/json' },
    }))

    const message = apiErrorMessage(await api.post('/pay/abc/intent', {}).catch((e) => e))

    expect(message).not.toContain('Too Many Attempts')
    expect(message).toContain('ถี่เกินไป')
  })

  it('invents no number when the server did not send one', async () => {
    // BR-7 — a made-up wait is a number nobody chose, and being wrong about it
    // is what teaches people to ignore the message.
    fetchMock.mockResolvedValue(new Response('{}', {
      status: 429,
      headers: { 'content-type': 'application/json' },
    }))

    const error = (await api.post('/register', {}).catch((e) => e)) as ApiError

    expect(error.retryAfterSeconds).toBeNull()
  })
})
