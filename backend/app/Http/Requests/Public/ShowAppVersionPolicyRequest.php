<?php

namespace App\Http\Requests\Public;

use App\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-10-02 — MOB-13. GET /app/version-policy?platform=ios|android.
 *
 * Public on purpose: the app asks on launch, before anyone has signed in,
 * because an app too old to talk to the API may not be able to sign in at
 * all. Nothing it returns is secret — the same numbers are on the store page.
 */
class ShowAppVersionPolicyRequest extends FormRequest
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
            'platform' => ['required', 'string', Rule::enum(DevicePlatform::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'platform' => 'แพลตฟอร์ม',
        ];
    }
}
