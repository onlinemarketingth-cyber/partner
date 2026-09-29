# ADR-050 — Payment accounts: one set for every company, or each company its own

- **Status:** Accepted
- **Date:** 2026-09-29
- **Decided by:** the owner (KreangYot): *"สามารถ setup ได้จาก super admin ว่าระบบชำระเงินใช้ค่าเดียวทุกบริษัท หรือแยกบริษัท"*, then "ทำเลย" on the written spec
- **Supersedes in part:** ADR-027 §2 ("There is no platform-level fallback account"). ADR-027 remains the per-company mode unchanged.

## Context

The owner asked why a new company could not select Omise or Stripe. It could not because each company must connect its own verified keys (ADR-027), and the new UAT company had none.

The owner expected the opposite: one set of channels for the whole platform. He asked for a Super Admin switch between the two.

Four answers were confirmed before any code was written:

| Question | Answer |
|---|---|
| In the shared mode, whose account receives the money? | **The platform's**, for every company. This overrides ADR-027. |
| Which channels are shared? | **All of them**: bank transfer, PromptPay and the card gateway. |
| May one company keep its own keys in the shared mode? | **No.** Every company uses the platform's. |
| What happens after deploy? | **The system follows the Super Admin's setting.** It starts at "แยกรายบริษัท", the existing behaviour, until switched. |

## Decision

A platform switch, `platform_payment_settings.mode` (`App\Enums\PaymentAccountScope`), has two values:

- `company` ("แยกรายบริษัท"): each company's own bank account, PromptPay and Omise/Stripe. This is ADR-027, and it is the default.
- `platform` ("ใช้ค่าเดียวทุกบริษัท"): the platform's single bank account and PromptPay, plus its single online gateway, for every company.

**The order decides, not the switch.** `orders.payment_account` is stamped once, by `OrderService`, when the order is created.

Every reader asks `PaymentAccountService` for the order's accounts:
- the pay page (`company_payment`, `promptpay_payload`, `gateway.online`);
- `GatewayPaymentService` (charge and intent);
- `ManualGateway`'s QR;
- the webhook simulator.

A customer holding a link from before a switch keeps the account that link showed them.

**Switching to `platform` is refused until the platform can take money both ways.** It needs a transfer destination (a bank account number or a PromptPay ID) and an active, verified online gateway. The refusal reasons appear on the screen before anyone presses the switch.

While in `platform` mode, the platform transfer account cannot be emptied.

## Implementation

- **Tables:**
  - `platform_payment_settings`: one row holding mode, active provider and transfer account. No row means `company`.
  - `platform_payment_gateway_settings`: the platform's keys, with the same encrypted, hidden `credentials` as ADR-027 §3. This is a separate table rather than `company_id IS NULL` rows, so the platform's keys cannot be read as some company's through one missing WHERE.
  - `orders.payment_account`: existing rows are `company`.
  - `payment_webhook_events.company_id` is now nullable.
- **`CompanyPaymentGatewayService`** now serves both owners through shared private helpers (describe, verify-and-store, activatable). Platform methods: `platformOverview`, `savePlatform`, `activatePlatform`, `deactivatePlatformOnlineGateway`, `platformActiveConfig`, `platformConfigFor`.
  - In `platform` mode, a company's own save, activate and deactivate return 422. Its stored keys are kept, so switching back restores them.
- **`PaymentGateway::verifyCredentials`** takes `?Company`. NULL means the platform. The online drivers never read the company. `ManualGateway` refuses NULL.
- **Webhooks:**
  - `POST /webhooks/payments/{provider}/platform` is verified with the platform's secret. It matches only orders stamped `platform`, of any company.
  - The per-company endpoint now matches only that company's `company`-stamped orders. It is restricted to numeric ids and registered after the platform route.
  - A platform event that names no order is recorded and audited with no company. The Super Admins are notified.
- **API**, Super Admin only (`Ability::SettingsPaymentGatewayUpdate`), read included:
  - `GET /platform-payment-settings`
  - `PUT /platform-payment-settings/mode`
  - `PUT /platform-payment-settings/transfer-account`
  - `PUT /platform-payment-settings/gateways/{provider}`
  - `POST /platform-payment-settings/gateways/activate` and `/deactivate`
  - Each responds with the whole overview. No secret is ever included.
- **Audit (§6):**
  - `platform_payment.mode_changed`
  - `platform_payment.transfer_account_updated`
  - `platform_payment.gateway_saved`, `gateway_activated`, `gateway_deactivated`
- **Admin UI:**
  - New menu "ระบบชำระเงินกลาง". It is `PaymentGatewaySettingsView` with `scope='platform'`, so there is one copy of the gateway cards.
  - The switch has a confirmation dialog, and its explanation sits behind the ⓘ (§7).
  - "ช่องทางรับชำระเงิน" of a company, while in `platform` mode, shows a lock notice and a link to the platform page instead of the forms.

## Consequences

- In `platform` mode the platform receives every company's customer money and settles with each company outside this system. A per-company "owed" report is not built; it would be a new task if wanted.
- Commission and supplier settlement are unchanged. They are computed from paid orders, not from whose account received the money.
- The platform's Omise/Stripe dashboard needs its own webhook, pointing at the `/platform` URL shown on the screen.

## Tests

- `tests/Feature/Payment/PlatformPaymentAccountTest.php` (19 tests). They cover:
  - the default;
  - the readiness refusal;
  - the switch and its audit;
  - new orders paying the platform;
  - an order created before the switch keeping its account;
  - a company with no PromptPay still getting the platform's QR;
  - card charges using the platform key;
  - the platform webhook confirming platform orders only, and refusing other keys;
  - a company's webhook never confirming a platform order;
  - ignored events recorded under no company;
  - company settings frozen but kept;
  - the transfer account not being emptied;
  - platform keys verified, never returned, and rejected keys not stored;
  - Super Admin only;
  - an unknown mode rejected.
- `frontend-admin/src/views/__tests__/PlatformPaymentSettings.spec.ts` (7 tests).
- Mutation checks were run on:
  - both webhook scope filters;
  - the online-config scope;
  - the readiness guard;
  - the non-empty transfer account guard;
  - the destination scope;
  - the order stamp;
  - the company lock;
  - authorisation;
  - the QR source;
  - five UI behaviours.

  All were killed.
