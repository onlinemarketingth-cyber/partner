/**
 * TASK-225 — every component test gets a fresh Pinia.
 *
 * WHY THIS FILE EXISTS. ADR-038/TASK-209 gave the Admin app a global
 * "which company am I working in" store, and every view that can be
 * scoped now calls `useActiveCompanyStore()` in its own `setup()`. That
 * turned five spec files red at once with
 *
 *     "getActivePinia()" was called but there was no active Pinia
 *
 * — not because the specs test the wrong thing, but because mounting ANY
 * of these views is now a thing you cannot do without a Pinia. Adding
 * five copies of the same four lines would leave the sixth view to
 * rediscover this the same way.
 *
 * A FRESH Pinia PER TEST, not one shared instance: stores are stateful,
 * and a company selected in one test leaking into the next is the kind of
 * order-dependent failure that costs an afternoon to find.
 */
/*
 * ── AND THE DICTIONARY (2026-09-03) ──
 *
 * Same story, one sprint later. The Admin console's copy now lives in
 * public/lang/{th,en}.json and every converted view asks for it through
 * td('dash.kpi_sales'). useI18n fetches those files at import time, and in
 * jsdom nothing answers a fetch for '/lang/th.json' — so the dictionary
 * stayed null, td() fell back to returning the KEY, and every assertion
 * about the words on a converted screen failed.
 *
 * Serving the real files keeps those tests asserting the real words, and
 * makes them fail when a key is MISSING from the dictionary — which is the
 * regression that ships a screen full of dotted identifiers.
 */
import { beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { config } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const langDir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'public', 'lang')

const served: Record<string, string> = {
  '/lang/th.json': fs.readFileSync(path.join(langDir, 'th.json'), 'utf8'),
  '/lang/en.json': fs.readFileSync(path.join(langDir, 'en.json'), 'utf8'),
}

// Anything not served here goes to the real fetch, so a spec that stubs or
// asserts on network calls of its own is unaffected.
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

// useI18n starts loading as a side effect of being imported. Importing it
// here makes that ordering explicit rather than a property of whichever
// module a spec happens to import first.
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
await Promise.resolve()
await Promise.resolve()
await new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
  const pinia = createPinia()

  // setActivePinia covers stores reached OUTSIDE a component (a composable
  // called directly in a test); the global plugin covers stores reached
  // during setup() of anything mounted. Both are needed — neither implies
  // the other.
  setActivePinia(pinia)
  config.global.plugins = [pinia]
})
