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
}
