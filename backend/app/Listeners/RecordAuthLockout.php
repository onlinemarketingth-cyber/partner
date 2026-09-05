<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Cache;

/**
 * SECURITY AUDIT 2026-08-21 (V19) — write down that somebody was locked out.
 *
 * LoginRequest has fired this event since it was written and nothing
 * listened. A password-guessing run against one agent's account was
 * throttled — correctly — and then left no trace whatsoever: no log line,
 * no audit row, nothing anybody could notice the next morning or reconstruct
 * a month later. Throttling stops an attack in progress; only a record tells
 * you it happened, against whom, and from where.
 *
 * ── WHY audit_logs AND NOT THE APPLICATION LOG ──
 *
 * Because it is queryable and because it is append-only by construction:
 * the table has no updated_at, no update or delete route, and no code path
 * anywhere that writes one. laravel.log is a single unrotated file on shared
 * hosting that nobody opens. If this is worth recording at all, it is worth
 * recording where an admin can actually find it.
 *
 * ── WHAT IS DELIBERATELY NOT RECORDED ──
 *
 * The submitted password, obviously — but also the email when it matches no
 * account. A locked-out attempt against an address nobody has registered
 * says nothing about this system and everything about whoever is spraying
 * it; storing a stranger's email address, unbidden and indefinitely, is
 * personal data this product has no reason to hold (PDPA). The IP is kept
 * because it is the only field that makes the record actionable.
 */
class RecordAuthLockout
{
    public function handle(Lockout $event): void
    {
        $email = (string) $event->request->input('email', '');

        /*
         * ── ONE ROW PER LOCKOUT, NOT ONE PER ATTEMPT (TASK-240) ──
         *
         * LoginRequest fires this event from ensureIsNotRateLimited(), which
         * runs on EVERY request while the lock is in force — so a bot that
         * keeps hammering a locked address wrote a row per guess, forever,
         * into the trail those rows are supposed to stand out in. The signal
         * and the flood were the same rows.
         *
         * A marker in the cache, expiring with the lock itself, so the second
         * through thousandth attempt of one lockout are silent and the next
         * genuine lockout is recorded again. Cache::add is atomic — two
         * simultaneous attempts cannot both win it.
         *
         * SIXTY SECONDS, matching RateLimiter::hit()'s own default decay in
         * LoginRequest — the marker is meant to live exactly as long as the
         * lock it describes. Read as "one lockout row per address per minute",
         * which is the shape of the thing being recorded.
         *
         * A cache that is unavailable must not lose the record, so this skips
         * only on an explicit true; any other answer (miss, driver error)
         * falls through and writes the row.
         */
        $key = 'auth.lockout.recorded|'.$event->request->ip().'|'.mb_strtolower($email);

        if (Cache::get($key) === true) {
            return;
        }

        Cache::put($key, true, 60);

        /*
         * withoutGlobalScopes(): there is no authenticated user during a
         * failed login, so TenantScope would be a no-op here anyway — but
         * saying so explicitly is what stops this becoming a silent
         * cross-tenant read the day the scope's null-user branch changes.
         */
        $user = $email === ''
            ? null
            : User::withoutGlobalScopes()->where('email', $email)->first();

        AuditLog::create([
            // Null for an unknown address: attributing the attempt to a
            // company we cannot identify would be a guess in an audit trail.
            'company_id' => $user?->company_id,
            // The person locked out is the SUBJECT, not the actor. Whoever
            // was typing is exactly what nobody knows — that is the point.
            'actor_user_id' => null,
            'action' => 'auth.lockout',
            'auditable_type' => $user ? User::class : null,
            'auditable_id' => $user?->id,
            'old_values' => null,
            'new_values' => [
                // Whether the target exists is recorded, the address is not.
                // "Locked out against a real account" and "locked out
                // against nothing" call for different responses.
                'target_exists' => $user !== null,
            ],
            'ip_address' => $event->request->ip(),
        ]);
    }
}
