<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-09 (human: "เวลาเพิ่มสินค้า หมวดหมู่ แบรนด์ขึ้นซ้อนกัน ต้องมีตัวเดียว
 * … เรื่องสินค้ากลางคุณทำให้จบ") — `php artisan catalog:tidy-taxonomy`.
 *
 * The leftovers of `catalog:promote-products`, cleared away.
 *
 * ── WHAT PROMOTION LEAVES BEHIND, AND WHY IT LEAVES IT ──
 *
 * Promoting a product repoints it at a PLATFORM brand and category of the same
 * name, creating them if absent, and deliberately does NOT touch the company's
 * own rows: at that moment it cannot know whether the company's other products
 * still use them, and a command that deleted a brand out from under a product
 * would be a far worse bug than a duplicate in a dropdown.
 *
 * Promote every product a company owns, though, and its own brand rows are
 * left behind with nothing pointing at them — and every product form from then
 * on offers "De La Lita" twice, once owned and once shared, with nothing on
 * screen to tell them apart. That is what the human was looking at.
 *
 * ── WHAT THIS REFUSES TO TOUCH ──
 *
 * A row is only removed when ALL of these hold:
 *
 *   1. it belongs to a company (a platform row is the thing we are keeping);
 *   2. a PLATFORM row with the same name exists — so nothing that was
 *      reachable before becomes unreachable, the choice simply stops being
 *      offered twice;
 *   3. NOTHING points at it. Products are counted INCLUDING soft-deleted ones,
 *      because a deleted product still carries the brand_id it was sold under
 *      and an admin restoring it must not find its brand gone. Categories are
 *      also checked against commission_rules, where a category-scoped rate
 *      lives (TASK-028).
 *
 * The deletion is SOFT (both models use SoftDeletes), so even the outcome this
 * is careful about is recoverable — `deleted_at` is set, the row and its id
 * stay exactly where they were.
 */
class TidyPromotedTaxonomyCommand extends Command
{
    protected $signature = 'catalog:tidy-taxonomy
        {--dry-run : show what would happen and change nothing}
        {--company= : only tidy rows owned by this company id}';

    protected $description = 'Remove company brands/categories left unused after promotion, where a platform one of the same name exists.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        if ($dryRun) {
            $this->warn('DRY RUN — จะไม่มีการเปลี่ยนแปลงใด ๆ');
        }

        $removed = 0;
        $kept = 0;

        $this->line('');
        $this->info('แบรนด์');
        [$r, $k] = $this->tidy(
            Brand::withoutGlobalScope(SharedOrTenantScope::class),
            $companyId,
            fn (Brand $brand) => $this->brandBlockers($brand),
            'brand.tidied_after_promotion',
            $dryRun,
        );
        $removed += $r;
        $kept += $k;

        $this->line('');
        $this->info('หมวดหมู่');
        [$r, $k] = $this->tidy(
            ProductCategory::withoutGlobalScope(SharedOrTenantScope::class),
            $companyId,
            fn (ProductCategory $category) => $this->categoryBlockers($category),
            'product_category.tidied_after_promotion',
            $dryRun,
        );
        $removed += $r;
        $kept += $k;

        $this->line('');
        $this->info($dryRun
            ? "สรุป (DRY RUN): จะลบ {$removed} · เก็บไว้ {$kept}"
            : "สรุป: ลบแล้ว {$removed} · เก็บไว้ {$kept}");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  callable(mixed): list<string>  $blockers
     * @return array{int, int}
     */
    private function tidy($query, ?int $companyId, callable $blockers, string $auditAction, bool $dryRun): array
    {
        $owned = (clone $query)->whereNotNull('company_id');

        if ($companyId !== null) {
            $owned->where('company_id', $companyId);
        }

        /*
         * The names the platform owns. Compared in PHP rather than with a
         * correlated subquery so the match is the SAME one promotion made —
         * `sharedBrandId()` looks a name up with a plain where, and a tidy that
         * matched more loosely (trimmed, case-folded) could delete a row that
         * promotion never replaced.
         */
        $platformNames = (clone $query)->whereNull('company_id')->pluck('name')->all();

        $removed = 0;
        $kept = 0;

        foreach ($owned->with('company')->orderBy('company_id')->orderBy('name')->get() as $row) {
            $owner = $row->company?->name ?? "company #{$row->company_id}";

            if (! in_array($row->name, $platformNames, true)) {
                $kept++;

                continue;
            }

            $reasons = $blockers($row);

            if ($reasons !== []) {
                $this->line("  เก็บไว้  {$row->name} ({$owner}) — ".implode(' · ', $reasons));
                $kept++;

                continue;
            }

            if ($dryRun) {
                $this->line("  จะลบ    {$row->name} ({$owner}) — ไม่มีอะไรใช้อยู่ และมีของกลางชื่อเดียวกันแล้ว");
                $removed++;

                continue;
            }

            DB::transaction(function () use ($row, $auditAction) {
                $row->delete(); // SoftDeletes — the row and its id stay.

                AuditLog::create([
                    'company_id' => $row->company_id,
                    'actor_user_id' => null,
                    'action' => $auditAction,
                    'auditable_type' => $row::class,
                    'auditable_id' => $row->id,
                    'old_values' => ['deleted_at' => null, 'name' => $row->name],
                    'new_values' => [
                        'deleted_at' => $row->deleted_at?->toIso8601String(),
                        'reason' => 'unused after catalog:promote-products; a platform row of the same name exists',
                        'source' => 'artisan catalog:tidy-taxonomy',
                    ],
                    'ip_address' => null,
                ]);
            });

            $this->line("  ลบแล้ว  {$row->name} ({$owner})");
            $removed++;
        }

        if ($removed === 0 && $kept === 0) {
            $this->line('  (ไม่มีรายการของบริษัทให้ตรวจ)');
        }

        return [$removed, $kept];
    }

    /** @return list<string> */
    private function brandBlockers(Brand $brand): array
    {
        // withTrashed: a deleted product still carries the brand_id it was
        // sold under, and restoring it must not find its brand gone.
        $products = Product::withoutGlobalScope(SharedOrTenantScope::class)
            ->withTrashed()
            ->where('brand_id', $brand->id)
            ->count();

        return $products > 0 ? ["ยังมีสินค้าใช้อยู่ {$products} รายการ"] : [];
    }

    /** @return list<string> */
    private function categoryBlockers(ProductCategory $category): array
    {
        $reasons = [];

        $products = Product::withoutGlobalScope(SharedOrTenantScope::class)
            ->withTrashed()
            ->where('category_id', $category->id)
            ->count();

        if ($products > 0) {
            $reasons[] = "ยังมีสินค้าใช้อยู่ {$products} รายการ";
        }

        // TASK-028 — a category-scoped commission rate points here, and losing
        // the category would make the rule stop matching silently.
        $rules = CommissionRule::withoutGlobalScope(TenantScope::class)
            ->where('product_category_id', $category->id)
            ->count();

        if ($rules > 0) {
            $reasons[] = "ยังมีกฎค่าคอมผูกอยู่ {$rules} กฎ";
        }

        return $reasons;
    }
}
