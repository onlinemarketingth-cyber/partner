# TASK-161 — Nav-bar gradient + company theme presets

- **Owner:** ag-lead (spec) → ag-dev (§3) + ag-ui (§4)
- **Date:** 2026-08-11 · **Human decisions:** 2026-08-11
- **Related:** ADR-018 (per-company theming), TASK-055, TASK-098 (surface/ink contrast), TASK-159/160, BR-6, BR-7

---

## 1. What was decided, and what was rejected

Presented three options; the human answered all of them:

| Proposed | Answer |
|---|---|
| Gradient on 4 surfaces | **2 only** — app background (already exists) and nav bar |
| Remove the 2 ink fields, derive them | **Rejected** — the ink pickers stay |
| Colour presets | **Yes, plus "save current colours as a preset"** |
| Preset visibility | **The owning company only** |
| Preset contents | **Colours only** (9 fields + gradient configs) |

**Card gradient is explicitly out.** I raised it as the expensive one: `--card-bg` is stored
as `R G B` channels so `bg-surface-card/95` works, and a gradient cannot carry an
alpha-value suffix. Not doing it means that rewrite never happens. Good.

**The nav bar is cheap, and this is verified, not assumed.** `--nav-bg` is consumed in
exactly two places — `App.vue:106` and `BottomNav.vue:65` — both as
`background: var(--nav-bg)`. Nothing uses `bg-surface-nav` (the channel/alpha form) in any
component; the channels exist only to feed the contrast audit. A `linear-gradient(...)`
string drops straight in.

**Note the consequence of keeping the ink fields (B rejected):** the "easier setup" goal now
rests entirely on presets. That is fine, but it means §4's preset UX is the part that has to
be good — it is no longer a nice-to-have on top of a simplified form.

---

## 2. Ruling — ink over a gradient (ag-lead, architecture not business value)

TASK-098's contrast machinery assumes one flat colour per surface. A gradient nav bar has
two, and the menu text sits across both.

**The ink must be chosen so it is legible at BOTH stops, not at their average.** Averaging
is what produces a bar that is readable in the middle and unreadable at one end.

Algorithm, exactly:

```
for each candidate ink in {black, white}:
    score = min(contrast(ink, stop1), contrast(ink, stop2))
pick the candidate with the higher score
```

`--surface-nav` (the channels the audit reads) is set to **whichever stop scored worse
against the chosen ink**, so `contrastAudit` reports the true worst case rather than a
flattering one. A gradient must never be able to improve its own audit score by averaging.

This applies only where the admin has NOT set `nav_text_hex` explicitly — that field
survives (decision B) and an explicit choice still wins. When it is set and fails at one
stop, the audit must say so; it must not silently override the admin.

---

## 3. ag-dev — schema + API

### 3.1 Nav gradient

`nav_bg_hex` is `string(7)` and cannot hold a gradient. **Mirror the shape the app
background already uses** (`background_type` / `background_config`) rather than inventing a
second convention:

- `nav_bg_type` — nullable string, `'solid'` | `'gradient'`; null/absent behaves as solid
- `nav_bg_config` — nullable json, `{ color1, color2, angle }`

`nav_bg_hex` stays and remains the solid value. **Every existing row keeps working with no
data migration** — that is the point of making the new column nullable rather than
rewriting the old one.

- Validate in the Form Request: `color1`/`color2` as hex, `angle` 0–360 integer. Reject a
  `gradient` type with a missing stop rather than silently falling back.
- `ThemeResource` exposes `nav_bg_type` and `nav_bg_config` alongside the existing
  `nav_bg_hex`.

### 3.2 Presets

New table `theme_presets`:

- `id`, `company_id` (FK, constrained, indexed), `name`, `colors` (json), `created_by`
  (nullable FK users), timestamps
- **`TenantScope` on the model** (BR-6, CLAUDE.md §5 rule 1/2 — it is a business table, it
  gets `company_id` and the global scope; no exceptions for "it's only colours")
- `ThemePresetPolicy` — Company Admin manages their own company's; **an Agent has no access
  at all**, this is admin config

`colors` holds **only** the colour surface: `primary_hex`, `accent_hex`, `nav_bg_hex`,
`nav_bg_type`, `nav_bg_config`, `nav_text_hex`, `nav_active_hex`, `card_bg_hex`,
`card_text_hex`, `card_border_hex`, `card_shadow`, `background_type`, `background_config`.

**Explicitly NOT in a preset:** logos, favicon, fonts, labels, `nav_icon_overrides`,
`recommended_slot_count`, `background_image_path`. Those are a company's identity or its
configuration, not a "look" — a preset that carried a logo would put one tenant's mark on
another's screen the moment presets ever became shareable.

Endpoints (Company Admin only):

- `GET /api/v1/theme-presets` — list own company's
- `POST /api/v1/theme-presets` — save the company's CURRENT colours under a name. The
  server reads the values from `company_theme_settings`; **it does not accept a colour
  payload from the client** (a client-supplied blob is a way to write values that never
  passed the field validation).
- `POST /api/v1/theme-presets/{preset}/apply` — copy the preset into
  `company_theme_settings` in ONE transaction. Partially-applied is worse than not applied.
- `PUT` (rename) and `DELETE`

### Acceptance criteria

- [ ] Existing rows render identically with no migration step (nullable columns)
- [ ] A gradient nav bar validates both stops; a half-specified gradient is a 422
- [ ] Presets are tenant-isolated: company A cannot list, apply, rename or delete company
      B's — **test all four verbs, not just list** (a read-only isolation test is the one
      that passes while `apply` leaks)
- [ ] An Agent gets 403 on every preset route
- [ ] `apply` is transactional
- [ ] `php artisan test` + `pint --test` clean

## 4. ag-ui — Admin screen

- **Nav bar colour** gains a solid/gradient switch, matching the app-background control that
  already exists on the same screen. Reuse that component if it is one; do not build a
  second gradient picker with different ergonomics.
- **Presets section** on `ThemeSettingsView`:
  - list of the company's saved presets, each with a small colour-swatch preview
  - "บันทึกสีปัจจุบันเป็นชุด" — name it, save
  - apply (with a confirm — it overwrites every colour field), rename, delete
  - empty state that says what the button does, not "ยังไม่มีข้อมูล"
- The live preview must reflect a gradient nav bar before saving.
- `vue-tsc` + `eslint` + `vite build` clean.

---

## 5. Resolved (human, 2026-08-11) — seeding and Super Admin

Both open items were answered. Neither invents a business value, so neither is BR-7.

### 5.1 Every company gets one preset, and it is a copy

**Decision: take the recommendation.** A company gets a preset named **"ค่าเริ่มต้น"**
holding a snapshot of the theme it already has.

- **On creation** — inside `CompanyService::create()`'s existing transaction, alongside
  `PipelineTemplateProvisioner`. Same place, same reason: a company that exists but is
  missing a piece of its own scaffolding looks perfectly healthy right up until someone
  needs it.
- **For companies that already exist** — a one-time backfill in the migration. Additive
  and idempotent (skip a company that already has a preset by that name). Without it only
  *future* tenants benefit, and the tenants actually using the system keep the empty list
  this task exists to remove.

**Snapshot the RESOLVED values, not the raw nullable columns.** A company with no
`company_theme_settings` row would otherwise store a preset of nulls: its swatches would
render blank and applying it would be a no-op that looks broken. Resolved means whatever
`ThemeResource` would emit, defaults filled in — the preset is then self-describing and
applying it is deterministic.

No designed palettes are invented. If "โทนทอง" / "โทนน้ำเงิน" starter sets are wanted
later, those hex values are BR-7 and must come from the human.

### 5.2 Super Admin gets access — scoped to one company at a time

**Decision: yes.** But *how* matters, because a Super Admin is exempt from `TenantScope`
(CLAUDE.md §5.4), so an unscoped list would return every company's presets in one
undifferentiated pile — useless to read and dangerous to act on.

**Reuse the `effectiveCompanyId()` pattern** the codebase already uses for exactly this
(see `AcademyProgressSummaryRequest`). A Super Admin works *inside* one company's context;
they do not get a cross-company view.

- The company id is resolved in the Form Request and **validated**. For a Company Admin it
  is their own and any supplied value is ignored. For a Super Admin it is required and must
  name a real company.
- Because Super Admin bypasses `TenantScope`, a stray or mistyped id would act on the wrong
  company **silently**. Validation here is not politeness; it is the only thing standing in
  the way (same reasoning as `ModuleOrderService`'s second check, TASK-151).
- `apply` must write the theme of **the same resolved company** the preset belongs to.
  Reading preset from company A and writing settings to company B must not be expressible.

**Explicitly still NOT allowed:** applying one company's preset to another. The human ruled
on 2026-08-11 that a saved preset is visible to its owning company only; giving Super Admin
a cross-company palette library would reverse that decision through the back door. If that
is ever wanted it is a new decision, not an implementation detail.
