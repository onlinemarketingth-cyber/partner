<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 2026-09-12 — wraps the plain array CommissionSettingService assembles,
 * never a raw Company model (§7). Deliberately narrow: this endpoint answers
 * "how is commission calculated here", and returning the company row would
 * hand every reader its name, slug, payment details and withdrawal minimum
 * along with it.
 *
 * `commission_basis` is never null on the wire. The column defaults to
 * 'price' and the Service coalesces, because a screen that had to handle
 * "no basis" would have to invent one — and inventing it is exactly how a PV
 * company ends up being shown 'ราคาขาย'.
 *
 * `commission_plan_type` CAN be null, and that is a different fact: it means
 * the caller asked about no company in particular (a Super Admin on
 * "ทุกบริษัท"). Kept nullable rather than defaulted for BR-7's reason — the
 * screen may not assert a business value nobody told it.
 */
class CommissionSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'commission_basis' => $this['commission_basis']->value,
            'commission_plan_type' => $this['commission_plan_type']?->value,
        ];
    }
}
