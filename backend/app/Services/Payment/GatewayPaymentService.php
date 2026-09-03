<?php

namespace App\Services\Payment;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Notification as InAppNotification;
use App\Models\Order;
use App\Models\User;
use App\Notifications\GatewayRefundReportedNotification;
use App\Services\Notification\NotificationService;
use App\Services\Order\OrderService;
use App\Services\Payment\Gateways\GatewayException;
use App\Services\Payment\Gateways\PaymentIntent;
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
        private readonly NotificationService $notifications,
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
         * THE COMPANY'S CURRENT GATEWAY DECIDES — not the order's stamp.
         *
         * 2026-09-03: this used to compare the order's stamped provider with
         * the company's active one and refuse if they differed. That made
         * sense while an order was BORN belonging to one gateway. It no
         * longer is: every order starts on the transfer flow and the customer
         * chooses a card at pay time, so the stamp is now a RECORD of how
         * money arrived, written here, rather than a rule about how it may.
         *
         * Still fails CLOSED when the company has no online gateway switched
         * on — a card form must never charge through a route nobody chose —
         * and the customer keeps the transfer instructions either way.
         */
        $config = $this->gateways->activeConfig($order->company);

        if ($config === null) {
            throw new GatewayException('ขณะนี้ร้านค้ายังไม่เปิดรับชำระด้วยบัตร กรุณาโอนเงินตามรายละเอียดในหน้านี้');
        }

        $provider = $config['provider'];

        /*
         * Stamped BEFORE the charge, so an order whose charge times out
         * mid-request still says which gateway is holding the customer's
         * money. A stamp written only on success would leave exactly the
         * orders somebody has to investigate looking like transfer orders.
         */
        $order = $this->stampProvider($order, $provider, $config['is_live']);

        $outcome = $this->registry
            ->driver($provider)
            ->charge($order, $config['credentials'], $paymentToken);

        if ($outcome->result !== WebhookResult::Paid) {
            throw new GatewayException($outcome->failureMessage ?? 'ชำระเงินไม่สำเร็จ');
        }

        return $this->applyPaid($order, $outcome) ?? $order->fresh();
    }

    /**
     * The customer chose to pay online — start it, and record the choice.
     *
     * ── WHY THIS IS A DELIBERATE ACT AND NOT PART OF LOADING THE PAY PAGE ──
     *
     * A redirect gateway creates a real, chargeable checkout session the
     * moment startPayment() is called. Doing that while merely RENDERING the
     * page would open one session per page view — for every customer,
     * including the great majority who then transfer instead — and each of
     * those sessions later expires and announces itself by webhook. The
     * result would be a stream of "ลูกค้าไม่ได้ชำระเงินภายในเวลาที่กำหนด"
     * warnings about orders that were paid by slip hours earlier.
     *
     * So the session is created when the customer PRESSES the card button,
     * which is also the moment the order stops being a transfer order.
     *
     * @return array{0: Order, 1: PaymentIntent}
     *
     * @throws GatewayException
     */
    public function beginOnlinePayment(Order $order): array
    {
        // Same two refusals as chargeWithToken, in the same order and for the
        // same reasons — a page that offers to start a payment on a paid
        // order is a page that takes a second one.
        if ($order->hasGatewayPayment()) {
            throw new GatewayException('คำสั่งซื้อนี้ได้รับการชำระเงินแล้ว');
        }

        if (! $order->isPayable()) {
            throw new GatewayException('คำสั่งซื้อนี้ไม่อยู่ในสถานะที่ชำระเงินได้');
        }

        $config = $this->gateways->activeConfig($order->company);

        if ($config === null) {
            throw new GatewayException('ขณะนี้ร้านค้ายังไม่เปิดรับชำระด้วยบัตร กรุณาโอนเงินตามรายละเอียดในหน้านี้');
        }

        $provider = $config['provider'];
        $order = $this->stampProvider($order, $provider, $config['is_live']);

        $intent = $this->registry
            ->driver($provider)
            ->startPayment($order, $config['credentials'], $config['is_live']);

        return [$order, $intent];
    }

    /**
     * Record which gateway is taking this order's money, and in which mode.
     *
     * `gateway_mode` matters as much as the provider: a charge made with a
     * test key is not revenue, and without this an order paid in test mode is
     * indistinguishable from a real sale in every report the system produces.
     *
     * A no-op when nothing changed, so a customer who opens the card form
     * three times does not write the same row three times.
     */
    private function stampProvider(Order $order, PaymentProvider $provider, bool $isLive): Order
    {
        $mode = $isLive ? 'live' : 'test';

        if ($order->payment_provider === $provider && $order->gateway_mode === $mode) {
            return $order;
        }

        $order->update([
            'payment_provider' => $provider->value,
            'gateway_mode' => $mode,
        ]);

        return $order;
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
        if ($this->attemptNoLongerMatters($order, 'failed', $outcome)) {
            return;
        }

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

        $this->tellTheAgent(
            $order,
            NotificationType::OrderPaymentFailed,
            'ลูกค้าชำระเงินไม่สำเร็จ',
            "คำสั่งซื้อ {$order->order_number}: ".($outcome->failureMessage ?? 'ชำระเงินไม่สำเร็จ')
                .' — ลิงก์ชำระเงินเดิมยังใช้ได้ ลูกค้าลองใหม่ได้เลย',
        );
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
        if ($this->attemptNoLongerMatters($order, 'expired', $outcome)) {
            return;
        }

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

        $this->tellTheAgent(
            $order,
            NotificationType::OrderPaymentFailed,
            'ลูกค้ายังไม่ได้ชำระเงิน',
            "คำสั่งซื้อ {$order->order_number}: ลูกค้าเปิดหน้าชำระเงินแล้วแต่ไม่ได้จ่ายจนหมดเวลา"
                .' — ลิงก์ชำระเงินเดิมยังใช้ได้ ลองติดต่อกลับได้เลย',
        );
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

        $this->tellTheAgent(
            $order,
            NotificationType::OrderRefundReported,
            'ผู้ให้บริการแจ้งการคืนเงิน',
            "คำสั่งซื้อ {$order->order_number} ถูกแจ้งคืนเงิน — ค่าคอมมิชชั่นของคุณยังไม่ถูกกลับรายการ"
                .' ผู้ดูแลกำลังตรวจสอบและจะเป็นผู้ตัดสินใจ',
            // Refunds are rare and each one matters to this agent's money, so
            // unlike a retried card this is NOT collapsed to one a day.
            once: false,
        );
    }

    /**
     * Tell the agent who owns this order.
     *
     * ── WHY THE AGENT, NOT ONLY THE ADMIN ──
     *
     * A declined card, an abandoned checkout and a refund are all things
     * somebody has to ACT on, and the person who can act is the one who sold
     * to that customer. Admins were being told about the money; the agent
     * was the one person the payment path never spoke to, while being the
     * only one who would pick up the phone.
     *
     * NotificationService is the right vehicle here (unlike for admins, who
     * get a mail): it writes the in-app row the agent portal reads AND
     * emails, honouring the agent's own email preference, which an admin
     * alert does not have to.
     *
     * ── $once ──
     *
     * A customer fumbling a card produces one webhook per attempt. Three
     * mails saying the same thing in five minutes is how an agent learns to
     * delete mail from this system unread, so a failure collapses to one per
     * order per day. A refund does not: it is rare, and each one is about
     * this agent's own money.
     *
     * ── IT CANNOT THROW ──
     *
     * Same rule as tellTheAdmins(): this runs inside the provider's webhook
     * request, the fact is already recorded, and a notification failure must
     * not turn a delivered webhook into a retry storm.
     */
    private function tellTheAgent(
        Order $order,
        NotificationType $type,
        string $title,
        string $body,
        bool $once = true,
    ): void {
        try {
            $agent = $order->agent;

            if ($agent === null) {
                return;
            }

            if ($once && $this->alreadyToldToday($agent->id, $type, $order->id)) {
                return;
            }

            $this->notifications->notify(
                $agent,
                $type,
                $title,
                $body,
                '/orders',
                ['order_id' => $order->id],
            );
        } catch (Throwable $e) {
            Log::error('Gateway outcome recorded but the agent notification failed', [
                'order_id' => $order->id,
                'type' => $type->value,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Has this agent already been told about this order today?
     *
     * Keyed on the ORDER, from the notification's own `data` payload, so two
     * different orders failing on the same day are two notifications — it is
     * repetition about one order that is noise, not volume in general.
     */
    private function alreadyToldToday(int $agentId, NotificationType $type, int $orderId): bool
    {
        return InAppNotification::withoutGlobalScopes()
            ->where('user_id', $agentId)
            ->where('type', $type->value)
            ->where('data->order_id', $orderId)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
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
     * Is this bad-news event about an order that has already moved on?
     *
     * ── WHY THIS EXISTS (2026-09-03) ──
     *
     * Since the customer now chooses their method on the pay page, an
     * abandoned card checkout on an order that was then PAID BY BANK
     * TRANSFER is completely normal traffic. Its expiry webhook arrives
     * hours later, and without this guard it would stamp
     * "ลูกค้าไม่ได้ชำระเงินภายในเวลาที่กำหนด" onto a paid order and tell the
     * agent to chase a customer who has already paid.
     *
     * The same is true of a decline that arrives after a second card
     * succeeded, or of any attempt on an order somebody has since cancelled.
     *
     * Info, not warning: this is expected, and an alarm that fires on
     * expected traffic teaches people to ignore alarms.
     */
    private function attemptNoLongerMatters(Order $order, string $kind, WebhookOutcome $outcome): bool
    {
        if (! $order->hasGatewayPayment() && $order->isPayable()) {
            return false;
        }

        Log::info('Ignored a late gateway attempt on an order that has moved on', [
            'order_id' => $order->id,
            'kind' => $kind,
            'status' => $order->status->value,
            'has_gateway_payment' => $order->hasGatewayPayment(),
            'charge_id' => $outcome->chargeId,
        ]);

        return true;
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
