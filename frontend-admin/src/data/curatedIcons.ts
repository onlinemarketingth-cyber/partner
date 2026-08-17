/**
 * Curated icon-name whitelist shared by IconPicker.vue's default choice
 * grid (TASK-069 / ADR-020). Kept in its own module rather than exported
 * from IconPicker.vue's `<script setup>` — oxlint's
 * `no-export-in-script-setup` rule forbids named exports there even
 * though the Vue SFC compiler itself allows them, and this codebase's
 * `npm run lint` chain runs oxlint alongside eslint (see
 * frontend-admin/package.json `lint:*` scripts).
 *
 * DELIBERATE mirror of the backend's App\Support\CuratedIcons::WHITELIST
 * (TASK-068 / ADR-020 row 3) — keep both in sync by hand (no shared
 * package between Vue and PHP yet, CLAUDE.md §7). Same precedent as
 * src/data/fontCatalog.ts for a plain curated-list data module.
 */
export const CURATED_ICON_CHOICES: string[] = [
  // TASK-089 (2026-08-03, human: "หมวดมีไอคอนซ้ำกันอยู่") — three names in
  // the original 24 render the IDENTICAL glyph, because Icon.vue maps them
  // to the same SVG path:
  //     dashboard  == home
  //     dollar     == money
  //     bar_chart  == chart
  // Verified by comparing the path strings, not by eye. In a picker a
  // duplicate is worse than a missing icon: it looks like a rendering bug
  // and it makes two cells indistinguishable, so the admin cannot tell
  // which one they picked. The alias of each pair is dropped here; the
  // shorter, more generic name is kept.
  //
  // The BACKEND whitelist (App\Support\CuratedIcons::WHITELIST) keeps all
  // 24 on purpose, so a category saved earlier with 'dashboard' still
  // passes validation on its next save. This list being a subset of the
  // backend's is safe; the reverse would not be.
  'home', 'users', 'contact', 'user', 'user_plus',
  'brain', 'book', 'template', 'trophy', 'star', 'sparkles',
  'money', 'chart', 'pie_chart', 'receipt',
  'bell', 'calendar', 'shield', 'flag', 'box', 'layout',
]
