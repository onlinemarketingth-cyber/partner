<?php

namespace App\Providers;

use App\Enums\Ability;
use App\Models\User;
use App\Services\Academy\LessonAccessGate;
use App\Services\Authorization\PermissionResolver;
use App\Services\Platform\MailSettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        /*
         * 2026-09-09 (human: "ทำให้อายุการ Login นานกว่านี้แบบ Facebook หรือ
         * YouTube ทำอย่างไร") — how long "จดจำฉัน" lasts in the ADMIN console.
         *
         * Laravel's default remember-me cookie lives five years. That is a
         * reasonable default for a forum and the wrong one for a console that
         * can see every company's money and every agent's bank details: a
         * laptop left in a taxi stays signed in until 2031.
         *
         * Seven days, and it slides — Laravel reissues the recaller on each
         * use — so an admin who works every week never types a password again,
         * and one who stops is signed out a week later.
         *
         * The box left UNTICKED is governed by SESSION_LIFETIME instead
         * (config/session.php), which is the short, per-sitting number.
         */
        Auth::guard('web')->setRememberDuration(60 * 24 * 7);

        /*
         * ══ NO LISTENERS ARE REGISTERED HERE ANY MORE (2026-09-05) ══
         *
         * TASK-020 registered two by hand, on the understanding that Laravel
         * 12 dropped the default EventServiceProvider and something had to
         * take its place. Discovery took its place: Laravel scans
         * app/Listeners on its own, so each explicit line registered a SECOND
         * copy and both listeners ran twice for every event.
         *
         * `php artisan event:list` showed the duplicates the whole time —
         * each listener printed once as a class and once as `@handle`.
         *
         * Both lines are gone. The two blocks below keep their reasoning
         * because it is still true of the listeners themselves, and because
         * the next person searching this file for "where is Lockout wired up"
         * must not conclude that nothing listens.
         */

        /*
         * ── THE LOUDER OF THE TWO DUPLICATES ──
         *
         *     Event::listen(AgentReadyForApproval::class, NotifyCompanyAdminsOfPendingAgent::class);
         *
         * This one was visible to people, not just to auditors:
         * NotifyCompanyAdminsOfPendingAgent SENDS A NOTIFICATION, so every
         * company admin received two emails for every agent who registered,
         * and had done since the day the listener shipped. Nothing counted
         * them, which is why it survived — a test now does.
         */

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
        /*
         *     Event::listen(Lockout::class, RecordAuthLockout::class);
         *
         * The quieter duplicate: every lockout wrote two identical audit
         * rows. Found by TASK-240's test counting them, not by reading
         * either file. RecordAuthLockout still runs, once — its own docblock
         * carries the reasoning for why it exists at all.
         */

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
        $this->definePublicPaymentRateLimits();

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
     * 2026-09-11 (human, blocked mid-payment by "Too Many Attempts.").
     *
     * ── WHY `throttle:10,1` WAS THE WRONG LIMIT ──
     *
     * On an unauthenticated route Laravel's throttle keys on the IP address.
     * The /pay/{token} routes are unauthenticated by design (ADR-011: the
     * token IS the credential), so every customer behind one address shared
     * one bucket — an office, a hotel, a seminar room, or any Thai mobile
     * carrier doing CGNAT, where thousands of phones leave through a handful
     * of addresses.
     *
     * The failure that produces is the worst one this system has: at an event
     * where twenty people buy at once, the eleventh customer is refused on
     * their FIRST tap, in English, on a page holding their money. Nothing
     * they can do fixes it, and nobody watching can tell it happened.
     *
     * ── WHAT THESE LIMITS PROTECT INSTEAD ──
     *
     * The ORDER. A limit exists here to stop one link being hammered — a bot
     * cycling attempts against a single order, or a retry loop — and the
     * order token is what identifies that. It is also unguessable, so it
     * cannot be used to deny service to anybody else's order.
     *
     * The per-IP ceiling stays as a SECOND, much looser limit, because the
     * first one alone would let somebody with a list of tokens work through
     * them freely. Both apply; whichever is hit first answers.
     *
     * Numbers chosen so an honest customer never meets them: nobody taps pay
     * ten times in a minute on one order, and forty requests a minute from
     * one address is a busy office rather than an attack.
     */
    private function definePublicPaymentRateLimits(): void
    {
        // The order this request is about. Falls back to the IP when the
        // route has no token (it always does — this is belt and braces, so a
        // future route reusing the limiter cannot accidentally key on null
        // and put every caller in one bucket).
        $order = fn (Request $request): string => 'pay-order:'.($request->route('token') ?? $request->ip());
        $address = fn (Request $request): string => 'pay-ip:'.$request->ip();

        // Reading the page. One call per page load; the ceiling only exists
        // to stop a scraper walking tokens.
        RateLimiter::for('pay-read', fn (Request $request) => [
            Limit::perMinute(60)->by($order($request)),
            Limit::perMinute(180)->by($address($request)),
        ]);

        /*
         * Opening a gateway session, and uploading a slip.
         *
         * Ten per order per minute: a customer who switches between card and
         * transfer while deciding is normal and must not be punished for it,
         * and neither request moves money on its own.
         */
        RateLimiter::for('pay-attempt', fn (Request $request) => [
            Limit::perMinute(10)->by($order($request)),
            Limit::perMinute(40)->by($address($request)),
        ]);

        /*
         * A real charge against the company's gateway account.
         *
         * Kept tighter than the rest, unchanged in spirit from the original
         * `throttle:5,1`: a stream of these from one source is card testing,
         * which costs the company its provider-side fraud reputation. A
         * person paying for one order does not need six attempts a minute.
         */
        RateLimiter::for('pay-charge', fn (Request $request) => [
            Limit::perMinute(5)->by($order($request)),
            Limit::perMinute(20)->by($address($request)),
        ]);
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
