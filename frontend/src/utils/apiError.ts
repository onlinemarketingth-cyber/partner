import { ApiError } from '@/api/client'

/**
 * TASK-079 Phase 2 (2026-08-03, UX audit) — ONE place that turns any
 * thrown error into a sentence a Thai salesperson can act on.
 *
 * Before this, ~30 call sites across the views hand-built their own
 * message, and almost all of them leaked the raw HTTP status straight
 * into the UI, e.g. `โหลดข้อมูลไม่สำเร็จ (500)`. A number in parentheses
 * means nothing to an agent in the field and — worse — gives them nothing
 * to do about it. This maps each status to plain Thai that says what
 * happened AND what to do next.
 *
 * Laravel's own `message` / `errors` payload is preferred when present
 * (it is already human-written and field-specific, e.g. a 422 validation
 * message), and only falls back to the status map when it isn't.
 *
 * Not a business rule — pure presentation (CLAUDE.md §7: no business
 * logic in the frontend). Nothing here decides anything, it only
 * re-words failures that the backend already decided.
 */

/** Shape Laravel returns on validation / abort() errors. */
interface LaravelErrorBody {
  message?: string
  errors?: Record<string, string[]>
}

function isLaravelErrorBody(body: unknown): body is LaravelErrorBody {
  return typeof body === 'object' && body !== null
}

/**
 * Status → actionable Thai copy. Deliberately omits the status number:
 * the technical detail belongs in the console (see below), not in front
 * of a salesperson.
 */
function messageForStatus(status: number, fallback: string): string {
  switch (status) {
    case 0:
      return 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบสัญญาณอินเทอร์เน็ตแล้วลองใหม่'
    case 401:
      return 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่อีกครั้ง'
    case 403:
      return 'คุณไม่มีสิทธิ์เข้าถึงส่วนนี้ หากคิดว่าผิดพลาด กรุณาติดต่อผู้ดูแลระบบ'
    case 404:
      return 'ไม่พบข้อมูลที่ต้องการ อาจถูกลบไปแล้ว'
    case 419:
      return 'เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่'
    case 422:
      return 'ข้อมูลที่กรอกไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง'
    case 429:
      return 'ทำรายการถี่เกินไป กรุณารอสักครู่แล้วลองใหม่'
    case 500:
    case 502:
    case 503:
    case 504:
      return 'ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง หากยังไม่ได้กรุณาแจ้งผู้ดูแลระบบ'
    default:
      return fallback
  }
}

/**
 * TASK-079 Phase 4 (2026-08-03, UX audit) — an aborted request is NOT a
 * failure and must never reach the agent.
 *
 * Phase 4 added AbortController to the heavy views (see api/client.ts):
 * leaving a screen mid-load now cancels its in-flight requests, and fetch
 * rejects each one with a DOMException named 'AbortError'. That is a
 * non-ApiError, so without this check it fell straight into the
 * `messageForStatus(0)` branch below and told the agent their internet
 * was down — for something the app itself deliberately cancelled.
 *
 * Every caller must check this FIRST and return before reporting, since a
 * silenced message still can't stop a toast that was already queued.
 */
export function isAbortError(e: unknown): boolean {
  return e instanceof DOMException ? e.name === 'AbortError' : e instanceof Error && e.name === 'AbortError'
}

/**
 * @param e        the caught error (any type — this never throws)
 * @param fallback context-specific default, e.g. 'โหลดรายชื่อลูกค้าไม่สำเร็จ'
 */
export function apiErrorMessage(e: unknown, fallback = 'ทำรายการไม่สำเร็จ กรุณาลองใหม่'): string {
  // Defence in depth behind each caller's own isAbortError() guard: an
  // empty string renders nothing through the `v-if="errorMessage"`
  // banners every view already has.
  if (isAbortError(e)) return ''

  if (!(e instanceof ApiError)) {
    // Network failure / thrown non-ApiError — fetch rejects before any
    // status exists, which is exactly the offline case field agents hit.
    return messageForStatus(0, fallback)
  }

  // Keep the real detail reachable for debugging without showing it to
  // the user (the audit found status codes rendered on-screen instead).
  if (import.meta.env.DEV) console.warn(`[api ${e.status}]`, e.body)

  if (isLaravelErrorBody(e.body)) {
    // A 422's first field error is the most specific, most useful string
    // available — prefer it over both `message` and the status map.
    if (e.status === 422 && e.body.errors) {
      const first = Object.values(e.body.errors)[0]?.[0]
      if (first) return first
    }
    // Laravel's `message` is human-written for deliberate abort()s.
    // Skip its generic framework defaults, which are no better than ours.
    const msg = e.body.message
    if (msg && msg !== 'Server Error' && msg !== 'Unauthenticated.' && !/^HTTP \d+/.test(msg)) {
      return msg
    }
  }

  return messageForStatus(e.status, fallback)
}
