/**
 * safeStorage — localStorage that can never take the app down.
 *
 * WHY THIS EXISTS. Several composables read a remembered preference at MODULE
 * scope, i.e. at import time. Anything that throws there takes down every
 * component that transitively imports the module, however far from the
 * preference it is: on 2026-08-12 a `window.localStorage` whose `getItem` was
 * not a function turned five test suites red through
 * ConfirmDialog → PipelineBoard, and would have white-screened the same paths
 * in a browser where storage is unavailable.
 *
 * `window.localStorage?.getItem(...)` — the idiom this replaces — guards the
 * wrong thing. The optional chain covers a MISSING localStorage, not one that
 * exists but cannot be used, which is the case that actually happens:
 *
 *   - Safari private mode has historically thrown on access
 *   - a sandboxed iframe throws SecurityError on the property read itself
 *   - a user can switch site data off
 *   - setItem throws QuotaExceededError when full
 *   - a test environment can supply a partial object
 *
 * A remembered preference is never worth a white screen, so every function
 * here fails soft: reads return null and callers fall back to their default,
 * writes are dropped and the choice simply is not remembered next visit.
 *
 * ADR-003: `frontend-admin/src/utils/safeStorage.js` is a deliberate copy —
 * the two apps share no package. Keep the two in sync.
 */

/**
 * The Storage object, or null when it cannot be used.
 *
 * Both methods are checked, not just the one the caller is about to use: a
 * store that can read but not write is not a store this app should treat as
 * present, and checking here keeps every call site down to one question.
 */
function store() {
  try {
    const s = typeof window !== 'undefined' ? window.localStorage : null

    return s && typeof s.getItem === 'function' && typeof s.setItem === 'function' ? s : null
  } catch {
    // Reading the PROPERTY can throw (sandboxed iframe). Hence the try.
    return null
  }
}

/** @returns {string|null} the stored value, or null if absent or unreadable. */
export function readStored(key) {
  try {
    return store()?.getItem(key) ?? null
  } catch {
    return null
  }
}

/** Store a value; silently does nothing when storage is unavailable or full. */
export function writeStored(key, value) {
  try {
    store()?.setItem(key, value)
  } catch {
    // Quota exceeded or storage disabled — this session still works, the
    // choice just will not survive a reload.
  }
}
