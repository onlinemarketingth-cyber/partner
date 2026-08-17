<?php

namespace App\Services\Commission;

use App\Models\CommissionMatrixSetting;

// ADR-011/TASK-030 — same "one settings row per company, upsert-style,
// no platform-wide fallback" shape as CommissionBinarySettingService.
// A company simply has NO Matrix settings until a Company Admin
// configures one; MatrixCommissionService::place() refuses to place an
// agent until this row exists (see that method's own error message).
class CommissionMatrixSettingService
{
    public function forCompany(int $companyId): ?CommissionMatrixSetting
    {
        return CommissionMatrixSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(int $companyId, array $data): CommissionMatrixSetting
    {
        // BR-6/§5 — see AgentRankSettingService::upsert() for why this must
        // be stripped: $data may still carry a client-supplied company_id,
        // which would otherwise overwrite the server-resolved match key via
        // updateOrCreate()'s fill(), redirecting the write to another tenant.
        unset($data['company_id']);

        return CommissionMatrixSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
