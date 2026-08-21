<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Section 7: Controller stays thin — validation lives in LoginRequest,
// there is no business logic here to push down into a Service.
class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        $request->authenticate();

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();

        /*
         * SECURITY AUDIT 2026-08-21 (V19) — record that this login happened.
         *
         * Nothing recorded logins before. In a system that pays commission,
         * "which admin was signed in when this payout was approved, and
         * from where" had no answer at all — and the absence only becomes
         * visible at the exact moment somebody needs it, which is always
         * after the fact and never in time.
         *
         * Successes only. A failed attempt is already covered by the
         * throttle and by RecordAuthLockout when it becomes a pattern;
         * writing a row per wrong password would let anyone with a login
         * form fill this table on demand.
         *
         * No user agent: it is attacker-controlled free text, it is bulky,
         * and it answers no question the IP does not answer better.
         */
        AuditLog::create([
            'company_id' => $user->company_id,
            'actor_user_id' => $user->id,
            'action' => 'auth.login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => null,
            'new_values' => ['role' => $user->role?->value],
            'ip_address' => $request->ip(),
        ]);

        // TASK-044 Phase A — this is the authenticated user's own row
        // (never a route-bound {user}), so the full bank_account_number
        // is safe to reveal here per the task spec's masking exception.
        return UserResource::forOwner($user->load('company'));
    }

    public function logout(Request $request): Response
    {
        auth('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource|Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->noContent(204);
        }

        // TASK-044 Phase A — GET /me is THE canonical "owning agent's own
        // profile view" the task spec calls out as the masking exception.
        return UserResource::forOwner($user->load('company'));
    }
}
