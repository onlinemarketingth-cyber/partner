<?php

namespace App\Http\Resources;

use App\Enums\CommissionBasis;
use App\Support\Money\SupportedCurrency;
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
            /*
             * 2026-09-19 — ISO 4217, plus the symbol to print with it.
             *
             * Resolved through the model, never read raw: a row from before
             * the column existed is a THB company and the screen must not
             * have to know that. The symbol is sent rather than looked up in
             * the frontend so the two apps cannot disagree about what a
             * currency looks like, and so a code this build does not
             * recognise renders as the code itself instead of as ฿.
             */
            'currency_code' => $this->resource->currencyCode(),
            'currency_symbol' => SupportedCurrency::symbol($this->resource->currencyCode()),
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'commission_plan_type' => $this->commission_plan_type?->value,
            // 2026-09-12 — 'price' or 'pv'. Coalesced rather than made
            // nullable in the payload: a company read back before the
            // column existed is a price-basis company, and the screen must
            // not have to know that.
            'commission_basis' => ($this->commission_basis ?? CommissionBasis::Price)->value,
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
