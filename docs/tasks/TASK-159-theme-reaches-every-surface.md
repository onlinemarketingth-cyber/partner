# TASK-159 — The company theme must reach every surface, including the ones customers see

- **Owner:** ag-lead (spec) → ag-dev (§3) + ag-ui (§4)
- **Date:** 2026-08-11 · **Human:** *"สี background หลายจุดรวมถึงแชร์ไปหาลูกค้าต่างๆ นอกระบบ พื้นที่ว่างนอก card ปรับเป็นสีเดียวกับค่า setting ของ admin company"*
- **Related:** ADR-018 (per-company theming), TASK-055, TASK-065, TASK-098 (surface/ink tokens), BR-6

---

## 1. Two independent causes, both verified in the code

The complaint reads as one bug. It is two, and they need different fixes.

### 1.1 `bg-surface-app` is computed and painted nowhere

`stores/theme.ts` derives the page background properly — `appBackgroundColor()` handles
solid and gradient, `--surface-app` / `--ink-app` / `--ink-app-muted` are all `set()`, and
`tailwind.config.js` exposes `surface.app` and `ink.app`.

**`text-ink-app` is used** (HomeView and ProductBrowseView headings). **`bg-surface-app` is
used in zero files.** The background layer in `App.vue` paints `bg-surface-chip` —
`--surface-chip`, which `assets/main.css:128` hardcodes to slate-100 — with the company
background only layered on top **as an inline style, and only when
`background.type` is set**. `companyBackgroundStyle` returns `{}` otherwise.

So a company that configured its brand colours but never picked a background *type* gets
themed text on an unthemed slate surface. The ink is the company's; the paper is not.

### 1.2 A public share link loads no theme at all

```php
async function loadPublic(): Promise<void> {
  const slug = resolveSlug()
  if (!slug) return          // ← every public page with no slug in scope
  ...
}
```

`resolveSlug()` reads the company from the hostname or `?company=`. A share link handed to
a customer — `/p/{token}`, the payment page, `/l/{token}` — carries **neither**. So
`loadPublic()` returns before it fetches anything and the page renders on platform
defaults. `LoginView` is themed only because TASK-065/TASK-063 mint its links *with* the
slug.

**This is the half the human actually named**, and it is the one that matters most: it is
the only surface a paying customer ever sees.

**Root cause, stated so the fix is not another slug guess:** the token IS the tenant.
`ProductShareLink`, the order/payment page and `AffiliateLink` all resolve to exactly one
company server-side. Deriving the tenant from the hostname when the URL already carries a
token that names it is the mistake.

---

## 2. Decisions (ag-lead)

1. **The theme follows the token, not the hostname.** Public resolver endpoints return the
   theme of the company that owns the token. No new slug in the URL — a share link must
   stay short, and a customer-visible `?company=` is a tenant enumeration hint we do not
   need to hand out.
2. **`bg-surface-app` becomes the page surface.** It already falls back to the card colour
   when no background is configured (`appBackgroundColor() ?? cardBg`), which is the right
   default: a dark-carded tenant gets a dark page, not slate-100.
3. **The image/gradient layer stays on top, unchanged.** §1.1 is about what shows when
   there is no image, not about replacing the image feature.
4. **No new theme columns.** Everything needed is already on `company_theme_settings`
   (BR-7 — this is plumbing, not a business value).

---

## 3. ag-dev — the public payload carries the theme

**Goal:** a public page can theme itself from the token it already has.

**Endpoints in scope** (all already exist and already resolve one company):

- `GET /api/v1/public/product-shares/{token}` (`PublicProductShareController::show`)
- the public order/payment page resolver (see `PaymentPageView.vue`'s fetch)
- `GET /api/v1/public/affiliate-links/{token}` (the `/l/{token}` context endpoint)

**Change:** add a `theme` key to each response, serialised with the **existing**
`ThemeResource` for the owning company, so there is one shape and one place to change.

### Acceptance criteria

- [ ] Each of the three responses carries `theme`, identical in shape to
      `GET /public/theme/{slug}`
- [ ] It is the theme of **the token's own company**, resolved server-side — never from a
      request parameter (BR-6: a client-supplied company id here would be a cross-tenant
      read)
- [ ] A company with no `company_theme_settings` row returns the same defaults
      `ThemeResource` already produces, not null
- [ ] **No credential, no `skey_*`, no internal id beyond what these endpoints already
      expose.** `ThemeResource` is presentational; confirm nothing new leaks through it
- [ ] Feature tests: two companies, two share links, each returns its own theme; and a
      revoked/expired token still 404s before any theme is emitted
- [ ] `php artisan test` + `pint --test` clean

### Out of scope

- Any new column, any new endpoint, the admin theme editor.

---

## 4. ag-ui — paint with it

**Goal:** the empty area outside cards is the company's colour, in the app and on the
public pages.

### 4.1 App shell

- `App.vue`'s background layer: `bg-surface-chip` → **`bg-surface-app`**. Read the comment
  above that div first — the `showChrome` branch deliberately constrains the layer to the
  phone column and lets the body fill the desktop margins; that behaviour stays.
- Sweep `/frontend/src` for other page-level backgrounds that hardcode a neutral
  (`bg-slate-50`, `bg-surface-chip` used as a PAGE background rather than as a chip/inset).
  A chip inside a card is correct and must be left alone — only page surfaces change.

### 4.2 Public pages

`ProductShareView.vue`, `PaymentPageView.vue`, `AffiliateLeadCaptureView.vue` currently
reference the theme store zero times.

- After the token fetch resolves, hand `response.theme` to the theme store and apply it,
  so every `--surface-*` / `--ink-*` var is the owning company's before the page paints
  content.
- **Apply before first paint of the branded content, not after.** A customer seeing the
  platform's slate flash into the company's brand is worse than a beat of skeleton.
- These pages stay full-bleed (they are not the phone shell).

### Acceptance criteria

- [ ] `bg-surface-app` is what paints the page surface; `--surface-chip` is used only for
      chips/insets inside cards
- [ ] A tenant with brand colours but **no background type** gets a page surface that
      matches its cards, not slate-100
- [ ] The three public pages render in the owning company's colours, logo and font with no
      slug in the URL
- [ ] No flash of platform default on the public pages
- [ ] Contrast: run the existing `contrastAudit` and confirm `--ink-app` on `--surface-app`
      still passes — this is the pair TASK-098 exists to protect, and it is now load-bearing
- [ ] `vue-tsc` + `eslint` + `vite build` clean on `/frontend`

### Out of scope

- `/frontend-admin` (admins are not customers; its chrome is deliberately neutral)
- Any change to the theme editor UI

---

## 5. Note for whoever picks this up

The screenshot that prompted this shows a dark tenant where the outside-card area *looks*
themed. Do not conclude from one screenshot that §1.1 is cosmetic: that tenant has a
background type set, which is the only reason it looks right. Test with a tenant that has
brand colours and **no** background configured — that is the broken case, and it is the
common one.
