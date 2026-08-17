<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Academy — multiple attempts allowed per Agent. Append-only.
 */
class ExamAttempt extends Model
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
        'exam_id',
        'score',
        'passed',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'passed' => 'boolean',
            'attempted_at' => 'datetime',
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

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
