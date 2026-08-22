<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service "email me / don't email me" (2026-08-22).
 *
 * Always operates on $request->user() — see UserProfileController, same
 * self-scoped pattern as UpdateNameRequest. There is deliberately no
 * user id in the payload: an agent can only ever change their OWN
 * preference, and an admin has no business silencing someone else's
 * approval and payment mail.
 *
 * `boolean` (not `required|in:0,1`) so the SPA can send a real JSON true /
 * false; `present` rather than `required` because `false` is the whole
 * point of this endpoint and `required` rejects it.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
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
            'email_notifications_enabled' => ['present', 'boolean'],
        ];
    }
}
