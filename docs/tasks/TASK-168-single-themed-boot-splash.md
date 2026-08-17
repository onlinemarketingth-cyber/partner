# TASK-168 — one boot splash, in the tenant's colours from the first frame

- **Owner:** ag-lead (implemented directly — ~50 lines across 3 files)
- **Date:** 2026-08-11
- **Human:** *"มี Loading ก่อน 1 ครั้งก่อนที่จะมีการ loading จาก theme config … ให้ loading ขึ้นครั้งเดียวเป็นของ theme ที่ถูก setting จาก admin"*
- **Related:** ADR-018 (per-company theming), TASK-055, TASK-078 (the splash itself)

---

## 1. There was never a second splash — there was one splash with two looks

Only `#app-splash` exists. What the human saw is a **repaint**:

1. `index.html` paints the splash on a hardcoded neutral `#f8fafc`, logo `display:none`.
2. `main.ts` awaits `themeStore.loadPublic()`, then swaps `background`, bar colour and logo in.

Step 2 cannot happen before step 1: the theme comes from `GET /public/theme/{slug}`, an HTTP
request that only starts once the module graph loads. **A theme applied by JS is by
construction a second look, and a second look on a loading screen reads as a second load.**

TASK-078's 3-second floor did not cause this, but it does hold both looks on screen long
enough to be unmistakable.

## 2. Options put to the human (2026-08-11)

| | Approach | Why not |
|---|---|---|
| **A ✅ chosen** | Cache the splash's three values in `localStorage`; an inline script in `index.html` applies them before the first paint | First-ever visit on a device is still neutral (nothing to cache yet) |
| B | Serve `index.html` through Laravel and interpolate the theme server-side | Always correct, including first visit — but ends static hosting of the SPA and contradicts ADR-003. Not worth it for one frame |
| C | Stop swapping; keep one plain neutral splash | Genuinely one loading screen, but not the branded one that was asked for |

Splash duration: **stays at 3000ms** (human decision — TASK-078 unchanged).

## 3. What was built

**`stores/theme.ts` — the writer.** `cacheSplashBoot()` persists
`{slug, bg, bar, logo}` under `sv_splash_boot` after every successful `loadPublic()` and
`loadForMe()`.

**Not** called from `applyResolved()`. That path serves `/p/{token}`, `/pay/{token}` and
`/l/{token}`, where the visitor is a **customer**, not this tenant's agent — §6/BR-6 already
keeps the slug out of their storage, and this would put the same fact back under a new key.

**`index.html` — the reader.** A synchronous IIFE placed **after** `#app-splash` and
**before** `#app`, so it runs during parse with nothing yet painted.

**`main.ts` — the corrector.** The existing post-theme swap stays and is authoritative. It
gained one thing: it now *hides* a logo the live theme no longer has, so a stale cache
corrects in both directions instead of only ever adding.

## 4. The one duplication, and why it is safe

The inline script re-implements `resolveSlug()`'s rule (`?company=` → cached slug) because
it must run before any module loads. Duplicated logic drifting is the most repeated defect
in this repo, so the copy is made **fail-safe rather than fail-wrong**: it compares the slug
it resolved against the slug stored *in the cache entry* and applies nothing on a mismatch.

- disagreement → neutral splash (today's behaviour), never another company's brand
- device that has seen two tenants → cannot paint the wrong one
- `localStorage` unavailable (private mode, quota) → neutral

**If `resolveSlug()` ever gains a third source, this script must follow.** Both comments say so.

## 5. Accepted behaviour

- **First visit on a new device:** neutral splash, once. Nothing is cached yet and only
  option B would fix it.
- **After an admin changes the theme:** one boot still paints the previous colours, then
  self-corrects in `main.ts` and caches the new values for next time.

## 6. Verification

- `vue-tsc`, `eslint src`, `vite build` clean.
- `dist/index.html` checked: the inline script survives the build and sits between the
  splash markup and `#app` — build order is what makes it pre-paint, so it is asserted, not
  assumed.
- **Still to do by hand** (a build proves none of it): load with a themed tenant twice and
  confirm the second load shows no neutral frame; load `?company=` for a *different* tenant
  and confirm it degrades to neutral rather than showing the first tenant's brand; clear
  `localStorage` and confirm the neutral first-run path still works.
