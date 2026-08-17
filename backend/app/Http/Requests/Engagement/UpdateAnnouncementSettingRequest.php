<?php

namespace App\Http\Requests\Engagement;

use App\Enums\Ability;
use App\Enums\AnnouncementDisplayStyle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// TASK-076 — Company Admin (own company) / Super Admin only, same
// visibility shape as UpdateVideoProcessingSettingRequest.
class UpdateAnnouncementSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsAnnouncementUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            // Human request (2026-08-02): "ระบบ banner ข่าวสารให้เปิดอย่าง
            // น้อย 4 ครั้ง ถึงไม่ขึ้น และสามารถกำหนดได้จาก admin" — repeat_count
            // is the number of times the Agent Portal auto-pops an unseen
            // announcement before it stops (BR-7: admin-editable, never
            // hardcoded). The max:50 ceiling is a fat-finger guard, not a
            // tuned business limit.
            'repeat_count' => ['required', 'integer', 'min:1', 'max:50'],
            // TASK-077 — human-confirmed via AskUserQuestion (2026-08-02):
            // 4 fixed display styles, one global value per company (not
            // per-announcement).
            'display_style' => ['required', Rule::enum(AnnouncementDisplayStyle::class)],
        ];
    }
}
