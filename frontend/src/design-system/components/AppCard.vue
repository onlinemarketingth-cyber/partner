<script setup lang="ts">
/**
 * AppCard — TASK-079 Phase 4 (2026-08-03, UX audit finding: design
 * system consistency).
 *
 * Same finding as AppButton: there was no Card primitive, so the content
 * surface was re-declared inline on every screen and drifted. Concretely
 * the audit found the RADIUS split down the middle — views built on the
 * HeroHeader shell used `rounded-xl`, hand-rolled views used
 * `rounded-2xl`, both otherwise identical (`bg-surface-card/95 border
 * border-line-card shadow-sm`).
 *
 * Standardised on **rounded-2xl**: it is what the primary surfaces the
 * agent looks at most already use (Home, Orders, Notifications), and
 * HeroHeader itself is rounded-2xl — so matching it makes the page read
 * as one family instead of two.
 *
 * The /95 (not /80) alpha is deliberate and must stay: the personal /
 * company background image lives behind the whole app (App.vue), and
 * content has to stay readable over any of them — the theme is meant to
 * show at the EDGES of the card, not through the text. (The utility
 * itself is `bg-surface-card/95` since TASK-098 — see the note at the
 * bottom of this comment.)
 *
 * `interactive` adds the press feedback Phase 3 established for tappable
 * rows: `hover:` alone is dead on a touchscreen, so a tap produced no
 * response at all and agents tapped twice.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TASK-082 (2026-08-03, human request: "พื้นผิวการ์ดแบบเดียวทั้งแอป
 * คุณเสนอแก้เรื่องนี้") — `variant` turns this from ONE surface into a
 * three-level hierarchy.
 *
 * The audit that prompted it measured exactly one surface app-wide:
 * `bg-surface-card/95 + border-line-card + shadow-sm` (now `bg-surface-card/95 +
 * border-line-card`), repeated on every screen,
 * with only a 12px-vs-16px radius difference nobody can see. When every
 * element is equally prominent, nothing is prominent, and all 11 screens
 * read as the same grey stack.
 *
 * Material's own guidance is the fix: **lists for homogeneous content,
 * cards for heterogeneous content** — and explicitly, never use cards when
 * the user must scan comparable items to find one. Five of our screens
 * (Clients / Referrals / Pipeline / Commission / AffiliateLinks) are
 * exactly that scan-and-compare case, so they were using cards wrongly.
 *
 *   flat   — a list ROW. No border, no shadow, no radius; separated from
 *            its neighbour by a hairline only. Dense and scannable. This
 *            is what the five list screens use now.
 *   card   — the original surface. Now RARE, and therefore meaningful:
 *            reserved for genuinely composite blocks (Home's greeting /
 *            menu grid / rings, Orders rows which carry an inline input
 *            plus three buttons).
 *   raised — the single most important thing on a screen. Opaque white
 *            with a brand-tinted left rule. Deliberately only ONE per
 *            screen; if everything is raised we are back where we started.
 *
 * All three work in greyscale on purpose — the human explicitly rejected
 * per-page accent colors (2026-08-03), so differentiation here comes from
 * structure, density and weight, never hue.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TASK-098 / ADR-023 (2026-08-04) — colours now come from the
 * surface/ink/line tokens instead of `bg-surface-card` / `border-slate-*`.
 *
 * This is the file ADR-023 §2.1 was about: card theming used to key off
 * the literal utility string `bg-surface-card/95` (main.css redefines that one
 * class), so the `raised` variant — the only one that is opaque
 * `bg-surface-card` — sat OUTSIDE the theme entirely and rendered as a stark
 * white block between dark siblings on a dark-card tenant. All three
 * variants now name the same `--surface-card` token, so `raised` differs
 * from `card` by ALPHA (opaque vs /95) as it always meant to, not by
 * being a different colour.
 *
 * Usage:
 *   <AppCard>...</AppCard>                          <!-- variant="card" -->
 *   <AppCard variant="flat" interactive>...</AppCard>
 *   <AppCard variant="raised">...</AppCard>
 *   <AppCard padding="sm" interactive @click="open">...</AppCard>
 *   <AppCard padding="none"><img class="rounded-2xl" ...></AppCard>
 */
import { computed } from 'vue'

type Padding = 'none' | 'sm' | 'md'
type Variant = 'flat' | 'card' | 'raised'

const props = withDefaults(
  defineProps<{
    interactive?: boolean
    padding?: Padding
    variant?: Variant
  }>(),
  { interactive: false, padding: 'md', variant: 'card' },
)

const PADDING_CLASSES: Record<Padding, string> = {
  none: '',
  sm: 'p-3',
  md: 'p-4',
}

const VARIANT_CLASSES: Record<Variant, string> = {
  // No radius/border/shadow: rows must butt together so the hairline
  // reads as a divider between them, not as a box around each. The
  // divider lives on the row (border-b) and the LAST one is suppressed by
  // the list wrapper (`[&>*:last-child]:border-b-0`) rather than here,
  // since only the parent knows which row is last.
  flat: 'bg-surface-card/95 border-b border-line-card-subtle',
  card: 'bg-surface-card/95 border border-line-card rounded-2xl shadow-sm',
  // Opaque (not /95): a raised surface is the one thing that must not let
  // the background theme wash through it. TASK-098: it is the SAME
  // `--surface-card` token as the other two variants now, just without
  // the alpha — before, this was a literal `bg-surface-card` and so was the one
  // variant the company card colour never reached.
  raised: 'bg-surface-card border border-line-card border-l-4 border-l-brand-600 rounded-2xl shadow-sm',
}

const classes = computed(() => [
  VARIANT_CLASSES[props.variant],
  PADDING_CLASSES[props.padding],
  // A flat row has no shadow to deepen, so its press feedback is a tint
  // rather than a lift — scaling a full-bleed row also looks broken
  // against its neighbours.
  // TASK-098: the tint is `--surface-chip`, which the theme store derives
  // FROM the card's own lightness — so it darkens a white card and
  // lightens a black one. The old `slate-50`/`slate-100` pair only ever
  // read as a press on a light card; on a dark one it was a bright flash.
  // Hover and press collapse to the one chip token deliberately: there is
  // no second neutral tint that is guaranteed to stay on the right side
  // of the card on every theme.
  props.interactive
    ? props.variant === 'flat'
      ? 'cursor-pointer hover:bg-surface-chip active:bg-surface-chip transition-colors'
      : 'cursor-pointer hover:shadow-md active:scale-[0.98] transition-all'
    : '',
])
</script>

<template>
  <div :class="classes">
    <slot />
  </div>
</template>
