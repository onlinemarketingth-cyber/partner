<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-10-02 — MOB-10. DELETE /me/devices: "stop sending my pushes to this
 * phone". The app calls it just before logout.
 *
 * Open to any authenticated user (no company check, unlike register): the
 * call only ever deletes the CALLER's own row, so there is nothing to guard,
 * and refusing it would leave a logging-out user with an error to look at.
 */
class UnregisterDeviceTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:4096'],
        ];
    }
}
