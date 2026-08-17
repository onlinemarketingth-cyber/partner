# ADR-023 — Surface/on-surface theme tokens with auto-derived contrast

- **Status:** Accepted — P1 + P2 shipped 2026-08-04; P3–P6 open
- **Date:** 2026-08-04
- **Task:** TASK-098
- **Related:** ADR-018 (per-company theming), CLAUDE.md §9 DoD, BR-7

## 1. Problem

The human reports, on the Agent Portal:

> "สีตัวอักษรบางตำแหน่งบน frontend เจอปัญหาสีพื้นเข้ม ตัวอักษรเข้ม
> และบางตำแหน่งตัวหนังสือสีอ่อน พื้นสีอ่อน ทำให้เห็นไม่ชัด"

Both directions of failure are real, and both are structural rather than
a handful of typos. An audit of `frontend/src/` found **1,147 hardcoded
colour classes** (`text-slate-*` 447, `border-slate-*` 194, `bg-slate-*`
100, `bg-white` 77, `text-white` 50, …) against a theme system that lets
a tenant pick a black card background, light card text, and a light
primary — as the current tenant has (`primary_hex #978A6E`, `card_bg
#000000`, `card_text #ffe6c2`).

## 2. Root causes

### 2.1 Card theming keys off a literal utility string

`main.css` re-defines the `.bg-white\/95` class to `rgb(var(--card-bg) /
.95)` and scopes `--card-text`, `--card-border`, `--card-shadow` to
descendants of that one selector.

Any surface that uses `bg-white`, `bg-white/80`, `bg-slate-50` instead is
**outside the theme entirely**: 45 of 77 white surfaces are `bg-white/95`,
so ~31 are not. That set includes every modal, bottom sheet, drawer,
popover, the `AppCard` `raised` variant, and every public page (login,
payment, share, lead capture). On a black-card tenant these render as
stark white blocks between dark siblings.

### 2.2 `--card-text` is applied too broadly

The override matches `text-slate-*` on **any** descendant of the card,
including elements that carry their own pale background. A
`bg-slate-100 text-slate-600` status pill becomes *light text on a light
pill* — this is the "ตัวหนังสือสีอ่อน พื้นสีอ่อน" half of the report, and
it is caused by the theming CSS rather than merely unprotected by it.
Confirmed at `HeroHeader.vue` (the KPI strip on **every** view),
`NotificationBell`, `NotificationsView`, `HomeView`, `ClientsView`,
`OrdersView`, `AcademyView`, `LeaderboardView`, `ConfirmDialog`.

### 2.3 No contrast maths exists anywhere

`theme/assets.ts` generates the brand ramp by **lightness mixing only**.
It never asks whether anything is readable on the result. So
`AppButton.vue`'s `primary: 'bg-brand-600 text-white'` — the single
highest-blast-radius line in the app — is white-on-light the moment a
tenant picks a pale primary. Same pattern in `TabFilterBar`,
`AnnouncementBanner`, `EmptyState`, `ConfirmDialog`.

A grep for `contrast|luminance|wcag|readable` returns **only comments**,
several of which explicitly record having hit this problem and worked
around it locally.

### 2.4 A token was borrowed for the wrong job

`ShareLinkModal.vue` (my change, 2026-08-03) uses `var(--nav-text)` as the
label colour on `bg-brand-600` buttons. `--nav-text` is defined as the
text colour *against `--nav-bg`*; nothing guarantees it contrasts with the
primary. It replaced a wrong assumption (`text-white`) with a different
wrong assumption. It happens to look fine on this tenant.

## 3. Proposed model — surface / on-surface pairs

Every background token ships with the foreground token that is guaranteed
readable on it. **A component never picks a text colour; it inherits the
one belonging to the surface it sits on.**

| Surface token | Paired foreground | Used by |
|---|---|---|
| `--surface-app` | `--on-app`, `--on-app-muted` | page background |
| `--surface-card` | `--on-card`, `--on-card-muted` | every card, modal, sheet, drawer, popover, public page |
| `--surface-nav` | `--on-nav`, `--on-nav-muted` | top bar, bottom nav |
| `--surface-primary` | `--on-primary` | primary buttons, active tabs, badges |
| `--surface-chip` | `--on-chip` | neutral pills/counters (replaces `bg-slate-100 text-slate-600`) |
| `--surface-success/warning/danger` | `--on-success/warning/danger` | status pills, toasts, validation |

Exposed as Tailwind utilities so usage is greppable and enforceable:
`bg-card text-on-card`, `bg-primary text-on-primary`, `bg-chip
text-on-chip`, `text-on-card-muted`, …

### 3.1 Auto-derived foreground

```
onColor(bg) → whichever of { tenant ink-dark, tenant ink-light }
              scores higher WCAG contrast against bg,
              rejecting any result below 4.5:1
```

Standard `relativeLuminance` + `(L1+0.05)/(L2+0.05)`. ~30 lines in
`theme/assets.ts`, no dependency.

The chip and semantic surfaces are derived from the **card's** lightness,
not hardcoded: on a dark card, `--surface-chip` becomes a light-alpha
overlay with light ink; on a light card it stays `slate-100` with dark
ink. This is what fixes §2.2 properly — the pill stops being pale on a
dark theme, so `--card-text` no longer lands on it wrongly.

### 3.2 Where it runs

**Frontend, at boot, in the theme store** — one place, no migration, and
the Admin live-preview needs the same function client-side anyway.
(Backend computation would need a second implementation in PHP purely so
the preview could work.)

## 4. Admin settings consequence

The settings screen currently exposes 8 raw hex fields with no feedback.
Proposed:

- Tenant picks **surfaces only** (Primary, Accent, Nav bg, Card bg, App
  bg) — foregrounds are derived and shown read-only.
- Each row displays a live **contrast badge**: `AA 7.2:1 ✓` / `2.1:1 ✗`.
- Manual foreground override stays available, but a failing override
  raises a warning rather than being silently accepted (BR-7: the value
  stays admin-editable, we only surface the consequence).
- Config Health report gains a "theme contrast" flag, alongside the
  existing theme flag from TASK-055 P4.

## 5. Phasing

| Phase | Scope | Note |
|---|---|---|
| **P1** | `contrast.ts` helper + token pairs in `main.css`/`tailwind.config.js` + theme store computes them | Tokens default to today's values → **zero visual change**, safe to merge alone |
| **P2** | Convert the 12 design-system components | `AppButton`, `AppCard`, `HeroHeader`, `TabFilterBar`, `ConfirmDialog`, `FilterSheet`, `EmptyState`, `ShareLinkModal`, `AnnouncementBanner`, `AnnouncementModal`, `BottomNav`, `NotificationBell`. Biggest visual win per file. |
| **P3** | Convert views; every `bg-white*`/`bg-slate-50` surface → `bg-card` | Heaviest: `ClientsView` 113, `RegisterView` 87, `ProfileSettingsView` 74, `AcademyView` 67, `OrdersView` 57 |
| **P4** | Admin contrast badge + Config Health flag | |
| **P5** | Guardrail: CI grep / eslint rule rejecting new `text-slate-*`, `text-white`, `bg-white` under `frontend/src` | Without this, P2–P3 decay |
| **P6** | ag-qa: render each view under 3 tenant themes (light, dark, pale-primary) and assert no pair below 4.5:1 | Definition of Done |

P1 alone is low-risk and unblocks everything else.

## 6. Decisions (human, 2026-08-04)

1. **Derive AND allow override** ("1+2"). Foregrounds are computed from
   the surfaces by WCAG contrast; `card_text_hex` / `nav_text_hex`, when
   set, still win, but the resulting ratio is recorded in the store's
   `contrastAudit` so the Admin screen can show it rather than accepting
   an unreadable choice silently.
2. **Semantic colours adapt to the card's lightness** — implemented as
   `semanticPair()`: tint the card background with the hue, then pick the
   first *tinted* ink that clears AA, falling back to plain ink only if
   none does. Picking the highest-contrast candidate instead always
   returned pure white and threw away the colour signal; that was caught
   in the live check and fixed.
3. **Scope: the whole Agent Portal including public pages** (login,
   payment, share, lead capture). `frontend-admin` is out of scope for now.
4. **Ship P1 + P2 together.**

## 6a. Measured result — P1 + P2, live, current tenant

Tenant: `primary_hex #978A6E`, `card_bg #000000`, `card_text #ffe6c2`.
Ratios read from the running app (`getComputedStyle` on the applied vars):

| Pair | Ratio | |
|---|---|---|
| card | 17.3 | ✓ |
| card-muted | 6.7 | ✓ |
| card-subtle | 4.9 | ✓ |
| chip | 13.0 | ✓ |
| primary | 4.8 | ✓ |
| success | 9.7 | ✓ |
| warning | 10.0 | ✓ |
| danger | 8.5 | ✓ |
| nav | 13.5 | ✓ |

All nine clear AA (4.5:1). Two corrections came out of taking this
measurement rather than trusting the code:

- `--ink-card-subtle` first measured **3.5:1**, because `muteInk`
  defaulted to the WCAG *large-text* floor of 3:1. That token is used for
  11–12px hint and placeholder text, which is not large text — the floor
  is now AA for both muted tiers.
- Semantic inks first came back as pure `255 255 255` for all three (see
  decision 2).

## 7. Consequences

- Positive: one place decides readability; new screens inherit it for
  free; the tenant cannot configure an unreadable app without being told.
- Cost: a large mechanical diff across ~40 files in P2–P3. Mitigated by
  phasing and by P5 preventing regression.
- Risk: converting `bg-white` → `bg-card` changes the look of modals and
  public pages for **every** tenant, not just dark ones. P3 must be
  screenshot-reviewed per view, not swept blindly.
- `ShareLinkModal`'s `--nav-text` workaround is deleted in P2, replaced by
  `text-on-primary`.

## 8. TASK-124 — the input surface (2026-08-07)

Human-reported, screenshot of `/register` under the dark tenant: light-tan
text on a near-white field. Root cause is the model of §3 having one hole
in it — the input was the only surface in the app that never got a pair:

```html
class="... text-ink-card placeholder:text-ink-card-subtle ..."   <!-- and NO bg -->
```

The ink followed the card and correctly flipped light; the box stayed the
browser's default white. Light card → accidentally fine. Dark card →
invisible. Latent in **every** form, not just the public pages.

Four tokens added, derived in `applyContrastTokens()`:

| Token | Derived as |
|---|---|
| `--surface-input` | `mix(cardBg, cardInk, isDark(cardBg) ? 0.12 : 0.06)` |
| `--ink-input` | `pickInk(inputBg)` |
| `--ink-input-placeholder` | `muteInk(inputInk, inputBg, 0.5, AA_CONTRAST)` |
| `--line-input` | `mix(inputBg, inputInk, 0.22)` |

Decisions worth keeping:

1. **The field steps off the CARD, it is not pinned to white.** Pinning
   would "fix" the dark theme by punching a white hole into a black card
   — the same failure §2.2 records for pale chips. A field must be a
   sibling of its card. The step is larger on a dark card because equal
   RGB deltas are far less perceptible at the dark end.
2. **The placeholder is floored at AA, not 3:1** — same call as §6a for
   `--ink-card-subtle`. It is 12–13px text carrying the only hint of what
   to type.
3. **The outline is judged against a 1.5 separation floor, not AA or
   1.4.11's 3:1.** It is not text, and it is no longer the sole marker of
   the field now that `--surface-input` differs from the card. Pinning it
   to 3:1 would roughly double the visual weight of every form in the
   app — a design change, not a legibility fix. It is still recorded in
   `contrastAudit` (entries now carry the `minRatio` they were judged
   against), so a mid-grey card surfaces as a flag instead of silently.
4. **`--input-color-scheme`** (not a colour) flips with the field so the
   browser-drawn parts — `type="time"` glyph, number spinner, autofill
   wash, `<select>` popup — don't vanish inside a dark field.
5. **The guard is a `@layer base` rule**, not only a convention: `input,
   select, textarea` get the pair by default, so a new form is readable
   even if nobody writing it thinks about theming. Tailwind utilities sit
   in a later layer, so a component that genuinely wants another surface
   still overrides it by writing both halves of a real pair.

Measured (node against `contrast.ts`, `--ink-input` / `--ink-input-placeholder`
on `--surface-input`):

| Tenant card | ink | placeholder | before (ink-card on white) |
|---|---|---|---|
| `#000000` + `#ffe6c2` | 17.1 ✓ | 5.2 ✓ | **1.2 ✗** |
| `#ffffff` (default) | 15.8 ✓ | 4.5 ✓ | 17.9 ✓ |
| `#101828` | 12.5 ✓ | 5.0 ✓ | **1.0 ✗** |
| `#767676` (worst case) | 4.9 ✓ | 4.7 ✓ | **1.0 ✗** |
