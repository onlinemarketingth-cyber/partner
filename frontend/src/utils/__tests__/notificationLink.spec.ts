/**
 * Where a notification goes when you tap it.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * The bug it pins (human-reported 2026-08-22, "คลิ๊ก Noti แล้วไม่ไปไหน") had
 * no error, no console entry and no failing test. The mapping said
 * `'/news' → '/'`, which is a perfectly reasonable line of code; it only
 * became wrong on the day someone else added a real /announcements page and
 * never learnt this mapping existed. Nothing in the codebase connected the
 * two facts.
 *
 * These cases connect them. Point announcements back at the home hub and
 * `announcement notifications open the announcement` fails by name.
 */
import { describe, expect, it } from 'vitest'
import { resolveNotificationLink } from '../notificationLink'
import type { AppNotification } from '@/stores/notifications'

function notification(over: Partial<AppNotification> = {}): AppNotification {
  return {
    id: 1,
    type: 'announcement',
    type_label: 'ข่าวสาร',
    title: 'ประกาศทดสอบ',
    body: null,
    link: '/announcements',
    data: { announcement_id: 7 },
    is_read: false,
    read_at: null,
    created_at: '2026-08-22T03:00:00Z',
    ...over,
  }
}

describe('resolveNotificationLink', () => {
  it('opens the announcement itself, not just the list', () => {
    // data.announcement_id was already being written and read by nobody. A
    // company that publishes weekly leaves the reader guessing which headline
    // pinged them.
    expect(resolveNotificationLink(notification())).toEqual({
      path: '/announcements',
      query: { a: '7' },
    })
  })

  it('still lands on the announcements page when the id is missing', () => {
    expect(resolveNotificationLink(notification({ data: null }))).toEqual({
      path: '/announcements',
    })
  })

  it('sends legacy /news rows to the announcements page, never to home', () => {
    // THE REPORTED BUG. Rows written before the fix say '/news'; they cannot
    // be rewritten, and '/'  is where the bell is usually opened from, so
    // routing them home navigated to the page the reader was already on.
    const legacy = notification({ type: 'something_else', link: '/news', data: null })

    expect(resolveNotificationLink(legacy)).toEqual({ path: '/announcements' })
  })

  it('passes an ordinary in-app path straight through', () => {
    const followUp = notification({ type: 'follow_up_due', link: '/clients/5', data: null })

    expect(resolveNotificationLink(followUp)).toBe('/clients/5')
  })

  it('answers null when there is nowhere to go', () => {
    // Callers rely on this to keep the dropdown OPEN rather than closing it
    // on a tap that moves nothing.
    expect(resolveNotificationLink(notification({ type: 'approval_status', link: null }))).toBeNull()
  })

  it('refuses an absolute URL', () => {
    // router.push('https://…') resolves it as a relative path and lands
    // somewhere meaningless. Nothing writes one today; this is the guard for
    // the day a producer does.
    const external = notification({ type: 'approval_status', link: 'https://evil.example/x' })

    expect(resolveNotificationLink(external)).toBeNull()
  })
})
