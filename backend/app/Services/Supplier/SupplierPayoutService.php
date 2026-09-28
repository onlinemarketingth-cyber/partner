<?php

namespace App\Services\Supplier;

use App\Enums\PaymentStatus;
use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\SupplierSettlementLedger;
use App\Models\SupplierWithdrawalItem;
use App\Models\SupplierWithdrawalRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 2026-09-16 — raising and settling a payment to a supplier.
 *
 * Mirrors CommissionWithdrawalService's company-payout path, including the
 * step that matters most: **the ledger settles at TRANSFER, not when the
 * payout is raised**. Raising a payout reserves the rows; nothing is marked
 * paid until somebody records that the money actually left the bank. There is
 * no supplier-request path (owner, 2026-09-27 — ADR-048). That is แนวทาง C, chosen for the
 * commission flow for the same reason it applies here — an approval is a
 * decision and a transfer is an event, and the event is the one the books
 * follow.
 *
 * ── WHAT IS DIFFERENT, AND IT IS ONLY TAX ──
 *
 * Owner: "ทำตามมาตรฐาน". Everything else about this class is the commission
 * flow with a supplier where a person used to be. Tax is the genuinely new
 * idea, and it lives in withholdingFor() below — read that one before
 * changing anything here.
 */
class SupplierPayoutService
{
    private const BASIS_POINT_SCALE = 10000;

    public function __construct(
        private ShipmentService $shipments,
    ) {}

    /**
     * What a supplier is owed right now, and what it is made of.
     *
     * Three figures, deliberately, because they answer three different
     * questions and collapsing them hides the answer to two of them:
     *
     *   payable   released, unpaid, not already reserved — what can go out today
     *   reserved  sitting in an open request — decided, not yet transferred
     *   pending   earned but not yet released by the deal's trigger
     *
     * `pending` is the one a supplier argues about ("you owe me more than
     * that"), and showing it is how the screen answers without anybody having
     * to look it up.
     *
     * @return array{payable_satang: int, reserved_satang: int, unreleased_satang: int}
     */
    public function balanceFor(Supplier $supplier): array
    {
        $reservedLedgerIds = $this->reservedLedgerIds($supplier);

        $payable = (int) SupplierSettlementLedger::query()
            ->where('supplier_id', $supplier->id)
            ->payable()
            ->whereNotIn('id', $reservedLedgerIds ?: [0])
            ->sum('amount_satang');

        $reserved = (int) SupplierWithdrawalItem::query()
            ->whereIn('supplier_withdrawal_request_id', $this->openRequestIds($supplier) ?: [0])
            ->sum('allocated_satang');

        $unreleased = (int) SupplierSettlementLedger::query()
            ->where('supplier_id', $supplier->id)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->whereNull('released_at')
            ->sum('amount_satang');

        return [
            'payable_satang' => $payable,
            'reserved_satang' => $reserved,
            'unreleased_satang' => $unreleased,
        ];
    }

    /**
     * Raise a payout for everything this supplier can currently be paid.
     *
     * ── ONLY WE RAISE IT (ADR-048) ──
     *
     * Owner, 2026-09-27: suppliers do not request withdrawals themselves. So
     * there is no supplier-request path, no review queue and no minimum: an
     * admin pressing this button IS the decision, and the request opens
     * Approved, waiting only for the transfer.
     *
     * ── WHY THERE IS NO "PAY PART OF IT" ARGUMENT ──
     *
     * Because the shortfall and refund rows make partial payouts dangerous. A
     * supplier's balance is the NET of positive sales and negative rows. Let a
     * caller pick an amount and the obvious implementation pays the positive
     * rows and leaves the negatives behind — which pays out MORE than is owed.
     * Taking the whole payable set is the only version that cannot do that.
     *
     * ── ONE AT A TIME ──
     *
     * The supplier row is locked for the whole calculation, the same way the
     * commission flow locks the agent. Two admins pressing the button together
     * (or one double-click) would otherwise both read the same rows as
     * unreserved and pay them twice.
     */
    public function open(Supplier $supplier, User $actor): SupplierWithdrawalRequest
    {
        // Parcels past the auto-receive window become payable first, so the
        // payout is never smaller than the supplier is owed because the
        // scheduler had not run yet.
        $this->shipments->autoConfirmDue();

        return DB::transaction(function () use ($supplier, $actor) {
            $supplier = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if (blank($supplier->payout_bank_account_number) || blank($supplier->payout_bank_account_name)) {
                throw ValidationException::withMessages([
                    'supplier' => 'คู่ค้ารายนี้ยังไม่มีบัญชีรับเงิน — ตั้งบัญชีที่หน้าจัดการคู่ค้าก่อนจึงจะตั้งจ่ายได้',
                ]);
            }

            $rows = $this->payableRows($supplier);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages([
                    'supplier' => 'ไม่มียอดที่พร้อมจ่ายสำหรับคู่ค้ารายนี้',
                ]);
            }

            $gross = (int) $rows->sum('amount_satang');

            /*
             * A net of zero or less is not a payment. It means this supplier's
             * shortfalls and refunds currently cancel out (or exceed) what they
             * have sold, so there is nothing to transfer — and we do NOT go and
             * ask them for the difference: the owner's rulings ("supplier เป็น
             * ผู้รับผิดชอบ", "หักครั้งถัดไป") are that it nets off against their
             * future sales, which is what leaving these rows open achieves.
             */
            if ($gross <= 0) {
                throw ValidationException::withMessages([
                    'supplier' => 'ยอดคงเหลือของคู่ค้ารายนี้เป็นศูนย์หรือติดลบ — ยังตั้งจ่ายไม่ได้ (จะหักกลบกับยอดขายรอบถัดไป)',
                ]);
            }

            $tax = $this->withholdingFor($rows);

            $request = SupplierWithdrawalRequest::create([
                'supplier_id' => $supplier->id,
                'source' => WithdrawalSource::CompanyPayout->value,
                'status' => WithdrawalStatus::Approved->value,
                'gross_satang' => $gross,
                'wht_rate_at_time' => $tax['rate'],
                'wht_satang' => $tax['satang'],
                'net_satang' => $gross - $tax['satang'],
                /*
                 * Snapshot — a supplier changing bank details later must not
                 * rewrite where this money was sent. The supplier's OWN payout
                 * account (suppliers.payout_bank_*), never the tenant's
                 * receiving account.
                 */
                'bank_name' => $supplier->payout_bank_name,
                'bank_account_number' => $supplier->payout_bank_account_number,
                'bank_account_holder_name' => $supplier->payout_bank_account_name,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ]);

            foreach ($rows as $row) {
                SupplierWithdrawalItem::create([
                    'supplier_withdrawal_request_id' => $request->id,
                    'supplier_settlement_ledger_id' => $row->id,
                    // Whole rows, including negative ones. See the model.
                    'allocated_satang' => $row->amount_satang,
                ]);
            }

            $this->audit($request, $actor, 'supplier_payout.opened', null, [
                'gross_satang' => $gross,
                'wht_satang' => $tax['satang'],
                'net_satang' => $gross - $tax['satang'],
                'ledger_rows' => $rows->count(),
            ]);

            return $request->fresh('items');
        });
    }

    /**
     * Money has left the bank. THIS is where the ledger settles.
     *
     * Not when the payout is raised — that is a decision that can still be
     * undone by a failed transfer (see cancel()), and a ledger marked paid on
     * the strength of one is a ledger that disagrees with the bank statement.
     */
    public function markTransferred(
        SupplierWithdrawalRequest $request,
        User $actor,
        ?string $reference = null,
        ?string $whtCertificateNo = null,
    ): SupplierWithdrawalRequest {
        return DB::transaction(function () use ($request, $actor, $reference, $whtCertificateNo) {
            $request = SupplierWithdrawalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== WithdrawalStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => 'บันทึกการโอนได้เฉพาะรายการที่รอโอนเท่านั้น (สถานะปัจจุบัน: '.$request->status->label().')',
                ]);
            }

            $request->update([
                'status' => WithdrawalStatus::Transferred->value,
                'transferred_at' => now(),
                'transfer_reference' => $reference,
                'wht_certificate_no' => filled($whtCertificateNo) ? $whtCertificateNo : $request->wht_certificate_no,
            ]);

            /*
             * Settle every row this request drew on — INCLUDING the negative
             * ones. Their effect has been taken into account in the transfer
             * that just happened; leaving them Pending would apply the same
             * shortfall or refund again on the next payout.
             */
            SupplierSettlementLedger::query()
                ->whereIn('id', $request->items()->pluck('supplier_settlement_ledger_id'))
                ->update(['payment_status' => PaymentStatus::Paid->value]);

            $this->audit($request, $actor, 'supplier_payout.transferred',
                ['status' => WithdrawalStatus::Approved->value],
                ['status' => WithdrawalStatus::Transferred->value, 'transfer_reference' => $reference, 'net_satang' => $request->net_satang],
            );

            return $request->fresh('items');
        });
    }

    /**
     * Take back a payout that was raised but never transferred.
     *
     * The transfer failed, the account was wrong, it was raised by mistake.
     * Without this the rows stayed reserved forever — "open" — and could
     * never be paid by any later payout. Cancelling drops the reservation and
     * the rows return to the payable pool untouched (they were never marked
     * paid). The reason is required and kept on the request.
     */
    public function cancel(SupplierWithdrawalRequest $request, User $actor, string $reason): SupplierWithdrawalRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = SupplierWithdrawalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== WithdrawalStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => 'ยกเลิกได้เฉพาะรายการที่ยังไม่ได้โอนเท่านั้น',
                ]);
            }

            $request->update([
                'status' => WithdrawalStatus::Cancelled->value,
                'rejection_reason' => $reason,
            ]);

            $this->audit($request, $actor, 'supplier_payout.cancelled',
                ['status' => WithdrawalStatus::Approved->value],
                ['status' => WithdrawalStatus::Cancelled->value, 'reason' => $reason],
            );

            return $request->fresh('items');
        });
    }

    private function audit(SupplierWithdrawalRequest $request, User $actor, string $action, ?array $old, ?array $new): void
    {
        // §6 — money. No company_id: a supplier is not a tenant.
        AuditLog::create([
            'company_id' => null,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => SupplierWithdrawalRequest::class,
            'auditable_id' => $request->id,
            'old_values' => $old,
            'new_values' => ['supplier_id' => $request->supplier_id] + ($new ?? []),
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * ── WITHHOLDING TAX, AND WHY IT IS GROUPED ──
     *
     * Owner: "ทำตามมาตรฐาน". The standard is not one number. Thai practice
     * withholds nothing on a sale of GOODS and a percentage on a SERVICE fee,
     * and this system carries both kinds of product for the same supplier —
     * so one payout can legitimately span several rates.
     *
     * The tempting implementation is `gross × rate`. On a mixed request there
     * is no `rate` to use, and whichever one gets picked — the supplier's
     * default, the first row's, an average — is wrong for part of the money,
     * every single time.
     *
     * So: group the rows by the rate snapshotted on each, withhold within each
     * group, and sum. `rate` in the return value is the single rate when every
     * row agrees and NULL when they do not; null there means "several", never
     * "none" (none is 0).
     *
     * Only positive rows are withheld against. A shortfall is not income and
     * cannot have tax deducted from it; including it would reduce the tax
     * withheld on the sales that ARE income, which is a real under-deduction
     * rather than a rounding choice.
     *
     * @param  Collection<int, SupplierSettlementLedger>  $rows
     * @return array{rate: ?int, satang: int}
     */
    private function withholdingFor(Collection $rows): array
    {
        /*
         * ADR-048 — a REFUND row is different from a shortfall: it takes back
         * income that was (or would have been) withheld against, so it reduces
         * the base at its own rate. A group whose refunds exceed its sales
         * withholds nothing rather than a negative amount.
         */
        $byRate = $rows
            ->filter(fn (SupplierSettlementLedger $row) => $row->amount_satang > 0 || $row->isRefund())
            ->groupBy(fn (SupplierSettlementLedger $row) => (int) ($row->wht_rate_at_time ?? 0));

        $satang = 0;

        foreach ($byRate as $rate => $group) {
            if ((int) $rate === 0) {
                continue;
            }

            // Multiply the GROUP's total before dividing once — summing
            // per-row tax would round a fraction of a satang away on every
            // line, which on a thousand-line payout is real money.
            $satang += intdiv(max(0, (int) $group->sum('amount_satang')) * (int) $rate, self::BASIS_POINT_SCALE);
        }

        $rates = $byRate->keys()->map(fn ($r) => (int) $r)->unique()->values();

        return [
            'rate' => $rates->count() === 1 ? $rates->first() : null,
            'satang' => $satang,
        ];
    }

    /**
     * The rows a new payout would draw on: released, unpaid, unreserved.
     *
     * @return Collection<int, SupplierSettlementLedger>
     */
    private function payableRows(Supplier $supplier): Collection
    {
        return SupplierSettlementLedger::query()
            ->where('supplier_id', $supplier->id)
            ->payable()
            ->whereNotIn('id', $this->reservedLedgerIds($supplier) ?: [0])
            ->orderBy('id')
            ->get();
    }

    /** @return list<int> */
    private function openRequestIds(Supplier $supplier): array
    {
        return SupplierWithdrawalRequest::query()
            ->where('supplier_id', $supplier->id)
            ->open()
            ->pluck('id')
            ->all();
    }

    /**
     * Ledger rows already spoken for by an open request.
     *
     * The guard against paying the same sale twice while a decision or a
     * transfer is outstanding — the supplier-side copy of the reserved-balance
     * rule the commission flow documents at length.
     *
     * @return list<int>
     */
    private function reservedLedgerIds(Supplier $supplier): array
    {
        $openIds = $this->openRequestIds($supplier);

        if ($openIds === []) {
            return [];
        }

        return SupplierWithdrawalItem::query()
            ->whereIn('supplier_withdrawal_request_id', $openIds)
            ->pluck('supplier_settlement_ledger_id')
            ->all();
    }
}
