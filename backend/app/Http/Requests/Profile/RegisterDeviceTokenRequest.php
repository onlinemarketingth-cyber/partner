<?php

namespace App\Http\Requests\Profile;

use App\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-10-02 — MOB-10. POST /me/devices: "this phone may receive my pushes".
 *
 * Self-scoped like every /me/* request: there is no user id in the payload,
 * the phone is always attached to $request->user().
 *
 * authorize() refuses a user without a company. notifications.company_id is
 * NOT NULL, so such a user (Super Admin, Company Partner) can never be sent a
 * notification, and storing a token for them would only be a row nobody
 * reads. A 403 is the honest answer; the app treats it like any other failed
 * registration and carries on without push.
 *
 * Token: max 4096 because FCM does not promise a length and a token we
 * truncated would be accepted here and then never deliver. Today's FCM tokens
 * are ~160 characters.
 */
class RegisterDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::enum(DevicePlatform::class)],
            'token' => ['required', 'string', 'max:4096'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }
}
