<?php

namespace App\Services\Supplier;

use App\Enums\PaymentStatus;
use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
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
 * Mirrors CommissionWithdrawalService step for step, including the one that
 * matters most: **the ledger settles at TRANSFER, not at approval**. Raising a
 * payout reserves the rows; nothing is marked paid until somebody records that
 * the money actually left the bank. That is แนวทาง C, chosen for the
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
     * ── WHY THERE IS NO "PAY PART OF IT" ARGUMENT ──
     *
     * Because the shortfall rows make partial payouts dangerous. A supplier's
     * balance is the NET of positive sales and negative ones (commission + GP
     * exceeded the price; the owner ruled the supplier carries it). Let a
     * caller pick an amount and the obvious implementation pays the positive
     * rows and leaves the negatives behind — which pays out MORE than is owed
     * and leaves a permanent debit that reduces every future payout. Taking
     * the whole payable set is the only version that cannot do that.
     */
    public function open(Supplier $supplier, User $actor, WithdrawalSource $source): SupplierWithdrawalRequest
    {
        $rows = $this->payableRows($supplier);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'supplier' => 'ไม่มียอดที่พร้อมจ่ายสำหรับคู่ค้ารายนี้',
            ]);
        }

        $gross = (int) $rows->sum('amount_satang');

        /*
         * A net of zero or less is not a payment. It means this supplier's
         * shortfalls currently cancel out (or exceed) what they have sold, so
         * there is nothing to transfer — and we do NOT go and ask them for the
         * difference: the owner's ruling is that it nets off against their
         * future sales, which is what leaving these rows open achieves.
         */
        if ($gross <= 0) {
            throw ValidationException::withMessages([
                'supplier' => 'ยอดคงเหลือของคู่ค้ารายนี้เป็นศูนย์หรือติดลบ — ยังตั้งจ่ายไม่ได้ (จะหักกลบกับยอดขายรอบถัดไป)',
            ]);
        }

        /*
         * The minimum binds a SUPPLIER asking, never us deciding to settle
         * what we owe. Identical asymmetry to the agent flow, and for the same
         * reason: refusing to let somebody ask for 12 baht is a policy;
         * refusing to let ourselves pay a debt we have decided to pay is not.
         */
        if ($source === WithdrawalSource::AgentRequest
            && $supplier->min_withdrawal_satang !== null
            && $gross < $supplier->min_withdrawal_satang) {
            throw ValidationException::withMessages([
                'supplier' => 'ยอดคงเหลือยังไม่ถึงขั้นต่ำที่กำหนดไว้สำหรับการขอเบิก',
            ]);
        }

        $tax = $this->withholdingFor($rows);

        return DB::transaction(function () use ($supplier, $actor, $source, $rows, $gross, $tax) {
            $request = SupplierWithdrawalRequest::create([
                'supplier_id' => $supplier->id,
                'source' => $source->value,
                // Same rule as WithdrawalSource documents for agents: a
                // supplier asking needs a decision; an admin raising it has
                // already made one by pressing the button.
                'status' => $source === WithdrawalSource::AgentRequest
                    ? WithdrawalStatus::PendingReview->value
                    : WithdrawalStatus::Approved->value,
                'gross_satang' => $gross,
                'wht_rate_at_time' => $tax['rate'],
                'wht_satang' => $tax['satang'],
                'net_satang' => $gross - $tax['satang'],
                /*
                 * Snapshot — a supplier changing bank details later must not
                 * rewrite where this money was sent.
                 *
                 * 2026-09-17 — the supplier's OWN payout account, on the
                 * suppliers table. It was briefly `companies.payment_bank_*`,
                 * which is the account a TENANT takes customer money in
                 * through — paying a trading partner into it conflated two
                 * different accounts pointing in opposite directions.
                 */
                'bank_name' => $supplier->payout_bank_name,
                'bank_account_number' => $supplier->payout_bank_account_number,
                'bank_account_holder_name' => $supplier->payout_bank_account_name,
                'decided_by_user_id' => $source === WithdrawalSource::CompanyPayout ? $actor->id : null,
                'decided_at' => $source === WithdrawalSource::CompanyPayout ? now() : null,
            ]);

            foreach ($rows as $row) {
                SupplierWithdrawalItem::create([
                    'supplier_withdrawal_request_id' => $request->id,
                    'supplier_settlement_ledger_id' => $row->id,
                    // Whole rows, including negative ones. See the model.
                    'allocated_satang' => $row->amount_satang,
                ]);
            }

            return $request->fresh('items');
        });
    }

    /**
     * Money has left the bank. THIS is where the ledger settles.
     *
     * Not at approval — an approval is a decision that can still be undone by
     * a failed transfer, and a ledger marked paid on the strength of one is a
     * ledger that disagrees with the bank statement.
     */
    public function markTransferred(SupplierWithdrawalRequest $request, User $actor, ?string $reference = null): SupplierWithdrawalRequest
    {
        if ($request->status !== WithdrawalStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => 'บันทึกการโอนได้เฉพาะรายการที่อนุมัติแล้วเท่านั้น (สถานะปัจจุบัน: '.$request->status->value.')',
            ]);
        }

        return DB::transaction(function () use ($request, $actor, $reference) {
            $request->update([
                'status' => WithdrawalStatus::Transferred->value,
                'transferred_at' => now(),
                'transfer_reference' => $reference,
                'decided_by_user_id' => $request->decided_by_user_id ?? $actor->id,
                'decided_at' => $request->decided_at ?? now(),
            ]);

            /*
             * Settle every row this request drew on — INCLUDING the negative
             * ones. They were paid in the sense that matters: their effect has
             * been taken into account in the transfer that just happened, and
             * leaving them Pending would apply the same shortfall again on the
             * next payout, and the one after that.
             */
            SupplierSettlementLedger::query()
                ->whereIn('id', $request->items()->pluck('supplier_settlement_ledger_id'))
                ->update(['payment_status' => PaymentStatus::Paid->value]);

            return $request->fresh('items');
        });
    }

    /** An admin agreeing to a request the supplier raised. */
    public function approve(SupplierWithdrawalRequest $request, User $actor): SupplierWithdrawalRequest
    {
        if ($request->status !== WithdrawalStatus::PendingReview) {
            throw ValidationException::withMessages([
                'status' => 'อนุมัติได้เฉพาะรายการที่รอตรวจสอบเท่านั้น',
            ]);
        }

        $request->update([
            'status' => WithdrawalStatus::Approved->value,
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
        ]);

        return $request->fresh();
    }

    /**
     * Turning a request down releases its reservation.
     *
     * The reason is required by the application, shown to the supplier
     * verbatim, and is the entire difference between a refusal they can act on
     * and one they have to telephone about.
     */
    public function reject(SupplierWithdrawalRequest $request, User $actor, string $reason): SupplierWithdrawalRequest
    {
        if (! $request->status->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'ปฏิเสธได้เฉพาะรายการที่ยังไม่ปิดเท่านั้น',
            ]);
        }

        $request->update([
            'status' => WithdrawalStatus::Rejected->value,
            'rejection_reason' => $reason,
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
        ]);

        // The ledger rows are untouched on purpose — they were never marked
        // paid, and dropping the allocation is what returns them to the
        // payable pool. Nothing to undo.
        return $request->fresh();
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
        $byRate = $rows
            ->filter(fn (SupplierSettlementLedger $row) => $row->amount_satang > 0)
            ->groupBy(fn (SupplierSettlementLedger $row) => (int) ($row->wht_rate_at_time ?? 0));

        $satang = 0;

        foreach ($byRate as $rate => $group) {
            if ((int) $rate === 0) {
                continue;
            }

            // Multiply the GROUP's total before dividing once — summing
            // per-row tax would round a fraction of a satang away on every
            // line, which on a thousand-line payout is real money.
            $satang += intdiv((int) $group->sum('amount_satang') * (int) $rate, self::BASIS_POINT_SCALE);
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
