<?php

namespace Database\Factories;

use App\Enums\CommissionRateType;
use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionLedger>
 */
class CommissionLedgerFactory extends Factory
{
    protected $model = CommissionLedger::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'referral_id' => Referral::factory(),
            // Closure attributes — same idiom as ReferralFactory/
            // ClientDocumentFactory — derive from the just-created
            // Referral so tenant/agent/product invariants hold by
            // default.
            'company_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->company_id,
            'agent_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->agent_id,
            'product_id' => fn (array $attributes) => Referral::find($attributes['referral_id'])->product_id,
            'cert_tier_id_at_time' => \App\Models\CertTier::factory(),
            'rate_type_applied' => CommissionRateType::Percentage,
            'rate_applied' => 300, // 3.00% — arbitrary test value, not a BR-7 claim
            'amount_satang' => 26700,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
        ];
    }
}
