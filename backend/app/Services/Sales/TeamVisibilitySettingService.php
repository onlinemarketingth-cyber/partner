<?php

namespace App\Services\Sales;

use App\Enums\RecruitPolicy;
use App\Enums\TeamVisibilityLevel;
use App\Models\AuditLog;
use App\Models\TeamVisibilitySetting;
use App\Models\User;

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
                    'recruit_policy' => $this->recruitPolicyFrom($override)->value,
                ];
            }
        }

        // ADR-024 §5 — an unconfigured tenant must fail closed, not open.
        // There is deliberately no config/*.php platform default to widen
        // this for every tenant at once (see the migration docblock).
        return [
            'client_visibility_level' => TeamVisibilityLevel::default()->value,
            'is_enabled' => true,
            'recruit_policy' => RecruitPolicy::default()->value,
        ];
    }

    /**
     * ADR-049 — who in this company may invite people into their team.
     *
     * Read with a raw fallback for the same reason as the visibility level
     * above: an unknown stored value must not 500 the registration path. It
     * degrades to Designated — the stricter rule — rather than opening
     * recruiting on a value nobody chose.
     */
    public function recruitPolicy(?int $companyId): RecruitPolicy
    {
        if ($companyId === null) {
            return RecruitPolicy::Designated;
        }

        $row = TeamVisibilitySetting::withoutGlobalScopes()->where('company_id', $companyId)->first();

        return $row ? $this->recruitPolicyFrom($row) : RecruitPolicy::default();
    }

    private function recruitPolicyFrom(TeamVisibilitySetting $row): RecruitPolicy
    {
        $raw = $row->getAttributes()['recruit_policy'] ?? null;

        if ($raw === null || $raw === '') {
            return RecruitPolicy::default();
        }

        return RecruitPolicy::tryFrom((string) $raw) ?? RecruitPolicy::Designated;
    }

    /**
     * @param  array{client_visibility_level?: string, is_enabled?: bool, recruit_policy?: string}  $data
     */
    public function upsert(int $companyId, array $data, ?User $actor = null): TeamVisibilitySetting
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

        $before = $this->recruitPolicy($companyId);

        $row = TeamVisibilitySetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );

        // §6 — who may bring people into the company is a permission.
        $after = $this->recruitPolicyFrom($row);
        if ($after !== $before && $actor !== null) {
            AuditLog::create([
                'company_id' => $companyId,
                'actor_user_id' => $actor->id,
                'action' => 'team_settings.recruit_policy_changed',
                'auditable_type' => TeamVisibilitySetting::class,
                'auditable_id' => $row->id,
                'old_values' => ['recruit_policy' => $before->value],
                'new_values' => ['recruit_policy' => $after->value],
                'ip_address' => request()?->ip(),
            ]);
        }

        return $row;
    }
}
