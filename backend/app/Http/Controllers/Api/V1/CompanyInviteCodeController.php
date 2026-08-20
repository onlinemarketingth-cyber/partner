<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TrackedLinkGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreCompanyInviteCodeRequest;
use App\Http\Requests\Platform\UpdateCompanyInviteCodeRequest;
use App\Http\Resources\CompanyInviteCodeResource;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Services\Link\TrackedLinkService;
use App\Services\Registration\CompanyInviteCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * TASK-233 — the management side of a company's signup link, which has
 * never existed until now (see CompanyInviteCodeService's own header).
 *
 * `destroy` REVOKES rather than deletes, matching the verb every other
 * link controller in this app gives that route. Deleting would orphan
 * `users.registered_via_invite_code_id` on people who are still working
 * here, erasing the answer to "where did this agent come from".
 */
class CompanyInviteCodeController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CompanyInviteCode::class, 'company_invite_code');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = CompanyInviteCode::query()->with(['company', 'createdBy']);

        // BR-6 — a Company Admin sees only their own. This model is NOT
        // tenant-scoped (it predates the convention, see the model), so the
        // narrowing has to be explicit here rather than assumed.
        if (! $user->isSuperAdmin()) {
            $query->where('company_id', $user->company_id);
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        return CompanyInviteCodeResource::collection(
            $query->orderByRaw('revoked_at is null desc')->orderByDesc('id')->get()
        );
    }

    public function store(StoreCompanyInviteCodeRequest $request, CompanyInviteCodeService $service): CompanyInviteCodeResource
    {
        $code = $service->create($request->validated(), $request->user());

        return new CompanyInviteCodeResource($code->load(['company', 'createdBy']));
    }

    public function update(UpdateCompanyInviteCodeRequest $request, CompanyInviteCode $companyInviteCode, CompanyInviteCodeService $service): CompanyInviteCodeResource
    {
        $code = $service->update($companyInviteCode, $request->validated());

        return new CompanyInviteCodeResource($code->load(['company', 'createdBy']));
    }

    public function destroy(CompanyInviteCode $companyInviteCode, CompanyInviteCodeService $service): CompanyInviteCodeResource
    {
        $code = $service->revoke($companyInviteCode);

        return new CompanyInviteCodeResource($code->load(['company', 'createdBy']));
    }

    /**
     * TASK-235 — mint (or return) the short LOGIN link for a company.
     *
     * A POST rather than a field on the theme payload, because minting
     * writes a row. ThemeResource is read by anonymous visitors on every
     * themed page load; a GET that quietly creates something is how you end
     * up with duplicate rows under retries and a link that appears without
     * anybody choosing it.
     *
     * Idempotent through the service, so pressing the button twice is safe
     * and gives back the same URL.
     */
    public function loginLink(Request $request, TrackedLinkService $trackedLinks): JsonResponse
    {
        $user = $request->user();
        $this->authorize('create', CompanyInviteCode::class);

        $companyId = $user->isSuperAdmin()
            ? $request->integer('company_id')
            : (int) $user->company_id;

        $company = Company::withoutGlobalScopes()->findOrFail($companyId);

        // The code defaults to the company's own slug — the thing already
        // printed on their branded login link today, so the short URL reads
        // the same way the long one did. `?: null` rather than `??`: a slug
        // of '' must fall through to a random code, not be claimed.
        $link = $trackedLinks->mintFor(
            TrackedLinkGroup::CompanyLogin,
            $company,
            $user,
            null,
            $request->input('code') ?: ($company->slug ?: null),
        );

        return response()->json(['data' => [
            'code' => $link->code,
            'login_short_link' => $link->publicUrl(),
        ]]);
    }
}
