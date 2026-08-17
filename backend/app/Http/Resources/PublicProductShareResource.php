<?php

namespace App\Http\Resources;

use App\Enums\MediaSourceType;
use App\Http\Resources\Concerns\ResolvesPublicTheme;
use App\Services\Catalog\ProductPricingService;
use App\Services\Pipeline\PipelineTemplateResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-056 Sprint P1 — backs GET /public/product-shares/{token}, the
 * PUBLIC (unauthenticated) product showcase page. Mirrors
 * PublicAffiliateLinkContextResource's exact-boundary approach: exposes
 * ONLY presentational, non-sensitive fields — product name/description/
 * price/specs, its full media gallery + sales materials (via signed
 * controller-served stream URLs, never a raw storage path — §5 rule 6),
 * and who shared it (agent + company name, same "business card" reasoning
 * as the affiliate link context resource). NEVER exposes: company_id,
 * agent_id, the token itself, view_count, cost/margin fields, or any
 * other AffiliateLink/Order/commission data.
 */
class PublicProductShareResource extends JsonResource
{
    use ResolvesPublicTheme;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;

        // One instance each — this Resource renders a SINGLE share link,
        // so there is no collection N+1 to solve here, but resolving the
        // container twice for the same class inside one array literal
        // would still be two objects and two chain walks.
        $pricing = app(ProductPricingService::class);
        $templateResolver = app(PipelineTemplateResolver::class);
        $template = $product ? $templateResolver->resolveForProduct($product) : null;

        return [
            'company_name' => $this->company?->name,
            'agent_name' => $this->agent?->name,
            // TASK-159 §3 — the SHARING company's theme, resolved from the
            // token (the link's own company_id), so a customer who opened
            // /p/{token} with no slug anywhere in the URL still sees this
            // company's brand instead of the platform default.
            'theme' => $this->publicTheme($this->company),
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'spec_description' => $product->spec_description,
                // The LIST price, unchanged — existing callers keep
                // reading exactly what they always did.
                'price_satang' => $product->price_satang,
                // TASK-136 (risk R1) — what the customer will ACTUALLY be
                // charged if they check out right now. Identical to
                // price_satang unless a product_price_promotion is live,
                // and derived from the same ProductPricingService that
                // OrderService snapshots onto the order, so "the price on
                // the page" and "the amount on the order" cannot drift.
                //
                // Exposed as a separate field rather than by overwriting
                // price_satang so ag-ui can render the standard
                // strikethrough-original / highlighted-sale pattern; it is
                // also what TASK-137's acceptance criterion "price shown
                // must equal the price the order is created at" is checked
                // against. BR-3: integer satang, never divided here.
                'payable_price_satang' => $pricing->effectivePriceSatang($product),
                // Non-null only while a promotion is running — lets the UI
                // decide whether there is a discount to advertise at all
                // without comparing two numbers itself.
                'promotional_price_satang' => $pricing->activePromotion($product)?->discounted_price_satang,
                // ADR-026 §3.7 (TASK-136) — may an anonymous visitor buy
                // this product straight from the page, or does its journey
                // still route through an appointment and a doctor's visit?
                // Answered here so TASK-137 can decide between a "ซื้อเลย"
                // CTA and the view-only page + "สนใจ ให้ติดต่อกลับ" lead
                // form WITHOUT having to POST and read a 422 to find out.
                //
                // This is the same predicate the checkout endpoint enforces
                // server-side (ProductShareCheckoutService), not a second
                // opinion — the UI hint and the gate cannot disagree. It
                // discloses nothing sensitive: it is exactly the fact that
                // the presence or absence of a buy button would reveal.
                'can_checkout' => $templateResolver->paymentReachableFromEntry($template),
                'specs' => $product->relationLoaded('specs')
                    ? $product->specs->map(fn ($spec) => [
                        'spec_group' => $spec->spec_group,
                        'spec_key' => $spec->spec_key,
                        'spec_value' => $spec->spec_value,
                    ])
                    : [],
                'media' => $product->relationLoaded('media')
                    ? $product->media->map(fn ($media) => [
                        'id' => $media->id,
                        'media_type' => $media->media_type?->value,
                        'source_type' => $media->source_type?->value,
                        'stream_url' => $media->source_type === MediaSourceType::Upload && $media->file_path
                            ? route('public-product-shares.media-stream', [$this->token, $media->id])
                            : null,
                        'thumbnail_url' => $media->thumbnail_path
                            ? route('public-product-shares.media-thumbnail', [$this->token, $media->id])
                            : null,
                        'embed_url' => $media->embed_url,
                        'is_primary' => $media->is_primary,
                    ])
                    : [],
                'sales_materials' => $product->relationLoaded('salesMaterials')
                    ? $product->salesMaterials->map(fn ($material) => [
                        'id' => $material->id,
                        'material_group' => $material->material_group,
                        'original_filename' => $material->original_filename,
                        'mime_type' => $material->mime_type,
                        'stream_url' => $material->embed_url === null
                            ? route('public-product-shares.material-stream', [$this->token, $material->id])
                            : null,
                        'embed_url' => $material->embed_url,
                    ])
                    : [],
            ] : null,
        ];
    }
}
