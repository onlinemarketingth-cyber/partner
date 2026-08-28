import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError, ensureCsrfCookie, setToken } from '@/api/client'
import { forgetPortalChoice } from '@/utils/portalChoice'

// Matches App\Enums\UserRole (backend). Kept as a string union rather than
// re-declaring business rules on the frontend — role gates itself live in
// Laravel Policies (CLAUDE.md Section 5); this is only for UI display/nav.
export type UserRole = 'agent' | 'company_admin' | 'super_admin'

export interface UserBackground {
  type: 'gradient' | 'image' | null
  config: { color1: string; color2: string; angle: number } | null
  image_url: string | null
}

export interface AuthUser {
  id: number
  name: string
  first_name: string
  last_name: string
  email: string
  role: UserRole
  /**
   * TASK-112 / ADR-025 §1 — a FLAG an admin grants, never a fourth role:
   * `role` above stays 'agent' for a team leader. It is the ONLY thing that
   * authorises minting a recruit link or approving one's own recruits.
   *
   * Deliberately NOT the same question as "does this person have direct
   * reports" (which /me/team answers with `is_leader`). ADR-025 §2 keeps the
   * two apart on purpose: a leader who loses the flag keeps seeing the team
   * they still manage, and an agent who happens to have reports still cannot
   * recruit. MyTeamView reads THIS field — never `is_leader` — for the
   * "ชวนเข้าทีม" affordance.
   *
   * UI-only. Every gate is re-enforced server-side (AgentInviteLinkService,
   * UserPolicy::approveRegistration), so a tampered store buys nothing.
   */
  is_team_leader: boolean
  /**
   * 2026-08-22 — the agent's own off switch for notification email, so
   * ProfileSettingsView can render the toggle in its true position on load
   * rather than guessing. Optional on the type (not on the wire) so a stale
   * cached user from before this field existed does not fail to parse; the
   * view defaults it to `true`, matching the column default.
   */
  email_notifications_enabled?: boolean
  company: { id: number; name: string } | null
  avatar_url: string | null
  background: UserBackground
  // TASK-044 Phase A — bank payout details. On this /me-scoped shape the
  // backend returns the FULL unmasked bank_account_number (UserResource
  // ::forOwner() — see its docblock), unlike every other read site which
  // gets the last-4-masked value. Nullable: agent may not have filled
  // these in yet.
  bank_name: string | null
  bank_account_number: string | null
  bank_account_holder_name: string | null
  /**
   * 2026-08-27 — the identity document, now collected AFTER sign-up
   * (ProfileSettingsView) instead of on the registration form. Nullable
   * because every agent registered from this date on starts without one.
   * On this /me-scoped shape `national_id` is the FULL value, like
   * bank_account_number above (UserResource::forOwner()).
   */
  national_id: string | null
  id_document_type: 'thai_national_id' | 'passport' | null
  /**
   * Server's own answer to "can this agent be paid?"
   * (User::hasCompletePayoutDetails) — identity document AND all three bank
   * fields. Read it, never re-derive it here: the payout gate will use the
   * server's version, and a second implementation in the browser is how the
   * prompt and the gate start disagreeing.
   */
  payout_details_complete: boolean
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  // Distinguishes "haven't checked yet" from "checked, not logged in" so the
  // router guard/App shell can show a loading state instead of flashing the
  // login screen on refresh.
  const status = ref<'idle' | 'checking' | 'ready'>('idle')

  const isAuthenticated = computed(() => user.value !== null)

  /**
   * Bug fix: /me and /login return a Laravel single-resource JsonResource
   * (UserResource), which Laravel auto-wraps as `{ data: {...} }` unless
   * `JsonResource::withoutWrapping()` is called globally (it isn't, and
   * shouldn't be — every collection endpoint in this app relies on that
   * same wrapping for its own `{ data: T[] }` shape, unwrapped manually
   * at each call site, e.g. LeaderboardView's `leaderboardRes.data`).
   * This store was the one place that skipped that unwrap and assigned
   * the raw `{ data: {...} }` envelope directly to `user.value` — so
   * `auth.user.role`, `.id`, `.name`, `.company` were all silently
   * `undefined` everywhere in both frontends (login still "worked" since
   * `isAuthenticated` only checks `user.value !== null`, which stayed
   * true). Caught via live browser testing: Super Admin's
   * `auth.user?.role === 'super_admin'` check in LeaderboardView always
   * evaluated false, so the Super-Admin-skip fix never ran and the
   * request still fired straight into the 422 it was built to avoid.
   */
  /**
   * Bug fix (2026-08-03, human-reported: logged-in agents were bounced to
   * /login on every page load even with a perfectly valid session).
   *
   * Root cause — a boot race introduced by TASK-078's splash. Vue Router
   * kicks off its FIRST navigation inside `app.use(router)` (router's
   * install() calls push(location) immediately), not at app.mount(). So
   * the guard in router/index.ts starts running the moment the router is
   * installed — while main.ts is still awaiting its own fetchUser() for
   * the splash. Two concurrent fetchUser() calls then raced: the second
   * one reset `status` back to 'checking' and re-issued GET /me, so the
   * guard's copy could resolve against a store the other call had not
   * finished populating, see `!isAuthenticated`, and redirect to /login.
   * The user object landed a moment later — which is exactly what the
   * symptom looked like: store fully populated (status 'ready', real
   * user) while the URL sat on /login?redirect=/.
   *
   * Fix: make this idempotent. Concurrent callers share ONE in-flight
   * request instead of each starting their own, so "has the session been
   * checked yet" has a single answer no matter who asks first. The guard
   * awaits the same promise main.ts is awaiting.
   */
  let inFlight: Promise<void> | null = null

  /** Fetch the current session's user, if any. Safe to call concurrently. */
  function fetchUser(): Promise<void> {
    if (inFlight) return inFlight

    status.value = 'checking'
    inFlight = (async () => {
      try {
        const res = await api.get<{ data: AuthUser } | ''>('/me')
        user.value = res === '' ? null : res.data
      } catch {
        user.value = null
      } finally {
        status.value = 'ready'
        inFlight = null
      }
    })()

    return inFlight
  }

  /**
   * Bug fix (2026-08-02, human-reported: "จำ login แบบ Facebook ทำอย่างไร")
   * — LoginView's "จดจำฉัน" checkbox existed but was never actually sent
   * to the backend, so it silently did nothing; every session behaved
   * identically regardless of the checkbox (120-min idle session cookie
   * — config/session.php SESSION_LIFETIME, survives browser close either
   * way since SESSION_EXPIRE_ON_CLOSE=false). The backend's LoginRequest
   * already calls `Auth::attempt($credentials, $this->boolean('remember'))`
   * (Laravel's built-in remember-me — a long-lived `remember_token` cookie,
   * same mechanism Facebook/most sites use to skip login on return visits
   * even after the short session expires) — it just never received a
   * truthy value. This wires the checkbox through.
   *
   * @throws {ApiError} on invalid credentials (422) or rate limiting (429) —
   * callers (LoginView) are responsible for mapping this to UI copy.
   */
  async function login(email: string, password: string, remember = false): Promise<void> {
    await ensureCsrfCookie()
    const res = await api.post<{ data: AuthUser; token?: string }>('/login', {
      email,
      password,
      remember,
    })

    /*
     * 2026-08-27 — store the Bearer token the backend mints for this app
     * (AuthController::login, gated on the X-Auth-Mode header the client
     * sends). Set BEFORE user.value so no reactive watcher can fire a
     * request in the window between "logged in" and "has a token".
     *
     * `token` is optional on the type, not on the wire: a build talking
     * to a backend deployed before this change would receive no token,
     * and calling setToken(undefined) would be worse than leaving the
     * previous value alone — so a falsy token is simply not stored, and
     * the first authenticated call 401s honestly instead of half-working.
     */
    if (res.token) setToken(res.token)

    user.value = res.data
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/logout')
    } finally {
      // Cleared in `finally`, never only on success: if the revoke call
      // fails (offline, server down) the person still pressed logout, and
      // leaving a usable token in localStorage on a machine somebody is
      // walking away from is the worst possible way to honour that. The
      // server-side revoke is what makes it unusable elsewhere; this is
      // what makes it gone from here.
      setToken(null)
      user.value = null
      // A Super Admin's "stay in the Agent Portal" choice belongs to the
      // person, not to the browser. Cleared here so the next sign-in on
      // this machine is asked in their own right — including the same
      // person coming back tomorrow, for whom it is a fresh question.
      forgetPortalChoice()
    }
  }

  /** Profile endpoints (avatar/background) return the fresh UserResource
   * directly — this lets ProfileSettingsView sync the store with that
   * response instead of poking `user.value` from outside the store. */
  function setUser(updated: AuthUser): void {
    user.value = updated
  }

  return { user, status, isAuthenticated, fetchUser, login, logout, setUser }
})

export { ApiError }
