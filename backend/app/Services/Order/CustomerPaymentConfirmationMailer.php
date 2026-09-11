<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Mail\OrderPaymentConfirmedMail;
use App\Models\Order;
use App\Services\Platform\PlatformMailSettingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 2026-09-11 (human: "2. ถ้าขึ้นสถานะสำเร็จ ต้องส่ง email ให้ลูกค้า — ยังไม่ส่ง").
 *
 * ── THE BUG THIS CLASS EXISTS TO END ──
 *
 * An order can become Paid by three different routes, and until today only
 * ONE of them emailed the customer:
 *
 *   1. an admin presses "ยืนยันการชำระเงิน"  → OrderController::confirm()  ✔ mailed
 *   2. the gateway says paid (webhook/charge) → GatewayPaymentService::applyPaid()  ✘ silent
 *   3. `payments:retry-stuck-confirmations`   → RetryStuckGatewayConfirmationsCommand  ✘ silent
 *
 * Route 2 is the one customers actually use. A customer paid by card, the
 * order went Paid, the agent got their in-app notification — and the person
 * who had just handed over money got nothing, with the voucher code they
 * bought sitting on a page they had already closed.
 *
 * The send lived in a CONTROLLER, so nothing that was not an HTTP request
 * from an admin could reach it. That is the whole cause. It lives here now,
 * and every route that marks an order paid calls this one method, so a fourth
 * route added next year gets the email by writing one line rather than by
 * remembering a thirty-line block exists.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DO ──
 *
 * It is NOT called from inside OrderService::confirmPayment(), which would be
 * the tempting place — one call site, impossible to forget. confirmPayment()
 * runs its work inside a DB transaction (TASK-190 §4.3): a slow or failing
 * SMTP handshake would hold that transaction open, and worse, a rollback
 * AFTER a successful send would leave a customer holding a confirmation for
 * an order that does not exist. An email cannot be un-sent. So every caller
 * invokes this AFTER its transaction has committed, and the cost of that rule
 * is the one line each caller has to write.
 */
final class CustomerPaymentConfirmationMailer
{
    public function __construct(
        private readonly PlatformMailSettingService $mailSettings,
    ) {}

    /**
     * Email the customer that their payment went through — best effort.
     *
     * Never throws. Every caller is at a point where the money has already
     * been taken and the order is already committed as paid; a mail failure
     * must not turn that into an error for an admin who confirmed a real
     * payment, and must not turn a webhook into a non-2xx that the gateway
     * then retries for hours.
     */
    public function send(Order $order): void
    {
        /*
         * Only ever for an order that really is paid. The guard is here
         * rather than at each call site because "we told a customer their
         * payment succeeded" is the single most expensive thing in this file
         * to get wrong, and a caller that confirms conditionally (the retry
         * command repairs journeys as it goes) should not have to remember.
         */
        if ($order->status !== OrderStatus::Paid) {
            return;
        }

        // Reloaded, not loadMissing()'d: the voucher is minted DURING the
        // confirmation that just ran, so a relation loaded before it would be
        // a cached `null` — an email announcing a voucher with no code in it.
        $order->load('voucher');
        $order->loadMissing('client');

        // No address to send to. Ordinary: a customer's email is optional on
        // orders an agent keys in by hand (it is required only at public
        // checkout). The agent's in-app notification, fired inside
        // confirmPayment() itself, is the delivery that is always there.
        if (! filled($order->client?->email)) {
            return;
        }

        /*
         * Platform mail switched off means `mail.default` is still the `log`
         * mailer (MailSettingsService::applyRuntimeConfig fails closed), so
         * sending here would write the customer's confirmation into a log
         * file and report success. Skipping is the honest version of the
         * same outcome.
         */
        if (! ($this->mailSettings->get()['is_enabled'] ?? false)) {
            return;
        }

        try {
            Mail::to($order->client->email)->send(new OrderPaymentConfirmedMail($order));
        } catch (Throwable $e) {
            // Logged with the order number because this log line is read by a
            // person answering "the customer says they never got the email".
            // The exception message is transport diagnostics (host, auth),
            // never the SMTP password — Symfony's TransportException does not
            // carry it (PlatformMailSettingController's docblock checks this).
            Log::error('TASK-190: OrderPaymentConfirmedMail failed to send', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
