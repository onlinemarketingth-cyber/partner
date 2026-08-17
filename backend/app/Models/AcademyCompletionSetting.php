<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-028 §4 / BR-7 — per-company completion thresholds. One optional row
 * per company; config/academy.php is the platform-wide fallback.
 *
 * Deliberately NOT TenantScope'd, exactly like VideoProcessingSetting:
 * every read goes through AcademyCompletionSettingService::forCompany(),
 * which is handed a server-resolved company_id and queries
 * withoutGlobalScopes(). A global scope here would silently return the
 * WRONG company's thresholds inside a queued job or a Super Admin request,
 * which is worse than no scope plus one disciplined access point.
 */
class AcademyCompletionSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'video_watch_percent',
        'pdf_read_percent',
        // ADR-029 §2.4 — the company-level half of the quiz pass-mark
        // resolution chain (module_lessons.quiz_pass_percent wins when set).
        'quiz_pass_percent',
    ];

    protected function casts(): array
    {
        return [
            'video_watch_percent' => 'integer',
            'pdf_read_percent' => 'integer',
            'quiz_pass_percent' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
