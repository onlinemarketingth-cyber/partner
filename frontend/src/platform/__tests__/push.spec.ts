/**
 * MOB-28 (2026-10-02) — push notifications in the app.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE PERMISSION PROMPT COMES TOO EARLY, or not at all. It must be asked
 *    only for a signed-in session; a refused prompt on iOS is final.
 * 2. THE SERVER NEVER HEARS ABOUT THE PHONE. No POST /me/devices (or one with
 *    the wrong platform) and nothing arrives — no error anywhere, the bell
 *    still works, pushes just never come.
 * 3. A ROTATED TOKEN IS NOT RE-SENT, so pushes stop weeks later.
 * 4. A PUSH OPENS ANY URL. The payload's `url` is outside input: only a
 *    portal path this build has a route for; `//host` is a different site.
 * 5. LOGOUT KEEPS PUSHING. DELETE /me/devices must go out BEFORE /logout
 *    (it needs the session), must carry the token in the body, and must never
 *    hold the logout up.
 * 6. THE WEB PORTAL CHANGES — nothing here may load or send in a browser.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const native = { value: true }
const pluginLoaded = vi.fn<AnyFn>()
const listeners: Record<string, (event: unknown) => void> = {}
const messaging = {
  checkPermissions: vi.fn<AnyFn>(),
  requestPermissions: vi.fn<AnyFn>(),
  getToken: vi.fn<AnyFn>(),
  deleteToken: vi.fn<AnyFn>(),
  addListener: vi.fn<AnyFn>(async (name: string, fn: (event: unknown) => void) => {
    listeners[name] = fn

    return { remove: vi.fn<AnyFn>() }
  }),
}
const calls: string[] = []
const post = vi.fn<AnyFn>(async (path: string, body?: unknown) => {
  calls.push(`POST ${path}`)

  return body
})
const del = vi.fn<AnyFn>(async (path: string, _signal?: unknown, _body?: unknown) => {
  calls.push(`DELETE ${path}`)
})

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? 'android' : 'web'),
  },
}))

vi.mock('@capacitor-firebase/messaging', () => {
  pluginLoaded()

  return { FirebaseMessaging: messaging }
})

vi.mock('@capacitor/app', () => ({ App: { getInfo: async () => ({ version: '1.2.0' }) } }))

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn<AnyFn>(),
    post: (path: string, body?: unknown) => post(path, body),
    delete: (path: string, signal?: unknown, body?: unknown) => del(path, signal, body),
  },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn<AnyFn>(),
  setToken: vi.fn<AnyFn>(),
}))

import {
  forgetPushToken,
  installPushListeners,
  notificationTarget,
  pushToken,
  registerPushForSession,
  resetPushState,
  unregisterPushDevice,
} from '../push'
import { useAuthStore } from '@/stores/auth'

const Blank = { template: '<div />' }
function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Blank },
      { path: '/notifications', name: 'notifications', component: Blank },
      { path: '/clients', name: 'clients', component: Blank },
      { path: '/academy/lessons/:id', name: 'academy-lesson', component: Blank },
    ],
  })
}

beforeEach(() => {
  native.value = true
  resetPushState()
  calls.length = 0
  post.mockClear()
  del.mockClear()
  pluginLoaded.mockClear()
  for (const key of Object.keys(listeners)) delete listeners[key]
  messaging.checkPermissions.mockReset().mockResolvedValue({ receive: 'prompt' })
  messaging.requestPermissions.mockReset().mockResolvedValue({ receive: 'granted' })
  messaging.getToken.mockReset().mockResolvedValue({ token: 'fcm-1' })
  messaging.deleteToken.mockReset().mockResolvedValue(undefined)
  messaging.addListener.mockClear()
})

describe('registering a signed-in session', () => {
  it('asks permission once, then sends platform, token and app version', async () => {
    await expect(registerPushForSession()).resolves.toBe('registered')

    expect(messaging.requestPermissions).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/me/devices', {
      platform: 'android',
      token: 'fcm-1',
      app_version: '1.2.0',
    })
    expect(pushToken()).toBe('fcm-1')
  })

  it('does not ask again when the OS already has an answer', async () => {
    messaging.checkPermissions.mockResolvedValue({ receive: 'granted' })
    await registerPushForSession()
    expect(messaging.requestPermissions).not.toHaveBeenCalled()

    messaging.checkPermissions.mockResolvedValue({ receive: 'denied' })
    await expect(registerPushForSession()).resolves.toBe('denied')
    expect(messaging.requestPermissions).not.toHaveBeenCalled()
  })

  it('a refusal or a plugin failure never throws', async () => {
    messaging.requestPermissions.mockResolvedValue({ receive: 'denied' })
    await expect(registerPushForSession()).resolves.toBe('denied')
    expect(post).not.toHaveBeenCalled()

    messaging.checkPermissions.mockRejectedValue(new Error('no google play services'))
    await expect(registerPushForSession()).resolves.toBe('failed')
  })
})

describe('listeners', () => {
  it('re-sends a rotated token, but only while signed in', async () => {
    let signedIn = true
    await installPushListeners({
      router: makeRouter(),
      isSignedIn: () => signedIn,
      onForegroundMessage: vi.fn<AnyFn>(),
    })

    listeners.tokenReceived?.({ token: 'fcm-2' })
    await vi.waitFor(() =>
      expect(post).toHaveBeenCalledWith('/me/devices', expect.objectContaining({ token: 'fcm-2' })),
    )

    post.mockClear()
    signedIn = false
    listeners.tokenReceived?.({ token: 'fcm-3' })
    await Promise.resolve()
    expect(post).not.toHaveBeenCalled()
    expect(pushToken()).toBe('fcm-3')
  })

  it('a tapped push opens its url; a foreground push refreshes the bell', async () => {
    const router = makeRouter()
    const onForegroundMessage = vi.fn<AnyFn>()
    await installPushListeners({ router, isSignedIn: () => true, onForegroundMessage })

    listeners.notificationActionPerformed?.({
      notification: { data: { notification_id: '9', url: '/clients' } },
    })
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/clients'))

    listeners.notificationReceived?.({ notification: { title: 'x' } })
    expect(onForegroundMessage).toHaveBeenCalled()
  })

  it('installs once per launch', async () => {
    const options = {
      router: makeRouter(),
      isSignedIn: () => true,
      onForegroundMessage: vi.fn<AnyFn>(),
    }
    await installPushListeners(options)
    await installPushListeners(options)

    expect(messaging.addListener).toHaveBeenCalledTimes(3)
  })
})

describe('where a tapped push may go', () => {
  const router = makeRouter()

  it('a known portal path', () => {
    expect(notificationTarget(router, { url: '/clients' })).toBe('/clients')
    expect(notificationTarget(router, { url: '/academy/lessons/4' })).toBe('/academy/lessons/4')
  })

  it('anything else falls back to the notifications list', () => {
    for (const data of [
      { url: '//evil.example/x' },
      { url: 'https://evil.example' },
      { url: '/no-such-page' },
      { url: 42 },
      {},
      null,
      undefined,
    ]) {
      expect(notificationTarget(router, data)).toBe('/notifications')
    }
  })
})

describe('signing out', () => {
  it('DELETEs the device with the token in the body, BEFORE the session is revoked', async () => {
    setActivePinia(createPinia())
    await registerPushForSession()
    calls.length = 0

    await useAuthStore().logout()

    expect(calls).toEqual(['DELETE /me/devices', 'POST /logout'])
    expect(del).toHaveBeenCalledWith('/me/devices', undefined, { token: 'fcm-1' })
  })

  it('a hanging DELETE never holds the logout up', async () => {
    vi.useFakeTimers()
    try {
      await registerPushForSession()
      del.mockImplementationOnce(() => new Promise(() => {}))

      const done = vi.fn<AnyFn>()
      void unregisterPushDevice().then(done)
      await vi.advanceTimersByTimeAsync(4100)

      expect(done).toHaveBeenCalled()
    } finally {
      vi.useRealTimers()
    }
  })

  it('account deletion throws the token away on the phone', async () => {
    await registerPushForSession()

    await forgetPushToken()

    expect(messaging.deleteToken).toHaveBeenCalled()
    expect(pushToken()).toBeNull()
  })
})

describe('in a browser', () => {
  it('nothing is loaded, asked or sent', async () => {
    native.value = false
    setActivePinia(createPinia())

    await expect(registerPushForSession()).resolves.toBe('skipped')
    await installPushListeners({
      router: makeRouter(),
      isSignedIn: () => true,
      onForegroundMessage: vi.fn<AnyFn>(),
    })
    await useAuthStore().logout()
    await forgetPushToken()

    expect(pluginLoaded).not.toHaveBeenCalled()
    expect(del).not.toHaveBeenCalled()
    expect(calls).toEqual(['POST /logout'])
  })
})
