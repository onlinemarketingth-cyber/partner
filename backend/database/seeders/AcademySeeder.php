<?php

namespace Database\Seeders;

use App\Enums\ModuleContentType;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\Exam;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\Product;
use Illuminate\Database\Seeder;

// DEV-ONLY seed data for the Academy domain (ERD-001 rev. 3, BR-1).
// Idempotent (firstOrCreate) — see DatabaseSeeder's note on why that
// matters (crashed once already on a non-idempotent seeder, TASK-002).
//
// All syllabus content (module titles/content_ref, exam passing_score,
// exam config) is a PLACEHOLDER — CLAUDE.md marks syllabus per tier as
// "to be confirmed" (BR-7). This exists only so Academy has something
// non-empty to build the Referral BR-1 gate against; every value below
// is `// TODO: CONFIRM (BR-7)`.
class AcademySeeder extends Seeder
{
    public function run(): void
    {
        $thaiLife = Company::where('slug', 'thai-life')->firstOrFail();
        $basic = CertTier::where('key', 'basic')->firstOrFail();
        $intermediate = CertTier::where('key', 'intermediate')->firstOrFail();
        $high = CertTier::where('key', 'high')->firstOrFail();
        $standardProduct = Product::where('company_id', $thaiLife->id)->where('name', 'Standard Package')->first();

        // One exam per tier — passing_score is a placeholder (BR-7).
        Exam::firstOrCreate(
            ['company_id' => $thaiLife->id, 'cert_tier_id' => $basic->id, 'title' => 'Basic Certification Exam'],
            ['passing_score' => 70, 'config' => ['question_count' => 10]], // TODO: CONFIRM (BR-7)
        );
        Exam::firstOrCreate(
            ['company_id' => $thaiLife->id, 'cert_tier_id' => $intermediate->id, 'title' => 'Intermediate Certification Exam'],
            ['passing_score' => 75, 'config' => ['question_count' => 15]], // TODO: CONFIRM (BR-7)
        );
        Exam::firstOrCreate(
            ['company_id' => $thaiLife->id, 'cert_tier_id' => $high->id, 'title' => 'High Certification Exam'],
            ['passing_score' => 80, 'config' => ['question_count' => 20]], // TODO: CONFIRM (BR-7)
        );

        // Modules (Sections) — general onboarding (no product_id) plus one
        // tied to a specific Product, demonstrating the rev. 3 "Academy
        // teaches about Product" relationship. ADR-009 — each Section gets
        // one placeholder Lesson (the pre-redesign content now lives on
        // ModuleLesson, not Module).
        $onboarding = Module::firstOrCreate(
            ['company_id' => $thaiLife->id, 'cert_tier_id' => $basic->id, 'title' => 'Platform Onboarding'],
            [
                'product_id' => null,
                'sort_order' => 1,
                'is_published' => true,
            ],
        );
        ModuleLesson::firstOrCreate(
            ['company_id' => $thaiLife->id, 'module_id' => $onboarding->id, 'title' => 'Platform Onboarding'],
            [
                'content_type' => ModuleContentType::Video,
                'content_ref' => 'https://example.test/placeholder-onboarding.mp4', // TODO: CONFIRM (BR-7)
                'sort_order' => 1,
                'xp_reward' => 50, // TODO: CONFIRM (BR-7)
                'is_published' => true,
            ],
        );

        if ($standardProduct) {
            $standardIntro = Module::firstOrCreate(
                ['company_id' => $thaiLife->id, 'cert_tier_id' => $basic->id, 'title' => 'Introduction to Standard Package'],
                [
                    'product_id' => $standardProduct->id,
                    'sort_order' => 2,
                    'is_published' => true,
                ],
            );
            ModuleLesson::firstOrCreate(
                ['company_id' => $thaiLife->id, 'module_id' => $standardIntro->id, 'title' => 'Introduction to Standard Package'],
                [
                    'content_type' => ModuleContentType::Pdf,
                    'content_ref' => 'https://example.test/placeholder-standard-package.pdf', // TODO: CONFIRM (BR-7)
                    'sort_order' => 1,
                    'xp_reward' => 50, // TODO: CONFIRM (BR-7)
                    'is_published' => true,
                ],
            );
        }
    }
}
