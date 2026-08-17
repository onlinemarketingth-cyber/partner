<?php

namespace App\Services\Academy;

use App\Models\AcademyCompletionSetting;

/**
 * ADR-028 §4 — BR-7: the two completion thresholds are admin-editable,
 * never hardcoded. forCompany() is the ONE place every consumer
 * (LessonCompletionGate, the Admin settings screen) reads them from —
 * never read config/academy.php or the academy_completion_settings table
 * directly anywhere else, so the "company override, else platform default"
 * fallback never has to be duplicated.
 *
 * Deliberately identical in shape to VideoProcessingSettingService, which
 * is the pattern this codebase already uses for per-company config.
 */
class AcademyCompletionSettingService
{
    /**
     * @return array{video_watch_percent: int, pdf_read_percent: int, quiz_pass_percent: int}
     */
    public function forCompany(?int $companyId): array
    {
        $override = $companyId === null
            ? null
            : AcademyCompletionSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();

        if ($override) {
            return [
                'video_watch_percent' => $override->video_watch_percent,
                'pdf_read_percent' => $override->pdf_read_percent,
                // ADR-029 §2.4. Coalesced rather than read blindly: a row
                // written before this column existed reads back as null on a
                // database where the migration added it without backfilling,
                // and a null pass mark would silently mean "0% passes".
                'quiz_pass_percent' => (int) ($override->quiz_pass_percent ?? config('academy.completion.quiz_pass_percent')),
            ];
        }

        return [
            'video_watch_percent' => (int) config('academy.completion.video_watch_percent'),
            'pdf_read_percent' => (int) config('academy.completion.pdf_read_percent'),
            'quiz_pass_percent' => (int) config('academy.completion.quiz_pass_percent'),
        ];
    }

    /**
     * @param  array{video_watch_percent?: int, pdf_read_percent?: int, quiz_pass_percent?: int, company_id?: int}  $data
     */
    public function upsert(int $companyId, array $data): AcademyCompletionSetting
    {
        // BR-6/§5 — $data comes from $request->validated() and may still
        // carry a client-supplied company_id (the Super Admin path
        // validates one). updateOrCreate() would otherwise overwrite the
        // match-key company_id with that value via fill(), redirecting the
        // write to another tenant. Always use the server-resolved
        // $companyId — same fix as VideoProcessingSettingService and the
        // other settings services (see task #431).
        unset($data['company_id']);

        return AcademyCompletionSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
