<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdateBackgroundGradientRequest;
use App\Http\Requests\Profile\UpdateBackgroundImageRequest;
use App\Http\Requests\Profile\UpdateBankAccountRequest;
use App\Http\Requests\Profile\UpdateNameRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\Platform\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Every action below operates on $request->user() only — never a
// route-bound {user} — so there is no way to edit anyone else's
// profile through this controller, by construction (no IDOR check
// needed because there's no "which user" input to spoof at all).
//
// TASK-044 Phase A: every UserResource returned below uses ::forOwner()
// (reveals full bank_account_number) — by the same "self-service only,
// never a route-bound {user}" construction above, every response here
// is unambiguously the caller's OWN row, matching the task spec's
// masking exception ("except on the owning agent's own profile view").
class UserProfileController extends Controller
{
    public function updateAvatar(UpdateAvatarRequest $request, UserProfileService $service): UserResource
    {
        $user = $service->updateAvatar($request->user(), $request->file('avatar'));

        return UserResource::forOwner($user->load('company'));
    }

    public function destroyAvatar(Request $request, UserProfileService $service): UserResource
    {
        $user = $service->deleteAvatar($request->user());

        return UserResource::forOwner($user->load('company'));
    }

    public function updateBackgroundGradient(UpdateBackgroundGradientRequest $request, UserProfileService $service): UserResource
    {
        $user = $service->updateBackgroundGradient($request->user(), $request->validated());

        return UserResource::forOwner($user->load('company'));
    }

    public function updateBackgroundImage(UpdateBackgroundImageRequest $request, UserProfileService $service): UserResource
    {
        $user = $service->updateBackgroundImage($request->user(), $request->file('background_image'));

        return UserResource::forOwner($user->load('company'));
    }

    public function destroyBackground(Request $request, UserProfileService $service): UserResource
    {
        $user = $service->deleteBackground($request->user());

        return UserResource::forOwner($user->load('company'));
    }

    public function updateName(UpdateNameRequest $request, UserProfileService $service): UserResource
    {
        $user = $service->updateName($request->user(), $request->validated());

        return UserResource::forOwner($user->load('company'));
    }

    public function updateBankAccount(UpdateBankAccountRequest $request, UserProfileService $service): UserResource
    {
        $user = $service->updateBankAccount($request->user(), $request->validated());

        return UserResource::forOwner($user->load('company'));
    }

    // Returns a plain message, not a UserResource — password isn't part
    // of AuthUser on either frontend and there's nothing else to sync
    // client-side; keeps this endpoint's contract minimal.
    public function updatePassword(UpdatePasswordRequest $request, UserProfileService $service): JsonResponse
    {
        $service->updatePassword($request->user(), $request->validated('password'));

        return response()->json(['message' => 'เปลี่ยนรหัสผ่านสำเร็จ']);
    }
}
