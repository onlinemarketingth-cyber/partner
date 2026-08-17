<?php

namespace App\Services\Referral;

use App\Models\AffiliateAttributionSetting;

// ADR-011/TASK-032 — same singleton shape as AgentRankSettingService/CommissionMatrixSettingService.
class AffiliateAttributionSettingService
{
    public function forCompany(int $companyId): ?AffiliateAttributionSetting
    {
        return AffiliateAttributionSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(int $companyId, array $data): AffiliateAttributionSetting
    {
        // BR-6/§5 — see AgentRankSettingService::upsert() for why this must
        // be stripped: $data may still carry a client-supplied company_id,
        // which would otherwise overwrite the server-resolved match key via
        // updateOrCreate()'s fill(), redirecting the write to another tenant.
        unset($data['company_id']);

        return AffiliateAttributionSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
