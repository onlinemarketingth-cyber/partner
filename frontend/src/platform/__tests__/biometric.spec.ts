/**
 * MOB-29 (2026-10-02) — Face ID / fingerprint unlock.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE LOCK DOES NOT LOCK. The preference is read per user; a wrong key or
 *    a lost resume listener and the app simply never asks — which looks
 *    exactly like the feature working, until a phone is picked up by someone
 *    else.
 * 2. THE AGENT IS LOCKED OUT OF THEIR OWN APP. Cancelling must leave the lock
 *    screen up with a way to retry (not sign them out); the device passcode
 *    must be allowed as the fallback (owner spec).
 * 3. AUTHENTICATING RE-LOCKS. On Android the system prompt is its own
 *    activity, so authenticating pauses the app; an agent slow over their
 *    finger must not be locked again on success.
 * 4. 60 SECONDS IS NOT 60 SECONDS (owner spec: lock again after MORE than
 *    60s in the background — not on every app switch).
 * 5. THE WEB PORTAL CHANGES — nothing here may report "available" or load
 *    a plugin in a browser, so the profile row never renders there.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any

const native = { value: true }
const prefs = new Map<string, string>()
const appListeners: Record<string, () => void> = {}
const checkBiometry = vi.fn<AnyFn>()
const authenticate = vi.fn<AnyFn>()

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? 'ios' : 'web'),
  },
}))

vi.mock('@aparajita/capacitor-biometric-auth', () => ({
  BiometricAuth: {
    checkBiometry: () => checkBiometry(),
    authenticate: (o: unknown) => authenticate(o),
  },
}))

vi.mock('@capacitor/preferences', () => ({
  Preferences: {
    get: async ({ key }: { key: string }) => ({ value: prefs.get(key) ?? null }),
    set: async ({ key, value }: { key: string; value: string }) => {
      prefs.set(key, value)
    },
    remove: async ({ key }: { key: string }) => {
      prefs.delete(key)
    },
  },
}))

vi.mock('@capacitor/app', () => ({
  App: {
    addListener: async (name: string, fn: () => void) => {
      appListeners[name] = fn

      return { remove: vi.fn<AnyFn>() }
    },
  },
}))

import {
  authenticateBiometric,
  checkBiometricSupport,
  clearLock,
  installResumeLock,
  isBiometricLockEnabled,
  LOCK_AFTER_BACKGROUND_MS,
  lockIfEnabled,
  lockState,
  setBiometricLockEnabled,
  unlock,
} from '../biometric'

const TEXTS = { reason: 'ยืนยันตัวตน', cancel: 'ยกเลิก', title: 'ล็อก' }

function biometryError(code: string) {
  return Object.assign(new Error(code), { code })
}

beforeEach(() => {
  native.value = true
  prefs.clear()
  clearLock()
  checkBiometry.mockReset().mockResolvedValue({ isAvailable: true, biometryType: 2 })
  authenticate.mockReset().mockResolvedValue(undefined)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('what the phone can do', () => {
  it('reports Face ID / fingerprint, and "unavailable" when not enrolled', async () => {
    await expect(checkBiometricSupport()).resolves.toEqual({ available: true, kind: 'face' })

    checkBiometry.mockResolvedValue({ isAvailable: true, biometryType: 3 })
    await expect(checkBiometricSupport()).resolves.toEqual({ available: true, kind: 'fingerprint' })

    checkBiometry.mockResolvedValue({ isAvailable: false, biometryType: 2 })
    await expect(checkBiometricSupport()).resolves.toEqual({ available: false, kind: null })
  })

  it('allows the device passcode as the fallback', async () => {
    await authenticateBiometric(TEXTS)

    expect(authenticate).toHaveBeenCalledWith(
      expect.objectContaining({ allowDeviceCredential: true, reason: 'ยืนยันตัวตน' }),
    )
  })

  it('tells a cancel apart from a failure and from "this phone cannot"', async () => {
    authenticate.mockRejectedValue(biometryError('userCancel'))
    await expect(authenticateBiometric(TEXTS)).resolves.toBe('cancelled')

    authenticate.mockRejectedValue(biometryError('passcodeNotSet'))
    await expect(authenticateBiometric(TEXTS)).resolves.toBe('unavailable')

    authenticate.mockRejectedValue(biometryError('authenticationFailed'))
    await expect(authenticateBiometric(TEXTS)).resolves.toBe('failed')
  })
})

describe('the setting, per user', () => {
  it('is stored on the phone per user id and never leaks to another user', async () => {
    await setBiometricLockEnabled(7, true)

    await expect(isBiometricLockEnabled(7)).resolves.toBe(true)
    await expect(isBiometricLockEnabled(8)).resolves.toBe(false)

    await setBiometricLockEnabled(7, false)
    await expect(isBiometricLockEnabled(7)).resolves.toBe(false)
  })
})

describe('the lock screen', () => {
  it('goes up at launch only for a user who turned it on', async () => {
    await lockIfEnabled(7)
    expect(lockState.active).toBe(false)

    await setBiometricLockEnabled(7, true)
    await lockIfEnabled(7)
    expect(lockState.active).toBe(true)

    clearLock()
    await lockIfEnabled(null)
    expect(lockState.active).toBe(false)
  })

  it('stays up after a cancel, with the reason, and comes down on success', async () => {
    lockState.active = true

    authenticate.mockRejectedValueOnce(biometryError('userCancel'))
    await unlock(TEXTS)
    expect(lockState.active).toBe(true)
    expect(lockState.lastResult).toBe('cancelled')

    await unlock(TEXTS)
    expect(lockState.active).toBe(false)
    expect(lockState.lastResult).toBeNull()
  })

  it('locks again after MORE than 60s in the background, not on a quick switch', async () => {
    vi.useFakeTimers()
    await setBiometricLockEnabled(7, true)
    await installResumeLock(() => 7)

    appListeners.pause?.()
    vi.advanceTimersByTime(LOCK_AFTER_BACKGROUND_MS)
    appListeners.resume?.()
    await vi.runAllTimersAsync()
    expect(lockState.active).toBe(false)

    appListeners.pause?.()
    vi.advanceTimersByTime(LOCK_AFTER_BACKGROUND_MS + 1000)
    appListeners.resume?.()
    await vi.waitFor(() => expect(lockState.active).toBe(true))
  })

  it('the system prompt itself (Android pauses the app) never re-locks', async () => {
    vi.useFakeTimers()
    await setBiometricLockEnabled(7, true)
    await installResumeLock(() => 7)
    lockState.authenticating = true

    appListeners.pause?.()
    vi.advanceTimersByTime(LOCK_AFTER_BACKGROUND_MS * 2)
    lockState.authenticating = false
    appListeners.resume?.()
    await vi.runAllTimersAsync()

    expect(lockState.active).toBe(false)
  })
})

describe('in a browser', () => {
  it('is never available and never locks', async () => {
    native.value = false

    await expect(checkBiometricSupport()).resolves.toEqual({ available: false, kind: null })
    await expect(isBiometricLockEnabled(7)).resolves.toBe(false)
    await expect(authenticateBiometric(TEXTS)).resolves.toBe('unavailable')
    expect(checkBiometry).not.toHaveBeenCalled()
  })
})
