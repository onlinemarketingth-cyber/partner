<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-255 / ADR-040 §5 — `php artisan catalog:promote-products`.
 *
 * Turns a company's product into THE platform's product: one row, still the
 * same row, that every company can now sell at its own price.
 *
 * ── WHY THE ID NEVER CHANGES ──
 *
 * Fifteen tables point at `products.id`, two of them money records that must
 * never be rewritten (commission_ledger, BR-4). Promotion is an ownership
 * change on the row that is already there — `company_id` becomes NULL and
 * nothing else moves — so every referral, order, ledger row and commission
 * rule keeps pointing at exactly what it pointed at this morning. A command
 * that copied the product into a new "shared" row and left the old one behind
 * would be the copy model again, wearing a different name.
 *
 * ── WHAT THE ORIGINAL COMPANY EXPERIENCES: NOTHING ──
 *
 * Its price is now the CENTRAL price, so it keeps paying the same number
 * without an override. Its settings row carries `is_active` exactly as the
 * product was, so a product it was selling stays sold and one it had switched
 * off stays off. Every other company gets a settings row that is switched OFF
 * and has no price — they inherit the central price the day they turn it on,
 * and until then nothing about them changed either.
 *
 * ── WHAT IT REFUSES TO DO ──
 *
 * A category-scoped commission rule (`product_id IS NULL AND
 * product_category_id = X`, TASK-028) matches through the product's category.
 * Promotion repoints the product at a PLATFORM category — it must, since a
 * shared product cannot belong to one company's taxonomy — and that rule
 * would then stop matching, silently, sending the sale to the company default
 * rate and writing a wrong payout into an immutable ledger (BR-2/BR-4).
 *
 * So when such a rule exists, this command SKIPS the product and names the
 * rule. The fix is a human decision (re-scope the rule to the product, or
 * accept the company default), and it is not one a migration should take at
 * 3am on somebody's behalf.
 */
class PromoteProductsToPlatformCommand extends Command
{
    protected $signature = 'catalog:promote-products
        {--dry-run : show what would happen and change nothing}
        {--company= : only promote products owned by this company id}
        {--product= : only promote this one product id}';

    protected $description = 'Make existing company products platform-owned, so every company can sell them (TASK-255).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Product::withoutGlobalScope(SharedOrTenantScope::class)
            ->with(['company', 'brand', 'category'])
            ->whereNotNull('company_id');

        if ($this->option('company') !== null) {
            $query->where('company_id', (int) $this->option('company'));
        }

        if ($this->option('product') !== null) {
            $query->where('id', (int) $this->option('product'));
        }

        $products = $query->orderBy('company_id')->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->info('ไม่มีสินค้าที่ยังเป็นของบริษัทเดียว — ไม่ต้องทำอะไร');

            return self::SUCCESS;
        }

        $companyIds = Company::query()->pluck('id')->all();

        $this->line($dryRun
            ? "DRY RUN — จะไม่มีการเปลี่ยนแปลงใด ๆ ({$products->count()} สินค้า)"
            : "กำลังเลื่อนสินค้า {$products->count()} รายการเป็นสินค้ากลาง");
        $this->newLine();

        $promoted = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $owner = $product->company?->name ?? "company #{$product->company_id}";

            if ($product->brand === null || $product->category === null) {
                $this->warn("  ข้าม  {$product->name} ({$owner}) — ไม่มีแบรนด์หรือหมวดหมู่");
                $skipped++;

                continue;
            }

            $categoryRules = CommissionRule::withoutGlobalScope(TenantScope::class)
                ->whereNull('product_id')
                ->where('product_category_id', $product->category_id)
                ->count();

            if ($categoryRules > 0) {
                $this->warn("  ข้าม  {$product->name} ({$owner}) — มีกฎค่าคอมผูกกับหมวดหมู่ \"{$product->category->name}\" อยู่ {$categoryRules} กฎ · ถ้าเลื่อนตอนนี้ กฎจะเลิก match เงียบ ๆ แล้วไปใช้เรตกลางของบริษัทแทน · ต้องให้คนตัดสินใจก่อน (ผูกกฎกับสินค้าโดยตรง หรือยอมรับเรตกลาง)");
                $skipped++;

                continue;
            }

            $priceBaht = number_format($product->price_satang / 100, 2);
            $others = count($companyIds) - 1;

            if ($dryRun) {
                $this->line("  จะเลื่อน  {$product->name} ({$owner}) · ราคากลาง {$priceBaht} บาท · {$owner} ขายต่อได้เหมือนเดิม · อีก {$others} บริษัทได้สิทธิ์ขาย (ปิดไว้ รอเปิดเอง)");
                $promoted++;

                continue;
            }

            DB::transaction(function () use ($product, $companyIds) {
                $ownerCompanyId = (int) $product->company_id;
                $before = [
                    'company_id' => $ownerCompanyId,
                    'brand_id' => $product->brand_id,
                    'category_id' => $product->category_id,
                    'commission_plan_type' => $product->commission_plan_type?->value,
                    'pipeline_template_id' => $product->pipeline_template_id,
                ];

                $product->forceFill([
                    'company_id' => null,
                    'brand_id' => $this->sharedBrandId($product),
                    'category_id' => $this->sharedCategoryId($product),
                    /*
                     * The plan type has to become EXPLICIT. It was inheriting
                     * from the owning company; a moment from now there is no
                     * owning company to inherit from, and
                     * Product::effectivePlanType() throws rather than guess.
                     * Copying the company's current value preserves exactly
                     * today's behaviour for today's seller — it invents
                     * nothing, it writes down what was already true.
                     */
                    'commission_plan_type' => $product->commission_plan_type
                        ?? $product->company?->commission_plan_type,
                    /*
                     * A pipeline template belongs to ONE company, so a shared
                     * product cannot carry one. Cleared, which makes the
                     * journey resolve per company (category → company default,
                     * ADR-026 §3.3) — the right answer for every company
                     * including the original.
                     */
                    'pipeline_template_id' => null,
                ])->save();

                foreach ($companyIds as $companyId) {
                    CompanyProductSetting::withoutGlobalScope(TenantScope::class)->updateOrCreate(
                        ['company_id' => $companyId, 'product_id' => $product->id],
                        [
                            // No override: everyone starts on the central
                            // price, which IS the original company's price.
                            'price_satang' => null,
                            // The original company keeps selling exactly as
                            // before; everybody else starts switched off.
                            'is_active' => (int) $companyId === $ownerCompanyId ? (bool) $product->is_active : false,
                        ],
                    );
                }

                AuditLog::create([
                    // The company that owned it until a second ago — the one
                    // whose catalogue actually changed hands.
                    'company_id' => $ownerCompanyId,
                    'actor_user_id' => null,
                    'action' => 'product.promoted_to_platform',
                    'auditable_type' => Product::class,
                    'auditable_id' => $product->id,
                    'old_values' => $before,
                    'new_values' => [
                        'company_id' => null,
                        'brand_id' => $product->brand_id,
                        'category_id' => $product->category_id,
                        'commission_plan_type' => $product->commission_plan_type?->value,
                        'price_satang' => (int) $product->price_satang,
                        'companies_granted' => count($companyIds),
                        'source' => 'artisan catalog:promote-products',
                    ],
                    'ip_address' => null,
                ]);
            });

            $this->info("  เลื่อนแล้ว  {$product->name} · ราคากลาง {$priceBaht} บาท · {$owner} ขายต่อได้เหมือนเดิม · อีก {$others} บริษัทเปิดขายเองได้");
            $promoted++;
        }

        $this->newLine();
        $this->line($dryRun
            ? "สรุป (DRY RUN): จะเลื่อน {$promoted} · ข้าม {$skipped}"
            : "สรุป: เลื่อนแล้ว {$promoted} · ข้าม {$skipped}");

        if (! $dryRun && $promoted > 0) {
            $this->newLine();
            $this->comment('บริษัทอื่นยังขายไม่ได้จนกว่าจะเปิดเอง และจะใช้ราคากลางจนกว่าจะตั้งราคาของตัวเอง');
        }

        return self::SUCCESS;
    }

    /**
     * The platform's brand with this name, created if absent.
     *
     * By NAME, and the per-company row is left exactly where it is: other
     * products of that company still point at it, and deleting or moving it
     * would take them with it.
     */
    private function sharedBrandId(Product $product): int
    {
        return Brand::withoutGlobalScope(SharedOrTenantScope::class)
            ->whereNull('company_id')
            ->where('name', $product->brand->name)
            ->firstOr(fn () => Brand::withoutGlobalScope(SharedOrTenantScope::class)->create([
                'company_id' => null,
                'name' => $product->brand->name,
                'is_active' => true,
            ]))->id;
    }

    private function sharedCategoryId(Product $product): int
    {
        return ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
            ->whereNull('company_id')
            ->where('name', $product->category->name)
            ->firstOr(fn () => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)->create([
                'company_id' => null,
                'name' => $product->category->name,
                'is_active' => true,
                // Presentation only, and the platform's list is its own: a
                // mirrored category starts at the end with no icon rather
                // than inheriting one company's ordering.
                'sort_order' => 0,
            ]))->id;
    }
}
