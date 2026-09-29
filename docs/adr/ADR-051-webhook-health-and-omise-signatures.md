# ADR-051 — Webhook health checks, and Omise's real signature scheme

- **Status:** Accepted
- **Date:** 2026-09-29
- **Decided by:** the owner (KreangYot). He wrote: *"การตั้งค่า stripe ในบริษัทแยกกันมีปัญหาเรื่อง web hook ไม่ตรงกัน สามารถขึ้นแจ้งเตือนมีปุ่มทดสอบ webhook ได้ไหม ถ้าได้ทำทั้ง stripe กับ omise"*. He then chose:
  - "แก้พร้อมกัน": fix Omise's verification in the same change;
  - "หน้านี้ + แจ้ง Super Admin": warn on the payment screen and email the Super Admins.
- **Relates to:** ADR-027 (per-company gateways), ADR-050 (platform gateway)

## Context

A wrong webhook signing secret fails silently:
- the charge succeeds at the provider;
- every event is refused here as forged;
- nothing on any screen says so.

Refusals were a `Log::warning` line and nothing more.

While checking the Omise docs for this work, a real defect was found. `OmiseGateway::verifyWebhook` did not implement Omise's scheme, so **every genuine Omise webhook was being refused**:
- it read an `X-Omise-Signature` header;
- it HMAC'd the body alone;
- it used the secret as typed.

Synchronous card charges still worked. Asynchronous events (refunds, declines, late confirmations) never landed.

## Decision

1. **Omise signatures now follow [docs.omise.co/api-webhooks](https://docs.omise.co/api-webhooks):**
   - headers `Omise-Signature` and `Omise-Signature-Timestamp`;
   - HMAC-SHA256 over `"<timestamp>.<raw body>"`, keyed with the **Base64-decoded** secret;
   - any of the comma-separated signatures may match (secret rotation);
   - the timestamp must be within 300 s, the same replay window as Stripe.

   Saving an Omise webhook secret that is not Base64 is refused at the field. This catches a Stripe `whsec_` pasted into the Omise card.
2. **Deliveries are counted per payment account per day** in `payment_webhook_delivery_stats`:
   - counters: accepted, refused, last of each;
   - the account key is `company:<id>` or `platform`;
   - a refused request's body is never stored, because it is unverified;
   - counters keep the table bounded however many forgeries arrive.
3. **Each verified online gateway card warns on its own.** The overview includes `webhook` with recent counts and problems:
   - `signature_rejected`: an **error**, downgraded to a warning if a good delivery has arrived since;
   - `never_received`: a **warning**, when the keys were verified more than 24 h ago and nothing has arrived.
4. **The "ตรวจสอบ webhook" button** (read-only):
   - `GET /companies/{id}/payment-gateways/{provider}/webhook-check`
   - `GET /platform-payment-settings/gateways/{provider}/webhook-check`

   For Stripe it also asks `GET /v1/webhook_endpoints` (via `InspectsWebhookEndpoints`). It reports:
   - `endpoint_missing`, which names any other account's URL it found instead;
   - `endpoint_disabled`;
   - `events_missing`.

   Endpoints are matched on the URL **path**, so a proxy spelling the host differently does not cause a false alarm. Omise has no such API, so the screen says only the deliveries were checked. The signing secret itself can never be read back from either provider.
5. **Super Admins are emailed on the first refusal of the day per account** (`WebhookSignatureRejectedNotification`). The email names both possible causes: a stale secret, or forgeries.

## Consequences

- An Omise account configured before this change must have its webhook secret re-entered exactly as Omise shows it, which is Base64.
- **Unverified:** Omise live keys. `assertKeyMode` requires `pkey_live_` / `skey_live_`, and the Omise docs we could reach show only `_test_` examples. Confirm against a real live Omise dashboard before going live on Omise.

## Tests

- `WebhookHealthTest` (19 tests). They cover:
  - counting;
  - card problems;
  - email once per day;
  - accumulation across days;
  - per-account isolation;
  - every Stripe setup finding;
  - the Omise dashboard not being checkable;
  - platform counters;
  - authorisation;
  - no secrets in responses.
- `GatewayChargeAndWebhookTest` updated to the real scheme, plus:
  - replay refused;
  - rotation accepted;
  - old header refused.
- Admin spec `PlatformPaymentSettings.spec.ts` (+5).
- Mutation checks on the Omise verifier, recording, email throttle, URL matching and each setup rule. All were killed.
