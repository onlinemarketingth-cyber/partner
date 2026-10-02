/**
 * 2026-10-02 — a signup link must not keep a stranger waiting twice.
 *
 * Owner: "ตอนแชร์ link ให้สมัครเข้าเป็น member โหลดช้ามาก ... เหมือนมันค้างเลย".
 *
 * What a recruit opening /j/<code> used to sit through, strictly in order:
 *   1. the boot splash, held for at least 3 s (TASK-078) whatever happened;
 *   2. only THEN the register page mounted and asked the server whether the
 *      link was good, behind a second "กำลังตรวจสอบลิงก์ชวนเข้าทีม..." line.
 * Two loading screens back to back, the second one starting from zero.
 *
 * Owner decision (2026-10-02, "เช็กลิงก์พร้อม splash + ไม่บังคับ 3 วิ"):
 *   - the link is checked from the first instant, in parallel with the
 *     splash (startLinkPrefetch, called by main.ts before it awaits boot);
 *   - pages a CUSTOMER or RECRUIT opens from a shared link are not held to
 *     the 3-second floor (isPublicLinkPage). An agent's own portal still is.
 *
 * The request is the SAME one RegisterView made; it is simply started
 * earlier and handed over. It is handed over ONCE (take…), so a later
 * re-check — the recruit retyping a code by hand — always asks the server.
 */
import { api } from '@/api/client'
import type { Theme } from '@/stores/theme'

export interface TeamLinkAnswer {
  company_name: string
  inviter_name: string
  theme?: Theme | null
}

export interface CompanyCodeAnswer {
  company_name: string
  theme?: Theme | null
}

type LinkTarget = { kind: 'team'; token: string } | { kind: 'company'; code: string }

/**
 * Which signup link, if any, this address is. Mirrors the router:
 * /j/:code and /register?ref=<token> are team links; /c/:code is a company
 * link. Anything else is not prefetched.
 */
export function signupLinkTarget(pathname: string, search: string): LinkTarget | null {
  const team = /^\/j\/([^/]+)\/?$/.exec(pathname)
  if (team?.[1]) {
    const token = decodeURIComponent(team[1]).trim()
    return token ? { kind: 'team', token } : null
  }

  const company = /^\/c\/([^/]+)\/?$/.exec(pathname)
  if (company?.[1]) {
    const code = decodeURIComponent(company[1]).trim()
    return code ? { kind: 'company', code } : null
  }

  if (/^\/register\/?$/.test(pathname)) {
    const ref = new URLSearchParams(search).get('ref')?.trim()
    return ref ? { kind: 'team', token: ref } : null
  }

  return null
}

/**
 * Pages opened by somebody who is not an agent of ours yet — a recruit or a
 * customer holding a link. These skip the splash's 3-second floor; the
 * splash still covers the real loading, it just never outstays it.
 */
export function isPublicLinkPage(pathname: string): boolean {
  return /^\/(j|c|p|pay|l)\/[^/]+\/?$/.test(pathname) || /^\/register\/?$/.test(pathname)
}

let pending: { target: LinkTarget; promise: Promise<unknown> } | null = null

/**
 * Start checking this address's signup link, if it has one. Returns the
 * in-flight request (already settled-safe: it never rejects unhandled) so
 * main.ts can let the splash cover it, or null when there is nothing to do.
 */
export function startLinkPrefetch(
  location: { pathname: string; search: string } = window.location,
): Promise<unknown> | null {
  const target = signupLinkTarget(location.pathname, location.search)
  if (!target) return null

  const promise =
    target.kind === 'team'
      ? api.post<TeamLinkAnswer>('/register/resolve-ref-token', { ref_token: target.token })
      : api.post<CompanyCodeAnswer>('/register/resolve-invite-code', { invite_code: target.code })

  // The page that takes it handles the rejection; this only stops an
  // unclaimed failure (the visitor navigated away) from being reported as
  // an unhandled rejection.
  promise.catch(() => {})
  pending = { target, promise }

  return promise
}

/** The prefetched answer for exactly this team token — once. Null if none. */
export function takePrefetchedTeamLink(token: string): Promise<TeamLinkAnswer> | null {
  if (pending?.target.kind !== 'team' || pending.target.token !== token) return null
  const { promise } = pending
  pending = null

  return promise as Promise<TeamLinkAnswer>
}

/** The prefetched answer for exactly this company code — once. Null if none. */
export function takePrefetchedCompanyCode(code: string): Promise<CompanyCodeAnswer> | null {
  if (pending?.target.kind !== 'company' || pending.target.code !== code) return null
  const { promise } = pending
  pending = null

  return promise as Promise<CompanyCodeAnswer>
}

/** For specs. */
export function resetLinkPrefetch(): void {
  pending = null
}
