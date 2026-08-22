import type { RouteLocationRaw } from 'vue-router'
import type { AppNotification } from '@/stores/notifications'

/**
 * Where a notification actually goes when you tap it.
 *
 * ── THE BUG THIS EXISTS TO KILL (human-reported 2026-08-22) ──
 *
 * "คลิ๊ก Noti แล้วไม่ไปไหน". Every item in the reported screenshot was an
 * `announcement`, and AnnouncementService writes those with `link = '/news'`.
 * There has never been a `/news` route in this SPA, so both call sites
 * carried the same private patch:
 *
 *     link === '/news' ? '/' : link
 *
 * — i.e. "send them to the home hub, the news lives there". That was true
 * when it was written. TASK-075 then added a real `/announcements` page and
 * did not know about this mapping, so it kept aiming at home.
 *
 * And the bell lives in the top bar, which on this app is most often looked
 * at FROM home. `router.push('/')` while already on `/` resolves to the same
 * route and does nothing at all: the dropdown closed, the page did not move,
 * and the notification looked broken. Not a dead link — a link pointing at
 * the page you are standing on.
 *
 * ── WHY IT IS A MODULE AND NOT A FUNCTION IN EACH VIEW ──
 *
 * NotificationBell.vue and NotificationsView.vue both had their own byte-
 * identical copy of `resolveLink`. Two copies of a routing table drift the
 * moment a route is added — which is exactly what happened. One resolver,
 * two callers.
 *
 * ── THE NULL CASE IS A DESTINATION TOO ──
 *
 * Returning `null` means "this notification has nowhere to go" (the
 * account-status ones carry the whole story in their body). Callers must
 * treat that as a real answer and NOT close the panel / not navigate,
 * rather than pushing something arbitrary. A tap that marks the item read
 * in place is a visible response; a panel that vanishes is not.
 */
export function resolveNotificationLink(item: AppNotification): RouteLocationRaw | null {
  // An announcement's own id rides in `data`, so we can open the announcement
  // itself instead of dropping the reader on a list to hunt for it.
  if (item.type === 'announcement') {
    const id = item.data?.announcement_id
    return typeof id === 'number' || typeof id === 'string'
      ? { path: '/announcements', query: { a: String(id) } }
      : { path: '/announcements' }
  }

  // Legacy rows written before this fix still say '/news' with no id.
  if (item.link === '/news') return { path: '/announcements' }

  // Anything else must be an in-app path. External or absent → no destination.
  // (Guards against an absolute URL ever reaching router.push, which would
  // resolve it as a relative path and land nowhere useful.)
  if (!item.link || !item.link.startsWith('/')) return null

  return item.link
}
