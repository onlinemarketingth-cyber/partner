<?php

namespace App\Services\Gamification;

use App\Models\Badge;
use App\Models\User;

// CRUD side for Badge definitions (distinct from BadgeAutoAwardService,
// which only READS badges to decide who earns them). Same
// company_id-forcing pattern as GamificationRuleService::create().
class BadgeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Badge
    {
        $data['company_id'] = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;
        $data['condition_config'] = $data['condition_config'] ?? null;

        return Badge::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Badge $badge, array $data): Badge
    {
        $badge->update($data);

        return $badge;
    }

    public function delete(Badge $badge): void
    {
        $badge->delete();
    }
}
