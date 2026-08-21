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
         * uncompromised() is the rule that actually matters here. Length
         * requirements push people towards predictable padding; the
         * HaveIBeenPwned k-anonymity check rejects the passwords that are
         * genuinely being sprayed at this login form right now. Only a
         * 5-character hash prefix leaves this server — never the password,
         * never the hash — and Laravel's verifier fails OPEN if the service
         * is unreachable, so a Hostinger network hiccup cannot lock a new
         * agent out of registering.
         *
         * Relaxed under `php artisan test` alone: the suite's fixtures use
         * the literal string "password" (which uncompromised() would
         * rightly reject) and must not make a network call per assertion.
         * Local development keeps the production rule deliberately — a dev
         * who cannot set a weak password locally will not be surprised by
         * production.
         */
        Password::defaults(function () {
            /*
             * EIGHT, not ten (human decision 2026-08-21).
             *
             * The audit raised the floor to 10 and shipped it without
             * touching the "อย่างน้อย 8 ตัวอักษร" hint rendered under the
             * field. An agent then met a rule of 10 and a hint of 8 in the
             * same box — and the rule's message came out in English on top
             * of that. The human's call is 8, so 8 it is, and the hint,
             * the rule and PasswordRuleMessages now all say the same number.
             *
             * uncompromised() stays and is the part that carries the
             * weight. Length pushes people towards predictable padding; the
             * breach check rejects the passwords actually being sprayed at
             * this login form. Only a 5-character hash prefix leaves the
             * server — never the password — and Laravel's verifier fails
             * OPEN if the service is unreachable, so a network hiccup on
             * Hostinger cannot lock a new agent out of registering.
             */
            return $this->app->runningUnitTests()
                ? Password::min(8)
                : Password::min(8)->uncompromised();
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
