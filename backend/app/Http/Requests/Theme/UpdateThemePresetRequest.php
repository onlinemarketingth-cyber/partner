<?php

namespace App\Http\Requests\Theme;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * TASK-161 §3.2 — PUT on a preset is a RENAME, and (2026-09-08) a promotion
 * to ชุดกลาง. Never an edit of the stored COLOURS.
 *
 * Editing the colours is still deliberately not an operation: a preset is a
 * snapshot of what the theme was, and "re-save the current colours under a new
 * name" (POST) is the supported way to change a look. That keeps the "colours
 * only ever come from validated theme columns" guarantee intact — see
 * StoreThemePresetRequest.
 *
 * `is_shared` was added because TASK-217 only offered the choice at CREATE
 * time. The human hit the obvious consequence (2026-09-08): they had already
 * saved a palette for one company and wanted every company to have it, and the
 * only route was to re-create it by hand under a company they were not looking
 * at. The flag they were shown once should not become unreachable a second
 * later.
 *
 * SUPER-ADMIN-ONLY, stripped rather than rejected, exactly as
 * StoreThemePresetRequest strips it — a Company Admin must never be able to
 * push a palette onto every other tenant's screen, and a field they were never
 * shown should not answer them with a 422 about it.
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
     * Super-Admin-only, stripped for everyone else before validation. Same
     * shape and same reason as StoreThemePresetRequest::prepareForValidation();
     * validated() cannot contain a key that was deleted from the input bag.
     */
    protected function prepareForValidation(): void
    {
        if ($this->user()?->isSuperAdmin()) {
            return;
        }

        $this->getInputSource()->remove('is_shared');
        $this->query->remove('is_shared');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            /*
             * Optional, and ONE-WAY: true promotes an owned preset to ชุดกลาง.
             * `false` is accepted and does nothing — see
             * ThemePresetService::update() for why un-sharing has no honest
             * answer.
             */
            'is_shared' => ['sometimes', 'boolean'],
        ];
    }
}
