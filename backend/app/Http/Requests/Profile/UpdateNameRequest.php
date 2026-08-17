<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

// Self-service "edit my own name" (ProfileSettingsView) — always operates
// on $request->user() (see UserProfileController), same self-scoped
// pattern as UpdateAvatarRequest. Not the Manage Agents flow (that's
// StoreUserRequest/UpdateUserRequest — Company Admin editing someone
// else's row); kept as a separate Request since the two have different
// authorization stories even though the validation shape looks similar.
class UpdateNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ];
    }
}
