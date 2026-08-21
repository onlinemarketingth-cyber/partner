/**
 * portalChoice — remembers that a Super Admin chose to stay in this app.
 *
 * ── WHY THIS EXISTS ──
 *
 * TASK-218 sent every Super Admin straight out of the Agent Portal, because
 * the agent dashboard rendered from an admin's identity (zero XP, no team,
 * no orders) reads as a broken app rather than as a wrong door. That was the
 * right diagnosis and the wrong remedy: it also removed the legitimate
 * reason to be here — looking at what agents actually see.
 *
 * The notice screen now ASKS instead of deciding, and this is where the
 * answer lives so the route guard stops bouncing them on the very next
 * navigation.
 *
 * ── sessionStorage, DELIBERATELY, NOT localStorage ──
 *
 * The choice has to survive a refresh — being thrown back to the chooser
 * after pressing F5 would be worse than the original problem. It must NOT
 * survive the browser session, because "which app did I mean today" is a
 * fresh question each time, and a permanent answer silently removes a choice
 * the human just asked for. Also cleared on logout, so the next person to
 * sign in on this machine is asked in their own right.
 *
 * ── FAILS SOFT, LIKE safeStorage ──
 *
 * Same reasoning as utils/safeStorage.js, and same failure modes: Safari
 * private mode has thrown on access, a sandboxed iframe throws SecurityError
 * on the property read itself, and a user can switch site data off. This is
 * read from a ROUTE GUARD, which runs before every single navigation — a
 * throw here would white-screen the whole app, not just lose a preference.
 * When storage is unusable the answer is "not chosen", i.e. the visitor sees
 * the chooser again. Mildly repetitive; never broken.
 *
 * A separate file rather than an addition to safeStorage.js because that one
 * is a byte-identical copy shared with frontend-admin (ADR-003) and is about
 * localStorage; this is neither.
 */

const KEY = 'sv_stay_in_agent_portal'

/** The session store, or null when it cannot be used. */
function session(): Storage | null {
  try {
    const s = typeof window !== 'undefined' ? window.sessionStorage : null

    // Both methods checked, not just the one about to be called: a store
    // that can read but not write is not one to treat as present.
    return s && typeof s.getItem === 'function' && typeof s.setItem === 'function' ? s : null
  } catch {
    return null
  }
}

/** The Super Admin chose to carry on into the Agent Portal. */
export function rememberStayInAgentPortal(): void {
  try {
    session()?.setItem(KEY, '1')
  } catch {
    // Dropped on purpose — they will simply be asked again.
  }
}

/** Has that choice been made in this browser session? */
export function hasChosenToStayInAgentPortal(): boolean {
  try {
    return session()?.getItem(KEY) === '1'
  } catch {
    return false
  }
}

/** Clear it — called on logout so the next sign-in asks again. */
export function forgetPortalChoice(): void {
  try {
    session()?.removeItem(KEY)
  } catch {
    // Nothing to do; a stale flag only means one skipped prompt.
  }
}
