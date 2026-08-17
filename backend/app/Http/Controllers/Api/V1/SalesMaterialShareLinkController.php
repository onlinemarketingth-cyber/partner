<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreSalesMaterialShareLinkRequest;
use App\Http\Resources\SalesMaterialShareLinkResource;
use App\Models\Company;
use App\Models\ProductSalesMaterial;
use App\Models\SalesMaterialShareLink;
use App\Services\Catalog\ProductSalesMaterialService;
use App\Services\Catalog\SalesMaterialShareLinkService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

// ADR-007 Decision 3. index/store are authenticated + Policy-checked
// (StoreSalesMaterialShareLinkRequest); show() is the ONE public,
// unauthenticated route in this app — registered OUTSIDE the
// auth:sanctum group in routes/api.php — deliberately.
class SalesMaterialShareLinkController extends Controller
{
    public function index(ProductSalesMaterial $salesMaterial): AnonymousResourceCollection
    {
        $this->authorize('view', $salesMaterial->product);

        return SalesMaterialShareLinkResource::collection(
            $salesMaterial->shareLinks()->with('createdBy')->latest()->get()
        );
    }

    public function store(StoreSalesMaterialShareLinkRequest $request, ProductSalesMaterial $salesMaterial, SalesMaterialShareLinkService $service): SalesMaterialShareLinkResource
    {
        $link = $service->create($salesMaterial, $request->validated()['expires_in_days'], $request->user());

        return new SalesMaterialShareLinkResource($link);
    }

    public function destroy(SalesMaterialShareLink $shareLink, SalesMaterialShareLinkService $service): Response
    {
        $this->authorize('view', $shareLink->salesMaterial->product);

        $service->revoke($shareLink);

        return response()->noContent();
    }

    /**
     * GET /share/sales-materials/{token} — PUBLIC, unauthenticated.
     * `token` is an opaque 64-char random string, not a database id
     * (never enumerable — Section 5 rule 5's IDOR concern). Grants
     * access to exactly ONE pre-authorized file, nothing else — this is
     * NOT a listing endpoint and never will be.
     */
    public function show(string $token, SalesMaterialShareLinkService $shareLinkService, ProductSalesMaterialService $materialService): mixed
    {
        $link = SalesMaterialShareLink::withoutGlobalScopes()->where('token', $token)->first();

        abort_if(! $link || ! $link->isUsable(), 404);

        // TASK-183 §3.5 — a closed tenant's brochures stop being downloadable.
        // A share link is minted to outlive the conversation it was sent in,
        // so without this a prospect keeps pulling a deactivated company's
        // sales collateral off the private disk indefinitely. Refused before
        // recordView() so a dead tenant gains no view counter either. 404,
        // matching the revoked/expired answer immediately above (§3.4).
        abort_unless(Company::isOperationalById($link->company_id), 404);

        $shareLinkService->recordView($link);

        $material = ProductSalesMaterial::withoutGlobalScopes()->find($link->sales_material_id);
        abort_if(! $material, 404);

        // An embedded material (YouTube/Vimeo link) has nothing of ours
        // to stream — redirect the prospect straight to it.
        if ($material->embed_url) {
            return redirect()->away($material->embed_url);
        }

        abort_if(! $material->file_path, 404);

        return Storage::disk($materialService->disk())->download($material->file_path, $material->original_filename);
    }
}
