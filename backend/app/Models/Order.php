<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\ShippingStatus;
use App\Models\Concerns\HasTrackedLink;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ADR-017 (TASK-054) — Order & Payment Collection. A payment-collection
 * record bound to a Referral: the customer pays (bank transfer / PromptPay)
 * on the public /pay/{token} page and uploads a slip; verifying it advances
 * the referral to Complete Payment and fires the existing BR-4 commission
 * (see OrderService). company_id carries TenantScope (BR-6, §5). Money is
 * satang integer (BR-3).
 */
class Order extends Model
{
    use HasFactory;
    use HasTrackedLink;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    // BR §6 (Mass Assignment) — explicit $fillable, never $guarded = [].
    // company_id/client_id/agent_id/product_id/amount_satang are always
    // set by OrderService from the referral, never trusted from the client;
    // they're listed here only so the Service's own create() array works.
    protected $fillable = [
        'company_id',
        'referral_id',
        'client_id',
        'agent_id',
        'product_id',
        'order_number',
        'public_token',
        'amount_satang',
        // 2026-09-16 — the product's cost at the moment this order was
        // created, so gross profit on a past quarter is not recomputed with
        // today's supplier price. Written once by OrderService; NULL when the
        // product has no cost recorded, and deliberately left NULL.
        'cost_satang_at_time',
        'payment_method',
        'status',
        'slip_path',
        // Follow-up to the 2026-08-21 audit — NULL when the customer
        // uploaded it themselves through the public /pay page, which is
        // the ordinary case and a real answer, not a missing one.
        'slip_uploaded_by_user_id',
        'payment_reference',
        'paid_at',
        'verified_by_user_id',
        // SECURITY AUDIT 2026-08-21 (V15) — written only by
        // CommissionReversalService, never from a request payload.
        'refunded_at',
        'refund_reason',
        'refunded_by_user_id',
        // ADR-033 (TASK-189) §2.5 — captured once, at the point of paying
        // (public /pay/{token} slip submission), never from a stored
        // client profile. Required server-side only when
        // product.requires_shipping (SubmitSlipRequest).
        'shipping_recipient_name',
        'shipping_phone',
        'shipping_address',
        /*
         * 2026-09-16 — the fulfilment half, added when a SUPPLIER became the
         * one who ships (owner: "supplier เป็นคนส่งเอง").
         *
         * Written by ShipmentService and nothing else. They are listed here so
         * that service can use update(), the same arrangement the gateway
         * fields above have — no request payload reaches this model directly,
         * because who may mark a parcel sent is a question about the actor's
         * relationship to the product's supplier, not about a form field.
         */
        'shipping_status',
        'tracking_number',
        'shipped_at',
        'shipped_by_user_id',
        // ADR-027 / TASK-139 — stamped ONCE by OrderService at creation from
        // the company's active gateway, then never re-read from the company.
        // A /pay link already in a customer's hand must not change what it
        // asks for because an admin flipped a setting afterwards.
        'payment_provider',
        'gateway_mode',
        /*
         * 2026-09-03 — written ONLY by GatewayPaymentService from a
         * signature-verified webhook. Listed here so that service can use
         * update(); no request payload reaches this model directly.
         */
        'last_payment_error',
        'last_payment_error_at',
        'refund_reported_at',
        'refund_reported_satang',
    ];

    /*
     * ADR-027 / TASK-139 — `gateway_charge_id` is DELIBERATELY ABSENT from
     * $fillable.
     *
     * It is the idempotency key behind a UNIQUE index, and the only writer is
     * GatewayPaymentService's conditional UPDATE. A charge id that could
     * arrive through mass assignment is a charge id a request payload can
     * choose, and choosing it means choosing whether a second webhook is
     * treated as a duplicate — which is the guard, not a field.
     */

    /*
     * The database's own defaults, mirrored so a model INSERTED in this
     * process carries them too. Without this an Order::create() that does not
     * mention `payment_provider` has it as null in memory even though the row
     * says 'manual' — and the code that decides which pay page to render then
     * reads null on the very request that created the order.
     */
    protected $attributes = [
        'payment_provider' => 'manual',
        'gateway_mode' => 'live',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_provider' => PaymentProvider::class,
            'amount_satang' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'last_payment_error_at' => 'datetime',
            'refund_reported_at' => 'datetime',
            'refund_reported_satang' => 'integer',
            'shipping_status' => ShippingStatus::class,
            'shipped_at' => 'datetime',
        ];
    }

    /**
     * Does this order need something physically sent?
     *
     * Reads the PRODUCT, because that is where the answer has always lived
     * (`requires_shipping`, ADR-033). `shipping_status` says where a parcel
     * is; this says whether there is one at all, and the two must not be
     * conflated — every order carries a shipping_status, including the ones
     * that will never have a parcel.
     */
    public function needsShipping(): bool
    {
        return $this->product?->requires_shipping === true;
    }

    /**
     * Did money actually arrive through a gateway for this order?
     *
     * The charge id is the receipt. It is claimed at the database BEFORE the
     * order is confirmed, so this can be true for a brief moment — or, in the
     * rare case where confirmation itself fails, for longer — while `status`
     * still says Pending. That gap is exactly why this exists: the public pay
     * page must never invite a second card payment for money already taken.
     */
    public function hasGatewayPayment(): bool
    {
        return filled($this->gateway_charge_id);
    }

    /** An order can only be paid from these non-terminal states. */
    public function isPayable(): bool
    {
        return in_array($this->status, [OrderStatus::Pending, OrderStatus::AwaitingVerification], true);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Referral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> The agent/admin who verified the payment. */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** @return HasOne<OrderVoucher, $this> ADR-033 (TASK-189) §2.2 — one voucher per PAID order. */
    public function voucher(): HasOne
    {
        return $this->hasOne(OrderVoucher::class);
    }
}
