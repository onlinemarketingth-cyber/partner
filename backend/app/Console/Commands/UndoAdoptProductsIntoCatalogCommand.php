<?php

namespace App\Console\Commands;

use App\Models\AffiliateLink;
use App\Models\AgentPromotion;
use App\Models\CommissionLedger;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Models\ProductMedia;
use App\Models\ProductPricePromotion;
use App\Models\ProductRecommendationPin;
use App\Models\ProductSalesMaterial;
use App\Models\ProductShareLink;
use App\Models\ProductSpec;
use App\Models\Referral;
use App\Models\Scopes\TenantScope;
use App\Models\StorefrontBanner;
use App\Services\Catalog\ProductCatalogLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-252 — `php artisan catalog:undo-adopt-products`, the way back.
 *
 * ── WHY THIS EXISTS ──
 *
 * TASK-251 implemented "สินค้าใช้ร่วมกันทุกบริษัท" the way ADR-036 §3 had
 * specified it in August: one `products` row PER COMPANY, joined by a shared
 * catalog item. On 2026-09-05 the human looked at the result — eight rows
 * where there are four products — and said plainly that it is the wrong
 * model: a shared product should be ONE record that every company uses, with
 * only price/commission/on-off differing per company, "ไม่เพิ่มแบบ copy".
 *
 * They are right, and the copy model was my design, not theirs. The
 * structural fix (central row + per-company settings) is a multi-phase change
 * to the most safety-critical scope in this application, and it must not be
 * built on top of a production database carrying copies that will have to be
 * unpicked later. So this command puts production back exactly as it was
 * before `catalog:adopt-products` ran.
 *
 * ── WHAT "EXACTLY AS IT WAS" MEANS ──
 *
 *   • the copies handed to other companies are REMOVED — but only when they
 *     are provably untouched: no referral, no order-bearing ledger row, no
 *     commission rule, no media, no share link, nothing. A copy that somebody
 *     has already used is not a copy any more, and this command stops rather
 *     than deleting it;
 *   • the ORIGINAL product is unlinked and its name/description/spec are
 *     restored from the catalog item they were moved into (adoption cleared
 *     them; the catalog item is where they have been living since). Its id,
 *     price, commission rules, media, orders and ledger rows were never
 *     touched by adoption and are not touched now;
 *   • the catalog item, and any catalog brand/category left with nothing
 *     pointing at it, are deleted last.
 *
 * FORCE-DELETED, not soft-deleted, for the copies alone: a soft-deleted row
 * would still block the coming re-model (the propagation check treats a
 * trashed listing as "this company already had one") and would leave rows
 * that no screen shows and no human can explain.
 *
 * Anything this command cannot do cleanly it reports and skips. It is not a
 * cleanup tool — it is the reverse of one specific command, and it refuses
 * every case that is not exactly that.
 */
class UndoAdoptProductsIntoCatalogCommand extends Command
{
    protected $signature = 'catalog:undo-adopt-products
        {--dry-run : show what would happen and change nothing}';

    protected $description = 'Reverse catalog:adopt-products — unlink the originals and remove the untouched per-company copies (TASK-252).';

    public function handle(ProductCatalogLinkService $linkService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $items = ProductCatalogItem::with(['catalogBrand', 'catalogCategory'])->get();

        if ($items->isEmpty()) {
            $this->info('ไม่มีรายการในแคตตาล็อกกลาง — ไม่ต้องทำอะไร');

            return self::SUCCESS;
        }

        $this->line($dryRun
            ? "DRY RUN — จะไม่มีการเปลี่ยนแปลงใด ๆ ({$items->count()} รายการแคตตาล็อก)"
            : "กำลังย้อนกลับ {$items->count()} รายการแคตตาล็อก");
        $this->newLine();

        $reverted = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $linked = Product::withoutGlobalScope(TenantScope::class)
                ->withTrashed()
                ->where('catalog_item_id', $item->id)
                ->orderBy('id')
                ->get();

            if ($linked->isEmpty()) {
                // A catalog item created by hand and never linked to anything.
                // Not ours to delete: nobody adopted anything here.
                $this->warn("  ข้าม  {$item->name} — ไม่มีสินค้าผูกอยู่ (ไม่ได้มาจากคำสั่ง adopt)");
                $skipped++;

                continue;
            }

            /*
             * The ORIGINAL is the oldest linked row: adoption links the
             * product that already existed and only then creates the copies,
             * so id order is creation order. The alternative — "the one with
             * dependents" — would misidentify a product that simply has not
             * sold yet.
             */
            $original = $linked->first();
            $copies = $linked->skip(1);

            $used = $copies->filter(fn (Product $p) => $this->dependentCount($p) > 0);

            if ($used->isNotEmpty()) {
                /*
                 * Somebody sold, priced, or attached something to a copy in
                 * the few hours it existed. Deleting it would take real data
                 * with it, and this command's whole purpose is to be safe to
                 * run — so it stops on this item and leaves every part of it
                 * alone, including the original.
                 */
                $names = $used->map(fn (Product $p) => "company #{$p->company_id}")->implode(', ');
                $this->warn("  ข้าม  {$item->name} — สำเนาของ {$names} มีข้อมูลผูกอยู่แล้ว ต้องให้คนตรวจก่อน");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  จะย้อน  {$item->name} · คืนชื่อให้สินค้าเดิม (id {$original->id}, company #{$original->company_id}) · ลบสำเนา {$copies->count()} รายการ · ลบรายการแคตตาล็อก");
                $reverted++;

                continue;
            }

            DB::transaction(function () use ($item, $original, $copies, $linkService) {
                foreach ($copies as $copy) {
                    // forceDelete: see the class docblock — a trashed copy
                    // still counts as "this company has one" to the very code
                    // this rollback exists to clear the way for.
                    $copy->forceDelete();
                }

                /*
                 * The identity adoption moved INTO the catalog item, moved
                 * back. brand_id/category_id are already correct on the row
                 * (TASK-206 keeps them populated on link), so the local
                 * identity is complete and unlink()'s contract is satisfied.
                 */
                $linkService->unlink($original, [
                    'name' => $item->name,
                    'brand_id' => $original->brand_id,
                    'category_id' => $original->category_id,
                    'description' => $item->description,
                    'spec_description' => $item->spec_description,
                ]);

                $brand = $item->catalogBrand;
                $category = $item->catalogCategory;

                $item->forceDelete();

                // Only if nothing else points at them. A brand the human
                // created for another purpose stays.
                if ($brand !== null && $brand->catalogItems()->withTrashed()->count() === 0) {
                    $brand->forceDelete();
                }
                if ($category !== null && $category->catalogItems()->withTrashed()->count() === 0) {
                    $category->forceDelete();
                }
            });

            $this->info("  ย้อนแล้ว  {$item->name} · สินค้าเดิมกลับเป็นของบริษัทตัวเอง · ลบสำเนา {$copies->count()} รายการ");
            $reverted++;
        }

        $this->newLine();
        $this->line($dryRun
            ? "สรุป (DRY RUN): จะย้อน {$reverted} · ข้าม {$skipped}"
            : "สรุป: ย้อนแล้ว {$reverted} · ข้าม {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Everything that would be lost with this row.
     *
     * Deliberately wider than ProductController::destroy's four blockers:
     * that guard protects a human's explicit delete of THEIR product, where
     * a share link is an acceptable casualty. This one is deciding whether a
     * row is untouched enough to remove silently, so anything at all counts.
     */
    private function dependentCount(Product $product): int
    {
        $id = $product->id;

        return Referral::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + CommissionLedger::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + $product->commissionRules()->withoutGlobalScope(TenantScope::class)->count()
            + $product->modules()->withoutGlobalScope(TenantScope::class)->count()
            + ProductMedia::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + ProductSpec::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + ProductSalesMaterial::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + ProductShareLink::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + AffiliateLink::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + AgentPromotion::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + ProductPricePromotion::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + ProductRecommendationPin::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count()
            + StorefrontBanner::withoutGlobalScope(TenantScope::class)->where('product_id', $id)->count();
    }
}
