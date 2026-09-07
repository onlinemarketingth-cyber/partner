<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-09-07 (human: "สร้าง link ครั้งแรก และสมัครสมาชิกในระบบทำไมช้า") —
 * where the time actually goes, measured on the machine it is slow on.
 *
 * "Why is this slow" was answerable only by reading code and guessing, and the
 * two candidates for these endpoints pull in opposite directions: registration
 * sends its verification email SYNCHRONOUSLY (deliberately — see
 * VerifyRegistrationEmailNotification, which records why a queued send was
 * reverted), which is seconds of SMTP inside the request and costs no queries;
 * a slow list or an N+1 is the opposite shape, many queries and no waiting.
 * One number cannot tell those apart. Three can:
 *
 *   Server-Timing: app;dur=<total ms>, db;dur=<time in queries>, ...
 *   X-Query-Count: <how many>
 *
 * `app - db` is time spent NOT talking to the database — SMTP, hashing, image
 * work, PHP. Read in a browser's Network tab, on production, against the real
 * request that felt slow, with no profiler to install.
 *
 * ── WHY IT IS OFF BY DEFAULT ──
 *
 * `SERVER_TIMING=true` in .env, and nothing else turns it on. Timing headers
 * on public endpoints are a mild side channel — the duration of a login or a
 * registration varies with whether the address exists, and this API works hard
 * elsewhere to make those two indistinguishable (see LoginRequest). Leaving
 * this on permanently would hand back through a header what those branches are
 * careful not to say in a body.
 *
 * So it is a switch an operator flips while looking at a problem, and turns
 * off after. Query COUNTING costs a listener on every query, which is another
 * reason not to leave it running.
 */
class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.server_timing')) {
            return $next($request);
        }

        $queries = 0;
        $dbMicros = 0.0;

        // `time` is milliseconds with a fractional part, as Laravel reports it.
        DB::listen(function ($query) use (&$queries, &$dbMicros) {
            $queries++;
            $dbMicros += $query->time;
        });

        $startedAt = microtime(true);

        $response = $next($request);

        $totalMs = (microtime(true) - $startedAt) * 1000;

        /*
         * Both numbers, not a percentage: the interesting quantity is the
         * SUBTRACTION, and a reader doing it themselves cannot be misled by
         * how this rounded it. A registration that spends 2,800ms of 2,900ms
         * outside the database is a mail server; 2,800ms across 400 queries is
         * an N+1. Same total, opposite fix.
         */
        $response->headers->set('Server-Timing', sprintf(
            'app;desc="total";dur=%.1f, db;desc="%d queries";dur=%.1f',
            $totalMs,
            $queries,
            $dbMicros,
        ));
        $response->headers->set('X-Query-Count', (string) $queries);

        return $response;
    }
}
