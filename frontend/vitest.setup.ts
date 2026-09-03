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

await import('./src/composables/useI18n.js')
// Two turns of the microtask queue: one for each of the two files useI18n
// awaits in sequence.
await Promise.resolve()
await Promise.resolve()
await new Promise((resolve) => setTimeout(resolve, 0))
