<?php

namespace App\Http\Requests\Registration;

use Illuminate\Foundation\Http\FormRequest;

// ADR-005 — public, unauthenticated (no Policy to check against; every
// visitor may attempt to resolve a code, that's the whole point of
// self-registration). Rate-limited at the route level (Section 6).
class ResolveInviteCodeRequest extends FormRequest
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
            'invite_code' => ['required', 'string', 'max:255'],
        ];
    }
}
