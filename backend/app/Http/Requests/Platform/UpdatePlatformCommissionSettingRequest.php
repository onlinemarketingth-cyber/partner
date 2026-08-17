<?php

namespace App\Http\Requests\Platform;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

// TASK-196 §2.2 — Super Admin ONLY (Ability::CommissionRateCapUpdate — see
// that case's own docblock for why there is no Company Admin grant to
// piggy-back on here, same shape as UpdatePlatformMailSettingRequest).
class UpdatePlatformCommissionSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::CommissionRateCapUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // BR-2/BR-3-adjacent basis points, same unit as
            // commission_rules.rate_value for rate_type=percentage.
            // max 10000 = 100.00% — a cap above 100% of the sale price
            // would not be a cap at all, so it is rejected here rather
            // than left to silently do nothing.
            'max_commission_rate_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
