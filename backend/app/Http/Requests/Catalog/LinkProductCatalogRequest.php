<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

// ADR-036 §3/§6 — POST /products/{product}/catalog-link. Authorization
// is the AND of two checks per ProductCatalogLinkService's own docblock:
// (1) can this actor manage THIS product at all (ProductPolicy::update —
// Company Admin's own company, or Super Admin), (2) is the target catalog
// item real (ProductCatalogItemPolicy::view is true for anyone, but the
// exists rule below is what actually catches a bad id). Linking itself —
// deciding WHETHER to allow it — is deliberately still gated to Super
// Admin only at the Controller (see ProductCatalogLinkController), since
// ADR-036's decision table is explicit that only Super Admin may point a
// company's product at the shared catalog, not merely "whoever can edit
// this product."
class LinkProductCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product !== null
            && $this->user()->isSuperAdmin()
            && $this->user()->can('update', $product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catalog_item_id' => ['required', 'integer', 'exists:product_catalog_items,id'],
        ];
    }
}
