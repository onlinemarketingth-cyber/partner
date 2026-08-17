# TASK-166 — Certificate PDF: a real feature, not a stub

- **Status:** **PARKED** — button hidden in the Agent Portal, work deferred (human, 2026-08-11)
- **Owner when resumed:** ag-lead → ag-dev + ag-ui
- **Human:** *"download เอกสารนี้ยังไม่มีระบบทั้ง frontend และ Admin เลยนิครับ"* → *"ซ่อนตัวระบบนี้จาก frontend ก่อน เก็บไว้พัฒนาในภายหลัง"*
- **Related:** ADR-018 (theming), BR-1, BR-7, CLAUDE.md §6 (PDPA), §3 (no Blade)

---

## 1. What actually exists today (surveyed, not assumed)

| Piece | State |
|---|---|
| Download button, Agent Portal | Existed and worked — **now hidden** |
| `GET /user-certifications/{id}/download` + `UserCertificationPolicy::view` | Correct, tenant- and owner-scoped. Left live. |
| `CertificatePdfService` | 95 lines. A hardcoded HTML string. |
| Admin configuration | **None.** No table, no screen, no field. |

The certificate reads, verbatim and unchangeably: *"Certificate of Completion"*, *"This is
to certify that"*, *"Issued automatically by Sync Vision Agent"*. A company cannot change a
word of it, cannot put its logo on it, cannot sign it. It does not even use the brand
colours the theme system already stores.

`dompdf` is installed and the service deliberately avoids Blade (CLAUDE.md §3 forbids Blade
in this API) — that part is sound.

## 2. The finding that decides the order of work

```php
font-family: "DejaVu Sans"
```

**DejaVu carries no Thai glyphs.** The repo contains no `.ttf`, no `config/dompdf.php`, and
registers no font anywhere. A Thai agent name or company name is therefore very likely to
render as blank space or tofu boxes.

**This is UNPROVEN** — nobody has generated a certificate for a Thai name; the screenshot
that prompted this only showed English tier names (Basic / Intermediate / High), which is
why it looked fine. **Prove it before designing anything.** A certificate that cannot print
the holder's name is not a design problem.

It is also the reason hiding the button beats leaving it: a certificate is a document an
agent shows to other people. A broken one is worse than an absent one.

## 3. Plan when resumed — three phases, in this order

### Phase 1 — make it print Thai (blocking, no way around it)

Embed a Thai face (Sarabun or Kanit — the app already uses Kanit), add `config/dompdf.php`,
register the font, and test with a real Thai name end to end.

**Fallback if dompdf cannot be made to behave:** Browsershot / headless Chrome. Far better
output, but it means installing Chrome on Hostinger — an infrastructure decision that needs
the human, not a library swap.

### Phase 2 — give the company control

A `certificate_settings` table, per company (BR-6: `company_id`, `TenantScope`, Policy):

- logo, signature image, signer name + title
- heading and body wording, editable in Thai and English
- paper orientation
- brand colours pulled from the existing theme rather than re-entered

Plus the admin screen, with a live preview. **Every one of those strings is BR-7** — ship
them as empty, admin-filled fields; do not seed wording.

### Phase 3 — make it verifiable

Certificate number (running, per company), QR code, and a public verification page that
resolves a number to "issued / not issued".

Only worth building if the certificate leaves the building — see §4 question 1.

## 4. Open questions — needed before any spec is written

1. **Who is the certificate for?** Kept by the agent / shown to customers / submitted to a
   regulator? This decides whether Phase 3 is essential or ornamental.
2. **What goes on it** — wording, signer, seal. BR-7. My recommendation is to invent
   nothing and make every one of them an admin-filled field.
3. **All three phases, or 1 + 2 first?**

## 5. State of the parked code

- `frontend/src/views/AcademyView.vue` — the button block is commented out in place, with
  the reasoning beside it. `downloadCertificate()` and `downloadingCertId` are untouched, so
  restoring is un-commenting.
- The certification LIST is deliberately still visible. It is real data on the BR-1 path —
  the agent should still see what they passed and when. Only the file download is parked.
- The endpoint stays live: it is self-scoped and Policy-protected, so leaving it costs
  nothing, and deleting it would only have to be re-added.
