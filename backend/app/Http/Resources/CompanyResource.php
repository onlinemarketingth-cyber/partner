<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'commission_plan_type' => $this->commission_plan_type?->value,
            // ADR-017 (TASK-054) — BR-7 admin-editable payment collection config.
            'payment_promptpay_id' => $this->payment_promptpay_id,
            'payment_bank_name' => $this->payment_bank_name,
            'payment_bank_account_number' => $this->payment_bank_account_number,
            'payment_bank_account_name' => $this->payment_bank_account_name,
            // ADR-026 §3.3 (TASK-132) — company-wide default journey.
            'default_pipeline_template_id' => $this->default_pipeline_template_id,
            'user_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
