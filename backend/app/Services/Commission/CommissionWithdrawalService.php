<?php

namespace App\Services\Commission;

use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalItem;
use App\Models\CommissionWithdrawalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agent-initiated commission withdrawal (2026-08-27).
 *
 * ── THE ONE IDEA THIS SERVICE IS BUILT AROUND ──
 *
 * A commission ledger row is an immutable record of one sale (BR-4), and an
 * agent may ask for an arbitrary amount. Those two facts do not fit
 * together with "mark the rows paid": ฿4,000 requested against rows of
 * ฿3,000 and ฿2,000 matches no set of rows exactly.
 *
 * So a request records an AMOUNT, and commission_withdrawal_items records
 * how much of each ledger row that amount was drawn from. A ledger row's
 * payment_status flips to Paid only when its allocations add up to its full
 * value — the ledger keeps saying what was earned, and the allocation table
 * carries the separate question of what has been drawn against it.
 *
 * ── REVERSALS ARE PART OF THE ARITHMETIC, NOT AN EXCEPTION ──
 *
 * A refund is a NEGATIVE ledger row (2026_09_20_090000). A negative row is
 * absorbed by a payout exactly like a positive one is drawn on, with a
 * negative allocation, and allocate() below always consumes every negative
 * row in full before taking positives. If it did not, the refund would stay
 * unallocated and be subtracted from the agent's available balance on every
 * future request instead of exactly once — an agent would be punished for a
 * single refund again and again, and nobody would be able to say why.
 */
class CommissionWithdrawalService
{
    /**
     * What this agent may ask for right now, in satang.
     *
     * SUM(unpaid ledger, netted) − SUM(already allocated to requests that
     * are still open). Never below zero: a company whose reversals currently
     * exceed its unpaid commission owes nothing, it does not owe a negative
     * amount, and returning one would render as a nonsense balance.
     */
    public function availableSatang(User $agent): int
    {
        $earned = (int) CommissionLedger::query()
            ->where('agent_id', $agent->id)
            ->where('payment_status', PaymentStatus::Pending)
            ->sum('amount_satang');

        $reserved = (int) CommissionWithdrawalItem::query()
            ->whereHas('request', fn ($q) => $q
                ->where('agent_id', $agent->id)
                ->whereIn('status', array_column(WithdrawalStatus::open(), 'value')))
            ->sum('allocated_satang');

        return max(0, $earned - $reserved);
    }

    /**
     * @throws ValidationException  every refusal an agent can act on —
     *                              incomplete payout details, below the
     *                              company minimum, more than they have.
     */
    public function request(User $agent, int $amountSatang): CommissionWithdrawalRequest
    {
        // Wrapped BEFORE the balance is read, not after: two requests
        // submitted at the same moment must not both see the same balance
        // and both pass. The row lock inside is what makes that true.
        return DB::transaction(function () use ($agent, $amountSatang) {
            // Lock the agent's own row for the duration. It is not the data
            // being summed, but it is a single, always-present row that every
            // concurrent request for THIS agent contends on — which is
            // exactly the serialisation point the balance maths needs, and
            // cheaper than locking a ledger that grows forever.
            User::query()->whereKey($agent->id)->lockForUpdate()->first();

            // The gate the profile page's banner has been previewing all
            // along, asked HERE because this is the moment it matters.
            // Re-read from the database rather than trusting the passed-in
            // model: the details may have been completed in another tab.
            $agent = $agent->fresh();

            if (! $agent->hasCompletePayoutDetails()) {
                throw ValidationException::withMessages([
                    'amount_satang' => 'กรุณากรอกเอกสารยืนยันตัวตนและบัญชีธนาคารให้ครบก่อนขอเบิก',
                ]);
            }

            if ($amountSatang <= 0) {
                throw ValidationException::withMessages([
                    'amount_satang' => 'จำนวนเงินที่ขอเบิกต้องมากกว่า 0',
                ]);
            }

            // NULL minimum means no minimum — a real setting, not a missing
            // one, so nothing is substituted for it here.
            $minimum = $agent->company?->min_withdrawal_satang;

            if ($minimum !== null && $amountSatang < $minimum) {
                throw ValidationException::withMessages([
                    'amount_satang' => sprintf(
                        'ยอดขั้นต่ำในการเบิกคือ %s บาท',
                        number_format($minimum / 100, 2)
                    ),
                ]);
            }

            $available = $this->availableSatang($agent);

            if ($amountSatang > $available) {
                throw ValidationException::withMessages([
                    'amount_satang' => sprintf(
                        'ยอดที่เบิกได้ขณะนี้คือ %s บาท',
                        number_format($available / 100, 2)
                    ),
                ]);
            }

            $request = CommissionWithdrawalRequest::create([
                'company_id' => $agent->company_id,
                'agent_id' => $agent->id,
                'amount_satang' => $amountSatang,
                'status' => WithdrawalStatus::PendingReview,
                // Snapshot, not a live read at payout time — see the model.
                'bank_name' => $agent->bank_name,
                'bank_account_number' => $agent->bank_account_number,
                'bank_account_holder_name' => $agent->bank_account_holder_name,
            ]);

            $this->allocate($request, $agent, $amountSatang);

            AuditLog::create([
                'company_id' => $agent->company_id,
                'actor_user_id' => $agent->id,
                'action' => 'commission_withdrawal.requested',
                'auditable_type' => CommissionWithdrawalRequest::class,
                'auditable_id' => $request->id,
                'old_values' => null,
                'new_values' => ['amount_satang' => $amountSatang],
                'ip_address' => request()?->ip(),
            ]);

            return $request->load('items');
        });
    }

    /**
     * Spread $amountSatang across this agent's unsettled ledger rows.
     *
     * Negatives first and in full (see the class docblock), then positives
     * oldest-first until the requested amount is covered. Oldest-first is
     * not arbitrary: it settles the ledger in the order it was earned, so
     * "which sales has this agent been paid for" has an answer that matches
     * how anyone would describe it.
     *
     * The caller has already proved $amountSatang <= availableSatang(), so
     * the positive rows are guaranteed to cover `need` — the exception at
     * the end is a tripwire for that invariant being broken by a future
     * change, never something a user can reach.
     */
    private function allocate(CommissionWithdrawalRequest $request, User $agent, int $amountSatang): void
    {
        $rows = CommissionLedger::query()
            ->where('agent_id', $agent->id)
            ->where('payment_status', PaymentStatus::Pending)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $alreadyAllocated = CommissionWithdrawalItem::query()
            ->whereIn('commission_ledger_id', $rows->pluck('id'))
            ->whereHas('request', fn ($q) => $q->whereIn(
                'status',
                array_column(WithdrawalStatus::open(), 'value')
            ))
            ->selectRaw('commission_ledger_id, SUM(allocated_satang) AS taken')
            ->groupBy('commission_ledger_id')
            ->pluck('taken', 'commission_ledger_id');

        $remainderOf = fn (CommissionLedger $row): int => (int) $row->amount_satang
            - (int) ($alreadyAllocated[$row->id] ?? 0);

        $need = $amountSatang;

        // Pass 1 — absorb every outstanding reversal, in full. This makes
        // `need` LARGER: the positives that follow have to cover the refund
        // as well as the payout.
        foreach ($rows as $row) {
            $remainder = $remainderOf($row);

            if ($remainder >= 0) {
                continue;
            }

            $this->writeItem($request, $row, $remainder);
            $need -= $remainder;
        }

        // Pass 2 — draw on positives, oldest first, taking part of a row
        // when the whole of it is more than is still needed.
        foreach ($rows as $row) {
            if ($need <= 0) {
                break;
            }

            $remainder = $remainderOf($row);

            if ($remainder <= 0) {
                continue;
            }

            $take = min($remainder, $need);
            $this->writeItem($request, $row, $take);
            $need -= $take;
        }

        if ($need !== 0) {
            // Unreachable while the balance check above is correct. Loud on
            // purpose: a silent partial allocation would mean an approved
            // payout drawing on commission that was never accounted for.
            throw new \RuntimeException(
                "Withdrawal allocation did not balance for request {$request->id}: {$need} satang unassigned."
            );
        }
    }

    /**
     * Agent withdraws their own request before anyone has decided on it.
     *
     * Only from PendingReview: once an admin has approved, the money may
     * already be on its way, and letting the agent quietly release the
     * allocation at that point would let the same commission be requested
     * twice while a transfer is in flight.
     */
    public function cancel(CommissionWithdrawalRequest $request, User $agent): CommissionWithdrawalRequest
    {
        if ($request->status !== WithdrawalStatus::PendingReview) {
            throw ValidationException::withMessages([
                'status' => 'ยกเลิกได้เฉพาะคำขอที่ยังรอตรวจสอบเท่านั้น',
            ]);
        }

        return $this->transition($request, $agent, WithdrawalStatus::Cancelled, 'commission_withdrawal.cancelled');
    }

    public function approve(CommissionWithdrawalRequest $request, User $actor): CommissionWithdrawalRequest
    {
        $this->assertPendingReview($request);

        return $this->transition($request, $actor, WithdrawalStatus::Approved, 'commission_withdrawal.approved', [
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
        ]);
    }

    public function reject(CommissionWithdrawalRequest $request, User $actor, string $reason): CommissionWithdrawalRequest
    {
        $this->assertPendingReview($request);

        // The allocations are NOT deleted. They stop counting the moment the
        // status leaves the open set (see WithdrawalStatus::open()), so the
        // commission is released for a future request — while the record of
        // what this request had claimed survives for the audit trail. A
        // rejected payout that leaves no trace of what it was for is exactly
        // the thing somebody will need to reconstruct later.
        return $this->transition($request, $actor, WithdrawalStatus::Rejected, 'commission_withdrawal.rejected', [
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * The money has actually left the bank.
     *
     * THIS is where the ledger changes — not at approval. A ledger row flips
     * to Paid only once its allocations add up to its full value; a row that
     * this payout only partly drew on stays Pending, correctly, because part
     * of it is still owed.
     */
    public function markTransferred(
        CommissionWithdrawalRequest $request,
        User $actor,
        ?string $reference = null,
    ): CommissionWithdrawalRequest {
        if ($request->status !== WithdrawalStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => 'บันทึกการโอนได้เฉพาะคำขอที่อนุมัติแล้วเท่านั้น',
            ]);
        }

        return DB::transaction(function () use ($request, $actor, $reference) {
            $updated = $this->transition($request, $actor, WithdrawalStatus::Transferred, 'commission_withdrawal.transferred', [
                'transferred_at' => now(),
                'transfer_reference' => $reference,
            ]);

            $this->settleFullyAllocatedLedgerRows($updated);

            return $updated;
        });
    }

    /**
     * Flip to Paid every ledger row this payout finished off.
     *
     * "Finished off" means the SUM of all allocations against the row — from
     * this request and any earlier transferred one — equals the row's own
     * amount. Cancelled and rejected requests are excluded: their claims
     * were released, and counting them would mark a row paid on the strength
     * of a payout that never happened.
     */
    private function settleFullyAllocatedLedgerRows(CommissionWithdrawalRequest $request): void
    {
        $ledgerIds = $request->items()->pluck('commission_ledger_id');

        if ($ledgerIds->isEmpty()) {
            return;
        }

        $settledTotals = CommissionWithdrawalItem::query()
            ->whereIn('commission_ledger_id', $ledgerIds)
            ->whereHas('request', fn ($q) => $q->whereIn('status', [
                WithdrawalStatus::Approved->value,
                WithdrawalStatus::Transferred->value,
            ]))
            ->selectRaw('commission_ledger_id, SUM(allocated_satang) AS taken')
            ->groupBy('commission_ledger_id')
            ->pluck('taken', 'commission_ledger_id');

        $rows = CommissionLedger::query()->whereIn('id', $ledgerIds)->get();

        foreach ($rows as $row) {
            if ((int) ($settledTotals[$row->id] ?? 0) !== (int) $row->amount_satang) {
                continue;
            }

            // payment_status + paid_at are the only two fields BR-4 allows to
            // move on a ledger row, and they are exactly the two written
            // here — the same pair CommissionLedgerController::markPaid()
            // writes when an admin settles a row by hand.
            $row->update([
                'payment_status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);
        }
    }

    private function assertPendingReview(CommissionWithdrawalRequest $request): void
    {
        if ($request->status !== WithdrawalStatus::PendingReview) {
            throw ValidationException::withMessages([
                'status' => 'คำขอนี้ถูกดำเนินการไปแล้ว',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        CommissionWithdrawalRequest $request,
        User $actor,
        WithdrawalStatus $to,
        string $action,
        array $extra = [],
    ): CommissionWithdrawalRequest {
        $from = $request->status;

        $request->update(['status' => $to, ...$extra]);

        // Every state change on this record is money-adjacent, so every one
        // of them is audited — CLAUDE.md §6. The amount travels with it so
        // the log can be read without joining back to a row that may since
        // have been read differently.
        AuditLog::create([
            'company_id' => $request->company_id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => CommissionWithdrawalRequest::class,
            'auditable_id' => $request->id,
            'old_values' => ['status' => $from->value],
            'new_values' => [
                'status' => $to->value,
                'amount_satang' => (int) $request->amount_satang,
            ],
            'ip_address' => request()?->ip(),
        ]);

        return $request->fresh(['items', 'agent', 'decidedBy']);
    }

    private function writeItem(CommissionWithdrawalRequest $request, CommissionLedger $row, int $satang): void
    {
        CommissionWithdrawalItem::create([
            'commission_withdrawal_request_id' => $request->id,
            'commission_ledger_id' => $row->id,
            'allocated_satang' => $satang,
        ]);
    }
}
