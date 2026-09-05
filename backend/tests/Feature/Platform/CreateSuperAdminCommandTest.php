<?php

namespace Tests\Feature\Platform;

use App\Enums\AgentApprovalStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan admin:create-super` — the only way a Super Admin can exist.
 *
 * ── WHY THIS COMMAND CARRIES SO MUCH WEIGHT ──
 *
 * No HTTP endpoint creates a Super Admin, at any actor level, deliberately.
 * `php artisan tinker` does not run on this Hostinger plan at all (Psy Shell
 * calls shell_exec() on startup, which shared hosting disables). The seeder
 * is dev-only by its own docblock. So this command is the entire path, and
 * until 2026-09-05 it had no test at all.
 *
 * TASK-237 added two things to it, and both are here:
 *   - an audit row, because gaining the highest privilege in a system that
 *     moves money used to leave no trace whatsoever
 *   - promotion of an EXISTING account, because `unique:users,email` turned
 *     the real recovery case — the one Super Admin is locked out, raise a
 *     Company Admin instead — into a flat failure
 */
class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_super_admin_who_can_actually_log_in(): void
    {
        /*
         * "Can log in" is the point, not "row exists". LoginGateService has
         * five gates, and a Super Admin created without email_verified_at or
         * an approved status passes none of them — the account would look
         * right in the database and refuse every login.
         */
        $this->artisan('admin:create-super')
            ->expectsQuestion('Email', 'boss@example.com')
            ->expectsQuestion('First name', 'สมชาย')
            ->expectsQuestion('Last name', 'ผู้ดูแล')
            ->expectsQuestion('Password (min 10 characters, input hidden)', 'correct horse 8')
            ->expectsQuestion('Confirm password', 'correct horse 8')
            ->assertSuccessful();

        $user = User::withoutGlobalScopes()->where('email', 'boss@example.com')->firstOrFail();

        $this->assertSame(UserRole::SuperAdmin, $user->role);
        $this->assertNull($user->company_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(AgentApprovalStatus::Approved, $user->agent_approval_status);

        /*
         * The Origin header and the pinned stateful list are copied from
         * LoginGateTest: Sanctum's EnsureFrontendRequestsAreStateful only
         * starts a session for a matching origin, and without a session
         * Auth::attempt() cannot run at all. This asserts the account passes
         * the real login path, gates and all — not just that a row exists.
         */
        config(['sanctum.stateful' => ['agent.localhost']]);

        $this->withHeader('Origin', 'http://agent.localhost')
            ->postJson('/api/v1/login', [
                'email' => 'boss@example.com',
                'password' => 'correct horse 8',
            ])->assertOk();
    }

    public function test_creating_one_is_written_to_the_audit_trail(): void
    {
        $this->artisan('admin:create-super')
            ->expectsQuestion('Email', 'boss@example.com')
            ->expectsQuestion('First name', 'สมชาย')
            ->expectsQuestion('Last name', 'ผู้ดูแล')
            ->expectsQuestion('Password (min 10 characters, input hidden)', 'correct horse 8')
            ->expectsQuestion('Confirm password', 'correct horse 8')
            ->assertSuccessful();

        $row = AuditLog::where('action', 'user.super_admin_created')->firstOrFail();

        // Null actor is the honest answer for a command line, not an
        // oversight — see the command's own comment.
        $this->assertNull($row->actor_user_id);
        $this->assertSame('artisan admin:create-super', $row->new_values['source']);
    }

    public function test_the_password_never_reaches_the_audit_trail(): void
    {
        // The one thing that must never be in a log, in any column.
        $this->artisan('admin:create-super')
            ->expectsQuestion('Email', 'boss@example.com')
            ->expectsQuestion('First name', 'สมชาย')
            ->expectsQuestion('Last name', 'ผู้ดูแล')
            ->expectsQuestion('Password (min 10 characters, input hidden)', 'correct horse 8')
            ->expectsQuestion('Confirm password', 'correct horse 8')
            ->assertSuccessful();

        $this->assertStringNotContainsString(
            'correct horse 8',
            AuditLog::all()->toJson(),
        );
    }

    public function test_an_existing_account_is_promoted_rather_than_refused(): void
    {
        /*
         * THE RECOVERY CASE. Before this, `unique:users,email` made this a
         * validation failure — at the exact moment somebody is locked out
         * of the only Super Admin account and the fix is to raise the
         * Company Admin who is standing right there.
         */
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create([
            'company_id' => $company->id,
            'email' => 'admin@example.com',
        ]);

        $this->artisan('admin:create-super')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsConfirmation('Promote this account to Super Admin?', 'yes')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertSame(UserRole::SuperAdmin, $admin->role);
        // A Super Admin pinned to one company contradicts TenantScope and
        // every "which company am I working in" answer built on it.
        $this->assertNull($admin->company_id);
    }

    public function test_a_promotion_records_what_the_account_used_to_be(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create([
            'company_id' => $company->id,
            'email' => 'admin@example.com',
        ]);

        $this->artisan('admin:create-super')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsConfirmation('Promote this account to Super Admin?', 'yes')
            ->assertSuccessful();

        $row = AuditLog::where('action', 'user.super_admin_granted')->firstOrFail();

        // Without the old values, the trail says somebody became a Super
        // Admin but not what they were before — which is the only part a
        // reviewer cannot reconstruct afterwards.
        $this->assertSame('company_admin', $row->old_values['role']);
        $this->assertSame($company->id, $row->old_values['company_id']);
        $this->assertSame($admin->id, $row->auditable_id);
    }

    public function test_declining_the_promotion_changes_nothing(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create([
            'company_id' => $company->id,
            'email' => 'admin@example.com',
        ]);

        $this->artisan('admin:create-super')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsConfirmation('Promote this account to Super Admin?', 'no')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertSame(UserRole::CompanyAdmin, $admin->role);
        $this->assertSame($company->id, $admin->company_id);
        $this->assertSame(0, AuditLog::where('action', 'user.super_admin_granted')->count());
    }

    public function test_promoting_someone_who_is_already_a_super_admin_is_a_no_op(): void
    {
        $existing = User::factory()->superAdmin()->create(['email' => 'boss@example.com']);

        $this->artisan('admin:create-super')
            ->expectsConfirmation('Create another one anyway?', 'yes')
            ->expectsQuestion('Email', 'boss@example.com')
            ->assertSuccessful();

        // No second confirmation, no audit row, nothing written twice.
        $this->assertSame(0, AuditLog::where('action', 'user.super_admin_granted')->count());
        $this->assertSame(UserRole::SuperAdmin, $existing->refresh()->role);
    }
}
