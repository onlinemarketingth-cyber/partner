<?php

namespace Tests\Feature\Registration;

use App\Enums\AgentApprovalStatus;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-017 — pure schema/model foundation for ADR-005. These tests
// check the building blocks TASK-018/019/020/021/022 all depend on:
// the approval-status default, the invite-code validity rule, and the
// social-account uniqueness constraint.
class RegistrationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normally_created_user_defaults_to_approved(): void
    {
        // No agent_approval_status is set anywhere in UserFactory's
        // definition() — this exercises the DB-level default directly,
        // confirming the backfill/default documented in the migration
        // never silently locks out an Admin-created Agent.
        //
        // Bug found + fixed 2026-07-14 (live `php artisan test` run by
        // the human surfaced it): this test was failing not because the
        // DB default was wrong, but because the in-memory $user instance
        // returned by create() never reflects a column's DB-level
        // DEFAULT when that column was omitted from the INSERT —
        // Eloquent only pulls back the auto-incrementing id after
        // insert, nothing else. ->refresh() re-fetches the row so the
        // assertion checks what's actually stored, which is what this
        // test always intended to verify.
        $user = User::factory()->create()->refresh();

        $this->assertSame(AgentApprovalStatus::Approved, $user->agent_approval_status);
    }

    public function test_pending_approval_factory_state_works(): void
    {
        $user = User::factory()->pendingApproval()->create();

        $this->assertSame(AgentApprovalStatus::Pending, $user->agent_approval_status);
    }

    public function test_social_account_unique_constraint_rejects_a_duplicate_provider_pair(): void
    {
        SocialAccount::factory()->create(['provider' => 'google', 'provider_user_id' => 'google-123']);

        $this->expectException(QueryException::class);
        SocialAccount::factory()->create(['provider' => 'google', 'provider_user_id' => 'google-123']);
    }

    public function test_invite_code_is_valid_by_default(): void
    {
        $code = CompanyInviteCode::factory()->create();

        $this->assertTrue($code->isValid());
    }

    public function test_expired_invite_code_is_not_valid(): void
    {
        $code = CompanyInviteCode::factory()->expired()->create();

        $this->assertFalse($code->isValid());
    }

    public function test_revoked_invite_code_is_not_valid_even_if_not_yet_expired(): void
    {
        $code = CompanyInviteCode::factory()->revoked()->create();

        $this->assertFalse($code->isValid());
    }

    public function test_a_company_can_have_two_simultaneously_valid_invite_codes(): void
    {
        $company = Company::factory()->create();
        CompanyInviteCode::factory()->create(['company_id' => $company->id, 'label' => 'Branch A']);
        CompanyInviteCode::factory()->create(['company_id' => $company->id, 'label' => 'Branch B']);

        $this->assertSame(2, $company->inviteCodes()->get()->filter->isValid()->count());
    }

    public function test_invite_code_cannot_be_created_without_an_expiry(): void
    {
        $this->expectException(QueryException::class);

        CompanyInviteCode::factory()->create(['expires_at' => null]);
    }
}
