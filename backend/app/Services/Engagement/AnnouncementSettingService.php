<?php

namespace App\Services\Engagement;

use App\Models\AnnouncementSetting;

// TASK-076 — BR-7: repeat_count is admin-editable, never hardcoded.
// forCompany() is the ONE place every consumer (AnnouncementSettingController
// and, indirectly, the Agent Portal's auto-pop logic) reads this value
// from — never read config/announcements.php or the announcement_settings
// table directly anywhere else, mirrors VideoProcessingSettingService.
class AnnouncementSettingService
{
    /**
     * @return array{repeat_count: int, display_style: string}
     */
    public function forCompany(?int $companyId): array
    {
        if ($companyId !== null) {
            $override = AnnouncementSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
            if ($override) {
                return [
                    'repeat_count' => $override->repeat_count,
                    'display_style' => $override->display_style->value,
                ];
            }
        }

        return [
            'repeat_count' => config('announcements.default_repeat_count'),
            'display_style' => config('announcements.default_display_style'),
        ];
    }

    /**
     * @param  array{repeat_count: int, display_style: string}  $data
     */
    public function upsert(int $companyId, array $data): AnnouncementSetting
    {
        // BR-6/§5 — $data comes from $request->validated() and may still
        // carry a client-supplied company_id (the Super Admin path in
        // UpdateAnnouncementSettingRequest validates it). updateOrCreate()
        // would otherwise overwrite the match-key company_id with that
        // value via fill(), redirecting the write to another tenant —
        // same fix as VideoProcessingSettingService/AgentRankSettingService.
        unset($data['company_id']);

        return AnnouncementSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );
    }
}
