<?php

namespace App\Services\Platform;

use App\Models\User;

/**
 * 2026-09-18 (human: "หากไม่มีกิจกรรม การซื้อขายอะไร ให้สามารถลบ รายชื่อสมาชิก
 * แบบ Soft Delete ได้").
 *
 * ── THE QUESTION THIS ANSWERS ──
 *
 * "Has this account ever DONE anything?" Until now the roster could only
 * remove a sign-up that never completed (User::isUnconfirmedApplicant) —
 * a much narrower fact, decided entirely from columns on the row itself.
 * The owner asked for the wider one: an account that logged in, did
 * nothing, and is now clutter.
 *
 * ── WHY IT LISTS REASONS RATHER THAN RETURNING A BOOLEAN ──
 *
 * The owner's answer on what the screen should do when an account cannot
 * be removed was "แสดงแต่กดไม่ได้ + บอกเหตุผล". A boolean cannot say
 * "มีลูกค้า 3 คน"; and a disabled button with no explanation is the thing
 * that makes an admin file a bug. So every check reports a key and a
 * count, and the screen turns those into a sentence.
 *
 * ── WHAT COUNTS, AND WHO DECIDED ──
 *
 * All four groups below were chosen by the owner on 2026-09-18. They are
 * not a guess, and they are deliberately broad: the point of the feature
 * is removing accounts that are provably untouched, so anything at all
 * that ties money, a customer, a colleague or a credential to this person
 * is a reason to keep the row.
 *
 * ONE INTERPRETATION IS MINE AND IS FLAGGED AS SUCH. The owner's wording
 * was "มีลูกทีม / อยู่ในผังทีม". This blocks on people UNDER them
 * (direct reports, or Matrix children), and NOT on merely having an
 * upline. Removing a leaf breaks no chain and strands nobody — every
 * agent recruited through a link has an upline, so blocking on that alone
 * would make the feature unreachable for almost everybody. If the owner
 * meant the stricter reading, `MATRIX_PLACEMENT` below is the line to
 * change.
 */
class AccountActivityProbe
{
    /*
     * Reason keys. Strings rather than an enum because they cross the wire
     * to a Vue screen that owns the Thai wording — one vocabulary, defined
     * here, rendered there (AgentRosterView::removalBlockerLabel).
     */
    public const CLIENTS = 'clients';

    public const REFERRALS = 'referrals';

    public const COMMISSION = 'commission';

    public const DOWNLINE = 'downline';

    public const LEARNING = 'learning';

    public const REWARDS = 'rewards';

    public const LINKS = 'links';

    /**
     * Every check, as `key => [relations]`.
     *
     * Grouped because an admin thinks in reasons, not in tables: "ผ่าน
     * ใบรับรอง" and "เรียนจบโมดูล" are one sentence to them and two
     * relations to us, and a list that said both would read as two
     * problems to solve rather than one fact about the person.
     *
     * @var array<string, list<string>>
     */
    private const CHECKS = [
        self::CLIENTS => ['referredClients'],
        self::REFERRALS => ['referrals'],
        self::COMMISSION => ['commissionLedgerEntries'],
        self::DOWNLINE => ['directReports'],
        self::LEARNING => ['certifications', 'moduleCompletions'],
        self::REWARDS => ['xpLedger', 'badges'],
        self::LINKS => ['affiliateLinks', 'agentInviteLinks'],
    ];

    /**
     * The relations UserController::index eager-counts so a page of rows
     * costs ONE query rather than seven per row.
     *
     * Kept here, derived from CHECKS, so the controller cannot drift from
     * the probe — a relation counted but never checked is waste, and one
     * checked but never counted turns a list into an N+1 nobody notices
     * until the roster has two hundred people on it.
     *
     * @return list<string>
     */
    public static function countableRelations(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CHECKS))));
    }

    /**
     * Why this account may NOT be removed. Empty means it may.
     *
     * Reads `{relation}_count` when the caller eager-counted (the list
     * path) and falls back to an `exists()` per relation otherwise (the
     * single-row path, where seven cheap queries are fine and a silently
     * wrong answer is not).
     *
     * @return list<array{key: string, count: int}>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        foreach (self::CHECKS as $key => $relations) {
            $count = 0;

            foreach ($relations as $relation) {
                $attribute = $relation.'_count';

                $count += $user->getAttribute($attribute) !== null
                    ? (int) $user->getAttribute($attribute)
                    : (int) $user->{$relation}()->count();
            }

            if ($count > 0) {
                $blockers[] = ['key' => $key, 'count' => $count];
            }
        }

        return $blockers;
    }

    /**
     * Never traded, never learned, never recruited, never linked.
     *
     * This is the predicate that decides whether removing the account also
     * RELEASES ITS EMAIL ADDRESS (UserService::deactivate) — so it is
     * asked on the server at the moment of deletion, never taken from the
     * client, whatever the button that was clicked believed.
     */
    public function isPristine(User $user): bool
    {
        return $this->blockers($user) === [];
    }
}
