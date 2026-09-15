<?php

namespace App\Services\Commission;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 2026-09-15 — THE COMPANY'S OWN SEAT IN ITS HIERARCHY.
 *
 * Owner: "หัวหน้าทีมในที่นี้มีได้ 2 ความหมาย คือหัวหน้าทีมที่เป็น user จริงในระบบ
 * กับหัวหน้าทีมที่เป็นตัวบริษัทเองที่ได้ค่าคอมจากการขาย เช่น Thailife".
 *
 * ── WHY A USER ROW AND NOT A NEW PAYEE KIND ──
 *
 * Four designs were weighed. Three of them taught the money code a second
 * kind of recipient: a nullable `agent_id` with a `payee_company_id` beside
 * it, a separate earnings table, or a snapshot column on the seller's row.
 * All three end in the same place — every query that sums, allocates,
 * reverses or lists money has to learn the new shape, and `availableSatang()`
 * getting it wrong means an agent can withdraw the company's margin.
 *
 * This one teaches the money code nothing. The company becomes a `users` row
 * at the TOP of the manager chain, and `CommissionService` pays it by walking
 * the chain it already walks. The cost is moved from the money path (where
 * mistakes are irreversible, BR-4) to the people path (where they are
 * visible): a row that looks like a person has to be kept out of every list
 * of people, and off every control a person gets.
 *
 * ── WHAT THIS SERVICE OWNS, AND WHY IT IS ONE OBJECT ──
 *
 * Turning the house account on is TWO writes that must never happen apart:
 * the company's pointer, and every unmanaged agent's `manager_id`. A pointer
 * with nobody reporting to it is a company that believes it earns and does
 * not; agents reporting to a user the company does not point at are paying
 * a stranger. Both ends live here, in one transaction, in both directions.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ──
 *
 * It never deletes the user. `commission_ledger.agent_id` restricts deletes,
 * and it must: rows the company was paid on are immutable (BR-4) and a
 * deleted payee would orphan them. `disable()` therefore detaches and
 * forgets; the row stays, carrying its history.
 */
class CommissionHouseAccountService
{
    /**
     * A mailbox that cannot receive mail, on a domain that cannot exist.
     *
     * `users.email` is UNIQUE and NOT NULL, so the house account needs one.
     * `.internal` is reserved by RFC 6762 precisely so it can never resolve —
     * which makes this address unusable for password reset, notification or
     * login even if every other guard in this file were removed.
     */
    public static function emailFor(Company $company): string
    {
        return "house.{$company->id}@commission.internal";
    }

    /**
     * Give this company a seat at the top of its own hierarchy.
     *
     * Idempotent: a company that already has one gets the same row back
     * rather than a second seat. Two house accounts would both be paid on
     * every sale, which is the most expensive way this could go wrong.
     */
    public function create(Company $company, ?string $displayName = null): User
    {
        $existing = $company->commissionHouseAccount;

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($company, $displayName) {
            $house = User::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                // COMPANY ADMIN, NOT AGENT, and that is load-bearing:
                // SalesTeamOverviewService, the roster and the upline picker
                // all narrow to `role = agent`, so this row stays out of
                // every list of salespeople for free. Nothing about the
                // payout depends on the role — only on manager_id.
                'role' => UserRole::CompanyAdmin,
                /*
                 * `first_name`, not `name`. `users.name` is DERIVED — the
                 * model's own saving hook rebuilds it from first/last and
                 * the column is not fillable (migration 2026_07_12_090000).
                 * Writing `name` directly is silently dropped, and the row
                 * then fails a NOT NULL constraint that reads like a bug in
                 * this service rather than a rule about that column.
                 *
                 * A company's label is one string, so it goes in the first
                 * field and the second stays empty; the hook trims, and the
                 * derived `name` comes out exactly as typed.
                 */
                'first_name' => $displayName !== null && trim($displayName) !== ''
                    ? trim($displayName)
                    : $company->name,
                'last_name' => '',
                'email' => self::emailFor($company),
                // Random, never shown, never recoverable. Password reset is
                // refused for this account (UserService), so there is no
                // path by which anybody obtains a working credential.
                'password' => Hash::make(Str::random(64)),
                // Stamped so nothing ever queues a verification mail to an
                // address that cannot receive one.
                'email_verified_at' => now(),
                // Top of the tree. assertValidManager() refuses to change it.
                'manager_id' => null,
                // NOT a team leader: that flag grants recruit-link minting,
                // which needs somebody able to log in and press it.
                'is_team_leader' => false,
            ]);

            $company->forceFill(['commission_house_user_id' => $house->id])->save();

            $this->attachUnmanaged($company->refresh());

            return $house;
        });
    }

    /**
     * Point every agent with no upline at the house account.
     *
     * Called when the seat is created, and again whenever somebody joins
     * without one (registration, approval, an admin clearing the field).
     * Without the second call the company quietly stops earning on whoever
     * arrived after setup — a revenue gap with no error and no screen.
     *
     * @return int how many people were attached
     */
    public function attachUnmanaged(Company $company): int
    {
        $houseId = $company->commission_house_user_id;

        if ($houseId === null) {
            return 0;
        }

        /*
         * AGENTS ONLY. A company admin or voucher-staff row with a null
         * manager is not somebody missing an upline — they are not in the
         * selling hierarchy at all, and attaching them would put them in the
         * override walk, where a certified one would start being paid on
         * other people's sales.
         */
        return User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('role', UserRole::Agent->value)
            ->whereNull('manager_id')
            ->whereKeyNot($houseId)
            ->update(['manager_id' => $houseId]);
    }

    /**
     * Change the name the seat is shown under.
     *
     * ── WHY THE SEAT NEEDS ITS OWN RENAME DOOR ──
     *
     * Every guard this feature added points the same way: UserPolicy::update
     * refuses the seat, UserService refuses to reset its password, move it or
     * deactivate it. Those refusals are right — the seat is not a person and
     * must not be edited as one — but together they closed the ONE edit that
     * is legitimate. A company that typed the wrong name when it turned the
     * seat on was stuck with it forever, on every payout row and every screen,
     * with no way back short of deleting the seat and losing the link between
     * its history and its successor.
     *
     * So the door exists, and it is exactly this wide: one field, on the
     * company's own commission settings, behind the same Ability as every
     * other step-4 write. Nothing here can change who the seat reports to,
     * who reports to it, what it is paid, or whether anyone can sign in as it.
     *
     * `first_name`, not `name` — same reason as create(): `users.name` is
     * derived by a saving hook and is not fillable, so writing it is silently
     * dropped.
     */
    public function rename(Company $company, string $displayName): User
    {
        $house = $company->commissionHouseAccount;

        abort_if($house === null, 404, 'บริษัทนี้ยังไม่ได้เปิดบัญชีบริษัทสำหรับรับค่าคอมหัวหน้าทีม');

        $house->forceFill([
            'first_name' => trim($displayName),
            'last_name' => '',
        ])->save();

        return $house->refresh();
    }

    /**
     * Take the seat away: everyone who reported to it reports to nobody, and
     * the company stops pointing at it.
     *
     * The user row survives on purpose — see the class docblock. Reinstating
     * later returns the SAME row, so the company's history stays on one
     * payee instead of being split across two.
     *
     * @return int how many people were detached
     */
    public function disable(Company $company): int
    {
        $houseId = $company->commission_house_user_id;

        if ($houseId === null) {
            return 0;
        }

        return DB::transaction(function () use ($company, $houseId) {
            $detached = User::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('manager_id', $houseId)
                ->update(['manager_id' => null]);
            // Not narrowed to agents, unlike attachUnmanaged(): whoever ended
            // up reporting to the seat must stop, whatever their role.

            $company->forceFill(['commission_house_user_id' => null])->save();

            return $detached;
        });
    }
}
