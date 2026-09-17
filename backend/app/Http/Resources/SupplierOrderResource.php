<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 2026-09-16 — one order, as the SUPPLIER of the product is allowed to see it.
 *
 * ── THIS IS THE MOST SENSITIVE RESOURCE IN THE CODEBASE ──
 *
 * Every other resource shows a tenant their own data. This one shows a company
 * data belonging to a DIFFERENT company's customers, which BR-6 forbids
 * everywhere else. It exists because the owner ruled that suppliers ship their
 * own goods ("supplier เป็นคนส่งเอง"), and you cannot post a parcel to somebody
 * whose address you may not see.
 *
 * So the rule here is not "hide the obvious secrets". It is: **nothing is
 * included unless the supplier needs it to do the job**, and the job is two
 * things — send the parcel, and know they will be paid for it.
 *
 * ── WHAT IS DELIBERATELY ABSENT, AND WHY EACH ──
 *
 *   the selling agent      who sold it is our commercial relationship, not
 *                          theirs. A supplier with a list of our best sellers
 *                          is a supplier who can approach them directly.
 *   commission             what we pay our members is not their business, and
 *                          it is derivable back to our margin.
 *   our margin (GP)        same.
 *   the client id          they get a name to write on a label, not a handle
 *                          into our customer records.
 *   national id, health    never, for anybody, on any screen.
 *
 * ── THE ADDRESS IS GATED ON requires_shipping ──
 *
 * A service voucher needs no address, so a supplier of services gets a name
 * and nothing else — they meet the customer in person at redemption anyway.
 * The gate is the PRODUCT's own flag rather than "is the address non-empty",
 * because an address captured by accident must not become a reason to disclose
 * one.
 */
class SupplierOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $needsShipping = $this->product?->requires_shipping === true;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'product_name' => $this->product?->name,
            /*
             * The price the CUSTOMER paid, not what the supplier earns.
             *
             * Included because a supplier reconciling a statement needs to see
             * the sale it came from; what they are owed for it is a separate
             * figure on the settlement screen, and the difference between the
             * two is our margin — which they can therefore work out. That is
             * accepted: a supplier who agreed a 30% GP already knows it.
             */
            'sale_price_satang' => (int) $this->amount_satang,

            // A name to write on a label. Nothing that identifies the record
            // behind it.
            'customer_name' => $this->client?->name,

            'requires_shipping' => $needsShipping,
            'shipping_status' => $this->shipping_status?->value,
            'shipping_status_label' => $this->shipping_status?->label(),
            'tracking_number' => $this->tracking_number,
            'shipped_at' => $this->shipped_at?->toIso8601String(),

            /*
             * Address and phone ONLY when there is a parcel. `when()` omits
             * the keys entirely rather than sending nulls — a supplier of
             * services should not receive a payload shaped as though contact
             * details exist and happen to be empty.
             */
            'shipping_recipient_name' => $this->when($needsShipping, $this->shipping_recipient_name),
            'shipping_phone' => $this->when($needsShipping, $this->shipping_phone),
            'shipping_address' => $this->when($needsShipping, $this->shipping_address),
        ];
    }
}
