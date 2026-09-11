<?php

namespace App\Services\Payment;

use App\Enums\PaymentProvider;
use App\Models\Company;
use App\Models\Order;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\Gateways\WebhookOutcome;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 2026-09-11 (human: "ขึ้นค่า debug จริงว่า stripe คืนค่าอะไรมา จะได้รู้ปัญหา
 * เกิดจากอะไร").
 *
 * Keeps the gateway's own words.
 *
 * ── WHY OUR LOG LINE WAS NOT ENOUGH ──
 *
 * `Log::info('Payment webhook applied', [...])` records what this system
 * CONCLUDED: result, charge id, amount. When an order looks paid after a card
 * was refused, the conclusion is exactly the thing in doubt — reading our own
 * summary back can only ever confirm what we already decided. The payload is
 * the evidence, and until now it was read once and dropped.
 *
 * ── EVERY VERIFIED DELIVERY, NOT ONLY THE INTERESTING ONES ──
 *
 * Including the ones this system ignores and the ones that match no order.
 * Those two are where an unexplained payment hides: "Stripe never sent it"
 * and "Stripe sent it and we dropped it" look identical from inside, and the
 * only way to tell them apart afterwards is to have written down what arrived.
 *
 * ── NEVER THE REASON A WEBHOOK FAILS ──
 *
 * Recording is a diagnostic, and a diagnostic that can break the money path
 * is a worse bug than the one it was added to explain. Every failure in here
 * is swallowed and logged.
 */
class PaymentWebhookRecorder
{
    /**
     * Keys whose VALUE is never written down, wherever they appear.
     *
     * `client_secret` is the one that matters in practice: Stripe includes it
     * on a PaymentIntent, and it is enough to confirm that intent from a
     * browser. The rest are defence in depth — nothing should ever put an api
     * key in a webhook body, and a debugging table is not the place to
     * discover that something did.
     *
     * Matched as a SUBSTRING of the lowercased key, so `webhook_secret`,
     * `stripe_secret_key` and `clientSecret` are all caught without
     * maintaining a list of spellings.
     *
     * @var list<string>
     */
    private const SECRET_KEY_FRAGMENTS = [
        'secret',
        'password',
        'api_key',
        'apikey',
        'private_key',
        'authorization',
        'access_token',
        'refresh_token',
    ];

    /** What a redacted value is replaced with — the NAME survives, the value never does. */
    private const REDACTED = '[redacted]';

    /**
     * Past this many characters of JSON the body is replaced by a summary.
     *
     * A cap is needed because an expanded object with hundreds of line items
     * is unbounded. 128KB is far above any real payment event and far below
     * anything that would trouble the column or the person reading it.
     */
    private const MAX_PAYLOAD_CHARS = 131_072;

    /**
     * @param  array<mixed>  $payload  The decoded body, as it arrived.
     */
    public function record(
        Company $company,
        PaymentProvider $provider,
        array $payload,
        ?WebhookOutcome $outcome,
        ?Order $order,
        ?string $result = null,
    ): void {
        try {
            PaymentWebhookEvent::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'provider' => $provider->value,
                'event_id' => $this->stringOrNull($payload['id'] ?? null),
                // The outcome's type is the interpreted one; the payload's is
                // what was on the wire. They are the same today and taking
                // the payload's keeps that an observation rather than an
                // assumption.
                'event_type' => $this->stringOrNull($payload['type'] ?? $outcome?->eventType),
                'order_id' => $order?->id,
                'order_token' => $outcome?->orderToken,
                'result' => $result ?? $outcome?->result->value,
                'charge_id' => $outcome?->chargeId,
                'amount_satang' => $outcome?->amountSatang,
                'payload' => $this->prepare($payload),
                'received_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record the payment webhook payload', [
                'provider' => $provider->value,
                'company_id' => $company->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Redacted, and capped.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function prepare(array $payload): array
    {
        $clean = $this->redact($payload);

        $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded !== false && strlen($encoded) <= self::MAX_PAYLOAD_CHARS) {
            return $clean;
        }

        /*
         * A body too large to keep is replaced by a NOTE saying so, never by
         * a truncated copy. Half a JSON document reads as a complete one to
         * whoever finds it next, and they would draw conclusions from fields
         * that were simply cut off.
         */
        return [
            '_note' => 'payload omitted — larger than '.self::MAX_PAYLOAD_CHARS.' characters',
            'id' => $this->stringOrNull($payload['id'] ?? null),
            'type' => $this->stringOrNull($payload['type'] ?? null),
        ];
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            $out[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $out;
    }

    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
