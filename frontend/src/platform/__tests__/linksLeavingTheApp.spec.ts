/**
 * MOB-22 / MOB-32 / deep links (2026-10-02) — which links stay in the app,
 * which open in the in-app browser, and which go to the OS.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE WEB PORTAL CHANGES. In a browser a banner still opens a new tab with
 *    `noopener`, "open the buy page" still navigates the tab itself, and the
 *    customer routes are ordinary routes. The customer-link guard must not
 *    even be installed there — that is the owner's condition for the app.
 * 2. THE APP REPLACES ITSELF WITH A WEB PAGE. location.assign() inside the
 *    app swaps the whole app for the customer's page with no way back.
 * 3. A `javascript:` URL RUNS INSIDE THE SIGNED-IN APP. Banner and lesson
 *    URLs are admin-typed; only http(s) and mailto/tel/sms may leave.
 * 4. ANY LINK ON THE INTERNET OPENS ANY SCREEN. Deep links are an ALLOWLIST;
 *    a route added next month must not become reachable from outside until
 *    somebody adds it here. `//evil.example` must never count as a path.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { createMemoryHistory, createRouter } from 'vue-router'

const native = { value: false }
const browserOpen = vi.fn<AnyFn>()

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? 'android' : 'web'),
  },
}))

vi.mock('@capacitor/browser', () => ({ Browser: { open: (o: unknown) => browserOpen(o) } }))

import { installExternalLinkInterceptor, isHttpUrl, openExternal, openWithSystem } from '../browser'
import {
  CUSTOMER_PATH_PREFIXES,
  installCustomerLinkGuard,
  isCustomerPath,
  webAppUrl,
} from '../customerLinks'
import { DEEP_LINK_ALLOWLIST, isAllowedDeepLinkPath, resolveDeepLink } from '../deepLinks'

const Blank = { template: '<div />' }

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Blank },
      { path: '/clients', name: 'clients', component: Blank },
      { path: '/p/:token', name: 'product-share', component: Blank },
      { path: '/pay/:token', name: 'payment-page', component: Blank },
      { path: '/register', name: 'register', component: Blank },
      { path: '/verify-email/:id/:hash', name: 'verify-email', component: Blank },
    ],
  })
}

let locationHref: string
const assign = vi.fn<AnyFn>()

beforeEach(() => {
  native.value = false
  browserOpen.mockReset().mockResolvedValue(undefined)
  assign.mockReset()
  locationHref = ''
  vi.stubGlobal('open', vi.fn<AnyFn>())
  // jsdom cannot navigate; record what the code asked for instead. The
  // origin is the document's own, so a relative <a href> resolves onto it.
  const origin = new URL(document.baseURI).origin
  const fake = { origin, assign }
  Object.defineProperty(fake, 'href', {
    configurable: true,
    get: () => locationHref || `${origin}/`,
    set: (v: string) => {
      locationHref = v
    },
  })
  Object.defineProperty(window, 'location', { configurable: true, value: fake })
})

describe('openExternal — browser', () => {
  it('opens a new tab with noopener by default (banner, lesson link)', async () => {
    await openExternal('https://example.com/promo')

    expect(window.open).toHaveBeenCalledWith('https://example.com/promo', '_blank', 'noopener')
    expect(browserOpen).not.toHaveBeenCalled()
  })

  it("keeps the buy page's same-tab navigation", async () => {
    await openExternal('https://other.example/p/abc', { web: 'same-tab' })

    expect(assign).toHaveBeenCalledWith('https://other.example/p/abc')
    expect(window.open).not.toHaveBeenCalled()
  })
})

describe('openExternal — app', () => {
  beforeEach(() => {
    native.value = true
  })

  it('uses the in-app browser for http(s), even for the same-tab caller', async () => {
    await openExternal('https://partner.example/p/abc', { web: 'same-tab' })

    expect(browserOpen).toHaveBeenCalledWith({ url: 'https://partner.example/p/abc' })
    expect(assign).not.toHaveBeenCalled()
    expect(window.open).not.toHaveBeenCalled()
  })

  it('hands mailto:/tel: to the OS through a plain navigation', async () => {
    await openExternal('tel:0812345678')
    expect(locationHref).toBe('tel:0812345678')

    await openExternal('mailto:a@example.com')
    expect(locationHref).toBe('mailto:a@example.com')
    expect(browserOpen).not.toHaveBeenCalled()
  })

  it('refuses javascript: and other schemes', async () => {
    await openExternal('javascript:alert(1)')
    await openExternal('intent://x')

    expect(locationHref).toBe('')
    expect(browserOpen).not.toHaveBeenCalled()
  })

  it('sends a store URL to the OS, not the in-app browser', () => {
    openWithSystem('https://apps.apple.com/app/id1')

    expect(locationHref).toBe('https://apps.apple.com/app/id1')
    expect(browserOpen).not.toHaveBeenCalled()
  })

  it('routes plain <a target=_blank> links to elsewhere through the in-app browser', async () => {
    const remove = installExternalLinkInterceptor()
    const outside = document.createElement('a')
    outside.href = 'https://youtube.com/watch?v=1'
    outside.target = '_blank'
    const inside = document.createElement('a')
    inside.href = '/clients'
    document.body.append(outside, inside)

    const outsideClick = new MouseEvent('click', { bubbles: true, cancelable: true })
    outside.dispatchEvent(outsideClick)
    const insideClick = new MouseEvent('click', { bubbles: true, cancelable: true })
    inside.dispatchEvent(insideClick)

    expect(outsideClick.defaultPrevented).toBe(true)
    await vi.waitFor(() =>
      expect(browserOpen).toHaveBeenCalledWith({ url: 'https://youtube.com/watch?v=1' }),
    )
    expect(insideClick.defaultPrevented).toBe(false)
    expect(browserOpen).toHaveBeenCalledTimes(1)

    remove()
    outside.remove()
    inside.remove()
  })
})

describe('the link interceptor is app-only', () => {
  it('installs nothing in a browser', () => {
    const add = vi.spyOn(document, 'addEventListener')
    installExternalLinkInterceptor()

    expect(add).not.toHaveBeenCalledWith('click', expect.anything())
    add.mockRestore()
  })

  it('isHttpUrl', () => {
    expect(isHttpUrl('https://a.b')).toBe(true)
    expect(isHttpUrl('mailto:x')).toBe(false)
    expect(isHttpUrl('not a url')).toBe(false)
  })
})

describe('customer routes (MOB-32)', () => {
  it('knows every customer/recruit page and nothing else', () => {
    expect(CUSTOMER_PATH_PREFIXES).toEqual(['/p/', '/pay/', '/l/', '/c/', '/j/', '/in/'])
    for (const path of [
      '/p/abc',
      '/pay/t',
      '/l/t',
      '/c/CODE',
      '/j/CODE',
      '/in/CODE',
      '/register',
    ]) {
      expect(isCustomerPath(path)).toBe(true)
    }
    for (const path of [
      '/',
      '/clients',
      '/products',
      '/profile',
      '/p/',
      '/registered',
      '/verify-email/1/h',
    ]) {
      expect(isCustomerPath(path)).toBe(false)
    }
  })

  it('reads VITE_WEB_APP_URL defensively', () => {
    expect(webAppUrl('https://partner.example/')).toBe('https://partner.example')
    expect(webAppUrl('  https://partner.example  ')).toBe('https://partner.example')
    expect(webAppUrl('')).toBeNull()
    expect(webAppUrl(undefined)).toBeNull()
    expect(webAppUrl('partner.example')).toBeNull()
  })

  it('browser: the routes stay ordinary routes — no guard is installed', async () => {
    const router = makeRouter()
    installCustomerLinkGuard(router, 'https://partner.example')

    await router.push('/pay/tok?x=1')

    expect(router.currentRoute.value.fullPath).toBe('/pay/tok?x=1')
    expect(browserOpen).not.toHaveBeenCalled()
  })

  it('app: opens the page on the web origin and stays where it was', async () => {
    native.value = true
    const router = makeRouter()
    installCustomerLinkGuard(router, 'https://partner.example')
    await router.push('/clients')

    await router.push('/register?ref=abc')

    expect(router.currentRoute.value.fullPath).toBe('/clients')
    await vi.waitFor(() =>
      expect(browserOpen).toHaveBeenCalledWith({ url: 'https://partner.example/register?ref=abc' }),
    )
  })

  it('app without VITE_WEB_APP_URL: the page opens in the app rather than nowhere', async () => {
    native.value = true
    const router = makeRouter()
    installCustomerLinkGuard(router, null)

    await router.push('/p/abc')

    expect(router.currentRoute.value.fullPath).toBe('/p/abc')
  })
})

describe('deep links', () => {
  it('the allowlist is exactly the agent pages the owner listed', () => {
    expect(DEEP_LINK_ALLOWLIST).toEqual([
      '/notifications',
      '/clients',
      '/orders',
      '/commission',
      '/withdrawals',
      '/academy',
      '/academy/*',
      '/verify-email/*',
    ])
  })

  it('matches exact entries and /* subtrees, nothing looser', () => {
    expect(isAllowedDeepLinkPath('/academy')).toBe(true)
    expect(isAllowedDeepLinkPath('/academy/lessons/4')).toBe(true)
    expect(isAllowedDeepLinkPath('/verify-email/7/abc')).toBe(true)
    expect(isAllowedDeepLinkPath('/verify-email')).toBe(false)
    expect(isAllowedDeepLinkPath('/clients/5')).toBe(false)
    expect(isAllowedDeepLinkPath('/clientsX')).toBe(false)
    expect(isAllowedDeepLinkPath('/profile')).toBe(false)
    expect(isAllowedDeepLinkPath('//evil.example/clients')).toBe(false)
    expect(isAllowedDeepLinkPath('clients')).toBe(false)
  })

  it('routes an allowed universal link, keeping its query', () => {
    expect(resolveDeepLink('https://partner.example/clients?view=pipeline')).toEqual({
      kind: 'route',
      location: '/clients?view=pipeline',
    })
    expect(resolveDeepLink('https://partner.example/notifications/')).toEqual({
      kind: 'route',
      location: '/notifications',
    })
  })

  it('reads a custom-scheme link the same way', () => {
    expect(resolveDeepLink('io.syncvision.partner://academy/lessons/4')).toEqual({
      kind: 'route',
      location: '/academy/lessons/4',
    })
  })

  it('opens customer pages (and anything not allowed) as web pages', () => {
    expect(resolveDeepLink('https://partner.example/p/abc')).toEqual({
      kind: 'external',
      url: 'https://partner.example/p/abc',
    })
    expect(resolveDeepLink('https://partner.example/profile')).toEqual({
      kind: 'external',
      url: 'https://partner.example/profile',
    })
  })

  it('ignores a custom-scheme link it does not allow — "external" would loop back here', () => {
    expect(resolveDeepLink('io.syncvision.partner://p/abc')).toEqual({ kind: 'ignore' })
    expect(resolveDeepLink('not a url')).toEqual({ kind: 'ignore' })
  })
})
