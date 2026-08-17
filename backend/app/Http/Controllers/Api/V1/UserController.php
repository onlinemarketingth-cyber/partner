<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IdDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\MoveUserCompanyRequest;
use App\Http\Requests\Platform\ResetUserPasswordRequest;
use App\Http\Requests\Platform\StoreUserRequest;
use App\Http\Requests\Platform\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Platform\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// "Manage Agents" — Company Admin's own team (agent + company_admin
// roles only, TenantScope already narrows Company Admin to their own
// company_id; Super Admin's queries are unscoped by TenantScope so they
// see every company by default — see TenantScope's own docblock).
// Super Admin rows are always excluded from this list (UserPolicy::view()
// backs this up too) — they aren't "team members" to browse/manage here.
class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()->with(['company', 'manager'])->where('role', '!=', 'super_admin');

        if ($request->boolean('include_inactive')) {
            $query->withTrashed();
        }

        // TASK-060 — search, same pattern as ClientController::index
        // (TASK-049). `q` is a free-text LIKE across name/phone/email
        // (partial match). `national_id` is EXACT-only: the column is
        // encrypted and therefore unsearchable directly, so we match on
        // the deterministic blind index (User::hashNationalId) — a
        // caller must supply the full number to get a hit. (TASK-122:
        // "national_id" now means "identity document number", which may be
        // a passport — see the block below.)
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }
        if (($nationalId = trim((string) $request->query('national_id', ''))) !== '') {
            // TASK-122 — the column may now hold a Thai national ID OR a
            // passport, and the blind index normalizes the two differently
            // (User::hashNationalId), so ONE hash is no longer enough.
            //
            // HOW THE CALLER SIGNALS THE TYPE: optionally, with
            // `?id_document_type=thai_national_id|passport`. When it is
            // supplied we match that hash only. When it is ABSENT — which is
            // the case for every existing caller, including the Admin search
            // box that just wants "find whoever this number belongs to" — we
            // canonicalise DEFENSIVELY and try both. That is safe rather than
            // sloppy: the two candidates are HMACs of two different strings,
            // so a row can only match if its own stored document really does
            // canonicalise to one of them. It is not a fuzzy match.
            $type = IdDocumentType::tryFrom((string) $request->query('id_document_type', ''));

            $candidates = $type !== null
                ? [User::hashNationalId($nationalId, $type)]
                : [
                    User::hashNationalId($nationalId, IdDocumentType::ThaiNationalId),
                    User::hashNationalId($nationalId, IdDocumentType::Passport),
                ];

            // array_unique because a digits-only search term canonicalises
            // identically under both rules and would otherwise be asked for
            // twice. array_filter because a term that normalizes to nothing
            // hashes to null — and a null in this list would let Eloquent
            // rewrite the comparison into an IS NULL and wrongly return every
            // agent with NO document on file. If everything filters out, the
            // sentinel below can never equal a real 64-hex-char HMAC, so the
            // result is zero rows (never "all rows").
            $candidates = array_values(array_unique(array_filter($candidates)));

            $query->whereIn('national_id_hash', $candidates ?: ['no-match']);
        }

        return UserResource::collection($query->orderBy('name')->paginate());
    }

    public function store(StoreUserRequest $request, UserService $service): UserResource
    {
        return new UserResource($service->create($request->validated(), $request->user())->load('company'));
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('company'));
    }

    public function update(UpdateUserRequest $request, User $user, UserService $service): UserResource
    {
        return new UserResource($service->update($user, $request->validated(), $request->user())->load('company'));
    }

    /**
     * Soft-delete (deactivate) — see UserPolicy::delete() for the self-lockout guard.
     *
     * TASK-183 §4.1 — $request->user() is passed through as the ACTOR for the
     * audit row. Same for restore() and resetPassword() below: the Service
     * cannot infer who did this, and "who" is most of the point of the row.
     */
    public function destroy(Request $request, User $user, UserService $service): Response
    {
        $service->deactivate($user, $request->user());

        return response()->noContent();
    }

    /** POST /users/{user}/restore — reactivate a deactivated agent. Route uses withTrashed binding. */
    public function restore(Request $request, User $user, UserService $service): UserResource
    {
        $this->authorize('restore', $user);

        return new UserResource($service->restore($user, $request->user())->load('company'));
    }

    /** POST /users/{user}/reset-password — the "no email system" companion to StoreUserRequest's temp password. */
    public function resetPassword(ResetUserPasswordRequest $request, User $user, UserService $service): UserResource
    {
        return new UserResource(
            $service->resetPassword($user, $request->validated('password'), $request->user())->load('company')
        );
    }

    /** POST /users/{user}/move-company — Phase 11, Super-Admin-only (UserPolicy::move()). */
    public function moveToCompany(MoveUserCompanyRequest $request, User $user, UserService $service): UserResource
    {
        return new UserResource($service->moveToCompany($user, $request->validated('company_id'), $request->user())->load('company'));
    }
}
