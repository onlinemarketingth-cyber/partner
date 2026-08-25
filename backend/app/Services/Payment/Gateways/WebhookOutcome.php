<?php

namespace App\Services\Payment\Gateways;

/**
 * What a provider's webhook means, in this application's terms.
 *
 * `Ignore` is a first-class outcome, not a failure. Gateways emit far more
 * events than any application cares about, and treating an uninteresting one
 * as an error produces alarms nobody can act on — which trains people to
 * ignore the alarms that matter.
 */
enum WebhookResult: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Ignore = 'ignore';
}

/**
 * The normalised event.
 *
 * `chargeId` is the provider's own id and becomes the value in orders'
 * UNIQUE `gateway_charge_id` — the database-level guard against a retried
 * webhook writing a second, immutable BR-4 commission row.
 *
 * `amountSatang` is what the provider says it actually took. The caller
 * compares it to the order and refuses a mismatch; a webhook claiming less
 * than the order is either a bug or an attack.
 */
class WebhookOutcome
{
    public function __construct(
        public readonly WebhookResult $result,
        public readonly ?string $chargeId = null,
        public readonly ?int $amountSatang = null,
        public readonly ?string $orderToken = null,
        public readonly ?string $failureMessage = null,
    ) {}

    public static function ignore(): self
    {
        return new self(WebhookResult::Ignore);
    }
}
