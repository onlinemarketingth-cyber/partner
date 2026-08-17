<?php

namespace App\Services\Commission;

use App\Models\CommissionGenerationSetting;

// ADR-011/TASK-031 — same singleton shape as CommissionMatrixSettingService.
class CommissionGenerationSettingService
{
    public function forCompany(int $companyId): ?CommissionGenerationSetting
    {
        return CommissionGenerationSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(int $companyId, array $data): CommissionGenerationSetting
    {
        // BR-6/§5 — see AgentRankSettingService::upsert() for why this must
        // be stripped: $data may still carry a client-supplied company_id,
        // which would otherwise overwrite the server-resolved match key via
        // updateOrCreate()'s fill(), redirecting the write to another tenant.
        unset($data['company_id']);

        return CommissionGenerationSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
