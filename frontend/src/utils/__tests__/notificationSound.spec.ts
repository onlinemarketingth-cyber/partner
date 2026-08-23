/**
 * The notification chime, and the mute that makes it acceptable.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 * 1. THE MUTE STOPS WORKING. A sound the user cannot stop is the fastest
 *    route to somebody closing the tab. If playNotificationSound() forgets
 *    to check the preference, nothing errors — the person just gets chimed
 *    at after asking not to be, and there is no way for them to tell whether
 *    the setting saved.
 *
 * 2. AUDIO THROWS ON A DEVICE WITHOUT IT. Web Audio is missing or blocked in
 *    more places than it looks: privacy modes, embedded webviews, and
 *    anything with autoplay locked down. Every one of those must be a quiet
 *    no-op, because this fires on a 60-second timer — an exception here is
 *    an exception every minute for the rest of the session.
 *
 * 3. localStorage THROWS. Reading it in a blocked-cookies context raises
 *    rather than returning null, and this module is imported at boot.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  isNotificationSoundMuted,
  playNotificationSound,
  setNotificationSoundMuted,
} from '../notificationSound'

const started: number[] = []

/** Minimal Web Audio double — records what would have been played. */
class FakeAudioContext {
  currentTime = 0
  destination = {}
  resume = vi.fn(() => Promise.resolve())

  createOscillator() {
    return {
      type: '',
      frequency: { value: 0 },
      connect: vi.fn(),
      start: (at: number) => started.push(at),
      stop: vi.fn(),
    }
  }

  createGain() {
    return {
      gain: {
        setValueAtTime: vi.fn(),
        exponentialRampToValueAtTime: vi.fn(),
      },
      connect: vi.fn(),
    }
  }
}

describe('notification sound', () => {
  beforeEach(() => {
    started.length = 0
    window.localStorage.clear()
    ;(window as unknown as { AudioContext: unknown }).AudioContext = FakeAudioContext
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('plays a two-note chime', () => {
    playNotificationSound()

    // Two tones, the second offset after the first — a chime, not a beep.
    // Destructured rather than indexed: under noUncheckedIndexedAccess
    // `started[0]` is `number | undefined`, and the length assertion above
    // has already established there are two.
    const [first, second] = started
    expect(started).toHaveLength(2)
    expect(second ?? 0).toBeGreaterThan(first ?? 0)
  })

  it('plays nothing when muted', () => {
    setNotificationSoundMuted(true)

    playNotificationSound()

    expect(started).toHaveLength(0)
  })

  it('remembers the mute across reads', () => {
    setNotificationSoundMuted(true)
    expect(isNotificationSoundMuted()).toBe(true)

    setNotificationSoundMuted(false)
    expect(isNotificationSoundMuted()).toBe(false)
  })

  it('is silent, not fatal, on a device with no Web Audio', () => {
    // Embedded webviews and locked-down privacy modes. This fires on a
    // timer — throwing here would throw every minute forever.
    ;(window as unknown as { AudioContext: unknown }).AudioContext = undefined

    expect(() => playNotificationSound()).not.toThrow()
  })

  it('survives localStorage throwing outright', () => {
    // Blocked-cookies contexts raise on access rather than returning null.
    const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })

    expect(() => isNotificationSoundMuted()).not.toThrow()
    expect(isNotificationSoundMuted()).toBe(false)
    expect(() => playNotificationSound()).not.toThrow()

    spy.mockRestore()
  })

  it('survives a write to localStorage throwing', () => {
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })

    expect(() => setNotificationSoundMuted(true)).not.toThrow()

    spy.mockRestore()
  })
})
