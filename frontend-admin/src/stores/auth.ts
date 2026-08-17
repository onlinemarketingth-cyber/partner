import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError, ensureCsrfCookie } from '@/api/client'

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
  company: { id: number; name: string } | null
  avatar_url: string | null
  background: UserBackground
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
   * at each call site). This store was the one place that skipped that
   * unwrap and assigned the raw `{ data: {...} }` envelope directly to
   * `user.value` — so `auth.user.role`, `.id`, `.name`, `.company` were
   * all silently `undefined` everywhere in both frontends, including
   * every role-gated UI check in this Admin app (e.g. "Manage companies"
   * card, per-role dashboard cards) — login still "worked" since
   * `isAuthenticated` only checks `user.value !== null`, which stayed
   * true throughout. See frontend/src/stores/auth.ts for the matching
   * fix (each app keeps its own copy of this file — ADR-003).
   */
  /** Fetch the current session's user, if any. Call once on app boot. */
  async function fetchUser(): Promise<void> {
    status.value = 'checking'
    try {
      const res = await api.get<{ data: AuthUser } | ''>('/me')
      user.value = res === '' ? null : res.data
    } catch {
      user.value = null
    } finally {
      status.value = 'ready'
    }
  }

  /**
   * Bug fix (2026-08-02, human-reported, same gap found in frontend/) —
   * LoginView's "จดจำฉัน" checkbox existed but was never sent to the
   * backend, so it silently did nothing. The backend's LoginRequest
   * already calls `Auth::attempt($credentials, $this->boolean('remember'))`
   * (Laravel's built-in remember-me — a long-lived `remember_token`
   * cookie, same mechanism Facebook/most sites use to skip login on
   * return visits even after the short session expires) — it just never
   * received a truthy value. This wires the checkbox through.
   *
   * @throws {ApiError} on invalid credentials (422) or rate limiting (429) —
   * callers (LoginView) are responsible for mapping this to UI copy.
   */
  async function login(email: string, password: string, remember = false): Promise<void> {
    await ensureCsrfCookie()
    const res = await api.post<{ data: AuthUser }>('/login', { email, password, remember })
    user.value = res.data
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/logout')
    } finally {
      user.value = null
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
