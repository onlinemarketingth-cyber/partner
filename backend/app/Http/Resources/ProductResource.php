<?php

namespace App\Http\Resources;

use App\Enums\ProductMediaPurpose;
use App\Enums\ProductMediaType;
use App\Models\CommissionRule;
use App\Models\ProductMedia;
use App\Services\Catalog\ProductPricingService;
use App\Services\Pipeline\PipelineTemplateResolver;
use App\Support\CompanyScopeFilter;
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
            // ADR-036 §2/§3 (TASK-212) — null = standalone (today's
            // behavior, every existing row). When set, 'name'/'brand'/
            // 'category'/'description'/'spec_description' below are the
            // RESOLVED (effective_) values from the shared catalog item,
            // never this product's own (now-vestigial) columns — see
            // Product::effectiveName() and its siblings. ag-ui never has
            // to know or care which source it came from; only
            // 'catalog_item_id' itself tells it whether editing those
            // fields here would even do anything (TASK-215: read-only
            // when set).
            'catalog_item_id' => $this->catalog_item_id,
            'brand' => $this->when(
                $this->catalog_item_id ? $this->relationLoaded('catalogItem') : $this->relationLoaded('brand'),
                fn () => $this->catalog_item_id
                    ? new CatalogBrandResource($this->catalogItem?->catalogBrand)
                    : new BrandResource($this->brand)
            ),
            'category' => $this->when(
                $this->catalog_item_id ? $this->relationLoaded('catalogItem') : $this->relationLoaded('category'),
                fn () => $this->catalog_item_id
                    ? new CatalogCategoryResource($this->catalogItem?->catalogCategory)
                    : new ProductCategoryResource($this->category)
            ),
            'name' => $this->effectiveName(),
            /*
             * TASK-254 / ADR-040 — `price_satang` is the row's own number: the
             * platform's central price for a shared product, the company's for
             * its own. It stays exactly as it was, because a Super Admin
             * editing the central price needs to see the central price.
             *
             * `effective_price_satang` is what the VIEWER's company actually
             * charges: their own override if they set one, the central price
             * if they did not, and the active promotion above either. Screens
             * that show a customer-facing number must read this one — the
             * difference between them is somebody's money.
             */
            'price_satang' => $this->price_satang,
            /*
             * 2026-09-12 — PV / commissionable value, or null when this
             * product has none. Exposed raw, with no fallback baked in:
             * the admin screen has to be able to tell "worth 0 PV" from
             * "nobody has set a PV", because only one of those is a
             * warning. The fallback that DOES exist lives in
             * CommissionBasisResolver, where the money is.
             */
            'pv_satang' => $this->pv_satang,
            'effective_price_satang' => RequestScopedService::get($request, ProductPricingService::class)
                ->effectivePriceSatang($this->resource, CompanyScopeFilter::contextCompanyId($request)),
            /*
             * TASK-256 — null means "this company has not set a price", which
             * is not the same as "its price happens to equal the central one".
             * The admin screen needs the difference: an inherited price moves
             * the next time a Super Admin edits the centre, and the row says
             * so. Deriving it from effective == central would call two
             * deliberate decisions one accident.
             */
            'own_price_satang' => RequestScopedService::get($request, ProductPricingService::class)
                ->ownPriceSatang($this->resource, CompanyScopeFilter::contextCompanyId($request)),
            'is_shared' => $this->isShared(),
            /*
             * Whether THIS viewer's company may sell it. For a shared product
             * that is their own switch (default off, no inheritance — see
             * Product::isSellableBy); for their own product it is simply
             * is_active. A screen that renders `is_active` for a shared
             * product would show the PLATFORM's state and read as the
             * company's.
             */
            'is_sellable_here' => $this->isSellableBy(CompanyScopeFilter::contextCompanyId($request)),
            'description' => $this->effectiveDescription(),
            'spec_description' => $this->effectiveSpecDescription(),
            'is_active' => $this->is_active,
            // TASK-056 P3 — only present when 'media' is eager-loaded
            // (ProductController::index()); never a raw storage path
            // (Section 5 rule 6), same controller-served thumbnail route
            // ProductMediaResource already uses.
            // Bug fix (2026-08-01, human-reported: storefront cards showing
            // placeholder for every product) — for an image with no
            // thumbnail_path, stream the image itself instead of showing
            // nothing. Same fallback pattern already used by
            // ProductShareView.vue/ClientsView.vue on the frontend.
            //
            // 2026-09-09 — that fallback used to be the ONLY branch that
            // ever ran for an image: thumbnail_path was written by
            // CompressUploadedVideo and nothing else, so every product card
            // in the system was quietly serving a full-resolution camera
            // photo. ImageThumbnailer now fills it at upload (and
            // `media:backfill-thumbnails` for everything uploaded before),
            // so the first branch is the normal case and the fallback is
            // left for the two rows it is genuinely right for: an image
            // already smaller than the thumbnail size, and one GD could
            // not read.
            // TASK-097 — resolution order is now cover-first:
            //   1. the primary COVER (รูปสินค้า) — what the admin chose
            //   2. any cover, if somehow none is flagged primary
            //   3. the old behaviour (primary anywhere, else first item)
            // Step 3 is kept ONLY as a fallback for products that have no
            // covers yet. Dropping it would blank the card for every
            // product whose photos still live in the detail gallery.
            'thumbnail_url' => $this->when($this->relationLoaded('media'), function () {
                $primary = $this->cardMedia();

                if (! $primary) {
                    return null;
                }

                if ($primary->thumbnail_path) {
                    return route('product-media.thumbnail', $primary->id);
                }

                return $primary->media_type === ProductMediaType::Image ? route('product-media.stream', $primary->id) : null;
            }),
            /*
             * 2026-09-09 (human: "หน้า frontend load รูปมาที่หลัง
             * ประสบการณ์ไม่ดี ค่อยทำให้ภาพชัดขึ้นเรื่อยๆ").
             *
             * A ~20px base64 copy of the card image, carried INSIDE this
             * response. `thumbnail_url` above is an authenticated stream:
             * the browser cannot paint it until it has fetched it, which is
             * the gap the human is describing. This has nothing to fetch,
             * so the blurred shape of every product is on screen in the
             * first paint and sharpens when the real file lands.
             *
             * Null is entirely normal (a product with no photos, or one
             * whose card image is a video) and the frontend falls back to
             * the plain placeholder box it has always shown.
             */
            'thumbnail_placeholder' => $this->when(
                $this->relationLoaded('media'),
                fn () => $this->cardMedia()?->placeholder,
            ),
            // ADR-011/TASK-027 — commission_plan_type is the product's OWN
            // override (null = inheriting); effective_plan_type is always
            // resolved (Product::effectivePlanType()) so ag-ui never has
            // to duplicate the inherit-fallback logic client-side.
            'commission_plan_type' => $this->commission_plan_type?->value,
            // TASK-253 / ADR-040 — the VIEWER's company answers for a
            // platform-owned product: "which plan applies to this product"
            // has a different answer per company, and the person reading the
            // screen is asking about their own. A company-owned product
            // ignores the argument (it inherits from its own company).
            'effective_plan_type' => $this->effectivePlanType(CompanyScopeFilter::contextCompany($request))->value,
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
                    // The viewer's company, for the same reason
                    // effective_plan_type passes it: a shared product's
                    // journey is the asking company's (TASK-253 / ADR-040).
                    ->resolveForProduct($this->resource, CompanyScopeFilter::contextCompanyId($request));

                // loadMissing, not load: the resolver hands back the SAME
                // model instance for every product that resolves the same
                // way, so load() would re-query its stages once per row
                // and undo the memo.
                return $template ? new PipelineTemplateResource($template->loadMissing('stages')) : null;
            })(),
            /*
             * TASK-245 — the three questions the catalogue screen used to
             * answer for itself, asked of the rules that will actually decide.
             *
             * They are three because they genuinely differ, and the screen had
             * been treating them as one:
             *
             *   update/delete   Super-Admin-only for a PLATFORM product (its
             *                   identity is the platform's) AND for a
             *                   catalog-linked one (ADR-036 §5/§6), which
             *                   `is_shared` alone cannot see — a linked product
             *                   still belongs to its company.
             *
             *   set_commission_rule   Still NOT the same question, but since
             *                   2026-09-11 it answers false for every Company
             *                   Admin, on every product. The owner decided
             *                   commission rate configuration is Super Admin's
             *                   alone (a rate is money), which supersedes the
             *                   ADR-040 right this flag used to carry: a
             *                   Company Admin can no longer set their own
             *                   commission on a shared product, or on any
             *                   other. The catalog-linked clause below is now
             *                   only reachable for a Super Admin, and is kept
             *                   rather than simplified away so the ADR-036
             *                   §5/§6 rule stays visible if the rate decision
             *                   is ever revisited.
             *
             *                   It is still computed from CommissionRulePolicy
             *                   rather than from `update`, because the two
             *                   remain different rules and a screen deriving
             *                   one from the other would be wrong again the
             *                   moment either moves.
             */
            'permissions' => [
                'update' => (bool) $request->user()?->can('update', $this->resource),
                'delete' => (bool) $request->user()?->can('delete', $this->resource),
                'set_commission_rule' => (bool) $request->user()?->can('create', CommissionRule::class)
                    && ((bool) $request->user()?->isSuperAdmin() || $this->catalog_item_id === null),
                // 2026-09-09 — the bin tab needs this per row: a Company
                // Admin sees a platform row they cannot bring back, and a
                // button that 403s is worse than no button.
                'restore' => (bool) $request->user()?->can('restore', $this->resource),
            ],
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The one media row a product CARD represents.
     *
     * TASK-097 — cover-first, in this order:
     *   1. the primary COVER (รูปสินค้า) — what the admin chose
     *   2. any cover, if somehow none is flagged primary
     *   3. the old behaviour (primary anywhere, else first item)
     *
     * Step 3 is kept ONLY as a fallback for products that have no covers
     * yet. Dropping it would blank the card for every product whose photos
     * still live in the detail gallery.
     *
     * Extracted 2026-09-09 so `thumbnail_url` and `thumbnail_placeholder`
     * cannot pick different rows — a blur that sharpens into a DIFFERENT
     * photo is more unsettling than no blur at all.
     */
    private function cardMedia(): ?ProductMedia
    {
        $covers = $this->media->where('purpose', ProductMediaPurpose::Cover);

        return $covers->firstWhere('is_primary', true)
            ?? $covers->first()
            ?? $this->media->firstWhere('is_primary', true)
            ?? $this->media->first();
    }
}
