/**
 * MOB-25 (2026-10-02) — Android's hardware / gesture back.
 *
 * Capacitor's default is "WebView history back, or quit the app". Quitting
 * is the wrong end for an app people keep open all day — the next tap on the
 * icon is a cold start with a splash — and history-back ignores whatever
 * dialog is open. The order here is what an Android user expects:
 *
 *   1. an overlay is open  → close the top one (backStack.ts), nothing else;
 *   2. on a start screen   → send the app to the background (minimise), the
 *      way the home screen of every Android app behaves;
 *   3. there is history    → go back one page;
 *   4. there is none       → minimise as well — a page opened straight from a
 *      notification has nothing "behind" it, and quitting would discard it.
 *
 * Registering a `backButton` listener is itself what switches Capacitor's
 * default off (@capacitor/app: the event goes to listeners instead).
 *
 * iOS has no back button; its swipe-back is WebView history and is untouched.
 * In a browser none of this is installed.
 */
import type { Router } from 'vue-router'
import { isNativeApp } from './index'
import { closeTopmost } from './backStack'

/** Route names that are the bottom of the stack: back from here = leave. */
export const ROOT_ROUTE_NAMES = ['home', 'login', 'super-admin-notice'] as const

/**
 * The decision, separate from the plugin so it can be tested.
 * `minimize` is App.minimizeApp in the app.
 */
export function handleBackButton(router: Router, minimize: () => void): void {
  if (closeTopmost()) return

  const name = router.currentRoute.value.name
  const atRoot = typeof name === 'string' && (ROOT_ROUTE_NAMES as readonly string[]).includes(name)
  // vue-router records the previous location in history.state.back (null on
  // the first entry), which — unlike the WebView's own canGoBack — knows
  // nothing about pages outside this router.
  const hasHistory = Boolean((window.history.state as { back?: unknown } | null)?.back)

  if (atRoot || !hasHistory) {
    minimize()

    return
  }

  router.back()
}

/** App only (Android emits the event; iOS never does). */
export async function installBackButtonHandler(router: Router): Promise<void> {
  if (!isNativeApp()) return

  const { App } = await import('@capacitor/app')
  await App.addListener('backButton', () => {
    handleBackButton(router, () => void App.minimizeApp())
  })
}
