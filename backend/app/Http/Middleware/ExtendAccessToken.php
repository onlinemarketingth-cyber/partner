<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\AuthController;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-09-09 (human: "ทำให้อายุการ Login นานกว่านี้แบบ Facebook หรือ YouTube
 * ทำอย่างไร").
 *
 * The token's clock only runs while nobody is using the app.
 *
 * ── WHAT "LIKE FACEBOOK" ACTUALLY IS ──
 *
 * Not a long fuse. A fuse that resets every time you touch it. Before this the
 * portal issued a flat 12-hour token with no renewal at all, so an agent using
 * the app all day was signed out in the middle of using it — the one moment a
 * timeout is least defensible, because the person is right there and demonstrably
 * still themselves.
 *
 * With sliding renewal the number stops meaning "how long you may stay signed
 * in" and starts meaning "how long you may STOP before you have to sign in
 * again", which is the question a person can actually reason about.
 *
 * ── WHY IT DOES NOT WRITE ON EVERY REQUEST ──
 *
 * Extending on every request would add a database write to every single API
 * call in the app — a busy agent's dashboard alone makes half a dozen — to
 * move a date that has weeks left on it.
 *
 * So it writes only once the token is past HALFWAY through its window. The
 * guarantee is unchanged (anyone active inside the window is carried forward)
 * and the cost collapses to roughly one write per half-window per device.
 *
 * ── WHY THE WINDOW COMES FROM THE NAME ──
 *
 * "จดจำฉัน" decides the length at login, and renewal must extend by the SAME
 * window the token was issued with. `expires_at` cannot answer that — the
 * first extension overwrites the only evidence of how long the original window
 * was. Sanctum already stores a name per token, so the name carries it, and
 * there is no migration and no second source of truth.
 */
class ExtendAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * AFTER the request, not before.
         *
         * This is global middleware, so on the way IN it runs ahead of the
         * route's own `auth:sanctum` and `$request->user()` is still null —
         * the first version of this checked there and silently did nothing at
         * all, on every request, while looking entirely correct.
         *
         * On the way OUT the guard has resolved, so the token is there to
         * read. Nothing about the response depends on this, which is why it
         * can happen last.
         */
        $response = $next($request);

        $token = $request->user()?->currentAccessToken();

        /*
         * Only a real database token. A cookie-session request (the admin
         * console) carries Sanctum's TransientToken, which has no expiry to
         * extend and no row to write — its lifetime is the session's, and
         * Laravel already slides that.
         */
        if ($token instanceof PersonalAccessToken && $token->expires_at !== null) {
            $this->extendIfPastHalfway($token);
        }

        return $response;
    }

    private function extendIfPastHalfway(PersonalAccessToken $token): void
    {
        $window = AuthController::tokenLifetimeMinutes($token->name === AuthController::TOKEN_NAME_REMEMBER);
        $remaining = now()->diffInMinutes($token->expires_at, false);

        // Already dead: leave it. Sanctum's own guard rejects the request, and
        // pushing the date forward here would resurrect a token that should
        // have sent the person back to the login screen.
        if ($remaining <= 0 || $remaining > $window / 2) {
            return;
        }

        /*
         * timestamps off: `updated_at` is not what this is about, and letting
         * it move would make every token row look freshly edited to anyone
         * reading the table to work out what happened.
         */
        $token->timestamps = false;
        $token->forceFill(['expires_at' => now()->addMinutes($window)])->save();
    }
}
