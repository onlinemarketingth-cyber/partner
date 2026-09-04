/**
 * Give the tests the same dictionary the browser gets.
 *
 * ── THE BUG THIS FIXES ──
 *
 * Since 95da007 the agent portal's copy lives in public/lang/{th,en}.json and
 * every component asks for it through `td('nav.home')`. useI18n fetches those
 * files once at import time — and in jsdom nothing answers a fetch for
 * '/lang/th.json', so the dictionary stayed null and `td()` fell back to
 * returning the KEY. Some forty tests then compared 'nav.home' with 'หน้าหลัก'
 * and failed, and they had been failing ever since.
 *
 * Rewriting those tests to expect dot keys was the other option, and it is the
 * wrong one: a test that asserts `td('pipeline.collect_now')` was rendered
 * proves nothing about what the agent reads on the button. Serving the real
 * file instead means the tests keep asserting the real words AND start failing
 * when a key is missing from the dictionary — which is the regression that
 * actually ships broken screens.
 *
 * ── WHY THE AWAIT AT THE BOTTOM ──
 *
 * useI18n starts loading as a side effect of being imported, so the load is
 * already in flight (or done) before the first spec body runs. Importing it
 * here, from the setup file, makes that ordering explicit rather than a
 * property of which module a spec happens to import first.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const langDir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'public', 'lang')

const served: Record<string, string> = {
  '/lang/th.json': fs.readFileSync(path.join(langDir, 'th.json'), 'utf8'),
  '/lang/en.json': fs.readFileSync(path.join(langDir, 'en.json'), 'utf8'),
}

// Anything this file does not serve is passed to the real fetch, so a spec
// that stubs or asserts on network calls of its own is unaffected.
const realFetch = globalThis.fetch

globalThis.fetch = ((input: RequestInfo | URL, init?: RequestInit) => {
  const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input)
  const body = served[url]

  if (body === undefined) return realFetch(input as RequestInfo, init)

  return Promise.resolve({
    ok: true,
    status: 200,
    json: async () => JSON.parse(body),
    text: async () => body,
  } as Response)
}) as typeof fetch

/*
 * ── NODE 25 TOOK localStorage AWAY (2026-09-04) ──
 *
 * Node 25 ships its own `localStorage` on the global object. Vitest builds
 * the jsdom globals on that same object and does not overwrite what is
 * already there, so on Node 25 `window.localStorage` is Node's — and without
 * --localstorage-file that is an empty plain object: no getItem, no setItem,
 * no clear. Thirteen tests died on `window.localStorage.clear is not a
 * function`, every one of them about behaviour that works in a real browser.
 *
 * `sessionStorage` still worked, because Node has no global of that name.
 * That asymmetry is the fingerprint of a name collision rather than a jsdom
 * bug, and it is why the check below is per-name instead of a blanket
 * replacement.
 *
 * ── WHY THIS BUILDS A STORAGE INSTEAD OF FETCHING JSDOM'S ──
 *
 * There is nothing to fetch. Vitest makes jsdom's window BE the global
 * object (document.defaultView === globalThis), so once Node's value has
 * taken the name, jsdom's own accessor is gone from the only object that
 * had it. A second JSDOM realm could produce a Storage, but its class would
 * then have to replace the global `Storage` anyway — the same swap as this,
 * with a whole extra DOM behind it.
 *
 * `Storage` is replaced together with the instance, deliberately: a spec
 * makes a read throw by spying on Storage.prototype, and a spy on a class
 * the instance does not use is a test that silently checks nothing.
 *
 * NOT INSTALLED unconditionally — on Node 24 and below this whole block is
 * skipped and the tests keep using jsdom's own implementation. To see the
 * failure it repairs on a machine that does not have Node 25, replace
 * globalThis.localStorage with `{}` before this runs.
 */
{
  /** Enough of the Web Storage API for what this app asks of it. */
  class TestStorage {
    private items = new Map<string, string>()

    get length(): number {
      return this.items.size
    }

    key(index: number): string | null {
      return [...this.items.keys()][index] ?? null
    }

    getItem(key: string): string | null {
      const value = this.items.get(String(key))

      // null, not undefined — safeStorage's `?? null` hides the difference,
      // but a caller comparing to null directly would not.
      return value === undefined ? null : value
    }

    setItem(key: string, value: string): void {
      // Storage stores strings and nothing else. A test that sets a number
      // and reads back a number would pass here and fail in a browser.
      this.items.set(String(key), String(value))
    }

    removeItem(key: string): void {
      this.items.delete(String(key))
    }

    clear(): void {
      this.items.clear()
    }
  }

  const g = globalThis as unknown as Record<string, unknown>
  const broken = (name: string) =>
    typeof (g[name] as Storage | undefined)?.clear !== 'function' ||
    typeof (g[name] as Storage | undefined)?.getItem !== 'function'

  if (broken('localStorage') || broken('sessionStorage')) {
    Object.defineProperty(globalThis, 'Storage', { configurable: true, writable: true, value: TestStorage })

    for (const name of ['localStorage', 'sessionStorage']) {
      if (broken(name)) {
        Object.defineProperty(globalThis, name, {
          configurable: true,
          writable: true,
          value: new TestStorage(),
        })
      }
    }
  }
}

await import('./src/composables/useI18n.js')
// Two turns of the microtask queue: one for each of the two files useI18n
// awaits in sequence.
await Promise.resolve()
await Promise.resolve()
await new Promise((resolve) => setTimeout(resolve, 0))
