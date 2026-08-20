<?php

namespace App\Services\Catalog;

use App\Models\VideoProcessingSetting;

// ADR-007 — BR-7: video compression limits are admin-editable, never
// hardcoded. forCompany() is the ONE place every consumer (upload
// validation, CompressUploadedVideo job) reads these three values from
// — never read config/media.php or the video_processing_settings table
// directly anywhere else, so the "company override, else platform
// default" fallback logic never has to be duplicated.
class VideoProcessingSettingService
{
    /**
     * @return array{max_upload_mb: int, target_resolution: string, target_bitrate_kbps: int}
     */
    /**
     * TASK-222 — `$companyId` is NULLABLE.
     *
     * A SUPER ADMIN has `users.company_id = NULL` (deliberately: they are
     * not scoped to any single company — see the users migration). Passing
     * that straight in used to be a fatal TypeError, and the one caller
     * that did it was ChunkedUploadController::init() — so every large
     * upload a Super Admin attempted died with a 500 at
     * `POST /uploads/init` before a single byte was sent. Reported from
     * production, 2026-08-20, on a 198 MB video.
     *
     * Null means "no company, so no per-company override can apply", which
     * is exactly the answer the body below already produces for a company
     * that has never customised its settings. The platform defaults from
     * config/media.php are the correct ceiling for a platform operator.
     */
    public function forCompany(?int $companyId): array
    {
        $override = $companyId === null
            ? null
            : VideoProcessingSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();

        if ($override) {
            return [
                'max_upload_mb' => $override->max_upload_mb,
                'target_resolution' => $override->target_resolution,
                'target_bitrate_kbps' => $override->target_bitrate_kbps,
            ];
        }

        return [
            'max_upload_mb' => config('media.video.max_upload_mb'),
            'target_resolution' => config('media.video.target_resolution'),
            'target_bitrate_kbps' => config('media.video.target_bitrate_kbps'),
        ];
    }

    /**
     * @param  array{max_upload_mb: int, target_resolution: string, target_bitrate_kbps: int}  $data
     */
    public function upsert(int $companyId, array $data): VideoProcessingSetting
    {
        // BR-6/§5 — $data comes from $request->validated() and may still
        // carry a client-supplied company_id (the Super Admin path in
        // UpdateVideoProcessingSettingRequest validates it). updateOrCreate()
        // would otherwise overwrite the match-key company_id with that value
        // via fill(), redirecting the write to another tenant. Always use
        // the server-resolved $companyId — see the same fix in
        // AgentRankSettingService/CommissionBinarySettingService/etc.
        unset($data['company_id']);

        return VideoProcessingSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
