<?php

namespace App\Services\Commission;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 2026-09-15 — THE ONE PLACE A COMMISSION STOPS BEING OWED.
 *
 * Owner: "จ่ายทั้งหมดของคนนี้" — a payout run is one person and thirty rows,
 * and pressing "จ่ายแล้ว" thirty times is thirty chances to stop halfway.
 *
 * ── WHY THIS IS A SERVICE AND NOT A SECOND CONTROLLER METHOD ──
 *
 * The single-row mark-paid already existed, with three things attached to it
 * that are easy to write once and forget the second time: the audit row
 * (which was itself added months later, after a security audit found the one
 * money-moving action in the application was the one action nobody recorded),
 * the notification, and the `paid_at` stamp. A bulk endpoint written beside
 * it would be a second implementation of the same act — the same shape as the
 * duplicated resolution ladder that once paid a Thai Life rate on an AIA
 * product — so the single row now goes through here too. One act, one place,
 * one audit shape.
 *
 * ── WHAT IS REFUSED, AND WHY EACH REFUSAL IS PERMANENT ──
 *
 * BR-4 makes a ledger row immutable: there is no "unmark". Every refusal
 * below therefore prevents a record that could never be corrected, only
 * explained.
 *
 *   · An ALREADY-PAID row is skipped, never re-stamped. Re-stamping moves
 *     `paid_at` to today and writes a second audit row for a transfer that
 *     happened last month.
 *   · The COMPANY'S OWN SEAT (ขั้นตอนที่ 4.2) is refused outright. That money
 *     is already with the company; "จ่ายแล้ว" on it records the company
 *     transferring to itself, which is not a thing that happens.
 *   · A BULK run whose total does not match what the admin was shown is
 *     refused whole. See markAgentPaid().
 */
class CommissionPayoutService
{
    public function __construct(private readonly NotificationService $notifier) {}

    /**
     * One row, as its own act.
     *
     * Returns the row, reloaded for the Resource. Throws if the row is the
     * company's own share; an already-paid row is stamped again here rather
     * than refused, because the single-row endpoint has behaved that way
     * since it existed and a screen re-pressing a button is not the failure
     * this class was written to prevent.
     */
    public function markPaid(CommissionLedger $row, ?User $actor, ?string $ip): CommissionLedger
    {
        $this->assertNotCompanyShare($row);

        $before = $row->payment_status;

        $row->update([
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->audit($row, $before, $actor, $ip, null);

        $row->load(['referral.client', 'agent', 'certTierAtTime', 'product', 'overrideSourceAgent', 'appliedPricePromotionAtTime']);

        if ($row->agent) {
            $baht = number_format($row->amount_satang / 100, 2);
            $this->notify($row->agent, "จำนวน {$baht} บาท ถูกทำจ่ายเรียบร้อย", ['commission_ledger_id' => $row->id]);
        }

        return $row;
    }

    /**
     * Everything this person is still owed, as ONE act.
     *
     * ── THE TOTAL THE ADMIN WAS SHOWN IS PART OF THE REQUEST ──
     *
     * `$expectedTotalSatang` is the figure on screen when the button was
     * pressed. The rows are summed again here, inside the transaction, and a
     * disagreement aborts the whole run.
     *
     * This is not defensiveness for its own sake. The gap between the screen
     * loading and the button being pressed is a gap in which a sale can
     * complete and write a new pending row — and a bulk button without this
     * check would pay that row too, in an admin's name, for an amount they
     * never saw and can never take back (BR-4). Refusing and asking them to
     * refresh costs one reload; the alternative costs a payout nobody
     * authorised.
     *
     * The date range is the one applied on screen, for the same reason: the
     * button must pay exactly the set the number above it was computed from.
     *
     * @return array{batch_id: string, paid_count: int, paid_satang: int}
     */
    public function markAgentPaid(
        User $payee,
        ?string $dateFrom,
        ?string $dateTo,
        int $expectedTotalSatang,
        ?User $actor,
        ?string $ip,
    ): array {
        /*
         * The seat cannot be a payee at all, so it cannot be a bulk payee
         * either — checked on the person rather than row by row, because a
         * run against the seat should be refused before anything is locked.
         */
        if ($payee->isCommissionHouseAccount()) {
            throw ValidationException::withMessages([
                'agent_id' => 'บัญชีบริษัทไม่ใช่ผู้รับโอน เงินส่วนนี้อยู่กับบริษัทอยู่แล้ว',
            ]);
        }

        $batchId = (string) Str::uuid();

        /** @var array{rows: Collection<int, CommissionLedger>, total: int} $result */
        $result = DB::transaction(function () use ($payee, $dateFrom, $dateTo, $expectedTotalSatang) {
            /*
             * lockForUpdate() so a second admin pressing the same button on
             * the same person waits rather than interleaving. Without it both
             * runs read the same pending set, both pass the total check, and
             * both write a payment record.
             */
            $query = CommissionLedger::query()
                ->where('agent_id', $payee->id)
                ->where('payment_status', PaymentStatus::Pending);

            if ($dateFrom !== null) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            if ($dateTo !== null) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $rows = $query->lockForUpdate()->get();

            $total = (int) $rows->sum('amount_satang');

            if ($total !== $expectedTotalSatang) {
                throw ValidationException::withMessages([
                    'expected_total_satang' => 'ยอดค้างจ่ายของคนนี้เปลี่ยนไปแล้ว ('
                        .number_format($total / 100, 2).' บาท ไม่ตรงกับ '
                        .number_format($expectedTotalSatang / 100, 2).' บาท ที่แสดงอยู่) '
                        .'— กรุณารีเฟรชหน้าจอแล้วลองใหม่ ระบบยังไม่ได้บันทึกการจ่ายใด ๆ',
                ]);
            }

            if ($rows->isNotEmpty()) {
                CommissionLedger::query()
                    ->whereIn('id', $rows->pluck('id'))
                    ->update([
                        'payment_status' => PaymentStatus::Paid,
                        'paid_at' => now(),
                    ]);
            }

            return ['rows' => $rows, 'total' => $total];
        });

        /*
         * ONE AUDIT ROW PER LEDGER ROW, written after the transaction commits
         * and never inside it — the same rule every other AuditLog::create()
         * in this codebase follows, so a logging failure can never roll back a
         * payment that succeeded.
         *
         * Per row, not per batch: `auditable_id` is what anybody investigating
         * a single payment searches on, and a batch-shaped log would answer
         * "was this row paid, by whom" with a row id buried in an array. The
         * batch id rides along in `new_values` so the run is still legible as
         * one act.
         */
        foreach ($result['rows'] as $row) {
            $this->audit($row, PaymentStatus::Pending, $actor, $ip, $batchId);
        }

        /*
         * ONE notification, not one per row. Thirty rows is one payout to one
         * person; thirty messages about it is a notification list nobody reads
         * again.
         */
        if ($result['rows']->isNotEmpty()) {
            $baht = number_format($result['total'] / 100, 2);
            $this->notify(
                $payee,
                "จำนวน {$baht} บาท ({$result['rows']->count()} รายการ) ถูกทำจ่ายเรียบร้อย",
                ['commission_payout_batch_id' => $batchId],
            );
        }

        return [
            'batch_id' => $batchId,
            'paid_count' => $result['rows']->count(),
            'paid_satang' => $result['total'],
        ];
    }

    /**
     * The company's own share never moves, so it is never "paid".
     *
     * Checked here rather than only in the UI: the screen already hides the
     * button, and a guard that lives only in the screen is a guard that a
     * second screen, a script or a stale tab walks straight past — into a row
     * that cannot afterwards be corrected.
     */
    private function assertNotCompanyShare(CommissionLedger $row): void
    {
        $houseId = $row->company?->commission_house_user_id;

        if ($houseId !== null && (int) $houseId === (int) $row->agent_id) {
            throw ValidationException::withMessages([
                'commission_ledger' => 'รายการนี้เป็นส่วนของบริษัทเอง เงินอยู่กับบริษัทอยู่แล้ว จึงไม่มีการโอนให้บันทึก',
            ]);
        }
    }

    private function audit(CommissionLedger $row, ?PaymentStatus $before, ?User $actor, ?string $ip, ?string $batchId): void
    {
        AuditLog::create([
            'company_id' => $row->company_id,
            'actor_user_id' => $actor?->id,
            // Same action string for one row and for a row inside a batch: an
            // auditor asking "when was this paid" must not have to know which
            // button was pressed.
            'action' => 'commission_ledger.marked_paid',
            'auditable_type' => CommissionLedger::class,
            'auditable_id' => $row->id,
            'old_values' => ['payment_status' => $before?->value],
            'new_values' => array_filter([
                'payment_status' => PaymentStatus::Paid->value,
                // The amount is recorded alongside the status deliberately.
                // An audit entry that forces the reader to join another table
                // to learn what was actually paid is one people stop reading.
                'amount_satang' => $row->amount_satang,
                'agent_user_id' => $row->agent_id,
                'batch_id' => $batchId,
            ], fn ($value) => $value !== null),
            'ip_address' => $ip,
        ]);
    }

    private function notify(User $payee, string $body, array $meta): void
    {
        // BR-3: amounts are satang everywhere above; the caller divides by 100
        // only for this text, which is the display layer.
        $this->notifier->notify(
            $payee,
            NotificationType::CommissionPaid,
            'ค่าคอมมิชชั่นจ่ายแล้ว',
            $body,
            '/commission',
            $meta,
        );
    }
}
