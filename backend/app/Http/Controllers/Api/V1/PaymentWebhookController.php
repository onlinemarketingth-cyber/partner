<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentProvider;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Order;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Notifications\GatewayEventUnmatchedNotification;
use App\Services\Payment\CompanyPaymentGatewayService;
use App\Services\Payment\GatewayPaymentService;
use App\Services\Payment\Gateways\WebhookOutcome;
use App\Services\Payment\Gateways\WebhookResult;
use App\Services\Payment\PaymentGatewayRegistry;
use App\Services\Payment\PaymentWebhookRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

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
        PaymentWebhookRecorder $recorder,
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

        /*
         * 2026-09-11 — THE BODY IS KEPT FROM HERE ON.
         *
         * Read once into a variable rather than re-read at each branch: what
         * gets recorded must be the same bytes the decision was made from,
         * or the record is of a different request than the one that happened.
         *
         * Below the signature check, deliberately. The rule this controller
         * already states for logging — an unverified body is chosen by whoever
         * sent it — applies with more force to a table: writing one would hand
         * anyone on the internet a place to put content of their choosing.
         */
        $payload = $request->json()->all();

        $outcome = $driver->interpret($payload);

        if ($outcome->result === WebhookResult::Ignore) {
            /*
             * Recorded even though nothing is done with it. "The gateway never
             * sent it" and "the gateway sent it and this system ignored it"
             * are indistinguishable from the inside, and they call for
             * opposite fixes — one is a dashboard subscription, the other is
             * a missing handler here.
             */
            $recorder->record($tenant, $paymentProvider, $payload, $outcome, null);

            /*
             * 2026-09-03 — an ignored event used to return 200 and leave no
             * trace whatsoever.
             *
             * This system subscribes to a fixed, short list of event types
             * (see StripeGateway::interpret). One outside it arriving
             * means the endpoint was edited in the provider's dashboard or
             * the provider changed something — both of which somebody should
             * be able to find out, and neither of which is an error worth
             * failing the delivery over. So: one line, at info, naming the
             * type, and still a 200.
             */
            Log::info('Payment webhook ignored — no handler for this event type', [
                'provider' => $paymentProvider->value,
                'company_id' => $tenant->id,
                'event_type' => $outcome->eventType,
            ]);

            $this->auditOncePerDay(
                $tenant,
                'webhook.unhandled_event_type',
                (string) ($outcome->eventType ?? 'unknown'),
                ['provider' => $paymentProvider->value, 'event_type' => $outcome->eventType],
            );

            return response()->json(['message' => 'ignored']);
        }

        $order = $this->resolveOrder($outcome->orderToken, $tenant);

        if ($order === null) {
            // The most valuable row in the table: a verified event, naming
            // money or not, that belongs to nothing here. Whatever went wrong
            // is IN this payload — a missing metadata token, a token from
            // another environment — and there is no order to read it from.
            $recorder->record($tenant, $paymentProvider, $payload, $outcome, null, PaymentWebhookEvent::RESULT_UNMATCHED);

            $this->reportUnmatched($outcome, $paymentProvider, $tenant);

            return response()->json(['message' => 'unmatched']);
        }

        /*
         * 2026-09-03 — every outcome now lands somewhere a human can see.
         *
         * Three of these four used to end in a log line and nothing else, so
         * the product could not tell an admin that a customer's card had been
         * declined, that a checkout had timed out, or that the gateway had
         * refunded a sale. Each handler writes the fact onto the order and
         * into the audit trail; see GatewayPaymentService for what each one
         * deliberately does NOT do (none of them changes the order's status,
         * and a refund never touches the commission ledger).
         */
        /*
         * 2026-09-10 — ONE LINE PER ACTED-UPON EVENT, before it is acted on.
         *
         * Prompted by a question this system could not answer: a card was
         * declined in testing and the order looked paid, and there was no way
         * to tell from the outside WHICH event had done it — a real
         * `checkout.session.completed`, a replay, or a person pressing approve
         * in the console. The failure paths each logged something; the success
         * path logged nothing at all, because nothing had gone wrong.
         *
         * "Nothing went wrong" is not the same as "nobody will ever ask". This
         * is the money path; it should always be possible to say which event
         * moved an order, and at info, so it costs nothing to leave on.
         */
        Log::info('Payment webhook applied', [
            'provider' => $paymentProvider->value,
            'company_id' => $tenant->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'event_type' => $outcome->eventType,
            'result' => $outcome->result->value,
            'charge_id' => $outcome->chargeId,
            'amount_satang' => $outcome->amountSatang,
        ]);

        /*
         * And the payload itself, BEFORE it is acted on — so a delivery that
         * makes this system fall over still leaves behind the thing that
         * caused it. `orders:explain` prints these against the order.
         */
        $recorder->record($tenant, $paymentProvider, $payload, $outcome, $order);

        match ($outcome->result) {
            WebhookResult::Paid => $payments->applyPaid($order, $outcome),
            WebhookResult::Failed => $payments->applyFailed($order, $outcome),
            WebhookResult::Expired => $payments->applyExpired($order, $outcome),
            WebhookResult::Refunded => $payments->applyRefunded($order, $outcome),
            default => null,
        };

        return response()->json(['message' => 'ok']);
    }

    /**
     * A verified event that names no order we hold.
     *
     * ── WHY THE SEVERITY DEPENDS ON THE RESULT ──
     *
     * Before 2026-09-03 every one of these wrote the same Log::info() line,
     * which flattened two very different situations into one.
     *
     * A Failed, Expired or unmatched-but-harmless event is exactly what the
     * old comment described: usually a charge created by hand in the
     * provider's own dashboard, normal enough not to alarm about, worth a
     * line so a PATTERN of them is findable.
     *
     * A PAID event is not that. The signature passed, so this came from the
     * company's own gateway account; it says money moved; and the token that
     * ties a charge to an order is missing or names nothing. A customer has
     * been charged and no order will ever be marked paid — no commission, no
     * voucher, no pipeline stage, no receipt — and nothing downstream will
     * notice, because everything downstream keys off an order that was never
     * touched. That deserves the loudest channel this system has.
     *
     * ── AND IT STILL RETURNS 200 ──
     *
     * Failing the delivery would make the provider retry the same
     * unplaceable event for hours, producing one alert per retry. The event
     * is recorded here; repeating it is not more information.
     */
    private function reportUnmatched(
        WebhookOutcome $outcome,
        PaymentProvider $provider,
        Company $tenant,
    ): void {
        $isMoney = $outcome->result === WebhookResult::Paid;

        $context = [
            'provider' => $provider->value,
            'company_id' => $tenant->id,
            'charge_id' => $outcome->chargeId,
            'amount_satang' => $outcome->amountSatang,
            'result' => $outcome->result->value,
        ];

        if (! $isMoney) {
            Log::info('Payment webhook did not match an order', $context);

            $this->auditOncePerDay(
                $tenant,
                'webhook.unmatched_non_payment',
                $outcome->result->value,
                $context,
            );

            return;
        }

        Log::critical('Payment received for an order this system cannot find', $context);

        /*
         * auditable is the COMPANY, not an order — there is no order, and
         * that absence is the whole event. §6 already treats audit_logs as
         * the trail for anything money-adjacent, and this is the only
         * durable record of a payment that never landed anywhere.
         */
        AuditLog::create([
            'company_id' => $tenant->id,
            'actor_user_id' => null,
            'action' => 'order.gateway_payment_unmatched',
            'auditable_type' => Company::class,
            'auditable_id' => $tenant->id,
            'new_values' => $context,
        ]);

        /*
         * Cannot throw. The event is already recorded above; an SMTP failure
         * escaping here would turn a delivered webhook into a non-2xx and
         * have the provider retry an event that can never be placed.
         */
        try {
            $admins = User::withoutGlobalScopes()
                ->where('company_id', $tenant->id)
                ->where('role', UserRole::CompanyAdmin->value)
                ->get();

            if ($admins->isEmpty()) {
                Log::warning('Unmatched payment, and this company has no Company Admin to tell', [
                    'company_id' => $tenant->id,
                ]);

                return;
            }

            Notification::send($admins, new GatewayEventUnmatchedNotification(
                $provider->label(),
                $outcome->chargeId,
                $outcome->amountSatang,
            ));
        } catch (Throwable $e) {
            Log::error('Unmatched payment recorded but the admin notification failed to send', [
                'company_id' => $tenant->id,
                'charge_id' => $outcome->chargeId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Put a low-severity webhook fact on a SCREEN, at most once a day.
     *
     * ── WHY IT IS ON A SCREEN AT ALL ──
     *
     * Log::info alone means storage/logs/laravel.log, which needs SSH to
     * read and which nobody reads unless they already suspect something.
     * "Recorded" and "discoverable" are not the same thing, and a fact only
     * the developer can reach is not a feature of this product.
     *
     * audit_logs is already the trail §6 defines for money-adjacent events
     * and already has a viewer (นโยบายและรายงาน), so these appear there beside
     * the serious ones rather than needing a screen of their own.
     *
     * ── WHY ONCE A DAY ──
     *
     * These two cases are the ones that can ARRIVE IN BULK. Anyone who ticks
     * "select all" in the provider's dashboard subscribes to hundreds of
     * event types, and an unbounded row per delivery would bury the audit
     * trail's real entries — a refund, an unmatched payment — under
     * thousands of routine ones. A log designed to be read must stay
     * readable, so the first occurrence of each kind each day is recorded
     * and the repeats are left to laravel.log, where volume costs nothing.
     *
     * The dedupe key is the event type (or the outcome), NOT the charge, so
     * "this keeps happening" collapses into one row a day rather than one
     * per delivery.
     */
    private function auditOncePerDay(Company $tenant, string $action, string $key, array $context): void
    {
        try {
            $alreadyToday = AuditLog::query()
                ->where('company_id', $tenant->id)
                ->where('action', $action)
                ->where('new_values->dedupe_key', $key)
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            if ($alreadyToday) {
                return;
            }

            AuditLog::create([
                'company_id' => $tenant->id,
                'actor_user_id' => null,
                'action' => $action,
                'auditable_type' => Company::class,
                'auditable_id' => $tenant->id,
                'new_values' => $context + ['dedupe_key' => $key],
            ]);
        } catch (Throwable $e) {
            // Never the reason a webhook fails. This is the least important
            // thing this request does.
            Log::warning('Could not write the webhook audit row', [
                'action' => $action,
                'reason' => $e->getMessage(),
            ]);
        }
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
