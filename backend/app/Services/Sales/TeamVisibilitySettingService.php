<?php

namespace App\Services\Sales;

use App\Enums\TeamVisibilityLevel;
use App\Models\TeamVisibilitySetting;

/**
 * TASK-106 / ADR-024 §5 — BR-7: the team-visibility level is admin-editable
 * per company, never hardcoded. forCompany() is the ONE place every consumer
 * (TeamVisibilitySettingController and, via DownlineService::resolveLevel(),
 * every TASK-107 endpoint) reads this config from, so the fail-closed
 * fallback below never has to be duplicated. Mirrors
 * VideoProcessingSettingService / AnnouncementSettingService.
 */
class TeamVisibilitySettingService
{
    /**
     * Always returns a value, never null — the caller must not have to
     * distinguish "not configured" from "configured", because getting that
     * distinction wrong is exactly how a tenant ends up failing OPEN.
     *
     * @return array{client_visibility_level: string, is_enabled: bool}
     */
    public function forCompany(?int $companyId): array
    {
        if ($companyId !== null) {
            $override = TeamVisibilitySetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
            if ($override) {
                // TASK-111 (D5) — read the RAW attribute, not the enum cast.
                //
                // WHY: DownlineService::resolveLevel() is documented to
                // degrade an unrecognised stored value to the safe level via
                // `tryFrom(...) ?? default()` — "a hand-edited row, a
                // half-rolled-back migration ... instead of throwing on a hot
                // path". That arm was unreachable: Eloquent's enum cast uses
                // BackedEnum::from(), which throws a ValueError the moment
                // ->client_visibility_level is touched, so a single bad row
                // 500'd the whole team screen for that tenant instead of
                // failing closed. Reading the raw attribute keeps the
                // validation where the fallback lives (resolveLevel), which
                // is the only place that can express "unknown => counts_only".
                $raw = $override->getAttributes()['client_visibility_level'] ?? null;

                return [
                    'client_visibility_level' => is_string($raw) && $raw !== ''
                        ? $raw
                        : TeamVisibilityLevel::default()->value,
                    'is_enabled' => (bool) $override->is_enabled,
                ];
            }
        }

        // ADR-024 §5 — an unconfigured tenant must fail closed, not open.
        // There is deliberately no config/*.php platform default to widen
        // this for every tenant at once (see the migration docblock).
        return [
            'client_visibility_level' => TeamVisibilityLevel::default()->value,
            'is_enabled' => true,
        ];
    }

    /**
     * @param  array{client_visibility_level?: string, is_enabled?: bool}  $data
     */
    public function upsert(int $companyId, array $data): TeamVisibilitySetting
    {
        // BR-6/§5 — $data comes from $request->validated() and may still
        // carry a client-supplied company_id (the Super Admin path in
        // UpdateTeamVisibilitySettingRequest validates it). updateOrCreate()
        // would otherwise overwrite the match-key company_id with that value
        // via fill(), redirecting the write into another tenant — a Company
        // Admin of A could then flip company B to full_file. Always use the
        // server-resolved $companyId. Same IDOR fix already applied to
        // VideoProcessingSettingService / AnnouncementSettingService /
        // AgentRankSettingService / CommissionBinarySettingService /
        // AffiliateAttributionSettingService.
        unset($data['company_id']);

        return TeamVisibilitySetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
