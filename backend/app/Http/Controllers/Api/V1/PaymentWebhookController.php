<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Services\Payment\CompanyPaymentGatewayService;
use App\Services\Payment\GatewayPaymentService;
use App\Services\Payment\Gateways\WebhookResult;
use App\Services\Payment\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ADR-027 / TASK-139 — where a payment provider tells us money arrived.
 *
 * PUBLIC and UNAUTHENTICATED, like /pay/{token}, and flagged here as ADR-011
 * flags every such endpoint so it is never mistaken for an accident. Unlike
 * /pay/{token} it is not merely unauthenticated: it can mark an order PAID,
 * which writes an immutable BR-4 commission ledger row. It is therefore the
 * highest-consequence unauthenticated route in this system.
 *
 * ── THE SIGNATURE IS THE ONLY THING STANDING HERE ──
 *
 * There is no permissive path. A request whose signature does not verify is
 * refused, full stop — no "trusted IP" fallback, no debug bypass, no
 * environment in which it is skipped. Without that, anyone on the internet
 * can POST `charge.complete` and receive free goods plus a commission payment
 * to an agent, and the ledger row cannot be un-written.
 *
 * ── THE COMPANY IS IN THE URL, NOT IN THE PAYLOAD ──
 *
 * Credentials are per company (ADR-027 §3), so the webhook secret to verify
 * AGAINST must be chosen before the payload can be trusted at all. Choosing
 * it from the payload would mean letting the caller nominate the key their
 * own forgery is checked with.
 *
 * ── AFTER A VALID SIGNATURE, THE ANSWER IS ALWAYS 200 ──
 *
 * Providers retry non-2xx responses for hours. Every condition below that is
 * not "this is forged" is a condition retrying cannot fix: an event type we
 * do not care about, an order that is not ours, a duplicate. Returning an
 * error for those buys nothing and produces a retry storm plus an alarm
 * nobody can act on. What deserves attention is logged instead, at a level
 * matching how bad it is.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        string $company,
        Request $request,
        CompanyPaymentGatewayService $gateways,
        GatewayPaymentService $payments,
        PaymentGatewayRegistry $registry,
    ): JsonResponse {
        $paymentProvider = PaymentProvider::tryFrom($provider);
        // No TenantScope — there is no authenticated user on a webhook, and
        // the tenant is what we are in the middle of establishing.
        $tenant = Company::withoutGlobalScopes()->find($company);

        /*
         * 404 for an unknown provider or company, matching what a wrong URL
         * gets everywhere else in this API. A distinct error would let anyone
         * probe this endpoint to enumerate which company ids exist and which
         * of them take card payments.
         */
        if ($paymentProvider === null || $tenant === null) {
            return response()->json(['message' => 'ไม่พบปลายทาง'], 404);
        }

        $config = $gateways->configFor($tenant, $paymentProvider);

        if ($config === null) {
            return response()->json(['message' => 'ไม่พบปลายทาง'], 404);
        }

        $driver = $registry->driver($paymentProvider);

        if (! $driver->verifyWebhook($request, $config['credentials'])) {
            /*
             * WARNING, not INFO. A wrong signature is either a misconfigured
             * webhook secret — in which case real payments are silently not
             * being confirmed — or somebody trying to forge one. Both need
             * to be visible; neither is routine.
             *
             * The body is NOT logged: an attacker chooses it, and logs are
             * read by people and shipped to third parties.
             */
            Log::warning('Rejected a payment webhook with an invalid signature', [
                'provider' => $paymentProvider->value,
                'company_id' => $tenant->id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'ลายเซ็นไม่ถูกต้อง'], 401);
        }

        $outcome = $driver->interpret($request->json()->all());

        if ($outcome->result === WebhookResult::Ignore) {
            return response()->json(['message' => 'ignored']);
        }

        $order = $this->resolveOrder($outcome->orderToken, $tenant);

        if ($order === null) {
            /*
             * A signed event we cannot place. Most often a charge created
             * outside this system (a manual charge in the provider's own
             * dashboard) — normal enough not to alarm, worth recording so
             * that a pattern of them is findable.
             */
            Log::info('Payment webhook did not match an order', [
                'provider' => $paymentProvider->value,
                'company_id' => $tenant->id,
                'charge_id' => $outcome->chargeId,
            ]);

            return response()->json(['message' => 'unmatched']);
        }

        match ($outcome->result) {
            WebhookResult::Paid => $payments->applyPaid($order, $outcome),
            WebhookResult::Failed => $payments->applyFailed($order, $outcome),
            /*
             * A refund is RECORDED AND NOT ACTED ON, deliberately.
             *
             * Reversing a sale means reversing an agent's commission, and
             * BR-4 rows are immutable — the reversal is its own ledger entry
             * with its own rules (CommissionReversalService). Letting a
             * webhook trigger that would mean money is clawed back from an
             * agent's balance by an event nobody in this company reviewed.
             * It stays a human decision; this line makes sure the human
             * knows there is one to make.
             */
            WebhookResult::Refunded => Log::warning('Refund reported by gateway — needs a human decision', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'charge_id' => $outcome->chargeId,
            ]),
            default => null,
        };

        return response()->json(['message' => 'ok']);
    }

    /**
     * The order this event belongs to, or null.
     *
     * Matched on `public_token`, which the charge carried as metadata when it
     * was created — the join key that survives the minutes between a charge
     * and its webhook.
     *
     * Scoped to the company from the URL as well, even though the token is
     * already unguessable. BR-6 is not a rule about what is guessable: a
     * signed event from company A's account must not be able to reach into
     * company B's orders, whatever token it names.
     */
    private function resolveOrder(?string $token, Company $company): ?Order
    {
        if (blank($token)) {
            return null;
        }

        return Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('public_token', $token)
            ->first();
    }
}
