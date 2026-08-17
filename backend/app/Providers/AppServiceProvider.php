<?php

namespace App\Providers;

use App\Enums\Ability;
use App\Events\AgentReadyForApproval;
use App\Listeners\NotifyCompanyAdminsOfPendingAgent;
use App\Models\User;
use App\Services\Academy\LessonAccessGate;
use App\Services\Authorization\PermissionResolver;
use App\Services\Platform\MailSettingsService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
