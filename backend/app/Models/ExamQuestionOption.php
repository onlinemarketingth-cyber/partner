<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Academy Sprint 1 — one answer option for an ExamQuestion. is_correct is
 * the answer key — see exam_question_options migration comment for the
 * masking rule (never exposed to Agent role) and the single-correct
 * invariant (enforced in ExamQuestionOptionService, not the DB).
 */
class ExamQuestionOption extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'exam_question_id',
        'option_text',
        'is_correct',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<ExamQuestion, $this> */
    public function examQuestion(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class);
    }
}
