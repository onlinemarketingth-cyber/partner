<?php

namespace App\Http\Requests\Gamification;

use App\Enums\GamificationSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// BR-5 config. company_id is nullable — null means "platform-wide
// default." Only Super Admin may set/omit it to null; Company Admin
// never sends it at all (forced to their own company in the Service),
// same prohibitedIf pattern as every other tenant-scoped Store request.
class StoreGamificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\GamificationRule::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'nullable',
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'source_type' => ['required', Rule::enum(GamificationSourceType::class)],
            'xp_value' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
