<?php

namespace App\Services\Engagement;

use App\Models\ProductPricePromotion;
use App\Models\User;

// Product-view IA item 2.3b. Same company_id-forcing pattern as
// AgentPromotionService::create() — product_id is always required here
// (unlike agent_promotions, there is no "applies to all products" case).
class ProductPricePromotionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): ProductPricePromotion
    {
        $data['company_id'] = $actor->isSuperAdmin() ? $data['company_id'] : $actor->company_id;
        $data['created_by'] = $actor->id;

        return ProductPricePromotion::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductPricePromotion $promotion, array $data): ProductPricePromotion
    {
        $promotion->update($data);

        return $promotion;
    }

    public function delete(ProductPricePromotion $promotion): void
    {
        $promotion->delete();
    }
}
