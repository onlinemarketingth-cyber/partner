# CI-001: "Neural Atlas" Visual Reference — Adoption Notes

- **Date:** 2026-07-08
- **Reference:** https://dribbble.com/shots/27445476-Neural-Atlas-Website (Mariusz Mitkow)
- **Status:** Accepted, partial adoption (human-approved)

## What the reference looks like

Single hero shot for a smart-glasses product landing page. Cool light-gray
background gradient (~#E9EBEC → #C7CCD1). Near-black (#0E0E10) pill buttons/nav,
white/light-gray secondary pills, one saturated lime-green accent
(~#B6E84A) used sparingly — a dot-grid "neural network" logo mark and a
lens reflection, nowhere else. Headline mixes a light italic phrase with a
bold upright phrase in the same line. All buttons/tags are fully pill-shaped
(rounded-full). Hero sits in one large rounded-corner (~24-32px) card.
Avatar stack next to the primary CTA for social proof.

## Decisions (asked the human before touching shared components)

1. **Accent color** — indigo stays the primary accent (nav, primary
   buttons, active states) across `HeroHeader`, `TabFilterBar`,
   `TopNavigation`. The reference's lime green is adopted as a
   **secondary accent reserved for gamification/success moments**: XP
   gained, badge earned, completed/won states. Added `lime` to
   `TabFilterBar`'s `accentMap`. Do not use lime for primary navigation
   or CTAs — that would dilute its meaning.
2. **Shape language** — kept our existing `rounded-xl`/`rounded-2xl`
   moderate corner radius project-wide. Did **not** switch to the
   reference's fully-pill (`rounded-full`) buttons — would have required
   touching every already-shipped component for a look the human judged
   too trendy/less enterprise for this project.

## What was adopted directly (no shared-component impact, no sign-off needed)

- **Logo mark** — `AppLogo.vue`'s icon mode changed from a plain "S" to
  a 3x3 dot-grid mark (SVG, scales cleanly), in lime-on-indigo instead
  of the reference's lime-on-black. Ties into the "Sync" name (nodes
  syncing) better than a plain letter did.
- **Editorial headline pairing** (italic-light + bold-upright in one
  line) — reserved for large marketing-style moments (Login screen,
  empty states), not dense dashboard/table screens. Not yet
  implemented anywhere (no Login screen built yet) — noted here so
  whoever builds it knows the intended treatment.

## Explicitly not adopted

- The reference's own brand asset (raster logo, product photography) —
  not ours to use.
- Avatar-stack-next-to-CTA — good fit for a future Leaderboard "top
  agents" widget, not implemented yet (Leaderboard page doesn't exist
  yet, per the earlier Agent Portal page-plan discussion).

## Addendum: LoginView first-pass correction (2026-07-08)

Re-checked the first-pass `LoginView.vue` against this reference and
against Dribbble's own extracted color-palette metadata for the shot
(`#59656E`, `#B0BBC4`, `#6F7B83`, `#0C0907` — neutral gray-blue + near-
black, no lime in the top 4, confirming lime really is a minor accent).
Human-reviewed corrections (see `frontend/src/views/LoginView.vue`):

1. **Panel balance** — the reference's near-black is confined to small
   pills/nav on a mostly light-gray canvas; the first pass inverted
   that with a full half-panel dark surface. Corrected: brand panel
   narrowed (`grid-cols-2` → `grid-cols-[2fr_3fr]`) and lightened one
   step (`bg-slate-900` → `bg-slate-800`).
2. **Dot-grid scope** — reference confines the dot-grid motif to the
   small logo mark. The first pass stretched it into a full-panel
   background texture, contradicting our own "used sparingly" call.
   Removed the panel-wide texture; the motif now only lives in
   `AppLogo.vue`.
3. **Thai italic legibility** — the editorial italic+bold pairing is
   Latin-script in origin. Applying `italic` to Kanit-rendered Thai
   glyphs has no legibility precedent and looked off. Corrected: the
   pairing (light italic + bold upright) is now Latin/English-only;
   the Thai headline uses weight contrast (light vs. bold) without a
   slant. Any future editorial-headline screen should follow this same
   split rather than italicizing Thai text.
