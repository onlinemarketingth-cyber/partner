<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreClientCategoryRequest;
use App\Http\Requests\Customer\UpdateClientCategoryRequest;
use App\Http\Resources\ClientCategoryResource;
use App\Models\ClientCategory;
use App\Models\Company;
use App\Services\Customer\ClientCategoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// TASK-056 Sprint P2 — mirrors BrandController's thin-controller shape.
// index() self-heals the starter set (ClientCategoryService::ensureDefaults)
// for the requester's own company before listing — see that method's own
// comment for why this is safe to call on every request.
class ClientCategoryController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ClientCategory::class, 'client_category');
    }

    public function index(Request $request, ClientCategoryService $service): AnonymousResourceCollection
    {
        if ($request->user()->company_id) {
            $company = Company::withoutGlobalScopes()->find($request->user()->company_id);
            if ($company) {
                $service->ensureDefaults($company);
            }
        }

        return ClientCategoryResource::collection(
            ClientCategory::query()->withCount('clients')->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(StoreClientCategoryRequest $request, ClientCategoryService $service): ClientCategoryResource
    {
        $category = $service->create($request->validated(), $request->user());

        return new ClientCategoryResource($category);
    }

    public function show(ClientCategory $clientCategory): ClientCategoryResource
    {
        return new ClientCategoryResource($clientCategory);
    }

    public function update(UpdateClientCategoryRequest $request, ClientCategory $clientCategory, ClientCategoryService $service): ClientCategoryResource
    {
        return new ClientCategoryResource($service->update($clientCategory, $request->validated()));
    }

    public function destroy(ClientCategory $clientCategory): Response
    {
        $clientCategory->delete();

        return response()->noContent();
    }
}
