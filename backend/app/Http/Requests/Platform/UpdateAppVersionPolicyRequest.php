<?php

namespace App\Http\Requests\Platform;

use App\Enums\DevicePlatform;
use App\Services\Platform\AppVersionPolicyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 2026-10-02 — MOB-13. PUT /platform/app-version-policies. Super Admin only:
 * one app binary serves every company, so a Company Admin raising the
 * minimum would lock every OTHER company's agents out of the app.
 *
 * Partial update: `platform` picks the row, and only the version/url keys
 * actually sent change (`sometimes`); sending one as null clears it.
 *
 * Versions are plain x.y.z (what both stores display), each part a number
 * without leading zeros so "1.10.0" and "1.010.0" cannot both exist and
 * compare differently from how they read.
 */
class UpdateAppVersionPolicyRequest extends FormRequest
{
    public const VERSION_PATTERN = '/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})$/';

    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::enum(DevicePlatform::class)],
            'min_supported_version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:'.self::VERSION_PATTERN],
            'latest_version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:'.self::VERSION_PATTERN],
            // https only: the app opens this from a native button, and a
            // plain-http store link is either a typo or something worse.
            'store_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url', 'starts_with:https://'],
        ];
    }

    /**
     * A minimum ABOVE the latest release would force every user to update
     * to a version that does not exist — the whole fleet locked out by a
     * typo. Compared against the value the row will have AFTER this save,
     * so sending only one of the two is still checked against the other.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $platform = DevicePlatform::from((string) $this->input('platform'));
                $current = app(AppVersionPolicyService::class)->forPlatform($platform);

                $min = $this->has('min_supported_version') ? $this->input('min_supported_version') : $current->min_supported_version;
                $latest = $this->has('latest_version') ? $this->input('latest_version') : $current->latest_version;

                if (is_string($min) && is_string($latest) && version_compare($min, $latest, '>')) {
                    $validator->errors()->add(
                        'min_supported_version',
                        'เวอร์ชันขั้นต่ำต้องไม่สูงกว่าเวอร์ชันล่าสุด — ไม่เช่นนั้นผู้ใช้ทุกคนจะถูกบังคับให้อัปเดตไปยังเวอร์ชันที่ยังไม่มีอยู่',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'min_supported_version.regex' => ':attribute ต้องอยู่ในรูปแบบ x.y.z เช่น 1.4.0',
            'latest_version.regex' => ':attribute ต้องอยู่ในรูปแบบ x.y.z เช่น 1.4.0',
            'store_url.starts_with' => ':attribute ต้องขึ้นต้นด้วย https://',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'platform' => 'แพลตฟอร์ม',
            'min_supported_version' => 'เวอร์ชันขั้นต่ำที่รองรับ',
            'latest_version' => 'เวอร์ชันล่าสุด',
            'store_url' => 'ลิงก์ร้านค้าแอป',
        ];
    }
}
