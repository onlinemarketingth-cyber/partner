import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, nextTick } from 'vue'

/**
 * TASK-223 — the Safari "product images show sometimes" bug, reproduced.
 *
 * Three of these five FAIL against the previous implementation, including
 * the third, which is the human's exact symptom: one card unmounting
 * blanked another card that was showing the same image.
 *
 * The module keeps its caches at module scope (deliberately — two cards
 * showing the same image must share one blob), so every test imports a
 * FRESH copy. Without that, test 1 warms the cache for test 2 and the
 * suite passes for the wrong reason.
 */
let created = 0
let revoked: string[] = []

async function freshModule() {
  vi.resetModules()
  created = 0
  revoked = []
  // jsdom always provides `document` — vitest.config.ts pins the
  // environment for the whole project — so this only clears the cookie jar
  // to keep the XSRF header deterministic between tests. An earlier
  // version also assigned a `{ cookie: '' }` fallback for a hypothetical
  // non-jsdom environment; it needed a @ts-expect-error that `vue-tsc
  // --build` then reported as UNUSED, which is TypeScript stating the
  // fallback could never be reached.
  document.cookie = ''
  // Patch the two methods, never replace globalThis.URL itself — Vite and
  // vitest both call `new URL(...)` internally, and swapping the whole
  // object for an object literal breaks the runner before a test can run.
  URL.createObjectURL = () => `blob:stub-${++created}`
  URL.revokeObjectURL = (u: string) => { revoked.push(u) }

  return (await import('../useAuthenticatedMedia')).useAuthenticatedMedia
}

/** A fetch that resolves only when the test says so. */
function deferredFetch() {
  const resolvers: Array<() => void> = []
  let calls = 0
  // @ts-expect-error test stub
  globalThis.fetch = vi.fn(() => {
    calls++

    return new Promise((resolve) => {
      resolvers.push(() => resolve({ ok: true, status: 200, blob: async () => ({}) }))
    })
  })

  return {
    flush: () => { resolvers.splice(0).forEach((r) => r()) },
    calls: () => calls,
  }
}

const settle = () => vi.advanceTimersByTimeAsync(0)
const URL_A = 'https://api.test/media/1'

describe('useAuthenticatedMedia', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    // TASK-224's retry waits 400ms then 1200ms between attempts. Fake
    // timers keep the suite instant instead of sleeping for real.
    vi.useFakeTimers()
  })

  afterEach(() => { vi.useRealTimers() })

  it('makes ONE request when two components ask for the same url at once', async () => {
    const use = await freshModule()
    const d = deferredFetch()
    const url = ref<string | null>(URL_A)

    use(url)
    use(url)
    await nextTick()

    expect(d.calls()).toBe(1)
  })

  it('gives both components the SAME object url, and creates only one blob', async () => {
    const use = await freshModule()
    const d = deferredFetch()
    const url = ref<string | null>(URL_A)

    const a = use(url)
    const b = use(url)
    await nextTick()
    d.flush()
    await settle()

    expect(created).toBe(1)
    expect(a.objectUrl.value).toBe('blob:stub-1')
    expect(b.objectUrl.value).toBe('blob:stub-1')
  })

  /** THE bug: one card unmounting blanked the other card's image. */
  it('does not revoke a blob a second component is still showing', async () => {
    const use = await freshModule()
    const d = deferredFetch()
    const urlA = ref<string | null>(URL_A)
    const urlB = ref<string | null>(URL_A)

    use(urlA)
    const b = use(urlB)
    await nextTick()
    d.flush()
    await settle()

    urlA.value = null // card A navigates away; card B is untouched
    await nextTick()

    expect(b.objectUrl.value).toBe('blob:stub-1')
    expect(revoked).not.toContain('blob:stub-1')
  })

  it('re-loading the same url still ends up displaying something', async () => {
    const use = await freshModule()
    const d = deferredFetch()
    const url = ref<string | null>(URL_A)

    const a = use(url)
    await nextTick()
    d.flush()
    await settle()

    url.value = null
    await nextTick()
    url.value = URL_A
    await nextTick()
    d.flush()
    await settle()

    expect(a.objectUrl.value).not.toBeNull()
    expect(revoked).not.toContain(a.objectUrl.value)
  })

  it('reports an error when the fetch fails', async () => {
    const use = await freshModule()
    // @ts-expect-error test stub
    globalThis.fetch = vi.fn(async () => ({ ok: false, status: 404 }))

    const a = use(ref('https://api.test/media/gone'))
    await settle()

    expect(a.objectUrl.value).toBeNull()
    expect(a.error.value).not.toBe('')
  })

  // ── TASK-224: retry ───────────────────────────────────────────────

  it('retries a transient failure and succeeds on the second attempt', async () => {
    const use = await freshModule()
    let calls = 0
    // @ts-expect-error test stub
    globalThis.fetch = vi.fn(async () => {
      calls++

      return calls === 1
        ? { ok: false, status: 503 }
        : { ok: true, status: 200, blob: async () => ({}) }
    })

    const a = use(ref(URL_A))
    await vi.advanceTimersByTimeAsync(500)

    expect(calls).toBe(2)
    expect(a.error.value).toBe('')
    expect(a.objectUrl.value).toBe('blob:stub-1')
  })

  it('gives up after three attempts on a server that stays down', async () => {
    const use = await freshModule()
    let calls = 0
    // @ts-expect-error test stub
    globalThis.fetch = vi.fn(async () => { calls++; return { ok: false, status: 500 } })

    const a = use(ref(URL_A))
    await vi.advanceTimersByTimeAsync(5000)

    expect(calls).toBe(3)
    expect(a.error.value).not.toBe('')
  })

  /** A 404 is an ANSWER. Retrying it twice per image is pure waste. */
  it('does NOT retry a 404', async () => {
    const use = await freshModule()
    let calls = 0
    // @ts-expect-error test stub
    globalThis.fetch = vi.fn(async () => { calls++; return { ok: false, status: 404 } })

    const a = use(ref(URL_A))
    await vi.advanceTimersByTimeAsync(5000)

    expect(calls).toBe(1)
    expect(a.error.value).toBe('ไม่พบไฟล์สื่อนี้')
  })

  it('retries a dropped connection (fetch itself rejecting)', async () => {
    const use = await freshModule()
    let calls = 0
    // @ts-expect-error test stub
    globalThis.fetch = vi.fn(async () => {
      calls++
      if (calls === 1) throw new TypeError('Load failed')

      return { ok: true, status: 200, blob: async () => ({}) }
    })

    const a = use(ref(URL_A))
    await vi.advanceTimersByTimeAsync(500)

    expect(calls).toBe(2)
    expect(a.objectUrl.value).toBe('blob:stub-1')
  })

  /** The manual escape hatch: the admin re-uploaded, the user taps again. */
  it('retry() recovers after a permanent failure that has since been fixed', async () => {
    const use = await freshModule()
    let broken = true
    // @ts-expect-error test stub
    globalThis.fetch = vi.fn(async () =>
      broken ? { ok: false, status: 404 } : { ok: true, status: 200, blob: async () => ({}) })

    const a = use(ref(URL_A))
    await vi.advanceTimersByTimeAsync(5000)
    expect(a.error.value).not.toBe('')

    broken = false
    a.retry()
    await vi.advanceTimersByTimeAsync(5000)

    expect(a.error.value).toBe('')
    expect(a.objectUrl.value).toBe('blob:stub-1')
  })
})
