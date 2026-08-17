<?php

namespace App\Policies;

use App\Models\ProductRecommendationPin;
use App\Models\User;

// TASK-068 / ADR-020 row 4. Same shape as ProductCategoryPolicy — any
// authenticated company member may read (the pin list feeds
// GET /products/recommended, an Agent-Portal-facing endpoint), only
// Company Admin/Super Admin may write.
class ProductRecommendationPinPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductRecommendationPin $productRecommendationPin): bool
    {
        return $user->isSuperAdmin() || $user->company_id === $productRecommendationPin->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, ProductRecommendationPin $productRecommendationPin): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $productRecommendationPin->company_id);
    }

    public function delete(User $user, ProductRecommendationPin $productRecommendationPin): bool
    {
        return $this->update($user, $productRecommendationPin);
    }
}
