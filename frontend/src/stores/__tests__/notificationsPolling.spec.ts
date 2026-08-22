/**
 * The polling that makes a notification sound possible at all.
 *
 * ── WHY THIS IS NOT OPTIONAL ──
 *
 * Until 2026-08-22 the unread badge was fetched exactly once, on mount. It
 * could sit at 0 for a whole working day while notifications piled up. A
 * chime added on top of that would have been a chime that only ever fires on
 * page refresh — the feature would look built and be useless.
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. THE FIRST READING CHIMES. Baseline handling is one comparison, and
 *     getting it wrong means every page load with unread items announces
 *     them as though they had just arrived. Nobody files that as a bug; they
 *     just mute the sound forever, and the feature is dead.
 *
 *  2. THE CHIME FOLLOWS THE UNREAD COUNT. Reading a notification on another
 *     device LOWERS the count. If arrivals were derived from the count
 *     falling and rising, a genuinely new notification that merely restores
 *     the previous number would be silent.
 *
 *  3. A PHONE IN A POCKET POLLS ALL NIGHT. The visibility guard is one `if`.
 *     Losing it costs battery on every backgrounded tab, and nothing on
 *     screen ever shows that it happened.
 *
 *  4. THE NEXT USER INHERITS THE BASELINE. After a logout the counter must
 *     be forgotten, or the next person to sign in on this machine is chimed
 *     at for somebody else's notifications.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: vi.fn() },
  ApiError: class extends Error {},
}))

import { useNotificationsStore } from '../notifications'

function unread(count: number) {
  return { data: { unread_count: count } }
}

/** jsdom reports 'visible'; this overrides it for the backgrounded case. */
function setVisibility(state: DocumentVisibilityState) {
  Object.defineProperty(document, 'visibilityState', { value: state, configurable: true })
}

describe('notifications store — polling and arrivals', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    get.mockReset()
    setVisibility('visible')
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('does not treat the first reading as an arrival', async () => {
    // Otherwise a page loaded with 3 unread items chimes three times for
    // notifications that may be days old.
    get.mockResolvedValue(unread(3))
    const store = useNotificationsStore()

    await store.fetchUnreadCount()

    expect(store.unreadCount).toBe(3)
    expect(store.arrivals).toBe(0)
  })

  it('counts only the increase as arrivals', async () => {
    get.mockResolvedValueOnce(unread(1)).mockResolvedValueOnce(unread(4))
    const store = useNotificationsStore()

    await store.fetchUnreadCount()
    await store.fetchUnreadCount()

    expect(store.arrivals).toBe(3)
  })

  it('does not chime when the count merely falls', async () => {
    // Reading something on another device lowers the count. That is not an
    // arrival and must not become a negative one either.
    get.mockResolvedValueOnce(unread(5)).mockResolvedValueOnce(unread(2))
    const store = useNotificationsStore()

    await store.fetchUnreadCount()
    await store.fetchUnreadCount()

    expect(store.arrivals).toBe(0)
    expect(store.unreadCount).toBe(2)
  })

  it('chimes for a new notification that only restores the previous number', async () => {
    // THE CASE A COUNT-WATCHER GETS WRONG: 5 → 4 (read elsewhere) → 5 (a new
    // one). A watcher on unreadCount sees the same number it started with.
    get
      .mockResolvedValueOnce(unread(5))
      .mockResolvedValueOnce(unread(4))
      .mockResolvedValueOnce(unread(5))
    const store = useNotificationsStore()

    await store.fetchUnreadCount()
    await store.fetchUnreadCount()
    await store.fetchUnreadCount()

    expect(store.arrivals).toBe(1)
  })

  it('polls on an interval', async () => {
    get.mockResolvedValue(unread(0))
    const store = useNotificationsStore()

    store.startPolling(1000)
    expect(get).toHaveBeenCalledTimes(1) // immediate first read

    await vi.advanceTimersByTimeAsync(3000)
    expect(get).toHaveBeenCalledTimes(4)

    store.stopPolling()
  })

  it('stops polling while the tab is hidden', async () => {
    get.mockResolvedValue(unread(0))
    const store = useNotificationsStore()

    store.startPolling(1000)
    get.mockClear()

    setVisibility('hidden')
    await vi.advanceTimersByTimeAsync(5000)

    expect(get).not.toHaveBeenCalled()
    store.stopPolling()
  })

  it('refreshes immediately when the tab comes back', async () => {
    get.mockResolvedValue(unread(0))
    const store = useNotificationsStore()
    store.startPolling(60_000)
    get.mockClear()

    setVisibility('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    await vi.advanceTimersByTimeAsync(0)

    // Someone returning to a tab wants the current number, not one from ten
    // minutes of sleep.
    expect(get).toHaveBeenCalledTimes(1)
    store.stopPolling()
  })

  it('stops cleanly and detaches its visibility listener', async () => {
    get.mockResolvedValue(unread(0))
    const store = useNotificationsStore()

    store.startPolling(1000)
    store.stopPolling()
    get.mockClear()

    await vi.advanceTimersByTimeAsync(5000)
    document.dispatchEvent(new Event('visibilitychange'))
    await vi.advanceTimersByTimeAsync(0)

    expect(get).not.toHaveBeenCalled()
  })

  it('starting twice does not leave two timers running', async () => {
    // App.vue restarts polling whenever the auth user changes. A start that
    // does not clear the previous timer doubles the request rate every time.
    get.mockResolvedValue(unread(0))
    const store = useNotificationsStore()

    store.startPolling(1000)
    store.startPolling(1000)
    get.mockClear()

    await vi.advanceTimersByTimeAsync(1000)

    expect(get).toHaveBeenCalledTimes(1)
    store.stopPolling()
  })

  it('forgets the baseline on reset so the next user is not chimed at', async () => {
    get.mockResolvedValueOnce(unread(9)).mockResolvedValueOnce(unread(9))
    const store = useNotificationsStore()

    await store.fetchUnreadCount()
    store.resetArrivals()
    await store.fetchUnreadCount()

    // A fresh baseline, not "9 new notifications for whoever just logged in".
    expect(store.arrivals).toBe(0)
  })

  it('survives a failed poll without throwing', async () => {
    get.mockRejectedValue(new Error('offline'))
    const store = useNotificationsStore()

    store.startPolling(1000)
    await vi.advanceTimersByTimeAsync(2000)

    // A flaky connection must not produce an unhandled rejection every
    // minute for the rest of the session.
    expect(store.unreadCount).toBe(0)
    store.stopPolling()
  })
})
