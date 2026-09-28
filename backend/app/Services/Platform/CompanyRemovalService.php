<?php

namespace App\Services\Platform;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Deleting a company, and deciding whether it may be deleted at all.
 *
 * ═══ THE OWNER'S RULE (2026-09-26) ═══
 *
 * "ช่วงนี้มีการทดสอบเยอะ … ให้ลบได้โดยสอบถามว่าจะลบทั้งหมดหรือไม่ ทั้ง User
 * ค่าคอม ข้อมูลทั้งหมด … และเมื่อทดสอบเสร็จ จะลบได้ต่อเมื่อไม่มีข้อมูลผู้สมัคร
 * ค่าคอมหรือข้อมูลที่เกี่ยวข้อง".
 *
 * Two regimes, and which one a company is in is decided PER COMPANY by its
 * `is_test` flag — the owner chose that over a platform-wide switch, so a real
 * tenant can never be wiped because somebody forgot to turn a global mode off
 * before go-live:
 *
 *   บริษัททดสอบ (is_test)   → may be wiped WHOLE: its users, its commission
 *                             ledger, its orders, everything it owns.
 *   บริษัทจริง (not test)   → may be deleted only while it holds no business
 *                             data: no agent or applicant, no customer, no deal,
 *                             no order, no commission row, no withdrawal. Its
 *                             own admins and its configuration do not count.
 *
 * ═══ THE FLAG IS A ONE-WAY DOOR ═══
 *
 * It may be switched ON only for a company that is empty — which is exactly
 * the company that could be deleted anyway, so switching it on grants
 * nothing new. It may be switched OFF at any time, and that writes
 * `went_live_at`, after which it can never come back on. Without that, a real
 * company could be relabelled as a test on a Tuesday and wiped on a Wednesday.
 *
 * ═══ BR-4, AND WHY THIS IS ALLOWED TO BREAK IT ═══
 *
 * commission_ledger rows are never edited or deleted, and the model enforces
 * that with a `deleting` hook that always throws. That rule protects a real
 * company's payout history. A test company never paid anybody, so its rows
 * go through the query builder here — deliberately, and only after the flag
 * has been checked. `uat:purge-commission-plans` is the only other caller,
 * scoped to `uat-plan-*` slugs, and it shares wipe() below.
 *
 * ═══ WHAT SURVIVES ═══
 *
 *   · audit_logs — their company_id is nullOnDelete, so the trail of what was
 *     done in the company outlives it, and so does the entry this service
 *     writes about the deletion itself (§6: every action on money is logged,
 *     and deleting money is one).
 *   · uploaded FILES on disk (slips, documents, product media) — the same
 *     caveat uat:reset states; nothing here pretends otherwise.
 */
class CompanyRemovalService
{
    /** What blocks deleting a REAL company. Each maps to a count. */
    private const BLOCKERS = ['agents', 'clients', 'referrals', 'orders', 'commission', 'withdrawals'];

    /**
     * What a deletion would take, and whether it is allowed.
     *
     * @return array{
     *   mode: 'wipe'|'empty'|'blocked',
     *   blockers: list<array{key: string, count: int}>,
     *   contents: list<array{key: string, count: int}>
     * }
     */
    public function assess(Company $company): array
    {
        $counts = $this->counts($company);

        $blockers = [];
        foreach (self::BLOCKERS as $key) {
            if ($counts[$key] > 0) {
                $blockers[] = ['key' => $key, 'count' => $counts[$key]];
            }
        }

        $contents = [];
        foreach (['users', 'clients', 'referrals', 'orders', 'commission', 'withdrawals', 'products'] as $key) {
            $contents[] = ['key' => $key, 'count' => $counts[$key]];
        }

        $mode = match (true) {
            (bool) $company->is_test => 'wipe',
            $blockers === [] => 'empty',
            default => 'blocked',
        };

        return ['mode' => $mode, 'blockers' => $blockers, 'contents' => $contents];
    }

    /**
     * Switch the test flag. ON only for an empty company that has never gone
     * live; OFF always, and permanently.
     */
    public function setTestMode(Company $company, bool $isTest, User $actor): Company
    {
        if ($isTest === (bool) $company->is_test) {
            return $company;
        }

        if ($isTest) {
            if ($company->went_live_at !== null) {
                throw ValidationException::withMessages([
                    'is_test' => 'บริษัทนี้เปิดใช้งานจริงไปแล้วเมื่อ '.$company->went_live_at->format('d/m/Y')
                        .' จึงกลับมาเป็นบริษัททดสอบไม่ได้อีก',
                ]);
            }

            if ($this->assess($company)['blockers'] !== []) {
                throw ValidationException::withMessages([
                    'is_test' => 'บริษัทนี้มีข้อมูลการใช้งานแล้ว (ตัวแทน ลูกค้า ดีล หรือค่าคอม) จึงตั้งเป็นบริษัททดสอบไม่ได้',
                ]);
            }
        }

        $old = ['is_test' => (bool) $company->is_test, 'went_live_at' => $company->went_live_at?->toIso8601String()];

        $company->forceFill([
            'is_test' => $isTest,
            'went_live_at' => $isTest ? null : now(),
        ])->save();

        $this->audit($company->id, $actor, $isTest ? 'company.marked_test' : 'company.went_live', $company, $old, [
            'is_test' => $isTest,
            'went_live_at' => $company->went_live_at?->toIso8601String(),
        ]);

        return $company;
    }

    /**
     * Delete the company, if the rules allow it.
     *
     * `$confirmName` must be the company's name, typed back. It names the
     * exact thing being destroyed and cannot be muscle memory — the same
     * reason uat:reset asks for the database name.
     */
    public function remove(Company $company, string $confirmName, User $actor): void
    {
        if (trim($confirmName) !== trim((string) $company->name)) {
            throw ValidationException::withMessages([
                'confirm_name' => 'ชื่อที่พิมพ์ไม่ตรงกับชื่อบริษัท — ยังไม่ได้ลบอะไร',
            ]);
        }

        $assessment = $this->assess($company);

        if ($assessment['mode'] === 'blocked') {
            throw ValidationException::withMessages([
                'company' => 'บริษัทนี้มีข้อมูลการใช้งานแล้ว จึงลบไม่ได้ — ใช้ "ปิดบริษัท" แทน',
            ]);
        }

        $snapshot = [
            'name' => $company->name,
            'slug' => $company->slug,
            'mode' => $assessment['mode'],
            'contents' => $assessment['contents'],
        ];
        $companyId = $company->id;

        $this->wipe($company);

        // Written AFTER the wipe, with no company_id: the company is gone,
        // and a row pointing at it would be nulled by the FK anyway.
        $this->audit(null, $actor, 'company.deleted', $company, $snapshot, null, $companyId);
    }

    /**
     * Remove the company and everything that belongs to it, users included.
     *
     * Callers decide WHETHER. This only knows HOW, and is shared with
     * uat:purge-commission-plans so there is one routine that deletes a tenant
     * rather than two that drift apart.
     */
    public function wipe(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            $companyId = $company->id;

            $userIds = DB::table('users')->where('company_id', $companyId)->pluck('id')->all();

            /*
             * users.current_rank_id points at agent_ranks, which the cascade
             * is about to remove, and users.manager_id points at users that
             * are about to go. Cut both before anything is deleted.
             */
            if ($userIds !== []) {
                DB::table('users')->whereIn('id', $userIds)->update(['current_rank_id' => null, 'manager_id' => null]);
            }

            /*
             * The house-account pointer runs the other way — companies → users
             * — and would stop the users below from being deleted.
             */
            DB::table('companies')->where('id', $companyId)->update(['commission_house_user_id' => null]);

            /*
             * EVERY row carrying this company_id, BEFORE the company row goes.
             *
             * Not left to the cascade, and the order is the whole point. Some
             * tables hang off company_id with nullOnDelete, because there a
             * NULL company_id means "shared by every company": badges,
             * gamification rules, reward items, announcements. Delete the
             * company first and its badge becomes everybody's badge. A test
             * proved it (CompanyRemovalTest, 2026-09-26) — and the same leak
             * was in uat:purge-commission-plans, which now calls this.
             *
             * audit_logs is the deliberate exception: the trail stays, with
             * its company_id nulled by the key. See the class docblock.
             *
             * commission_ledger goes with the rest, through the query builder:
             * CommissionLedger::deleting always throws (BR-4), and the class
             * docblock says why a test company is the one place that is
             * allowed.
             */
            $this->deleteEveryCompanyRow($companyId);

            Company::withoutGlobalScopes()->withTrashed()->whereKey($companyId)->forceDelete();

            if ($userIds !== []) {
                // Their own login state goes with them. None of these tables
                // has a cascading key to users.
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();

                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                }

                DB::table('users')->whereIn('id', $userIds)->delete();
            }
        });
    }

    /**
     * Delete from every table with a company_id, in whatever order the
     * foreign keys allow.
     *
     * The tables reference each other with RESTRICT in many places — a
     * referral's product, a banner's product, an earned badge, a redeemed
     * reward — so a fixed order would have to be kept by hand for every
     * future migration, and the one this replaced was already wrong (it
     * deleted products before the banners and lesson modules pointing at
     * them; CompanyRemovalTest caught it). Instead each pass
     * deletes what it can; a table that is still referenced fails inside its
     * own savepoint and waits for the next pass. A pass that deletes nothing
     * while tables remain is a real cycle, and that is thrown, not swallowed —
     * half a wipe must never be committed.
     */
    private function deleteEveryCompanyRow(int $companyId): void
    {
        $pending = array_values(array_filter(
            $this->tablesWithCompanyId(),
            fn (string $t) => ! in_array($t, ['audit_logs', 'users'], true),
        ));

        while ($pending !== []) {
            $stillPending = [];
            $lastError = null;

            foreach ($pending as $table) {
                try {
                    DB::transaction(fn () => DB::table($table)->where('company_id', $companyId)->delete());
                } catch (QueryException $e) {
                    $stillPending[] = $table;
                    $lastError = $e;
                }
            }

            if (count($stillPending) === count($pending)) {
                throw $lastError ?? new \RuntimeException('Could not delete company rows from: '.implode(', ', $stillPending));
            }

            $pending = $stillPending;
        }
    }

    /** @return array<string, int> */
    private function counts(Company $company): array
    {
        $id = $company->id;
        $count = fn (string $table) => Schema::hasTable($table) ? DB::table($table)->where('company_id', $id)->count() : 0;

        return [
            // DB::table on purpose: it sees soft-deleted rows. A removed
            // applicant is still an applicant who once signed up here.
            'agents' => DB::table('users')->where('company_id', $id)->where('role', UserRole::Agent->value)->count(),
            'users' => DB::table('users')->where('company_id', $id)->count(),
            'clients' => $count('clients'),
            'referrals' => $count('referrals'),
            'orders' => $count('orders'),
            'commission' => $count('commission_ledger'),
            'withdrawals' => $count('commission_withdrawal_requests'),
            'products' => $count('products'),
        ];
    }

    /** @return list<string> */
    private function tablesWithCompanyId(): array
    {
        $tables = array_map(fn (array $t): string => (string) ($t['name'] ?? ''), Schema::getTables());

        return array_values(array_filter(
            $tables,
            fn (string $t) => $t !== '' && $t !== 'companies' && Schema::hasColumn($t, 'company_id'),
        ));
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(?int $companyId, User $actor, string $action, Company $company, ?array $old, ?array $new, ?int $auditableId = null): void
    {
        AuditLog::create([
            'company_id' => $companyId,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => Company::class,
            'auditable_id' => $auditableId ?? $company->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()?->ip(),
        ]);
    }
}
