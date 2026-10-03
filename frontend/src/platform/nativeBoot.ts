/**
 * MOB-25..MOB-31 (2026-10-02) — everything the iOS/Android app does at
 * launch that the web portal does not, in one place.
 *
 * main.ts loads this file with a dynamic import() ONLY inside the app, so
 * none of it — nor NativeAppLayer.vue, nor any plugin — is part of what a
 * browser downloads. main.ts calls, in order:
 *
 *   beforeMount(pinia)        after the session check, before the first
 *                             frame: put the biometric lock up if this agent
 *                             turned it on (MOB-29), so the portal is never
 *                             visible behind it, not even for one frame.
 *   afterMount(router, pinia) right after app.mount(): hide the native splash
 *                             (MOB-26), then install the listeners.
 *
 * Every step is independent and best-effort: a plugin that fails must cost
 * that one feature, never the launch.
 */
import { nextTick, watch } from 'vue'
import type { Pinia } from 'pinia'
import type { Router } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import { useThemeStore } from '@/stores/theme'
import NativeAppLayer from './components/NativeAppLayer.vue'
import { installBackButtonHandler } from './backButton'
import { clearLock, installResumeLock, lockIfEnabled } from './biometric'
import { installExternalLinkInterceptor, openExternal } from './browser'
import { resolveDeepLink } from './deepLinks'
import { installNetworkWatcher } from './network'
import { installPushListeners, registerPushForSession } from './push'
import { syncStatusBar } from './statusBar'
import { runUpdateCheck } from './versionPolicy'

export { NativeAppLayer }

/** MOB-29 — lock before the first frame if the agent asked for it. */
export async function beforeMount(pinia: Pinia): Promise<void> {
  const auth = useAuthStore(pinia)
  try {
    await lockIfEnabled(auth.user?.id ?? null)
  } catch {
    // An unreadable preference must not stop the app from opening.
  }
}

/**
 * MOB-26 — the native launch screen stays up until the web content is ready
 * (capacitor.config.ts: SplashScreen.launchAutoHide = false), then goes the
 * moment Vue has painted. Without this the user sees the native splash, then
 * a blank WebView, then the HTML splash — three loading screens in a row.
 */
async function hideNativeSplash(): Promise<void> {
  try {
    const { SplashScreen } = await import('@capacitor/splash-screen')
    await SplashScreen.hide({ fadeOutDuration: 200 })
  } catch {
    // Nothing to hide (or no plugin): the HTML splash still fades as usual.
  }
}

/**
 * Deep links — the same URL can arrive twice on a cold start (the retained
 * `appUrlOpen` event AND getLaunchUrl()). Handling it once matters for the
 * external case, which would otherwise open two browser sheets.
 */
function deepLinkOpener(router: Router): (url: string) => void {
  let last: { url: string; at: number } | null = null

  return (url: string) => {
    const now = Date.now()
    if (last && last.url === url && now - last.at < 3000) return
    last = { url, at: now }

    const action = resolveDeepLink(url)
    if (action.kind === 'route') void router.push(action.location)
    else if (action.kind === 'external') void openExternal(action.url)
  }
}

async function installDeepLinks(router: Router): Promise<void> {
  try {
    const { App } = await import('@capacitor/app')
    const open = deepLinkOpener(router)
    await App.addListener('appUrlOpen', (event) => open(event.url))
    const launch = await App.getLaunchUrl()
    if (launch?.url) open(launch.url)
  } catch {
    // No deep links on this build; the app itself is unaffected.
  }
}

export function afterMount(options: { router: Router; pinia: Pinia }): void {
  const { router, pinia } = options
  const auth = useAuthStore(pinia)
  const notifications = useNotificationsStore(pinia)
  const themeStore = useThemeStore(pinia)

  void hideNativeSplash()

  // MOB-25
  void installBackButtonHandler(router)
  void nextTick(() => syncStatusBar(!router.currentRoute.value.meta.public))
  watch([() => themeStore.theme, () => !router.currentRoute.value.meta.public], ([, chrome]) => {
    // After the DOM update: theme.ts writes --ink-nav in apply().
    void nextTick(() => syncStatusBar(chrome))
  })

  // MOB-22 / deep links
  installExternalLinkInterceptor()
  void installDeepLinks(router)

  // MOB-27
  void installNetworkWatcher()

  // MOB-29
  void installResumeLock(() => auth.user?.id ?? null)

  // MOB-31
  void runUpdateCheck()

  // MOB-28 — listeners from the start (a tapped push may be what launched
  // the app); permission and registration only once somebody is signed in.
  void installPushListeners({
    router,
    isSignedIn: () => auth.isAuthenticated,
    onForegroundMessage: () => {
      void notifications.fetchUnreadCount().catch(() => {})
      if (router.currentRoute.value.name === 'notifications') {
        void notifications.fetchList().catch(() => {})
      }
    },
  })

  watch(
    () => auth.user?.id ?? null,
    (userId, previous) => {
      if (userId === null) {
        clearLock()

        return
      }
      if (userId !== previous) void registerPushForSession()
    },
    { immediate: true },
  )
}
