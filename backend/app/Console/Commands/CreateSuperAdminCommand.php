<?php

namespace App\Console\Commands;

use App\Enums\AgentApprovalStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * TASK-205 follow-up — bootstrap the first real Super Admin on a fresh
 * production database.
 *
 * `php artisan tinker` doesn't work on this Hostinger plan (Psy Shell
 * calls shell_exec() on startup, which shared hosting disables for
 * security — same family of restriction as exec()/symlink(), see
 * docs/DEPLOYMENT.md). Every other bootstrap path goes through
 * DatabaseSeeder, which is explicitly DEV-ONLY (weak fixed password,
 * never meant to touch production — see that file's own docblock).
 * This command is the safe production alternative: prompts for a real
 * password interactively (never a CLI argument, so it never lands in
 * shell history or `ps`), never hardcodes one.
 *
 * `company_id` is deliberately always null and `role` always
 * SuperAdmin — this command only ever creates the platform-level
 * account (CLAUDE.md Section 2: Super Admin sees across companies).
 * Company Admins / Agents are created afterwards through the Admin UI
 * once this first login exists.
 *
 * ── TWO ADDITIONS, 2026-09-05 (TASK-237) ──
 *
 * 1. IT WRITES AN AUDIT ROW. Gaining the highest privilege in the system
 *    left no trace at all, which made this the one privileged action
 *    nobody could review afterwards. `actor_user_id` is null on purpose —
 *    a CLI run has no logged-in actor, and inventing one would be worse
 *    than saying so; `new_values.source` records that it came from here.
 *
 * 2. IT CAN PROMOTE AN ACCOUNT THAT ALREADY EXISTS. Creating was the only
 *    path, so `unique:users,email` turned the actual recovery case into a
 *    failure: the one Super Admin is locked out, and the fix is to raise
 *    an existing Company Admin rather than invent a new person. Promotion
 *    is a separate confirmation and a separate audit action — never a
 *    silent fallback from a failed create.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'admin:create-super';

    protected $description = 'Interactively create a Super Admin user (production-safe alternative to tinker/seeders).';

    public function handle(): int
    {
        $existing = User::withoutGlobalScopes()->where('role', UserRole::SuperAdmin)->count();
        if ($existing > 0) {
            $this->warn("There are already {$existing} Super Admin account(s).");
            if (! $this->confirm('Create another one anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $email = $this->ask('Email');

        /*
         * THE RECOVERY PATH. Asked before anything else is collected: if
         * this address already has an account, a new one cannot be made
         * and the only useful question is whether to raise that one.
         */
        $existingUser = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($existingUser !== null) {
            return $this->promote($existingUser);
        }

        $firstName = $this->ask('First name');
        $lastName = $this->ask('Last name');
        $password = $this->secret('Password (min 10 characters, input hidden)');
        $passwordConfirm = $this->secret('Confirm password');

        $validator = Validator::make(
            [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => $password,
                'password_confirmation' => $passwordConfirm,
            ],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:10', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // 'name' is deliberately NOT set here — it isn't mass-assignable
        // (see the $fillable docblock on User) and doesn't need to be:
        // User::booted()'s saving() hook derives it from first_name +
        // last_name on every save that isn't run through WithoutModelEvents
        // (DatabaseSeeder is the one place that bypasses it, for its own
        // documented reason). This command runs the normal Eloquent
        // lifecycle, so the hook fires here.
        $user = User::create([
            'email' => $email,
            'password' => $password, // cast as 'hashed' on the model — auto-hashed on save.
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => UserRole::SuperAdmin,
            'company_id' => null,
            'agent_approval_status' => AgentApprovalStatus::Approved,
        ]);

        /*
         * ── A BUG THIS COMMAND SHIPPED WITH, FOUND 2026-09-05 BY ITS FIRST
         *    TEST ──
         *
         * `email_verified_at` was passed to create() above, and create() is
         * mass assignment: the column is NOT in User::$fillable, so Eloquent
         * dropped it in silence. The command then reported "Super Admin
         * created" and the account was blocked at LoginGateService's email
         * gate — created successfully, unable to log in, on the ONE path
         * that can produce a Super Admin at all.
         *
         * forceFill, because the guard is doing its job everywhere else: an
         * HTTP request must never be able to mark an email verified. Here
         * the operator is standing at the server's own command line.
         */
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->audit('user.super_admin_created', $user, null, [
            'role' => UserRole::SuperAdmin->value,
            'source' => 'artisan admin:create-super',
        ]);

        $this->info("Super Admin created: {$user->email} (id {$user->id}).");

        return self::SUCCESS;
    }

    /**
     * Raise an account that already exists.
     *
     * Deliberately NOT a password reset as well: this command's job is the
     * ROLE. Somebody who has forgotten their password should say so and use
     * the reset path, and quietly changing a password while changing a role
     * would hide one action inside another in the audit trail.
     */
    private function promote(User $user): int
    {
        $currentRole = $user->role?->value ?? 'unknown';

        if ($user->role === UserRole::SuperAdmin) {
            $this->info("{$user->email} is already a Super Admin (id {$user->id}). Nothing to do.");

            return self::SUCCESS;
        }

        $this->warn("{$user->email} already exists as: {$currentRole} (id {$user->id}).");

        if (! $this->confirm('Promote this account to Super Admin?', false)) {
            return self::SUCCESS;
        }

        /*
         * company_id is cleared with the role, not left behind. A Super
         * Admin scoped to one company is a contradiction the rest of the
         * system does not expect: TenantScope reads the role, and every
         * "which company am I working in" answer would then disagree with
         * the row underneath it.
         */
        $previousCompanyId = $user->company_id;

        $user->forceFill([
            'role' => UserRole::SuperAdmin,
            'company_id' => null,
            // A locked-out admin is the reason this path exists — leaving
            // either of these unset would raise the role and still refuse
            // the login (see LoginGateService's five gates).
            'email_verified_at' => $user->email_verified_at ?? now(),
            'agent_approval_status' => AgentApprovalStatus::Approved,
        ])->save();

        $this->audit(
            'user.super_admin_granted',
            $user,
            ['role' => $currentRole, 'company_id' => $previousCompanyId],
            ['role' => UserRole::SuperAdmin->value, 'company_id' => null, 'source' => 'artisan admin:create-super'],
        );

        $this->info("Promoted to Super Admin: {$user->email} (id {$user->id}).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function audit(string $action, User $user, ?array $oldValues, array $newValues): void
    {
        AuditLog::create([
            // Null: a platform-level account belongs to no company, and the
            // promotion case has just cleared the one it used to have.
            'company_id' => null,
            // No logged-in actor exists on the command line. Saying null is
            // honest; naming the target as its own actor would not be.
            'actor_user_id' => null,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => null,
        ]);
    }
}
