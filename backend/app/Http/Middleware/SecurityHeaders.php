<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY AUDIT 2026-08-21 (V10) — the app set no security headers at all.
 *
 * Not one: no X-Frame-Options, no nosniff, no Referrer-Policy, no HSTS,
 * anywhere in three .htaccess files and every middleware. The audit found
 * it by grepping for all six across the whole repo and getting nothing.
 *
 * ── WHY A MIDDLEWARE AND NOT ANOTHER .htaccess EDIT ──
 *
 * Because .htaccess has already taken production down once here (TASK-231:
 * the file went out mode 600, LiteSpeed could not read it, and every deep
 * link 404'd for an afternoon). A middleware is deployed by the same git
 * reset as the rest of the app, is covered by the test suite, and behaves
 * identically whatever the web server does. The frontends still need their
 * own .htaccess headers — nothing else serves those static files — but the
 * API's headers do not have to share that risk, so they do not.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ──
 *
 * A content Content-Security-Policy. A CSP that is wrong does not warn,
 * it white-screens the application, and the only honest way to tune one is
 * to load every screen in a real browser and read the console. That has not
 * been done, so it is not shipped. `frame-ancestors` is the exception and
 * is present: it is the clickjacking control the audit actually found, and
 * it cannot break a page that loads resources correctly.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never guess a MIME type. This one matters more here than on a
        // typical API: several endpoints stream files that a user
        // uploaded, and sniffing is how an uploaded file gets treated as
        // something more interesting than what it was stored as.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // DENY, not SAMEORIGIN: nothing in this system frames the API.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        // Send the origin to other sites, never the path. Paths here carry
        // order ids, share tokens and payment tokens — a full Referer on an
        // outbound click would hand those to whoever was linked to.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        /*
         * HSTS only over HTTPS, and only in a real environment.
         *
         * Sent over plain HTTP the header is meaningless (browsers ignore
         * it) — but sent from a local dev server it is worse than
         * meaningless: the browser pins "always HTTPS" for *.localhost and
         * every developer on the team suddenly cannot reach their own
         * machine over http, with no obvious cause and no easy undo.
         *
         * No `preload`, and no `includeSubDomains` beyond what is already
         * true: both are effectively irreversible for the parent domain,
         * and that is the owner's decision to make deliberately, not a
         * side effect of a security patch.
         */
        if ($request->secure() && ! app()->environment('local', 'testing')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
