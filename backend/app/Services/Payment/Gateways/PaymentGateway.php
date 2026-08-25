<?php

namespace App\Services\Payment\Gateways;

use App\Enums\PaymentProvider;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * What this application needs from whoever takes the money.
 *
 * ── THE CONTRACT IS DERIVED FROM OUR SIDE, NOT FROM A PROVIDER'S API ──
 *
 * Writing an interface around a gateway you have not integrated produces an
 * interface shaped like your ASSUMPTIONS about that gateway, and the second
 * provider gets bolted on badly. The usual protection is to write the first
 * one concretely and extract the interface once two real examples exist.
 *
 * That protection is available here without waiting, because two real
 * examples already exist: the manual PromptPay-and-slip flow that every
 * company uses today, and Omise. So this interface was written against both,
 * and each method below is a question the APPLICATION asks — not a feature
 * some provider happens to offer. Nothing here is speculative: every method
 * has two implementations in this same directory.
 *
 * Deliberately absent, because nothing asks for them yet: refunds, 3-D
 * Secure, saved cards, instalments, partial capture.
 */
interface PaymentGateway
{
    public function provider(): PaymentProvider;

    /**
     * The credential fields this provider needs, for the admin form AND its
     * validation.
     *
     * ONE declaration drives both, so a field cannot appear on the form and
     * be silently unvalidated — which is how a gateway ends up live with a
     * key nobody checked the shape of.
     *
     * Each entry: key, Thai label, whether it is required, and whether it is
     * SECRET. `secret` fields are never returned by the API in any form
     * (see CompanyPaymentGatewayService) — the settings screen learns only
     * whether one is set, exactly as PlatformMailSettingService does for the
     * SMTP password.
     *
     * @return array<int, array{key: string, label: string, required: bool, secret: bool, help?: string}>
     */
    public function credentialFields(): array;

    /**
     * Prove these credentials actually work, by asking the provider.
     *
     * A company may not SWITCH to a provider that has never passed this
     * (CompanyPaymentGatewayService::activate). Wrong keys otherwise fail at
     * the customer, silently, one payment at a time — the failure is on
     * somebody else's screen, so nobody here finds out.
     *
     * Returns a human-readable note on success (the account name, the mode)
     * so the admin can see WHICH account they just connected — a green tick
     * alone cannot tell you that you connected the wrong company's Omise.
     *
     * Takes the COMPANY as well as the submitted credentials, because not
     * every provider keeps its configuration in this table: the manual flow's
     * PromptPay proxy has lived on `companies.payment_promptpay_id` since
     * ADR-017 and the public pay page already reads it there. Copying it into
     * a settings row would create a second home for one value — the exact
     * drift this interface exists to avoid.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws GatewayException when the provider rejects or cannot be reached
     */
    public function verifyCredentials(Company $company, array $credentials, bool $isLive): string;

    /**
     * Everything the customer's browser needs in order to start paying.
     *
     * Returns a PaymentIntent rather than performing a charge: the card
     * number must never reach this server. For Omise that means handing back
     * a public key and letting Omise.js tokenise in the browser; for the
     * manual flow it means a PromptPay QR payload and the fact that a slip
     * upload is expected.
     *
     * @param  array<string, string>  $credentials
     */
    public function startPayment(Order $order, array $credentials, bool $isLive): PaymentIntent;

    /**
     * Turn what the browser collected into a real charge, server-side.
     *
     * `$paymentToken` is whatever startPayment's flow produced — for Omise a
     * one-time card token from Omise.js. The card number itself never reaches
     * this server and never will; that is the entire PCI position, and it is
     * why this method takes a token rather than card fields.
     *
     * Returns the outcome in the SAME shape a webhook produces, on purpose.
     * A charge can complete synchronously here AND be announced again by a
     * webhook seconds later, so both paths must be indistinguishable to the
     * code that confirms orders — otherwise the two would drift and one of
     * them would eventually confirm an order the other would have refused.
     *
     * Providers with no synchronous charge step (the manual flow) throw.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws GatewayException
     */
    public function charge(Order $order, array $credentials, string $paymentToken): WebhookOutcome;

    /**
     * Is this webhook genuinely from the provider?
     *
     * MANDATORY, and there is no permissive fallback anywhere in this system.
     * An unverified webhook endpoint means anyone on the internet can POST
     * "charge.complete" and mark an order paid — free goods, and a BR-4
     * commission ledger row written for money that never arrived.
     *
     * A provider that offers no signature scheme does not get a webhook; it
     * gets polled.
     *
     * @param  array<string, string>  $credentials
     */
    public function verifyWebhook(Request $request, array $credentials): bool;

    /**
     * What does this webhook MEAN, in this application's terms?
     *
     * The normalisation point. Callers never see a provider's own event
     * vocabulary, so adding a gateway cannot require editing the code that
     * confirms orders.
     *
     * `amountSatang` on the outcome is not decoration: the caller compares it
     * to the order's own amount and refuses a mismatch. A webhook claiming a
     * 100-satang payment for an 890,000-satang order is either a bug or an
     * attack, and both must stop here.
     *
     * @param  array<string, mixed>  $payload
     */
    public function interpret(array $payload): WebhookOutcome;
}
