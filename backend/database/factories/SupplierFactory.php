<?php

namespace Database\Factories;

use App\Enums\SupplierGpMode;
use App\Enums\SupplierReleaseTrigger;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 2026-09-17 — a supplier, as a fixture.
 *
 * ── THE DEFAULT STATE HAS NO DEAL TERMS ──
 *
 * `gp_mode`, `gp_value` and `release_trigger` are NULL by default, which is
 * the state a supplier is actually in the moment somebody creates one. Tests
 * that need a payable supplier say so with ->withTerms(), and the ones that
 * do not get the real half-configured shape.
 *
 * That is a direct response to how the first cut of this feature went wrong:
 * every test built `Company::factory()->create(['is_supplier' => true, ...])`
 * with the terms already filled in, so no test ever passed through the state
 * a human passes through, and the fact that there was NO SCREEN to leave that
 * state went unnoticed until the owner asked where it was. A factory whose
 * default is "already set up" is a factory that hides setup bugs.
 *
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'legal_name' => $this->faker->company().' Co., Ltd.',
            'tax_id' => (string) $this->faker->numerify('#############'),
            'contact_name' => $this->faker->name(),
            'contact_phone' => $this->faker->numerify('08########'),
            'contact_email' => $this->faker->unique()->safeEmail(),
            'address' => $this->faker->address(),
            'is_active' => true,

            // Deliberately absent: gp_mode, gp_value, release_trigger,
            // min_withdrawal_satang, wht_rate, payout_bank_*. See the class
            // note — a fresh supplier has no deal yet.
        ];
    }

    /** A supplier that can actually be paid: GP, trigger and a bank account. */
    public function withTerms(
        SupplierGpMode $mode = SupplierGpMode::PercentOfSale,
        int $value = 3000,
        SupplierReleaseTrigger $trigger = SupplierReleaseTrigger::OnPayment,
    ): static {
        return $this->state(fn () => [
            'gp_mode' => $mode,
            // 3000 basis points = 30%. Expressed in the same unit the column
            // holds so a test never has to think about the conversion.
            'gp_value' => $value,
            'release_trigger' => $trigger,
            'payout_bank_name' => 'ธนาคารกสิกรไทย',
            'payout_bank_account_number' => '1234567890',
            'payout_bank_account_name' => 'บริษัท ซัพพลายเออร์ จำกัด',
        ]);
    }

    /** Withholding at a rate, in basis points (300 = 3%, the service rate). */
    public function withholding(int $basisPoints = 300): static
    {
        return $this->state(fn () => ['wht_rate' => $basisPoints]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
