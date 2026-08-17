import defaultTheme from 'tailwindcss/defaultTheme'

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        // Thai-friendly brand font, per design-system reference (Kanit),
        // with Noto Sans Thai as a fallback for wider glyph coverage.
        sans: ['Kanit', '"Noto Sans Thai"', ...defaultTheme.fontFamily.sans],
      },
      colors: {
        // Neutral gray scale — ported from the reference design system.
        gray: {
          50: '#F9F9F9',
          100: '#F1F1F1',
          200: '#E2E2E2',
          300: '#C7C7C7',
          400: '#A0A0A0',
          500: '#848282',
          600: '#727070',
          700: '#5A5858',
          800: '#454343',
          900: '#2E2D2D',
        },
        navy: {
          600: '#3F6C92',
          700: '#325675',
        },
        // Brand palette — sourced from the GENESENN co-brand logo
        // (CI-002, 2026-07-08). Replaces the earlier indigo/lime
        // placeholder pair (CI-001) project-wide. See
        // docs/design/CI-002-genesenn-brand.md for the source values
        // and the ramp-generation method (base hex pinned at the noted
        // anchor step, lightness interpolated toward white/black at the
        // ends — hue/saturation held constant).
        //
        // TASK-055 / ADR-018 (per-company theming): every step now
        // references a CSS variable so the palette is runtime-swappable
        // per company. The `rgb(var(--x) / <alpha-value>)` form (channels
        // held as "R G B" in the var) preserves Tailwind's opacity
        // utilities (e.g. `bg-brand-50/60`, `ring-brand-500/30`). The
        // GENESENN default hex values live in `src/assets/main.css`
        // `:root` — so the default rendering is pixel-identical to before.
        brand: {
          50: 'rgb(var(--brand-50) / <alpha-value>)',
          100: 'rgb(var(--brand-100) / <alpha-value>)',
          200: 'rgb(var(--brand-200) / <alpha-value>)',
          300: 'rgb(var(--brand-300) / <alpha-value>)',
          400: 'rgb(var(--brand-400) / <alpha-value>)',
          500: 'rgb(var(--brand-500) / <alpha-value>)',
          600: 'rgb(var(--brand-600) / <alpha-value>)', // anchor — sampled navy from the GENESENN mark
          700: 'rgb(var(--brand-700) / <alpha-value>)',
          800: 'rgb(var(--brand-800) / <alpha-value>)',
          900: 'rgb(var(--brand-900) / <alpha-value>)',
        },
        gold: {
          50: 'rgb(var(--gold-50) / <alpha-value>)',
          100: 'rgb(var(--gold-100) / <alpha-value>)',
          200: 'rgb(var(--gold-200) / <alpha-value>)',
          300: 'rgb(var(--gold-300) / <alpha-value>)',
          400: 'rgb(var(--gold-400) / <alpha-value>)', // anchor — sampled gold from the GENESENN mark
          500: 'rgb(var(--gold-500) / <alpha-value>)',
          600: 'rgb(var(--gold-600) / <alpha-value>)',
          700: 'rgb(var(--gold-700) / <alpha-value>)',
          800: 'rgb(var(--gold-800) / <alpha-value>)',
          900: 'rgb(var(--gold-900) / <alpha-value>)',
        },

        // TASK-098 / ADR-023 — surface / ink / line token pairs.
        //
        // Naming is deliberately NOT slate/white/brand: the whole point
        // is that `text-ink-card` cannot be written without knowing which
        // surface it sits on, whereas `text-slate-500` could be (and was,
        // 447 times) dropped onto a background nobody checked.
        //
        // Defaults live in src/assets/main.css :root and equal today's
        // hardcoded slate values; theme.ts recomputes them per company
        // from WCAG contrast.
        surface: {
          app: 'rgb(var(--surface-app) / <alpha-value>)',
          card: 'rgb(var(--surface-card) / <alpha-value>)',
          nav: 'rgb(var(--surface-nav) / <alpha-value>)',
          primary: 'rgb(var(--surface-primary) / <alpha-value>)',
          chip: 'rgb(var(--surface-chip) / <alpha-value>)',
          success: 'rgb(var(--surface-success) / <alpha-value>)',
          warning: 'rgb(var(--surface-warning) / <alpha-value>)',
          danger: 'rgb(var(--surface-danger) / <alpha-value>)',
          // TASK-124 — form fields. Derived one small step from the card
          // toward the card's own ink, never pinned to white: a field has
          // to be a sibling of the card it sits in, or a dark theme gets a
          // white hole punched in it (and light-on-white text, which is
          // what the human screenshotted on /register).
          input: 'rgb(var(--surface-input) / <alpha-value>)',
        },
        ink: {
          app: 'rgb(var(--ink-app) / <alpha-value>)',
          'app-muted': 'rgb(var(--ink-app-muted) / <alpha-value>)',
          card: 'rgb(var(--ink-card) / <alpha-value>)',
          'card-muted': 'rgb(var(--ink-card-muted) / <alpha-value>)',
          'card-subtle': 'rgb(var(--ink-card-subtle) / <alpha-value>)',
          nav: 'rgb(var(--ink-nav) / <alpha-value>)',
          'nav-muted': 'rgb(var(--ink-nav-muted) / <alpha-value>)',
          primary: 'rgb(var(--ink-primary) / <alpha-value>)',
          brand: 'rgb(var(--ink-brand) / <alpha-value>)',
          chip: 'rgb(var(--ink-chip) / <alpha-value>)',
          success: 'rgb(var(--ink-success) / <alpha-value>)',
          warning: 'rgb(var(--ink-warning) / <alpha-value>)',
          danger: 'rgb(var(--ink-danger) / <alpha-value>)',
          // TASK-124 — paired with surface-input. `text-ink-input` is
          // picked against the FIELD, not against the card, which is the
          // whole difference from the broken `text-ink-card` it replaces.
          // The placeholder tier is floored at AA (not the 3:1 large-text
          // threshold) because a placeholder is real 12-13px text — same
          // call ADR-023 §6a already made for `--ink-card-subtle`.
          input: 'rgb(var(--ink-input) / <alpha-value>)',
          'input-placeholder': 'rgb(var(--ink-input-placeholder) / <alpha-value>)',
        },
        line: {
          card: 'rgb(var(--line-card) / <alpha-value>)',
          'card-subtle': 'rgb(var(--line-card-subtle) / <alpha-value>)',
          input: 'rgb(var(--line-input) / <alpha-value>)',
        },
      },
    },
  },
  plugins: [],
}
