<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * DeletionGuard — TASK-091 (2026-08-03, human: "หากมีการเกิด fk แล้ว มี DATA
 * ที่เป็น fk แจ้งเตือนไม่ให้ลบ").
 *
 * WHY THIS IS NEEDED AT ALL
 * Every catalogue model here uses `SoftDeletes`, and a soft delete is an
 * UPDATE, not a DELETE — so the `restrictOnDelete` foreign keys on
 * `products.brand_id`, `products.category_id`, `commission_rules.product_id`
 * and friends NEVER FIRE. The database happily lets an admin hide a brand
 * that still has ten products attached; the products keep working, but the
 * catalogue is now referencing a row that no screen lists, which is how you
 * end up with "why does this product have no brand name" a month later.
 *
 * The FK protection therefore has to be re-implemented in application code.
 * This helper is that check, in one place, so every controller phrases the
 * refusal identically instead of each inventing its own message.
 *
 * Returns a 422 (ValidationException) rather than a 409: the Admin SPA
 * already surfaces Laravel's first field error verbatim for 422s
 * (see ApiError.extractMessage in frontend-admin/src/api/client.ts), so the
 * admin reads exactly what is blocking the delete and how many of them
 * there are — with no new frontend error-handling path.
 */
class DeletionGuard
{
    /**
     * Refuse the delete when anything still references this record.
     *
     * @param  array<string, int>  $blockers  Thai label => count of dependent rows
     */
    public static function ensureNoDependents(array $blockers): void
    {
        $found = array_filter($blockers, static fn (int $count): bool => $count > 0);

        if ($found === []) {
            return;
        }

        $parts = [];

        foreach ($found as $label => $count) {
            $parts[] = "{$label} {$count} รายการ";
        }

        // Keyed 'id' because the route-model-bound record is the subject of
        // the failure; there is no request field to blame.
        throw ValidationException::withMessages([
            'id' => 'ลบไม่ได้ เพราะยังมีข้อมูลอ้างอิงอยู่: '.implode(' · ', $parts)
                .' — กรุณาย้ายหรือลบข้อมูลเหล่านั้นก่อน',
        ]);
    }
}
