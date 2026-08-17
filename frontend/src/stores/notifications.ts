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

  async function fetchUnreadCount(): Promise<void> {
    const res = await api.get<{ data: { unread_count: number } }>('/notifications/unread-count')
    unreadCount.value = res.data.unread_count
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

  return { items, unreadCount, loading, fetchList, fetchUnreadCount, markRead, markAllRead }
})
