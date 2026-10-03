/**
 * MOB-31 (2026-10-02) — compare two app version strings ("1.4.2").
 *
 * The forced-update check asks one question: is the installed build older
 * than the server's minimum? A string comparison gets that wrong in exactly
 * the case that matters ("1.10.0" < "1.9.0" as strings), so this compares
 * the numbers.
 *
 * Accepted: "1", "1.2", "1.2.3", a leading "v", a pre-release ("1.2.3-beta.1",
 * which is OLDER than "1.2.3", as in semver) and build metadata ("+45",
 * ignored). Missing parts count as 0, so "1.2" equals "1.2.0".
 *
 * Anything else returns null from parseVersion / compareVersions. The caller
 * must treat null as "cannot tell" and do nothing — a typo in an admin field
 * must never lock every agent out of the app.
 */

export interface ParsedVersion {
  numbers: number[]
  prerelease: string | null
}

export function parseVersion(raw: string | null | undefined): ParsedVersion | null {
  if (typeof raw !== 'string') return null

  const trimmed = raw.trim().replace(/^v/i, '').split('+')[0] ?? ''
  const dash = trimmed.indexOf('-')
  const core = dash === -1 ? trimmed : trimmed.slice(0, dash)
  const prerelease = dash === -1 ? null : trimmed.slice(dash + 1)

  if (!/^\d+(\.\d+)*$/.test(core)) return null
  if (prerelease === '') return null

  return { numbers: core.split('.').map(Number), prerelease }
}

/**
 * -1 when a < b, 0 when equal, 1 when a > b; null when either is not a
 * version this function understands.
 */
export function compareVersions(
  a: string | null | undefined,
  b: string | null | undefined,
): -1 | 0 | 1 | null {
  const left = parseVersion(a)
  const right = parseVersion(b)
  if (!left || !right) return null

  const length = Math.max(left.numbers.length, right.numbers.length)
  for (let i = 0; i < length; i++) {
    const l = left.numbers[i] ?? 0
    const r = right.numbers[i] ?? 0
    if (l !== r) return l < r ? -1 : 1
  }

  // Same numbers: a pre-release comes BEFORE its release.
  if (left.prerelease === right.prerelease) return 0
  if (left.prerelease === null) return 1
  if (right.prerelease === null) return -1

  return comparePrerelease(left.prerelease, right.prerelease)
}

/** semver §11: dot-separated identifiers, numeric ones compared as numbers. */
function comparePrerelease(a: string, b: string): -1 | 0 | 1 {
  const left = a.split('.')
  const right = b.split('.')
  const length = Math.max(left.length, right.length)

  for (let i = 0; i < length; i++) {
    const l = left[i]
    const r = right[i]
    if (l === undefined) return -1
    if (r === undefined) return 1
    if (l === r) continue

    const ln = /^\d+$/.test(l) ? Number(l) : null
    const rn = /^\d+$/.test(r) ? Number(r) : null
    if (ln !== null && rn !== null) return ln < rn ? -1 : 1
    if (ln !== null) return -1
    if (rn !== null) return 1

    return l < r ? -1 : 1
  }

  return 0
}

/** True only when `installed` is definitely older than `required`. */
export function isOlderThan(
  installed: string | null | undefined,
  required: string | null | undefined,
): boolean {
  return compareVersions(installed, required) === -1
}
