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
    /*
     * The customer never paid and the provider closed the attempt.
     *
     * Distinct from Failed: nothing was declined and nothing went wrong —
     * a Checkout Session simply timed out. The order stays open (opening the
     * same /pay link makes a new session), so this exists to be RECORDED,
     * which is exactly what separates "never opened the link" from "opened
     * it and walked away" when an admin looks at a stale order.
     */
    case Expired = 'expired';
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
        /*
         * The provider's own name for the event, carried so the caller can
         * SAY what it ignored without knowing any gateway's payload shape.
         *
         * 2026-09-03 — an ignored event used to return HTTP 200 and vanish
         * without a trace of any kind. This system subscribes to a fixed,
         * short list of event types, so one outside it arriving means somebody
         * edited the endpoint or the provider changed something, and neither
         * should be undiscoverable.
         */
        public readonly ?string $eventType = null,
        /*
         * 2026-09-10 — the provider's RAW refusal code, beside the human
         * sentence in `failureMessage`.
         *
         * Support's question months later is "which code did the bank send?",
         * and that cannot be worked out backwards from the message: a dozen
         * codes deliberately share one wording, including the four whose real
         * reason must never be shown to the cardholder. So the code is carried
         * separately and lands in the audit log, where staff can read it and
         * the customer cannot.
         */
        public readonly ?string $failureCode = null,
    ) {}

    public static function ignore(?string $eventType = null): self
    {
        return new self(WebhookResult::Ignore, eventType: $eventType);
    }
}
