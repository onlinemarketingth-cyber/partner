<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\ProductCategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\CompanyScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-09-09 (human: "ออกแบบ Ui สำหรับปุ่มกู้คืนสินค้าซึ่งจะทำใน tab ใหม่").
 *
 * GET /catalog-trash — every hidden product, brand and category the actor is
 * allowed to bring back, in one response for one tab.
 *
 * ── WHY THIS EXISTS ──
 *
 * Everything in the catalogue has soft-deleted since TASK-091, so "ลบ" has
 * always meant "hide" — and nothing on any screen could unhide it. The rows
 * were still in the database and the only way back was a hand-written
 * UPDATE, which is exactly the kind of errand that ends with somebody
 * editing production by hand. The delete confirmation could not honestly say
 * "กู้คืนได้" either, because for the person reading it, it was not true.
 *
 * ── WHY ONE ENDPOINT AND NOT ?trashed=1 ON THREE ──
 *
 * The three index endpoints carry filters, agent narrowing, pagination and
 * eager-loads that exist to serve pickers and storefronts. A bin is a
 * different question with different answers ("what did I lose, and can I
 * have it back"), and threading a mode flag through three list endpoints
 * would put that question in the same code path as every product picker in
 * the app — where a flag defaulting wrong once means deleted products
 * appearing for sale.
 *
 * Deliberately unpaginated: this is a bin, not a catalogue. If it ever grows
 * large enough to need paging, that is itself the finding.
 */
class CatalogTrashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        // Not authorizeResource: this reads three models at once. Anyone who
        // may manage a catalogue may look in its bin; what they SEE is
        // narrowed below, and what they may restore is each row's own
        // policy answer, carried on the row as `permissions.restore`.
        abort_unless($actor?->isSuperAdmin() || $actor?->isCompanyAdmin(), 403);

        return response()->json(['data' => [
            'products' => ProductResource::collection(
                $this->trashed(Product::query()->with(['brand', 'category', 'company']), $request)->get()
            ),
            'brands' => BrandResource::collection(
                $this->trashed(Brand::query(), $request)->get()
            ),
            'categories' => ProductCategoryResource::collection(
                $this->trashed(ProductCategory::query(), $request)->get()
            ),
        ]]);
    }

    /**
     * Hidden rows only, narrowed the same way every other catalogue list is.
     *
     * includePlatformWide: a Super Admin scoped to one company must still
     * see the platform rows in the bin — they are the ones only they can
     * restore, and a bin that hides them would leave the delete they just
     * performed looking permanent.
     *
     * @param  Builder<Product|Brand|ProductCategory>  $query
     * @return Builder<Product|Brand|ProductCategory>
     */
    private function trashed(Builder $query, Request $request): Builder
    {
        $query->onlyTrashed()->orderByDesc('deleted_at');

        CompanyScopeFilter::apply($query, $request, includePlatformWide: true);

        return $query;
    }
}
