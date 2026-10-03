/**
 * MOB-29 (2026-10-02) — unlock the app with Face ID / a fingerprint.
 *
 * Owner decision: an agent can switch this on and off in their profile.
 *
 * ── WHAT IT PROTECTS, AND WHAT IT DOES NOT ──
 *
 * The app keeps the agent signed in (the token is in the Keychain), so
 * anyone who picks up an unlocked phone is inside the agent's account —
 * clients' names and phone numbers, health notes (PDPA), commission. With
 * this on, the app shows a lock screen on launch and after it has been in the
 * background for more than LOCK_AFTER_BACKGROUND_MS, and only the phone's
 * owner (biometrics, or the device passcode as the fallback) gets past it.
 *
 * It is a gate in front of a session that already exists, not a second way
 * to sign in: no password is ever stored, and nothing is sent to the server.
 * The session itself is still the server's 12-hour token.
 *
 * ── WHERE THE SETTING LIVES ──
 *
 * @capacitor/preferences, keyed by user id: two agents sharing a phone each
 * have their own answer, and signing in as somebody else never inherits the
 * previous person's lock.
 *
 * In a browser nothing here runs: checkBiometricSupport() reports
 * unavailable, so the profile toggle is not even rendered.
 */
import { reactive } from 'vue'
import { isNativeApp } from './index'

/** Owner spec: lock again after more than 60 seconds in the background. */
export const LOCK_AFTER_BACKGROUND_MS = 60_000

export type BiometryKind = 'face' | 'fingerprint' | 'iris' | 'other'

export interface BiometricSupport {
  available: boolean
  kind: BiometryKind | null
}

async function plugin() {
  const { BiometricAuth } = await import('@aparajita/capacitor-biometric-auth')

  return BiometricAuth
}

async function preferences() {
  const { Preferences } = await import('@capacitor/preferences')

  return Preferences
}

/**
 * The plugin's BiometryType enum values (definitions.d.ts): 1 touchId,
 * 2 faceId, 3 fingerprintAuthentication, 4 faceAuthentication,
 * 5 irisAuthentication. Compared by value so the enum (a runtime object) is
 * not needed outside the dynamic import.
 */
function kindOf(type: number): BiometryKind {
  if (type === 2 || type === 4) return 'face'
  if (type === 1 || type === 3) return 'fingerprint'
  if (type === 5) return 'iris'

  return 'other'
}

/** Can this phone do it right now (hardware present AND enrolled)? */
export async function checkBiometricSupport(): Promise<BiometricSupport> {
  if (!isNativeApp()) return { available: false, kind: null }
  try {
    const result = await (await plugin()).checkBiometry()

    return {
      available: result.isAvailable,
      kind: result.isAvailable ? kindOf(result.biometryType) : null,
    }
  } catch {
    return { available: false, kind: null }
  }
}

export type BiometricResult = 'ok' | 'cancelled' | 'unavailable' | 'failed'

/** BiometryErrorType values (definitions.d.ts) that mean "the person chose not to". */
const CANCEL_CODES = ['userCancel', 'systemCancel', 'appCancel']
/** …and the ones that mean "this phone cannot do it any more". */
const UNAVAILABLE_CODES = [
  'biometryNotAvailable',
  'biometryNotEnrolled',
  'passcodeNotSet',
  'noDeviceCredential',
]

/**
 * Show the system prompt. The device passcode is allowed as the fallback
 * (owner spec), so a wet finger or a mask never locks the agent out of their
 * own app.
 */
export async function authenticateBiometric(texts: {
  reason: string
  cancel: string
  title: string
}): Promise<BiometricResult> {
  if (!isNativeApp()) return 'unavailable'
  try {
    await (
      await plugin()
    ).authenticate({
      reason: texts.reason,
      cancelTitle: texts.cancel,
      allowDeviceCredential: true,
      androidTitle: texts.title,
      androidSubtitle: texts.reason,
    })

    return 'ok'
  } catch (error) {
    const code = (error as { code?: unknown } | null)?.code
    if (typeof code === 'string' && CANCEL_CODES.includes(code)) return 'cancelled'
    if (typeof code === 'string' && UNAVAILABLE_CODES.includes(code)) return 'unavailable'

    return 'failed'
  }
}

function preferenceKey(userId: number): string {
  return `sv_biometric_lock:${userId}`
}

export async function isBiometricLockEnabled(userId: number): Promise<boolean> {
  if (!isNativeApp()) return false
  try {
    const { value } = await (await preferences()).get({ key: preferenceKey(userId) })

    return value === '1'
  } catch {
    return false
  }
}

export async function setBiometricLockEnabled(userId: number, enabled: boolean): Promise<void> {
  if (!isNativeApp()) return
  const store = await preferences()
  if (enabled) await store.set({ key: preferenceKey(userId), value: '1' })
  else await store.remove({ key: preferenceKey(userId) })
}

/** What NativeAppLayer renders. */
export const lockState = reactive({
  /** The lock screen is up. */
  active: false,
  /** The system prompt is on screen (see the pause guard below). */
  authenticating: false,
  /** Last outcome other than success, for the lock screen's message. */
  lastResult: null as BiometricResult | null,
})

/** Put the lock screen up if this user turned the lock on. */
export async function lockIfEnabled(userId: number | null): Promise<void> {
  if (userId === null) return
  if (await isBiometricLockEnabled(userId)) {
    lockState.lastResult = null
    lockState.active = true
  }
}

/** The lock screen's "unlock" — and its automatic first attempt. */
export async function unlock(texts: {
  reason: string
  cancel: string
  title: string
}): Promise<BiometricResult> {
  if (lockState.authenticating) return 'failed'

  lockState.authenticating = true
  try {
    const result = await authenticateBiometric(texts)
    lockState.lastResult = result === 'ok' ? null : result
    if (result === 'ok') lockState.active = false

    return result
  } finally {
    lockState.authenticating = false
  }
}

/** Sign-out from the lock screen, or a session that ended: drop the lock. */
export function clearLock(): void {
  lockState.active = false
  lockState.lastResult = null
}

/**
 * Lock again after the app has been in the background for too long.
 *
 * `pause` while the SYSTEM PROMPT is up is ignored: on Android the prompt is
 * its own activity, so authenticating sends the app through pause/resume —
 * and an agent who takes a while over their fingerprint must not be locked
 * again the moment they succeed.
 */
export async function installResumeLock(currentUserId: () => number | null): Promise<void> {
  if (!isNativeApp()) return

  const { App } = await import('@capacitor/app')
  let pausedAt: number | null = null

  await App.addListener('pause', () => {
    if (!lockState.authenticating) pausedAt = Date.now()
  })

  await App.addListener('resume', () => {
    const since = pausedAt
    pausedAt = null
    if (since === null || Date.now() - since <= LOCK_AFTER_BACKGROUND_MS) return

    void lockIfEnabled(currentUserId())
  })
}
