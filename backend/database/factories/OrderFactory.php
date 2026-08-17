<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Derive company/client/agent/product from a just-created Referral so
        // tenant/ownership invariants hold by default (same idiom as
        // ReferralFactory) — a test can still override any of them.
        return [
            'referral_id' => Referral::factory(),
            'company_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->company_id,
            'client_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->client_id,
            'agent_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->agent_id,
            'product_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->product_id,
            'order_number' => fn () => 'ORD-'.strtoupper(Str::random(8)),
            'public_token' => fn () => Str::random(40),
            'amount_satang' => fn (array $attributes) => Referral::find($attributes['referral_id'])->product->price_satang,
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => OrderStatus::Pending,
            'slip_path' => null,
            'paid_at' => null,
        ];
    }

    public function awaitingVerification(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::AwaitingVerification,
            'slip_path' => 'orders/slips/placeholder.jpg',
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
