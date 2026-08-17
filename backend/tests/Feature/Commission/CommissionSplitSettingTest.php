<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Enums\TeamVisibilityLevel;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\CommissionSplitSetting;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\TeamVisibilitySetting;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-174 — switching TASK-026's co-agent commission split OFF per company.
 *
 * Spec §7, case by case:
 *   - split enabled → two rows summing to the single-row amount
 *       → covered by SplitCommissionTest (unchanged behaviour, now with the
 *         switch turned on explicitly)
 *   - SPLIT DISABLED, REFERRAL HAS co_agent_id → ONE ROW, FULL AMOUNT (D1)
 *       → test_split_disabled_with_a_stored_co_agent_pays_one_row_at_the_full_amount
 *         test_the_one_row_pays_exactly_what_the_same_sale_pays_with_no_co_agent
 *   - disabled → setCoAgent + co-agent-options refuse; StoreReferralRequest rejects
 *       → test_set_co_agent_is_refused_while_the_split_is_disabled
 *         test_co_agent_options_is_refused_while_the_split_is_disabled
 *         test_creating_a_referral_with_the_pair_is_rejected_while_the_split_is_disabled
 *   - disabled → the fields are absent from both Resources
 *       → test_the_split_fields_are_absent_from_the_referral_resource_while_disabled
 *         test_the_split_fields_are_absent_from_the_team_client_resource_while_disabled
 *   - ledger rows written while enabled are unchanged by switching off
 *       → test_switching_off_never_touches_a_ledger_row_already_written
 *   - tenant isolation on the new setting endpoint (BR-6)
 *       → test_a_company_admin_company_id_param_is_ignored_on_write
 *         test_company_a_cannot_read_company_bs_setting
 *         test_a_super_admin_can_target_a_named_company
 */
class CommissionSplitSettingTest extends TestCase
{
    use RefreshDatabase;

    private function enableSplit(Company $company): void
    {
        CommissionSplitSetting::create(['company_id' => $company->id, 'is_enabled' => true]);
    }

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);

        return $tier;
    }

    private function advanceToStage(Referral $referral, User $agent, PipelineStage $target): Referral
    {
        while ($referral->current_stage !== $target) {
            $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
            $referral->refresh();
        }

        return $referral;
    }

    /**
     * A product priced so that 3% is NOT evenly divisible — 333,350 satang *
     * 3% = 10,000.5 → 10,001. The same figure SplitCommissionTest uses, so
     * "one row, full amount" is asserted against a number that a split would
     * visibly have broken in two.
     */
    private function sellableProduct(Company $company, CertTier $tier): Product
    {
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 333350]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300, // 3%
        ]);

        return $product;
    }

    // -----------------------------------------------------------------
    // D1 — THE CASE THE WHOLE TASK EXISTS FOR.
    // "A split nobody can see in the UI must not move money."
    // -----------------------------------------------------------------

    public function test_split_disabled_with_a_stored_co_agent_pays_one_row_at_the_full_amount(): void
    {
        $company = Company::factory()->create(); // no settings row at all => disabled
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $product = $this->sellableProduct($company, $tier);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        // The referral CARRIES a split — entered while the feature was live.
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'product_id' => $product->id, 'co_agent_id' => $coAgent->id, 'split_percentage' => 33,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $rows = CommissionLedger::where('referral_id', $referral->id)
            ->where('earned_via', CommissionEarnedVia::Direct->value)
            ->get();

        $this->assertSame(1, $rows->count(), 'A disabled split must produce exactly one direct-sale row.');
        $this->assertSame($agent->id, $rows->first()->agent_id, 'The whole commission goes to the REFERRING agent (D1).');
        $this->assertSame(10001, $rows->first()->amount_satang);

        // The co-agent is paid nothing, on any row, by any mechanism.
        $this->assertDatabaseMissing('commission_ledger', ['referral_id' => $referral->id, 'agent_id' => $coAgent->id]);

        // §3 — the stored split is PRESERVED, not cleared. Reversible means
        // reversible: the data survives, it is simply not read.
        $this->assertDatabaseHas('referrals', [
            'id' => $referral->id, 'co_agent_id' => $coAgent->id, 'split_percentage' => 33,
        ]);
    }

    /**
     * BR-3 — the one-row path must pay EXACTLY what a non-split sale pays.
     * Asserted by running the identical sale twice, once on a referral with a
     * stored split and once without, and comparing the satang. A re-rounding
     * bug in the disabled path would show up here as a 1-satang difference.
     */
    public function test_the_one_row_pays_exactly_what_the_same_sale_pays_with_no_co_agent(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $product = $this->sellableProduct($company, $tier);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $withStoredSplit = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'product_id' => $product->id, 'co_agent_id' => $coAgent->id, 'split_percentage' => 33,
            'branch' => 'Silom', 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $withoutSplit = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom', 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->advanceToStage($withStoredSplit, $agent, PipelineStage::CompletePayment);
        $this->advanceToStage($withoutSplit, $agent, PipelineStage::CompletePayment);

        $splitless = CommissionLedger::where('referral_id', $withoutSplit->id)->sole();
        $stored = CommissionLedger::where('referral_id', $withStoredSplit->id)->sole();

        $this->assertSame($splitless->amount_satang, $stored->amount_satang);
    }

    /**
     * Spec §3 / BR-4 — rows written while the feature was ON keep their
     * history exactly, split rows included. Switching off is not a migration.
     */
    public function test_switching_off_never_touches_a_ledger_row_already_written(): void
    {
        $company = Company::factory()->create();
        $this->enableSplit($company);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $product = $this->sellableProduct($company, $tier);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'product_id' => $product->id, 'co_agent_id' => $coAgent->id, 'split_percentage' => 33,
            'branch' => 'Silom', 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $before = CommissionLedger::where('referral_id', $referral->id)->orderBy('id')->get()
            ->map(fn (CommissionLedger $row) => [$row->id, $row->agent_id, $row->amount_satang, $row->payment_status->value])
            ->all();
        $this->assertCount(2, $before);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-split-settings', ['is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);

        $after = CommissionLedger::where('referral_id', $referral->id)->orderBy('id')->get()
            ->map(fn (CommissionLedger $row) => [$row->id, $row->agent_id, $row->amount_satang, $row->payment_status->value])
            ->all();

        $this->assertSame($before, $after);
    }

    // -----------------------------------------------------------------
    // Write endpoints refuse. "Hiding is not disabling" (spec §4).
    // -----------------------------------------------------------------

    public function test_set_co_agent_is_refused_while_the_split_is_disabled(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'product_id' => $product->id, 'branch' => 'Silom',
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->actingAs($agent)->patchJson("/api/v1/referrals/{$referral->id}/co-agent", [
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 25,
        ])->assertForbidden();

        $this->assertDatabaseHas('referrals', ['id' => $referral->id, 'co_agent_id' => null]);
    }

    public function test_co_agent_options_is_refused_while_the_split_is_disabled(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/referrals/co-agent-options')->assertForbidden();
    }

    public function test_creating_a_referral_with_the_pair_is_rejected_while_the_split_is_disabled(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'co_agent_id' => $coAgent->id,
            'split_percentage' => 40,
        ])->assertUnprocessable()->assertJsonValidationErrors(['co_agent_id', 'split_percentage']);

        // Not "created without the split" — not created at all, and certainly
        // never created WITH one.
        $this->assertDatabaseCount('referrals', 0);
    }

    public function test_a_referral_without_the_pair_is_still_creatable_while_the_split_is_disabled(): void
    {
        // The switch removes a capability, not the feature it lives inside.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
        ])->assertCreated();
    }

    // -----------------------------------------------------------------
    // Read Resources.
    // -----------------------------------------------------------------

    public function test_the_split_fields_are_absent_from_the_referral_resource_while_disabled(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id, 'first_name' => 'Hidden', 'last_name' => 'Partner']);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'product_id' => $product->id, 'co_agent_id' => $coAgent->id, 'split_percentage' => 30,
            'branch' => 'Silom', 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $response = $this->actingAs($agent)->getJson('/api/v1/referrals')->assertOk();

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('co_agent', $row);
        $this->assertArrayNotHasKey('split_percentage', $row);
        // Absent, not merely null-valued: the partner's name is not in the
        // body at all.
        $this->assertStringNotContainsString('Hidden Partner', $response->getContent());

        // And the same referral shows both fields again the moment the
        // company turns the split back on — the switch is reversible, and the
        // preserved data is still there to show (§3 / §6).
        $this->enableSplit($company);

        $this->actingAs($agent)->getJson('/api/v1/referrals')
            ->assertOk()
            ->assertJsonPath('data.0.co_agent.id', $coAgent->id)
            ->assertJsonPath('data.0.split_percentage', 30);
    }

    public function test_the_split_fields_are_absent_from_the_team_client_resource_while_disabled(): void
    {
        // TeamClientResource builds its rows FROM ReferralResource and then
        // blanks agent identities outside the caller's subtree. The risk this
        // covers is that blanking step handing `co_agent` back as a null key
        // after the switch removed it.
        $company = Company::factory()->create();
        TeamVisibilitySetting::create([
            'company_id' => $company->id,
            'client_visibility_level' => TeamVisibilityLevel::FullFile->value,
            'is_enabled' => true,
        ]);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $child = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $outsider = User::factory()->agent()->create(['company_id' => $company->id, 'first_name' => 'Sibling', 'last_name' => 'Outsider']);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $child->id]);

        Referral::factory()->create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $child->id,
            'co_agent_id' => $outsider->id, 'split_percentage' => 50,
            'current_stage' => PipelineStage::CompletePayment,
        ]);

        $response = $this->actingAs($leader)
            ->getJson("/api/v1/me/team/{$child->id}/clients")
            ->assertOk();

        $referrals = $response->json('data.0.referrals');
        $this->assertCount(1, $referrals);
        $this->assertArrayNotHasKey('co_agent', $referrals[0]);
        $this->assertArrayNotHasKey('split_percentage', $referrals[0]);
        $this->assertStringNotContainsString('Sibling Outsider', $response->getContent());
    }

    // -----------------------------------------------------------------
    // The setting endpoint itself.
    // -----------------------------------------------------------------

    public function test_an_unconfigured_company_reads_as_disabled(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-split-settings')
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/commission-split-settings')->assertUnauthorized();
        $this->putJson('/api/v1/commission-split-settings', ['is_enabled' => true])->assertUnauthorized();
    }

    public function test_a_company_admin_can_turn_it_on_and_read_it_back(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-split-settings', ['is_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-split-settings')
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true);

        $this->assertDatabaseHas('commission_split_settings', ['company_id' => $company->id, 'is_enabled' => true]);
    }

    public function test_an_agent_may_read_the_flag_but_never_write_it(): void
    {
        // The Agent Portal has to know whether to render the split controls;
        // the party the switch binds must not be able to flip it.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/commission-split-settings')
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);

        $this->actingAs($agent)
            ->putJson('/api/v1/commission-split-settings', ['is_enabled' => true])
            ->assertForbidden();

        $this->assertDatabaseMissing('commission_split_settings', ['company_id' => $company->id]);
    }

    public function test_the_pending_stored_split_count_is_reported_to_admins_only(): void
    {
        // Spec §6 — turning it back ON resumes splitting for every pending
        // referral that kept a co_agent_id, so the flipper must see how many.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $product = $this->sellableProduct($company, $tier);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $base = [
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'product_id' => $product->id, 'branch' => 'Silom',
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ];

        // Two pending referrals carry a stored split...
        Referral::create($base + ['co_agent_id' => $coAgent->id, 'split_percentage' => 20]);
        Referral::create($base + ['co_agent_id' => $coAgent->id, 'split_percentage' => 40]);
        // ...one pending referral has none...
        Referral::create($base);
        // ...and one already has its BR-4 ledger row, so nothing about it can
        // change any more — it must NOT be counted.
        $paid = Referral::create($base + ['co_agent_id' => $coAgent->id, 'split_percentage' => 60]);
        CommissionLedger::create([
            'company_id' => $company->id, 'agent_id' => $agent->id, 'referral_id' => $paid->id,
            'cert_tier_id_at_time' => $tier->id, 'product_id' => $product->id,
            'rate_type_applied' => CommissionRateType::Percentage, 'rate_applied' => 300,
            'amount_satang' => 10001, 'payment_status' => PaymentStatus::Pending, 'paid_at' => null,
            'earned_via' => CommissionEarnedVia::Direct,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-split-settings')
            ->assertOk()
            ->assertJsonPath('data.pending_referrals_with_stored_split', 2);

        // An Agent gets the flag, not the company-wide backlog figure.
        $this->actingAs($agent)
            ->getJson('/api/v1/commission-split-settings')
            ->assertOk()
            ->assertJsonMissingPath('data.pending_referrals_with_stored_split');
    }

    public function test_is_enabled_is_required_and_must_be_boolean(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-split-settings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_enabled');

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-split-settings', ['is_enabled' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_enabled');
    }

    public function test_repeated_writes_update_one_row(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        foreach ([true, false, true] as $value) {
            $this->actingAs($admin)
                ->putJson('/api/v1/commission-split-settings', ['is_enabled' => $value])
                ->assertOk()
                ->assertJsonPath('data.is_enabled', $value);
        }

        $this->assertSame(1, CommissionSplitSetting::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_flipping_the_switch_is_written_to_the_audit_log(): void
    {
        // Section 6 — "record every action that affects money [or] commission."
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson('/api/v1/commission-split-settings', ['is_enabled' => true])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'actor_user_id' => $admin->id,
            'action' => 'commission_split_setting.updated',
        ]);
    }

    // -----------------------------------------------------------------
    // BR-6 — tenant isolation on the new endpoint.
    // -----------------------------------------------------------------

    public function test_a_company_admin_company_id_param_is_ignored_on_write(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/commission-split-settings', [
            'is_enabled' => true,
            'company_id' => $companyB->id,
        ])->assertOk();

        $this->assertDatabaseHas('commission_split_settings', ['company_id' => $companyA->id, 'is_enabled' => true]);
        $this->assertDatabaseMissing('commission_split_settings', ['company_id' => $companyB->id]);
    }

    public function test_company_a_cannot_read_company_bs_setting(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $this->enableSplit($companyB);

        $this->actingAs($adminA)
            ->getJson('/api/v1/commission-split-settings?company_id='.$companyB->id)
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);
    }

    public function test_a_super_admin_can_target_a_named_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->putJson('/api/v1/commission-split-settings', [
            'company_id' => $companyB->id,
            'is_enabled' => true,
        ])->assertOk()->assertJsonPath('data.is_enabled', true);

        $this->assertDatabaseHas('commission_split_settings', ['company_id' => $companyB->id, 'is_enabled' => true]);
        $this->assertDatabaseMissing('commission_split_settings', ['company_id' => $companyA->id]);

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/commission-split-settings?company_id='.$companyA->id)
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);
    }

    public function test_a_super_admin_must_name_a_company_on_write(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/commission-split-settings', ['is_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');
    }

    /**
     * BR-6 on the CALCULATION, not just the endpoint: company A enabling the
     * split must not make company B's sales split. The predicate is asked per
     * referral company, and this is the test that proves it is not a global.
     */
    public function test_one_companys_switch_does_not_leak_into_another_companys_payout(): void
    {
        $enabled = Company::factory()->create();
        $this->enableSplit($enabled);
        $disabled = Company::factory()->create();

        foreach ([[$enabled, 2], [$disabled, 1]] as [$company, $expectedRows]) {
            $agent = User::factory()->agent()->create(['company_id' => $company->id]);
            $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
            $tier = $this->passBasicCert($agent, $company);
            $product = $this->sellableProduct($company, $tier);
            $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

            $referral = Referral::create([
                'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
                'product_id' => $product->id, 'co_agent_id' => $coAgent->id, 'split_percentage' => 33,
                'branch' => 'Silom', 'current_stage' => PipelineStage::CompleteRegistered,
                'meeting_number' => null, 'submitted_at' => now(),
            ]);
            $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

            $this->assertSame(
                $expectedRows,
                CommissionLedger::withoutGlobalScopes()->where('referral_id', $referral->id)->count(),
            );
        }
    }
}
