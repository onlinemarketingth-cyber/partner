<?php

namespace App\Console\Commands;

use App\Enums\AgentApprovalStatus;
use App\Enums\UserRole;
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
            'email_verified_at' => now(),
            'agent_approval_status' => AgentApprovalStatus::Approved,
        ]);

        $this->info("Super Admin created: {$user->email} (id {$user->id}).");

        return self::SUCCESS;
    }
}
