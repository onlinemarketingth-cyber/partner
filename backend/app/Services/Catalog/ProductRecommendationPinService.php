<?php

namespace App\Services\Catalog;

use App\Models\ProductRecommendationPin;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// TASK-068 / ADR-020 row 4 — manual half of the hybrid recommended-row.
// Same "force company_id from the actor, never trust request input"
// shape as ProductCategoryService.
class ProductRecommendationPinService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): ProductRecommendationPin
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        return ProductRecommendationPin::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductRecommendationPin $pin, array $data): ProductRecommendationPin
    {
        $pin->update($data);

        return $pin;
    }
}
