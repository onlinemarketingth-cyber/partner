<?php

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Models\Badge;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Exam;
use App\Models\ModuleLesson;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Services\Academy\ExamAttemptService;
use App\Services\Academy\ModuleCompletionService;
use App\Services\Gamification\UserBadgeService;
use App\Services\Referral\PipelineService;
use App\Services\Referral\ReferralService;
use Illuminate\Database\Seeder;

/**
 * DEV-ONLY seed data — end-to-end demo activity so every screen in both
 * frontends has real, non-empty, business-rule-correct data to test
 * against when logging in as agent@thailife.test (or the two extra
 * agents below), instead of every list showing an empty state.
 *
 * Deliberately goes through the real Services (ModuleCompletionService,
 * ExamAttemptService, ReferralService, PipelineService,
 * UserBadgeService) rather than inserting rows directly — same
 * reasoning ReferralSeeder documented for not faking a passed
 * certification: BR-1's gate, BR-4's commission calculation, and BR-5's
 * XP awarding all have real logic attached (see each Service), and
 * seeding "around" that logic would risk producing data no real user
 * flow could ever actually reach (e.g. a referral with commission but
 * no passed cert tier). Walking the real Services guarantees the demo
 * data is exactly as valid as anything a human would produce by hand.
 *
 * Idempotent: module/exam/badge awarding lean on each Service's own
 * idempotency (firstOrCreate / unique constraints), and the
 * referral+pipeline block explicitly skips any client that already has
 * a Referral (pipeline advancing itself is NOT idempotent — calling
 * PipelineService::advance() again would move a referral past its
 * intended demo stage), so `php artisan db:seed` stays safe to rerun.
 *
 * Company/product/cert-tier/exam/module/gamification-rule data all
 * comes from CatalogSeeder/AcademySeeder/GamificationSeeder, which must
 * run first (see DatabaseSeeder's call order) — this seeder only reads
 * that config, never invents its own values (BR-7).
 */
class DemoActivitySeeder extends Seeder
{
    public function run(): void
    {
        $thaiLife = Company::where('slug', 'thai-life')->firstOrFail();

        $agent = User::where('email', 'agent@thailife.test')->firstOrFail();
        $niran = User::where('email', 'niran@thailife.test')->firstOrFail();
        $pim = User::where('email', 'pim@thailife.test')->firstOrFail();

        $basicExam = Exam::where('company_id', $thaiLife->id)->where('title', 'Basic Certification Exam')->firstOrFail();
        $intermediateExam = Exam::where('company_id', $thaiLife->id)->where('title', 'Intermediate Certification Exam')->firstOrFail();
        // ADR-009 — completion is keyed on ModuleLesson now, not Module (Section).
        $lessons = ModuleLesson::where('company_id', $thaiLife->id)->get();
        $standard = Product::where('company_id', $thaiLife->id)->where('name', 'Standard Package')->firstOrFail();
        $premium = Product::where('company_id', $thaiLife->id)->where('name', 'Premium Package')->firstOrFail();

        $moduleCompletionService = app(ModuleCompletionService::class);
        $examAttemptService = app(ExamAttemptService::class);
        $referralService = app(ReferralService::class);
        $pipelineService = app(PipelineService::class);
        $userBadgeService = app(UserBadgeService::class);

        // --- Academy: every agent completes both modules and passes
        // Basic (BR-1 gate) — agent@thailife.test additionally passes
        // Intermediate, so their later commission is calculated at the
        // higher tier rate (a realistic "your most experienced agent"
        // demo case rather than all 3 agents looking identical).
        foreach ([$agent, $niran, $pim] as $a) {
            foreach ($lessons as $lesson) {
                $moduleCompletionService->complete($lesson, $a, null);
            }
            $examAttemptService->attempt($basicExam, $a, 85);
        }
        $examAttemptService->attempt($intermediateExam, $agent, 90);

        // --- Referral & Pipeline: each (client, product, how-far-to-
        // advance) triple below is skipped entirely if that client
        // already has a Referral, so reruns never double-advance.
        // Advance counts (CompleteRegistered -> WaitingAppointment ->
        // Finish1stDoctorMeeting -> CompletePayment -> OngoingNextMeeting)
        // are chosen to populate every Pipeline column and give at least
        // two Commission Ledger entries (one paid, one pending).
        $plan = [
            // agent@thailife.test — richest data, this is the account
            // named in the request this data was built for.
            ['client' => 'Somchai Jaidee', 'agent' => $agent, 'product' => $standard, 'advances' => 4, 'markPaid' => true],
            ['client' => 'Malee Suksawat', 'agent' => $agent, 'product' => $premium, 'advances' => 3, 'markPaid' => false],
            ['client' => 'Preecha Wattana', 'agent' => $agent, 'product' => $standard, 'advances' => 1, 'markPaid' => false],
            // Niran — mid-pipeline + a fresh, un-advanced referral.
            ['client' => 'Anong Srisai', 'agent' => $niran, 'product' => $premium, 'advances' => 2, 'markPaid' => false],
            ['client' => 'Wichai Chaiyaporn', 'agent' => $niran, 'product' => $standard, 'advances' => 0, 'markPaid' => false],
            // Pim — one fully-closed sale, so she has a competitive XP
            // total on the Leaderboard too, not just agent@thailife.test.
            ['client' => 'Kanya Ruangrit', 'agent' => $pim, 'product' => $premium, 'advances' => 3, 'markPaid' => false],
        ];

        foreach ($plan as $row) {
            $client = Client::where('company_id', $thaiLife->id)->where('name', $row['client'])->first();
            if (! $client) {
                $this->command?->warn("DemoActivitySeeder: skipped {$row['client']} — client not found (run CustomerSeeder first).");

                continue;
            }

            if (Referral::where('client_id', $client->id)->exists()) {
                continue; // already seeded in a previous db:seed run
            }

            $referral = $referralService->create([
                'client_id' => $client->id,
                'product_id' => $row['product']->id,
                'branch' => 'สาขาสีลม', // TODO: CONFIRM (BR-7) — placeholder branch, same value ReferralSeeder used
                'preferred_time' => now()->addDays(3),
            ], $row['agent']);

            for ($i = 0; $i < $row['advances']; $i++) {
                $referral = $pipelineService->advance($referral, $row['agent']);
            }

            if ($row['markPaid']) {
                $ledger = CommissionLedger::where('referral_id', $referral->id)->first();
                // Same mutation CommissionLedgerController::markPaid() performs —
                // no dedicated Service method exists for this one allowed
                // mutation (BR-4), see that controller's own comment.
                $ledger?->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);
            }
        }

        // --- Badges: manual award (condition_config is null on every
        // seeded badge — see GamificationSeeder — so BadgeAutoAwardService
        // has nothing to auto-evaluate yet). Awarded here exactly the way
        // a Company Admin would via POST /user-badges, for agents who
        // have actually earned them under the badge's stated criteria.
        $certifiedAgentBadge = Badge::where('key', 'certified_agent')->first();
        $firstSaleBadge = Badge::where('key', 'first_sale')->first();

        foreach ([$agent, $niran, $pim] as $a) {
            if ($certifiedAgentBadge && $a->hasPassedCertTier('basic')) {
                $userBadgeService->award($a->id, $certifiedAgentBadge->id);
            }
        }
        if ($firstSaleBadge) {
            // "First Sale" — awarded to whichever seeded agents actually
            // have a referral (all 3, per the $plan above).
            foreach ([$agent, $niran, $pim] as $a) {
                if (Referral::where('agent_id', $a->id)->exists()) {
                    $userBadgeService->award($a->id, $firstSaleBadge->id);
                }
            }
        }
    }
}
