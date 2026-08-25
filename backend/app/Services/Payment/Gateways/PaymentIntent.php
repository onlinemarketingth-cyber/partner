<?php

namespace App\Services\Payment\Gateways;

/**
 * What the customer's browser needs in order to start paying.
 *
 * Deliberately NOT a charge. The card number must never reach this server:
 * for a card gateway this object carries a PUBLIC key and the browser
 * tokenises directly with the provider, which is the whole of our PCI story.
 *
 * `kind` tells the pay page which UI to render. It is a small closed
 * vocabulary rather than a provider name, so the page never grows a branch
 * per gateway:
 *
 *   'qr'       — render this payload as a QR and expect a slip upload
 *   'tokenize' — load the provider's JS with `publicKey` and post the token
 *   'redirect' — send the customer to `redirectUrl`
 */
class PaymentIntent
{
    /**
     * @param  array<string, mixed>  $extra  provider-specific, rendered by nothing generic
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $amountSatang,
        public readonly ?string $publicKey = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $qrPayload = null,
        public readonly bool $expectsSlipUpload = false,
        public readonly array $extra = [],
    ) {}
}
