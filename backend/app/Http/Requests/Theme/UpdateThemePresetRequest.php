<?php

namespace App\Http\Requests\Theme;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * TASK-161 §3.2 — PUT on a preset is a RENAME and nothing else.
 *
 * Editing the stored colours is deliberately not an operation: a preset is
 * a snapshot of what the theme was, and "re-save the current colours under
 * a new name" (POST) is the supported way to change a look. That also
 * keeps the "colours only ever come from validated theme columns"
 * guarantee intact — see StoreThemePresetRequest.
 */
class UpdateThemePresetRequest extends FormRequest
{
    /**
     * Returns the Gate RESPONSE, not a bool. TASK-164 §1: renaming a system
     * preset must answer 422 with a Thai explanation, and
     * ThemePresetPolicy::update() says so via `denyWithStatus(422, …)`.
     * `$user->can()` collapses that to a bool, which FormRequest then turns
     * into a bare 403 — losing both the status and the message.
     * FormRequest::passesAuthorization() handles a Response natively, so
     * the policy's own status and wording reach the client intact.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('theme_preset'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
