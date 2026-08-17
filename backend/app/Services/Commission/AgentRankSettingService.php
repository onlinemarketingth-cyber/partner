<?php

namespace App\Services\Commission;

use App\Models\AgentRankSetting;

// ADR-011/TASK-031 — same "one settings row per company, upsert-style,
// no platform-wide fallback" shape as CommissionBinarySettingService/
// CommissionMatrixSettingService.
class AgentRankSettingService
{
    public function forCompany(int $companyId): ?AgentRankSetting
    {
        return AgentRankSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(int $companyId, array $data): AgentRankSetting
    {
        // BR-6/§5 — company_id is deliberately NOT taken from $data even
        // though it's fillable: $data comes straight from the request and
        // may still carry a client-supplied company_id (Super Admin path).
        // updateOrCreate() would otherwise overwrite the match-key company_id
        // with that value via fill(), letting a Company Admin redirect the
        // write to another tenant. Always use the server-resolved $companyId.
        unset($data['company_id']);

        return AgentRankSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
