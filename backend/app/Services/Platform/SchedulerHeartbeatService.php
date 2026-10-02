<?php

namespace App\Services\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * 2026-10-02 — "is the scheduler actually running?" as something a person
 * can see, not something discovered when a commission is wrong.
 *
 * Owner, choosing how to run cron on Hostinger: "ลอง hostinger ตั้งค่าแบบ
 * ทดสอบได้เร็วๆ ว่าทำงานได้ไหม" and, for this class, "เพิ่ม". A cron entry set
 * up there before "ทำงานไม่ค่อยสมบูรณ์" — and nine jobs depend on it: rank
 * recalculation, renewal commissions, agent notification emails, follow-up
 * reminders, binary matching, promotion credits, supplier auto-receipt and two
 * clean-ups. When it stops, nothing errors; promotions simply never happen.
 *
 * ── HOW ──
 *
 * routes/console.php schedules beat() every minute. Every `schedule:run`
 * therefore records "I ran at T" — so the timestamp proves the WHOLE chain
 * (cron fired → the right PHP started → Laravel booted → the schedule ran),
 * which is exactly the chain that broke last time.
 *
 * Stored in the cache store, which on this deployment is the database
 * (CACHE_STORE=database), so the web request reading it and the CLI process
 * writing it see the same value. A cache:clear wipes it; the next minute's
 * run writes it back, and until then the status reads "never ran" — the safe
 * direction to be wrong in.
 */
class SchedulerHeartbeatService
{
    public const CACHE_KEY = 'scheduler:last_heartbeat_at';

    /**
     * How long without a heartbeat before the admin is warned. Operational,
     * not a business value: the most frequent job runs every 5 minutes, and
     * a scheduler that missed two of those is not running. A cron entry set
     * to every 5 minutes on a host that refuses every-minute still stays
     * green under this.
     */
    public const STALE_AFTER_MINUTES = 10;

    public function beat(): void
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());
    }

    public function lastBeatAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /**
     * @return array{last_run_at: ?string, minutes_since_last_run: ?int, is_running: bool, stale_after_minutes: int}
     */
    public function status(): array
    {
        $last = $this->lastBeatAt();
        $minutes = $last === null ? null : (int) floor($last->diffInSeconds(now(), true) / 60);

        return [
            'last_run_at' => $last?->toIso8601String(),
            'minutes_since_last_run' => $minutes,
            'is_running' => $minutes !== null && $minutes < self::STALE_AFTER_MINUTES,
            'stale_after_minutes' => self::STALE_AFTER_MINUTES,
        ];
    }
}
