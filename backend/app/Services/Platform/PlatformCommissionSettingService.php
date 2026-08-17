<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\PlatformCommissionSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * TASK-196 §2.1/§2.2 — the ONE place the platform-wide commission-rate-cap
 * row (see PlatformCommissionSetting's own docblock for why it has no
 * company_id) is read or written. Gated at the Controller/Form-Request
 * layer by Ability::CommissionRateCapUpdate for writes (Super Admin only)
 * — this Service assumes that check already happened, same layering as
 * PlatformMailSettingService. Reads have no ability check at all (§2.2 —
 * any authenticated user may read, same shape as CertTierController).
 *
 * §2.3 — capBasisPoints() is ALSO the read path StoreCommissionRuleRequest/
 * UpdateCommissionRuleRequest call on every single commission-rule write,
 * not just from the settings screen, so it reuses the exact short-lived
 * Cache::remember() pattern PlatformMailSettingService::row() /
 * MailSettingsService::applyRuntimeConfig() already established for
 * TASK-190, rather than a fresh query per validation.
 */
class PlatformCommissionSettingService
{
    public const CACHE_KEY = 'platform_commission_settings.row';

    // §2.1 — "seeded with 3000 basis points = 30%". Only ever used as a
    // fallback if the seeded migration row is somehow missing (e.g. a test
    // that truncates the table without re-seeding) — the fail-closed
    // reasoning is the SAME cap either way, this is not a second place the
    // 30% value could drift from the migration's own seed.
    public const DEFAULT_CAP_BASIS_POINTS = 3000;

    /**
     * @return array{max_commission_rate_basis_points: int}
     */
    public function get(): array
    {
        return [
            'max_commission_rate_basis_points' => $this->capBasisPoints(),
        ];
    }

    /** §2.3 — the value StoreCommissionRuleRequest/UpdateCommissionRuleRequest validate against. */
    public function capBasisPoints(): int
    {
        return $this->row()?->max_commission_rate_basis_points ?? self::DEFAULT_CAP_BASIS_POINTS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data, User $actor): PlatformCommissionSetting
    {
        $settings = PlatformCommissionSetting::query()->first() ?? new PlatformCommissionSetting;

        $oldValues = $this->auditableFields($settings);

        $settings->fill($data);
        $settings->save();

        // Section 6 / CLAUDE.md §8 rule 5 — "record every action that
        // affects money [or] commission." The cap is exactly that: it
        // gates every future commission-rule write. Shape copied from
        // PlatformMailSettingService::update()'s own AuditLog::create()
        // call.
        AuditLog::create([
            // No company_id — platform-level config, not a tenant action
            // (AuditLog::company_id is nullable for exactly this case, per
            // that Model's own docblock).
            'company_id' => null,
            'actor_user_id' => $actor->id,
            'action' => 'platform_commission_settings.updated',
            'auditable_type' => PlatformCommissionSetting::class,
            'auditable_id' => $settings->id,
            'old_values' => $oldValues,
            'new_values' => $this->auditableFields($settings),
            'ip_address' => request()?->ip(),
        ]);

        Cache::forget(self::CACHE_KEY);

        return $settings;
    }

    /**
     * §2.3 — briefly cached so the per-commission-rule-write validation
     * check does not add a query to every single create/update. update()
     * above forgets this key immediately, so a saved change is visible on
     * the very next request rather than waiting out the TTL — same
     * contract as PlatformMailSettingService::row().
     */
    private function row(): ?PlatformCommissionSetting
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => PlatformCommissionSetting::query()->first());
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableFields(PlatformCommissionSetting $settings): array
    {
        return [
            'max_commission_rate_basis_points' => $settings->max_commission_rate_basis_points,
        ];
    }
}
