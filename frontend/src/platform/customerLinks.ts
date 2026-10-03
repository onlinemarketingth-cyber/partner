/**
 * MOB-32 (2026-10-02) — customer and recruit pages are web pages, even when
 * the agent opens one from inside the app.
 *
 * ── WHY ──
 *
 * /p/ (product), /pay/ (payment), /l/ (affiliate), /c/ /j/ /in/ (signup and
 * company-login short links) and /register are the pages an agent SENDS to
 * somebody else. Inside the app they would render against the app's own
 * origin (capacitor://localhost), so:
 *
 *  - the address the agent sees and might copy is not one a customer can open;
 *  - a payment gateway that redirects back after a card payment returns to the
 *    web origin, never into the app — the customer's "paid" screen would
 *    never appear;
 *  - the agent would be looking at a page with their OWN session attached,
 *    which is not what the customer will see.
 *
 * So when the app's router is about to open one of them, it opens the same
 * path on the real web origin (VITE_WEB_APP_URL) in the in-app browser
 * instead, and the app stays where it was.
 *
 * VITE_WEB_APP_URL is read from .env.app (gitignored; see .env.app.example).
 * If it is missing the page simply opens inside the app as before — a link
 * that still works beats a tap that does nothing.
 *
 * ── WHAT THIS FILE DOES NOT CHANGE ──
 *
 * The guard is only installed inside the app. In a browser these routes are
 * the routes they always were — that is the owner's condition for the app
 * ("ยังเข้าผ่านการแชร์ link ปรกติผ่าน browser ได้ไหม").
 */
import type { Router } from 'vue-router'
import { isNativeApp } from './index'

/** Paths that begin with one of these belong to a customer or recruit. */
export const CUSTOMER_PATH_PREFIXES = ['/p/', '/pay/', '/l/', '/c/', '/j/', '/in/'] as const

/** Paths that ARE one of these (a query string is allowed). */
export const CUSTOMER_EXACT_PATHS = ['/register'] as const

export function isCustomerPath(path: string): boolean {
  if ((CUSTOMER_EXACT_PATHS as readonly string[]).includes(path)) return true

  return CUSTOMER_PATH_PREFIXES.some(
    (prefix) => path.startsWith(prefix) && path.length > prefix.length,
  )
}

/**
 * The web portal's own origin, e.g. https://partner.syncvision.io, without a
 * trailing slash. Null when unset or not an http(s) URL.
 */
export function webAppUrl(
  raw: string | undefined = import.meta.env.VITE_WEB_APP_URL as string | undefined,
): string | null {
  const value = (raw ?? '').trim().replace(/\/+$/, '')
  if (!/^https?:\/\/[^/]+/i.test(value)) return null

  return value
}

/**
 * App only — send customer routes to the in-app browser on the web origin,
 * and cancel the in-app navigation. Install BEFORE the auth guard, so a
 * signed-in agent opening /register?ref=... is not first bounced home by it.
 */
export function installCustomerLinkGuard(router: Router, base: string | null = webAppUrl()): void {
  if (!isNativeApp() || !base) return

  router.beforeEach((to) => {
    if (!isCustomerPath(to.path)) return true

    // Loaded on use: the router imports this file in the browser build too,
    // and a browser never reaches this line.
    void import('./browser').then(({ openExternal }) => openExternal(`${base}${to.fullPath}`))

    return false
  })
}
