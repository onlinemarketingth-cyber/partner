<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Scopes\TenantScope;
use App\Services\Catalog\ProductCatalogLinkService;
use Illuminate\Console\Command;

/**
 * TASK-206 — repair products that were linked to a catalog item BEFORE the
 * link service stopped nulling brand_id/category_id.
 *
 * Why this needs a command and not just "re-link them by hand": a product
 * with a null category_id silently resolves the WRONG commission rule
 * (CommissionService::resolveCommissionRule() skips the category-scoped rung
 * — BR-2, and the result is written to an immutable ledger row, BR-4). There
 * is no error, no log line, and no way to spot it from the UI, so it has to
 * be swept for rather than noticed.
 *
 * Safe to run repeatedly: backfillLocalTaxonomy() only ever fills a null and
 * skips anything already set.
 */
class BackfillCatalogLinkedTaxonomyCommand extends Command
{
    protected $signature = 'catalog:backfill-linked-taxonomy {--dry-run : List what would change without writing anything}';

    protected $description = 'Give every catalog-linked product its own company brand/category again (TASK-206)';

    public function handle(ProductCatalogLinkService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // withoutGlobalScope(TenantScope) — a maintenance sweep across every
        // company, run from the console with no authenticated actor. Same
        // rationale as DispatchDueRenewalCommissions' own withoutGlobalScopes().
        $stranded = Product::withoutGlobalScope(TenantScope::class)
            ->whereNotNull('catalog_item_id')
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhereNull('category_id'))
            ->with('company')
            ->get();

        if ($stranded->isEmpty()) {
            $this->info('No catalog-linked product is missing its brand/category. Nothing to do.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d catalog-linked product(s) are missing a brand and/or category%s',
            $stranded->count(),
            $dryRun ? ' (dry run — nothing will be written):' : ':',
        ));

        $repaired = 0;
        $skipped = 0;

        foreach ($stranded as $product) {
            $label = sprintf(
                'product #%d (company: %s, catalog_item_id: %d) brand_id=%s category_id=%s',
                $product->id,
                $product->company?->name ?? '?',
                $product->catalog_item_id,
                $product->brand_id ?? 'NULL',
                $product->category_id ?? 'NULL',
            );

            if ($dryRun) {
                $this->line("  would fix  {$label}");
                $repaired++;

                continue;
            }

            if ($service->backfillLocalTaxonomy($product)) {
                $fresh = $product->fresh();
                $this->line(sprintf('  fixed      product #%d -> brand_id=%s category_id=%s', $product->id, $fresh->brand_id, $fresh->category_id));
                $repaired++;
            } else {
                // Only reachable when the catalog item itself is gone —
                // report it rather than pretending the row is healthy.
                $this->error("  SKIPPED    {$label} — its catalog item no longer exists; unlink this product manually");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run complete: {$repaired} product(s) would be repaired."
            : "Done: {$repaired} repaired, {$skipped} skipped.");

        if ($skipped > 0 && ! $dryRun) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
