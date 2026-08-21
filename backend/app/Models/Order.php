<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
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
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount_satang' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
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
