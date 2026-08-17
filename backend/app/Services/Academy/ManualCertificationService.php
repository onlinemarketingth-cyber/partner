<?php

namespace App\Services\Academy;

use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Support\Facades\DB;

/**
 * BR-1 admin override (human-requested 2026-07-30) — lets a Company Admin
 * (own company) or Super Admin mark an agent as having passed a cert tier
 * WITHOUT a real exam attempt (e.g. onboarding an experienced hire, or
 * unblocking someone while the exam content is still being authored).
 *
 * Deliberately conservative vs. ExamAttemptService::attempt(): no XP is
 * awarded here. BR-5's two XP sources are "completing modules / passing
 * EXAMS" and "closing sales" — a manual override is neither, and awarding
 * XP for it would turn the override into a farmable back door (an admin
 * could otherwise repeatedly "un-grant" via DB and re-grant for free XP).
 * // TODO: CONFIRM (business rule) — flagged to the human; revisit if
 * manual grants should also award XP after all.
 *
 * The resulting row is otherwise identical in shape to a real pass
 * (`exam_attempt_id` stays null, which is how a manual grant can already
 * be told apart from a real one if that's ever needed later).
 */
class ManualCertificationService
{
    public function grant(User $target, CertTier $tier, User $actor): UserCertification
    {
        return DB::transaction(function () use ($target, $tier, $actor) {
            // firstOrCreate — idempotent like ProductShareLinkService::create()
            // (TASK-056): re-clicking "grant" on an already-certified agent is
            // always safe and never duplicates the row (unique(user_id,
            // cert_tier_id) would otherwise throw) or the audit trail below.
            $certification = UserCertification::withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $target->id, 'cert_tier_id' => $tier->id],
                ['company_id' => $target->company_id, 'passed_at' => now()],
            );

            // Section 6 Audit Log rule — this affects certification/
            // permissions (unlocks BR-1 selling rights), so it must be
            // logged with who/what/when, same as UserService's bank-account
            // and move-to-company writes.
            if ($certification->wasRecentlyCreated) {
                AuditLog::create([
                    'company_id' => $target->company_id,
                    'actor_user_id' => $actor->id,
                    'action' => 'user_certification.manual_grant',
                    'auditable_type' => User::class,
                    'auditable_id' => $target->id,
                    'old_values' => null,
                    'new_values' => [
                        'cert_tier_id' => $tier->id,
                        'cert_tier_key' => $tier->key,
                        'passed_at' => $certification->passed_at?->toIso8601String(),
                    ],
                    'ip_address' => request()?->ip(),
                ]);
            }

            return $certification;
        });
    }
}
