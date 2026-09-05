<?php

namespace App\Console\Commands;

use App\Models\CatalogBrand;
use App\Models\CatalogCategory;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Models\Scopes\TenantScope;
use App\Services\Catalog\ProductCatalogLinkService;
use App\Services\Catalog\ProductCatalogPropagationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-251 — `php artisan catalog:adopt-products`.
 *
 * Moves the products that already exist into the shared catalog, so the
 * "สินค้าใช้ร่วมกันทุกบริษัท" rule applies to the products this business
 * actually sells and not only to ones created from today onward.
 *
 * ── WHY A COMMAND AND NOT A MIGRATION ──
 *
 * ADR-036 §7 says exactly this: "no automatic detection/merge of same-named
 * products across companies — merging is a human decision". A migration runs
 * itself, on every environment, during a deploy nobody is watching, and it
 * would decide on that human's behalf. A command is run by a person who has
 * read what it will do, can see it first with --dry-run, and can stop.
 *
 * The human's own words when approving this ("แก้ให้อยู่ในรูปแบบใหม่ไม่เป็นไร
 * อยู่ในช่วง Setup") are why it exists at all — the system is in setup, so the
 * handful of existing products may be adopted rather than left as a permanent
 * exception.
 *
 * ── WHAT IT DOES, PER STANDALONE PRODUCT ──
 *
 *   1. mirrors its brand and category into the GLOBAL catalog_brands /
 *      catalog_categories by name (created only if absent);
 *   2. creates a catalog item carrying its name/description/spec, with
 *      default_price_satang = THIS product's current price — the only
 *      defensible default, since it is the one price a human actually chose
 *      for this product (BR-7: nothing is invented here);
 *   3. links the original product to it — its own price, commission rules,
 *      media, orders and ledger rows are untouched, and it stays exactly as
 *      active as it was;
 *   4. gives every OTHER company a disabled listing at that same price.
 *
 * ── WHAT IT REFUSES TO DO ──
 *
 * If a catalog item with the same name already exists, the product is SKIPPED
 * and reported. That is ADR-036 §7's rule with teeth: two products sharing a
 * name are not proof of one product, and quietly merging Thai Life's "Vital
 * Blueprint" with another tenant's would fuse two independent price/commission
 * histories on a guess. Linking those is a per-pair human decision, made in
 * the admin screen.
 *
 * Nothing is ever deleted, nothing already linked is touched, and re-running
 * it is a no-op — the second run reports "already in the catalog" for every
 * product it adopted on the first.
 */
class AdoptProductsIntoCatalogCommand extends Command
{
    protected $signature = 'catalog:adopt-products
        {--dry-run : show what would happen and change nothing}
        {--company= : only adopt products belonging to this company id}';

    protected $description = 'Adopt existing standalone products into the shared cross-company catalog (TASK-251).';

    public function handle(
        ProductCatalogLinkService $linkService,
        ProductCatalogPropagationService $propagation,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        /*
         * TenantScope off: this command has no authenticated user, and the
         * whole point is to see every company's products. withTrashed is
         * deliberately NOT used — a soft-deleted product is one somebody
         * removed, and adopting it would put it back in front of everybody.
         */
        $query = Product::withoutGlobalScope(TenantScope::class)
            ->with(['brand', 'category', 'company'])
            ->whereNull('catalog_item_id');

        if ($this->option('company') !== null) {
            $query->where('company_id', (int) $this->option('company'));
        }

        $products = $query->orderBy('company_id')->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->info('ไม่มีสินค้าที่ยังไม่ได้เชื่อมกับแคตตาล็อกกลาง — ไม่ต้องทำอะไร');

            return self::SUCCESS;
        }

        $this->line($dryRun
            ? "DRY RUN — จะไม่มีการเปลี่ยนแปลงใด ๆ ({$products->count()} สินค้า)"
            : "กำลังย้ายสินค้า {$products->count()} รายการเข้าแคตตาล็อกกลาง");
        $this->newLine();

        $adopted = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $name = (string) $product->name;
            $companyName = $product->company?->name ?? "company #{$product->company_id}";

            $clash = ProductCatalogItem::where('name', $name)->first();

            if ($clash !== null) {
                /*
                 * ADR-036 §7. The safe half of the rule: a name is not an
                 * identity. The human links these by hand if they really are
                 * the same product — and if they are not, this skip is what
                 * kept two unrelated products from sharing one price history.
                 */
                $this->warn("  ข้าม  {$name} ({$companyName}) — มีรายการชื่อนี้ในแคตตาล็อกกลางแล้ว (#{$clash->id}) ต้องให้คนตัดสินใจว่าเป็นสินค้าเดียวกันหรือไม่");
                $skipped++;

                continue;
            }

            if ($product->brand === null || $product->category === null) {
                // Every standalone product has both (NOT NULL until ADR-036
                // relaxed the column for LINKED rows only), so this is a data
                // repair job, not something to paper over with a guess.
                $this->warn("  ข้าม  {$name} ({$companyName}) — ไม่มีแบรนด์หรือหมวดหมู่");
                $skipped++;

                continue;
            }

            $priceBaht = number_format($product->price_satang / 100, 2);

            if ($dryRun) {
                $this->line("  จะย้าย  {$name} ({$companyName}) · ราคาเริ่มต้น {$priceBaht} บาท · บริษัทอื่นจะได้สำเนาแบบ<ปิดการใช้งาน>");
                $adopted++;

                continue;
            }

            $created = DB::transaction(function () use ($product, $linkService, $propagation) {
                $catalogItem = ProductCatalogItem::create([
                    'catalog_brand_id' => CatalogBrand::firstOrCreate(
                        ['name' => $product->brand->name],
                        ['is_active' => true],
                    )->id,
                    'catalog_category_id' => CatalogCategory::firstOrCreate(
                        ['name' => $product->category->name],
                        ['is_active' => true, 'sort_order' => 0],
                    )->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'spec_description' => $product->spec_description,
                    // BR-7 — the price this product is ALREADY sold at. The
                    // one number here that nobody has to invent.
                    'default_price_satang' => $product->price_satang,
                    'is_active' => true,
                ]);

                // The original keeps its price, its commission rules, its
                // media and its is_active exactly as they are; link() only
                // moves the identity columns.
                $linkService->link($product, $catalogItem);

                return $propagation->propagateItemToAllCompanies($catalogItem);
            });

            // The product's own company already had it, so it is not in the list.
            $others = count($created);
            $this->info("  ย้ายแล้ว  {$name} ({$companyName}) · ราคาเริ่มต้น {$priceBaht} บาท · เพิ่มให้อีก {$others} บริษัท (ปิดการใช้งานไว้)");
            $adopted++;
        }

        $this->newLine();
        $this->line($dryRun
            ? "สรุป (DRY RUN): จะย้าย {$adopted} · ข้าม {$skipped}"
            : "สรุป: ย้ายแล้ว {$adopted} · ข้าม {$skipped}");

        if (! $dryRun && $adopted > 0) {
            $this->newLine();
            $this->comment('สินค้าที่เพิ่มให้บริษัทอื่นถูก "ปิดการใช้งาน" ไว้ทั้งหมด — แต่ละบริษัทเปิดเองเมื่อพร้อมขาย และตั้งราคาของตัวเองได้');
        }

        return self::SUCCESS;
    }
}
