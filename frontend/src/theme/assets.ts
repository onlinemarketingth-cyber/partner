/**
 * TASK-055 / ADR-018 — runtime theme asset helpers.
 *
 * Small DOM side-effect helpers used by the theme store's `apply()`:
 *  - color-ramp generation from a single base hex → CSS-var channels
 *  - Google Font <link> injection (replace-in-place, never duplicated)
 *  - favicon <link rel=icon> swap
 *
 * Kept out of the Pinia store so the store stays about state; these are
 * pure DOM writes. No external deps (CLAUDE.md §3 constraint).
 */

/** Parse `#rrggbb` (or `rrggbb`) → [r, g, b] channels, or null if invalid. */
export function hexToRgb(hex: string): [number, number, number] | null {
  const m = /^#?([0-9a-fA-F]{6})$/.exec(hex.trim())
  if (!m || !m[1]) return null
  const n = parseInt(m[1], 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

// Lightness mix factors per Tailwind-style step. Steps < 500 mix toward
// white (tint), steps > 500 mix toward black (shade); 500 is the base.
// Hue/saturation are effectively held (linear RGB mix toward the poles),
// matching the "interpolate lightness toward white/black" method noted in
// tailwind.config.js for the hand-tuned GENESENN ramp.
const TINT: Record<number, number> = { 50: 0.95, 100: 0.85, 200: 0.7, 300: 0.5, 400: 0.25 }
const SHADE: Record<number, number> = { 600: 0.18, 700: 0.34, 800: 0.5, 900: 0.66 }
const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as const

/** One ramp step as an "R G B" channel string, generated from a base hex. */
function stepChannels(base: [number, number, number], step: number): string {
  const [r, g, b] = base
  let R = r
  let G = g
  let B = b
  if (step < 500) {
    const f = TINT[step] ?? 0
    R = r + (255 - r) * f
    G = g + (255 - g) * f
    B = b + (255 - b) * f
  } else if (step > 500) {
    const f = SHADE[step] ?? 0
    R = r * (1 - f)
    G = g * (1 - f)
    B = b * (1 - f)
  }
  return `${Math.round(R)} ${Math.round(G)} ${Math.round(B)}`
}

/**
 * Generate the full 50–900 ramp for a palette (`brand`/`gold`) from a
 * single base hex and write every step to `document.documentElement` as
 * `--{name}-{step}` channel vars. No-op on an invalid hex (defaults keep).
 */
export function applyRamp(name: 'brand' | 'gold', baseHex: string | null | undefined): void {
  if (!baseHex) return
  const base = hexToRgb(baseHex)
  if (!base) return
  const root = document.documentElement
  for (const step of STEPS) {
    root.style.setProperty(`--${name}-${step}`, stepChannels(base, step))
  }
}

/**
 * Inject or replace a company Google Font <link>. Idempotent — the given
 * element id is reused so re-applying a theme swaps the href, never
 * duplicates the tag. Falls back to weights [400,500,700] when unspecified.
 * `id` lets callers keep separate <link>s per script (Latin / Thai).
 */
export function applyGoogleFont(family: string, weights?: number[] | null, id = 'sv-theme-font'): void {
  const fam = family.trim()
  if (!fam) return
  let link = document.getElementById(id) as HTMLLinkElement | null
  if (!link) {
    link = document.createElement('link')
    link.id = id
    link.rel = 'stylesheet'
    document.head.appendChild(link)
  }
  const famParam = encodeURIComponent(fam).replace(/%20/g, '+')
  const w = (weights && weights.length ? weights : [400, 500, 700]).join(';')
  link.href = `https://fonts.googleapis.com/css2?family=${famParam}:wght@${w}&display=swap`
}

/** Set or replace <link rel="icon"> to the company favicon URL. */
export function applyFavicon(url: string | null | undefined): void {
  if (!url) return
  let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
  if (!link) {
    link = document.createElement('link')
    link.rel = 'icon'
    document.head.appendChild(link)
  }
  link.href = url
}
