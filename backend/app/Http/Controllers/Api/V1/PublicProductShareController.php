<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreProductShareCheckoutRequest;
use App\Http\Resources\PublicProductShareResource;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductSalesMaterial;
use App\Models\ProductShareLink;
use App\Services\Catalog\ProductMediaService;
use App\Services\Catalog\ProductSalesMaterialService;
use App\Services\Order\ProductShareCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-056 Sprint P1 — the PUBLIC (unauthenticated) side of a Product
 * Share link: the landing page's data (show) plus the two file-serving
 * routes it needs (mediaStream/mediaThumbnail/materialStream), all gated
 * by the SAME opaque, revocable token — never a raw storage path (§5
 * rule 6). Mirrors SalesMaterialShareLinkController::show()'s
 * `withoutGlobalScopes()` + `isUsable()` pattern exactly (no authenticated
 * user exists here to scope by).
 */
class PublicProductShareController extends Controller
{
    public function show(string $token): PublicProductShareResource
    {
        $link = $this->resolveUsableLink($token);
        $link->increment('view_count');

        $link->load(['agent', 'company', 'product.media', 'product.salesMaterials', 'product.specs']);

        return new PublicProductShareResource($link);
    }

    /**
     * POST /public/product-shares/{token}/checkout — TASK-136.
     *
     * The SECOND unauthenticated write endpoint in this codebase (after
     * ADR-011's affiliate lead capture), and the first that creates a
     * money record. Rate-limited at 10/min — see routes/api.php.
     *
     * RESPONSE: `{ pay_url }` AND NOTHING ELSE. No ids, no company_id, no
     * agent name, no client name, no order number, no amount. Everything
     * the customer needs next is behind the pay token, which
     * PublicOrderResource already curates for exactly this audience (§6).
     * A test asserts the absence, because the natural instinct when
     * debugging is to "just add the order id" and that is a PDPA/BR-6
     * regression, not a convenience.
     *
     * EVERY REFUSAL LOOKS IDENTICAL — same 422, same Thai sentence:
     *
     *   - honeypot filled (a bot filled every input, including one no
     *     human sees)
     *   - the link's agent no longer holds Basic certification (BR-1)
     *   - the product's journey still requires a doctor's visit, so the
     *     order could be paid but never confirmed (ADR-026 §3.7)
     *   - the product or its pipeline template cannot be read
     *
     * ag-dev note for ag-lead/ag-qa: TASK-136's spec and TASK-140's
     * acceptance criteria both say the honeypot should "return the same
     * body as success". That is literally impossible here and the
     * difference is worth stating rather than fudging: this endpoint's
     * success body is a pay_url, so a 200 would have to carry either a
     * null (an obvious tell) or a fabricated token (lying to the caller
     * and 404ing on click). Instead, EVERY refusal path — honeypot
     * included — returns byte-identical output, so a probing bot cannot
     * tell "I was detected" from the far more common "this product isn't
     * self-serve". That is the strongest anti-oracle property available
     * given the response contract, and it is a deliberate, flagged
     * deviation, not an oversight.
     */
    public function checkout(
        StoreProductShareCheckoutRequest $request,
        string $token,
        ProductShareCheckoutService $service,
    ): JsonResponse {
        $link = $this->resolveUsableLink($token);

        $order = empty($request->validated('hp_field'))
            ? $service->checkout($link, $request->validated())
            : null;

        abort_if(! $order, 422, 'ขออภัย ขณะนี้ไม่สามารถทำรายการสั่งซื้อจากลิงก์นี้ได้');

        // Same construction as OrderResource::public_pay_url — the
        // customer must land on the rendered Vue page (/pay/{token},
        // PublicPaymentView), not on this API. The order's public_token is
        // itself unguessable (40 random chars) and is the ONLY identifier
        // that leaves this endpoint.
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');

        return response()->json([
            'pay_url' => "{$frontendUrl}/pay/{$order->public_token}",
        ]);
    }

    /** GET /public/product-shares/{token}/media/{productMedia}/stream */
    public function mediaStream(string $token, ProductMedia $productMedia, ProductMediaService $service): mixed
    {
        $link = $this->resolveUsableLink($token);
        abort_unless($productMedia->product_id === $link->product_id, 404);

        return Storage::disk($service->disk())->response($productMedia->file_path);
    }

    /** GET /public/product-shares/{token}/media/{productMedia}/thumbnail */
    public function mediaThumbnail(string $token, ProductMedia $productMedia, ProductMediaService $service): mixed
    {
        $link = $this->resolveUsableLink($token);
        abort_unless($productMedia->product_id === $link->product_id, 404);
        abort_unless($productMedia->thumbnail_path, 404);

        return Storage::disk($service->disk())->response($productMedia->thumbnail_path);
    }

    /** GET /public/product-shares/{token}/materials/{salesMaterial}/stream */
    public function materialStream(string $token, ProductSalesMaterial $salesMaterial, ProductSalesMaterialService $service): mixed
    {
        $link = $this->resolveUsableLink($token);
        abort_unless($salesMaterial->product_id === $link->product_id, 404);

        // An embedded material (YouTube/Vimeo link) has nothing of ours to
        // stream — redirect straight to it, same as
        // SalesMaterialShareLinkController::show().
        if ($salesMaterial->embed_url) {
            return redirect()->away($salesMaterial->embed_url);
        }

        abort_if(! $salesMaterial->file_path, 404);

        return Storage::disk($service->disk())->response($salesMaterial->file_path);
    }

    private function resolveUsableLink(string $token): ProductShareLink
    {
        $link = ProductShareLink::withoutGlobalScopes()->where('token', $token)->first();
        abort_if(! $link || ! $link->isUsable(), 404);

        /*
         * TASK-183 §3.5 — A LINK INTO A CLOSED COMPANY IS DEAD, for exactly
         * the reason the TASK-156 block below says a link to a withdrawn
         * PRODUCT is: the link outlives the thing it points at. Here the thing
         * is the whole tenant. Without this, the showcase still renders, the
         * media still streams, and — worst — /checkout still creates a Client,
         * a Referral and an Order for a company that has been switched off or
         * deleted.
         *
         * Placed alongside the product check and above it, on the same
         * resolver every public route of this controller passes through
         * (showcase, media stream, thumbnail, sales material, checkout), so
         * one line closes all five. Above rather than below because the tenant
         * is the broader fact: if the company is closed, whether its product
         * happens to still be flagged active is not a question worth asking.
         *
         * 404, matching the revoked/expired/withdrawn answers around it — the
         * visitor learns the link is dead, never that a particular company
         * exists and has been suspended (§3.4).
         */
        abort_unless(Company::isOperationalById($link->company_id), 404);

        /*
         * TASK-156 (human: "ปิดการใช้งาน ซ่อนทุกที่") — A LINK TO A
         * DEACTIVATED PRODUCT IS DEAD.
         *
         * "Shared" was named in the decision and missing from the spec table I
         * handed ag-dev; this closes it. A share link outlives the product: an
         * agent posts it to a customer, the company later discontinues the
         * product, and without this the customer could still open the
         * showcase, browse its media, and CHECK OUT — buying something the
         * company has withdrawn from sale. That is the most expensive form
         * this bug takes, because it ends in an order and a commission.
         *
         * Placed on the link resolver rather than in ProductShareLink::
         * isUsable(): "usable" is a fact about the LINK (revoked, expired),
         * and this is a fact about the product behind it. Every public route
         * — showcase, media, sales material, checkout — goes through here, so
         * one check closes all of them; ProductShareCheckoutService keeps its
         * own refusal as defence in depth, because it is reachable from the
         * Service layer independently of this controller.
         *
         * 404, matching the revoked/expired answer immediately above: a
         * customer holding a stale link learns the link is dead, not that the
         * company has discontinued a product.
         *
         * withoutGlobalScopes on the relation for the same reason the link
         * lookup uses it — this route is PUBLIC, there is no authenticated
         * user for TenantScope to resolve against.
         */
        $product = Product::withoutGlobalScopes()->whereKey($link->product_id)->first();
        abort_if(! $product || ! $product->is_active, 404);

        return $link;
    }
}
