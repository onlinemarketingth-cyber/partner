/**
 * Deep links (2026-10-02, prepares mobile plan phase 4) — what the app does
 * when the OS hands it a URL (a universal link / app link tapped in LINE or
 * an email, or the app's own URL scheme).
 *
 * ── THE RULE ──
 *
 * Only AGENT pages open inside the app, and only the ones on the allowlist
 * below. Everything else — above all the customer pages (/p/, /pay/, /l/,
 * /register ...) — is opened as a web page in the in-app browser, for the
 * reasons in customerLinks.ts.
 *
 * An ALLOWLIST, not a blocklist, on purpose: a URL is input from outside the
 * app. A route added to the portal next month must not become reachable from
 * any link on the internet until somebody decides it should be — adding it
 * here IS that decision.
 *
 * Phase 4 (universal links / app links) still needs the native side: the
 * Associated Domains entitlement on iOS, an intent filter with autoVerify on
 * Android, and the apple-app-site-association / assetlinks.json files on the
 * web server. This file is the JS half, ready for that; nativeBoot.ts wires
 * it to @capacitor/app's `appUrlOpen` (and getLaunchUrl for a cold start).
 */
import { isHttpUrl } from './browser'

/**
 * Agent paths a deep link may open inside the app. `/*` means "this path or
 * anything below it"; any other entry matches exactly (a query string is
 * always allowed).
 */
export const DEEP_LINK_ALLOWLIST = [
  '/notifications',
  '/clients',
  '/orders',
  '/commission',
  '/withdrawals',
  '/academy',
  '/academy/*',
  '/verify-email/*',
] as const

export function isAllowedDeepLinkPath(path: string): boolean {
  // `//evil.example` is a path to the router but a HOST to a browser — never.
  if (!path.startsWith('/') || path.startsWith('//')) return false

  return DEEP_LINK_ALLOWLIST.some((entry) => {
    if (entry.endsWith('/*')) {
      const prefix = entry.slice(0, -1) // keeps the trailing slash

      return path.startsWith(prefix) && path.length > prefix.length
    }

    return path === entry
  })
}

export type DeepLinkAction =
  | { kind: 'route'; location: string }
  | { kind: 'external'; url: string }
  | { kind: 'ignore' }

/**
 * Decide what to do with a URL the OS opened the app with.
 *
 * https://partner.syncvision.io/clients?view=pipeline → route '/clients?view=pipeline'
 * io.syncvision.partner://academy/lessons/4         → route '/academy/lessons/4'
 * https://partner.syncvision.io/p/abc               → external (in-app browser)
 * io.syncvision.partner://p/abc                     → ignore (opening a custom
 *   scheme "externally" would only hand it straight back to this app)
 */
export function resolveDeepLink(url: string): DeepLinkAction {
  let parsed: URL
  try {
    parsed = new URL(url)
  } catch {
    return { kind: 'ignore' }
  }

  const http = isHttpUrl(url)
  // For a custom scheme the first segment parses as the HOST
  // (scheme://notifications), so it is put back in front of the path.
  // A trailing slash (`/clients/`) is the same page to a person, so it is
  // dropped before the allowlist is asked.
  const raw = http ? parsed.pathname : `/${parsed.host}${parsed.pathname}`
  const path = raw.replace(/\/+$/, '') || '/'

  if (isAllowedDeepLinkPath(path)) {
    return { kind: 'route', location: `${path}${parsed.search}${parsed.hash}` }
  }

  return http ? { kind: 'external', url } : { kind: 'ignore' }
}
