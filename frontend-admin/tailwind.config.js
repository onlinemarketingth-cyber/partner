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
        brand: {
          50: '#F4F5FB',
          100: '#C5CDEA',
          200: '#96A5DA',
          300: '#677DC9',
          400: '#3F59B2',
          500: '#2F4183',
          600: '#1E2A54', // anchor — sampled navy from the GENESENN mark
          700: '#182142',
          800: '#111830',
          900: '#0B0F1E',
        },
        gold: {
          50: '#FAF8F5',
          100: '#E5DCCE',
          200: '#D1BFA7',
          300: '#BCA381',
          400: '#A8875A', // anchor — sampled gold from the GENESENN mark
          500: '#8C704A',
          600: '#705A3B',
          700: '#54432C',
          800: '#372C1D',
          900: '#1B150E',
        },
      },
    },
  },
  plugins: [],
}
