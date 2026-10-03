/**
 * MOB-28 (2026-10-02) — push notifications in the app.
 *
 * Owner decision: every notification that lands in the bell also arrives as a
 * push. The server decides what to send (it already writes the bell rows);
 * this file only gets the phone registered and handles what happens when a
 * push arrives or is tapped.
 *
 * ── WHEN PERMISSION IS ASKED ──
 *
 * After sign-in (or after a saved session is restored), never on the first
 * launch. A permission prompt on a login screen, from an app that has not yet
 * shown the person anything, is the one most often refused — and on iOS a
 * refusal is final until the person finds the setting themselves.
 *
 * ── THE ROUND TRIP ──
 *
 *   1. ask permission (once; the OS remembers the answer)
 *   2. FCM token → POST /me/devices { platform, token, app_version }
 *   3. FCM rotates the token → `tokenReceived` → POST again (the server
 *      upserts on the token)
 *   4. a push is TAPPED → open its `url` (a portal path), or /notifications
 *      when the path is missing, not ours, or not a route this build knows
 *   5. a push arrives while the app is OPEN → refresh the bell count, which
 *      the notifications store already knows how to do
 *   6. sign-out → DELETE /me/devices { token } BEFORE the session is revoked
 *      (the request needs it), best effort, never holding the logout up
 *
 * ── WHAT THIS FILE DOES NOT CHANGE ──
 *
 * In a browser every function returns at once and the Firebase plugin is
 * never loaded. The `firebase` web SDK (the plugin's optional peer) is not
 * installed on purpose: the web portal keeps its polling bell, and the
 * plugin's web half is never imported (vite.config.ts stubs the import so
 * the build does not need it).
 */
import type { Router } from 'vue-router'
import { api } from '@/api/client'
import { isNativeApp, nativePlatform } from './index'
import { installedAppVersion } from './appInfo'

/** The FCM token this install currently has, once known. */
let currentToken: string | null = null
let listenersInstalled = false

export function pushToken(): string | null {
  return currentToken
}

/** Test helper. */
export function resetPushState(): void {
  currentToken = null
  listenersInstalled = false
}

async function messaging() {
  const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')

  return FirebaseMessaging
}

async function sendToken(token: string): Promise<void> {
  const platform = nativePlatform()
  if (platform === 'web') return

  const appVersion = await installedAppVersion()
  await api.post('/me/devices', {
    platform,
    token,
    ...(appVersion ? { app_version: appVersion } : {}),
  })
}

export type PushRegistration = 'registered' | 'denied' | 'skipped' | 'failed'

/**
 * Called when a signed-in session starts (login or restore). Asks for
 * permission if the OS has not been answered yet, then registers the token.
 * Never throws: a phone that cannot receive pushes still has the bell.
 */
export async function registerPushForSession(): Promise<PushRegistration> {
  if (!isNativeApp()) return 'skipped'

  try {
    const plugin = await messaging()

    let { receive } = await plugin.checkPermissions()
    if (receive === 'prompt' || receive === 'prompt-with-rationale') {
      ;({ receive } = await plugin.requestPermissions())
    }
    if (receive !== 'granted') return 'denied'

    const { token } = await plugin.getToken()
    if (!token) return 'failed'

    currentToken = token
    await sendToken(token)

    return 'registered'
  } catch {
    return 'failed'
  }
}

/**
 * Where a tapped push should land.
 *
 * Only a path inside this portal that this build has a route for. The URL
 * comes from the push payload, so it is checked the same way a deep link is:
 * must start with one `/` (never `//host`, which a browser reads as another
 * site), and must match a route — this router has no catch-all, and an
 * unmatched path renders an empty shell that looks like a crash.
 */
export function notificationTarget(router: Router, data: unknown): string {
  const url = (data as { url?: unknown } | null | undefined)?.url

  if (typeof url === 'string' && url.startsWith('/') && !url.startsWith('//')) {
    try {
      if (router.resolve(url).matched.length > 0) return url
    } catch {
      // fall through
    }
  }

  return '/notifications'
}

/**
 * App only, once per launch, BEFORE sign-in is known: a push tapped while
 * the app was closed is delivered to the first listener, so it must exist
 * from the start. Navigating before the session is known is fine — the
 * router's guard sends a signed-out person to login with ?redirect=.
 */
export async function installPushListeners(options: {
  router: Router
  isSignedIn: () => boolean
  onForegroundMessage: () => void
}): Promise<void> {
  if (!isNativeApp() || listenersInstalled) return
  listenersInstalled = true

  try {
    const plugin = await messaging()

    await plugin.addListener('tokenReceived', (event) => {
      currentToken = event.token
      if (options.isSignedIn()) void sendToken(event.token).catch(() => {})
    })

    await plugin.addListener('notificationReceived', () => {
      options.onForegroundMessage()
    })

    await plugin.addListener('notificationActionPerformed', (event) => {
      void options.router.push(notificationTarget(options.router, event.notification?.data))
    })
  } catch {
    listenersInstalled = false
  }
}

/** Long enough for a slow phone, short enough that logout never feels stuck. */
const UNREGISTER_TIMEOUT_MS = 4000

/**
 * Sign-out: tell the server to stop pushing to this phone for this account.
 * Must run while the session is still valid. Best effort — resolves within
 * UNREGISTER_TIMEOUT_MS whatever happens, and never rejects.
 */
export async function unregisterPushDevice(): Promise<void> {
  if (!isNativeApp() || !currentToken) return

  const token = currentToken
  try {
    await Promise.race([
      api.delete('/me/devices', undefined, { token }),
      new Promise((resolve) => setTimeout(resolve, UNREGISTER_TIMEOUT_MS)),
    ])
  } catch {
    // The server drops tokens FCM reports as dead; a missed delete only
    // means that happens a little later.
  }
}

/**
 * Account deletion (MOB-30): the session is already revoked, so the server
 * cannot be asked to forget the device. Throwing the token away on the phone
 * guarantees no further push for this account reaches it; the next sign-in
 * simply gets a fresh one. Best effort, never rejects.
 */
export async function forgetPushToken(): Promise<void> {
  if (!isNativeApp()) return

  currentToken = null
  try {
    await (await messaging()).deleteToken()
  } catch {
    // Nothing more this phone can do.
  }
}
