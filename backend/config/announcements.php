<?php

// TASK-076 — BR-7 platform-wide fallback used when a company has no
// announcement_settings row yet (AnnouncementSettingService::forCompany()).
// Human request (2026-08-02): "ให้เปิดอย่างน้อย 4 ครั้ง ถึงไม่ขึ้น" — this
// is only the platform default; every company can override it via
// PUT /announcement-settings (Company Admin/Super Admin).
return [
    'default_repeat_count' => 4,
    // TASK-077 — see App\Enums\AnnouncementDisplayStyle for the 4 valid
    // values. Matches the pre-existing TASK-075 modal behavior.
    'default_display_style' => 'bottom_sheet',
];
