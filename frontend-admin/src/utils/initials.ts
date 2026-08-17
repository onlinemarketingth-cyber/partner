/**
 * Initials avatar fallback — shared wherever a user's identity is shown
 * without a real avatar_url. Strips punctuation (e.g. the "(dev)"
 * suffix on seeded dev accounts) so it never becomes an initial itself.
 * Ported from frontend/src/utils/initials.ts (ADR-003 — no shared
 * package between the two frontends yet).
 */
export function initials(name: string): string {
  const parts = name.replace(/[^\p{L}\p{N}\s]/gu, '').trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return '?'
  const first = parts[0]?.[0] ?? ''
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : ''
  return (first + last).toUpperCase()
}
