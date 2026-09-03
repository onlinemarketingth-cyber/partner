<?php

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Notifications\GatewayRefundReportedNotification;
use App\Services\Order\OrderService;
use App\Services\Payment\Gateways\GatewayException;
use App\Services\Payment\Gateways\WebhookOutcome;
use App\Services\Payment\Gateways\WebhookResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The one place a gateway result is allowed to mark an order paid.
 *
 * Both the synchronous charge and the webhook end here, deliberately. A
 * charge can succeed in the customer's request AND be announced again by a
 * webhook seconds later; if those two paths each had their own confirmation
 * logic they would drift, and one of them would eventually accept something
 * the other would have refused.
 *
 * ── THE THREE GUARDS, IN ORDER ──
 *
 * 1. THE AMOUNT MUST MATCH. A result claiming a different amount than the
 *    order is either a bug or an attack, and both stop here. Checked BEFORE
 *    anything is written, because the cheapest place to reject is before the
 *    ledger.
 *
 * 2. THE CHARGE ID IS CLAIMED AT THE DATABASE. `orders.gateway_charge_id` is
 *    UNIQUE. Two webhooks arriving at once — normal operation for every
 *    gateway — cannot both pass, whatever PHP believes about the order's
 *    status. This matters more than it sounds: what gets written twice is a
 *    BR-4 commission ledger row, which is immutable by definition and
 *    therefore cannot be un-written by any correction afterwards.
 *
 * 3. CONFIRMATION GOES THROUGH OrderService::confirmPayment, the same method
 *    the manual slip flow calls. Commission, vouchers, pipeline stage and the
 *    agent's notification then fire identically no matter how the money
 *    arrived. A second confirmation path would be a second set of rules about
 *    when an agent gets paid.
 */
class GatewayPaymentService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CompanyPaymentGatewayService $gateways,
        private readonly PaymentGatewayRegistry $registry,
    ) {}

    /**
     * Charge an order with a token the customer's browser produced.
     *
     * @throws GatewayException
     */
    public function chargeWithToken(Order $order, string $paymentToken): Order
    {
        /*
         * ALREADY PAID — refuse before touching the gateway.
         *
         * A customer who double-clicks, or whose browser retries a timed-out
         * request, must not produce a second charge. The definitive guard is
         * the UNIQUE charge id, but that one only stops the second charge
         * from being RECORDED — it does not stop the customer's card being
         * charged twice, and a refund is a worse outcome than a refusal.
         */
        if ($order->hasGatewayPayment()) {
            throw new GatewayException('คำสั่งซื้อนี้ได้รับการชำระเงินแล้ว');
        }

        if (! $order->isPayable()) {
            throw new GatewayException('คำสั่งซื้อนี้ไม่อยู่ในสถานะที่ชำระเงินได้');
        }

        /*
         * The order's OWN provider — and only while it is still the
         * company's active one.
         *
         * The order carries the provider it was created for, because the
         * /pay link is already in a customer's hand with instructions they
         * have read. But a company that has switched gateways has decided to
         * stop collecting money that way, and honouring an old link past
         * that decision would take money through a route the company closed.
         *
         * So this fails CLOSED and the customer is told to contact the
         * seller, rather than being charged either way round.
         */
        $provider = $order->payment_provider;
        $active = $this->gateways->activeConfig($order->company);

        if ($provider === null || $active === null || $active['provider'] !== $provider) {
            throw new GatewayException('ช่องทางชำระเงินของคำสั่งซื้อนี้ไม่พร้อมใช้งาน กรุณาติดต่อผู้ขาย');
        }

        $config = $this->gateways->configFor($order->company, $provider);

        if ($config === null) {
            throw new GatewayException('บริษัทนี้ยังไม่ได้ตั้งค่าช่องทางชำระเงินนี้');
        }

        $outcome = $this->registry
            ->driver($provider)
            ->charge($order, $config['credentials'], $paymentToken);

        if ($outcome->result !== WebhookResult::Paid) {
            throw new GatewayException($outcome->failureMessage ?? 'ชำระเงินไม่สำเร็จ');
        }

        return $this->applyPaid($order, $outcome) ?? $order->fresh();
    }

    /**
     * Apply a Paid outcome to an order, exactly once.
     *
     * Returns null when the outcome was rejected or already applied — a
     * no-op, not an error, because a duplicate webhook is normal traffic and
     * alarming on it trains people to ignore alarms.
     */
    public function applyPaid(Order $order, WebhookOutcome $outcome): ?Order
    {
        if ($outcome->result !== WebhookResult::Paid || $outcome->chargeId === null) {
            return null;
        }

        /*
         * GUARD 1 — the amount.
         *
         * Refused loudly: unlike a duplicate webhook, this should never
         * happen in normal operation, so it is worth waking somebody up.
         */
        if ($outcome->amountSatang !== null && $outcome->amountSatang !== $order->amount_satang) {
            Log::critical('Gateway reported an amount that does not match the order', [
                'order_id' => $order->id,
                'order_amount_satang' => $order->amount_satang,
                'reported_amount_satang' => $outcome->amountSatang,
                'charge_id' => $outcome->chargeId,
            ]);

            return null;
        }

        /*
         * GUARD 2 — claim the charge id at the DATABASE.
         *
         * A conditional UPDATE, not a read-then-write: `whereNull` inside the
         * same statement means two concurrent workers cannot both see "not
         * claimed yet". The unique index is the second line of defence for
         * the case where the same charge id arrives for two different orders.
         */
        try {
            $claimed = Order::withoutGlobalScopes()
                ->whereKey($order->id)
                ->whereNull('gateway_charge_id')
                ->update(['gateway_charge_id' => $outcome->chargeId]);
        } catch (QueryException $e) {
            // Unique violation: this charge already belongs to some order.
            Log::warning('Duplicate gateway charge id', [
                'order_id' => $order->id,
                'charge_id' => $outcome->chargeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($claimed === 0) {
            // Already claimed — a retried webhook, or the synchronous charge
            // got here first. Both are expected.
            return null;
        }

        $order->refresh();

        if ($order->status === OrderStatus::Paid) {
            return $order;
        }

        /*
         * GUARD 3 — the SAME confirmation the manual flow uses.
         *
         * The actor is the agent who owns the order. confirmPayment() records
         * who verified a payment, and for a gateway payment nobody did — but
         * the column is not nullable and inventing a system user would put a
         * fake person in an audit trail. The agent is the truthful answer to
         * "whose sale closed"; `payment_provider` on the row is what says a
         * machine confirmed it.
         */
        /*
         * THE MONEY IS ALREADY TAKEN BY THE TIME WE GET HERE.
         *
         * confirmPayment() can still refuse — its pipeline rule says an
         * order's referral must have reached the stage before Complete
         * Payment, and that rule is about the SALE, not about the money.
         *
         * A refusal must not propagate. Thrown from a webhook it becomes a
         * non-2xx, the gateway retries the same event for hours, and every
         * retry fails identically; thrown from the customer's charge request
         * it reads as "payment failed" to somebody who has just been charged,
         * and they try another card.
         *
         * So it is caught, and it is caught LOUDLY. The order keeps its
         * charge id, which is what makes it findable: `gateway_charge_id IS
         * NOT NULL AND status <> 'paid'` is the complete list of orders where
         * money arrived and the system could not finish, and it is a list a
         * human must work through.
         */
        /*
         * A successful payment clears the last failed attempt.
         *
         * Retrying a declined card is ordinary. Leaving "บัตรถูกปฏิเสธ" on an
         * order that has since been paid would have an admin chasing a
         * customer who already paid — a worse outcome than never having
         * recorded the failure at all.
         */
        if ($order->last_payment_error !== null) {
            $order->update(['last_payment_error' => null, 'last_payment_error_at' => null]);
        }

        try {
            return DB::transaction(fn () => $this->orders->confirmPayment($order, $this->actorFor($order)));
        } catch (Throwable $e) {
            Log::critical('Gateway payment received but the order could not be confirmed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'charge_id' => $outcome->chargeId,
                'amount_satang' => $order->amount_satang,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Apply a Failed outcome — recorded, never destructive.
     *
     * A declined card must NOT cancel the order: the customer will very
     * likely try another card in the next thirty seconds, and an order
     * cancelled underneath them turns a retry into a dead link.
     */
    /**
     * A payment attempt the gateway says did not succeed.
     *
     * Recorded ON THE ORDER, not only in a log file. Until 2026-09-03 this
     * method called Log::info() and returned, so an admin looking at an order
     * a customer swore they had tried to pay saw an untouched row and could
     * not tell "never opened the link" from "card declined three times".
     *
     * The STATUS is deliberately untouched: a declined card can be retried on
     * the same /pay link, and moving the order to a terminal state here would
     * cancel sales that were about to complete.
     */
    public function applyFailed(Order $order, WebhookOutcome $outcome): void
    {
        $this->recordAttempt(
            $order,
            $outcome,
            $outcome->failureMessage ?? 'ชำระเงินไม่สำเร็จ',
            'order.gateway_payment_failed',
        );

        Log::info('Gateway payment failed', [
            'order_id' => $order->id,
            'charge_id' => $outcome->chargeId,
            'reason' => $outcome->failureMessage,
        ]);
    }

    /**
     * The customer never paid and the provider closed the attempt.
     *
     * Same treatment as a failure and for the same reason — the order stays
     * open, because opening the same /pay link creates a new session — but
     * with its own wording. "หมดเวลา" and "ชำระเงินไม่สำเร็จ" are different
     * facts about a customer, and an admin deciding whether to chase them
     * needs to know which one happened.
     */
    public function applyExpired(Order $order, WebhookOutcome $outcome): void
    {
        $this->recordAttempt(
            $order,
            $outcome,
            $outcome->failureMessage ?? 'ลูกค้าไม่ได้ชำระเงินภายในเวลาที่กำหนด',
            'order.gateway_checkout_expired',
        );

        Log::info('Gateway checkout session expired', [
            'order_id' => $order->id,
            'charge_id' => $outcome->chargeId,
        ]);
    }

    /**
     * The gateway says money went back to the customer.
     *
     * ── WHAT THIS DOES NOT DO ──
     *
     * It does not set `refunded_at`, `refund_reason` or the order's status,
     * and it does not touch the commission ledger. Reversing a sale reverses
     * an agent's commission, BR-4 ledger rows are immutable, and the reversal
     * is its own entry with its own rules (CommissionReversalService). Letting
     * a webhook start that would claw money out of an agent's balance on an
     * event nobody in the company reviewed.
     *
     * ── WHAT IT DOES INSTEAD ──
     *
     * It writes the claim where a human will see it: on the order, and in the
     * audit trail at warning level. Before this, the only record was a line
     * in laravel.log that nobody reads unless they already suspect something.
     */
    public function applyRefunded(Order $order, WebhookOutcome $outcome): void
    {
        $order->update([
            'refund_reported_at' => now(),
            'refund_reported_satang' => $outcome->amountSatang,
        ]);

        AuditLog::create([
            'company_id' => $order->company_id,
            // NULL: no person did this. Inventing a system user would put a
            // fake actor in a trail whose whole value is naming real ones.
            'actor_user_id' => null,
            'action' => 'order.gateway_refund_reported',
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'new_values' => [
                'charge_id' => $outcome->chargeId,
                'amount_satang' => $outcome->amountSatang,
                'provider' => $order->payment_provider?->value,
            ],
        ]);

        Log::warning('Refund reported by gateway — needs a human decision', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'charge_id' => $outcome->chargeId,
            'amount_satang' => $outcome->amountSatang,
        ]);

        $this->tellTheAdmins($order, $outcome->amountSatang);
    }

    /**
     * Email every Company Admin that a refund was reported.
     *
     * ── WHY THIS EXISTS AT ALL ──
     *
     * Writing the fact on the order and in the audit trail only helps
     * somebody who is already looking. A refund is precisely the event
     * nobody is looking for: the sale closed, the commission was paid, the
     * screen was last opened days ago. Without a push, "how would an admin
     * find out" has no answer — which was the state of this code until
     * 2026-09-03.
     *
     * ── WHY IT CANNOT THROW ──
     *
     * This runs inside Stripe's webhook request. An SMTP failure escaping
     * here becomes a non-2xx, Stripe retries the same event for hours, and
     * every retry re-runs applyRefunded — mailing again on each one if the
     * mail server has recovered by then. The refund is already RECORDED by
     * the time we get here, so a mail that could not be sent must not undo
     * that or turn a delivered webhook into a failed one.
     *
     * withoutGlobalScopes: a system-level "who administers this company"
     * lookup with the company already fixed from the signed webhook's URL,
     * the same pattern and the same reasoning as
     * NotifyCompanyAdminsOfPendingAgent.
     */
    private function tellTheAdmins(Order $order, ?int $amountSatang): void
    {
        try {
            $admins = User::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('role', UserRole::CompanyAdmin->value)
                ->get();

            if ($admins->isEmpty()) {
                // Worth a line: a company with no admin cannot be told
                // anything, and that is a configuration problem somebody
                // needs to know about rather than silence.
                Log::warning('Refund reported but this company has no Company Admin to tell', [
                    'company_id' => $order->company_id,
                    'order_number' => $order->order_number,
                ]);

                return;
            }

            Notification::send($admins, new GatewayRefundReportedNotification($order, $amountSatang));
        } catch (Throwable $e) {
            Log::error('Refund recorded but the admin notification failed to send', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The shared write behind applyFailed() and applyExpired().
     *
     * `last_payment_error` holds the LATEST attempt only; the full history is
     * the audit trail, which is append-only and already the place this
     * codebase keeps money-adjacent events (§6). Two homes for the same fact
     * would drift; one summary plus one history does not.
     */
    private function recordAttempt(Order $order, WebhookOutcome $outcome, string $message, string $action): void
    {
        $order->update([
            'last_payment_error' => $message,
            'last_payment_error_at' => now(),
        ]);

        AuditLog::create([
            'company_id' => $order->company_id,
            'actor_user_id' => null,
            'action' => $action,
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'new_values' => [
                'charge_id' => $outcome->chargeId,
                'amount_satang' => $outcome->amountSatang,
                'message' => $message,
                'provider' => $order->payment_provider?->value,
            ],
        ]);
    }

    private function actorFor(Order $order): User
    {
        return $order->agent ?? $order->agent()->withTrashed()->firstOrFail();
    }
}
