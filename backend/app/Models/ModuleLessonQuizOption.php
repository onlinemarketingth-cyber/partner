<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-009 — mirrors ExamQuestionOption exactly. "At most one correct
 * option per question" is enforced by mutual exclusion in
 * ModuleLessonQuizOptionService, not a DB constraint.
 */
class ModuleLessonQuizOption extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'module_lesson_quiz_question_id',
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

    /** @return BelongsTo<ModuleLessonQuizQuestion, $this> */
    public function moduleLessonQuizQuestion(): BelongsTo
    {
        return $this->belongsTo(ModuleLessonQuizQuestion::class);
    }
}
