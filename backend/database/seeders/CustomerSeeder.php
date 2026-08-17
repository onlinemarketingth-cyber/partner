<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

// DEV-ONLY seed data for the Customer domain (ERD-001 rev. 3). Unlike
// CatalogSeeder/AcademySeeder, none of this is BR-7-governed — sample
// client names/phone numbers aren't a business config value, they're
// just fixture data, so no `// TODO: CONFIRM` markers needed here.
// Idempotent (firstOrCreate keyed on name+company, since clients have
// no natural unique column) — safe to rerun `db:seed`.
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $thaiLife = Company::where('slug', 'thai-life')->firstOrFail();
        $agent = User::where('email', 'agent@thailife.test')->firstOrFail();
        $niran = User::where('email', 'niran@thailife.test')->firstOrFail();
        $pim = User::where('email', 'pim@thailife.test')->firstOrFail();

        Client::firstOrCreate(
            ['company_id' => $thaiLife->id, 'name' => 'Somchai Jaidee'],
            [
                'referring_agent_id' => $agent->id,
                'phone' => '081-234-5678',
                'email' => 'somchai.dev@example.test',
                'consent_given_at' => now(),
                'health_notes' => null,
            ],
        );

        Client::firstOrCreate(
            ['company_id' => $thaiLife->id, 'name' => 'Malee Suksawat'],
            [
                'referring_agent_id' => $agent->id,
                'phone' => '089-876-5432',
                'email' => 'malee.dev@example.test',
                'consent_given_at' => now(),
                'health_notes' => null,
            ],
        );

        // Third client for agent@thailife.test — DemoActivitySeeder
        // leaves this one's referral at an early pipeline stage, so the
        // Pipeline board has a "just submitted" row alongside the two
        // further-along ones above.
        Client::firstOrCreate(
            ['company_id' => $thaiLife->id, 'name' => 'Preecha Wattana'],
            [
                'referring_agent_id' => $agent->id,
                'phone' => '086-111-2233',
                'email' => 'preecha.dev@example.test',
                'consent_given_at' => now(),
                'health_notes' => null,
            ],
        );

        // Clients for the other two agents (see DatabaseSeeder), so
        // Leaderboard/Pipeline/Commission have more than one agent's
        // worth of activity to show.
        Client::firstOrCreate(
            ['company_id' => $thaiLife->id, 'name' => 'Anong Srisai'],
            [
                'referring_agent_id' => $niran->id,
                'phone' => '082-345-6789',
                'email' => 'anong.dev@example.test',
                'consent_given_at' => now(),
                'health_notes' => null,
            ],
        );
        Client::firstOrCreate(
            ['company_id' => $thaiLife->id, 'name' => 'Wichai Chaiyaporn'],
            [
                'referring_agent_id' => $niran->id,
                'phone' => '083-456-7890',
                'email' => 'wichai.dev@example.test',
                'consent_given_at' => now(),
                'health_notes' => null,
            ],
        );
        Client::firstOrCreate(
            ['company_id' => $thaiLife->id, 'name' => 'Kanya Ruangrit'],
            [
                'referring_agent_id' => $pim->id,
                'phone' => '084-567-8901',
                'email' => 'kanya.dev@example.test',
                'consent_given_at' => now(),
                'health_notes' => null,
            ],
        );
    }
}
