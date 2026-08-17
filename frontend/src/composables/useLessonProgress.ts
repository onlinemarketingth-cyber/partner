/**
 * useLessonProgress — TASK-147 / ADR-028 §2.3.
 *
 * The learner's client reports RAW POSITIONS to
 * `PUT /module-lessons/{lesson}/progress`. It never reports a
 * percentage, a `max_*` or a "completed" flag: ADR-028 §3 explicitly
 * rejected trusting a client-reported completion, so the server clamps
 * every number to what the media can actually contain and decides on
 * its own what it means (ModuleLessonProgressService).
 *
 * The endpoint answers **`{ "completed": bool }` and nothing else**
 * (TASK-165 §3.4). The learner is still not told how far they got —
 * ADR-028 §4 is intact, and there is no percentage, threshold or
 * position in the payload to display. The one bit that IS returned is
 * whether the lesson is now complete, because completion can now be
 * recorded BY this very request (TASK-165 §3.2: where the server can
 * measure, it records) and the row has to be able to flip without
 * polling. `onCompleted` is how that bit reaches the screen.
 *
 * ── THROTTLE ────────────────────────────────────────────────────────
 * `REPORT_INTERVAL_MS = 15_000`. Chosen against two ceilings:
 *
 *  - the route's own `throttle:60,1` (routes/api.php) — 60 requests per
 *    minute across ALL progress writes for this user;
 *  - `<video>` fires `timeupdate` roughly 4x a second, so an unthrottled
 *    reporter would be ~240 requests a minute from one lesson alone.
 *
 * 15s gives 4 writes/minute per open lesson, ~15 requests over a
 * 4-minute video, and bounds the worst case (a hard browser kill with
 * no unload event) to 15 seconds of lost position — comfortably inside
 * TASK-147's "resumes within a few seconds of where it was" once a
 * learner-readable resume endpoint exists (see the note in AcademyView).
 *
 * Leading edge fires immediately so opening a lesson records that it was
 * opened; everything after is trailing-edge, and `flush()` forces the
 * pending value out on pause / close / unmount.
 */
import { onUnmounted } from 'vue'
import { api } from '@/api/client'

export interface LessonProgressPayload {
  /** Video only — seconds into the clip. Prohibited by the API for any other content type. */
  last_position_seconds?: number
  /** PDF only — 1-based page currently reached. */
  last_page?: number
  /** PDF only — FALLBACK denominator; module_lessons.page_count is the real one. */
  total_pages?: number
}

export const REPORT_INTERVAL_MS = 15_000

/**
 * After this many consecutive failures we stop reporting for the rest of
 * the session. A learner whose session died (401) or whose lesson was
 * unpublished must not have their tab quietly retrying forever.
 */
const MAX_CONSECUTIVE_FAILURES = 3

export interface LessonProgressOptions {
  /**
   * TASK-165 §3.2/§3.5 — called when the SERVER reports that this ping
   * completed the lesson. Fires only on `completed: true`, so the caller
   * flips a row to done and never has to un-flip one.
   *
   * Nothing here decides completion: the client has no idea what the
   * threshold is and must not (ADR-028 §4). It is told.
   */
  onCompleted?: (lessonId: number) => void
}

export function useLessonProgress(options: LessonProgressOptions = {}) {
  let pendingLessonId: number | null = null
  let pending: LessonProgressPayload | null = null
  let lastSentAt = 0
  let timer: ReturnType<typeof setTimeout> | null = null
  let consecutiveFailures = 0
  let disabled = false

  function clearTimer() {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  async function send(lessonId: number, payload: LessonProgressPayload) {
    try {
      const result = await api.put<{ completed: boolean }>(`/module-lessons/${lessonId}/progress`, payload)
      consecutiveFailures = 0
      // TASK-165 §3.2 — the server may have recorded a completion off this
      // very ping. `completed` is the ONLY field in the response and the
      // only thing read from it.
      if (result?.completed) options.onCompleted?.(lessonId)
    } catch {
      // Deliberately silent: a dropped progress ping is not something to
      // interrupt a learner mid-video about, and the gate's own message
      // is the only place completion is ever discussed (ADR-028 §4).
      consecutiveFailures += 1
      if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) disabled = true
    }
  }

  /**
   * Returns the in-flight request so callers that MUST NOT race it can
   * await — markComplete() is the one that matters: the gate reads what
   * the server has recorded, so a completion POST that overtakes the
   * final position report would be rejected on stale data.
   */
  function flushNow(): Promise<void> {
    if (!pending || pendingLessonId === null) return Promise.resolve()
    const lessonId = pendingLessonId
    const payload = pending
    pending = null
    lastSentAt = Date.now()
    clearTimer()
    return send(lessonId, payload)
  }

  /**
   * Record a position. Merges into any value not yet sent, so a burst of
   * `timeupdate` events costs exactly one request per interval.
   *
   * Switching lesson flushes the previous one first — otherwise closing
   * a video and opening a PDF would discard the video's last position.
   */
  function report(lessonId: number, payload: LessonProgressPayload) {
    if (disabled) return

    if (pendingLessonId !== null && pendingLessonId !== lessonId) void flushNow()

    pendingLessonId = lessonId
    pending = { ...(pending ?? {}), ...payload }

    const elapsed = Date.now() - lastSentAt
    if (elapsed >= REPORT_INTERVAL_MS) {
      void flushNow()
      return
    }

    if (timer === null) {
      timer = setTimeout(() => void flushNow(), REPORT_INTERVAL_MS - elapsed)
    }
  }

  /** Force the pending value out — pause, close, unmount, mark-complete. */
  function flush(): Promise<void> {
    return flushNow()
  }

  onUnmounted(() => {
    void flushNow()
    clearTimer()
  })

  return { report, flush }
}
