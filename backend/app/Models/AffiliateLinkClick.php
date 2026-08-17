<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-011 Section 4 (TASK-032) — one row per GET /l/{token} hit.
 * ip_hash is a hash, never a raw IP (PDPA — see AffiliateLinkClickService).
 */
class AffiliateLinkClick extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'link_id',
        'clicked_at',
        'ip_hash',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<AffiliateLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class, 'link_id');
    }
}
