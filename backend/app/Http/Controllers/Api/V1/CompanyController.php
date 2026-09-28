<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\RemoveCompanyRequest;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Requests\Platform\UpdateCompanyRequest;
use App\Http\Requests\Platform\UpdateCompanyTestModeRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\Platform\CompanyRemovalService;
use App\Services\Platform\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Super Admin only, end to end (CompanyPolicy::viewAny/create/update/
// delete all require isSuperAdmin() — a Company Admin/Agent can view
// their own single company via GET /me instead, not through this
// resource). No TenantScope applies to Company itself (see its model
// docblock), so this is a plain, un-scoped CRUD list — same shape as
// BrandController otherwise.
class CompanyController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Company::class, 'company');
    }

    public function index(): AnonymousResourceCollection
    {
        return CompanyResource::collection(Company::withCount('users')->orderBy('name')->paginate());
    }

    public function store(StoreCompanyRequest $request, CompanyService $service): CompanyResource
    {
        return new CompanyResource($service->create($request->validated()));
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company->loadCount('users'));
    }

    public function update(UpdateCompanyRequest $request, Company $company, CompanyService $service): CompanyResource
    {
        return new CompanyResource($service->update($company, $request->validated(), $request->user()));
    }

    public function destroy(Company $company, CompanyService $service): Response
    {
        $service->delete($company);

        return response()->noContent();
    }

    /**
     * GET /companies/{company}/removal — 2026-09-26. What deleting this
     * company would take, and whether it is allowed. Read-only; the screen
     * asks before it offers the button.
     */
    public function removal(Request $request, Company $company, CompanyRemovalService $service): JsonResponse
    {
        $this->authorize('delete', $company);

        return response()->json(['data' => $service->assess($company)]);
    }

    /** PUT /companies/{company}/test-mode — see CompanyRemovalService::setTestMode(). */
    public function testMode(UpdateCompanyTestModeRequest $request, Company $company, CompanyRemovalService $service): CompanyResource
    {
        $service->setTestMode($company, $request->boolean('is_test'), $request->user());

        return new CompanyResource($company->fresh()->loadCount('users'));
    }

    /**
     * DELETE /companies/{company}/purge — a real deletion, users and all.
     *
     * Not the resource's own DELETE, which remains the soft delete it has
     * always been: that one is a kill switch that can be undone, this one
     * cannot, and a single verb meaning both is how the wrong one gets called.
     */
    public function purge(RemoveCompanyRequest $request, Company $company, CompanyRemovalService $service): Response
    {
        $service->remove($company, (string) $request->validated('confirm_name'), $request->user());

        return response()->noContent();
    }
}
