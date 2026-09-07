<?php

namespace App\Services\Catalog;

use App\Models\AuditLog;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TASK-254 / ADR-040 — the one place a company's price and on/off switch for
 * a shared product is written.
 *
 * ── WHY IT IS AUDITED, EVERY TIME ──
 *
 * These two fields decide what a customer is charged and whether a company is
 * selling at all. Section 6 records what affects money; a price that changed
 * with nobody's name on it is exactly the row somebody needs six months later
 * when an order does not match a quote. The audit carries BOTH values —
 * old and new — because "the price is 8,900" answers a different question
 * from "the price was 9,900 and this person changed it".
 *
 * ── WHY updateOrCreate AND NOT create ──
 *
 * The unique index makes a second row impossible, so a blind create would
 * turn a routine second edit into a 500. The row's absence and its presence
 * mean the same thing to a reader — "this company has not decided yet" is a
 * null price, not a missing row — so creating one on first write is
 * bookkeeping, not a state change.
 */
class CompanyProductSettingService
{
    /**
     * @param  array{price_satang?: int|null, is_active?: bool}  $data
     */
    public function set(Product $product, int $companyId, array $data, ?User $actor = null): CompanyProductSetting
    {
        return DB::transaction(function () use ($product, $companyId, $data, $actor) {
            $setting = CompanyProductSetting::withoutGlobalScope(TenantScope::class)
                ->firstOrNew(['company_id' => $companyId, 'product_id' => $product->id]);

            $before = [
                'price_satang' => $setting->exists ? $setting->price_satang : null,
                'is_active' => $setting->exists ? $setting->is_active : false,
            ];

            /*
             * array_key_exists, not isset: `price_satang => null` is a real
             * instruction — "stop overriding, go back to the central price" —
             * and isset() would silently ignore it, leaving the old override
             * in place while the screen reported success.
             */
            if (array_key_exists('price_satang', $data)) {
                $setting->price_satang = $data['price_satang'];
            }

            if (array_key_exists('is_active', $data)) {
                $setting->is_active = (bool) $data['is_active'];
            }

            $setting->company_id = $companyId;
            $setting->product_id = $product->id;
            $setting->save();

            $after = ['price_satang' => $setting->price_satang, 'is_active' => $setting->is_active];

            if ($before !== $after) {
                AuditLog::create([
                    // The company whose money this is — not the actor's, who
                    // is a Super Admin with no company of their own.
                    'company_id' => $companyId,
                    'actor_user_id' => $actor?->id,
                    'action' => 'product.company_setting_updated',
                    'auditable_type' => Product::class,
                    'auditable_id' => $product->id,
                    'old_values' => $before,
                    'new_values' => $after + [
                        // The central price, so the row can still be read when
                        // the override is null and the number in `after` is
                        // therefore not the price anybody paid.
                        'product_price_satang' => (int) $product->price_satang,
                    ],
                    'ip_address' => request()?->ip(),
                ]);
            }

            return $setting;
        });
    }
}
