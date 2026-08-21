<?php

namespace App\Providers;

use App\Enums\Ability;
use App\Events\AgentReadyForApproval;
use App\Listeners\NotifyCompanyAdminsOfPendingAgent;
use App\Listeners\RecordAuthLockout;
use App\Models\User;
use App\Services\Academy\LessonAccessGate;
use App\Services\Authorization\PermissionResolver;
use App\Services\Platform\MailSettingsService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * TASK-151 / ADR-031 §2.2 — one LessonAccessGate PER REQUEST.
         *
         * ModuleLessonResource asks it a question for every lesson it
         * renders, and GET /modules returns every lesson of every Section.
         * The gate memoises the sibling chain and the learner's completions
         * per Section, which only helps if every one of those resources gets
         * the SAME instance — an unbound class is newed up on each `app()`
         * call, so the memo would never hit and a 30-lesson Section with
         * `enforce_sequential` on would cost 60 queries instead of 2.
         *
         * `scoped()`, not `singleton()`: the memo is keyed by user and by
         * Section but caches COMPLETION state, which changes within the life
         * of a queue worker. Scoped is flushed per request/job, so a stale
         * chain cannot outlive the request that built it.
         */
        $this->app->scoped(LessonAccessGate::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TASK-020 (ADR-005) — Laravel 12 removed the default
        // EventServiceProvider, so listeners are registered here via the
        // Event facade instead of a $listen[] array. Same spot TASK-019
        // will register Socialite's SocialiteWasCalled -> Line provider
        // extension once that task starts.
        Event::listen(AgentReadyForApproval::class, NotifyCompanyAdminsOfPendingAgent::class);

        /*
         * SECURITY AUDIT 2026-08-21 (V19) — somebody is now listening.
         *
         * LoginRequest has fired Illuminate\Auth\Events\Lockout since the
         * day it was written, and nothing anywhere in the application
         * listened for it. A password-guessing run against a specific
         * agent's account was throttled, correctly, and then left no trace
         * of having happened: no log line, no audit row, nothing anyone
         * could notice or investigate afterwards. Throttling stops the
         * attack in progress; it does not tell you it occurred.
         */
        Event::listen(Lockout::class, RecordAuthLockout::class);

        /*
         * SECURITY AUDIT 2026-08-21 (V18) — ONE password policy, in one place.
         *
         * There were four password rules across four Form Requests, three
         * of them a bare `min:8` and the fourth Password::defaults() with
         * no defaults ever registered — which is Laravel's built-in
         * min(8), no complexity, no breach check. So every path agreed by
         * accident rather than by decision, and moving the floor meant
         * finding all four.
         *
         * Relaxed under `php artisan test` alone, so the suite's fixtures can
         * keep using the literal string "password". Local development keeps
         * the production rule deliberately — a dev who cannot set a weak
         * password locally will not be surprised by production.
         */
        Password::defaults(function () {
            /*
             * EIGHT CHARACTERS, UPPER + LOWER + A NUMBER (human decision
             * 2026-08-21, revised the same day).
             *
             * ── WHAT THIS REPLACED, AND WHY IT WENT ──
             *
             * The audit had shipped min(8)->uncompromised(): a
             * HaveIBeenPwned k-anonymity check that rejects passwords known
             * to be in public breach data. On the security merits that is
             * the stronger rule — it refuses exactly the passwords being
             * sprayed at this login form, which a character-class rule does
             * not (P@ssw0rd1 satisfies every class below and is in every
             * cracking dictionary on earth).
             *
             * It was removed at the human's explicit instruction after a
             * registration was refused with
             *
             *   "รหัสผ่านนี้เคยปรากฏในเหตุข้อมูลรั่วไหลของเว็บอื่น..."
             *
             * That is the correct verdict on the password that was typed —
             * and it is also a sentence that reads, to an insurance agent
             * signing up on a phone, as the site accusing them of something.
             * There is no way to tell them WHICH rule to satisfy, because
             * "not in a breach list" is not a rule anybody can plan around.
             * Character classes are worse security and better instructions,
             * and the person who owns this product chose instructions.
             *
             * Recorded plainly so nobody later reads the weaker rule as an
             * oversight: it is a deliberate trade, made by the owner, with
             * the cost stated.
             *
             * ── WHY NO symbols() ──
             *
             * Every class added is another way to be refused with no idea
             * why, and a symbol is the one an agent cannot type without
             * hunting through a phone keyboard. Upper + lower + digit is
             * what mainstream sites ask for, and it is what the hint under
             * the field says.
             *
             * ── THE NUMBER 8 APPEARS IN THREE PLACES ──
             *
             * Here, in PasswordRuleMessages, and in the hint rendered under
             * the field in both apps. They have disagreed before: the audit
             * raised this to 10 without touching the hint, so an agent met a
             * rule of 10 under a hint of 8, in English. Change one, change
             * all three.
             */
            return $this->app->runningUnitTests()
                ? Password::min(8)
                : Password::min(8)->letters()->mixedCase()->numbers();
        });

        $this->defineAbilityGates();

        // TASK-190 §3.5 — the "simplest correct integration point" the spec
        // asks for: once per request, before any Mailable/Notification in
        // this request sends, override Laravel's runtime mail config from
        // the DB row IF is_enabled. Cheap (single cached row read, see
        // MailSettingsService/PlatformMailSettingService's shared cache
        // key) and correct for both callers in this codebase today
        // (OrderController::confirm()'s synchronous send and any future
        // ShouldQueue mail — a queue worker boots this same provider chain
        // per job, so the override still applies there too).
        app(MailSettingsService::class)->applyRuntimeConfig();
    }

    /**
     * TASK-185 §3 / ADR-032 §2.2 — wire every App\Enums\Ability case to
     * Laravel's Gate so call sites can eventually ask `$user->can(Ability::X)`.
     *
     * WIRING ONLY. No call site uses these gates yet: TASK-185 converts
     * nothing, and the 17 `abort_unless` sites, the 12 Form Requests and the
     * 41 Policies are all untouched. Defining new, dotted ability NAMES cannot
     * change any existing outcome — Policy dispatch is by model class, and no
     * existing gate or policy method is called `commission.agent_summary.view`.
     *
     * NOTE THE ABSENCE OF `Gate::before`. Adding one that returns true for a
     * Super Admin is the single change most likely to be proposed here and is
     * exactly what ADR-032/TASK-185 §3 forbid — it would silently grant the
     * three abilities Super Admin is excluded from. PermissionResolver
     * enumerates Super Admin instead; AbilityCatalogueTest asserts no
     * `before` callback exists.
     */
    private function defineAbilityGates(): void
    {
        foreach (Ability::cases() as $ability) {
            Gate::define(
                $ability->value,
                fn (?User $user) => app(PermissionResolver::class)->may($user, $ability),
            );
        }
    }
}
