/**
 * MOB-22 (2026-10-02) — opening a page that is not part of the portal.
 *
 * Three places do this today: a storefront banner that points at an outside
 * URL (ProductBrowseView), an Academy lesson whose content lives on another
 * site (AcademyLessonView), and "open the buy page" on an agent's own product
 * link (useProductShare.openBuyPage).
 *
 * ── WHY THE APP NEEDS ITS OWN PATH ──
 *
 * `window.open(url, '_blank')` inside the app does not open a tab — there are
 * no tabs. iOS hands it to Safari, Android to whatever browser is the
 * default, and the agent has left the app with no obvious way back.
 * `window.location.assign()` is worse: it replaces the APP itself with the
 * outside page, and the only way back is to kill the app.
 *
 * @capacitor/browser opens an in-app browser instead (SFSafariViewController
 * on iOS, a Custom Tab on Android): a sheet over the app with a "Done" /
 * close button that returns to exactly where the agent was.
 *
 * ── mailto: AND tel: ──
 *
 * The in-app browser only takes http(s). For mailto:/tel:/sms: the app sets
 * window.location.href, the same thing the web portal does: both native
 * shells refuse to load a non-app URL in the WebView and hand it to the OS
 * instead — iOS `UIApplication.shared.open` (Capacitor's
 * WebViewDelegationHandler.decidePolicyFor), Android an ACTION_VIEW intent
 * (Capacitor's Bridge.launchIntent). Verified against the installed
 * @capacitor/ios and @capacitor/android 8.5 sources, not assumed. So the mail
 * app / dialler opens and the agent's place in the app is kept.
 *
 * Any OTHER scheme is ignored in the app. The URLs here are admin-entered
 * (banner, lesson), and a `javascript:` URL assigned to location would run
 * inside the signed-in app.
 *
 * ── WHAT THIS FILE DOES NOT CHANGE ──
 *
 * In a browser openExternal() is the call each site already made:
 * window.open(url, '_blank', 'noopener') — or window.location.assign(url)
 * for the one caller that navigated the tab itself.
 */
import { isNativeApp } from './index'

/** What the BROWSER build should do — each caller keeps its old behaviour. */
export type WebOpenMode = 'new-tab' | 'same-tab'

/** Schemes the app may hand to the OS. Everything else is dropped. */
const SYSTEM_SCHEMES = ['mailto:', 'tel:', 'sms:']

function protocolOf(url: string): string {
  try {
    return new URL(url).protocol
  } catch {
    return ''
  }
}

export function isHttpUrl(url: string): boolean {
  const protocol = protocolOf(url)

  return protocol === 'http:' || protocol === 'https:'
}

/**
 * Open a page outside the portal.
 *
 * Browser: a new tab (default) or the same tab, exactly as before.
 * App: in-app browser for http(s); the OS for mailto:/tel:/sms:.
 */
export async function openExternal(
  url: string,
  options: { web?: WebOpenMode } = {},
): Promise<void> {
  if (!isNativeApp()) {
    if (options.web === 'same-tab') window.location.assign(url)
    else window.open(url, '_blank', 'noopener')

    return
  }

  if (isHttpUrl(url)) {
    const { Browser } = await import('@capacitor/browser')
    await Browser.open({ url })

    return
  }

  if (SYSTEM_SCHEMES.includes(protocolOf(url))) {
    window.location.href = url
  }
}

/**
 * MOB-31 — open a URL with whichever app the OS picks for it, NOT the in-app
 * browser. For the store page: inside SFSafariViewController an App Store
 * link is a web page with yet another "open in App Store" button, whereas a
 * plain navigation is handed by the shell to the OS (see the header), which
 * opens the App Store / Google Play app directly.
 *
 * Browser: a new tab, like openExternal().
 */
export function openWithSystem(url: string): void {
  if (!isNativeApp()) {
    window.open(url, '_blank', 'noopener')

    return
  }

  if (isHttpUrl(url) || SYSTEM_SCHEMES.includes(protocolOf(url))) {
    window.location.href = url
  }
}

/**
 * App only — route every `<a href="https://elsewhere">` through
 * openExternal().
 *
 * Plain anchors are all over the portal (an Academy lesson's "open in a new
 * tab" link, an agent's phone/email links on the product page). Without this
 * each would leave the app for the system browser. One listener on the
 * document catches them all, instead of a change in every template.
 *
 * Bubble phase, and only when nobody has handled the click already: a
 * component that calls preventDefault() itself keeps full control, and
 * in-app links (same origin — RouterLink) are never touched. mailto:/tel:
 * are left alone: the shell already hands them to the OS (see the header).
 *
 * Returns the remover, for tests.
 */
export function installExternalLinkInterceptor(): () => void {
  if (!isNativeApp()) return () => {}

  const onClick = (event: MouseEvent) => {
    if (event.defaultPrevented) return

    const anchor = (event.target as Element | null)?.closest?.(
      'a[href]',
    ) as HTMLAnchorElement | null
    if (!anchor) return

    let target: URL
    try {
      target = new URL(anchor.href, window.location.href)
    } catch {
      return
    }

    if (target.protocol !== 'http:' && target.protocol !== 'https:') return
    if (target.origin === window.location.origin) return

    event.preventDefault()
    void openExternal(target.href)
  }

  document.addEventListener('click', onClick)

  return () => document.removeEventListener('click', onClick)
}
