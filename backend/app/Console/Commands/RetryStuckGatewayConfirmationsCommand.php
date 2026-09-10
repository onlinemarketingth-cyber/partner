<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use App\Services\Order\OrderService;
use App\Services\Pipeline\PipelineTemplateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-10 (human, from production: "1 คำสั่งซื้อผ่าน ORD-MWJTV2QV สำเร็จ
 * แล้ว … แต่ระบบขึ้นรอชำระ").
 *
 * ── WHAT THAT ORDER IS ──
 *
 * The card was charged. GatewayPaymentService claimed the charge id, called
 * OrderService::confirmPayment(), and confirmPayment REFUSED — its rule is
 * that the referral's next step must be ชำระเงิน, and this referral's
 * snapshotted journey was Medical Package, whose next step is รอนัดหมาย. The
 * refusal is caught on purpose (a throw there becomes a webhook retry loop,
 * or a "payment failed" shown to somebody who has just been charged), so the
 * order kept its receipt and sat in the "ได้รับเงินแล้วแต่ยืนยันไม่สำเร็จ"
 * queue waiting for a person.
 *
 * ── WHY DEPLOYING THE JOURNEY FIX DOES NOT CLEAR IT ──
 *
 * A referral SNAPSHOTS its journey when it is created (ADR-026 §3.4) — on
 * purpose, so a product's journey changing mid-sale cannot move a customer
 * onto a different one halfway. Correct, and it means the orders taken while
 * the product had the wrong journey keep the wrong journey forever. Fixing
 * the product fixes the next sale, not the ones already made.
 *
 * ── WHAT THIS COMMAND DOES, AND THE LINE IT WILL NOT CROSS ──
 *
 * For each stuck order it re-resolves the product's journey as it stands
 * today, and re-snapshots the referral onto it — but ONLY when that referral
 * has not moved at all (`current_stage` is still the entry stage). A referral
 * that has been through an appointment has a history on its old journey, and
 * rewriting the journey underneath it would silently discard steps a person
 * actually performed. Those are reported and left alone, for a human.
 *
 * Then it retries the SAME confirmPayment() the gateway called. Everything
 * that makes a sale a sale — BR-4 commission, the voucher, the agent's
 * notification — happens there and nowhere else, so nothing here writes
 * `status = paid` by hand.
 */
class RetryStuckGatewayConfirmationsCommand extends Command
{
    protected $signature = 'payments:retry-stuck-confirmations
                            {--dry-run : Report what would happen and change nothing}
                            {--order= : Only this order number, e.g. ORD-MWJTV2QV}';

    protected $description = 'Close the sales where a gateway payment arrived but the order could not be confirmed';

    public function handle(OrderService $orders, PipelineTemplateResolver $resolver): int
    {
        // The same condition the admin screen's "ได้รับเงินแล้วแต่ยืนยันไม่
        // สำเร็จ" tab shows (OrderController: ?needs_attention=1), so what
        // this command works on is exactly what a person is looking at.
        $query = Order::withoutGlobalScope(TenantScope::class)
            ->whereNotNull('gateway_charge_id')
            ->where('status', '!=', OrderStatus::Paid->value);

        if ($this->option('order') !== null) {
            $query->where('order_number', $this->option('order'));
        }

        $stuck = $query->orderBy('id')->get();

        if ($stuck->isEmpty()) {
            $this->info('ไม่มีคำสั่งซื้อที่ได้รับเงินแล้วแต่ยืนยันไม่สำเร็จ — ไม่ต้องทำอะไร');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line("พบคำสั่งซื้อที่ค้างอยู่ {$stuck->count()} รายการ".($dryRun ? ' (dry-run)' : ''));

        $confirmed = 0;
        $needsAPerson = 0;

        foreach ($stuck as $order) {
            $referral = Referral::withoutGlobalScope(TenantScope::class)->find($order->referral_id);

            if (! $referral) {
                $this->warn("  ✗ {$order->order_number} — ไม่พบรายการอ้างอิง");
                $needsAPerson++;

                continue;
            }

            $repaired = $this->repairJourney($referral, $resolver, $dryRun);

            if ($repaired === false) {
                $this->warn("  ✗ {$order->order_number} — รายการอ้างอิงเดินไปแล้วถึงขั้น \"{$referral->current_stage->value}\" · ไม่แก้เส้นทางให้อัตโนมัติ ต้องให้คนตัดสินใจ");
                $needsAPerson++;

                continue;
            }

            if ($dryRun) {
                $this->line("  · {$order->order_number} — จะยืนยันการชำระเงิน".($repaired ? ' (หลังปรับเส้นทางให้ตรงกับสินค้า)' : '').' (dry-run, ยังไม่บันทึก)');
                $confirmed++;

                continue;
            }

            try {
                // The agent who owns the sale, exactly as the gateway path
                // records it — `payment_provider` on the row is what says a
                // machine, not a person, produced the proof.
                $actor = $referral->agent ?? $order->agent;

                if (! $actor) {
                    throw new \RuntimeException('ไม่พบตัวแทนเจ้าของรายการ');
                }

                DB::transaction(fn () => $orders->confirmPayment($order, $actor));

                $this->info("  ✓ {$order->order_number} — ยืนยันการชำระเงินแล้ว".($repaired ? ' (ปรับเส้นทางให้ตรงกับสินค้าแล้ว)' : ''));
                $confirmed++;
            } catch (\Throwable $e) {
                $this->warn("  ✗ {$order->order_number} — ยืนยันไม่สำเร็จ: ".$e->getMessage());
                $needsAPerson++;
            }
        }

        $this->newLine();
        $this->line($dryRun
            ? "สรุป (dry-run): จะยืนยันได้ {$confirmed} · ต้องให้คนดู {$needsAPerson}"
            : "สรุป: ยืนยันแล้ว {$confirmed} · ต้องให้คนดู {$needsAPerson}");

        return self::SUCCESS;
    }

    /**
     * Point an untouched referral at its product's CURRENT journey.
     *
     * @return bool|null true = re-snapshotted, false = refused (it has moved),
     *                   null = nothing to change
     */
    private function repairJourney(Referral $referral, PipelineTemplateResolver $resolver, bool $dryRun): ?bool
    {
        $product = Product::withoutGlobalScope(SharedOrTenantScope::class)->find($referral->product_id);

        if (! $product) {
            return null;
        }

        $journeyId = $resolver->resolveForProduct($product, (int) $referral->company_id)?->id;

        if ($journeyId === null || $journeyId === $referral->pipeline_template_id) {
            return null;
        }

        /*
         * THE LINE. A referral still standing where it was created has no
         * history on its old journey to lose; one that has moved does, and no
         * command should quietly delete steps a person performed.
         */
        if ($referral->current_stage !== PipelineStage::CompleteRegistered) {
            return false;
        }

        if (! $dryRun) {
            $referral->forceFill(['pipeline_template_id' => $journeyId])->save();
        }

        return true;
    }
}
