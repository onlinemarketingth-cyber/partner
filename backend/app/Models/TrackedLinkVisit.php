<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-232 — one opening of one tracked link.
 *
 * `$timestamps = false` because the table has `created_at` and no
 * `updated_at`: this is an append-only log, in the same shape as
 * audit_logs and voucher_redemptions. Eloquent would otherwise try to
 * write a column that does not exist.
 *
 * There is no `update()` path anywhere in the application and there should
 * never be one. If a visit is wrong, the right answer is another row
 * explaining why, not a quiet edit to the evidence.
 */
class TrackedLinkVisit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'tracked_link_id',
        'visited_at',
        'ip_hash',
        'user_agent',
        'referrer_host',
        'device_type',
        'is_unique',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'created_at' => 'datetime',
            'is_unique' => 'boolean',
        ];
    }

    /** @return BelongsTo<TrackedLink, $this> */
    public function trackedLink(): BelongsTo
    {
        return $this->belongsTo(TrackedLink::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
