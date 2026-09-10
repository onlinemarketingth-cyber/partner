<?php

namespace Tests\Feature\Order;

use App\Enums\Ability;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderVoucher;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserAbility;
use App\Models\UserCertification;
use App\Support\VoucherCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-10 (human: "Admin ที่ใช้บัตร voucher นั้นต้องใช้วิธี Key
 * ทำให้รหัสสั้นลงไม่เกิน 6 ตัวได้หรือไม่").
 *
 * The code stopped being a token and became something a person types. That
 * changes what can go wrong with it, and this file is those things:
 *
 *  • it must be typable — short, and free of the characters a person cannot
 *    tell apart on a screen;
 *  • it must forgive how people actually type — lower case, the dash off the
 *    printed card, an O where a zero was meant;
 *  • it must NOT forgive so eagerly that a 40-character code issued last week
 *    stops working, because a customer is holding that card;
 *  • and it must stay unguessable in practice, which is now the throttle's
 *    job rather than the code length's.
 */
class VoucherCodeTest extends TestCase
{
    use RefreshDatabase;

    // ── The code itself ──────────────────────────────────────────────

    public function test_a_new_code_is_six_characters_a_person_can_read_out(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = VoucherCode::generate();

            $this->assertSame(6, strlen($code));
            // No I, L, O or U. The first three because 1/0 are indistinguishable
            // from them on a screen — a "wrong code" that is really a correct
            // one is the most expensive kind of failure at a counter.
            $this->assertMatchesRegularExpression('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{6}$/', $code);
        }
    }

    public function test_codes_are_not_sequential(): void
    {
        // A credential for something a customer paid for. If codes were drawn
        // in order, anybody who had seen two could work out the next.
        $codes = collect(range(1, 50))->map(fn () => VoucherCode::generate());

        $this->assertGreaterThan(45, $codes->unique()->count());
    }

    public function test_it_is_written_down_in_two_groups(): void
    {
        $this->assertSame('AB1-234', VoucherCode::format('AB1234'));
    }

    public function test_a_legacy_token_is_never_chopped_into_threes(): void
    {
        // Formatting exists for legibility. A 40-character token cut into
        // thirteen groups is less readable, not more.
        $legacy = str_repeat('a1B2', 10);

        $this->assertSame($legacy, VoucherCode::format($legacy));
    }

    public function test_it_accepts_what_people_actually_type(): void
    {
        // The dash off the printed card, lower case, a stray space — and O/I/L
        // typed where 0/1 were meant, which is the whole reason those letters
        // are not in the alphabet.
        $this->assertSame('AB1234', VoucherCode::normalize('ab1-234'));
        $this->assertSame('AB1234', VoucherCode::normalize('  AB1 234 '));
        $this->assertSame('0B1234', VoucherCode::normalize('ob1-234'));
        $this->assertSame('AB1234', VoucherCode::normalize('abI-234'));
        $this->assertSame('AB1234', VoucherCode::normalize('abl-234'));
    }

    // ── Redemption ───────────────────────────────────────────────────

    public function test_staff_may_type_the_code_in_the_form_it_is_printed(): void
    {
        [$company, $voucher] = $this->issuedVoucher();
        $staff = $this->redeemer($company);

        $this->actingAs($staff)
            ->postJson('/api/v1/vouchers/redeem', ['code' => strtolower(VoucherCode::format($voucher->code))])
            ->assertOk();

        $this->assertSame(1, $voucher->fresh()->used_count);
    }

    public function test_a_voucher_issued_before_the_change_still_redeems(): void
    {
        /*
         * THE ONE THAT MATTERS MOST. Nothing rewrites the codes already out
         * there, and a customer holding a card printed last week must not be
         * turned away because the format changed. Note the code below contains
         * a lower-case l and an O — exactly the characters normalisation
         * rewrites, which is why the raw input is tried first.
         */
        [$company, $voucher] = $this->issuedVoucher();
        $legacy = 'aBcOl'.str_repeat('x', 35);
        $voucher->forceFill(['code' => $legacy])->save();

        $this->actingAs($this->redeemer($company))
            ->postJson('/api/v1/vouchers/redeem', ['code' => $legacy])
            ->assertOk();

        $this->assertSame(1, $voucher->fresh()->used_count);
    }

    public function test_guessing_is_cut_off_long_before_it_could_work(): void
    {
        /*
         * Six characters is ~1.07 billion codes, which is only safe while
         * nobody may sit and try them. This is the control that makes the
         * length acceptable, so it is pinned rather than left to configuration
         * nobody reads.
         */
        [$company] = $this->issuedVoucher();
        $staff = $this->redeemer($company);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->actingAs($staff)
                ->postJson('/api/v1/vouchers/redeem', ['code' => VoucherCode::generate()])
                ->assertUnprocessable();
        }

        $this->actingAs($staff)
            ->postJson('/api/v1/vouchers/redeem', ['code' => VoucherCode::generate()])
            ->assertStatus(429);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** Somebody who has been GRANTED the right — it is no longer implied by being an admin. */
    private function redeemer(Company $company): User
    {
        $user = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        UserAbility::create(['user_id' => $user->id, 'ability' => Ability::VoucherRedeem->value]);

        return $user;
    }

    /**
     * A paid order with a real, freshly minted voucher on it.
     *
     * @return array{0: Company, 1: OrderVoucher}
     */
    private function issuedVoucher(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'price_satang' => 890000,
            'voucher_usage_quota' => 5,
            'voucher_validity_days' => null,
        ]);

        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
        ]);

        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => Client::factory()->create([
                'company_id' => $company->id,
                'referring_agent_id' => $agent->id,
            ])->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::Finish1stDoctorMeeting,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        return [$company, $order->fresh()->voucher];
    }
}
