/**
 * TASK-075/076 (2026-08-02, human-confirmed/human-requested) — the
 * announcement auto-popup modal must not re-show an announcement
 * forever, but the human later asked for it to auto-pop "อย่างน้อย 4
 * ครั้ง" (at least 4 times) before it stops, with the exact count
 * admin-editable (GET /announcement-settings, repeat_count — BR-7).
 *
 * So this tracks a per-announcement VIEW COUNT, not a boolean seen-flag.
 * The caller (HomeView/AnnouncementsListView) compares each
 * announcement's stored count against the admin-configured
 * `repeat_count` limit and only treats it as "exhausted" once the count
 * has reached that limit.
 *
 * Human explicitly chose browser localStorage over a backend-persisted
 * view count (simpler, no migration; resets if the agent switches
 * device/clears browser data — an accepted trade-off from the original
 * TASK-075 AskUserQuestion round; TASK-076 only changed "how many times"
 * the popup allowed, not where the state lives).
 *
 * Scoped per-user-id (not global) so a shared/kiosk device doesn't let
 * one agent's view count hide the modal for a different agent who logs
 * in next — this is client-only, non-sensitive UI state, not a security
 * boundary.
 */
const STORAGE_KEY_PREFIX = 'sv_agent_announcement_view_counts_'

// Cap stored history so localStorage doesn't grow unbounded over a long
// agent tenure — 200 distinct announcement ids is far more than any
// realistic volume needs to stay useful.
const MAX_STORED_IDS = 200

function storageKey(userId: number | string | undefined): string {
  return `${STORAGE_KEY_PREFIX}${userId ?? 'anon'}`
}

function readCounts(userId: number | string | undefined): Record<number, number> {
  try {
    const raw = localStorage.getItem(storageKey(userId))
    if (!raw) return {}
    const parsed = JSON.parse(raw) as unknown
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {}
    const out: Record<number, number> = {}
    for (const [k, v] of Object.entries(parsed as Record<string, unknown>)) {
      const id = Number(k)
      if (Number.isInteger(id) && typeof v === 'number' && Number.isFinite(v)) out[id] = v
    }
    return out
  } catch {
    // Private browsing / storage disabled / corrupt JSON — fail open
    // (treat as "nothing viewed yet"), never block the page from rendering.
    return {}
  }
}

function writeCounts(userId: number | string | undefined, counts: Record<number, number>): void {
  try {
    // Trim to the most recently touched MAX_STORED_IDS entries if it
    // ever grows past the cap (insertion order is preserved by
    // Object.entries for plain objects built incrementally like this).
    const entries = Object.entries(counts)
    const trimmed = entries.length > MAX_STORED_IDS ? entries.slice(entries.length - MAX_STORED_IDS) : entries
    localStorage.setItem(storageKey(userId), JSON.stringify(Object.fromEntries(trimmed)))
  } catch {
    // Non-fatal — worst case the modal re-shows more than intended.
  }
}

export function getAnnouncementViewCount(userId: number | string | undefined, announcementId: number): number {
  return readCounts(userId)[announcementId] ?? 0
}

/**
 * Records one more view of `announcementId` and returns the new count.
 */
export function recordAnnouncementView(userId: number | string | undefined, announcementId: number): number {
  const counts = readCounts(userId)
  const next = (counts[announcementId] ?? 0) + 1
  counts[announcementId] = next
  writeCounts(userId, counts)
  return next
}
