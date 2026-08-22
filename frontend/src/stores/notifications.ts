import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'

/**
 * In-app notification shape (TASK-053). Mirrors NotificationResource on
 * the backend — `type` is the machine key, `type_label` the human copy,
 * `link` an internal SPA path (e.g. '/clients/5', '/academy') or null.
 * Only the transport lives here; no business logic (CLAUDE.md Section 7).
 */
export interface AppNotification {
  id: number
  type: string
  type_label: string
  title: string
  body: string | null
  link: string | null
  data: Record<string, unknown> | null
  is_read: boolean
  read_at: string | null
  created_at: string
}

export const useNotificationsStore = defineStore('notifications', () => {
  const items = ref<AppNotification[]>([])
  const unreadCount = ref(0)
  const loading = ref(false)

  /** GET /notifications — latest first, max 50 (server-scoped to this agent). */
  async function fetchList(): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<{ data: AppNotification[] }>('/notifications')
      items.value = res.data
    } finally {
      loading.value = false
    }
  }

  /**
   * How many notifications have arrived that this session has never seen.
   *
   * Distinct from `unreadCount`, which counts UNREAD — a different thing.
   * Reading a notification elsewhere (another tab, the phone) lowers the
   * unread count, and that must not be mistaken for "nothing new". This is
   * the number the bell watches to decide whether to chime.
   */
  const arrivals = ref(0)
  let lastSeenCount = -1

  async function fetchUnreadCount(): Promise<void> {
    const res = await api.get<{ data: { unread_count: number } }>('/notifications/unread-count')
    const next = res.data.unread_count

    // The FIRST reading establishes a baseline and never counts as an
    // arrival: otherwise every page load with 3 unread items would chime as
    // though 3 things had just happened.
    if (lastSeenCount >= 0 && next > lastSeenCount) {
      arrivals.value += next - lastSeenCount
    }

    lastSeenCount = next
    unreadCount.value = next
  }

  /**
   * Poll for new notifications.
   *
   * ── WHY POLLING AND NOT A WEBSOCKET ──
   *
   * Until 2026-08-22 the badge was fetched exactly once, on mount. It could
   * sit at 0 for an entire working day while notifications piled up on the
   * server, and there was no moment at which a sound could have played
   * because nothing ever noticed a change. Adding a chime without this would
   * have produced a chime that only ever fires on page refresh.
   *
   * A websocket (Reverb/Pusher) is the better answer eventually — it is also
   * a broker, a daemon, auth on the socket, and a reconnect story. One HTTP
   * GET returning a single integer, once a minute, costs about a kilobyte an
   * hour per open tab and needs no new infrastructure at all. That is the
   * right trade for a notification badge; it would be the wrong trade for a
   * chat.
   *
   * Stopped when the tab is hidden and refreshed immediately when it comes
   * back: a phone in a pocket must not poll all night, and someone returning
   * to a tab wants the current number, not one from ten minutes of sleep.
   */
  const DEFAULT_INTERVAL_MS = 60_000
  let timer: ReturnType<typeof setInterval> | null = null
  let onVisibilityChange: (() => void) | null = null

  function startPolling(intervalMs: number = DEFAULT_INTERVAL_MS): void {
    stopPolling()

    const tick = () => {
      // A failed poll is not worth a toast — the next one is 60 seconds
      // away, and a flaky connection would otherwise produce a stream of
      // error messages about a badge.
      void fetchUnreadCount().catch(() => {})
    }

    timer = setInterval(() => {
      if (document.visibilityState === 'visible') tick()
    }, intervalMs)

    onVisibilityChange = () => {
      if (document.visibilityState === 'visible') tick()
    }
    document.addEventListener('visibilitychange', onVisibilityChange)

    tick()
  }

  function stopPolling(): void {
    if (timer !== null) {
      clearInterval(timer)
      timer = null
    }
    if (onVisibilityChange !== null) {
      document.removeEventListener('visibilitychange', onVisibilityChange)
      onVisibilityChange = null
    }
  }

  /**
   * Forget the baseline — called on logout, so the next user's first reading
   * establishes their own and does not chime for notifications inherited
   * from the previous session's count.
   */
  function resetArrivals(): void {
    arrivals.value = 0
    lastSeenCount = -1
  }

  async function markRead(id: number): Promise<void> {
    await api.post<{ data: AppNotification }>(`/notifications/${id}/read`)
    const item = items.value.find((n) => n.id === id)
    if (item && !item.is_read) {
      item.is_read = true
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    }
  }

  /** POST /notifications/read-all returns 204 No Content — nothing to parse. */
  async function markAllRead(): Promise<void> {
    await api.post('/notifications/read-all')
    items.value.forEach((n) => {
      n.is_read = true
    })
    unreadCount.value = 0
  }

  return {
    items,
    unreadCount,
    arrivals,
    loading,
    fetchList,
    fetchUnreadCount,
    markRead,
    markAllRead,
    startPolling,
    stopPolling,
    resetArrivals,
  }
})
