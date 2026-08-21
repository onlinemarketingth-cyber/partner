<?php

use App\Http\Middleware\EnsureCompanyIsOperational;
use App\Http\Middleware\ResolveChunkedUpload;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Fix (2026-07-14, surfaced by the human running `php artisan test`):
// `Throwable` is a root-namespace (global) class — PHP always resolves
// it unqualified from anywhere, so `use Throwable;` is a no-op and PHP
// emits "The use statement with non-compound name 'Throwable' has no
// effect" every single time this file loads (i.e. on every request AND
// every test bootstrap — this is exactly why it showed up as a warning
// attached to nearly every test in the suite). Harmless, but noisy.
// Removed the import; the bare `Throwable` type-hint on line ~58 below
// still resolves correctly without it.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA auth (cookie-based) per CLAUDE.md Section 3.
        $middleware->statefulApi();

        // Bug fix (2 previous attempts at this were wrong — see git
        // history / SETUP.md for the full postmortem). Root cause,
        // found by reading vendor/laravel/framework source directly:
        // Illuminate\Foundation\Configuration\ApplicationBuilder::withMiddleware()
        // ALWAYS defaults to redirectGuestsTo(fn () => route('login'))
        // BEFORE this callback runs, unless overridden here. That
        // default wires Authenticate::redirectUsing(),
        // AuthenticateSession::redirectUsing(), AND
        // AuthenticationException::redirectUsing() to call route('login')
        // — a route this app has never had (no web login page, only the
        // SPA). The crash happens INSIDE
        // Illuminate\Auth\Middleware\Authenticate::unauthenticated(),
        // which eagerly calls that callback while CONSTRUCTING the
        // AuthenticationException for any non-JSON-expecting request
        // (e.g. a plain browser navigation, Accept: text/html) — this
        // happens before the exception is even thrown, so it's entirely
        // outside the exception Handler's reach; a shouldRenderJsonWhen()
        // override there (tried first) can never prevent it, because by
        // the time the Handler runs, route('login') has already blown up.
        // Overriding with a callback that returns null instead makes
        // "no redirect target" the safe, permanent answer everywhere.
        $middleware->redirectGuestsTo(fn () => null);

        // TASK-094 — turns a completed chunked-upload token back into a
        // normal uploaded file BEFORE the route's Form Request runs, so
        // every existing mime/size rule applies to a chunked upload
        // unchanged. Applied per-route (the four media create endpoints),
        // never globally: it must not run on routes that legitimately
        // carry an unrelated `upload_token`-shaped input.
        // TASK-183 §3.3 — "may this user's tenant operate?", re-asked on every
        // authenticated request so a session/token minted before the company
        // was deactivated stops working immediately rather than at the next
        // login (which for an active user could be never). Applied to the
        // whole auth:sanctum group in routes/api.php — see that registration
        // for the ONE deliberate exclusion (logout) and why.
        $middleware->alias([
            'resolve.chunked-upload' => ResolveChunkedUpload::class,
            'company.operational' => EnsureCompanyIsOperational::class,
        ]);

        /*
         * SECURITY AUDIT 2026-08-21 (V10) — response security headers.
         *
         * append(), so it runs last on the way in and therefore wraps
         * everything on the way out: the headers have to be on error
         * responses and on streamed file downloads too, not only on the
         * happy path of a request that reaches a controller.
         *
         * Global rather than attached to a group, because "which responses
         * deserve X-Content-Type-Options" has no useful answer other than
         * "all of them", and a list is a list somebody forgets to add to.
         */
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Second half of the same fix — CLAUDE.md Section 3: "strictly a
        // RESTful API ... Blade forbidden". Even with the redirectTo
        // override above (so the exception's own redirectTo is safely
        // null), Illuminate\Foundation\Exceptions\Handler::unauthenticated()
        // still has its OWN fallback — redirect()->guest($exception->redirectTo($request) ?? route('login'))
        // — which would call route('login') itself and crash again the
        // moment shouldReturnJson() is false. shouldRenderJsonWhen()
        // controls exactly that decision: every /api/* request (i.e.
        // every route in this app) is now unconditionally treated as
        // JSON-expecting, regardless of Accept header, so this redirect
        // branch is never reached in the first place. See
        // tests/Feature/Auth/UnauthenticatedRequestTest.php — specifically
        // uses a plain (non-getJson()) request to reproduce the exact
        // Accept: text/html path that caused the original crash.
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
