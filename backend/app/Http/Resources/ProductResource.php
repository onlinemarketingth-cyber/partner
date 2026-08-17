<?php

namespace App\Http\Resources;

use App\Services\Pipeline\PipelineTemplateResolver;
use App\Support\RequestScopedService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// price_satang stays an integer here (BR-3) — dividing by 100 for
// display is a UI-layer concern (CLAUDE.md BR-3: "Divide by 100 only at
// the UI display layer"), never done in the API.
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'category' => new ProductCategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'price_satang' => $this->price_satang,
            'description' => $this->description,
            'spec_description' => $this->spec_description,
            'is_active' => $this->is_active,
            // TASK-056 P3 — only present when 'media' is eager-loaded
            // (ProductController::index()); never a raw storage path
            // (Section 5 rule 6), same controller-served thumbnail route
            // ProductMediaResource already uses.
            // Bug fix (2026-08-01, human-reported: storefront cards showing
            // placeholder for every product) — thumbnail_path is populated
            // by CompressUploadedVideo ONLY for video media; plain image
            // uploads never get one (ProductMediaService::store() only sets
            // file_path for images), so this fell back to null for the
            // overwhelming majority of products. Same fallback pattern
            // already used by ProductShareView.vue/ClientsView.vue on the
            // frontend: for an image with no thumbnail_path, stream the
            // image itself instead of showing nothing.
            // TASK-097 — resolution order is now cover-first:
            //   1. the primary COVER (รูปสินค้า) — what the admin chose
            //   2. any cover, if somehow none is flagged primary
            //   3. the old behaviour (primary anywhere, else first item)
            // Step 3 is kept ONLY as a fallback for products that have no
            // covers yet. Dropping it would blank the card for every
            // product whose photos still live in the detail gallery.
            'thumbnail_url' => $this->when($this->relationLoaded('media'), function () {
                $covers = $this->media->where('purpose', \App\Enums\ProductMediaPurpose::Cover);

                $primary = $covers->firstWhere('is_primary', true)
                    ?? $covers->first()
                    ?? $this->media->firstWhere('is_primary', true)
                    ?? $this->media->first();

                if (! $primary) {
                    return null;
                }

                if ($primary->thumbnail_path) {
                    return route('product-media.thumbnail', $primary->id);
                }

                return $primary->media_type === \App\Enums\ProductMediaType::Image ? route('product-media.stream', $primary->id) : null;
            }),
            // ADR-011/TASK-027 — commission_plan_type is the product's OWN
            // override (null = inheriting); effective_plan_type is always
            // resolved (Product::effectivePlanType()) so ag-ui never has
            // to duplicate the inherit-fallback logic client-side.
            'commission_plan_type' => $this->commission_plan_type?->value,
            'effective_plan_type' => $this->effectivePlanType()->value,
            // TASK-194 §3.1/§3.4 — same "own override + always-resolved
            // effective value" pairing as commission_plan_type/
            // effective_plan_type above, so ag-ui never has to duplicate
            // the null='additive' fallback client-side. Only meaningful
            // when effective_plan_type is Affiliate; harmless otherwise.
            'affiliate_override_mode' => $this->affiliate_override_mode?->value,
            'effective_affiliate_override_mode' => $this->effectiveAffiliateOverrideMode()->value,
            // TASK-197 §2.1 — the product's OWN %/fixed-amount format
            // setting for its commission_rules. Null = "not yet
            // configured"; ag-ui defaults a fresh rule form to
            // 'percentage' when null (no "effective_" resolved twin like
            // commission_plan_type/affiliate_override_mode above — there
            // is no company-level fallback to inherit here, this is a
            // purely per-product setting the first rule locks in).
            'commission_rate_type' => $this->commission_rate_type?->value,
            // ADR-026 §3.3 (TASK-132) — the product's OWN template
            // override; null = inheriting from category/company.
            'pipeline_template_id' => $this->pipeline_template_id,
            // ADR-033 (TASK-189) §2.3/§2.5 — BR-7 admin-editable config so
            // ProductEditView.vue (F1) can populate/edit the voucher +
            // shipping fields. Null quota/validity mean unlimited/never
            // expires (OrderVoucherService::issueFor() snapshots these at
            // payment time, never reads them live at redemption).
            'voucher_usage_quota' => $this->voucher_usage_quota,
            'voucher_validity_days' => $this->voucher_validity_days,
            'requires_shipping' => (bool) $this->requires_shipping,
            // ADR-026 §3.3 (TASK-136) — the RESOLVED template, i.e. the
            // journey a referral created for this product would actually
            // be stamped with. Mirrors how `effective_plan_type` sits
            // beside `commission_plan_type` above, and for the same
            // reason: the inherit chain (product -> category -> company
            // -> medical_package_default) must be resolved in exactly one
            // place, never duplicated client-side.
            //
            // TASK-132 deliberately left this off, arguing no consumer
            // needed it before the admin editor existed. That turned out
            // to be the thing that made the feature inert: the admin
            // product form cannot honestly offer an "inherit" option
            // without being able to say what inherit currently MEANS, so
            // ag-ui had nothing to render.
            //
            // Cost note: resolving walks up to three scopes. The lookups
            // are shared per request AND memoised by (own template id,
            // category id, company id) inside PipelineTemplateResolver, so
            // a paginated catalogue whose products all inherit costs ONE
            // resolution for the whole page, not one per row.
            //
            // Null only when resolution fails closed (a company with no
            // templates at all — see PipelineTemplateResolver's docblock);
            // ag-ui must treat null as "misconfigured", not as "none".
            'effective_pipeline_template' => (function () use ($request) {
                $template = RequestScopedService::get($request, PipelineTemplateResolver::class)
                    ->resolveForProduct($this->resource);

                // loadMissing, not load: the resolver hands back the SAME
                // model instance for every product that resolves the same
                // way, so load() would re-query its stages once per row
                // and undo the memo.
                return $template ? new PipelineTemplateResource($template->loadMissing('stages')) : null;
            })(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
