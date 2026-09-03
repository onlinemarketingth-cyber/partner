<?php

namespace App\Services\Payment;

use App\Enums\PaymentProvider;
use App\Models\Company;
use App\Models\CompanyPaymentGatewaySetting;
use App\Services\Payment\Gateways\GatewayException;
use Illuminate\Validation\ValidationException;

/**
 * The only thing allowed to read a company's payment credentials.
 *
 * ── WHAT NEVER LEAVES THIS CLASS ──
 *
 * Any credential field a driver declared `secret`. The settings screen learns
 * only whether one is SET — the same rule PlatformMailSettingService applies
 * to the SMTP password, and for the same reason: nobody needs to read a
 * secret key back to know which one is configured, and an API that can return
 * it is an API that can leak it.
 *
 * Non-secret fields (an Omise public key, a PromptPay proxy) ARE returned.
 * Both are visible to every customer anyway — the public key sits in the pay
 * page's HTML, the proxy is encoded in the QR — and masking them would hide
 * them from the one person who needs to check they are right.
 *
 * ── EXACTLY ONE ACTIVE GATEWAY ──
 *
 * The human's rule (2026-08-22). Enforced by SHAPE rather than by care:
 * `companies.payment_provider` is one column, so two active providers cannot
 * be written down. A boolean per settings row could represent two, and MySQL
 * has no partial unique index with which to forbid it.
 */
class CompanyPaymentGatewayService
{
    public function __construct(private readonly PaymentGatewayRegistry $registry) {}

    /**
     * Every provider, with its fields and whether this company has set it up.
     *
     * Returns the whole list rather than only configured ones, because this
     * IS the setup screen: an admin arriving for the first time must see what
     * they could choose, not an empty page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(Company $company): array
    {
        $rows = CompanyPaymentGatewaySetting::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get()
            ->keyBy(fn (CompanyPaymentGatewaySetting $s) => $s->provider->value);

        return array_map(function (PaymentProvider $provider) use ($company, $rows) {
            $row = $rows->get($provider->value);
            $driver = $this->registry->driver($provider);
            $stored = $row?->credentials ?? [];

            return [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'requires_human_verification' => $provider->requiresHumanVerification(),
                /*
                 * 2026-09-03 — the manual flow is ALWAYS available and is not
                 * a choice. Reporting it as is_active/inactive would put it
                 * back in a race it is not running: a company does not switch
                 * bank transfer off by switching Stripe on, and never did
                 * want to. `payment_provider` now answers only "which online
                 * gateway", so the manual row answers a different question.
                 */
                'always_available' => $provider->requiresHumanVerification(),
                'is_active' => ! $provider->requiresHumanVerification()
                    && $company->payment_provider === $provider->value,
                'is_live' => (bool) ($row?->is_live ?? false),
                'is_configured' => $row !== null,
                'is_verified' => (bool) $row?->isVerified(),
                'verified_at' => $row?->verified_at,
                'verified_note' => $row?->verified_note,
                'fields' => array_map(function (array $field) use ($stored) {
                    $value = $stored[$field['key']] ?? null;

                    return [
                        ...$field,
                        /*
                         * A secret is reported as SET or NOT SET and never as
                         * a value — not even a masked fragment. A key's last
                         * four characters identify nothing an admin can act
                         * on, and every character published is one fewer to
                         * guess.
                         */
                        'value' => $field['secret'] ? null : $value,
                        'is_set' => filled($value),
                    ];
                }, $driver->credentialFields()),
            ];
        }, $this->registry->available());
    }

    /**
     * Save credentials for one provider and prove them against its API.
     *
     * Verification is part of SAVING, not a separate button the admin might
     * skip. Storing unverified credentials and letting somebody activate them
     * later is how a gateway goes live with a typo in the secret key, which
     * then fails on the customer's screen one payment at a time.
     *
     * A secret left blank keeps the stored one. That is what lets an admin
     * change the mode or fix a public key without re-typing a secret they do
     * not have to hand — and it is why a blank secret is not treated as "clear
     * this field".
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException when the provider rejects the credentials
     */
    public function save(Company $company, PaymentProvider $provider, array $input, bool $isLive): CompanyPaymentGatewaySetting
    {
        $driver = $this->registry->driver($provider);

        $row = CompanyPaymentGatewaySetting::withoutGlobalScopes()
            ->firstOrNew(['company_id' => $company->id, 'provider' => $provider->value]);

        $merged = $row->credentials ?? [];
        foreach ($driver->credentialFields() as $field) {
            $submitted = trim((string) ($input[$field['key']] ?? ''));

            if ($submitted !== '') {
                $merged[$field['key']] = $submitted;
            } elseif (! $field['secret']) {
                // A blank NON-secret field is a real instruction to clear it;
                // the admin can see what they are erasing.
                unset($merged[$field['key']]);
            }

            if ($field['required'] && blank($merged[$field['key']] ?? null)) {
                throw ValidationException::withMessages([
                    $field['key'] => "ต้องระบุ {$field['label']}",
                ]);
            }
        }

        try {
            $note = $driver->verifyCredentials($company, $merged, $isLive);
        } catch (GatewayException $e) {
            /*
             * NOT SAVED. Credentials that failed verification must not sit in
             * the table looking configured — the next person to open this
             * screen would see a filled-in form and reasonably believe it
             * works.
             */
            // Keyed on the FIELD when the driver named one, so the admin
            // screen can put a red border on the box that is wrong. Falls
            // back to 'credentials' for rejections that are about the
            // account rather than any one value.
            throw ValidationException::withMessages([($e->field ?? 'credentials') => $e->getMessage()]);
        }

        $row->fill([
            'company_id' => $company->id,
            'provider' => $provider->value,
            'credentials' => $merged,
            'is_live' => $isLive,
            'verified_at' => now(),
            'verified_note' => $note,
        ])->save();

        /*
         * Changing the credentials of the ACTIVE provider re-verifies it, but
         * changing them can also invalidate it — a live key swapped for a
         * test one, say. The mode is part of what was just verified, so the
         * company's own record of "which mode am I taking money in" follows
         * it here rather than drifting.
         */
        return $row->refresh();
    }

    /**
     * Switch the company to a provider.
     *
     * Refuses a provider that is not configured and verified. This is the
     * whole reason verification is stored rather than transient: activation
     * is the moment real customers start being routed somewhere, and it must
     * not be possible to route them at credentials nobody has tested.
     *
     * @throws ValidationException
     */
    public function activate(Company $company, PaymentProvider $provider): Company
    {
        /*
         * 2026-09-03 — the manual flow cannot be "activated" because it is
         * never off. Allowing it here would write 'manual' back into a column
         * that now means "which ONLINE gateway", and the next reader would
         * take that to mean the company has an online gateway called manual.
         */
        if ($provider->requiresHumanVerification()) {
            throw ValidationException::withMessages([
                'provider' => 'ช่องทางโอนเงิน/พร้อมเพย์เปิดใช้งานอยู่เสมอ ไม่ต้องเลือก',
            ]);
        }

        $row = CompanyPaymentGatewaySetting::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('provider', $provider->value)
            ->first();

        if ($row === null || ! $row->isVerified()) {
            throw ValidationException::withMessages([
                'provider' => 'ต้องตั้งค่าและตรวจสอบการเชื่อมต่อของช่องทางนี้ให้ผ่านก่อน จึงจะเปิดใช้งานได้',
            ]);
        }

        /*
         * forceFill: `payment_provider` is deliberately NOT in Company's
         * $fillable, so no broad company-update Request can move a tenant's
         * money to a different gateway as a side effect of an unrelated edit.
         * This method is the only writer.
         */
        $company->forceFill(['payment_provider' => $provider->value])->save();

        return $company->refresh();
    }

    /**
     * Stop offering any online gateway. Bank transfer is unaffected.
     *
     * NULL, not 'manual': since 2026-09-03 this column answers "which online
     * gateway", and the honest answer to "none" is nothing rather than the
     * name of a flow that lives elsewhere.
     *
     * forceFill for the same reason activate() uses it — this method and that
     * one are the only writers of a column that decides where money goes.
     */
    public function deactivateOnlineGateway(Company $company): Company
    {
        $company->forceFill(['payment_provider' => null])->save();

        return $company->refresh();
    }

    /**
     * The credentials for a company's ACTIVE provider, for charging.
     *
     * Returns null when the active provider has no verified row — the caller
     * must fail closed rather than fall back to another gateway. Falling back
     * would take a customer's money through a provider the company did not
     * choose, into an account they may not have configured.
     *
     * @return array{provider: PaymentProvider, credentials: array<string, string>, is_live: bool}|null
     */
    public function activeConfig(Company $company): ?array
    {
        $provider = PaymentProvider::tryFrom((string) $company->payment_provider);

        /*
         * 2026-09-03 — THE MANUAL FLOW IS NEVER AN ANSWER HERE.
         *
         * Every caller of this method is about to take a card payment: it
         * asks "which online gateway may charge this order". Bank transfer is
         * not one, it is always available on its own, and returning it would
         * make the pay page ask ManualGateway to start a card payment.
         *
         * Rows written before 2026_09_03_100000 cleared them, and any code
         * path that still writes 'manual', both land here.
         */
        if ($provider === null || $provider->requiresHumanVerification()) {
            return null;
        }

        return $this->configFor($company, $provider);
    }

    /**
     * The usable configuration for ONE named provider, or null.
     *
     * Separate from activeConfig() because an ORDER carries its own provider
     * (stamped at creation, see the orders migration) and that is not always
     * the company's current one. Reading the order's provider through the
     * company's current setting would be the exact re-resolution the stamp
     * exists to prevent.
     *
     * ── A PROVIDER WITH NO CREDENTIALS NEEDS NO SETTINGS ROW ──
     *
     * ManualGateway declares no credential fields: its configuration is
     * `companies.payment_promptpay_id`, where ADR-017 put it and where the
     * public pay page has read it since. There is nothing to store and
     * nothing to verify against anyone's API, so a company that has never
     * opened the new settings screen — today, all of them — must still read
     * back as "manual", which is the truth about how it takes money.
     *
     * HONEST NOTE, found by mutation testing rather than by reasoning:
     * removing this branch breaks nothing today. The manual pay page reads
     * `companies.payment_promptpay_id` directly and never consults this
     * method, and every current caller falls back to Manual on null anyway.
     * It is kept because "this company has no payment configuration" is a
     * false statement about a company that takes money by bank transfer
     * every day, and the next caller to act on that answer is the one that
     * would find out. Its test pins the ANSWER, not the branch.
     *
     * Derived from the DRIVER (`credentialFields() === []`) rather than
     * written as `=== Manual`, so a second credential-free provider does not
     * need this method edited (BR-7).
     *
     * Activation is deliberately stricter: activate() still demands a
     * verified row, because switching providers is a decision somebody makes
     * and a decision is worth checking. Reading the state of a company that
     * has made no decision must return the truth instead.
     *
     * @return array{provider: PaymentProvider, credentials: array<string, string>, is_live: bool}|null
     */
    public function configFor(Company $company, PaymentProvider $provider): ?array
    {
        $row = CompanyPaymentGatewaySetting::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('provider', $provider->value)
            ->first();

        if ($row === null || ! $row->isVerified()) {
            if ($this->registry->driver($provider)->credentialFields() === []) {
                return ['provider' => $provider, 'credentials' => [], 'is_live' => true];
            }

            return null;
        }

        return [
            'provider' => $provider,
            'credentials' => $row->credentials ?? [],
            'is_live' => (bool) $row->is_live,
        ];
    }
}
