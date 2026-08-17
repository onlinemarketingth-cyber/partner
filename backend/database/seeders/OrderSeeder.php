<?php

namespace Database\Seeders;

use App\Enums\ClientStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderService;
use App\Services\Referral\PipelineService;
use App\Services\Referral\ReferralService;
use Illuminate\Database\Seeder;

/**
 * DEV-ONLY seed data for ADR-017 (TASK-054) Order & Payment Collection.
 *
 * 1. Sets Thai Life's payment collection config (bank + PromptPay). These
 *    are BR-7 admin-editable values — DEMO seed data, fine to place here.
 * 2. Creates a mixed set (~6) of orders under agent@thailife.test across
 *    pending / awaiting_verification / paid states, so the agent's order
 *    list + Home/Commission pages have data.
 *
 * Goes through the REAL Services (ReferralService, PipelineService,
 * OrderService) exactly like DemoActivitySeeder — so a `paid` order's
 * commission_ledger row is produced the same way a genuine sale-close
 * would (OrderService::confirmPayment -> PipelineService::advance ->
 * CommissionService at Complete Payment, BR-4). Never writes the ledger
 * directly.
 *
 * Idempotent-ish: no-ops if the agent already has orders, so
 * `db:seed` stays safe to rerun (a full `migrate:fresh --seed` rebuilds
 * everything cleanly). Guards gracefully if Thai Life / the agent /
 * the demo product don't exist.
 */
class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $thaiLife = Company::where('slug', 'thai-life')->first();
        if (! $thaiLife) {
            $this->command?->info('OrderSeeder: skipped — Thai Life company not found.');

            return;
        }

        // 1. Payment collection config (BR-7 demo/seed values).
        $thaiLife->update([
            'payment_promptpay_id' => '0812345678',
            'payment_bank_name' => 'ธนาคารกสิกรไทย',
            'payment_bank_account_number' => '123-4-56789-0',
            'payment_bank_account_name' => 'บริษัท ไทยไลฟ์ จำกัด',
        ]);

        $agent = User::where('email', 'agent@thailife.test')->first();
        if (! $agent) {
            $this->command?->info('OrderSeeder: skipped orders — agent@thailife.test not found.');

            return;
        }

        // BR-1: ReferralService::create() blocks a referral for an agent who
        // hasn't passed Basic cert. DemoActivitySeeder passes it; if it
        // hasn't run/been completed yet, skip rather than bypass the gate.
        if (! $agent->hasPassedCertTier('basic')) {
            $this->command?->info('OrderSeeder: skipped orders — agent@thailife.test has not passed Basic cert yet (BR-1).');

            return;
        }

        if (Order::where('agent_id', $agent->id)->exists()) {
            return; // already seeded in a previous run
        }

        $product = \App\Models\Product::where('company_id', $thaiLife->id)->where('name', 'Standard Package')->first();
        if (! $product) {
            $this->command?->info('OrderSeeder: skipped orders — "Standard Package" product not found (run CatalogSeeder first).');

            return;
        }

        $referralService = app(ReferralService::class);
        $pipelineService = app(PipelineService::class);
        $orderService = app(OrderService::class);

        // (client name, payment method, target order state)
        $plan = [
            ['name' => 'ลูกค้าออเดอร์ สมพงษ์', 'method' => PaymentMethod::BankTransfer, 'state' => 'pending'],
            ['name' => 'ลูกค้าออเดอร์ วรรณา', 'method' => PaymentMethod::PromptPay, 'state' => 'pending'],
            ['name' => 'ลูกค้าออเดอร์ ธนกร', 'method' => PaymentMethod::BankTransfer, 'state' => 'awaiting_verification'],
            ['name' => 'ลูกค้าออเดอร์ ปิยะดา', 'method' => PaymentMethod::PromptPay, 'state' => 'awaiting_verification'],
            ['name' => 'ลูกค้าออเดอร์ อนุชา', 'method' => PaymentMethod::BankTransfer, 'state' => 'paid'],
            ['name' => 'ลูกค้าออเดอร์ กมลชนก', 'method' => PaymentMethod::PromptPay, 'state' => 'paid'],
        ];

        foreach ($plan as $row) {
            $client = Client::firstOrCreate(
                ['company_id' => $thaiLife->id, 'name' => $row['name']],
                [
                    'referring_agent_id' => $agent->id,
                    'phone' => '0800000000',
                    'consent_given_at' => now(),
                    'status' => ClientStatus::New,
                ],
            );

            $referral = $referralService->create([
                'client_id' => $client->id,
                'product_id' => $product->id,
                'branch' => 'สาขาสีลม', // TODO: CONFIRM (BR-7) — placeholder branch, same value the other seeders use
                'preferred_time' => now()->addDays(3),
            ], $agent);

            // Advance to Finish 1st Doctor Meeting — the stage from which a
            // payment can be confirmed (§4.3 / ADR-017 decision 2).
            while ($referral->current_stage !== PipelineStage::Finish1stDoctorMeeting) {
                $referral = $pipelineService->advance($referral, $agent);
            }

            $order = $orderService->createForReferral($referral, $row['method']);

            if ($row['state'] === 'awaiting_verification') {
                // Placeholder slip path — no real file needed for demo data.
                $order->update([
                    'status' => OrderStatus::AwaitingVerification,
                    'slip_path' => 'orders/slips/'.$thaiLife->id.'/placeholder-slip.jpg',
                ]);
            } elseif ($row['state'] === 'paid') {
                // Real close: advances the referral to Complete Payment,
                // firing BR-4 commission exactly once via CommissionService.
                $orderService->confirmPayment($order, $agent);
            }
        }
    }
}
