<?php

namespace Database\Seeders;

use App\Enums\CommissionRateType;
use App\Models\Brand;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

// DEV-ONLY seed data for the Product Catalog domain (ERD-001 rev. 3).
//
// cert_tiers: the 3 tier keys/names are taken verbatim from CLAUDE.md
// §2 ("Basic (mandatory) -> Intermediate -> High") — not invented.
//
// products: the 8,900 THB / 9,900 THB price points are taken verbatim
// from CLAUDE.md §2 ("Currently 8,900 THB and 9,900 THB tiers") — real
// values, not placeholders. Everything else about the products (name,
// brand, category, description) IS a placeholder, since CLAUDE.md marks
// "clinical details" as not yet finalized (BR-7).
//
// commission_rules.rate_value: PLACEHOLDER — CLAUDE.md explicitly says
// "Actual rates live in the commission_rules config table — never
// hardcode numbers" (BR-2) and marks the exact % as "to be agreed"
// (BR-7). The values below (3% / 5% / 8%) exist only so the Commission
// domain has something non-empty to build and test against; they must
// be replaced with real figures before this goes anywhere near
// production, and are marked `// TODO: CONFIRM (BR-7)` for that reason.
//
// Idempotent (firstOrCreate throughout) — safe to run more than once
// against a database that already has this data (`php artisan db:seed`
// without `:fresh`), same reasoning as DatabaseSeeder.
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $thaiLife = Company::where('slug', 'thai-life')->firstOrFail();

        $basic = CertTier::firstOrCreate(['key' => 'basic'], [
            'name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true,
        ]);
        $intermediate = CertTier::firstOrCreate(['key' => 'intermediate'], [
            'name' => 'Intermediate', 'sort_order' => 2, 'is_mandatory' => false,
        ]);
        $high = CertTier::firstOrCreate(['key' => 'high'], [
            'name' => 'High', 'sort_order' => 3, 'is_mandatory' => false,
        ]);

        $brand = Brand::firstOrCreate(
            ['company_id' => $thaiLife->id, 'name' => 'Thai Life Wellness'], // TODO: CONFIRM (BR-7) — placeholder brand name
            ['is_active' => true],
        );

        $category = ProductCategory::firstOrCreate(
            ['company_id' => $thaiLife->id, 'name' => 'Annual Health Package'], // TODO: CONFIRM (BR-7) — placeholder category name
            ['sort_order' => 1, 'is_active' => true],
        );

        $standard = Product::firstOrCreate(
            ['company_id' => $thaiLife->id, 'brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'Standard Package'],
            [
                'price_satang' => 890000, // 8,900 THB — CLAUDE.md §2, real value
                'description' => 'Placeholder description — clinical package details not yet finalized (BR-7).',
                'is_active' => true,
            ],
        );

        $premium = Product::firstOrCreate(
            ['company_id' => $thaiLife->id, 'brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'Premium Package'],
            [
                'price_satang' => 990000, // 9,900 THB — CLAUDE.md §2, real value
                'description' => 'Placeholder description — clinical package details not yet finalized (BR-7).',
                'is_active' => true,
            ],
        );

        foreach ([$standard, $premium] as $product) {
            foreach ([$basic, $intermediate, $high] as $tier) {
                CommissionRule::firstOrCreate(
                    ['company_id' => $thaiLife->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id],
                    [
                        'rate_type' => CommissionRateType::Percentage,
                        // TODO: CONFIRM (BR-7) — placeholder basis points (300=3%, 500=5%, 800=8%)
                        'rate_value' => match ($tier->key) {
                            'basic' => 300,
                            'intermediate' => 500,
                            'high' => 800,
                        },
                        'effective_from' => now()->startOfYear()->toDateString(),
                        'effective_to' => null,
                    ],
                );
            }
        }
    }
}
