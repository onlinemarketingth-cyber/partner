# ADR-027 — One Omise Account per Company

- **Status:** Accepted
- **Date:** 2026-08-08
- **Human decision:** KreangYot — *"payment ตอนนี้สรุปคือ 1 company 1 omise"*
- **Supersedes:** the platform-wide `services.omise.*` block added earlier the same day (now removed)
- **Related:** ADR-026 §5 Q2 (Omise chosen), ADR-017 (orders), BR-3, BR-6, CLAUDE.md §5, §6
- **Unblocks:** TASK-139

---

## 1. Context

The question that produced this ADR came from the human noticing something real: their
Omise dashboard already has a webhook endpoint pointing at a different project, and an
Omise account accepts **one** webhook endpoint. That is a symptom, not the problem.

The problem underneath: **who receives the customer's money.**

`companies` already carries `payment_promptpay_id`, `payment_bank_name`,
`payment_bank_account_number`, `payment_bank_account_name` (TASK-054). The manual flow
has therefore always paid **the selling company directly** — the platform never touches
the funds. The gateway must not quietly reverse that.

An earlier draft of `config/services.php` (mine, same day) read a single
`OMISE_SECRET_KEY` from `.env`. That would have routed every tenant's revenue into one
account. It is removed, and this ADR records why so it is not re-added.

---

## 2. Decision

**Each company connects its own Omise account.** Credentials, webhook identity and
signing secret are per-company. The platform holds no Omise account in the payment path.

A company with no Omise configuration simply cannot take gateway payments — it falls
back to the existing slip flow. **There is no platform-level fallback account**, because
a fallback that "just works" would silently mean the platform collected someone else's
money.

---

## 3. Where the credentials live — and why this is not a §6 violation

CLAUDE.md §6 says *"Secrets: `.env` only — never committed to git."* Taken literally that
forbids what this ADR does, so the reasoning is spelled out rather than waved past.

`.env` is a **deploy-time** file. Companies are created at **runtime**, by admins, in the
product. A new tenant cannot require an ops engineer to edit `.env` and redeploy before
they can take a payment. There is no arrangement of `.env` that expresses "N tenants,
each with their own key pair, N growing on its own".

So credentials live in a new table, **`company_payment_gateway_settings`**, with:

- `secret_key` and `webhook_secret` under Laravel's **`encrypted` cast** — the exact
  treatment `users.bank_account_number` already receives (TASK-044), keyed by `APP_KEY`
  which itself is in `.env`. The database alone is not enough to spend anyone's money.
- `public_key` stored plain — it is safe to expose to a browser by design (it only mints
  tokens); pretending otherwise would be theatre.
- **Never serialised into any API Resource.** The Admin UI writes them and reads back a
  masked confirmation only ("ตั้งค่าแล้ว · pkey_test_…hpz4"). If a secret key ever appears
  in a response body, that is a P0, not a bug report.
- Every write **audit-logged** (§6 — an action affecting money).

§6's intent — never in git, never in a response, never in a log — is fully preserved.
What changes is only the storage medium, and only because the tenancy model requires it.

---

## 4. Webhooks — the part that actually solves the human's original question

Omise allows one webhook endpoint per account. With one account per company, **each
company sets its own endpoint**, so the collision with their other project disappears
entirely: that project keeps `https://syncvision.io/api/webhooks/omise`, and each company
on this platform points at a URL of its own.

**Endpoint shape:** `POST /api/v1/webhooks/omise/{companyToken}`

- `companyToken` is an opaque random token stored on `company_payment_gateway_settings`,
  **not** the `company_id`. An enumerable id in a public URL is an invitation to probe
  every tenant's webhook (BR-6 / IDOR — §5 rule 5).
- The token identifies **which company's Omise secret to authenticate the follow-up
  API call with**. It is a routing key, never an authorisation.
- Authorisation is the signature check plus the re-fetch (below). A valid token with an
  invalid signature must be rejected.

**Processing rules (non-negotiable, carried forward from ADR-026 §5 Q2):**

1. Verify the signature using **that company's** `webhook_secret`.
2. Read **only the charge id** from the body. Re-fetch that charge from Omise using
   **that company's** `secret_key`, and act solely on the re-fetched status. The webhook
   endpoint is a public unauthenticated POST; treating its payload as truth would let
   anyone mark any order paid.
3. Resolve the order **within that company only** — never a bare `Order::find()`. A
   charge belonging to company A must not be able to name an order in company B (§5
   rule 5). This is the specific cross-tenant hole this design creates and must close.
4. Compare the re-fetched amount against `orders.amount_satang` (BR-3, integer satang).
   Mismatch is a hard failure, not a warning.
5. Then call the **existing** `OrderService::confirmPayment()`. Do not duplicate its
   logic and do not write `commission_ledger` from the webhook — commission is a side
   effect of the pipeline reaching Complete Payment and nothing else (BR-4).
6. **Idempotent.** Omise may deliver the same event more than once, and `confirmPayment()`
   fires commission. Rely on its existing early-return on already-`paid`.

---

## 5. Consequences

### Accepted costs

- **Onboarding a company now includes a payment-setup step.** Someone at each company
  must create an Omise account, complete KYC, paste three values into the Admin UI, and
  register a webhook URL we generate for them. This is real friction and it is the
  correct friction — it is the same conversation as "which bank account do you want your
  money in".
- **Support burden shifts.** A failing payment may now be a tenant's own Omise
  misconfiguration, which the platform cannot see into. The Admin UI needs a "test
  connection" action and a clear last-webhook-received timestamp.
- **Config Health (TASK-041) gains a check:** a company with gateway enabled but no
  usable credentials.

### What this does NOT decide

- **Account Chaining** (Omise beta) is a different model — sub-merchants under one parent,
  useful if the platform ever wants to take a cut at the payment layer. Explicitly out of
  scope; revisit only if the commercial model changes.
- Refunds, installments, recurring charges.
- Whether the platform ever charges companies a SaaS fee, and how. Unrelated money flow,
  unbuilt.

---

## 6. Open — BR-7, not to be guessed

1. Whether a company may enable the gateway **and** slips simultaneously, or must pick one.
2. What the customer sees when a company has no gateway configured — is the slip flow
   presented as normal, or is checkout hidden entirely?
3. Test-mode keys vs live-mode keys per company: one row with a mode flag, or two rows?
   (Leaning one row + `is_test_mode`, but it changes the Admin UI, so ask.)
