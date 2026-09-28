<?php

namespace App\Services\Supplier;

use App\Enums\PaymentStatus;
use App\Enums\SupplierGpMode;
use App\Enums\SupplierReleaseTrigger;
use App\Models\CommissionLedger;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierSettlementLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 2026-09-16 — WHAT A SALE LEAVES US OWING THE SUPPLIER.
 *
 * Owner's formula, verbatim: `ราคาขาย − ค่าคอม − GP` goes to the supplier.
 * This is the only place in the codebase allowed to compute it, for the same
 * reason CommissionService is the only place allowed to compute commission —
 * a second implementation is a second answer, and money questions must have
 * one.
 *
 * ── THE ORDER OF OPERATIONS IS LOAD-BEARING ──
 *
 * Commission comes off FIRST, always. SupplierGpMode::PercentOfNet is defined
 * as a share of what remains after the sellers are paid; computing GP before
 * commission silently turns it into PercentOfSale and nobody would see the
 * difference until a supplier queried their statement.
 *
 * ── WHEN THIS RUNS ──
 *
 * After the commission rows for the order exist, never before. That is why
 * OrderService::confirmPayment() calls it at the END of its transaction,
 * after pipelineService->advance() has fired BR-4. Called any earlier,
 * `commission` reads 0 and the supplier is overpaid on every sale that has
 * an upline — silently, and on every single order.
 */
class SupplierSettlementService
{
    /** Basis points: 30% is stored as 3000. */
    private const BASIS_POINT_SCALE = 10000;

    /**
     * Record what this paid order owes its supplier, or do nothing.
     *
     * Returns null for every ordinary order — the overwhelmingly common case
     * is a product we supply ourselves, and this method is called on every
     * confirmed payment in the system.
     */
    public function recordForOrder(Order $order): ?SupplierSettlementLedger
    {
        $product = $order->product;

        if (! $product?->hasSupplier()) {
            return null;
        }

        /*
         * Idempotency lives on the UNIQUE index over order_id, and this check
         * is the polite half of it. A second row for one sale pays a supplier
         * twice, so the database refuses regardless — but a re-confirm that
         * races past OrderService's own guard should be a no-op, not a
         * constraint violation surfaced to whoever pressed the button.
         */
        $existing = SupplierSettlementLedger::where('order_id', $order->id)
            ->where('entry_kind', SupplierSettlementLedger::KIND_SALE)
            ->first();

        if ($existing) {
            return $existing;
        }

        $supplier = $product->supplier;

        if (! $supplier) {
            // The supplier was deleted after the product was listed (the FK
            // nulls rather than cascades — see the migration). There is no one
            // to owe, and inventing a row pointing nowhere is worse than
            // recording nothing.
            return null;
        }

        $terms = $this->resolveTerms($product, $supplier);

        $sale = (int) $order->amount_satang;
        $commission = $this->commissionFor($order);
        $gp = $this->gp($terms['gp_mode'], (int) $terms['gp_value'], $sale, $commission);

        // SIGNED on purpose. Owner's ruling: where commission and GP together
        // exceed the sale price, "supplier เป็นผู้รับผิดชอบ" — the shortfall
        // is theirs and nets off against their other sales. A max(0, …) here
        // would move that loss onto us and leave no trace of it anywhere.
        $amount = $sale - $commission - $gp;

        return DB::transaction(function () use ($order, $product, $supplier, $terms, $sale, $commission, $gp, $amount) {
            $row = SupplierSettlementLedger::create([
                'supplier_id' => $supplier->id,
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'entry_kind' => SupplierSettlementLedger::KIND_SALE,
                'product_id' => $product->id,
                'sale_price_satang_at_time' => $sale,
                'commission_satang_at_time' => $commission,
                'gp_mode_at_time' => $terms['gp_mode']->value,
                'gp_value_at_time' => $terms['gp_value'],
                'gp_satang_at_time' => $gp,
                'wht_rate_at_time' => $terms['wht_rate'],
                // ADR-048 — the trigger is a term of THIS sale. Release reads
                // it from here, never from the supplier's current deal.
                'release_trigger_at_time' => $terms['trigger']->value,
                'amount_satang' => $amount,
                'released_at' => $this->releaseAtFor($order, $terms['trigger']),
                'payment_status' => PaymentStatus::Pending->value,
            ]);

            /*
             * ── THE LINE THAT KEEPS THE PROFIT REPORT HONEST ──
             *
             * `orders.cost_satang_at_time` is "what this sale cost us", and
             * BusinessOverviewService computes gross profit as
             * revenue − cost − commission over the orders that have one.
             *
             * For a supplier's product the cost IS what we hand back, so:
             *
             *   revenue − (sale − commission − gp) − commission  =  gp
             *
             * which is exactly our margin. Leave this unwritten and the order
             * has no cost at all, so the report either excludes it (losing the
             * sale entirely) or — worse, if anyone ever "fixes" that with a
             * zero — reports the whole sale price as profit.
             *
             * updateQuietly: this is bookkeeping derived from the order, not a
             * change anybody made to it, and it must not wake observers in the
             * middle of a payment confirmation.
             */
            $order->updateQuietly(['cost_satang_at_time' => max(0, $amount)]);

            return $row;
        });
    }

    /**
     * Release rows whose trigger has now fired.
     *
     * Called when a voucher is redeemed and when the recipient confirms a
     * parcel arrived (or the auto-confirm window passes). Returns how many
     * rows moved, so callers can log a surprise rather than assume.
     *
     * ── THE ROW'S OWN TRIGGER, NOT THE DEAL'S CURRENT ONE (ADR-048) ──
     *
     * This used to match the supplier's current `release_trigger`. Change a
     * deal from "on redemption" to "on payment" and every row still waiting
     * for a redemption could never be released again: the redemption now
     * looked for a trigger the deal no longer had. The trigger is snapshotted
     * on the row at sale time, and that is what is matched.
     *
     * A refund row carries its sale's trigger, so the pair is released
     * together and keeps summing to zero.
     *
     * Deliberately narrow: it only ever sets `released_at`, never clears it.
     * Money that has become payable does not become unpayable because
     * something was re-saved.
     */
    public function releaseFor(Order $order, SupplierReleaseTrigger $trigger): int
    {
        return SupplierSettlementLedger::where('order_id', $order->id)
            ->whereNull('released_at')
            ->where('release_trigger_at_time', $trigger->value)
            ->update(['released_at' => now()]);
    }

    /**
     * A refunded order: write the refund row against its supplier sale.
     *
     * Owner (2026-09-27): "คืนเงินลูกค้าแล้ว … หักครั้งถัดไป". The sale row is
     * never edited (same rule as BR-4). Its negative is written beside it:
     *
     *   · sale already payable or paid → the refund row is payable now, so it
     *     comes off the supplier's NEXT payout — including when the sale is
     *     sitting in a payout that has not been transferred yet;
     *   · sale not yet payable (waiting for redemption or receipt) → the
     *     refund row waits with it, and the pair nets to zero in "not yet
     *     payable" instead of paying the supplier for a sale that was undone.
     *
     * Idempotent: a second call finds the refund row and returns it.
     */
    public function reverseForRefund(Order $order): ?SupplierSettlementLedger
    {
        $sale = SupplierSettlementLedger::where('order_id', $order->id)
            ->where('entry_kind', SupplierSettlementLedger::KIND_SALE)
            ->lockForUpdate()
            ->first();

        if (! $sale) {
            return null;
        }

        $existing = SupplierSettlementLedger::where('reverses_ledger_id', $sale->id)->first();

        if ($existing) {
            return $existing;
        }

        return SupplierSettlementLedger::create([
            'supplier_id' => $sale->supplier_id,
            'company_id' => $sale->company_id,
            'order_id' => $sale->order_id,
            'entry_kind' => SupplierSettlementLedger::KIND_REFUND,
            'reverses_ledger_id' => $sale->id,
            'product_id' => $sale->product_id,
            // Copied verbatim, like CommissionReversalService does: the refund
            // describes the sale as it was priced then, so the pair reads as a
            // pair.
            'sale_price_satang_at_time' => $sale->sale_price_satang_at_time,
            'commission_satang_at_time' => $sale->commission_satang_at_time,
            'gp_mode_at_time' => $sale->gp_mode_at_time,
            'gp_value_at_time' => $sale->gp_value_at_time,
            'gp_satang_at_time' => $sale->gp_satang_at_time,
            'wht_rate_at_time' => $sale->wht_rate_at_time,
            'release_trigger_at_time' => $sale->release_trigger_at_time,
            'amount_satang' => -$sale->amount_satang,
            'released_at' => $sale->released_at !== null ? now() : null,
            'payment_status' => PaymentStatus::Pending->value,
        ]);
    }

    /**
     * The deal terms for THIS product: its own overrides, else the supplier's.
     *
     * ── WHY THIS THROWS INSTEAD OF DEFAULTING ──
     *
     * BR-7. A missing GP is not zero. Zero means "we take no margin on this
     * product", which is a decision somebody would have had to make, and
     * nobody has. Defaulting would put a number nobody agreed to into an
     * immutable ledger row, and BR-4 means it could never be corrected.
     *
     * The failure is loud and early — a product with a supplier and no GP
     * should be caught when it is listed, not when it sells. The request
     * classes enforce that; this is the backstop for every other path.
     *
     * @return array{gp_mode: SupplierGpMode, gp_value: int, wht_rate: ?int, trigger: SupplierReleaseTrigger}
     */
    private function resolveTerms(Product $product, Supplier $supplier): array
    {
        /*
         * Mode and value travel TOGETHER. A product that names a mode but no
         * value, falling back to the supplier's value, would apply the
         * supplier's 30% as 30 satang — a number 1000x wrong that still looks
         * like a number. So the override is all-or-nothing.
         */
        $hasProductOverride = $product->supplier_gp_mode !== null && $product->supplier_gp_value !== null;

        $mode = $hasProductOverride ? $product->supplier_gp_mode : $supplier->gp_mode;
        $value = $hasProductOverride ? $product->supplier_gp_value : $supplier->gp_value;

        if (! $mode instanceof SupplierGpMode || $value === null) {
            throw new RuntimeException(
                "Product {$product->id} is supplied by supplier {$supplier->id} but no GP terms are set "
                .'on either the product or the supplier deal. Refusing to invent one (BR-7).'
            );
        }

        $trigger = $supplier->release_trigger;

        if (! $trigger instanceof SupplierReleaseTrigger) {
            throw new RuntimeException(
                "Supplier {$supplier->id} has no release_trigger set. "
                .'Refusing to guess when their money becomes payable (BR-7).'
            );
        }

        return [
            'gp_mode' => $mode,
            'gp_value' => (int) $value,
            // NULL is a real answer here, unlike the two above: withholding
            // nothing is correct for a sale of goods, and is the state every
            // deal starts in until accounting says otherwise.
            'wht_rate' => $product->supplier_wht_rate ?? $supplier->wht_rate,
            'trigger' => $trigger,
        ];
    }

    /**
     * Every commission row this sale produced, summed.
     *
     * Owner: "Full ระบบของเราที่เราทำได้เลย" — the seller's row AND every
     * upline override, not just the seller's.
     *
     * Joined through `referral_id` because that is the only link
     * commission_ledger has to a sale; an order and its referral are 1:1 by
     * construction (createForReferral refuses a second active order).
     *
     * An order with no referral cannot have commission, which is genuinely
     * zero rather than unknown.
     */
    private function commissionFor(Order $order): int
    {
        if ($order->referral_id === null) {
            return 0;
        }

        return (int) CommissionLedger::withoutGlobalScopes()
            ->where('referral_id', $order->referral_id)
            ->sum('amount_satang');
    }

    /**
     * Our margin, by mode.
     *
     * BR-3 — multiply before dividing, once, at the end. `intdiv` truncates
     * toward zero, so GP loses the fraction of a satang and the SUPPLIER keeps
     * it; stated here because "which way does it round" is a real question
     * about real money and a reader should not have to infer it from an
     * operator.
     */
    private function gp(SupplierGpMode $mode, int $value, int $sale, int $commission): int
    {
        return match ($mode) {
            SupplierGpMode::PercentOfSale => intdiv($sale * $value, self::BASIS_POINT_SCALE),
            // max(0, …) guards the BASE, not the result: on a sale whose
            // commission already exceeds the price there is no net for us to
            // take a share of, and a negative base would hand the supplier a
            // NEGATIVE GP — i.e. pay them extra for the privilege.
            SupplierGpMode::PercentOfNet => intdiv(max(0, $sale - $commission) * $value, self::BASIS_POINT_SCALE),
            SupplierGpMode::FixedPerUnit => $value,
        };
    }

    /**
     * Is this row payable the moment it is written?
     *
     * Only for OnPayment deals. The other two wait for an event that has not
     * happened yet — with one exception worth spelling out: an order whose
     * product needs no shipping can never be shipped or received, so an
     * OnDelivered deal on a service product would hold that money forever. A
     * deal set that way against a service is a configuration mistake, and this
     * treats it as one by leaving the row unreleased and visible in the
     * supplier's "not yet payable" list, rather than quietly releasing it and
     * hiding the mistake.
     */
    private function releaseAtFor(Order $order, SupplierReleaseTrigger $trigger): ?string
    {
        return match ($trigger) {
            SupplierReleaseTrigger::OnPayment => now()->toDateTimeString(),
            // ADR-048 — received, not merely shipped.
            SupplierReleaseTrigger::OnDelivered => $order->received_at !== null
                ? now()->toDateTimeString()
                : null,
            SupplierReleaseTrigger::OnRedeemed => null,
        };
    }
}
