# TASK-192 — downloadable voucher card image (Agent Portal, `/pay/{token}`)

- **Owner:** ag-lead (spec) → ag-ui (frontend only, no backend change) → ag-qa
- **Date:** 2026-08-17
- **Human:** after fixing the TASK-191-era regression that hid the voucher block entirely (see
  the `2026-08-17 bugfix` comment in `PaymentPageView.vue`), asked *"ผมจะ download voucher เพื่อ
  ส่งลูกค้าอย่างไร หรือจะส่ง email ให้ลูกค้าอย่างไร"*. Existing answer: the 3 TASK-191 share
  buttons already give copy-link / LINE / mailto / QR-PNG-download / native-share, and TASK-190's
  SMTP mail already auto-sends once at `confirmPayment()`. Human confirmed (via AskUserQuestion)
  they specifically want a **new** downloadable voucher **card** — one branded PNG image with
  code + QR + validity together — not just the bare link-QR the share modal already downloads.
- **Related:** ADR-033 §2.4 (one delivery surface — `/pay/{token}` is still the only place voucher
  *data* renders; this task adds a downloadable *artifact* of that same data, not a second
  rendering surface), TASK-189 (voucher fields), TASK-190 (existing email — unaffected, out of
  scope here), TASK-191 (existing share buttons — unaffected, out of scope here).

---

## 1. Scope

**In:** one new "ดาวน์โหลดบัตรกำนัล" button inside the voucher block already on
`frontend/src/views/PaymentPageView.vue` (the block nested under `v-if="isPaid"` /
`v-if="order.voucher"`, fixed earlier today). Clicking it renders a single PNG card client-side
(HTML5 `<canvas>`, same "generate then trigger `<a download>`" pattern `ShareLinkModal.vue`'s
`downloadQr()` already uses — no new dependency) containing:
- Company name (`themeStore` — already loaded on this page, `Theme.company.name`) and logo if one
  is configured (`Theme.logos.nav_url` or `login_url` — fall back to text-only if neither exists,
  never block the download on a missing logo).
- Product name (`order.product_name`).
- The voucher code, large and legible (`order.voucher.code`).
- The QR image already generated on this page (`voucherQrDataUrl` — same source data as what's
  on-screen, `generateQrDataUrl(order.voucher.code, ...)`; do not regenerate from a different
  source — one QR-generation call site stays one).
- Quota (`formatVoucherQuota(order.voucher)`) and expiry (`formatVoucherExpiry(order.voucher)`) —
  reuse the two existing formatter functions verbatim, don't re-derive the strings a second way.
- The status label (`order.voucher.status_label`) only when `status !== 'active'`, matching the
  on-screen conditional.

File name: `voucher-{order.order_number}.png`.

**Out:** any change to `ShareLinkModal.vue`, the 3 TASK-191 share buttons, or TASK-190's email —
all already do what they do and this doesn't touch them. No backend change — every field the card
needs is already in the `PublicOrderResource` payload this page already fetched (confirmed: theme,
product_name, voucher.*, order_number all present, see `PublicOrderResource.php` and this page's
`PublicOrder`/`PublicVoucher` TS interfaces). No admin-side (`frontend-admin`) equivalent in this
task — the human's ask was specifically about this page.

## 2. Implementation notes

- Draw onto an off-screen `<canvas>` (e.g. 600×900 or similar portrait card ratio — ag-ui's call,
  match the visual language already on this page: dark card background, gold/cream accent text
  per the screenshot, rounded corners optional since canvas corners are cosmetic only). Load the
  logo image and the QR data-URL into `Image` objects before drawing (both may already be
  data-URLs/same-origin storage URLs — handle the logo being a remote `Storage::url()` path,
  which needs `crossOrigin = 'anonymous'` on the `Image` before `canvas.toDataURL()` will not
  throw a tainted-canvas security error).
- `canvas.toDataURL('image/png')` → same `<a>`-click-download pattern as `ShareLinkModal.downloadQr()`.
- Button only rendered when `order.voucher` is present (same gate as the block it lives in) —
  never show it for an order with no voucher.
- If the logo fails to load (network error, CORS, or none configured), the card must still render
  and download with company name text only — never let a logo failure block the whole download.

## 3. Verification

- Voucher block still renders identically to before (this task only adds a button + a canvas
  function, doesn't touch the on-screen markup).
- Clicking the new button downloads a PNG containing the same code/quota/expiry/status currently
  on screen for that order (visually verify against a live paid order with a voucher).
- A paid order with no logo configured for its company still downloads successfully (text-only
  card, no thrown error, no blocked button).
- `vue-tsc --noEmit`, `eslint src` (Agent Portal only) clean.

## 4. Definition of Done

CLAUDE.md §9, plus: no backend change, the QR/quota/expiry values on the downloaded card are
byte-identical in meaning to what's already shown on the page (not re-derived a second way), and a
missing/broken logo never blocks the download.
