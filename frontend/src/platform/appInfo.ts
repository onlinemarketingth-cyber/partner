/**
 * MOB-28 / MOB-31 (2026-10-02) — the installed app's version string, as the
 * store knows it (CFBundleShortVersionString on iOS, versionName on Android).
 *
 * Two things need it: the device registration (so the server can tell which
 * builds still receive pushes) and the update check (is this build still
 * supported?). One helper, so both read the same value the same way.
 *
 * Null in a browser and on any failure — both callers treat "unknown" as
 * "do nothing", never as a reason to block somebody.
 */
import { isNativeApp } from './index'

export async function installedAppVersion(): Promise<string | null> {
  if (!isNativeApp()) return null
  try {
    const { App } = await import('@capacitor/app')
    const info = await App.getInfo()

    return info.version || null
  } catch {
    return null
  }
}
