<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-017 (TASK-054) — the AUTHENTICATED order view (agent/admin). Full
// internal shape. The public /pay page uses PublicOrderResource instead,
// which deliberately exposes far less (no agent/commission/PDPA data).
class OrderResource extends JsonResource
{
    /**
     * TASK-191 §1.1 — the ONE place the public pay/voucher URL is derived
     * from an order's `public_token`. ReferralResource's nested `order`
     * calls this too, rather than re-deriving the URL a second way.
     */
    public static function publicPayUrl(Order $order): string
    {
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');

        return "{$frontendUrl}/pay/{$order->public_token}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            // BR-3 — satang stays an integer all the way to the wire;
            // amount_baht is a display convenience (divide by 100).
            'amount_satang' => $this->amount_satang,
            'amount_baht' => round($this->amount_satang / 100, 2),
            'public_token' => $this->public_token,
            'public_pay_url' => self::publicPayUrl($this->resource),
            'client_name' => $this->whenLoaded('client', fn () => $this->client?->name),
            // TASK-212 — prefills <ShareLinkModal>'s recipient field so the
            // agent confirms an address rather than retyping one (human's
            // answer, 2026-08-19: "ดึงอีเมลลูกค้ามาให้ แก้ไขได้").
            //
            // Not a new disclosure: this is the agent's OWN client, whose
            // email they already read on the clients screen, and every
            // reader of this Resource is inside the same company
            // (TenantScope + OrderPolicy). whenLoaded, so a caller that did
            // not eager-load `client` gets the key omitted rather than an
            // N+1 per row.
            'client_email' => $this->whenLoaded('client', fn () => $this->client?->email),
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent?->id,
                'name' => $this->agent?->name,
            ]),
            'referral_id' => $this->referral_id,
            'has_slip' => $this->slip_path !== null,
            'paid_at' => $this->paid_at,
            // TASK-176 §1.3 — WHO confirmed this payment. Already recorded in
            // orders.verified_by_user_id since ADR-017; it was simply never
            // read back. Same shape as ReferralResource's `order.verified_by`
            // so the Agent Portal and the Admin board show one thing, not two.
            // Null while the order is unpaid, and null (never a guessed name)
            // if the confirming user no longer exists.
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy === null ? null : [
                'id' => $this->verifiedBy->id,
                'name' => $this->verifiedBy->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
