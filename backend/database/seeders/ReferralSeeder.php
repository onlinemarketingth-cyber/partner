<?php

namespace Database\Seeders;

use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Company;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Seeder;

// DEV-ONLY seed data for Referral & Pipeline (ERD-001 rev. 3, CLAUDE.md
// §2 "SWS Referral", §4.3). Idempotent (firstOrCreate).
//
// Deliberately conditional on the seeded agent having ACTUALLY passed
// Basic certification (User::hasPassedCertTier('basic')) — not faked
// here. AcademySeeder only seeds exam/module *content*, not a passed
// UserCertification row for agent@thailife.test; that only exists once
// a human walks through UAT-002 §3 (mark module complete, pass the
// Basic exam) for real in their own dev DB. If it hasn't happened yet,
// this seeder silently skips creating sample referrals rather than
// bypassing BR-1 to manufacture fixture data the agent hasn't earned —
// see UAT-004's note on this.
class ReferralSeeder extends Seeder
{
    public function run(): void
    {
        $thaiLife = Company::where('slug', 'thai-life')->firstOrFail();
        $agent = User::where('email', 'agent@thailife.test')->firstOrFail();

        if (! $agent->hasPassedCertTier('basic')) {
            $this->command?->info('ReferralSeeder: skipped — agent@thailife.test has not passed Basic cert yet (BR-1). Complete UAT-002 §3 first, then rerun db:seed.');

            return;
        }

        $product = Product::where('company_id', $thaiLife->id)->where('name', 'Standard Package')->first();
        if (! $product) {
            $this->command?->info('ReferralSeeder: skipped — no "Standard Package" product found (run CatalogSeeder first).');

            return;
        }

        $clients = Client::where('company_id', $thaiLife->id)
            ->where('referring_agent_id', $agent->id)
            ->get();

        foreach ($clients as $client) {
            $referral = Referral::firstOrCreate(
                ['company_id' => $thaiLife->id, 'client_id' => $client->id, 'product_id' => $product->id],
                [
                    'agent_id' => $agent->id,
                    'branch' => 'สาขาสีลม', // TODO: CONFIRM (BR-7) — sample branch name, not real branch config
                    'preferred_time' => now()->addDays(3),
                    'current_stage' => PipelineStage::CompleteRegistered,
                    'meeting_number' => null,
                    'submitted_at' => now(),
                ],
            );

            PipelineStageLog::firstOrCreate(
                ['referral_id' => $referral->id, 'to_stage' => PipelineStage::CompleteRegistered],
                [
                    'company_id' => $thaiLife->id,
                    'from_stage' => null,
                    'changed_by_user_id' => $agent->id,
                    'changed_at' => $referral->submitted_at,
                ],
            );
        }
    }
}
