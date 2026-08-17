<?php

namespace App\Services\Gamification;

use App\Models\LevelThreshold;

// CRUD side for level_thresholds config (BR-7 — Admin-editable, never
// hardcoded). Kept as its own thin Service, mirroring
// GamificationRuleService/CompanyService, rather than letting the
// Controller touch the Model directly (Section 7 layering).
class LevelThresholdService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LevelThreshold
    {
        return LevelThreshold::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LevelThreshold $levelThreshold, array $data): LevelThreshold
    {
        $levelThreshold->update($data);

        return $levelThreshold;
    }

    public function delete(LevelThreshold $levelThreshold): void
    {
        $levelThreshold->delete();
    }
}
