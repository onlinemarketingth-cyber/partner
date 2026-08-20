import { describe, it, expect, vi, beforeEach } from 'vitest'
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
  // jsdom already provides `document`; this only clears the cookie jar so
  // the XSRF header is deterministic. The `??` keeps the spec runnable if
  // the environment is ever switched away from jsdom.
  // @ts-expect-error test stub
  globalThis.document = globalThis.document ?? { cookie: '' }
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

const settle = () => new Promise((r) => setTimeout(r, 0))
const URL_A = 'https://api.test/media/1'

describe('useAuthenticatedMedia', () => {
  beforeEach(() => { vi.restoreAllMocks() })

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
})
