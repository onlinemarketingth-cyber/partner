<?php

namespace App\Models;

use App\Enums\PipelineStage;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-026 §3.2 (TASK-132) — one stage of one pipeline template, at one
 * position. `stage` is enum-cast, never free text (ADR-026 §2 Option C).
 */
class PipelineTemplateStage extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'pipeline_template_id',
        'stage',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'stage' => PipelineStage::class,
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<PipelineTemplate, $this> */
    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class);
    }
}
