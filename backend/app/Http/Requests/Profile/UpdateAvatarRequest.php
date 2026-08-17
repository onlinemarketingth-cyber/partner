<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

// Always operates on the authenticated user themselves (see
// UserProfileController) — authorize() just needs "is logged in",
// already guaranteed by the auth:sanctum route middleware, so this
// stays true. No Policy exists for "can I edit my own profile photo".
class UpdateAvatarRequest extends FormRequest
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
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'], // 4MB
        ];
    }
}
