<?php

namespace App\Services\Engagement;

use App\Models\RewardItem;
use App\Models\User;

// Reward catalog CRUD. Same "own company or platform default" forcing
// pattern as BadgeService::create().
class RewardItemService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): RewardItem
    {
        $data['company_id'] = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        return RewardItem::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RewardItem $rewardItem, array $data): RewardItem
    {
        $rewardItem->update($data);

        return $rewardItem;
    }

    public function delete(RewardItem $rewardItem): void
    {
        $rewardItem->delete();
    }
}
