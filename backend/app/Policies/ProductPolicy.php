<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

// Mirrors BrandPolicy — Agent can browse (needed for Referral submission
// later), only Company Admin/Super Admin can manage.
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        /*
         * TASK-253 / ADR-040 — a PLATFORM-owned product (company_id null) is
         * readable by everyone, because every company sells it. Without this
         * branch the comparison below is `4 === null` for every Company
         * Admin: the row would be listed by SharedOrTenantScope and then
         * 403/404 on the way in, which is the most confusing possible
         * combination.
         */
        if ($product->isShared()) {
            return true;
        }

        return $user->isSuperAdmin() || $user->company_id === $product->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Product $product): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        /*
         * TASK-253 / ADR-040 §3 — reading a shared product is not owning it.
         * The identity of a platform product (name, description, spec, brand,
         * category, central price) is Super-Admin-only, exactly as ADR-036 §5
         * decided and the human confirmed again on 2026-09-05 ("Super Admin
         * เท่านั้น").
         *
         * A company's own price and on/off switch are NOT this row — they
         * live in company_product_settings, gated separately. So this refusal
         * costs a Company Admin nothing they were promised.
         */
        if ($product->isShared()) {
            return false;
        }

        // ADR-036 §5/§6 (human decision, ADR-036 decision table: "Super
        // Admin เป็นคนตั้ง...ราคา กับค่าคอมแยกบริษัท") — once a product is
        // linked to a shared catalog item, ALL writes to its row —
        // including the per-company fields that would otherwise be a
        // Company Admin's own to set, like price_satang — become
        // Super-Admin-only too, not just the shared identity content.
        // Company Admin gets read-only visibility on a linked product;
        // their own standalone (catalog_item_id = null) products are
        // completely unaffected.
        if ($product->catalog_item_id !== null) {
            return false;
        }

        return $user->isCompanyAdmin() && $user->company_id === $product->company_id;
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }

    /**
     * 2026-09-09 — bringing a hidden row back is the same authority as
     * hiding it, deliberately: anyone who could not delete it has no
     * business un-deleting it either, and for a platform-owned row that
     * means Super Admin alone (see update()).
     */
    public function restore(User $user, Product $product): bool
    {
        return $this->delete($user, $product);
    }
}
