<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-033 (TASK-189) §4/C5 — the redemption-staff view of a voucher: what
// a Company Admin/Super Admin needs to decide whether to redeem it. NOT
// the customer's full PDPA record (§6) — no agent, no client contact
// details, no health data, only a display name.
class VoucherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status()->value,
            'status_label' => $this->status()->label(),
            'usage_quota' => $this->usage_quota,
            'used_count' => $this->used_count,
            'quota_remaining' => $this->quotaRemaining(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'order_number' => $this->order?->order_number,
            'product_name' => $this->order?->product?->name,
            // Display-only customer name — no phone/email/national id/health data.
            'client_name' => $this->order?->client?->name,
        ];
    }
}
