<?php

namespace App\Policies;

use App\Models\ProductCatalogItem;
use App\Models\User;

// Mirrors CatalogBrandPolicy — see its docblock for the reasoning. Also
// the single authorization gate this Service layer checks before writing
// a catalog item's media (ProductCatalogMedia) or specs
// (ProductCatalogSpec) rows, since neither of those has its own Policy —
// they're always written "through" their parent catalog item, exactly
// like ProductMedia/ProductSpec are authorized through ProductPolicy
// today.
class ProductCatalogItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductCatalogItem $productCatalogItem): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, ProductCatalogItem $productCatalogItem): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, ProductCatalogItem $productCatalogItem): bool
    {
        return $user->isSuperAdmin();
    }
}
