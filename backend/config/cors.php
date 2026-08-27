<?php

// Published app-level override of vendor/laravel/framework/config/cors.php.
// Required for the Sanctum SPA cookie flow: the Vite dev server(s) and
// the Laravel API (localhost:8010) are different origins/ports, so
// each frontend's `credentials: 'include'` fetches need
// `supports_credentials: true` + an explicit origin allowlist —
// browsers reject credentialed requests against a wildcard '*' origin.
// Two frontends share this backend now (ADR-003): Agent Portal
// (:5178) and the Admin app (:5179). Moved off 5173/5273 (human's
// choice: 5178/5179) because the human's machine already runs a
// different, unrelated project on 5173.
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    // Keep in sync with SANCTUM_STATEFUL_DOMAINS in .env. Add production
    // frontend origin(s) here once deployed — never widen this to '*'
    // while supports_credentials is true.
    //
    // Bug fix (2026-08-02) — moved off bare "localhost"/"127.0.0.1" onto
    // agent.localhost / admin.localhost (see SESSION_DOMAIN comment in
    // .env for why: sharing one hostname made the two apps' login
    // sessions collide in the same browser).
    // SECURITY AUDIT 2026-08-21 (V14) — the two hardcoded dev origins used
    // to sit in this list unconditionally, so production accepted
    // credentialed requests from http://agent.localhost:5178 and
    // http://admin.localhost:5179. Those names do not resolve on the public
    // internet, so this was never remotely exploitable — but anyone able to
    // make them resolve on a machine (a hosts entry, a hostile local proxy,
    // a shared workstation) got a fully credentialed origin against live
    // customer data, for no benefit whatsoever in production.
    //
    // Gated the same way allowed_origins_patterns below already was. That
    // the pattern list was gated and this one was not is what makes this an
    // oversight rather than a decision.
    'allowed_origins' => array_values(array_filter(array_merge(
        [
            env('FRONTEND_URL'),
            env('ADMIN_FRONTEND_URL'),
        ],
        // 2026-08-27 — additional first-party origins the agent portal is
        // served from, comma-separated in .env (e.g. the Parked Domain
        // alias apps.liveto100club.com). Additive on purpose: it does NOT
        // replace FRONTEND_URL, which config/services.php reads as the ONE
        // canonical host for building public share/pay links — a share link
        // must resolve to the same place for every recipient regardless of
        // which domain the agent happened to be signed in to when minting
        // it, so that value stays single-valued.
        array_filter(array_map('trim', explode(',', (string) env('CORS_EXTRA_ORIGINS', '')))),
        env('APP_ENV') === 'local'
            ? ['http://agent.localhost:5178', 'http://admin.localhost:5179']
            : [],
    ))),

    // Local-dev-only fallback: Vite auto-increments its port (5173 ->
    // 5174 -> ...) whenever 5173 is already taken by another running
    // dev server, which otherwise breaks the CSRF/cookie handshake with
    // a confusing "can't reach server" error and no indication why.
    // Restricted to APP_ENV=local so this never applies in production —
    // SANCTUM_STATEFUL_DOMAINS in .env still needs the exact port too
    // (Sanctum's own check isn't pattern-based). Pattern covers any
    // *.localhost subdomain + any port, plus bare localhost/127.0.0.1
    // for any other local tooling that still hits the API directly.
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^http://([a-z0-9-]+\.)?(localhost|127\.0\.0\.1):\d+$#']
        : [],

    'allowed_headers' => ['*'],

    // TASK-143 / ADR-028 §2.5 — byte-range streaming diagnostics.
    //
    // Purely for DIAGNOSABILITY FROM JS. ag-ui verified that <video>
    // playback does NOT depend on this: the element parses range headers
    // in the browser's network stack, below the layer
    // Access-Control-Expose-Headers gates. Without these three, a
    // fetch()-based check of "did the server actually answer 206, and with
    // what range?" reads undefined and looks like a server bug.
    //
    // Additive only — origins, methods and credentials above are
    // deliberately untouched.
    'exposed_headers' => ['Content-Range', 'Accept-Ranges', 'Content-Length'],

    'max_age' => 0,

    'supports_credentials' => true,

];
