/**
 * 2026-09-10 (human: "ปิดหน้า admin ค้างไว้ ... กดปุ่มทำงานอะไรไม่ได้
 * ต้องกดปุ่ม refresh ถึงกลับมาทำงานได้").
 *
 * `fetch()` has no timeout. A request issued over a connection that has since
 * died — the laptop slept, the Wi-Fi changed — never settles: it is not
 * refused and it does not fail. Every screen here awaits that promise, so
 * `loading` stays true, every `:disabled="saving"` button stays dead, and the
 * page looks like a working page that has stopped caring. Nothing is logged
 * and nothing is shown; the only way out is a reload.
 *
 * These tests pin the two halves of the fix: it gives up, and what it says
 * when it does. A timeout the user cannot see is the same bug with a timer.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError } from '../client'

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

    const pending = api.get('/users')
    const assertion = expect(pending).rejects.toBeInstanceOf(ApiError)

    await vi.advanceTimersByTimeAsync(31_000)
    await assertion
  })

  it('says something a person can act on', async () => {
    // The message is the whole point: every view already renders ApiError's
    // message, so this is what turns a dead screen into a visible failure.
    hangUntilAborted()

    const pending = api.get('/users').catch((e) => e)
    await vi.advanceTimersByTimeAsync(31_000)
    const error = await pending as ApiError

    expect(error.message).toContain('ลองใหม่')
  })

  it('names the real problem when the device is simply offline', async () => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: false })
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    const error = (await api.get('/users').catch((e) => e)) as ApiError

    expect(error.message).toContain('ไม่ได้เชื่อมต่ออินเทอร์เน็ต')
  })

  it('never signs anybody out over a dropped connection', async () => {
    /*
     * Status 0 is not an HTTP answer and must never be mistaken for one. If a
     * lost connection were treated like a 401, sleeping the laptop would end
     * the session — and the person would be sent to the login screen holding
     * a form they had not saved.
     */
    hangUntilAborted()

    const pending = api.get('/users').catch((e) => e)
    await vi.advanceTimersByTimeAsync(31_000)
    const error = await pending as ApiError

    expect(error.status).toBe(0)
  })

  it('leaves a real HTTP failure exactly as it was', async () => {
    // The deadline must not swallow or reword the server's own answer.
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ message: 'ไม่พบข้อมูล' }), {
      status: 404,
      headers: { 'content-type': 'application/json' },
    }))

    const error = (await api.get('/users/9').catch((e) => e)) as ApiError

    expect(error.status).toBe(404)
    expect(error.message).toBe('ไม่พบข้อมูล')
  })

  it('does not cut off a slow upload at the JSON deadline', async () => {
    /*
     * A real upload on a bad connection legitimately takes minutes, so
     * transfers get their own, longer window. Cutting one off at 30 seconds
     * would be a new bug rather than a fix.
     */
    hangUntilAborted()

    let settled = false
    const pending = api.postForm('/product-media', new FormData()).catch(() => { settled = true })

    await vi.advanceTimersByTimeAsync(60_000)
    expect(settled).toBe(false)

    await vi.advanceTimersByTimeAsync(70_000)
    await pending
    expect(settled).toBe(true)
  })
})
