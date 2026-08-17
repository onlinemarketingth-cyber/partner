<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-1 gate record. A `basic` row here unlocks SWS Referral/Pipeline for
 * an Agent. Append-only.
 */
class UserCertification extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'cert_tier_id',
        'passed_at',
        'exam_attempt_id',
    ];

    protected function casts(): array
    {
        return [
            'passed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CertTier, $this> */
    public function certTier(): BelongsTo
    {
        return $this->belongsTo(CertTier::class);
    }

    /** @return BelongsTo<ExamAttempt, $this> */
    public function examAttempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }
}
