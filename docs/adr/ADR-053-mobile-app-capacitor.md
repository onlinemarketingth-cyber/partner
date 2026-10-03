# ADR-053 — The agent portal ships as an iOS/Android app through Capacitor

- Status: Accepted (owner, 2026-10-02)
- Scope: frontend (portal), backend (push, device tokens, version policy, account
  deletion), frontend-admin (two new screens). The web portal is unchanged.

## Context

Owner, 2026-10-02: plan the portal as a mobile app for Android and iOS, reusing the
existing HTML/Vue front end, and state the technical limits. Follow-up decisions in the
same conversation:

- Scope: the agent portal only. The admin console and the customer/recruit link pages
  (`/p/ /pay/ /l/ /c/ /j/ /in/ /register`) stay web-only.
- "หากเราปรับทั้ง Capacitor เรายังเข้า ผ่านการแชร์ link ปรกติผ่าน browser ได้ไหม" — the web
  portal and every shared link must keep working exactly as before.
- Account deletion (App Store guideline 5.1.1(v)) is a request a company admin approves.
  Refined 2026-10-03: with no unpaid commission, or when the agent consents to waive it,
  the account is deleted at once; an agent who wants the unpaid commission waits for
  the admin to pay out and approve.
- Push title = the agent's company name. Tapping a push does not mark it read.
- Push for every notification that already reaches the in-app bell.
- Biometric unlock that the agent switches on in their profile.

Options considered: (A) a WebView that loads the live site; (B) Capacitor with the
built bundle inside the app; (C) a PWA. (A) is the classic App Store 4.2 rejection
("repackaged website") and fails offline. (C) has no store presence and weak iOS push.
(B) reuses the Vue code and adds native features one at a time.

## Decision

1. **Capacitor 8 inside `frontend/`.** `npm run build:app` builds with `.env.app`
   (absolute API URL) into `dist-app/`; `npm run cap:sync` copies it into `ios/` and
   `android/`. `dist/` and `scripts/deploy.sh` are untouched. appId `io.syncvision.partner`.
2. **One seam: `frontend/src/platform/`.** Every web/app difference lives there and is
   chosen by `isNativeApp()`. Views call platform functions, never Capacitor. Plugins are
   imported dynamically, so the browser bundle does not carry them.
3. **Auth stays bearer-token (TASK-241).** In the app the token is also kept in the
   iOS Keychain / Android Keystore, because iOS may clear a WebView's localStorage.
   The server must list `capacitor://localhost` and `https://localhost` in
   `CORS_EXTRA_ORIGINS`.
4. **Push = Firebase Cloud Messaging HTTP v1 for both platforms.** Device tokens in
   `device_tokens` (tenant-scoped). `NotificationService::notify()` queues one push job
   after commit; the push text is a fixed per-type sentence, never the notification's
   own title/body (PDPA: lock screens). Disabled until `FIREBASE_PROJECT_ID` and
   `FIREBASE_CREDENTIALS` are set.
5. **Forced update** via `app_version_policies` (platform-wide, Super Admin screen):
   below `min_supported_version` the app blocks with a store link.
6. **Account deletion** (`account_deletion_requests`): the agent re-enters the password.
   No unpaid commission, or the agent waives it → deleted at once (`resolution=immediate`;
   unpaid ledger rows get `payment_status=forfeited`, amounts untouched — BR-4). Keeps
   it → pending request, signed out and blocked (`LoginBlockReason::DeletionRequested`)
   until an admin approves or rejects. Approval anonymises personal fields and keeps the
   users row and the ledger. Waiving is refused while a withdrawal/payout is in flight.
7. **Links:** customer link paths open in the in-app browser against the web origin;
   app deep links (Universal Links / App Links) follow an explicit allowlist of agent
   paths only (`DEEP_LINK_ALLOWLIST`). The `.well-known` files are added once the Apple
   Team ID and the Play signing fingerprint are known.

## Consequences

- Two release tracks: web deploys at once, the app needs a store release for UI
  changes. `/api/v1` must stay backward compatible; breaking changes go through the
  version policy or a new API version.
- Native projects (`ios/`, `android/`) are committed. Firebase config files
  (`GoogleService-Info.plist`, `google-services.json`) are added by the owner.
- Cron must keep running `queue:work`, or pushes wait in the `jobs` table.
- Owner, 2026-10-03: no retention limit for withdrawal bank snapshots, audit history or
  the deletion reason after deletion (covered by the consent agents already give).
