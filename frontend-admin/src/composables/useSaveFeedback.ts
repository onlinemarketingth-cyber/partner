/**
 * ADR-052 — "it saved" is said ONCE, by ONE thing, and only when it is true.
 *
 * Owner, 2026-10-01: "รีเฟรชหน้า (F5) แล้วดูว่าค่ายังอยู่ ถ้ายังอยู่แปลว่า
 * บันทึกแล้วจริง ผมอึดอัดเรื่องนี้ของระบบเราพอสมควรแก้ทั้งหมดเลยได้ ว่าบันทึก
 * ส่วนไหนสำเร็จต้องมี Modal แจ้งการบันทึกสำเร็จ และคุณต้องตรวจว่าบันทึกสำเร็จ
 * แล้วไม่ต้องกด F5".
 *
 * What the audit found (216 writes in this app): 8 raised a dialog, 50 printed
 * a line somewhere on the page, and 158 said nothing at all — the form closed,
 * or a list quietly changed. Worse, about 40 kept showing what the admin had
 * TYPED rather than what the server stored, so a save that failed looked
 * exactly like one that worked. The rank-settings rail on the commission
 * screen went green from unsaved input, and a UAT run was blocked a day later
 * by settings that had never reached the database.
 *
 * ── THE RULE, IN ORDER ──
 *
 *   1. the write is sent;
 *   2. the server answers 2xx — anything else throws, the caller shows the
 *      error, and NO dialog appears;
 *   3. the screen takes its state from that answer (or from a re-read);
 *   4. only then does the dialog say it saved.
 *
 * `confirmSaved()` is that order as code. Use it for every write. Where a
 * function's shape makes wrapping awkward, call `notifySaved()` yourself —
 * but only at step 4, after the response has been applied or the reload
 * awaited, never before and never in a `finally`.
 *
 * A module singleton rather than a Pinia store: it carries no data that
 * belongs to anyone, and every view in this app would otherwise need an
 * active Pinia just to say "done" (see vitest.setup.ts for what that costs).
 * The single <SaveFeedbackHost> in App.vue renders it.
 */
import { reactive, readonly } from 'vue'

export const SAVED_TITLE = 'บันทึกสำเร็จ'

/**
 * Shown when the write landed but re-reading it failed. The save is real, so
 * it is still reported — but the admin is told the screen may be behind,
 * rather than being shown a "success" over values that may not be the stored
 * ones.
 */
export const SAVED_BUT_STALE_BODY = 'ระบบบันทึกแล้ว แต่โหลดข้อมูลล่าสุดกลับมาไม่สำเร็จ — ค่าบนหน้าจออาจยังไม่ใช่ค่าล่าสุด'

interface SaveFeedbackState {
  show: boolean
  title: string
  body: string
  /** Bumped on every notice, so a test (or a watcher) can tell two identical notices apart. */
  count: number
}

const state = reactive<SaveFeedbackState>({ show: false, title: SAVED_TITLE, body: '', count: 0 })

/** Read-only view for the host and for tests. */
export const saveFeedbackState = readonly(state)

/**
 * Raise the "saved" dialog. Call ONLY after the server confirmed the write
 * AND the screen shows the server's values (step 4 above).
 */
export function notifySaved(body = '', title: string = SAVED_TITLE): void {
  state.title = title
  state.body = body
  state.count += 1
  state.show = true
}

export function dismissSaved(): void {
  state.show = false
}

/** For specs: start every test with no dialog showing. */
export function resetSaveFeedback(): void {
  state.show = false
  state.title = SAVED_TITLE
  state.body = ''
  state.count = 0
}

export interface ConfirmSavedOptions<T> {
  /**
   * Step 3: put the server's answer on screen — assign the response, or
   * await a reload. Runs only after a 2xx.
   */
  apply?: (response: T) => unknown | Promise<unknown>
  /** Dialog body. A function receives the RESPONSE, so the text quotes what was stored, not what was typed. */
  message?: string | ((response: T) => string)
  title?: string
}

/**
 * Steps 1-4 in one call.
 *
 * Throws exactly when `write` throws, so the caller's existing catch keeps
 * showing the error — and no dialog is raised. A failure in `apply` does not
 * throw: the write has already happened, so the admin is told it saved and
 * that the screen may be behind (SAVED_BUT_STALE_BODY).
 */
export async function confirmSaved<T>(write: () => Promise<T>, options: ConfirmSavedOptions<T> = {}): Promise<T> {
  const response = await write()

  try {
    if (options.apply) await options.apply(response)
  } catch {
    notifySaved(SAVED_BUT_STALE_BODY, options.title)

    return response
  }

  const body = typeof options.message === 'function' ? options.message(response) : (options.message ?? '')
  notifySaved(body, options.title)

  return response
}

export function useSaveFeedback() {
  return { state: saveFeedbackState, notifySaved, dismissSaved, confirmSaved }
}
