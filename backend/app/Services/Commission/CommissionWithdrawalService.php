<?php

namespace App\Services\Commission;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalItem;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;
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
    public function __construct(private readonly NotificationService $notifier) {}

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
        /*
         * 2026-09-15 (ครั้งที่สอง) — THE HARD ZERO FOR THE HOUSE ACCOUNT IS GONE.
         *
         * It used to `return 0` here, on the reasoning that the company cannot
         * transfer to itself. The owner has since decided the opposite —
         * "ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย" — and they are the one who knows
         * where that money actually goes: the company's share is transferred
         * out to a real company bank account like anybody else's.
         *
         * So the seat now reports its real balance. What replaced the blanket
         * refusal is a narrower one in open(): the seat is payable only
         * through the company door, and only once somebody has given it an
         * account to pay into (ตั้งค่าค่าแนะนำ → บัญชีรับเงินของบริษัท).
         *
         * Nothing here can be reached by the seat itself — its password is 64
         * random characters nobody kept and its mailbox cannot receive a reset
         * (CommissionHouseAccountService) — so this is read by admin screens
         * only, never by a logged-in company account.
         */
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
     * The agent asks, from the agent portal.
     *
     * @throws ValidationException every refusal an agent can act on —
     *                             incomplete payout details, below the
     *                             company minimum, more than they have.
     */
    public function request(User $agent, int $amountSatang): CommissionWithdrawalRequest
    {
        return $this->open($agent, $amountSatang, WithdrawalSource::AgentRequest, $agent);
    }

    /**
     * 2026-09-15 — AN ADMIN RAISES THE PAYOUT INSTEAD.
     *
     * Owner: "ระบบเรามีข้อจำกัดในการโอนเงินไปให้ Agent เราใช้วิธีโอนเองผ่านระบบ
     * การทำงาน Bank … ซึ่งต้องได้รับข้อมูลจากฝ่ายบัญชีก่อนว่าโอนแล้วจึงมากดยืนยัน".
     *
     * That is a three-step process, and the button this replaces had one
     * step. "จ่ายแล้ว" on the payout screen flipped the ledger to Paid and
     * emailed the agent that their money had arrived — at the moment the
     * admin DECIDED to pay, days before accounting actually transferred it,
     * and with no way back afterwards (BR-4).
     *
     * So the admin's decision now creates the same object an agent's request
     * creates, already in Approved: the decision is made, the transfer is
     * not. The ledger is untouched until markTransferred(), which is the
     * press that happens after accounting confirms — and that is also where
     * the agent's email moved to.
     *
     * @throws ValidationException
     */
    public function payOut(User $agent, int $amountSatang, User $actor): CommissionWithdrawalRequest
    {
        return $this->open($agent, $amountSatang, WithdrawalSource::CompanyPayout, $actor);
    }

    /**
     * Settle this payee's WHOLE outstanding balance, if it is still the figure
     * the admin was shown.
     *
     * The single-payee twin of payOutMany(), and the one door the "ตั้งจ่าย"
     * button goes through. The staleness check used to live in the Controller;
     * it is inside open()'s row lock now, so both doors get it and neither has
     * its own copy of the rule.
     *
     * @throws ValidationException
     */
    public function payOutSettling(User $agent, int $expectedSatang, User $actor): CommissionWithdrawalRequest
    {
        return $this->open(
            $agent,
            $expectedSatang,
            WithdrawalSource::CompanyPayout,
            $actor,
            expectWholeBalance: true,
            // The field the screen's own number came from, so the message
            // lands on it rather than on an `amount_satang` input that this
            // request does not have.
            errorKey: 'expected_total_satang',
        );
    }

    /**
     * 2026-09-15 (ครั้งที่สอง) — ONE PRESS, SEVERAL PAYEES, ALL OR NOTHING.
     *
     * Owner chose แบบ C: tick the people to pay this round, press once. That
     * shape is only safe if the press is atomic. Raising the payouts one HTTP
     * call at a time — the obvious way to build it in the browser — fails
     * halfway on the third of five and leaves two payouts raised, three not,
     * and an admin with no way to tell which without reading the queue row by
     * row. They would then press again, and the two already raised would be
     * refused as stale while the other three went through, which is a worse
     * version of the same confusion.
     *
     * So: one transaction. Any refusal — a stale total, a missing bank
     * account, somebody else's company — rolls back every payout in the batch
     * and names the row that caused it. Nothing partial is ever written.
     *
     * ── WHY THE EMAILS WAIT ──
     *
     * announce() is held back until after the commit. open() normally
     * announces as its last act, which is correct on its own; inside a batch
     * that "last act" happens while the outer transaction is still open, and a
     * later failure would roll the payout back after its notification had
     * already gone out. An email about a payout that does not exist cannot be
     * recalled.
     *
     * @param  list<array{agent: User, expected_total_satang: int}>  $payees
     * @return Collection<int, CommissionWithdrawalRequest>
     *
     * @throws ValidationException
     */
    public function payOutMany(array $payees, User $actor): Collection
    {
        $opened = DB::transaction(function () use ($payees, $actor) {
            $raised = collect();

            foreach ($payees as $index => $payee) {
                $raised->push([
                    'agent' => $payee['agent'],
                    'request' => $this->open(
                        $payee['agent'],
                        $payee['expected_total_satang'],
                        WithdrawalSource::CompanyPayout,
                        $actor,
                        expectWholeBalance: true,
                        announce: false,
                        // Every refusal inside open() is keyed 'amount_satang',
                        // which on a list of ten rows says nothing about WHICH
                        // one. Keyed by position, the screen can put the
                        // message on the row the admin ticked.
                        errorKey: "payees.{$index}.expected_total_satang",
                        payeeLabel: $payee['agent']->name,
                    ),
                ]);
            }

            return $raised;
        });

        foreach ($opened as $row) {
            $this->announceOpened($row['request'], $row['agent'], WithdrawalSource::CompanyPayout);
        }

        return $opened->map(fn (array $row) => $row['request']);
    }

    /**
     * Both doors, one room.
     *
     * The only two things `$source` changes are the starting state and
     * whether the company minimum applies — see WithdrawalSource. Everything
     * else here (the lock, the balance maths, the bank snapshot, the
     * allocation, the audit row) is identical on purpose: a second copy of
     * any of it is a second place for the money to go wrong.
     */
    private function open(
        User $agent,
        int $amountSatang,
        WithdrawalSource $source,
        User $actor,
        bool $expectWholeBalance = false,
        bool $announce = true,
        string $errorKey = 'amount_satang',
        ?string $payeeLabel = null,
    ): CommissionWithdrawalRequest {
        // Wrapped BEFORE the balance is read, not after: two requests
        // submitted at the same moment must not both see the same balance
        // and both pass. The row lock inside is what makes that true.
        $request = DB::transaction(function () use ($agent, $amountSatang, $source, $actor, $expectWholeBalance, $errorKey, $payeeLabel) {
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

            /*
             * THE COMPANY IS PAID, BUT IT NEVER ASKS.
             *
             * The seat is a payee now (see availableSatang), but only through
             * the company door. The agent door is reached by somebody logged
             * in as the payee, and nobody can be logged in as this row — so an
             * AgentRequest naming it is not a company asking for its money, it
             * is a sign that something else is wrong, and it stops here rather
             * than opening a request nobody raised.
             */
            if ($agent->isCommissionHouseAccount() && $source !== WithdrawalSource::CompanyPayout) {
                throw ValidationException::withMessages([
                    $errorKey => 'บัญชีบริษัทขอเบิกเองไม่ได้ — ส่วนของบริษัทตั้งจ่ายจากหน้าตั้งจ่ายเท่านั้น',
                ]);
            }

            /*
             * Both doors, and the wording is the only difference: the agent
             * is being told to go and fill their own details in, the admin is
             * being told why they cannot pay this person yet — and they can
             * fix it without leaving the payout screen, which has the bank
             * fields on the same row.
             */
            if (! $agent->hasCompletePayoutDetails()) {
                throw ValidationException::withMessages([
                    /*
                     * Three readers, three sentences. The third is new with the
                     * company seat: telling an admin that the COMPANY has not
                     * uploaded its identity document would send them looking
                     * for a profile page that does not exist for this row — the
                     * account lives on the commission settings screen, and
                     * what it is missing is a bank account.
                     */
                    $errorKey => match (true) {
                        $agent->isCommissionHouseAccount() => 'ตั้งจ่ายส่วนของบริษัทไม่ได้ — ยังไม่ได้กรอกบัญชีรับเงินของบริษัท (ตั้งค่าระบบ → ตั้งค่าค่าแนะนำ)',
                        $source === WithdrawalSource::CompanyPayout => $payeeLabel !== null
                            ? "ตั้งจ่ายไม่ได้ — {$payeeLabel} ยังกรอกเอกสารยืนยันตัวตนหรือบัญชีธนาคารไม่ครบ"
                            : 'ตั้งจ่ายไม่ได้ — สมาชิกคนนี้ยังกรอกเอกสารยืนยันตัวตนหรือบัญชีธนาคารไม่ครบ',
                        default => 'กรุณากรอกเอกสารยืนยันตัวตนและบัญชีธนาคารให้ครบก่อนขอเบิก',
                    },
                ]);
            }

            if ($amountSatang <= 0) {
                throw ValidationException::withMessages([
                    $errorKey => 'จำนวนเงินที่ขอเบิกต้องมากกว่า 0',
                ]);
            }

            /*
             * THE MINIMUM IS A RULE FOR THE PERSON ASKING, NOT FOR THE
             * COMPANY PAYING.
             *
             * It exists so agents do not queue up ฿20 transfers. A company
             * settling what it owes is the opposite situation — refusing to
             * let an admin close out a small balance would leave that money
             * stuck with no way to release it, because the agent cannot
             * request it either.
             *
             * NULL minimum means no minimum — a real setting, not a missing
             * one, so nothing is substituted for it here.
             */
            $minimum = $source === WithdrawalSource::CompanyPayout
                ? null
                : $agent->company?->min_withdrawal_satang;

            if ($minimum !== null && $amountSatang < $minimum) {
                throw ValidationException::withMessages([
                    $errorKey => sprintf(
                        'ยอดขั้นต่ำในการเบิกคือ %s บาท',
                        number_format($minimum / 100, 2)
                    ),
                ]);
            }

            $available = $this->availableSatang($agent);

            /*
             * THE PRESS PAYS THE WHOLE BALANCE, AND ONLY THE ONE THAT WAS
             * SHOWN.
             *
             * Asked HERE rather than in the controller, where it used to live,
             * because here it is inside the row lock. Outside it, a sale
             * completing between the check and the write slipped through the
             * gap — the window was small, and BR-4 means the ledger rows a
             * payout settles cannot be un-settled afterwards, so "small" was
             * not the same as "acceptable".
             *
             * The wording names both figures on purpose: an admin who is told
             * only "this is stale" cannot tell whether the difference is one
             * new sale or somebody else already paying this person.
             */
            if ($expectWholeBalance && $available !== $amountSatang) {
                throw ValidationException::withMessages([
                    $errorKey => ($payeeLabel !== null ? "{$payeeLabel}: " : '')
                        .'ยอดค้างจ่ายของคนนี้เปลี่ยนไปแล้ว ('
                        .number_format($available / 100, 2).' บาท ไม่ตรงกับ '
                        .number_format($amountSatang / 100, 2).' บาท ที่แสดงอยู่) '
                        .'— กรุณารีเฟรชหน้าจอแล้วลองใหม่ ระบบยังไม่ได้ตั้งจ่ายใด ๆ',
                ]);
            }

            if ($amountSatang > $available) {
                throw ValidationException::withMessages([
                    $errorKey => sprintf(
                        'ยอดที่เบิกได้ขณะนี้คือ %s บาท',
                        number_format($available / 100, 2)
                    ),
                ]);
            }

            $byCompany = $source === WithdrawalSource::CompanyPayout;

            $request = CommissionWithdrawalRequest::create([
                'company_id' => $agent->company_id,
                'agent_id' => $agent->id,
                'source' => $source,
                'amount_satang' => $amountSatang,
                /*
                 * A company payout starts DECIDED. The admin pressing
                 * "ตั้งจ่าย" is the approval — sending it to a review queue
                 * so they can approve their own press is a rubber stamp, and
                 * rubber stamps are what teach people to click through a
                 * queue without reading it.
                 *
                 * Recorded as decided by them, then and there, so the audit
                 * answers "who authorised this" with a person rather than
                 * with the absence of a review step.
                 */
                'status' => $byCompany ? WithdrawalStatus::Approved : WithdrawalStatus::PendingReview,
                'decided_by_user_id' => $byCompany ? $actor->id : null,
                'decided_at' => $byCompany ? now() : null,
                // Snapshot, not a live read at payout time — see the model.
                'bank_name' => $agent->bank_name,
                'bank_account_number' => $agent->bank_account_number,
                'bank_account_holder_name' => $agent->bank_account_holder_name,
            ]);

            $this->allocate($request, $agent, $amountSatang);

            AuditLog::create([
                'company_id' => $agent->company_id,
                // The ACTOR, which for an agent request is the agent and for a
                // company payout is the admin. Hardcoding the agent here (as
                // this did) would have credited every admin-raised payout to
                // the person receiving it.
                'actor_user_id' => $actor->id,
                'action' => $byCompany
                    ? 'commission_withdrawal.raised_by_company'
                    : 'commission_withdrawal.requested',
                'auditable_type' => CommissionWithdrawalRequest::class,
                'auditable_id' => $request->id,
                'old_values' => null,
                'new_values' => [
                    'amount_satang' => $amountSatang,
                    'source' => $source->value,
                    'status' => $request->status->value,
                    'agent_user_id' => $agent->id,
                ],
                'ip_address' => request()?->ip(),
            ]);

            return $request->load('items');
        });

        /*
         * Outside the transaction, deliberately. A mail server that is slow
         * or down must not hold a row lock on the agent open, and must not
         * roll back a payout that was written correctly — the same rule every
         * AuditLog::create() in this codebase follows for the same reason.
         */
        if ($announce) {
            $this->announceOpened($request, $agent, $source);
        }

        return $request;
    }

    /**
     * 2026-09-15 — TELL SOMEBODY. THIS WAS THE HALF NOBODY BUILT.
     *
     * Owner: "ตัวแทนขอเบิกผ่านหน้า frontend แล้วแจ้งให้ admin ทราบ อันนี้ไม่มี
     * การแจ้งเตือนเลย" — and it was worse than that. The route where the agent
     * is sitting and WAITING for an answer said nothing at any step, while the
     * route where nobody was waiting sent an email. Exactly backwards.
     *
     * Who is told depends on who is now blocked:
     *   · an agent's request blocks on an ADMIN reading the queue
     *   · a company payout blocks on accounting, and the agent is simply told
     *     it is coming, so that money appearing in their bank later is not a
     *     surprise they have to ask about
     */
    private function announceOpened(CommissionWithdrawalRequest $request, User $agent, WithdrawalSource $source): void
    {
        $baht = number_format((int) $request->amount_satang / 100, 2);

        if ($source === WithdrawalSource::CompanyPayout) {
            foreach ($this->audienceFor($agent) as $recipient) {
                $this->notifier->notify(
                    $recipient,
                    NotificationType::CommissionWithdrawalDecided,
                    $agent->isCommissionHouseAccount()
                        ? 'ตั้งจ่ายส่วนของบริษัทแล้ว'
                        : 'บริษัทตั้งจ่ายค่าแนะนำให้คุณแล้ว',
                    $agent->isCommissionHouseAccount()
                        ? "ส่วนของบริษัท {$baht} บาท อยู่ระหว่างรอโอน"
                        : "จำนวน {$baht} บาท อยู่ระหว่างรอโอน จะแจ้งอีกครั้งเมื่อโอนเรียบร้อย",
                    '/withdrawals',
                    ['commission_withdrawal_request_id' => $request->id],
                );
            }

            return;
        }

        foreach ($this->adminsOf($agent) as $admin) {
            $this->notifier->notify(
                $admin,
                NotificationType::CommissionWithdrawalRequested,
                'มีคำขอเบิกค่าแนะนำใหม่',
                ($agent->name ?? 'สมาชิก')." ขอเบิก {$baht} บาท — รอตรวจสอบ",
                '/commission?view=queue',
                ['commission_withdrawal_request_id' => $request->id],
            );
        }
    }

    /**
     * WHO ACTUALLY HEARS ABOUT A PAYOUT TO THIS PAYEE.
     *
     * Normally the payee — it is their money and their bank account.
     *
     * The company's own seat has neither a person nor a reachable address:
     * house.<id>@commission.internal is on a domain RFC 6762 reserves, so it
     * resolves nowhere and every message to it is a guaranteed bounce. Sending
     * there would look like a notification and be a silent hole. The company's
     * own admins are told instead; they are the people who will see the money
     * arrive, and adminsOf() already leaves the seat out of that list.
     *
     * @return Collection<int, User>
     */
    private function audienceFor(User $payee): Collection
    {
        return $payee->isCommissionHouseAccount()
            ? $this->adminsOf($payee)
            : collect([$payee]);
    }

    /**
     * The people who can act on a request for this agent.
     *
     * Company admins of the agent's own company, and never the house account:
     * its address is on a reserved domain that cannot receive mail (RFC 6762),
     * so mailing it is a guaranteed bounce, and it is not a person who can
     * read a queue. Super Admins are not included — they are not on the hook
     * for one tenant's payout run, and a platform operator does not want every
     * company's withdrawal traffic in their inbox.
     *
     * @return Collection<int, User>
     */
    private function adminsOf(User $agent): Collection
    {
        if ($agent->company_id === null) {
            return collect();
        }

        $houseId = $agent->company?->commission_house_user_id;

        /*
         * withoutGlobalScope(TenantScope) rather than withoutGlobalScopes():
         * the tenant filter has to go (the acting user may be a Super Admin
         * with no company of their own), but SoftDeletes must STAY, or a
         * removed admin keeps being mailed about a queue they can no longer
         * open.
         *
         * There is no `is_active` on users — being deactivated is a soft
         * delete here, and `is_active` is a COMPANY column. An earlier draft
         * of this filtered on it and silently matched nobody.
         */
        return User::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $agent->company_id)
            ->where('role', UserRole::CompanyAdmin->value)
            ->when($houseId !== null, fn ($q) => $q->whereKeyNot($houseId))
            ->get();
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

        $updated = $this->transition($request, $actor, WithdrawalStatus::Approved, 'commission_withdrawal.approved', [
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
        ]);

        /*
         * Approved is NOT "paid", and the wording has to carry that or this
         * message does more harm than silence did. An agent told "อนุมัติแล้ว"
         * who then sees nothing in their bank for three days will ask; one
         * told "รอโอน" already knows the answer.
         */
        $this->tellAgent(
            $updated,
            'คำขอเบิกได้รับอนุมัติแล้ว',
            fn (string $baht) => "จำนวน {$baht} บาท อนุมัติแล้ว อยู่ระหว่างรอโอน จะแจ้งอีกครั้งเมื่อโอนเรียบร้อย",
        );

        return $updated;
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
        $updated = $this->transition($request, $actor, WithdrawalStatus::Rejected, 'commission_withdrawal.rejected', [
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ]);

        /*
         * THE MOST IMPORTANT ONE OF THE FOUR. The admin is required to type a
         * reason precisely because the agent needs it — and until now that
         * reason sat in a row the agent would only ever see by opening the
         * portal and thinking to look. A refusal nobody is told about is a
         * request that just never happens, and the agent's money stays
         * unclaimed while they wait for an answer that was given days ago.
         *
         * The reason is passed through verbatim, as it is everywhere else.
         */
        $this->tellAgent(
            $updated,
            'คำขอเบิกไม่ได้รับอนุมัติ',
            fn (string $baht) => "จำนวน {$baht} บาท ไม่ได้รับอนุมัติ — เหตุผล: {$reason}",
        );

        return $updated;
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
        $updated = DB::transaction(fn () => $this->settleOne($request, $actor, $reference));

        $this->announceTransferred($updated);

        return $updated;
    }

    /**
     * 2026-09-16 — A WHOLE ROUND OF TRANSFERS, RECORDED IN ONE PRESS.
     *
     * Owner: "เราโอนเองผ่านระบบการทำงาน Bank … ต้องได้รับข้อมูลจากฝ่ายบัญชีก่อน
     * ว่าโอนแล้วจึงมากดยืนยัน" — accounting works in rounds and reports back in
     * batches, so ten transfers used to mean ten presses and ten browser
     * prompts asking for the reference the admin had just typed.
     *
     * ── ALL OR NOTHING, FOR THE SAME REASON AS payOutMany ──
     *
     * This is the press that settles commission_ledger rows and emails agents
     * that their money has arrived. Half of it succeeding is the worst outcome
     * available: some agents told, some not, some ledger rows closed, and
     * nothing on any screen saying which. One transaction; a refusal anywhere
     * rolls the whole round back and the admin ticks again.
     *
     * ── WHY THE EMAILS ARE OUTSIDE THE TRANSACTION ──
     *
     * Identical to payOutMany: a notification written inside would be rolled
     * back with everything else, but one already HANDED to the mailer cannot
     * be. Announcing after the commit means the only thing a late failure can
     * cost is an email, never a settled ledger row that nobody was told about.
     *
     * @param  array<int, CommissionWithdrawalRequest>  $requests
     * @return Collection<int, CommissionWithdrawalRequest>
     */
    public function markManyTransferred(array $requests, User $actor, ?string $reference = null): Collection
    {
        $settled = DB::transaction(function () use ($requests, $actor, $reference) {
            $done = collect();

            foreach ($requests as $request) {
                $done->push($this->settleOne($request, $actor, $reference));
            }

            return $done;
        });

        foreach ($settled as $request) {
            $this->announceTransferred($request);
        }

        return $settled;
    }

    /**
     * One request settled — the state change and the ledger, no transaction of
     * its own and no notification.
     *
     * Extracted 2026-09-16 so the single press and the batch press do THE SAME
     * THING. A second copy of "flip to Transferred and settle what it fully
     * covers" is a second place for money to be closed differently, and this
     * particular step is the only one in the system that agents are emailed
     * about.
     */
    private function settleOne(
        CommissionWithdrawalRequest $request,
        User $actor,
        ?string $reference,
    ): CommissionWithdrawalRequest {
        if ($request->status !== WithdrawalStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => 'บันทึกการโอนได้เฉพาะคำขอที่อนุมัติแล้วเท่านั้น',
            ]);
        }

        $settled = $this->transition($request, $actor, WithdrawalStatus::Transferred, 'commission_withdrawal.transferred', [
            'transferred_at' => now(),
            'transfer_reference' => $reference,
        ]);

        $this->settleFullyAllocatedLedgerRows($settled);

        return $settled;
    }

    /**
     * 2026-09-15 — THIS is where the money email belongs, and where it now
     * lives.
     *
     * CommissionPaid is the one notification type in this file with email
     * enabled (config/notifications.php), and it used to fire the instant an
     * admin pressed "จ่ายแล้ว" on the payout screen — which was the moment they
     * DECIDED to pay, not the moment the money moved. Agents were emailed
     * "เงินเข้าแล้ว" while accounting had not yet opened the bank.
     *
     * Here it fires after the transfer has been confirmed by the person who saw
     * it happen, which is the only point at which the sentence is true.
     */
    private function announceTransferred(CommissionWithdrawalRequest $request): void
    {
        if (! $request->agent) {
            return;
        }

        $baht = number_format((int) $request->amount_satang / 100, 2);
        $withReference = $request->transfer_reference
            ? " (อ้างอิง {$request->transfer_reference})"
            : '';

        // "โอนเข้าบัญชีของคุณแล้ว" is addressed to the payee, and for a
        // company-seat payout the readers are the admins who authorised it —
        // the sentence has to name whose account the money went to.
        $isHouse = $request->agent->isCommissionHouseAccount();

        foreach ($this->audienceFor($request->agent) as $recipient) {
            $this->notifier->notify(
                $recipient,
                NotificationType::CommissionPaid,
                $isHouse ? 'โอนส่วนของบริษัทเรียบร้อยแล้ว' : 'ค่าแนะนำโอนเรียบร้อยแล้ว',
                $isHouse
                    ? "ส่วนของบริษัท {$baht} บาท โอนเข้าบัญชีบริษัทแล้ว{$withReference}"
                    : "จำนวน {$baht} บาท โอนเข้าบัญชีของคุณแล้ว{$withReference}",
                '/withdrawals',
                ['commission_withdrawal_request_id' => $request->id],
            );
        }
    }

    /**
     * One message to the agent about their own payout.
     *
     * A closure rather than a finished string so every caller formats the
     * amount the same way — BR-3 says satang all the way to the display
     * layer, and this is the display layer.
     */
    private function tellAgent(CommissionWithdrawalRequest $request, string $title, callable $body): void
    {
        if (! $request->agent) {
            return;
        }

        // A company-seat payout is announced to the company's admins instead
        // of into a mailbox that cannot receive it — see audienceFor().
        foreach ($this->audienceFor($request->agent) as $recipient) {
            $this->notifier->notify(
                $recipient,
                NotificationType::CommissionWithdrawalDecided,
                $title,
                $body(number_format((int) $request->amount_satang / 100, 2)),
                '/withdrawals',
                ['commission_withdrawal_request_id' => $request->id],
            );
        }
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
