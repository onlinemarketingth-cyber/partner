<?php

namespace App\Services\Platform;

use App\Enums\DevicePlatform;
use App\Models\AppVersionPolicy;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 2026-10-02 — MOB-13. The one place the mobile app version policy is read
 * or written. Writes assume the Super Admin check already happened
 * (UpdateAppVersionPolicyRequest::authorize), same layering as
 * PlatformCommissionSettingService.
 *
 * ── WHY THE PUBLIC READ IS CACHED ──
 *
 * Every app launch asks, before login, so this is the most-called
 * unauthenticated endpoint the app has. Two tiny rows, cached for a minute
 * and forgotten on every write, so a saved change is live on the very next
 * request rather than after the TTL.
 *
 * A platform row that is somehow missing (a test that truncated the table)
 * reads as "no policy" — every field null — which blocks nobody. That is the
 * safe direction: an empty policy can at worst fail to force an update,
 * while an invented one could lock every agent out of the app.
 */
class AppVersionPolicyService
{
    public const CACHE_KEY = 'app_version_policies.all';

    /** @var list<string> */
    private const EDITABLE = ['min_supported_version', 'latest_version', 'store_url'];

    public function forPlatform(DevicePlatform $platform): AppVersionPolicy
    {
        return $this->all()->first(fn (AppVersionPolicy $p) => $p->platform === $platform)
            ?? new AppVersionPolicy(['platform' => $platform]);
    }

    /**
     * Both platforms, always in enum order, a blank one standing in for a
     * missing row.
     *
     * @return Collection<int, AppVersionPolicy>
     */
    public function all(): Collection
    {
        $rows = Cache::remember(self::CACHE_KEY, 60, fn () => AppVersionPolicy::query()->get());

        return collect(DevicePlatform::cases())->map(
            fn (DevicePlatform $platform) => $rows->first(fn (AppVersionPolicy $p) => $p->platform === $platform)
                ?? new AppVersionPolicy(['platform' => $platform]),
        );
    }

    /**
     * Only the keys PRESENT in $data change — an omitted key keeps its value,
     * an explicit null clears it.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(DevicePlatform $platform, array $data, User $actor): AppVersionPolicy
    {
        $policy = AppVersionPolicy::query()->firstOrNew(['platform' => $platform->value]);
        $old = $this->auditableFields($policy);

        $policy->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
        $policy->save();

        // §6 — a minimum version decides who can use the app at all, which is
        // an access decision; record who moved it and from what.
        AuditLog::create([
            'company_id' => null, // platform-level, not a tenant action
            'actor_user_id' => $actor->id,
            'action' => 'app_version_policy.updated',
            'auditable_type' => AppVersionPolicy::class,
            'auditable_id' => $policy->id,
            'old_values' => $old,
            'new_values' => $this->auditableFields($policy),
            'ip_address' => request()?->ip(),
        ]);

        Cache::forget(self::CACHE_KEY);

        return $policy;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableFields(AppVersionPolicy $policy): array
    {
        return [
            'platform' => $policy->platform?->value,
            'min_supported_version' => $policy->min_supported_version,
            'latest_version' => $policy->latest_version,
            'store_url' => $policy->store_url,
        ];
    }
}
