import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { restoreNativeToken, setUnauthorizedHandler } from './api/client'
import { useAuthStore } from './stores/auth'
import { useThemeStore } from './stores/theme'
import { installStaleAssetRecovery } from './utils/staleAssets'
import { isPublicLinkPage, startLinkPrefetch } from './utils/bootPrefetch'
import { isNativeApp, NATIVE_LAYER_KEY } from './platform'

const app = createApp(App)

// Pinia first so the theme store is usable before mount (ADR-018: the
// company theme must resolve at boot, pre-login, delivered as CSS vars).
const pinia = createPinia()
app.use(pinia)
app.use(router)

// Bug fix — see api/client.ts's comment on setUnauthorizedHandler: a
// session that expires mid-use (not just "never logged in") now clears
// the stale client-side user and sends the person back to login,
// instead of leaving them stuck on a page repeatedly showing raw
// "(401)" errors.
setUnauthorizedHandler(() => {
  const authStore = useAuthStore()
  authStore.user = null
  if (router.currentRoute.value.name !== 'login') {
    // TASK-064 — same "keep the login page themed" fix as the explicit
    // logout buttons (theme.ts's loginRouteLocation()): an expired
    // session is effectively a forced logout, so it should carry
    // ?company=<slug> too rather than dropping to neutral defaults.
    // `themeStore` (declared further down, after loadPublic() sets it up
    // at boot) is safe to reference here — this closure only ever runs
    // in response to a later API call, never during this module's
    // synchronous top-level evaluation.
    router.push(themeStore.loginRouteLocation())
  }
})

/*
 * 2026-09-09 (human: "ตอนนี้ session หมดอายุ ไม่เด้งไปที่ login").
 *
 * The handler above is REACTIVE: it only learns the token died when a request
 * fails, so an app left open in a background tab — which on a phone is most of
 * the time — sits showing yesterday's screen until something is tapped.
 *
 * Returning to the app is exactly the moment to check, and it costs one
 * request at the one instant a person is about to act. fetchUser() clears the
 * user on failure and client.ts drops the dead token, so there is nothing to
 * duplicate here.
 *
 * Guarded on being signed in already, so it never fires on the login screen —
 * which would reload the page under the hands of somebody mid-password.
 */
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') return

  const authStore = useAuthStore()
  if (!authStore.isAuthenticated) return

  void authStore.fetchUser().then(() => {
    if (!authStore.user && router.currentRoute.value.name !== 'login') {
      router.push(themeStore.loginRouteLocation())
    }
  })
})

// TASK-078 (2026-08-02, human-confirmed via AskUserQuestion) — the splash
// (logo + progress bar, see index.html) must hold for a minimum of 3000ms
// regardless of how fast boot actually finishes, and the bar itself should
// track REAL boot progress (theme + session check) rather than being a
// pure fake animation. A gentle eased ticker smooths the 2 discrete
// progress jumps (theme resolved / session resolved) into a continuous
// motion instead of visible steps.
const SPLASH_MIN_MS = 3000
const bootStartedAt = performance.now()
const splashBar = document.getElementById('app-splash-bar') as HTMLDivElement | null
let splashTarget = 8 // small immediate jump so the bar never looks frozen at 0
let splashShown = 0
const splashTicker = window.setInterval(() => {
  splashShown += (splashTarget - splashShown) * 0.15
  if (splashBar) splashBar.style.width = `${Math.min(splashShown, 99)}%`
}, 50)
function bumpSplash(p: number) {
  splashTarget = Math.max(splashTarget, p)
}

// TASK-055 / ADR-018 — resolve + apply the company theme before mount so
// the app paints branded (colors/font/favicon) from the first frame.
// TASK-078 extends this to also run the session check (authStore.fetchUser())
// during the splash, so by the time we reveal the app the router's very
// first navigation guard already knows whether to land on Home or Login —
// no separate "skip splash if logged in" branch needed, the guard's
// existing isAuthenticated redirect (router/index.ts) just does the right
// thing once status is 'ready'. Every call here is individually resilient
// (each store method try/catches its own network failure — see
// theme.ts/auth.ts) so a single failed request can never block boot; a
// hard timeout on top guards against a request that never settles at all.
const themeStore = useThemeStore(pinia)
// Named distinctly from the `authStore` local inside setUnauthorizedHandler
// above (different scope, but same store) to avoid shadowing it.
const bootAuthStore = useAuthStore(pinia)

function withTimeout(promise: Promise<void>, ms: number): Promise<void> {
  return Promise.race([promise, new Promise<void>((resolve) => window.setTimeout(resolve, ms))])
}

// 2026-10-02 — a signup link is checked NOW, alongside the two boot calls,
// instead of after the splash by the page itself (utils/bootPrefetch.ts).
// The page picks up this same request; nothing is asked twice.
const linkPrefetch = startLinkPrefetch()

// MOB-25..31 (2026-10-02) — the app-only launch work (platform/nativeBoot.ts):
// splash, back button, push, lock, offline notice, update check. Loaded with
// import() and only inside the app, so a browser never downloads any of it.
// Started here so it loads while the session check below is in flight.
const nativeBootLoading = isNativeApp() ? import('./platform/nativeBoot').catch(() => null) : null

// MOB-01 — inside the iOS/Android app, put the saved token back BEFORE the
// session check below, or /me would go out without it and the agent would
// land on the login screen every launch. Resolves at once in a browser.
await withTimeout(restoreNativeToken(), 3000)

await Promise.allSettled([
  withTimeout(themeStore.loadPublic(), 8000).then(() => bumpSplash(55)),
  withTimeout(bootAuthStore.fetchUser(), 8000).then(() => bumpSplash(90)),
  ...(linkPrefetch ? [withTimeout(linkPrefetch.then(() => undefined), 8000)] : []),
])
bumpSplash(100)

// Apply the resolved theme to the splash. TASK-168 made index.html paint
// this from cache before the first frame, so on a repeat visit these are
// usually no-ops — but this is the AUTHORITATIVE pass, and it is what makes
// a stale cache self-correct within the same boot after an admin changes
// the theme. It therefore also has to UNDO a cached value the live theme no
// longer has (the logo), or the correction would only ever be one-way.
const splash = document.getElementById('app-splash')
if (splash) {
  const loadingBg = themeStore.theme?.loading?.bg_hex
  if (loadingBg) splash.style.background = loadingBg
  const barColor = themeStore.theme?.primary_hex
  if (barColor && splashBar) splashBar.style.background = barColor
  const logoUrl = themeStore.loadingLogo ?? themeStore.navLogo
  const logoEl = document.getElementById('app-splash-logo') as HTMLImageElement | null
  if (logoEl) {
    if (logoUrl) {
      logoEl.src = logoUrl
      logoEl.style.display = 'block'
    } else if (themeStore.theme) {
      logoEl.style.display = 'none'
    }
  }
}

// Hold the splash open for the remainder of the 3000ms floor (boot work
// above may have finished in well under that).
//
// 2026-10-02 (owner decision) — NOT for a page a recruit or customer opens
// from a shared link: for them the splash is only as long as the loading.
//
// MOB-26 (2026-10-02) — and NOT inside the app either. The floor exists so a
// web visit shows the brand instead of a flash; the app already showed its
// native launch screen while the WebView started, so another 3 seconds on
// top of that is just waiting.
const splashFloorMs = isNativeApp() || isPublicLinkPage(window.location.pathname) ? 0 : SPLASH_MIN_MS
const remainingMs = splashFloorMs - (performance.now() - bootStartedAt)
if (remainingMs > 0) await new Promise((resolve) => window.setTimeout(resolve, remainingMs))
window.clearInterval(splashTicker)
if (splashBar) splashBar.style.width = '100%'

/*
 * 2026-09-10 — a tab open when a deploy landed is asking for chunk filenames
 * the server deleted (deploy.sh rsyncs with --delete), so every navigation
 * fails silently and the menu just stops working. See utils/staleAssets;
 * installed before mount so a failure on the very first navigation counts too.
 *
 * MOB-26 (2026-10-02) — web only. The app's chunks are bundled INSIDE the
 * app and never deleted by a deploy, so a failed chunk load there is a real
 * error, and "reload to fetch the new build" would only reload the same one.
 */
if (!isNativeApp()) installStaleAssetRecovery(router)

// MOB-27/29/31 — the app-only overlays (lock, offline, update), handed to
// App.vue by injection so App.vue never imports them; and the biometric lock
// goes up BEFORE the first frame, so the portal never shows behind it.
const nativeBoot = nativeBootLoading ? await nativeBootLoading : null
if (nativeBoot) {
  app.provide(NATIVE_LAYER_KEY, nativeBoot.NativeAppLayer)
  await nativeBoot.beforeMount(pinia)
} else if (nativeBootLoading) {
  // capacitor.config.ts keeps the native splash up until it is hidden; if
  // nativeBoot itself failed to load, hide it here or the app never shows.
  void import('@capacitor/splash-screen').then((m) => m.SplashScreen.hide()).catch(() => {})
}

app.mount('#app')

nativeBoot?.afterMount({ router, pinia })

// Fade + remove the boot splash now that Vue owns the screen. The very
// first router navigation (router/index.ts's beforeEach) already resolves
// isAuthenticated from the fetchUser() call above, so it lands directly on
// Home or Login as appropriate — no extra redirect logic needed here.
if (splash) {
  splash.style.opacity = '0'
  window.setTimeout(() => splash.remove(), 300)
}
