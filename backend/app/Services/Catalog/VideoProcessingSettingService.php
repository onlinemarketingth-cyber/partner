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
    public function forCompany(int $companyId): array
    {
        $override = VideoProcessingSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();

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
