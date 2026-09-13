<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Order;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\Referral;
use App\Services\Commission\CommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * 2026-09-11 (human: "มีลูกค้าชำระเงินมาจำนวนมากแล้วทำไมไม่ได้ค่าคอม").
 *
 * ── WHY THIS CANNOT BE ANSWERED FROM A SCREEN ──
 *
 * A missing commission is a NEGATIVE: there is no row, so there is nothing
 * for any screen to show. CommissionService is deliberately silent when it
 * cannot compute one — it logs a warning and returns null rather than
 * blocking the sale, because a missing rate is a finance data gap and not a
 * reason to stop recording that a customer paid. That is the right call and
 * it has a cost: the failure leaves no trace anybody but a developer with
 * SSH can read.
 *
 * This command reads the same five gates the Service walks, in the same
 * order, and names the FIRST one that closed. Read-only: it writes nothing,
 * changes nothing, and takes no flag that could.
 *
 * ── THE FIVE GATES, IN THE ORDER THEY ARE CHECKED ──
 *
 *  1. Is there a referral at all? (an order with none can never pay anybody)
 *  2. Did the referral ever TRANSITION INTO "ชำระเงินแล้ว"? Commission fires
 *     on the transition, not on the state — PipelineService::advance() calls
 *     recordForReferral() only when `$toStage === CompletePayment`. A
 *     referral already at or past that stage when its order was confirmed
 *     gets marked paid, gets its voucher, and never triggers a commission,
 *     because OrderService::confirmPayment() skips the advance
 *     (`if (! $alreadyClosed)`) to keep a re-confirm from paying twice.
 *  3. Has the agent passed a cert tier? (BR-1 — no tier, no commission)
 *  4. Is there an active commission_rule for the product, or its category,
 *     or a company-wide default? (BR-2)
 *  5. Everything passed and there is still no ledger row — which should be
 *     impossible, and is therefore the answer worth knowing about.
 *
 * ── THE CROSS-COMPANY CHECK, AND WHY IT IS NOW A REGRESSION GUARD ──
 *
 * This command used to be the only thing that could SEE a defect it could not
 * fix: resolveCommissionRule() leaned on TenantScope, which is a no-op for a
 * Super Admin and for a gateway confirmation, so the lookup could return
 * another company's rule and pay an agent at a rate nobody at their company
 * had set. The warning below said so from 2026-09-11 and nothing acted on it
 * until the owner hit the same bug through the admin screen on 2026-09-12.
 *
 * resolveCommissionRule() now takes the company as an argument
 * (CrossCompanyRateIsolationTest), so the two answers compared below agree by
 * construction. The comparison is KEPT rather than deleted: it costs one query
 * per order and it is the only thing that would notice the day somebody makes
 * the resolver ambient again.
 */
class ExplainCommissionGapCommand extends Command
{
    protected $signature = 'commissions:explain
                            {--order= : ตรวจเฉพาะคำสั่งซื้อนี้ เช่น ORD-8CDEHHGY}
                            {--company= : จำกัดเฉพาะบริษัทนี้ (id)}
                            {--limit=30 : ดูคำสั่งซื้อที่ชำระแล้วล่าสุดกี่รายการ}
                            {--all : แสดงทุกรายการ รวมที่ได้ค่าคอมถูกต้องแล้ว}';

    protected $description = 'Explain why paid orders did or did not produce a commission (read-only)';

    public function handle(CommissionService $commissions): int
    {
        $orders = $this->paidOrders();

        if ($orders->isEmpty()) {
            $this->warn('ไม่พบคำสั่งซื้อที่ชำระเงินแล้วตามเงื่อนไขนี้');

            return self::SUCCESS;
        }

        $rows = [];
        $reasons = [];
        $withCommission = 0;
        $crossCompany = 0;

        foreach ($orders as $order) {
            $referral = $order->referral_id
                ? Referral::withoutGlobalScopes()->with(['agent', 'product'])->find($order->referral_id)
                : null;

            $ledger = $referral
                ? CommissionLedger::withoutGlobalScopes()->where('referral_id', $referral->id)->first()
                : null;

            if ($ledger !== null) {
                $withCommission++;

                if (! $this->option('all')) {
                    continue;
                }

                $rows[] = [
                    $order->order_number,
                    $order->paid_at?->format('d/m/Y'),
                    $referral?->agent?->name ?? '—',
                    number_format($ledger->amount_satang / 100, 2),
                    'ได้ค่าคอมแล้ว',
                ];

                continue;
            }

            $reason = $this->diagnose($order, $referral, $commissions, $crossCompany);
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

            $rows[] = [
                $order->order_number,
                $order->paid_at?->format('d/m/Y'),
                $referral?->agent?->name ?? '—',
                '—',
                $reason,
            ];
        }

        $this->line('');
        $this->info('ตรวจคำสั่งซื้อที่ชำระเงินแล้ว '.$orders->count().' รายการ');
        $this->line('');

        if ($rows !== []) {
            $this->table(
                ['เลขที่คำสั่งซื้อ', 'ชำระเมื่อ', 'ตัวแทน', 'ค่าคอม (บาท)', 'ผล / สาเหตุ'],
                $rows,
            );
        }

        $this->line('');
        $this->info('สรุป');
        $this->line("  ได้ค่าคอมเรียบร้อย: {$withCommission} รายการ");

        if ($reasons === []) {
            $this->line('  ไม่มีรายการที่ขาดค่าคอม');
        } else {
            foreach ($reasons as $reason => $count) {
                $this->line("  ไม่ได้ค่าคอม — {$reason}: {$count} รายการ");
            }
        }

        if ($crossCompany > 0) {
            $this->line('');
            /*
             * Worth its own line and its own alarm level: this one is not a
             * configuration gap a person can fix on a screen, it is the
             * lookup reaching outside the company. An order confirmed by the
             * gateway would be paid at a rate nobody at this company set,
             * into a ledger row that can never be edited.
             */
            $this->warn("  ⚠ พบ {$crossCompany} รายการที่อัตราค่าคอมถูกหาเจอจาก 'บริษัทอื่น' เมื่อไม่มีผู้ใช้ล็อกอิน");
            $this->line('     (เกิดกับการชำระผ่านเกตเวย์ ซึ่งไม่มี user ให้ TenantScope ยึด) — แจ้ง ag-lead');
        }

        $this->line('');
        $this->line('  หมายเหตุ: คำสั่งนี้อ่านอย่างเดียว ไม่แก้ไขข้อมูลใด ๆ');
        $this->line('  รายละเอียดเพิ่มเติมของแต่ละใบ: php artisan orders:explain <เลขที่คำสั่งซื้อ>');

        return self::SUCCESS;
    }

    /** The first closed gate, in the order the Service itself walks them. */
    private function diagnose(Order $order, ?Referral $referral, CommissionService $commissions, int &$crossCompany): string
    {
        if ($referral === null) {
            return 'คำสั่งซื้อนี้ไม่มีรายการอ้างอิง (referral)';
        }

        /*
         * GATE 2 — the one that surprises people, so it is checked before the
         * configuration gates even though the Service reaches it first in a
         * different way. Commission fires on the TRANSITION into
         * complete_payment. If the referral was already sitting at or past
         * that stage when the order was confirmed, confirmPayment() skipped
         * the advance on purpose (a re-confirm must not pay twice) and the
         * Service was never called at all — so no warning was logged either,
         * and nothing anywhere records that a commission was skipped.
         */
        $everEnteredPayment = PipelineStageLog::withoutGlobalScopes()
            ->where('referral_id', $referral->id)
            ->where('to_stage', PipelineStage::CompletePayment->value)
            ->exists();

        if (! $everEnteredPayment) {
            return 'ไม่เคยมีการเปลี่ยนสถานะเข้า "ชำระเงินแล้ว" (ค่าคอมจึงไม่ถูกเรียกใช้เลย)';
        }

        // GATE 3 — BR-1.
        if (! $referral->agent?->highestPassedCertTier()) {
            return 'ตัวแทนยังไม่ผ่าน cert tier ใดเลย';
        }

        // GATE 4 — BR-2, asked twice: as the Service asks it, and scoped to
        // this order's own company.
        $product = $referral->product;

        if ($product === null) {
            return 'ไม่พบสินค้าของรายการอ้างอิงนี้';
        }

        $asServiceSees = $commissions->resolveCommissionRule($product, (int) $order->company_id);
        $ownCompanyRule = $this->ruleForCompany($product, (int) $order->company_id);

        if ($ownCompanyRule === null) {
            return 'ไม่มีอัตราค่าคอมของบริษัทนี้ (ทั้งระดับสินค้า หมวดหมู่ และค่าเริ่มต้นบริษัท)';
        }

        if ($asServiceSees !== null && (int) $asServiceSees->company_id !== (int) $order->company_id) {
            $crossCompany++;

            return 'อัตราที่ระบบหาเจอเป็นของบริษัทอื่น (ดูคำเตือนท้ายรายงาน)';
        }

        return 'ผ่านทุกเงื่อนไขแต่ไม่มีแถวค่าคอม — ต้องดู storage/logs';
    }

    /**
     * The same product -> category -> company-wide fallback the Service
     * walks, but filtered by company by hand.
     *
     * withoutGlobalScopes() and an explicit `company_id`, rather than
     * trusting TenantScope: this command runs from a console where there is
     * no authenticated user, which is the exact condition that makes the
     * scope a no-op — see the class docblock.
     */
    private function ruleForCompany(Product $product, int $companyId): ?CommissionRule
    {
        $base = fn () => CommissionRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('effective_from');

        $rule = $base()->where('product_id', $product->id)->first();

        if ($rule) {
            return $rule;
        }

        if ($product->category_id) {
            $rule = $base()->whereNull('product_id')->where('product_category_id', $product->category_id)->first();

            if ($rule) {
                return $rule;
            }
        }

        return $base()->whereNull('product_id')->whereNull('product_category_id')->first();
    }

    /** @return Collection<int, Order> */
    private function paidOrders(): Collection
    {
        $query = Order::withoutGlobalScopes()
            ->where('status', OrderStatus::Paid->value)
            ->orderByDesc('paid_at');

        if ($this->option('order') !== null) {
            return $query->where('order_number', trim((string) $this->option('order')))->get();
        }

        if ($this->option('company') !== null) {
            $query->where('company_id', (int) $this->option('company'));
        }

        return $query->limit(max(1, (int) $this->option('limit')))->get();
    }
}
