<?php

namespace App\Http\Requests\Platform;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// TASK-190 §3.2/3.4 — Super Admin ONLY (Ability::SettingsMailUpdate — see
// that case's own docblock for why there is no Company Admin grant to
// piggy-back on here, unlike every other Settings*Update case in this
// codebase).
class UpdatePlatformMailSettingRequest extends FormRequest
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
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            // Optional — PlatformMailSettingService::update() only
            // overwrites the stored (encrypted) password when this key is
            // present AND non-empty, so re-saving the other fields never
            // blanks out an already-configured password.
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
