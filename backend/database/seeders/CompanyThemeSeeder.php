<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyThemeSetting;
use Illuminate\Database\Seeder;

/**
 * TASK-055 / ADR-018 — DEMO/seed white-label theme for Thai Life. These
 * are BR-7 seed values (a real tenant sets their own via the admin UI),
 * fine to place in a seeder. Primary/accent mirror the current GENESENN
 * brand the Agent Portal ships as its default. No-ops if Thai Life is
 * absent. Idempotent (updateOrCreate keyed on company_id).
 */
class CompanyThemeSeeder extends Seeder
{
    public function run(): void
    {
        $thaiLife = Company::where('slug', 'thai-life')->first();

        if (! $thaiLife) {
            $this->command?->info('CompanyThemeSeeder: skipped — Thai Life company not found.');

            return;
        }

        CompanyThemeSetting::updateOrCreate(
            ['company_id' => $thaiLife->id],
            [
                'primary_hex' => '#2F4183',
                'accent_hex' => '#8C704A',
                'background_type' => 'solid',
                'font_family' => 'Kanit',
                'font_family_thai' => 'Kanit',
                'font_family_latin' => 'Inter',
                'font_weights' => [400, 500, 700],
                'loading_message' => 'กำลังโหลด Sync Vision…',
                'label_overrides' => [
                    'app_name' => 'Thai Life Agent',
                    'nav_home' => 'หน้าหลัก',
                ],
            ],
        );

        // A SECOND demo tenant with a deliberately DIFFERENT brand, so the
        // white-label is visibly demonstrable: open the Agent Portal with
        // ?company=genesenn and the login/loading/app brand switches to
        // this palette/font/labels. Company row is created here (idempotent)
        // purely to host the theme — no users/products are seeded for it.
        $genesenn = Company::firstOrCreate(
            ['slug' => 'genesenn'],
            ['name' => 'GENESENN Health', 'is_active' => true],
        );

        CompanyThemeSetting::updateOrCreate(
            ['company_id' => $genesenn->id],
            [
                'primary_hex' => '#0E7C6B',
                'accent_hex' => '#E8792B',
                'nav_bg_hex' => '#0B5F52',
                'nav_text_hex' => '#FFFFFF',
                'background_type' => 'gradient',
                'background_config' => ['from' => '#0E7C6B', 'to' => '#0B5F52', 'angle' => 135],
                'font_family' => 'Prompt',
                'font_family_thai' => 'Prompt',
                'font_family_latin' => 'Audiowide',
                'font_weights' => [400, 500, 700],
                'loading_bg_hex' => '#0E7C6B',
                'loading_message' => 'GENESENN กำลังเตรียมข้อมูล…',
                'label_overrides' => [
                    'app_name' => 'GENESENN Health',
                    'nav_home' => 'หน้าแรก',
                    'nav_clients' => 'สมาชิก',
                    'nav_commission' => 'รายได้',
                ],
            ],
        );
    }
}
