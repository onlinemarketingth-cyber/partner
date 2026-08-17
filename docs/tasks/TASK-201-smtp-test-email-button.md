# TASK-201 — "ทดสอบส่งอีเมล" button on the SMTP settings screen

- **Owner:** ag-dev (backend) → ag-ui (frontend-admin)
- **Date:** 2026-08-17
- **Goal:** human wants to verify their SMTP credentials actually work from local (MAMP) before
  relying on them, without faking a whole registration/order flow just to trigger a real
  notification email. TASK-190 built the `platform_mail_settings` screen with only a Save button —
  no way to test. Human confirmed (AskUserQuestion, 2026-08-17): build a real "ทดสอบส่งอีเมล" button.
- **Related:** TASK-190 (the feature this extends — `platform_mail_settings` table,
  `MailSettingsService::applyRuntimeConfig()`, `Ability::SettingsMailUpdate`, the
  `OrderPaymentConfirmedMail`/`VerifyRegistrationEmailNotification` "don't queue anything the user
  is actively waiting on" precedent this must also follow).

## Design decisions (stated, not guessed — flag if wrong)

- **Tests the SAVED (persisted) config, not unsaved in-progress form edits.** Simpler and
  unambiguous: `MailSettingsService::applyRuntimeConfig()` already reads the persisted row every
  request via `AppServiceProvider::boot()`, so the test endpoint rides the same already-applied
  config rather than needing a second "apply these unsaved values" code path. If the admin has
  unsaved edits in the form, the frontend should make it obvious the test uses what's currently
  saved (e.g. disable Test while the form is dirty, or a short note) — ag-ui's call on exact UX,
  but the test must never silently use un-persisted values.
- **Requires `is_enabled = true` on the saved row.** `applyRuntimeConfig()` is fail-closed — it does
  nothing at all when `is_enabled` is false, so `mail.default` stays `log` and a "successful" test
  send in that state would be a lie (it went to the log, not real SMTP). The test endpoint must
  check this itself and return a clear error rather than a false-positive success.
- **Synchronous, not queued** — same reasoning as the `VerifyRegistrationEmailNotification` fix
  earlier today and `OrderPaymentConfirmedMail`'s existing docblock: the admin is actively watching
  for the result, so queuing risks "nothing happens, no error" if no `queue:work` is running.
- **Real SMTP errors must surface to the admin, not be swallowed.** The entire point of this
  feature is catching a bad host/port/credential BEFORE it silently breaks real notification
  emails — a connection/auth failure from the mail transport should come back as a clear error
  message (translate/wrap it in Thai, but keep the underlying reason legible — "host not found",
  "auth failed", etc. — don't just say "เกิดข้อผิดพลาด").

## Backend (ag-dev)

**Route:** `POST /platform/mail-settings/test`, same auth group as the existing
`GET`/`PUT /platform/mail-settings` (see `routes/api.php` ~line 869-870).

**Request:** a `SendTestMailRequest` Form Request. `authorize()`: same ability as update —
`Ability::SettingsMailUpdate` (mirror `UpdatePlatformMailSettingRequest::authorize()` exactly, Super
Admin only, same reasoning: testing SMTP credentials is an admin action, not a general-read one).
Validates `to` — required, `email`. Frontend should default this field to the logged-in admin's own
email (convenience), but the backend must still validate it's present and a valid email — never
assume/derive it server-side.

**Service — `MailSettingsService` (or `PlatformMailSettingService`, ag-dev's call on which existing
service this belongs in, given `MailSettingsService::applyRuntimeConfig()` already owns the
runtime-config concern):** new method, e.g. `sendTest(string $to, User $actor): void`.
1. Read the persisted `PlatformMailSetting` row (reuse `PlatformMailSettingService`'s existing
   cached `row()`/equivalent if convenient, or a fresh query — no need to invent a second cache
   path if one already exists).
2. If no row, or `is_enabled` is false: throw a clear exception (e.g. a `ValidationException` on
   the `to` field, or a dedicated exception the Controller catches) with message: "กรุณาเปิดใช้งาน
   และบันทึกการตั้งค่า SMTP ก่อนทดสอบส่งอีเมล" — do NOT attempt to send in this state.
3. Otherwise, build and send a small plain test `Mailable` (new `App\Mail\SmtpTestMail` or similar
   — subject e.g. "ทดสอบการตั้งค่า SMTP - Sync Vision Agent", short body confirming this is a test,
   maybe echoing `from_name`/`from_address` so the admin can visually confirm which config sent
   it). Send synchronously via `Mail::to($to)->send(...)` — NOT `Notification`, since this has no
   `User` recipient semantics, and NOT queued (no `ShouldQueue`).
4. Let transport exceptions (`Symfony\Component\Mailer\Exception\TransportExceptionInterface` or
   its Laravel wrapper) propagate up rather than swallowing them — the Controller catches and
   translates to a 422 with the exception's message included (safe to expose: it's connection/auth
   diagnostics, not secrets — the SMTP password itself is never part of a transport exception
   message).
5. On success, write an `AuditLog` row (same shape/reasoning as `PlatformMailSettingService::update()`'s
   own audit entry — Section 6/CLAUDE.md §8 rule 5, this is an action affecting the mail
   configuration surface) — action e.g. `platform_mail_settings.test_sent`, note the `to` address
   in `new_values` (or a dedicated field), no `company_id` (platform-level, same as the rest of this
   feature).

**Controller:** new `test(SendTestMailRequest $request, ...$service)` method on
`PlatformMailSettingController`. Catch the "not enabled" case and any transport exception, return
`response()->json(['message' => ...], 422)`. On success, `response()->json(['message' => 'ส่งอีเมล
ทดสอบสำเร็จ'], 200)` (exact copy ag-dev's call, just needs to be unambiguous success vs failure for
the frontend to key off of).

**Tests:** feature test(s) covering: enabled + valid SMTP config → 200 (mock/fake the mail
transport — do not attempt a real outbound SMTP connection in the test suite, use Laravel's
`Mail::fake()` and assert `Mail::assertSent(SmtpTestMail::class, ...)`, or fake the transport layer
however this codebase's existing precedent does for other outbound-call tests); disabled row → 422
with the specific message; non-Super-Admin → 403 (mirrors the existing `update()` authorization
test for this same ability); tenant/company isolation is not applicable here (platform-level, no
`company_id`, matching the rest of this feature) — no need to write an isolation test for that
reason, same as TASK-190's own tests.

## Frontend (ag-ui, `frontend-admin/src/views/MailSettingsView.vue`)

- Add a "ที่อยู่อีเมลสำหรับทดสอบ" input, defaulting to the logged-in admin's own email (read from
  the auth store, however this app already exposes the current user — same pattern any other
  screen in this app already uses to get "my own email").
- Add a "ทดสอบส่งอีเมล" button next to/near the existing Save button, calling
  `POST /platform/mail-settings/test`. Loading state while in flight (`testingMail` ref or similar,
  following this file's existing `savingSettings`-style naming). On success, a clear inline
  success message ("ส่งอีเมลทดสอบสำเร็จ ไปที่ {to} แล้ว — กรุณาตรวจสอบกล่องจดหมาย"). On failure, show
  the real error message returned by the backend (don't generic it away — the whole point is
  actionable diagnostics).
- Per the "tests the SAVED config" decision above: disable the Test button while the form has
  unsaved changes (compare current form state to last-loaded/saved state — if this view doesn't
  already track dirty state, a simple JSON-stringify comparison against the last-saved snapshot is
  fine), with a short inline note like "บันทึกการตั้งค่าก่อน จึงจะทดสอบด้วยค่าล่าสุดได้" so it's clear
  why the button is disabled rather than just leaving it silently unresponsive.
- If `is_enabled` is currently off in the form/saved state, the Test button can still be clickable
  (backend will return the clear 422 either way) — no need to duplicate that guard client-side,
  keep the single source of truth on the backend per this project's general pattern (client-side
  checks are a UX nicety, never the only gate).

**Verification:** `vue-tsc --noEmit`, `eslint src`. Attempt a real `php artisan test` run for the
backend feature tests if a working PHP toolchain is available in the dispatch's sandbox (recent
sessions have had one); if not, honest structural review instead of a claimed test run.

## Definition of Done

CLAUDE.md §9, plus: no test send is ever silently queued or silently mistaken for success when
`is_enabled` is off, the SMTP password is never echoed back in any response/error message, and the
audit log records who tested and when (Section 6).
