<?php

namespace App\Services\Supplier;

use App\Models\AuditLog;
use App\Models\SupplierPlatformSetting;
use App\Models\User;

/**
 * 2026-09-27 — ADR-048. Reads and writes the one supplier setting the
 * platform has: how many days after shipping a parcel counts as received.
 *
 * ── NO FALLBACK NUMBER ──
 *
 * If the row is missing, autoReceiveDays() answers null and the auto-confirm
 * sweep does nothing. Inventing a default here would release supplier money
 * on a schedule nobody set (BR-7); a sweep that waits is the safe failure.
 */
class SupplierPlatformSettingService
{
    public function autoReceiveDays(): ?int
    {
        $days = SupplierPlatformSetting::query()->value('auto_receive_days');

        return $days === null ? null : (int) $days;
    }

    /** @return array{auto_receive_days: ?int} */
    public function get(): array
    {
        return ['auto_receive_days' => $this->autoReceiveDays()];
    }

    public function update(int $autoReceiveDays, User $actor): SupplierPlatformSetting
    {
        $settings = SupplierPlatformSetting::query()->first() ?? new SupplierPlatformSetting;
        $old = $settings->exists ? ['auto_receive_days' => $settings->auto_receive_days] : null;

        $settings->auto_receive_days = $autoReceiveDays;
        $settings->save();

        // §6 — this decides when supplier money becomes payable.
        AuditLog::create([
            'company_id' => null,
            'actor_user_id' => $actor->id,
            'action' => 'supplier_settings.updated',
            'auditable_type' => SupplierPlatformSetting::class,
            'auditable_id' => $settings->id,
            'old_values' => $old,
            'new_values' => ['auto_receive_days' => $settings->auto_receive_days],
            'ip_address' => request()?->ip(),
        ]);

        return $settings;
    }
}
