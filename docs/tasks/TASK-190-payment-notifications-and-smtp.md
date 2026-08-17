# TASK-190 — Agent notification on payment confirm + admin-configurable SMTP

- **Owner:** ag-lead (spec) → ag-dev (backend) → ag-ui (frontend) → ag-qa
- **Date:** 2026-08-16
- **Human:** chose "A" (in-app agent notification) + "B, SMTP only" (real customer email) as the
  follow-up to TASK-189 (nothing currently alerts anyone that a voucher exists). Provided a
  reference screenshot of an SMTP settings screen from another project and said "ใช้ตัวนี้เลย" —
  taken as: build an equivalent admin-configurable settings screen in this app (not a `.env`-only
  edit), seeded with the values shown (host `smtp.hostinger.com`, port 465, SSL, username
  `noreply@syncvision.io`, sender name `SyncVision CRM`, enabled).
- **Related:** ADR-033, TASK-189, CLAUDE.md §6 (secrets, audit), §7 (layering), BR-7.

---

## 1. Why this isn't a `.env` edit

Every other piece of operational config in this system — theme, commission rates, gamification
rules, video processing, announcements — is DB-backed and Super-Admin-editable, never a value only
a developer can change (BR-7, CLAUDE.md §8 rule 2). SMTP credentials are the same kind of value.
The screenshot the human sent is exactly that pattern already in use elsewhere; this task builds
the equivalent for this app rather than hardcoding `.env`, which would be the same class of defect
this codebase has spent this whole sprint removing.

**Secrets still never enter git.** The screenshot's actual password is entered through the new
screen once it exists (or inserted directly into the running database by ag-dev, never into a
migration/seeder file) — CLAUDE.md §6's "`.env` only, never committed" rule extends to "never in a
versioned seeder" for the DB-backed equivalent.

## 2. Scope

**In:** one platform-wide (not per-company) `platform_mail_settings` row, Super-Admin-only read/
write; a Mailable sent to the customer when `SubmitSlipRequest`'s... no — when
`OrderService::confirmPayment()` succeeds, if the client has an email and mail is enabled; an
in-app `Notification` to the referral's agent on the same event, unconditional.

**Out:** per-company SMTP (ADR-027 already parked the equivalent for payment gateways — same
reasoning, a later task if ever needed), any channel besides SMTP (LINE OA, SMS — explicitly
deferred by the human's "B เฉพาะ SMTP พอ"), a queued mail pipeline (see §4 — queue worker isn't
guaranteed running per ADR-004, so this sends synchronously).

## 3. Phase 1 (ag-dev) — settings table + Super-Admin API

**1.1** Migration: `platform_mail_settings` — single row (no `company_id`; this is platform-level,
avoiding the exact null-`company_id`-for-Super-Admin defect already open as task #583 for
`video_processing_settings`). Columns: `smtp_host`, `smtp_port` (unsigned int), `encryption`
(string: `ssl`/`tls`/`none`), `username`, `password` (**`encrypted` cast**, same treatment as
`users.bank_account_number` — TASK-044), `from_address`, `from_name`, `is_enabled` (bool, default
false — fail closed, ADR-032 §2.5's rule applies here too: no configured mail means no mail
attempted, not a crash), timestamps.

**1.2** `Ability::SettingsMailUpdate` (new case, provenance docblock says "new, no prior call
site" — same honesty as `Ability::VoucherRedeem`'s own docblock in TASK-189). Grant to
`UserRole::SuperAdmin` only in `PermissionResolver::ROLE_ABILITIES` — not Company Admin; this is
platform infrastructure, not tenant config.

**1.3** `PlatformMailSettingService` — `get()` (returns masked: password never returned in plain,
same "•••• + reveal" pattern `AgentCommissionSummaryController`'s bank-unmask endpoint already
uses) and `update()` (writes an `audit_logs` row, `platform_mail_settings.updated`, **never
including the password value itself** — same rule as TASK-183's password-reset audit).

**1.4** Controller + routes, gated by `Gate::authorize(Ability::SettingsMailUpdate)`: `GET/PUT
/api/v1/platform/mail-settings`.

**1.5** Wire the DB settings into Laravel's actual mail sending. Simplest correct approach: a
`MailSettingsService::applyRuntimeConfig()` called once per request lifecycle (e.g.
`AppServiceProvider::boot()`, cached briefly to avoid a query per request) that, when
`is_enabled`, calls `config(['mail.mailers.smtp' => [...], 'mail.from' => [...]])` from the DB row
— overriding whatever `.env` has. When `is_enabled` is false, leave `.env`'s existing
`MAIL_MAILER=log` behavior untouched (current safe default, matches what the earlier audit already
found and expected).

## 4. Phase 2 (ag-dev) — the two notifications

**4.1** `Notification` to the agent — `NotificationType::OrderPaymentConfirmed` (new case,
Thai `label()` following the enum's existing convention), fired from
`OrderService::confirmPayment()` **inside the existing `DB::transaction`**, same place voucher
issuance (TASK-189 B1) already sits, gated by the same `! $alreadyClosed` idempotency flag — a
plain DB row write, no external call, safe inside the transaction. Title/body/link follow the
`CommissionPaid` precedent (`CommissionLedgerController::markPaid()`) — link to wherever the agent
can see this order (find the existing Agent Portal order-detail route).

**4.2** `OrderPaymentConfirmedMail` (new Mailable, `app/Mail/` — this directory does not exist yet,
first Mailable in the whole app). Content: short Thai message + the `/pay/{token}` link only — do
**not** re-render voucher/QR details inside the email body (ADR-033 §2.4's "one delivery surface"
reasoning: the email is a notification that something is ready, not a second place the voucher is
rendered, which would drift from the page the moment either one changes).

**4.3** Sent from the Controller/caller **after** `confirmPayment()`'s transaction has committed —
never inside it (a slow or failing SMTP call must not hold the DB transaction open, and a
transaction rollback must never have already sent an email). Wrapped in try/catch; a mail failure
is logged and never surfaces as an error to the Admin confirming payment. Only attempted when
`order.client.email` is present and `platform_mail_settings.is_enabled` — silently skipped
otherwise (4.1's in-app notification is the guaranteed path either way).

**4.4** **Synchronous, not `ShouldQueue`.** ADR-004 already flags that a `queue:work` process
isn't guaranteed running in every environment; queuing this mail risks it silently never sending
with no visible failure. Trade-off, stated plainly: the confirm-payment request may take slightly
longer while SMTP responds. Flag in the PR if this turns out to add noticeable latency in practice.

## 5. Phase 3 (ag-ui, Admin) — the settings screen

New screen, Super-Admin-only route (mirror how `/companies` or similar Super-Admin-only screens
are gated today). Fields: SMTP host, port, encryption (select), username, password (masked input
with the existing eye-icon reveal pattern — TASK-081 precedent), sender name, an
enable/disable toggle, save button. Match this app's existing card/icon/input conventions (same
family of components already used across `ProductEditView`, settings screens elsewhere in
`frontend-admin`) — do not introduce a new visual style for one screen.

## 6. Verification

- Super Admin only: Company Admin and Agent both denied (403) on both settings endpoints.
- Password never appears in plain in any API response; audit row never contains it.
- Mutation-check the `! $alreadyClosed` guard around the new notification (same proof style as
  TASK-189's voucher-issued-exactly-once test) — no duplicate notification on a re-confirm.
- A test proving mail sending is skipped (not attempted, not errored) when `is_enabled = false` or
  `client.email` is null.
- A test proving a mail-send exception does not roll back or fail the payment confirmation.
- `pint`, `vue-tsc --noEmit`, `eslint src` (frontend-admin only — this task has no Agent Portal
  changes).

## 7. Definition of Done

CLAUDE.md §9, plus: SMTP is a real Super-Admin-editable setting (not `.env`-only), the password is
encrypted at rest and never logged/returned/audited in plain, the agent notification and the
customer email both trace to the same `confirmPayment()` event without duplicating on re-confirm,
and no secret value from this task was written into any versioned file.
