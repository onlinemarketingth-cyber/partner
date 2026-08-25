<?php

namespace App\Console\Commands;

use App\Enums\PaymentProvider;
use App\Models\Order;
use App\Services\Payment\CompanyPaymentGatewayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Fire a correctly-signed payment webhook at THIS machine (ADR-027 / TASK-139).
 *
 * ── WHY THIS EXISTS ──
 *
 * Omise's servers cannot reach http://localhost:8010. Every other part of the
 * gateway can be exercised locally — verification, the card form, the charge —
 * because those are all OUTBOUND calls. The webhook is the one inbound leg,
 * and without something like this it is untestable until a deploy.
 *
 * Untestable-until-deploy is precisely how the highest-consequence
 * unauthenticated route in this system would end up first exercised in
 * production, against real money.
 *
 * ── WHAT IT PROVES, AND WHAT IT CANNOT ──
 *
 * PROVES: our routing, our tenant scoping, our idempotency, our amount check,
 * and that a wrong signature is refused. Those are our bugs to have.
 *
 * CANNOT PROVE: that Omise's real webhook is shaped like this. The payload
 * below and the `X-Omise-Signature` HMAC scheme are read from Omise's docs and
 * assumed. This command signs with the SAME assumption OmiseGateway verifies
 * with, so the two agree with each other whether or not either matches Omise.
 * Only a real webhook from Omise can settle that (docs/TASK-139-omise-golive.md
 * §4 step 3).
 *
 * ── LOCAL ONLY ──
 *
 * Refused outside APP_ENV=local. This adds no capability anyone holding the
 * webhook secret lacks — they could write the same curl — but a tool whose
 * whole job is "mark this order paid" has no business being runnable on a
 * production console, and the guard costs one line.
 */
class SimulatePaymentWebhook extends Command
{
    protected $signature = 'payment:simulate-webhook
                            {order : Order id, or order_number}
                            {--status=successful : successful|failed}
                            {--charge= : Reuse a charge id, to test a DUPLICATE delivery}
                            {--amount= : Override the satang amount, to test a MISMATCH}
                            {--unsigned : Send no signature at all — must be refused with 401}
                            {--forge : Sign with the WRONG secret — must be refused with 401}';

    protected $description = 'Local only: POST a signed gateway webhook to this app, as Omise would.';

    public function handle(CompanyPaymentGatewayService $gateways): int
    {
        if (! app()->environment('local')) {
            $this->error('This command runs only when APP_ENV=local.');

            return self::FAILURE;
        }

        $key = (string) $this->argument('order');
        $order = Order::withoutGlobalScopes()
            ->where('id', is_numeric($key) ? (int) $key : 0)
            ->orWhere('order_number', $key)
            ->first();

        if ($order === null) {
            $this->error("No order found for [{$key}].");

            return self::FAILURE;
        }

        $provider = $order->payment_provider ?? PaymentProvider::Manual;
        $config = $gateways->configFor($order->company, $provider);

        if ($config === null || ($config['credentials']['webhook_secret'] ?? '') === '') {
            $this->error("Order {$order->order_number} is stamped [{$provider->value}], which has no webhook secret configured for company {$order->company_id}.");
            $this->line('Set the gateway up in the Admin console first, and create the order AFTER activating it.');

            return self::FAILURE;
        }

        $body = json_encode([
            'key' => 'charge.complete',
            'data' => [
                // Reusing a charge id is how a DUPLICATE delivery is simulated.
                'id' => (string) ($this->option('charge') ?: 'chrg_test_'.substr(md5((string) $order->id.microtime()), 0, 16)),
                'status' => (string) $this->option('status'),
                'amount' => (int) ($this->option('amount') ?: $order->amount_satang),
                'currency' => 'THB',
                'failure_message' => $this->option('status') === 'successful' ? null : 'insufficient_fund',
                // THE JOIN KEY. OmiseGateway::charge() sends this when creating
                // a charge, and Omise echoes metadata back on every event for
                // it — without it a webhook belongs to nobody.
                'metadata' => ['order_token' => $order->public_token],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $secret = (string) $config['credentials']['webhook_secret'];
        $signingSecret = $this->option('forge') ? $secret.'-wrong' : $secret;

        $url = rtrim((string) config('app.url'), '/')
            ."/api/v1/webhooks/payments/{$provider->value}/{$order->company_id}";

        $this->line("POST {$url}");
        $this->line($body);
        $this->newLine();

        $request = Http::withBody($body, 'application/json')->acceptJson();

        if (! $this->option('unsigned')) {
            $request = $request->withHeaders([
                'X-Omise-Signature' => hash_hmac('sha256', $body, $signingSecret),
            ]);
        }

        $response = $request->post($url);

        $this->line("HTTP {$response->status()}  {$response->body()}");
        $this->newLine();

        /*
         * Read the ORDER back rather than trusting the response.
         *
         * A 200 here means "the endpoint accepted delivery", which for an
         * amount mismatch or a duplicate is deliberately the same answer as
         * for a real payment — the gateway must not be told to retry. What
         * actually happened is a row in the database, so that is what gets
         * printed.
         */
        $order->refresh();
        $this->table(
            ['status', 'gateway_charge_id', 'paid_at'],
            [[$order->status->value, $order->gateway_charge_id ?? '—', $order->paid_at ?? '—']],
        );

        return self::SUCCESS;
    }
}
