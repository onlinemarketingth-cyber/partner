<?php

namespace App\Http\Requests\Platform;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

// TASK-201 — mirrors UpdatePlatformMailSettingRequest::authorize() exactly:
// Super Admin ONLY (Ability::SettingsMailUpdate), same reasoning — testing
// SMTP credentials is an admin action on the same settings surface, not a
// general-read one.
class SendTestMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsMailUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required/valid regardless of who the logged-in admin is — the
            // frontend defaults this to the admin's own email as a
            // convenience, but the backend never derives it server-side
            // (spec §Backend/Request).
            'to' => ['required', 'email'],
        ];
    }
}
