<?php

namespace App\Services\Account;

use App\Enums\AccountDeletionRequestStatus;
use App\Enums\AccountDeletionResolution;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Models\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalItem;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * MOB-12 (2026-10-02) — in-app account deletion, as a request a Company Admin
 * decides. Apple App Store guideline 5.1.1(v) is the reason it exists; the
 * owner's decision is the shape it has:
 *
 *   1. the agent asks (re-entering their password) — they are signed out
 *      everywhere at once and the login gate refuses them from then on;
 *   2. an admin settles what is still attached to them — pending commission,
 *      downline, clients — with the screens that already do those jobs
 *      (AccountDeletionImpactService only COUNTS them, as warnings);
 *   3. the admin approves, and the account is anonymised in place — or
 *      rejects, and the agent can sign in again.
 *
 * ── AMENDED 2026-10-03 (owner decision): NOT EVERY REQUEST WAITS ──
 *
 * The admin step exists for money still owed. So an agent with NO unpaid
 * commission is deleted immediately, and an agent WITH unpaid commission
 * chooses in the dialog: "ยินยอมไม่รับค่าคอม" (waive — the unpaid ledger rows
 * become payment_status = forfeited and the account is deleted immediately)
 * or "ต้องการรับค่าคอม" (keep — the flow above, unchanged). Waiving is refused
 * while any of that money is already in a payout someone is working on (see
 * inFlightReason()). Downline and clients stay attached to the anonymised
 * account until an admin moves them — owner's decision, same as before.
 *
 * Retention (owner, 2026-10-03): the payout bank snapshots, audit history and
 * the reason text are kept with no limit — consent was given at sign-up.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 *   A. A REQUEST THAT DOES NOT SIGN THE PERSON OUT. "I asked you to delete my
 *      account and the app is still logged in" is the one outcome that reads
 *      as the feature not working at all. Three credentials can keep a person
 *      in, and request() withdraws all three in the same transaction that
 *      writes the row: every Sanctum token (the agent portal and the mobile
 *      app), every server-side session (the database session driver keeps
 *      them in `sessions`), and the remember-me token (a recaller cookie logs
 *      a browser straight back in without ever passing the login gate).
 *
 *   B. ANONYMISING BY DELETING. The `users` row is an FK target for the
 *      immutable commission ledger (BR-4), for referrals, clients, payouts and
 *      the audit trail. Deleting it would either fail on a constraint or —
 *      where the FK nulls on delete — quietly orphan money that was really
 *      earned and really paid. So approve() never deletes the row: it
 *      REPLACES every personal field in place and leaves every id where it
 *      was. Anonymising never touches commission_ledger; the tests prove its
 *      rows are byte-identical afterwards (a waive changes only the waived
 *      rows' payment_status, and the tests prove that too).
 *
 *   C. AN ANONYMISED ACCOUNT THAT CAN STILL BE ENTERED. approve() replaces the
 *      email AND the password with values nobody holds, soft-deletes the row
 *      (exactly the state UserService::deactivate leaves a switched-off
 *      account in, so every existing "is this person active" check already
 *      treats it correctly), and the login gate keeps refusing an approved
 *      request on top of that. UserService::restore refuses it as well, so
 *      the roster's กู้คืน cannot bring back a person who asked to be gone.
 *
 *   D. PERSONAL DATA COPIED INTO THE AUDIT TRAIL. audit_logs is read by more
 *      people, for longer, than the row it describes (the same argument
 *      UserService makes for passwords and national IDs). The approval row
 *      therefore records WHICH fields were cleared, by name, and never what
 *      they held.
 *
 * ── WHAT THIS DOES NOT TOUCH, AND WHY ──
 *
 *   * commission_ledger amounts — BR-4, never. Only a waive moves a row's
 *     payment_status (to forfeited), the one field BR-4 lets change.
 *   * clients — they are the company's customers, not the agent's personal
 *     data; moving them to another agent is the admin's manual step.
 *   * commission_withdrawal_requests.bank_* — a payout's bank snapshot is
 *     evidence of where money was sent, and is kept with the payout record.
 *   * historical audit_logs rows that already mention this person.
 *   Each of those is a retention decision, not a technical one; they are kept
 *   as they are (owner confirmed 2026-10-03: no retention limit).
 */
class AccountDeletionService
{
    public function __construct(private readonly AccountDeletionImpactService $impact) {}

    /** The two answers the dialog can send when commission is unpaid. */
    public const CHOICE_WAIVE = 'waive';

    public const CHOICE_KEEP = 'keep';

    /** Same allowance as the login form (LoginRequest::MAX_ATTEMPTS). */
    private const MAX_PASSWORD_ATTEMPTS = 5;

    /** Seconds; the login throttle's decay (RateLimiter::hit's default). */
    private const PASSWORD_DECAY_SECONDS = 60;

    /**
     * users columns that hold something about the PERSON and are set to null
     * on approval. Name, email and password are not in this list because they
     * cannot be null (the columns are NOT NULL) — they are REPLACED instead,
     * see anonymise().
     *
     * Kept as one list so the audit row can name exactly what was cleared and
     * the test can assert exactly the same set — a field added to `users`
     * next year that holds personal data belongs here.
     */
    public const CLEARED_FIELDS = [
        'phone',
        'bank_name',
        'bank_account_number',
        'bank_account_holder_name',
        'national_id',
        'national_id_hash',
        'id_document_type',
        'avatar_path',
        'background_type',
        'background_config',
        'background_image_path',
        // An admin's words about this person's registration — about them.
        'approval_rejection_reason',
        // The address a previous removal released (UserService::deactivate).
        'email_released_from',
        'remember_token',
    ];

    /** The public disk UserProfileService stores avatars and backgrounds on. */
    private const PROFILE_DISK = 'public';

    /**
     * What the dialog needs before the agent chooses (GET .../preview).
     *
     * `pending_commission_satang` is the same figure the admin queue shows
     * for this person (AccountDeletionImpactService), read under the agent's
     * own tenant scope. `can_waive` is false while any of it is IN FLIGHT —
     * see inFlightReason() — and the reason is the sentence the dialog shows
     * in place of the waive option.
     *
     * @return array{pending_commission_satang: int, can_waive: bool, waive_blocked_reason: ?string}
     */
    public function preview(User $user): array
    {
        $blocked = $this->inFlightReason($user);

        return [
            'pending_commission_satang' => $this->impact->forUser($user)['pending_commission_satang'],
            'can_waive' => $blocked === null,
            'waive_blocked_reason' => $blocked,
        ];
    }

    /**
     * Step 1 — the agent asks. Three outcomes (owner decision 2026-10-03):
     *
     *   * nothing unpaid               → deleted IMMEDIATELY (no admin);
     *   * unpaid + `waive`             → the unpaid rows are FORFEITED and the
     *                                    account deleted immediately;
     *   * unpaid + `keep`              → the pending request an admin decides.
     *
     * Authorization (agents only) is the Policy's job and has already run;
     * this re-checks the password and then does everything in one
     * transaction, so a failure part-way can never leave a request on file
     * for a person who is still signed in, or a forfeited row on an account
     * that was not deleted.
     *
     * The unpaid figure is recomputed HERE, under row locks, never taken from
     * the preview the client saw: a sale that closed between the two, or a
     * payout raised in the meantime, changes the answer.
     *
     * One edge is decided rather than asked: an agent with a payout IN FLIGHT
     * whose unpaid rows net to zero (a refund cancelling a sale, while an
     * earlier payout is still waiting for its transfer) is not deleted on the
     * spot — the money that payout represents is still on its way to them, so
     * the request goes to an admin exactly as `keep` would.
     *
     * @param  'waive'|'keep'|null  $commissionChoice  ignored when nothing is unpaid
     *
     * @throws ValidationException wrong password, or no choice made while commission is unpaid (422)
     * @throws ThrottleRequestsException too many wrong passwords (429)
     * @throws ConflictHttpException a request is already pending, or waive while something is in flight (409)
     */
    public function request(User $user, string $password, ?string $reason, ?string $commissionChoice = null): AccountDeletionRequest
    {
        $this->assertPassword($user, $password);

        return DB::transaction(function () use ($user, $reason, $commissionChoice) {
            /*
             * Serialise concurrent requests from the same person (two taps,
             * two devices) on their own users row, then check. The unique
             * `pending_user_id` index is the backstop if anything ever writes
             * around this method.
             */
            User::withoutGlobalScope(TenantScope::class)->whereKey($user->id)->lockForUpdate()->first();

            $existing = AccountDeletionRequest::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('status', AccountDeletionRequestStatus::Pending->value)
                ->first();

            if ($existing !== null) {
                throw new ConflictHttpException('มีคำขอลบบัญชีที่รอผู้ดูแลพิจารณาอยู่แล้ว ไม่ต้องส่งซ้ำ');
            }

            // Same set AccountDeletionImpactService sums, locked so a payout
            // cannot settle or reserve one of them while this runs.
            $unpaidRows = CommissionLedger::query()
                ->where('agent_id', $user->id)
                ->where('payment_status', PaymentStatus::Pending->value)
                ->lockForUpdate()
                ->get();
            $unpaid = (int) $unpaidRows->sum('amount_satang');
            $inFlight = $this->inFlightReason($user);
            $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

            if ($unpaid === 0 && $inFlight === null) {
                return $this->deleteImmediately($user, $reason, collect(), 0);
            }

            if ($unpaid !== 0) {
                if ($commissionChoice === null) {
                    throw ValidationException::withMessages([
                        'commission_choice' => sprintf(
                            'คุณมีค่าแนะนำค้างจ่าย %s บาท กรุณาเลือกว่าจะรอรับค่าแนะนำ หรือยินยอมสละสิทธิ์',
                            number_format($unpaid / 100, 2),
                        ),
                    ]);
                }

                if ($commissionChoice === self::CHOICE_WAIVE) {
                    if ($inFlight !== null) {
                        throw new ConflictHttpException($inFlight);
                    }

                    return $this->deleteImmediately($user, $reason, $unpaidRows, $unpaid);
                }
            }

            return $this->fileForAdmin($user, $reason);
        });
    }

    /**
     * Step 3a — approve: anonymise the account in place. See B, C and D above.
     *
     * @throws ConflictHttpException the request is no longer pending (409)
     */
    public function approve(AccountDeletionRequest $deletionRequest, User $actor): AccountDeletionRequest
    {
        return DB::transaction(function () use ($deletionRequest, $actor) {
            $locked = $this->lockPending($deletionRequest);

            $user = User::withoutGlobalScope(TenantScope::class)
                ->withTrashed()
                ->whereKey($locked->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'status' => AccountDeletionRequestStatus::Approved,
                'resolution' => AccountDeletionResolution::Admin,
                'decided_at' => now(),
                'decided_by' => $actor->id,
            ])->save();

            $this->finalise($locked, $user, $actor);

            return $locked->refresh();
        });
    }

    /**
     * Why waiving is not possible right now, or null when it is.
     *
     * "IN FLIGHT" means some of this agent's unpaid commission is already
     * part of a payout that a person is working on, so marking it forfeited
     * would contradict a decision somebody else has made or is about to make:
     *
     *   1. an OPEN commission withdrawal request — pending_review (the agent
     *      asked, nobody decided) or approved (decided, transfer not yet
     *      recorded). Admin payouts (จ่ายค่าแนะนำ, single or batch) are rows in
     *      the same table created already approved (WithdrawalSource), so a
     *      payout batch that is being processed is covered by the same check.
     *      Their allocations reserve ledger rows (commission_withdrawal_items)
     *      that are still `pending` until the transfer is recorded.
     *   2. an unpaid row that has ALREADY been partly paid — a transferred or
     *      approved allocation against a row whose payment_status is still
     *      `pending` (allocations can split a row across payouts, and the row
     *      only flips to `paid` when they add up to all of it). Forfeiting it
     *      would label money that was actually sent as given up.
     *
     * The bulk "mark paid" (CommissionPayoutService::markAgentPaid) settles in
     * one transaction and leaves nothing in flight, so it needs no check here.
     */
    public function inFlightReason(User $user): ?string
    {
        $openPayout = CommissionWithdrawalRequest::withoutGlobalScope(TenantScope::class)
            ->where('agent_id', $user->id)
            ->whereIn('status', array_column(WithdrawalStatus::open(), 'value'))
            ->exists();

        if ($openPayout) {
            return 'มีรายการเบิก/ทำจ่ายค่าแนะนำของคุณที่กำลังดำเนินการอยู่ จึงสละสิทธิ์ตอนนี้ไม่ได้ — กรุณาเลือก "ต้องการรับค่าคอม" แล้วผู้ดูแลจะจ่ายให้ก่อนลบบัญชี';
        }

        $partlyPaid = CommissionWithdrawalItem::query()
            ->whereHas('ledger', fn ($q) => $q->withoutGlobalScope(TenantScope::class)
                ->where('agent_id', $user->id)
                ->where('payment_status', PaymentStatus::Pending->value))
            ->whereHas('request', fn ($q) => $q->withoutGlobalScope(TenantScope::class)
                ->whereIn('status', [WithdrawalStatus::Approved->value, WithdrawalStatus::Transferred->value]))
            ->exists();

        if ($partlyPaid) {
            return 'ค่าแนะนำบางรายการของคุณได้รับโอนไปแล้วบางส่วน จึงสละสิทธิ์ตอนนี้ไม่ได้ — กรุณาเลือก "ต้องการรับค่าคอม" แล้วผู้ดูแลจะจ่ายส่วนที่เหลือให้ก่อนลบบัญชี';
        }

        return null;
    }

    /**
     * The `keep` path (and the in-flight edge): the request an admin decides.
     */
    private function fileForAdmin(User $user, ?string $reason): AccountDeletionRequest
    {
        $deletionRequest = AccountDeletionRequest::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'status' => AccountDeletionRequestStatus::Pending,
            'reason' => $reason,
            'requested_at' => now(),
        ]);

        $signedOut = $this->signOutEverywhere($user);

        $this->writeAudit('account_deletion.requested', $user, $user, null, [
            'account_deletion_request_id' => $deletionRequest->id,
            'status' => AccountDeletionRequestStatus::Pending->value,
            // Whether a reason was given, not the reason: it is the
            // agent's free text and may well contain personal detail.
            // It is on the request row for the admin to read.
            'reason_given' => $deletionRequest->reason !== null,
        ] + $signedOut);

        return $deletionRequest;
    }

    /**
     * Nothing owed, or waived: the request is born approved and the account is
     * anonymised in the same transaction.
     *
     * decided_by stays NULL on purpose. Nobody DECIDED this — the system
     * applied the owner's rule to the agent's own choice — and a row naming
     * the agent as its own approver would read, in the admin queue, as if an
     * account had approved itself. `resolution = immediate` says what
     * happened; the audit rows name the agent as the actor.
     *
     * @param  Collection<int, CommissionLedger>  $forfeitRows  the locked unpaid rows (empty when nothing is owed)
     */
    private function deleteImmediately(User $user, ?string $reason, Collection $forfeitRows, int $forfeitSatang): AccountDeletionRequest
    {
        $waived = $forfeitRows->isNotEmpty();

        $deletionRequest = AccountDeletionRequest::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'status' => AccountDeletionRequestStatus::Approved,
            'reason' => $reason,
            'requested_at' => now(),
            'decided_at' => now(),
            'decided_by' => null,
            'resolution' => AccountDeletionResolution::Immediate,
            'forfeited_commission_satang' => $waived ? $forfeitSatang : null,
            'decision_note' => $waived
                ? sprintf('ลบทันที: สมาชิกยินยอมสละค่าคอมค้างจ่าย ฿%s', number_format($forfeitSatang / 100, 2))
                : 'ลบทันที: ไม่มีค่าคอมค้างจ่าย',
        ]);

        $this->writeAudit('account_deletion.requested', $user, $user, null, [
            'account_deletion_request_id' => $deletionRequest->id,
            'status' => AccountDeletionRequestStatus::Pending->value,
            'reason_given' => $deletionRequest->reason !== null,
            'commission_choice' => $waived ? self::CHOICE_WAIVE : null,
        ]);

        if ($waived) {
            $this->forfeit($user, $deletionRequest, $forfeitRows, $forfeitSatang);
        }

        $this->finalise($deletionRequest, $user, $user);

        return $deletionRequest->refresh();
    }

    /**
     * Mark exactly the locked unpaid rows forfeited (BR-4: payment_status is
     * the one mutable field — amounts, rates and every other column stay as
     * written; CommissionLedger's own `updating` guard would refuse anything
     * else). Guarded on `pending` again so a row that changed under us is
     * left alone rather than overwritten.
     *
     * @param  Collection<int, CommissionLedger>  $rows
     */
    private function forfeit(User $user, AccountDeletionRequest $deletionRequest, Collection $rows, int $totalSatang): void
    {
        $changed = CommissionLedger::query()
            ->whereIn('id', $rows->pluck('id'))
            ->where('payment_status', PaymentStatus::Pending->value)
            ->update(['payment_status' => PaymentStatus::Forfeited->value, 'updated_at' => now()]);

        $this->writeAudit(
            'account_deletion.commission_forfeited',
            $user,
            $user,
            ['payment_status' => PaymentStatus::Pending->value],
            [
                'account_deletion_request_id' => $deletionRequest->id,
                'payment_status' => PaymentStatus::Forfeited->value,
                'forfeited_satang' => $totalSatang,
                'commission_ledger_ids' => $rows->pluck('id')->values()->all(),
                'rows_changed' => $changed,
            ],
        );
    }

    /**
     * The anonymisation itself, shared by admin approval and immediate
     * deletion. The caller has already written the request's decided state.
     */
    private function finalise(AccountDeletionRequest $deletionRequest, User $user, User $actor): void
    {
        $cleared = $this->anonymise($user);
        $deletedFiles = $this->deleteProfileFiles($cleared['files']);
        $signedOut = $this->signOutEverywhere($user);
        $socialAccounts = $user->socialAccounts()->delete();

        if (! $user->trashed()) {
            // SoftDeletes — the same state a deactivated account is in.
            $user->delete();
        }

        $this->writeAudit(
            'account_deletion.approved',
            $user,
            $actor,
            [
                'account_deletion_request_id' => $deletionRequest->id,
                'status' => AccountDeletionRequestStatus::Pending->value,
                'deleted_at' => null,
            ],
            [
                'account_deletion_request_id' => $deletionRequest->id,
                'status' => AccountDeletionRequestStatus::Approved->value,
                'resolution' => $deletionRequest->resolution?->value,
                'deleted_at' => $user->deleted_at?->toIso8601String(),
                // Names only — see D. "name/email/password replaced" is
                // stated separately because they are not cleared to null.
                'fields_cleared' => $cleared['fields'],
                'fields_replaced' => ['first_name', 'last_name', 'name', 'email', 'password'],
                'profile_files_deleted' => $deletedFiles,
                'social_accounts_removed' => $socialAccounts,
            ] + $signedOut,
        );
    }

    /**
     * Step 3b — reject: the request is closed and the login block lifts on its
     * own, because the gate only refuses while a request is pending/approved.
     * Nothing is restored because nothing was taken: the tokens revoked at
     * request time are simply replaced by the next login.
     *
     * @throws ConflictHttpException the request is no longer pending (409)
     */
    public function reject(AccountDeletionRequest $deletionRequest, User $actor, ?string $note): AccountDeletionRequest
    {
        return DB::transaction(function () use ($deletionRequest, $actor, $note) {
            $locked = $this->lockPending($deletionRequest);
            $note = $note === null || trim($note) === '' ? null : trim($note);

            $locked->forceFill([
                'status' => AccountDeletionRequestStatus::Rejected,
                'resolution' => AccountDeletionResolution::Admin,
                'decided_at' => now(),
                'decided_by' => $actor->id,
                'decision_note' => $note,
            ])->save();

            $user = User::withoutGlobalScope(TenantScope::class)->withTrashed()->find($locked->user_id);

            if ($user !== null) {
                $this->writeAudit(
                    'account_deletion.rejected',
                    $user,
                    $actor,
                    ['account_deletion_request_id' => $locked->id, 'status' => AccountDeletionRequestStatus::Pending->value],
                    [
                        'account_deletion_request_id' => $locked->id,
                        'status' => AccountDeletionRequestStatus::Rejected->value,
                        'login_unblocked' => true,
                        'note_given' => $note !== null,
                    ],
                );
            }

            return $locked->refresh();
        });
    }

    /**
     * Does this user have a request that keeps them out? Asked by the login
     * gate with nobody signed in, so it reads without the tenant scope — the
     * row is found by the user's own id, there is nothing to leak.
     */
    public static function blocksLogin(User $user): bool
    {
        return AccountDeletionRequest::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('status', array_map(
                fn (AccountDeletionRequestStatus $status) => $status->value,
                AccountDeletionRequestStatus::blockingLogin(),
            ))
            ->exists();
    }

    /**
     * Was this account anonymised on its owner's request? Asked by
     * UserService::restore, which must not hand the row back (see C).
     */
    public static function wasDeletedOnRequest(User $user): bool
    {
        return AccountDeletionRequest::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', AccountDeletionRequestStatus::Approved->value)
            ->exists();
    }

    /**
     * Re-authentication, throttled like the login form: five wrong passwords
     * and the sixth attempt is refused before the hash is even checked.
     * Keyed per user — the caller is already authenticated, so there is no
     * enumeration surface to protect, only a stolen-session guessing one.
     */
    private function assertPassword(User $user, string $password): void
    {
        $key = 'account-deletion-password|'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PASSWORD_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException(
                "ใส่รหัสผ่านผิดหลายครั้งเกินไป กรุณารอ {$seconds} วินาทีแล้วลองใหม่",
                null,
                ['Retry-After' => $seconds],
            );
        }

        if (! Hash::check($password, (string) $user->getAuthPassword())) {
            RateLimiter::hit($key, self::PASSWORD_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'password' => 'รหัสผ่านไม่ถูกต้อง',
            ]);
        }

        RateLimiter::clear($key);
    }

    /**
     * Re-read the request under a row lock and refuse anything not pending.
     * Two admins pressing at once: the second waits for the first, then sees
     * a decided row and gets the 409 instead of anonymising twice.
     */
    private function lockPending(AccountDeletionRequest $deletionRequest): AccountDeletionRequest
    {
        $locked = AccountDeletionRequest::withoutGlobalScopes()
            ->whereKey($deletionRequest->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->isPending()) {
            throw new ConflictHttpException('คำขอนี้ไม่ได้อยู่ในสถานะรอพิจารณาแล้ว (อาจถูกดำเนินการไปแล้วโดยผู้ดูแลคนอื่น)');
        }

        return $locked;
    }

    /**
     * Replace the person with a placeholder, in place.
     *
     * The display name is written through first_name/last_name because
     * User::booted()'s saving hook re-derives `name` from those two whenever
     * either changes — writing `name` alone would be overwritten in the same
     * save. "บัญชีที่ถูกลบ" + "#{id}" therefore lands as the name
     * "บัญชีที่ถูกลบ #{id}" on every screen that reads `name` (the ledger,
     * payouts, the team tree), which is what lets those screens keep making
     * sense without saying who the person was.
     *
     * The email carries the id, so it is unique by construction and can never
     * collide on the UNIQUE index; `.invalid` is reserved (RFC 2606) so no
     * mail can ever be delivered to it.
     *
     * @return array{fields: list<string>, files: list<string>}
     */
    private function anonymise(User $user): array
    {
        $cleared = array_values(array_filter(
            self::CLEARED_FIELDS,
            fn (string $field) => $user->getAttribute($field) !== null,
        ));

        $files = array_values(array_filter([$user->avatar_path, $user->background_image_path]));

        $user->forceFill(array_fill_keys(self::CLEARED_FIELDS, null) + [
            'first_name' => 'บัญชีที่ถูกลบ',
            'last_name' => '#'.$user->id,
            'email' => sprintf('deleted-%d@deleted.invalid', $user->id),
            // Hashed by the model cast. Nobody is ever told this value, so the
            // account has no working password from this moment on.
            'password' => Str::random(64),
            'email_notifications_enabled' => false,
        ])->save();

        return ['fields' => $cleared, 'files' => $files];
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteProfileFiles(array $paths): int
    {
        $deleted = 0;

        foreach ($paths as $path) {
            if (Storage::disk(self::PROFILE_DISK)->exists($path)) {
                Storage::disk(self::PROFILE_DISK)->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * See A. Returns counts for the audit row.
     *
     * Device tokens (push notifications) are removed too: a person who asked
     * to leave should not keep receiving pushes on a phone that is signed out.
     * The table is added by the mobile push work; guarded so this works with
     * or without it.
     *
     * @return array{api_tokens_revoked: int, sessions_ended: int, device_tokens_removed: int}
     */
    private function signOutEverywhere(User $user): array
    {
        $tokens = $user->tokens()->delete();

        $sessions = 0;
        if (config('session.driver') === 'database') {
            $sessions = DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }

        $user->forceFill(['remember_token' => null])->save();

        $devices = Schema::hasTable('device_tokens')
            ? DB::table('device_tokens')->where('user_id', $user->id)->delete()
            : 0;

        return [
            'api_tokens_revoked' => $tokens,
            'sessions_ended' => $sessions,
            'device_tokens_removed' => $devices,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function writeAudit(string $action, User $target, User $actor, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::create([
            'company_id' => $target->company_id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
        ]);
    }
}
