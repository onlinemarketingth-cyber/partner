<?php

namespace App\Services\Commission;

use App\Models\CommissionBinarySetting;

// ADR-011/TASK-029 — same "one settings row per company, upsert-style"
// shape as VideoProcessingSettingService (ADR-007), except there is no
// platform-wide fallback here: unlike video compression limits, a
// company simply has NO Binary settings until a Company Admin actually
// configures one (BinaryCommissionService::runDueCycles() only
// processes companies with a settings row at all — see its own
// docblock). forCompany() returning null is a legitimate, expected
// state, not an error.
class CommissionBinarySettingService
{
    public function forCompany(int $companyId): ?CommissionBinarySetting
    {
        return CommissionBinarySetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(int $companyId, array $data): CommissionBinarySetting
    {
        // BR-6/§5 — see AgentRankSettingService::upsert() for why this must
        // be stripped: $data may still carry a client-supplied company_id,
        // which would otherwise overwrite the server-resolved match key via
        // updateOrCreate()'s fill(), redirecting the write to another tenant.
        unset($data['company_id']);

        return CommissionBinarySetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
