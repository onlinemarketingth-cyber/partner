/**
 * MOB-31 / MOB-27 (2026-10-02) — "this app is too old" and "no internet".
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. EVERY PHONE IS LOCKED OUT AT ONCE. The forced-update screen must appear
 *    ONLY when the installed version is definitely below the server's
 *    minimum. A failed request, a null threshold, or a version nobody can
 *    parse must do nothing — otherwise a server hiccup is an outage.
 * 2. "1.10.0" IS OLDER THAN "1.9.0". A string comparison says so; the
 *    numbers do not.
 * 3. THE UPDATE BANNER NAGS. Once dismissed for a version it must not come
 *    back for that version.
 * 4. THE WEB PORTAL ASKS FOR A VERSION POLICY, or shows an offline notice.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any

const native = { value: true }
const get = vi.fn<AnyFn>()
const prefs = new Map<string, string>()
const networkListeners: Array<(s: { connected: boolean }) => void> = []
const getStatus = vi.fn<AnyFn>()

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? 'ios' : 'web'),
  },
}))

vi.mock('@capacitor/app', () => ({ App: { getInfo: async () => ({ version: '1.4.0' }) } }))

vi.mock('@capacitor/preferences', () => ({
  Preferences: {
    get: async ({ key }: { key: string }) => ({ value: prefs.get(key) ?? null }),
    set: async ({ key, value }: { key: string; value: string }) => {
      prefs.set(key, value)
    },
  },
}))

vi.mock('@capacitor/network', () => ({
  Network: {
    getStatus: () => getStatus(),
    addListener: async (_name: string, fn: (s: { connected: boolean }) => void) => {
      networkListeners.push(fn)

      return { remove: vi.fn<AnyFn>() }
    },
  },
}))

vi.mock('@/api/client', () => ({ api: { get: (path: string) => get(path) } }))

import { compareVersions, isOlderThan, parseVersion } from '@/utils/semver'
import {
  checkForUpdate,
  dismissUpdateBanner,
  evaluateVersionPolicy,
  runUpdateCheck,
  updateState,
  type VersionPolicy,
} from '../versionPolicy'
import { installNetworkWatcher, networkState, recheckNetwork } from '../network'

function policy(overrides: Partial<VersionPolicy> = {}): VersionPolicy {
  return {
    platform: 'ios',
    min_supported_version: null,
    latest_version: null,
    store_url: 'https://apps.apple.com/app/id1',
    ...overrides,
  }
}

beforeEach(() => {
  native.value = true
  prefs.clear()
  get.mockReset()
  getStatus.mockReset().mockResolvedValue({ connected: true, connectionType: 'wifi' })
  networkListeners.length = 0
  networkState.offline = false
  updateState.required = false
  updateState.available = null
  updateState.storeUrl = null
})

describe('semver', () => {
  it('compares numbers, not strings', () => {
    expect(compareVersions('1.10.0', '1.9.0')).toBe(1)
    expect(compareVersions('1.2', '1.2.0')).toBe(0)
    expect(compareVersions('v2.0.0', '2.0.0')).toBe(0)
    expect(compareVersions('1.0.0+45', '1.0.0+46')).toBe(0)
    expect(compareVersions('0.9.9', '1.0.0')).toBe(-1)
  })

  it('a pre-release is older than its release', () => {
    expect(compareVersions('1.2.3-beta.1', '1.2.3')).toBe(-1)
    expect(compareVersions('1.2.3-beta.2', '1.2.3-beta.10')).toBe(-1)
    expect(compareVersions('1.2.3-alpha', '1.2.3-beta')).toBe(-1)
    expect(compareVersions('1.2.3-1', '1.2.3-alpha')).toBe(-1)
  })

  it('anything it cannot read is "cannot tell", never "older"', () => {
    for (const bad of ['', 'latest', '1..2', '1.2.x', '1.2-', null, undefined]) {
      expect(parseVersion(bad as string)).toBeNull()
      expect(compareVersions(bad as string, '1.0.0')).toBeNull()
      expect(isOlderThan(bad as string, '1.0.0')).toBe(false)
      expect(isOlderThan('1.0.0', bad as string)).toBe(false)
    }
  })
})

describe('the update decision', () => {
  it('below the minimum → blocking; below latest → banner; else nothing', () => {
    expect(evaluateVersionPolicy('1.4.0', policy({ min_supported_version: '1.5.0' }))).toEqual({
      kind: 'required',
      storeUrl: 'https://apps.apple.com/app/id1',
    })
    expect(
      evaluateVersionPolicy(
        '1.4.0',
        policy({ min_supported_version: '1.0.0', latest_version: '1.10.0' }),
      ),
    ).toEqual({
      kind: 'available',
      latestVersion: '1.10.0',
      storeUrl: 'https://apps.apple.com/app/id1',
    })
    expect(
      evaluateVersionPolicy(
        '1.4.0',
        policy({ min_supported_version: '1.4.0', latest_version: '1.4.0' }),
      ),
    ).toEqual({
      kind: 'none',
    })
  })

  it('null thresholds, a typo, or an unknown installed version do nothing', () => {
    expect(evaluateVersionPolicy('1.4.0', policy())).toEqual({ kind: 'none' })
    expect(
      evaluateVersionPolicy('1.4.0', policy({ min_supported_version: 'one point five' })),
    ).toEqual({ kind: 'none' })
    expect(evaluateVersionPolicy(null, policy({ min_supported_version: '9.0.0' }))).toEqual({
      kind: 'none',
    })
    expect(evaluateVersionPolicy('1.4.0', null)).toEqual({ kind: 'none' })
  })

  it('asks the server for THIS platform', async () => {
    get.mockResolvedValue({ data: policy({ min_supported_version: '2.0.0' }) })

    await expect(checkForUpdate()).resolves.toEqual({
      kind: 'required',
      storeUrl: 'https://apps.apple.com/app/id1',
    })
    expect(get).toHaveBeenCalledWith('/app/version-policy?platform=ios')
  })

  it('a failed request is "nothing to do", never a blocked app', async () => {
    get.mockRejectedValue(new Error('500'))
    await runUpdateCheck()

    expect(updateState.required).toBe(false)
    expect(updateState.available).toBeNull()
  })

  it('the banner shows once per version', async () => {
    get.mockResolvedValue({ data: policy({ latest_version: '1.5.0' }) })

    await runUpdateCheck()
    expect(updateState.available).toBe('1.5.0')

    await dismissUpdateBanner()
    expect(updateState.available).toBeNull()

    await runUpdateCheck()
    expect(updateState.available).toBeNull()

    get.mockResolvedValue({ data: policy({ latest_version: '1.6.0' }) })
    await runUpdateCheck()
    expect(updateState.available).toBe('1.6.0')
  })

  it('a browser never asks', async () => {
    native.value = false

    await expect(checkForUpdate()).resolves.toEqual({ kind: 'none' })
    expect(get).not.toHaveBeenCalled()
  })
})

describe('offline notice', () => {
  it('follows the connection, both ways', async () => {
    getStatus.mockResolvedValue({ connected: false, connectionType: 'none' })
    await installNetworkWatcher()
    expect(networkState.offline).toBe(true)

    networkListeners[0]?.({ connected: true })
    expect(networkState.offline).toBe(false)
  })

  it('retry re-checks and reports the result', async () => {
    networkState.offline = true
    getStatus.mockResolvedValue({ connected: true, connectionType: 'cellular' })

    await expect(recheckNetwork()).resolves.toBe(true)
    expect(networkState.offline).toBe(false)
  })

  it('a browser never shows it', async () => {
    native.value = false
    getStatus.mockResolvedValue({ connected: false, connectionType: 'none' })

    await installNetworkWatcher()

    expect(getStatus).not.toHaveBeenCalled()
    expect(networkState.offline).toBe(false)
  })
})
