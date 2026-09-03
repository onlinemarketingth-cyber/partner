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
