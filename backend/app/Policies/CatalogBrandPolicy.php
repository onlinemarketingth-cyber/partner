<?php

namespace App\Policies;

use App\Models\CatalogBrand;
use App\Models\User;

// ADR-036 — shared/global catalog data, no company_id to scope by.
// Readable by any authenticated user (a Company Admin/Agent needs it to
// display a catalog-linked product they don't own), writable by Super
// Admin only — this is the whole governance point of ADR-036's decision
// table ("เนื้อหากลาง...ได้เฉพาะสิทธิ์ super admin").
class CatalogBrandPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CatalogBrand $catalogBrand): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, CatalogBrand $catalogBrand): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, CatalogBrand $catalogBrand): bool
    {
        return $user->isSuperAdmin();
    }
}
