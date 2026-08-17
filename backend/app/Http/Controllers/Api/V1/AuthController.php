<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
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

        /** @var \App\Models\User $user */
        $user = $request->user();

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
