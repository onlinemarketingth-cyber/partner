<?php

namespace App\Http\Requests\Referral;

use App\Models\Referral;
use App\Services\Commission\CommissionSplitSettingService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// TASK-026 — PATCH /referrals/{referral}/co-agent. Send both fields to
// set/replace the split, or both as null to clear it. Never a lone
// field — see withValidator() below.
class SetCoAgentRequest extends FormRequest
{
    /**
     * TASK-174 §4 — "Hiding a button while the endpoint still accepts the
     * request is not switching a feature off; it is hiding it from honest
     * users only." So the switch is checked HERE, before the Policy, and a
     * disabled company gets 403 on this endpoint outright — including the
     * "clear the split" call, which has nothing left to do once the value
     * is no longer read at calculation time (TASK-174 D1).
     *
     * Asked of the REFERRAL's company, not the caller's: for a Super Admin
     * (company_id = null) the caller's own company is not the one whose
     * money this moves.
     */
    public function authorize(): bool
    {
        $referral = $this->route('referral');
        $companyId = $referral instanceof Referral ? $referral->company_id : $this->user()->company_id;

        if (! app(CommissionSplitSettingService::class)->isEnabledForCompany($companyId)) {
            return false;
        }

        return $this->user()->can('setCoAgent', $referral);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'co_agent_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('company_id', $this->user()->company_id)->where('role', 'agent'),
            ],
            'split_percentage' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasCoAgent = $this->filled('co_agent_id');
            $hasSplit = $this->filled('split_percentage');

            // TASK-170 — Thai, and no `TASK-026:` tag. CoAgentEditor shows
            // the 422's own field messages verbatim (they are more specific
            // than any generic copy), so an internal task number in front
            // of them lands in a salesperson's face and reads as a crash.
            if ($hasCoAgent && ! $hasSplit) {
                $validator->errors()->add('split_percentage', 'กรุณาระบุเปอร์เซ็นต์ที่จะแบ่งให้ผู้ร่วมทีม');
            }
            if ($hasSplit && ! $hasCoAgent) {
                $validator->errors()->add('co_agent_id', 'กรุณาเลือกตัวแทนที่จะแบ่งคอมมิชชั่นด้วย');
            }
        });
    }
}
