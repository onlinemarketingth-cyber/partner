<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

// TASK-042 §2: plain Admin-editable field, authorized the same way as
// approve/reject/fulfill (RewardRedemptionPolicy::decide()) since it's
// the same actor set (Company Admin/Super Admin, never the requesting
// Agent) — see RewardRedemptionService::updateTrackingNumber() for why
// this is deliberately NOT routed through decide()'s ALLOWED_TRANSITIONS
// state machine.
class UpdateRewardRedemptionTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->route('reward_redemption'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tracking_number' => ['nullable', 'string', 'max:255'],
        ];
    }
}
