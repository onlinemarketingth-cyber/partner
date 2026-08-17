import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/api/client'
import { applyFavicon, applyGoogleFont, applyRamp, hexToRgb } from '@/theme/assets'
import {
  AA_CONTRAST,
  contrastRatio,
  isDark,
  mix,
  muteInk,
  parseHex,
  pickInk,
  readableTint,
  semanticPair,
  toChannels,
  type Rgb,
} from '@/theme/contrast'

/**
 * TASK-055 / ADR-018 — per-company theming (white-label).
 *
 * Loads a company's presentational theme (colors, font, background,
 * logos, loading splash config, curated label overrides) and applies it
 * at runtime via CSS variables. Runs pre-login (public endpoint, resolved
 * by slug) and again once the authenticated company is known.
 *
 * Only presentational state lives here — no business logic (CLAUDE.md §7).
 */

const SLUG_KEY = 'sv_company_slug'

/**
 * TASK-168 — what index.html's inline script needs to paint the boot splash
 * in this tenant's colours on the FIRST frame. See the comment there; the
 * two halves only work together.
 */
const SPLASH_BOOT_KEY = 'sv_splash_boot'

export interface ThemeBackground {
  type: 'solid' | 'gradient' | 'image' | null
  config: Record<string, unknown> | null
  image_url: string | null
}

export interface ThemeLogos {
  nav_url: string | null
  login_url: string | null
  favicon_url: string | null
  loading_url: string | null
}

export interface ThemeLoading {
  bg_hex: string | null
  message: string | null
}

export interface Theme {
  company: { name: string; slug: string }
  primary_hex: string | null
  accent_hex: string | null
  nav_bg_hex: string | null
  // TASK-161 §3.1 — the nav bar can be a two-stop gradient. `nav_bg_hex`
  // stays the SOLID value; a null/absent type means solid, so a theme
  // saved before this feature keeps its exact previous appearance.
  nav_bg_type: 'solid' | 'gradient' | null
  nav_bg_config: Record<string, unknown> | null
  nav_text_hex: string | null
  nav_active_hex: string | null
  card_bg_hex: string | null
  card_text_hex: string | null
  card_border_hex: string | null
  card_shadow: string | null
  background: ThemeBackground | null
  font_family: string | null
  font_family_thai: string | null
  font_family_latin: string | null
  font_weights: number[] | null
  logos: ThemeLogos | null
  loading: ThemeLoading | null
  label_overrides: Record<string, string> | null
  // TASK-057 — key => Icon.vue icon-name map for the bottom-nav icons
  // (BR-7 admin config). Same key set as label_overrides.
  nav_icon_overrides: Record<string, string> | null
}

/**
 * TASK-098 / ADR-023 — hues the semantic tokens are tinted from. These
 * are the 600 steps of the emerald/amber/rose ramps the app used to
 * hardcode; `semanticPair()` mixes them into the CARD background so the
 * pill belongs to whatever card the tenant configured.
 */
const SEMANTIC_HUES: Record<'success' | 'warning' | 'danger', Rgb> = {
  success: [5, 150, 105], // emerald-600
  warning: [217, 119, 6], // amber-600
  danger: [225, 29, 72], // rose-600
}

/**
 * TASK-161 §2 — the two ink candidates a surface is judged against.
 *
 * Derived by asking `pickInk()` for the readable ink on pure white and on
 * pure black, rather than restating literals here: contrast.ts's
 * INK_DARK/INK_LIGHT pair is not exported, and a second copy of those
 * values in this file is a copy that can drift.
 */
const INK_CANDIDATES: Rgb[] = [pickInk([255, 255, 255]), pickInk([0, 0, 0])]

/**
 * Angle (deg) used when a gradient config omits one. Shared by the app
 * background and the nav bar so the two controls behave identically.
 */
const GRADIENT_FALLBACK_ANGLE = 160

/**
 * The two stops of a gradient config, whichever key names it was written with.
 *
 * ── A REAL, PRE-EXISTING BUG, NOT A STYLE PREFERENCE ────────────────────
 * The Admin theme screen has written `background_config` as `{ from, to,
 * angle }` since TASK-055. This store has always read `{ color1, color2 }`.
 * The two never met: `if (c.color1 && c.color2)` was simply false for every
 * row the Admin had ever saved, so **the app-background gradient has never
 * rendered on the Agent Portal.** It silently fell through to no gradient,
 * and TASK-159's `appBackgroundColor()` — same key names — therefore returned
 * null and let `--surface-app` fall back to the card colour.
 *
 * Found while reviewing TASK-161, where ag-ui flagged that my spec named
 * `color1/color2` for the NEW nav gradient while the existing background
 * control shipped `from/to`. They were right to flag it, and the mismatch
 * turned out to be older and worse than a spec typo.
 *
 * Accepting BOTH shapes rather than picking one and migrating: rows already
 * in the database carry `from/to`, and a read-side tolerance fixes every
 * existing company the moment they reload, with nothing to run and nothing to
 * roll back. `color1/color2` is the canonical spelling going forward (the nav
 * gradient uses it on both sides), and `from/to` is the legacy alias.
 */
function gradientStops(config: unknown): [string, string] | null {
  if (!config || typeof config !== 'object') return null

  const c = config as { color1?: unknown; color2?: unknown; from?: unknown; to?: unknown }
  const first = typeof c.color1 === 'string' ? c.color1 : typeof c.from === 'string' ? c.from : null
  const second = typeof c.color2 === 'string' ? c.color2 : typeof c.to === 'string' ? c.to : null

  return first && second ? [first, second] : null
}

/** Contrast pairs the Admin screen / Config Health can surface as a warning. */
export interface ContrastAudit {
  key: string
  ratio: number
  passes: boolean
  /**
   * The threshold `passes` was judged against — AA (4.5) for text pairs,
   * lower only for a non-text pair such as a field outline (TASK-124), so
   * a UI showing this audit can label the bar it was actually held to.
   */
  minRatio: number
}

export const useThemeStore = defineStore('theme', () => {
  const theme = ref<Theme | null>(null)
  const applied = ref(false)
  const contrastAudit = ref<ContrastAudit[]>([])

  // --- Logo URL getters (null fallback → components use built-in AppLogo) --
  const navLogo = computed(() => theme.value?.logos?.nav_url ?? null)
  const loginLogo = computed(() => theme.value?.logos?.login_url ?? null)
  const loadingLogo = computed(() => theme.value?.logos?.loading_url ?? null)

  /**
   * Company background as an inline-style object, for App.vue to apply as
   * a fallback layer BEHIND the personal user background. Returns `{}`
   * when no company background is set — in which case the layer's own
   * `bg-surface-app` class shows through, which since TASK-159 §4.1 is
   * the company's derived page surface (`appBackgroundColor() ?? cardBg`)
   * rather than a fixed slate.
   */
  const companyBackgroundStyle = computed<Record<string, string>>(() => {
    const style: Record<string, string> = {}
    const bg = theme.value?.background
    if (!bg || !bg.type) return style

    if (bg.type === 'image' && bg.image_url) {
      style.backgroundImage = `url(${bg.image_url})`
      style.backgroundSize = 'cover'
      style.backgroundPosition = 'center'
      return style
    }

    if (bg.type === 'gradient' && bg.config) {
      const stops = gradientStops(bg.config)
      if (stops) {
        const angle = (bg.config as { angle?: number }).angle ?? GRADIENT_FALLBACK_ANGLE
        style.backgroundImage = `linear-gradient(${angle}deg, ${stops[0]}, ${stops[1]})`
      }
      return style
    }

    if (bg.type === 'solid') {
      const c = bg.config as { color?: string; hex?: string } | null
      const color = c?.color ?? c?.hex ?? theme.value?.primary_hex
      if (color) style.backgroundColor = color
    }

    return style
  })

  /**
   * Resolve the company slug pre-login, in priority order:
   *   1) `?company=<slug>` query param (persisted to localStorage when seen)
   *   2) last-used slug from localStorage
   *   3) null (neutral default brand)
   */
  function resolveSlug(): string | null {
    let fromQuery: string | null = null
    try {
      fromQuery = new URLSearchParams(window.location.search).get('company')
    } catch {
      fromQuery = null
    }
    if (fromQuery) {
      try {
        window.localStorage?.setItem(SLUG_KEY, fromQuery)
      } catch {
        /* ignore storage errors (private mode, etc.) */
      }
      return fromQuery
    }
    try {
      return window.localStorage?.getItem(SLUG_KEY) ?? null
    } catch {
      return null
    }
  }

  /**
   * TASK-168 — hand index.html's inline script the three values it needs to
   * paint the NEXT boot's splash in this tenant's colours from the first
   * frame. Without it the splash paints neutral, then flips to branded once
   * this store resolves, and the flip reads as a second loading screen
   * (human report, 2026-08-11).
   *
   * Keyed by slug on purpose: a device that has seen two tenants must never
   * paint the wrong one, and the reader compares slugs before applying, so a
   * disagreement degrades to neutral rather than to somebody else's brand.
   *
   * Deliberately NOT called from `applyResolved()`. Those are the customer
   * -facing token pages, where the person is not this tenant's agent —
   * §6/BR-6 already keeps the slug out of their storage and this would put
   * the same fact back in under a different key.
   */
  function cacheSplashBoot(): void {
    const slug = theme.value?.company?.slug
    if (!slug) return
    try {
      window.localStorage?.setItem(
        SPLASH_BOOT_KEY,
        JSON.stringify({
          slug,
          bg: theme.value?.loading?.bg_hex ?? null,
          bar: theme.value?.primary_hex ?? null,
          logo: loadingLogo.value ?? navLogo.value ?? null,
        }),
      )
    } catch {
      /* private mode / quota — the neutral splash is still a correct look */
    }
  }

  /** curated label override: `label_overrides[key] ?? fallback`. */
  function label(key: string, fallback: string): string {
    return theme.value?.label_overrides?.[key] ?? fallback
  }

  /**
   * curated icon override: `nav_icon_overrides[key] ?? fallback`
   * (TASK-057). `fallback` is the icon name this component would use
   * anyway if no company override exists — never omit it, an unknown
   * icon name just renders Icon.vue's default glyph.
   */
  function icon(key: string, fallback: string): string {
    return theme.value?.nav_icon_overrides?.[key] ?? fallback
  }

  /**
   * TASK-098 / ADR-023 — derive every surface/ink/line token from the
   * SURFACES the company chose, using WCAG contrast.
   *
   * Human decision (2026-08-04, "1+2"): auto-derive AND honour a manual
   * override. So `card_text_hex` / `nav_text_hex`, when set, win — but
   * the resulting ratio is recorded in `contrastAudit` so the Admin
   * screen can show `AA 7.2:1 ✓` / `2.1:1 ✗` instead of accepting an
   * unreadable choice silently (BR-7: the value stays admin-editable,
   * we only surface the consequence).
   *
   * Muted/subtle shades are MIXED from the ink toward its own surface
   * rather than being fixed slate steps. That is what lets the text
   * hierarchy survive a black card — the old approach hardcoded
   * `text-slate-500`, which then needed the blunt `.has-card-text`
   * override that flattened every shade to a single colour.
   */
  function applyContrastTokens(t: Theme): void {
    const root = document.documentElement
    const audit: ContrastAudit[] = []

    const set = (name: string, rgb: Rgb) => root.style.setProperty(name, toChannels(rgb))
    // `minRatio` defaults to AA because almost every pair here is text on
    // a surface. It is only ever passed explicitly for a NON-text pair
    // (TASK-124's field outline), so no entry is silently graded soft.
    const record = (key: string, bg: Rgb, fg: Rgb, minRatio: number = AA_CONTRAST) => {
      const ratio = contrastRatio(bg, fg)
      audit.push({
        key,
        ratio: Math.round(ratio * 10) / 10,
        passes: ratio >= minRatio,
        minRatio,
      })
    }

    // --- Card ------------------------------------------------------------
    const cardBg = parseHex(t.card_bg_hex) ?? [255, 255, 255]
    const cardInk = parseHex(t.card_text_hex) ?? pickInk(cardBg)

    set('--surface-card', cardBg)
    set('--ink-card', cardInk)
    // Both tiers are floored at AA. The first version floored `subtle` at
    // 3:1 (the WCAG large-text threshold) and it measured 3.5:1 on this
    // tenant's black card — readable at a glance in a screenshot, but
    // `--ink-card-subtle` is used for hint and placeholder text at 11-12px,
    // which is not large text. Hierarchy still survives: 17.3 / 6.7 / 4.5.
    set('--ink-card-muted', muteInk(cardInk, cardBg, 0.38, AA_CONTRAST))
    set('--ink-card-subtle', muteInk(cardInk, cardBg, 0.58, AA_CONTRAST))
    record('card', cardBg, cardInk)

    // Hairlines: a fixed slate-200 border disappears on a black card and
    // shouts on a dark one. Mixing the ink into the surface keeps it a
    // hairline at any lightness.
    //
    // Human-reported 2026-08-04 ("border card เหมือนจะ hardcode ไว้
    // ไม่เปลี่ยนตาม config"): the legacy `card_border_hex` setting only
    // ever reached `.has-card-border .bg-white\/95`, and converted
    // components no longer carry that class — so the setting silently
    // stopped applying to them. It now drives `--line-card` directly.
    // 'none' maps to the card colour itself rather than `transparent`,
    // because this var is channels feeding `rgb(var(--line-card) / α)`.
    const borderOverride = t.card_border_hex === 'none' ? cardBg : parseHex(t.card_border_hex)
    set('--line-card', borderOverride ?? mix(cardBg, cardInk, 0.14))
    // `subtle` is the internal-divider weight. When the admin picks an
    // explicit border colour we still halve it toward the card, otherwise
    // every divider inside a card renders at full outline weight and the
    // card reads as a grid instead of a card.
    set('--line-card-subtle', borderOverride ? mix(cardBg, borderOverride, 0.5) : mix(cardBg, cardInk, 0.07))

    // --- Neutral chip ----------------------------------------------------
    // Derived from the card, never a fixed slate-100. This is the fix for
    // the light-ink-on-pale-pill failure: on a dark card the chip is now
    // a dark tint, so light ink reads correctly on it.
    const chipBg = mix(cardBg, cardInk, 0.1)
    const chipInk = muteInk(pickInk(chipBg, [cardInk, ...([[255, 255, 255], [15, 23, 42]] as Rgb[])]), chipBg, 0.15)
    set('--surface-chip', chipBg)
    set('--ink-chip', chipInk)
    record('chip', chipBg, chipInk)

    // --- Form fields (TASK-124) ------------------------------------------
    // Human-reported 2026-08-05 (screenshot of /register on a dark tenant):
    // light-tan text on a near-white box. Every input in the app said
    // `text-ink-card placeholder:text-ink-card-subtle` and set NO
    // background, so the ink followed the card while the box stayed the
    // browser's default white — invisible as soon as `--ink-card` flipped
    // light. The input was the one surface that never got a pair.
    //
    // WHY it steps off the CARD instead of being pinned to white: pinning
    // to white "fixes" the dark theme by punching a white hole into a
    // black card, which is the same failure ADR-023 §2.2 recorded for pale
    // chips. A field has to be a SIBLING of its card — a slightly darker
    // light on a light card, a slightly LIGHTER DARK on a dark one — so it
    // is the card surface stepped toward the card's own ink.
    //
    // The step is bigger on a dark card on purpose: the same 6% mix that
    // reads as a distinct field on white is invisible against black
    // (equal RGB deltas are far less perceptible at the dark end), so a
    // black-card tenant would see no field boundary at all.
    const inputBg = mix(cardBg, cardInk, isDark(cardBg) ? 0.12 : 0.06)
    // Ink is picked against the FIELD, not the card. That single change is
    // the fix: whatever the field became, its text is AA-guaranteed on it.
    const inputInk = pickInk(inputBg)
    // Floored at AA, not at the 3:1 large-text threshold — a placeholder
    // is real 12-13px text carrying the only hint of what to type. Same
    // call ADR-023 §6a already recorded for `--ink-card-subtle`, which
    // measured 3.5:1 when it was floored at 3.
    const inputPlaceholderInk = muteInk(inputInk, inputBg, 0.5, AA_CONTRAST)
    set('--surface-input', inputBg)
    set('--ink-input', inputInk)
    set('--ink-input-placeholder', inputPlaceholderInk)
    // Field outline, derived off the FIELD so it tracks it at any
    // lightness (a fixed slate-200 vanishes on a black card). Held one
    // notch heavier than `--line-card` (0.14) because this hairline has to
    // read against the card AND the field, not just one of them.
    const inputLine = mix(inputBg, inputInk, 0.22)
    set('--line-input', inputLine)
    // The parts of a field the page does NOT paint — the `type="time"`
    // clock glyph, the number spinner, the browser autofill wash, the
    // `<select>` popup — are drawn by the browser in dark ink unless told
    // otherwise. Without this they vanish inside a dark field, which
    // would be the same bug in a new place.
    root.style.setProperty('--input-color-scheme', isDark(inputBg) ? 'dark' : 'light')
    record('input', inputBg, inputInk)
    record('input-placeholder', inputBg, inputPlaceholderInk)
    // The outline is judged against a SEPARATION floor, not AA: it is not
    // text, and it is no longer the only thing marking the field now that
    // `--surface-input` differs from the card. WCAG 1.4.11's 3:1 applies
    // where a boundary is the sole affordance; pinning to it here would
    // roughly double the visual weight of every form in the app, which is
    // a design change, not a legibility fix. Recorded so the Admin screen
    // can still show it rather than nobody ever measuring it.
    record('input-border', inputBg, inputLine, 1.5)

    // --- App background --------------------------------------------------
    // A gradient is averaged rather than ignored: section headings sit on
    // it directly, and "just use the card colour" is only right while the
    // two happen to agree. An IMAGE background still falls back to the
    // card — there is no colour to read without sampling the bitmap, and
    // guessing wrong there is worse than tracking the card.
    const appBg = appBackgroundColor(t) ?? cardBg
    const appInk = pickInk(appBg)
    set('--surface-app', appBg)
    set('--ink-app', appInk)
    set('--ink-app-muted', muteInk(appInk, appBg, 0.38))
    // TASK-159 §4 — this pair was derived but never RECORDED, because
    // until now nothing painted `--surface-app`: the app/page surface was
    // a fixed slate-100 and `--ink-app` was used on exactly two headings.
    // Now that `bg-surface-app` is the page surface everywhere (App.vue
    // and the three public token pages), it is a real text-on-surface
    // pair and belongs in the audit the Admin screen / Config Health
    // reads — an unmeasured pair is one nobody can be warned about.
    record('app', appBg, appInk)

    // --- Nav chrome ------------------------------------------------------
    // TASK-161 §2 (ag-lead ruling) — a gradient nav bar has TWO surfaces
    // and the menu text sits across both, so the ink is chosen to be
    // legible at BOTH stops rather than at their average. Averaging is
    // exactly what produces a bar that reads in the middle and not at one
    // end. For a solid bar `stops` has one entry and this collapses to the
    // previous `pickInk(navBg)` behaviour, byte for byte.
    const stops = navStops(t)
    const navInk = parseHex(t.nav_text_hex) ?? inkAcross(stops)
    // `--surface-nav` is the pair contrastAudit reads. It is set to
    // whichever stop scored WORSE against the chosen ink, so the audit
    // reports the true worst case — a gradient must never be able to
    // improve its own score by averaging. When the admin set `nav_text_hex`
    // explicitly that choice still wins (decision B), and this is what
    // makes the audit tell them when it fails at one end instead of
    // silently overriding them.
    const navSurface = worstStop(stops, navInk)
    set('--surface-nav', navSurface)
    set('--ink-nav', navInk)
    set('--ink-nav-muted', muteInk(navInk, navSurface, 0.4))
    record('nav', navSurface, navInk)

    // --- Primary ---------------------------------------------------------
    // The ramp is generated by lightness mixing, so brand-600 for a pale
    // primary is itself pale. Reading the GENERATED step back out of the
    // DOM (rather than shading primary_hex again here) guarantees the ink
    // matches the exact colour `bg-surface-primary` will paint.
    const brand600 = readChannels(root, '--brand-600') ?? [30, 42, 84]
    const primaryInk = pickInk(brand600)
    set('--surface-primary', brand600)
    set('--ink-primary', primaryInk)
    record('primary', brand600, primaryInk)

    // Brand-coloured TEXT on a card (prices, accents). `text-brand-700`
    // was doing this job and sank into a dark card, because the ramp is
    // generated by lightness-mixing and its 700 step is a dark version of
    // an already-mid-tone primary.
    const brandInk = readableTint(brand600, cardBg)
    set('--ink-brand', brandInk)
    record('brand-text', cardBg, brandInk)

    // --- Semantic --------------------------------------------------------
    for (const [name, hue] of Object.entries(SEMANTIC_HUES)) {
      const pair = semanticPair(hue, cardBg)
      set(`--surface-${name}`, pair.surface)
      set(`--ink-${name}`, pair.ink)
      record(name, pair.surface, pair.ink)
    }

    contrastAudit.value = audit
  }

  /**
   * TASK-161 §3.1 — the nav bar's colour STOPS: one for a solid bar, two
   * for a gradient. Deliberately returns the stops rather than a blended
   * colour; blending is the thing §2 forbids.
   *
   * A half-specified gradient (one stop) is rejected by the API, but this
   * still degrades to the stop it has (then the solid hex) rather than
   * throwing — a client must not fail to paint a nav bar because a row
   * written by some other path is incomplete.
   */
  function navStops(t: Theme): Rgb[] {
    const solid = parseHex(t.nav_bg_hex) ?? [255, 255, 255]
    if (t.nav_bg_type !== 'gradient') return [solid]

    const stops = gradientStops(t.nav_bg_config)
    const from = parseHex(stops?.[0])
    const to = parseHex(stops?.[1])
    if (from && to) return [from, to]

    return [from ?? to ?? solid]
  }

  /**
   * The CSS value for `--nav-bg` — a `linear-gradient(...)` string for a
   * gradient bar, the solid hex otherwise, null when nothing is set.
   * `--nav-bg` is consumed only as `background: var(--nav-bg)` (App.vue,
   * BottomNav.vue), so a gradient string drops straight in; the channel
   * form lives in `--surface-nav` and is handled separately above.
   */
  function navBackground(t: Theme): string | null {
    if (t.nav_bg_type === 'gradient') {
      const stops = gradientStops(t.nav_bg_config)
      if (stops) {
        const angle = (t.nav_bg_config as { angle?: number } | null)?.angle ?? GRADIENT_FALLBACK_ANGLE
        return `linear-gradient(${angle}deg, ${stops[0]}, ${stops[1]})`
      }
    }

    return t.nav_bg_hex
  }

  /**
   * TASK-161 §2, exactly as ruled:
   *   for each candidate ink: score = min(contrast(ink, stop) for every stop)
   *   pick the candidate with the higher score
   */
  function inkAcross(stops: Rgb[]): Rgb {
    let best = INK_CANDIDATES[0]!
    let bestScore = -1

    for (const candidate of INK_CANDIDATES) {
      const score = Math.min(...stops.map((stop) => contrastRatio(stop, candidate)))
      if (score > bestScore) {
        best = candidate
        bestScore = score
      }
    }

    return best
  }

  /** The stop that scores WORST against `ink` — the honest audit surface. */
  function worstStop(stops: Rgb[], ink: Rgb): Rgb {
    return stops.reduce((worst, stop) =>
      contrastRatio(stop, ink) < contrastRatio(worst, ink) ? stop : worst,
    )
  }

  /**
   * The flat colour that best represents the company's page background,
   * or null when it cannot be known (an uploaded image).
   */
  function appBackgroundColor(t: Theme): Rgb | null {
    const bg = t.background
    if (!bg || !bg.type) return null

    if (bg.type === 'solid') {
      const c = bg.config as { color?: string; hex?: string } | null

      return parseHex(c?.color ?? c?.hex ?? t.primary_hex)
    }

    if (bg.type === 'gradient') {
      const stops = gradientStops(bg.config)
      const a = parseHex(stops?.[0])
      const b = parseHex(stops?.[1])
      if (a && b) return mix(a, b, 0.5)

      return a ?? b
    }

    return null
  }

  /** Read an already-applied `"R G B"` CSS var back as channels. */
  function readChannels(root: HTMLElement, name: string): Rgb | null {
    const raw = getComputedStyle(root).getPropertyValue(name).trim()
    if (!raw) return null

    const parts = raw.split(/[\s,]+/).map(Number)
    if (parts.length < 3 || parts.some((n) => Number.isNaN(n))) return null

    return [parts[0]!, parts[1]!, parts[2]!]
  }

  /**
   * Write CSS vars / font / favicon for the currently-loaded theme.
   * Every provided field overrides its default; nulls leave the default
   * (from :root in main.css) untouched. Generates the full 50–900 brand
   * ramp from `primary_hex` and the gold ramp from `accent_hex`.
   */
  function apply(): void {
    const t = theme.value
    if (!t) return
    const root = document.documentElement

    applyRamp('brand', t.primary_hex)
    applyRamp('gold', t.accent_hex)

    // App-chrome colours (top bar + bottom nav). Each falls back to the
    // :root default in main.css when unset (removeProperty), so the neutral
    // white-bar / slate-text look is preserved for companies that don't set them.
    // TASK-161 §3.1 — solid hex OR a `linear-gradient(...)` string.
    const navBg = navBackground(t)
    if (navBg) root.style.setProperty('--nav-bg', navBg)
    else root.style.removeProperty('--nav-bg')
    if (t.nav_text_hex) root.style.setProperty('--nav-text', t.nav_text_hex)
    else root.style.removeProperty('--nav-text')

    // Bottom-nav "active tab" colour. Default (unset) falls through to the
    // --nav-active CSS variable's own default in main.css, which points at
    // the generated brand-600 ramp — so the active tab keeps following the
    // primary brand colour unless a company sets a dedicated override here.
    if (t.nav_active_hex) root.style.setProperty('--nav-active', t.nav_active_hex)
    else root.style.removeProperty('--nav-active')

    // Card/surface colour — stored as `R G B` channels so the existing
    // bg-white/95 translucency (rgb(var(--card-bg) / .95)) still works.
    const cardRgb = t.card_bg_hex ? hexToRgb(t.card_bg_hex) : null
    if (cardRgb) root.style.setProperty('--card-bg', cardRgb.join(' '))
    else root.style.removeProperty('--card-bg')

    // Card text colour — gated behind a class so the default slate text
    // hierarchy is untouched until a company sets it (a single override
    // colour would otherwise flatten all card text shades to one colour).
    if (t.card_text_hex) {
      root.style.setProperty('--card-text', t.card_text_hex)
      root.classList.add('has-card-text')
    } else {
      root.style.removeProperty('--card-text')
      root.classList.remove('has-card-text')
    }

    // Card border: 'none' = borderless (transparent), a hex = coloured;
    // null = leave the default slate border.
    if (t.card_border_hex) {
      root.style.setProperty('--card-border', t.card_border_hex === 'none' ? 'transparent' : t.card_border_hex)
      root.classList.add('has-card-border')
    } else {
      root.style.removeProperty('--card-border')
      root.classList.remove('has-card-border')
    }

    // Card shadow: map the level to a box-shadow; null = default.
    const shadowMap: Record<string, string> = {
      none: 'none',
      sm: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
      md: '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
      lg: '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
      xl: '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)',
    }
    const shadowValue = t.card_shadow ? shadowMap[t.card_shadow] : undefined
    if (shadowValue) {
      root.style.setProperty('--card-shadow', shadowValue)
      root.classList.add('has-card-shadow')
    } else {
      root.style.removeProperty('--card-shadow')
      root.classList.remove('has-card-shadow')
    }

    // Per-script fonts: a Latin face lacks Thai glyphs, so a per-glyph
    // font-family stack (Latin FIRST, Thai SECOND) lets Latin characters
    // render in the Latin face while Thai characters fall through to the
    // Thai face. Either may be null → fall back to the legacy single
    // `font_family`. Load one <link> per distinct family (idempotent ids).
    const latin = t.font_family_latin ?? t.font_family
    const thai = t.font_family_thai ?? t.font_family
    if (latin || thai) {
      const stack: string[] = []
      if (latin) stack.push(`"${latin}"`)
      if (thai && thai !== latin) stack.push(`"${thai}"`)
      stack.push('sans-serif')
      root.style.setProperty('--app-font', stack.join(', '))
      if (latin) applyGoogleFont(latin, t.font_weights, 'sv-theme-font-latin')
      if (thai && thai !== latin) applyGoogleFont(thai, t.font_weights, 'sv-theme-font-thai')
    }

    applyFavicon(t.logos?.favicon_url)

    // TASK-098 — LAST, deliberately: it reads the generated --brand-600
    // back out of the DOM to pick the primary-button ink, so the ramp
    // must already be written.
    applyContrastTokens(t)

    applied.value = true
    // Background is applied reactively by App.vue via companyBackgroundStyle.
  }

  /**
   * Pre-login load: resolve slug → GET /public/theme/{slug} → apply.
   * Never throws — boot must not break when no/invalid company or the
   * endpoint fails; the neutral defaults simply remain.
   */
  async function loadPublic(): Promise<void> {
    const slug = resolveSlug()
    if (!slug) return
    try {
      const res = await api.get<{ data: Theme }>(`/public/theme/${encodeURIComponent(slug)}`)
      theme.value = res.data
      apply()
      cacheSplashBoot()
    } catch {
      // leave defaults; do not surface — boot resilience
    }
  }

  /**
   * TASK-159 §4.2 — adopt a theme that arrived INSIDE somebody else's
   * payload and apply it.
   *
   * The three customer-facing token pages (/p/{token}, /pay/{token},
   * /l/{token}) carry no company slug, so `loadPublic()` bails at
   * `resolveSlug()` and they used to render on platform defaults. ag-dev
   * now returns the owning company's theme on those three responses,
   * serialised by the same `ThemeResource` — so there is nothing left to
   * FETCH, only to adopt. This is deliberately the thinnest possible
   * seam: it sets the same `theme` ref the two loaders set and calls the
   * same `apply()`, so there is exactly one implementation of "what a
   * theme does to the DOM".
   *
   * The slug is intentionally NOT cached to localStorage the way
   * `loadForMe()` caches it: the person on these pages is a CUSTOMER, not
   * an agent, and persisting which tenant they were shown is neither
   * useful to them nor something to leave lying around (§6 / BR-6).
   *
   * `null` is a no-op rather than a reset — the payload's `theme` is null
   * only if the company could not be resolved at all, in which case the
   * defaults already in `:root` are exactly what we want to keep.
   */
  function applyResolved(next: Theme | null | undefined): void {
    if (!next) return
    theme.value = next
    apply()
  }

  /**
   * Authenticated load: GET /me/theme → cache slug → apply. Called after
   * auth is known (router guard). Also resilient (never throws).
   */
  async function loadForMe(): Promise<void> {
    try {
      const res = await api.get<{ data: Theme }>('/me/theme')
      theme.value = res.data
      const slug = res.data?.company?.slug
      if (slug) {
        try {
          window.localStorage?.setItem(SLUG_KEY, slug)
        } catch {
          /* ignore */
        }
      }
      apply()
      cacheSplashBoot()
    } catch {
      // leave whatever public theme / defaults are already applied
    }
  }

  /**
   * TASK-064 (human-reported 2026-07-31, follow-up to TASK-063) — every
   * in-app "send me back to /login" navigation (logout button, session
   * expired, self-deactivation) must carry `?company=<slug>` too, not
   * just the branded links Admin hands out externally. Without this, a
   * currently-logged-in agent who logs out mid-session drops back to the
   * NEUTRAL default-coloured login page instead of their own company's
   * themed one — the cached-localStorage-slug fallback in resolveSlug()
   * only helps a FRESH page load (main.ts boot), not an in-SPA route
   * push, which never re-runs loadPublic(). Reads the slug from the
   * theme ALREADY loaded this session (not resolveSlug()'s localStorage
   * read) since that's the authoritative "who is this" for an
   * authenticated user logging out right now.
   */
  function loginRouteLocation(): { name: 'login'; query?: { company: string } } {
    const slug = theme.value?.company?.slug
    return slug ? { name: 'login', query: { company: slug } } : { name: 'login' }
  }

  return {
    theme,
    applied,
    contrastAudit,
    navLogo,
    loginLogo,
    loadingLogo,
    companyBackgroundStyle,
    resolveSlug,
    label,
    icon,
    apply,
    applyResolved,
    loadPublic,
    loadForMe,
    loginRouteLocation,
  }
})
