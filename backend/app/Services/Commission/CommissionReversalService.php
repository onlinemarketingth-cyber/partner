<?php

namespace App\Services\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SECURITY AUDIT 2026-08-21 (V15, human ruling D3) — refund a paid order.
 *
 * ── THE SHAPE OF THE ANSWER ──
 *
 * The original commission rows are not touched. For each one, a second row
 * is written with the negative of its amount, pointing back at it. The
 * ledger then says two true things in sequence — "this was earned" and
 * "this was taken back" — instead of one edited half-truth, and every
 * SUM(amount_satang) in the application nets to the right number without a
 * single query changing.
 *
 * ── WHY EVERY ROW, NOT JUST THE AGENT'S ──
 *
 * One sale can pay five people: the selling agent, their upline override,
 * a co-agent split, a matrix or generation override, a binary volume
 * credit. Reversing only the direct commission would leave the overrides
 * standing on a sale that no longer exists — and those are the rows
 * nobody would think to check, because nobody remembers they were written.
 * The reversal follows referral_id, which is the thread every one of them
 * hangs off.
 *
 * ── ALREADY-PAID COMMISSION IS STILL REVERSED, AND MARKED PAID ──
 *
 * If the money already left the company, the reversing row is created with
 * payment_status = Paid too. That keeps "what do we still owe this agent"
 * (the pending sum) honest: a refund does not create a pending debt in the
 * agent's favour, and it must not quietly reduce a payout run that has
 * already gone out of the door. Recovering money already paid to an agent
 * is a conversation between humans, not a row this service can invent.
 */
class CommissionReversalService
{
    /**
     * Refund a paid order and reverse every commission it generated.
     *
     * @return list<CommissionLedger> the reversing entries written
     */
    public function refundOrder(Order $order, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            /*
             * Re-read under a lock before deciding anything.
             *
             * Two admins clicking refund on the same order at the same
             * moment is not exotic — it is what happens when the first
             * click looks slow. Without this, both would pass the status
             * check and both would write a full set of reversals; the
             * unique index on reverses_commission_ledger_id would then
             * turn the second one into a 500 rather than a clean refusal.
             */
            $order = Order::withoutGlobalScopes()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->status === OrderStatus::Refunded) {
                throw ValidationException::withMessages([
                    'status' => 'คำสั่งซื้อนี้ถูกคืนเงินไปแล้ว',
                ]);
            }

            if ($order->status !== OrderStatus::Paid) {
                throw ValidationException::withMessages([
                    'status' => 'คืนเงินได้เฉพาะคำสั่งซื้อที่ชำระเงินแล้วเท่านั้น (สถานะปัจจุบัน: '.$order->status->label().')',
                ]);
            }

            $originals = $order->referral_id === null
                ? []
                : CommissionLedger::withoutGlobalScopes()
                    ->where('referral_id', $order->referral_id)
                    ->where('earned_via', '!=', CommissionEarnedVia::Reversal)
                    // A row that has already been reversed is skipped rather
                    // than reversed twice. Belt and braces with the unique
                    // index — this gives the clean answer, the index makes
                    // the unclean one impossible.
                    ->whereDoesntHave('reversal')
                    ->get()
                    ->all();

            $reversals = [];

            foreach ($originals as $original) {
                $reversals[] = CommissionLedger::create([
                    'company_id' => $original->company_id,
                    'agent_id' => $original->agent_id,
                    'referral_id' => $original->referral_id,
                    'cert_tier_id_at_time' => $original->cert_tier_id_at_time,
                    'product_id' => $original->product_id,
                    // The snapshot columns are copied verbatim, not
                    // recomputed. A reversal has to describe the sale as it
                    // was priced THEN, or the pair no longer sums to zero.
                    'sale_price_satang_at_time' => $original->sale_price_satang_at_time,
                    'applied_price_promotion_id_at_time' => $original->applied_price_promotion_id_at_time,
                    'rate_type_applied' => $original->rate_type_applied,
                    'rate_applied' => $original->rate_applied,
                    'amount_satang' => -$original->amount_satang,
                    'payment_status' => $original->payment_status,
                    'paid_at' => $original->payment_status === PaymentStatus::Paid ? now() : null,
                    'earned_via' => CommissionEarnedVia::Reversal,
                    'override_source_agent_id' => $original->override_source_agent_id,
                    'reverses_commission_ledger_id' => $original->id,
                ]);
            }

            $order->update([
                'status' => OrderStatus::Refunded,
                'refunded_at' => now(),
                'refund_reason' => $reason,
                'refunded_by_user_id' => $actor->id,
            ]);

            /*
             * The referral's stage is deliberately LEFT WHERE IT IS.
             *
             * Walking a pipeline backwards is not a thing this system can
             * do — PipelineService only advances — and a refund is not the
             * place to invent it. The sale did reach Complete Payment; that
             * is history and stays true. What changed is the money, and the
             * money is in the ledger and on the order, both of which now
             * say so.
             */

            AuditLog::create([
                'company_id' => $order->company_id,
                'actor_user_id' => $actor->id,
                'action' => 'order.refunded',
                'auditable_type' => Order::class,
                'auditable_id' => $order->id,
                'old_values' => ['status' => OrderStatus::Paid->value],
                'new_values' => [
                    'status' => OrderStatus::Refunded->value,
                    'reason' => $reason,
                    'amount_satang' => $order->amount_satang,
                    'commission_entries_reversed' => count($reversals),
                    'commission_satang_reversed' => array_sum(array_map(
                        static fn (CommissionLedger $entry): int => $entry->amount_satang,
                        $reversals,
                    )),
                ],
                'ip_address' => request()?->ip(),
            ]);

            return $reversals;
        });
    }
}
