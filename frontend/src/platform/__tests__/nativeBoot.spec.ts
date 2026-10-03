/**
 * MOB-25..31 (2026-10-02) — what the app does at launch, and how App.vue
 * receives the app-only screens.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE APP NEVER LEAVES ITS SPLASH. capacitor.config.ts turns auto-hide
 *    off; if nothing calls SplashScreen.hide() after mount, the app is a logo
 *    for ever.
 * 2. PUSH IS NEVER REGISTERED, or is asked for before anybody signed in.
 * 3. A DEEP LINK OPENS TWICE on a cold start (retained event + launch URL) —
 *    two browser sheets stacked for one tap.
 *
 * App.vue's side of it (render the layer only when provided) is pinned in
 * AppShellNative.spec.ts.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const splashHide = vi.fn<AnyFn>()
const appListeners: Record<string, (e: { url: string }) => void> = {}
const launchUrl = { value: undefined as { url: string } | undefined }
const browserOpen = vi.fn<AnyFn>()
const messaging = {
  checkPermissions: vi.fn<AnyFn>(),
  requestPermissions: vi.fn<AnyFn>(),
  getToken: vi.fn<AnyFn>(),
  addListener: vi.fn<AnyFn>(async () => ({ remove: vi.fn<AnyFn>() })),
}
const post = vi.fn<AnyFn>()

vi.mock('@capacitor/core', () => ({
  Capacitor: { isNativePlatform: () => true, getPlatform: () => 'ios' },
}))
vi.mock('@capacitor/splash-screen', () => ({
  SplashScreen: { hide: (o: unknown) => splashHide(o) },
}))
vi.mock('@capacitor/app', () => ({
  App: {
    addListener: async (name: string, fn: (e: { url: string }) => void) => {
      appListeners[name] = fn

      return { remove: vi.fn<AnyFn>() }
    },
    getLaunchUrl: async () => launchUrl.value,
    getInfo: async () => ({ version: '1.0.0' }),
    minimizeApp: vi.fn<AnyFn>(),
  },
}))
vi.mock('@capacitor/browser', () => ({ Browser: { open: (o: unknown) => browserOpen(o) } }))
vi.mock('@capacitor/network', () => ({
  Network: {
    getStatus: async () => ({ connected: true }),
    addListener: vi.fn<AnyFn>(async () => ({})),
  },
}))
vi.mock('@capacitor/status-bar', () => ({
  Style: { Dark: 'DARK', Light: 'LIGHT', Default: 'DEFAULT' },
  StatusBar: { setStyle: vi.fn<AnyFn>() },
}))
vi.mock('@capacitor/preferences', () => ({
  Preferences: { get: async () => ({ value: null }), set: vi.fn<AnyFn>(), remove: vi.fn<AnyFn>() },
}))
vi.mock('@capacitor-firebase/messaging', () => ({ FirebaseMessaging: messaging }))
vi.mock('@aparajita/capacitor-biometric-auth', () => ({ BiometricAuth: {} }))
vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn<AnyFn>().mockRejectedValue(new Error('offline')),
    post: (...a: unknown[]) => post(...a),
    delete: vi.fn<AnyFn>(),
  },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn<AnyFn>(),
  setToken: vi.fn<AnyFn>(),
}))

import { afterMount } from '../nativeBoot'
import { useAuthStore, type AuthUser } from '@/stores/auth'

const Blank = { template: '<div />' }
function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Blank },
      { path: '/notifications', name: 'notifications', component: Blank },
      { path: '/clients', name: 'clients', component: Blank },
    ],
  })
}

beforeEach(() => {
  splashHide.mockReset().mockResolvedValue(undefined)
  browserOpen.mockReset().mockResolvedValue(undefined)
  post.mockReset().mockResolvedValue({})
  launchUrl.value = undefined
  for (const key of Object.keys(appListeners)) delete appListeners[key]
  messaging.checkPermissions.mockReset().mockResolvedValue({ receive: 'granted' })
  messaging.getToken.mockReset().mockResolvedValue({ token: 'fcm-1' })
})

describe('afterMount', () => {
  it('hides the native splash', async () => {
    const pinia = createPinia()
    afterMount({ router: makeRouter(), pinia })

    await vi.waitFor(() => expect(splashHide).toHaveBeenCalled())
  })

  it('registers for push only once somebody is signed in', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    afterMount({ router: makeRouter(), pinia })
    await flushPromises()
    expect(messaging.getToken).not.toHaveBeenCalled()

    useAuthStore(pinia).setUser({ id: 7 } as unknown as AuthUser)
    await nextTick()

    await vi.waitFor(() =>
      expect(post).toHaveBeenCalledWith(
        '/me/devices',
        expect.objectContaining({ token: 'fcm-1', platform: 'ios' }),
      ),
    )
  })

  it('a cold-start deep link is handled once, not twice', async () => {
    launchUrl.value = { url: 'https://partner.example/p/abc' }
    const pinia = createPinia()
    afterMount({ router: makeRouter(), pinia })
    await vi.waitFor(() => expect(browserOpen).toHaveBeenCalledTimes(1))

    appListeners.appUrlOpen?.({ url: 'https://partner.example/p/abc' })
    await flushPromises()

    expect(browserOpen).toHaveBeenCalledTimes(1)
  })

  it('an allowed deep link navigates inside the app', async () => {
    const router = makeRouter()
    const pinia = createPinia()
    afterMount({ router, pinia })
    await vi.waitFor(() => expect(appListeners.appUrlOpen).toBeDefined())

    appListeners.appUrlOpen?.({ url: 'https://partner.example/clients?view=pipeline' })

    await vi.waitFor(() =>
      expect(router.currentRoute.value.fullPath).toBe('/clients?view=pipeline'),
    )
  })
})
