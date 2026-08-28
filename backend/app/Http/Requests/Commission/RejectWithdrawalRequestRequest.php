<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A rejection MUST carry a reason. The agent is shown it verbatim, and a
 * refusal with no explanation is the kind of thing that turns into a support
 * conversation somebody has to have by hand.
 */
class RejectWithdrawalRequestRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ',
        ];
    }
}
