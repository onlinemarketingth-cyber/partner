<?php

namespace App\Http\Requests\Platform;

use App\Enums\Ability;
use App\Http\Controllers\Api\V1\UserAbilityController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-09-10 — the complete set of abilities granted to one person.
 *
 * WHOLE SET, NOT A DIFF. Two admins toggling at once with add/remove
 * messages can leave a person holding a combination neither of them chose;
 * a replacement makes the last write the answer.
 *
 * The allowed values are UserAbilityController::GRANTABLE, not the whole
 * Ability catalogue — see that constant's docblock for why a general
 * endpoint here would let a Company Admin grant themselves the setting that
 * names their company's bank account.
 */
class UpdateUserAbilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The Policy check that matters runs in the controller
        // (`authorize('update', $user)`), where the target user is resolved.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $grantable = array_map(fn (Ability $a) => $a->value, UserAbilityController::GRANTABLE);

        return [
            // present:array rather than required: an empty list is the
            // meaningful "revoke everything", and `required` rejects [].
            'abilities' => ['present', 'array'],
            'abilities.*' => ['string', Rule::in($grantable)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'abilities.*.in' => 'สิทธิ์นี้ยังไม่เปิดให้มอบหมายผ่านหน้าจอ',
        ];
    }
}
