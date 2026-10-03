/**
 * MOB-31 (2026-10-02) — "this version of the app is too old".
 *
 * A web portal is always the latest version; an installed app is whatever
 * the agent last downloaded, possibly months ago. When an API change makes
 * an old build unsafe to use (a field it no longer understands, a flow that
 * moved), the server must be able to say so. GET /app/version-policy answers
 * per platform with two thresholds, both admin-set (BR-7 — never hardcoded
 * here):
 *
 *   installed < min_supported_version → a blocking screen with one button,
 *     to the store. The agent cannot continue on a build the server has
 *     declared broken.
 *   installed < latest_version        → a banner they can dismiss, shown
 *     once per new version, never again for the same one.
 *
 * ── FAILURE MEANS NOTHING HAPPENS ──
 *
 * No network, a 500, a version string nobody can parse, a null threshold:
 * every one of those leaves the app exactly as usable as it was. Blocking an
 * agent because the CHECK failed would turn a server hiccup into an outage
 * for every phone at once.
 *
 * In a browser checkForUpdate() returns 'none' without a request.
 */
import { reactive } from 'vue'
import { api } from '@/api/client'
import { isOlderThan } from '@/utils/semver'
import { isNativeApp, nativePlatform } from './index'
import { installedAppVersion } from './appInfo'

export interface VersionPolicy {
  platform: 'ios' | 'android'
  min_supported_version: string | null
  latest_version: string | null
  store_url: string | null
}

export type UpdateVerdict =
  | { kind: 'none' }
  | { kind: 'required'; storeUrl: string | null }
  | { kind: 'available'; latestVersion: string; storeUrl: string | null }

/** The decision on its own, so it can be tested without a phone. */
export function evaluateVersionPolicy(
  installed: string | null,
  policy: VersionPolicy | null,
): UpdateVerdict {
  if (!installed || !policy) return { kind: 'none' }

  if (isOlderThan(installed, policy.min_supported_version)) {
    return { kind: 'required', storeUrl: policy.store_url }
  }

  if (policy.latest_version && isOlderThan(installed, policy.latest_version)) {
    return { kind: 'available', latestVersion: policy.latest_version, storeUrl: policy.store_url }
  }

  return { kind: 'none' }
}

/** Ask the server. Any failure is 'none' (see the header). */
export async function checkForUpdate(): Promise<UpdateVerdict> {
  if (!isNativeApp()) return { kind: 'none' }

  const platform = nativePlatform()
  if (platform === 'web') return { kind: 'none' }

  try {
    const [installed, res] = await Promise.all([
      installedAppVersion(),
      api.get<{ data: VersionPolicy }>(`/app/version-policy?platform=${platform}`),
    ])

    return evaluateVersionPolicy(installed, res?.data ?? null)
  } catch {
    return { kind: 'none' }
  }
}

/** Preferences key: the latest_version whose banner the agent closed. */
const DISMISSED_KEY = 'sv_update_banner_dismissed'

async function preferences() {
  const { Preferences } = await import('@capacitor/preferences')

  return Preferences
}

export async function wasBannerDismissed(version: string): Promise<boolean> {
  try {
    const { value } = await (await preferences()).get({ key: DISMISSED_KEY })

    return value === version
  } catch {
    return false
  }
}

export async function rememberBannerDismissed(version: string): Promise<void> {
  try {
    await (await preferences()).set({ key: DISMISSED_KEY, value: version })
  } catch {
    // Worst case the banner shows once more on the next launch.
  }
}

/** What NativeAppLayer renders. */
export const updateState = reactive<{
  required: boolean
  available: string | null
  storeUrl: string | null
}>({ required: false, available: null, storeUrl: null })

/** Launch-time check: fills updateState. Never throws. */
export async function runUpdateCheck(): Promise<void> {
  const verdict = await checkForUpdate()

  if (verdict.kind === 'required') {
    updateState.required = true
    updateState.storeUrl = verdict.storeUrl

    return
  }

  if (verdict.kind === 'available' && !(await wasBannerDismissed(verdict.latestVersion))) {
    updateState.available = verdict.latestVersion
    updateState.storeUrl = verdict.storeUrl
  }
}

/** The banner's ✕ — hide it now and for this version from now on. */
export async function dismissUpdateBanner(): Promise<void> {
  const version = updateState.available
  updateState.available = null
  if (version) await rememberBannerDismissed(version)
}
