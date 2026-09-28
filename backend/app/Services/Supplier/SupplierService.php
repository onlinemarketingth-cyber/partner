<?php

namespace App\Services\Supplier;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 2026-09-27 — ADR-048. Creating and editing a supplier's deal.
 *
 * Moved out of SupplierController for two reasons the controller could not
 * carry:
 *
 * ── 1. AUDIT (CLAUDE.md §6) ──
 *
 * GP, the release trigger, withholding tax and the bank account decide what a
 * supplier is paid and where it goes. Every change is logged with old → new.
 *
 * ── 2. A LIVE DEAL CANNOT BE LEFT HALF-SET ──
 *
 * Clearing GP or the trigger while the supplier has products on sale used to
 * return 200 — and the next customer payment for one of those products then
 * failed inside the payment transaction (SupplierSettlementService refuses to
 * invent terms, BR-7). The order stayed unpaid and its commission never fired,
 * including payments confirmed by a gateway webhook. The refusal belongs here,
 * at the edit, where the person making it can see why.
 */
class SupplierService
{
    /** The fields whose changes are money and are audited. */
    private const AUDITED = [
        'gp_mode', 'gp_value', 'release_trigger', 'wht_rate',
        'payout_bank_name', 'payout_bank_account_number', 'payout_bank_account_name',
        'is_active',
    ];

    public function create(array $data, User $actor): Supplier
    {
        return DB::transaction(function () use ($data, $actor) {
            $supplier = Supplier::create([
                ...$data,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit($supplier, $actor, 'supplier.created', null, $this->audited($supplier));

            return $supplier;
        });
    }

    public function update(Supplier $supplier, array $data, User $actor): Supplier
    {
        return DB::transaction(function () use ($supplier, $data, $actor) {
            $supplier = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $before = $this->audited($supplier);

            $supplier->fill($data);
            $this->assertLiveProductsStaySellable($supplier);
            $supplier->save();

            $after = $this->audited($supplier);
            $changed = array_keys(array_diff_assoc(
                array_map(fn ($v) => json_encode($v), $after),
                array_map(fn ($v) => json_encode($v), $before),
            ));

            if ($changed !== []) {
                $this->audit(
                    $supplier,
                    $actor,
                    'supplier.updated',
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)),
                );
            }

            return $supplier->fresh();
        });
    }

    /**
     * Refuse an edit that would leave an on-sale product with no terms.
     *
     * The trigger is always the supplier's (products have no trigger of their
     * own); GP may come from the product's override instead.
     */
    private function assertLiveProductsStaySellable(Supplier $supplier): void
    {
        $live = Product::withoutGlobalScopes()
            ->where('supplier_id', $supplier->id)
            ->where('is_active', true);

        if ($supplier->release_trigger === null && (clone $live)->exists()) {
            throw ValidationException::withMessages([
                'release_trigger' => 'คู่ค้ารายนี้ยังมีสินค้าเปิดขายอยู่ จึงล้างจังหวะการเบิกไม่ได้ — ปิดขายสินค้าก่อน',
            ]);
        }

        if (($supplier->gp_mode === null || $supplier->gp_value === null)
            && (clone $live)->where(fn ($q) => $q->whereNull('supplier_gp_mode')->orWhereNull('supplier_gp_value'))->exists()) {
            throw ValidationException::withMessages([
                'gp_value' => 'คู่ค้ารายนี้ยังมีสินค้าเปิดขายที่ใช้ GP ของสัญญานี้ จึงล้าง GP ไม่ได้ — ตั้ง GP ที่ตัวสินค้าหรือปิดขายก่อน',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function audited(Supplier $supplier): array
    {
        $values = [];

        foreach (self::AUDITED as $field) {
            $value = $supplier->getAttribute($field);
            $values[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $values;
    }

    private function audit(Supplier $supplier, User $actor, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            // A supplier is not a tenant.
            'company_id' => null,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()?->ip(),
        ]);
    }
}
