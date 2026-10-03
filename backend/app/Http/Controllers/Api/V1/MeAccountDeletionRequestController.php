<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreAccountDeletionRequestRequest;
use App\Http\Resources\AccountDeletionPreviewResource;
use App\Http\Resources\OwnAccountDeletionRequestResource;
use App\Models\AccountDeletionRequest;
use App\Services\Account\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * MOB-12 (2026-10-02) — /me/account-deletion-request.
 *
 * Self-scoped like every /me/* route: the subject is always $request->user(),
 * so there is no {user} to guess and no IDOR surface. Agents only (the
 * Policy); everything else is the Service's job.
 *
 * Two answers from store() (owner decision 2026-10-03), and the portal
 * branches on `data.status`:
 *   * 202 Accepted + `pending` — a REQUEST an admin has yet to act on;
 *   * 200 OK + `deleted`       — the account no longer exists as of this
 *                                response (nothing unpaid, or waived).
 */
class MeAccountDeletionRequestController extends Controller
{
    /** GET /me/account-deletion-request/preview */
    public function preview(Request $request, AccountDeletionService $service): AccountDeletionPreviewResource
    {
        $this->authorize('create', AccountDeletionRequest::class);

        return new AccountDeletionPreviewResource($service->preview($request->user()));
    }

    public function store(StoreAccountDeletionRequestRequest $request, AccountDeletionService $service): JsonResponse
    {
        $deletionRequest = $service->request(
            $request->user(),
            (string) $request->validated('password'),
            $request->validated('reason'),
            $request->validated('commission_choice'),
        );

        /*
         * The Service has already revoked every token and every stored
         * session. A COOKIE-authenticated caller additionally holds this
         * request's own live session, which the session middleware would
         * write straight back to storage on the way out — so end it here,
         * the same way AuthController::logout() does. A token caller has no
         * session (non-stateful domain) and its token is already gone.
         */
        if (! $request->user()?->currentAccessToken() instanceof PersonalAccessToken && $request->hasSession()) {
            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return (new OwnAccountDeletionRequestResource($deletionRequest))
            ->response()
            ->setStatusCode(OwnAccountDeletionRequestResource::wasDeleted($deletionRequest) ? 200 : 202);
    }
}
