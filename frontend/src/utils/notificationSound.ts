/**
 * The chime that plays when a new notification arrives.
 *
 * ── WHY IT IS SYNTHESISED AND NOT AN AUDIO FILE ──
 *
 * An .mp3 would be a build asset, a network request, a cache entry and a
 * content-hash to keep in step — for roughly 300ms of two sine tones. Web
 * Audio produces it in about twenty lines with no file, no request and no
 * decoding step, which also means it cannot fail with a 404 on a stale
 * deploy.
 *
 * ── AUTOPLAY IS THE HARD PART, NOT THE SOUND ──
 *
 * Every browser refuses audio until the user has interacted with the page,
 * and an AudioContext created before that first gesture starts `suspended`.
 * So:
 *   - the context is created LAZILY, on the first play attempt, not at
 *     import time (a suspended context created at boot stays suspended in
 *     Safari even after a later gesture);
 *   - resume() is attempted every time, because a context can be suspended
 *     again by the browser when a tab is backgrounded;
 *   - every path is wrapped, and a refusal is SILENT. A notification that
 *     could not make a sound is a missing nicety; an unhandled promise
 *     rejection in the console on every poll is a bug report.
 *
 * ── THE MUTE IS THE FEATURE, NOT AN AFTERTHOUGHT ──
 *
 * A sound the user cannot stop is worse than no sound: an agent working with
 * the portal open in a quiet office will simply close the tab. The preference
 * lives in localStorage rather than on the server because it is a property of
 * THIS device — the same person may want the chime on their desk machine and
 * off on the phone they carry into meetings.
 */

const MUTE_KEY = 'sv.notificationSound.muted'

let context: AudioContext | null = null

/** localStorage throws outright in some privacy modes — never let it escape. */
function readMuted(): boolean {
  try {
    return window.localStorage.getItem(MUTE_KEY) === '1'
  } catch {
    return false
  }
}

export function isNotificationSoundMuted(): boolean {
  return readMuted()
}

export function setNotificationSoundMuted(muted: boolean): void {
  try {
    window.localStorage.setItem(MUTE_KEY, muted ? '1' : '0')
  } catch {
    // A device that cannot remember the choice still honours it for this
    // session — the caller holds the value in a ref.
  }
}

function ensureContext(): AudioContext | null {
  if (context) return context

  try {
    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!Ctor) return null
    context = new Ctor()
    return context
  } catch {
    return null
  }
}

/**
 * A short two-note chime — a rising fifth, which reads as "something
 * arrived" rather than as an alarm. Deliberately quiet (peak gain 0.12) and
 * under 400ms: this fires while somebody is working, possibly next to
 * colleagues.
 */
export function playNotificationSound(): void {
  if (readMuted()) return

  const ctx = ensureContext()
  if (!ctx) return

  try {
    // A context suspended by the autoplay policy (or by the tab being
    // backgrounded) resolves this and plays; one the browser still refuses
    // rejects, and we let it go quietly.
    void ctx.resume?.().catch(() => {})

    const now = ctx.currentTime
    const notes: Array<[number, number]> = [
      [880, 0], // A5
      [1318.5, 0.12], // E6, a fifth above
    ]

    for (const [frequency, offset] of notes) {
      const oscillator = ctx.createOscillator()
      const gain = ctx.createGain()

      oscillator.type = 'sine'
      oscillator.frequency.value = frequency

      // An abrupt start or stop on a sine wave is an audible click. The
      // short ramp in and exponential decay out is what makes it read as a
      // chime rather than a beep.
      const start = now + offset
      gain.gain.setValueAtTime(0.0001, start)
      gain.gain.exponentialRampToValueAtTime(0.12, start + 0.015)
      gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.22)

      oscillator.connect(gain)
      gain.connect(ctx.destination)
      oscillator.start(start)
      oscillator.stop(start + 0.24)
    }
  } catch {
    // Nothing here is worth interrupting the app for.
  }
}
