<?php

namespace App\Models;

use App\Enums\PipelineStage;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-4.3 audit trail — who, when, from-status -> to-status. Append-only.
 */
class PipelineStageLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'referral_id',
        'from_stage',
        'to_stage',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_stage' => PipelineStage::class,
            'to_stage' => PipelineStage::class,
            'changed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Referral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
