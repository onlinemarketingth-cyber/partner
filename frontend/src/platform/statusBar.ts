/**
 * MOB-25 (2026-10-02) — the phone's status bar (clock, battery) follows the
 * company's theme.
 *
 * Inside the app the top bar is drawn UNDER the status bar (viewport-fit=
 * cover, App.vue pads it by the safe-area inset), so the clock sits on the
 * company's nav colour. Its text must be light on a dark nav and dark on a
 * light one, or it disappears.
 *
 * The colour is not re-derived here: theme.ts already computes `--ink-nav`,
 * the ink that passes contrast against the nav bar (TASK-161), and writes it
 * to the document as "R G B" channels. Light ink ⇒ dark bar ⇒ light status
 * text (Style.Dark, in Capacitor's naming: "light text for dark
 * backgrounds").
 *
 * Pages without the top bar (login) use the system default.
 *
 * ANDROID BEFORE EDGE-TO-EDGE: on an older Android WebView Capacitor keeps the
 * page BELOW the status bar (it pads the WebView and reports a 0 inset), so
 * the bar sits on the window background, not on the nav colour — matching
 * the nav ink there could put white text on white. Capacitor's SystemBars
 * writes the real inset to `--safe-area-inset-top`; when that is 0 on
 * Android, the system default is kept.
 *
 * App only; in a browser nothing is loaded.
 */
import { relativeLuminance } from '@/theme/contrast'
import { isNativeApp, nativePlatform } from './index'

export type StatusBarStyleName = 'DARK' | 'LIGHT' | 'DEFAULT'

/** "R G B" channels of the nav ink → the status bar style that matches. */
export function statusBarStyleForInk(channels: string): StatusBarStyleName {
  const parts = channels
    .trim()
    .split(/[\s,]+/)
    .map(Number)
  if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) return 'DEFAULT'

  return relativeLuminance(parts as [number, number, number]) > 0.5 ? 'DARK' : 'LIGHT'
}

/** Is the page drawn under the status bar? Always on iOS (see the header). */
function drawsUnderStatusBar(root: CSSStyleDeclaration): boolean {
  if (nativePlatform() !== 'android') return true

  return Number.parseFloat(root.getPropertyValue('--safe-area-inset-top')) > 0
}

/** Read the theme's nav ink and apply it. `chrome`: is the top bar on screen? */
export async function syncStatusBar(chrome: boolean): Promise<void> {
  if (!isNativeApp()) return
  try {
    const root = getComputedStyle(document.documentElement)
    const style =
      chrome && drawsUnderStatusBar(root)
        ? statusBarStyleForInk(root.getPropertyValue('--ink-nav'))
        : 'DEFAULT'
    const { StatusBar, Style } = await import('@capacitor/status-bar')
    await StatusBar.setStyle({
      style: style === 'DARK' ? Style.Dark : style === 'LIGHT' ? Style.Light : Style.Default,
    })
  } catch {
    // Cosmetic; the system default is still readable most of the time.
  }
}
