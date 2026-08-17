<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Requests\Platform\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\Platform\CompanyService;
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
        return new CompanyResource($service->update($company, $request->validated()));
    }

    public function destroy(Company $company, CompanyService $service): Response
    {
        $service->delete($company);

        return response()->noContent();
    }
}
