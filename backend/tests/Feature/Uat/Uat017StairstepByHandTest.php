<?php

namespace Tests\Feature\Uat;

use App\Enums\CommissionEarnedVia;
use App\Models\AgentRank;
use App\Models\CertTier;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UAT-017, run by a machine before the owner runs it by hand.
 *
 * ═══ WHY THIS TEST EXISTS ═══
 *
 * Owner, 2026-09-25: clear the old UAT, then a new one "ที่กรอกโดยผม ผ่าน UI
 * ตั้งแต่ตั้งค่าคอมมิชชันใหม่ สมัครหัวหน้าในแต่ละขั้น และทดสอบปิดยอดเอง".
 *
 * docs/qa/UAT-017-stairstep-by-hand.md tells him what to type and what he
 * should see. Every figure in it comes from THIS test, not from arithmetic
 * done in a head: the sheet says what the system pays, this test is where
 * that was measured, and the two change together or not at all.
 *
 * Each step goes through the same HTTP endpoint the screen it names calls,
 * as the same kind of person, in the same order. Two things cannot be done
 * that way and are said so where they happen: clicking the link in a
 * verification email, and the passage of a day.
 *
 * ═══ THE SHAPE ═══
 *
 *   ผู้อำนวยการ (D) ← ผู้จัดการ (M) ← ผู้นำ (L) ← ผู้ขาย (S)
 *
 * Every rate and threshold below is a UAT fixture chosen so the arithmetic
 * can be checked by eye. None of them is a business value (BR-7).
 */
class Uat017StairstepByHandTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'uat-plan-stairstep-by-hand';

    private const PRICE = 1_000_000; // ฿10,000

    private User $owner;

    private Company $company;

    private int $productId;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');
        Mail::fake();
        Notification::fake();

        // Seeded on every real deployment by CatalogSeeder; a platform table.
        CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        $this->owner = User::factory()->superAdmin()->create();
    }

    public function test_the_whole_uat_by_hand(): void
    {
        $this->part1CreateTheCompany();
        $this->part2SetUpCommission();
        $this->part3ThePackage();
        $this->part4RegisterTheChain();
        $this->part5BuildVolume();
        $this->part6FirstRanking();
        $this->part7TheTestSales();
        $this->part8TheNextDay();
    }

    /* ── ส่วนที่ 1 · สร้างบริษัท ──────────────────────────────────────── */

    private function part1CreateTheCompany(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/companies', [
            'name' => 'UAT · Stairstep ทดสอบมือ',
            // The prefix is what lets `uat:purge-commission-plans` remove this
            // company later. A tenant nobody can remove is not a UAT tenant.
            'slug' => self::SLUG,
            'commission_plan_type' => 'stairstep_breakaway',
        ])->assertCreated()->json('data.id');

        $this->company = Company::withoutGlobalScopes()->findOrFail($id);
    }

    /* ── ส่วนที่ 2 · ตั้งค่าคอมมิชชัน ─────────────────────────────────── */

    private function part2SetUpCommission(): void
    {
        $cid = $this->company->id;

        $this->actingAs($this->owner)->putJson('/api/v1/commission-settings', [
            'company_id' => $cid,
            'commission_basis' => 'price',
        ])->assertOk();

        $this->actingAs($this->owner)->putJson('/api/v1/agent-rank-settings', [
            'company_id' => $cid,
            'trailing_window_days' => 90,
            'volume_scope' => 'group',
            'recalculation_frequency' => 'daily',
        ])->assertSuccessful(); // 201 the first time: the settings row is created

        // Entered bottom rung first, exactly as the sheet says: ADR-042 refuses
        // a save that makes the ladder's findings worse, so the order matters.
        foreach ([
            ['UAT ขั้นเริ่มต้น', 0, 1, 500, false],
            ['UAT ขั้นผู้นำ', 2_000_000, 2, 1_200, false],
            ['UAT ขั้นผู้จัดการ', 4_000_000, 3, 2_000, true],
            ['UAT ขั้นผู้อำนวยการ', 6_000_000, 4, 2_500, false],
        ] as [$name, $threshold, $sort, $bp, $breakaway]) {
            $this->actingAs($this->owner)->postJson('/api/v1/agent-ranks', [
                'company_id' => $cid,
                'name' => $name,
                'volume_threshold' => $threshold,
                'sort_order' => $sort,
                'rate_type' => 'percentage',
                'rate_value' => $bp,
                'is_breakaway_rank' => $breakaway,
            ])->assertCreated();
        }

        // Paid to an agent who holds no rung yet — and set equal to the entry
        // rung, which is what the overview's "สองค่านี้ต้องตรงกัน" asks for.
        $this->actingAs($this->owner)->postJson('/api/v1/commission-rules', [
            'company_id' => $cid,
            'rate_type' => 'percentage',
            'rate_value' => 500,
            'effective_from' => now()->subDay()->toDateString(),
        ])->assertCreated();
    }

    /* ── ส่วนที่ 3 · สินค้า ─────────────────────────────────────────── */

    private function part3ThePackage(): void
    {
        $q = '?company_id='.$this->company->id;

        $brand = $this->actingAs($this->owner)->postJson('/api/v1/brands'.$q, ['name' => 'UAT แบรนด์'])
            ->assertCreated()->json('data.id');
        $category = $this->actingAs($this->owner)->postJson('/api/v1/product-categories'.$q, ['name' => 'UAT หมวด'])
            ->assertCreated()->json('data.id');

        // The product form pre-selects the direct-sale journey for a new
        // product; the API does not, so it is sent the way the form sends it.
        $direct = PipelineTemplate::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('key', PipelineTemplate::KEY_DIRECT_SALE_DEFAULT)
            ->firstOrFail();

        $this->productId = $this->actingAs($this->owner)->postJson('/api/v1/products'.$q, [
            'brand_id' => $brand,
            'category_id' => $category,
            'name' => 'UAT แพ็กเกจ ฿10,000',
            'price_satang' => self::PRICE,
            'is_active' => true,
            'pipeline_template_id' => $direct->id,
        ])->assertCreated()->json('data.id');
    }

    /* ── ส่วนที่ 4 · สมัครทีมทีละขั้น ──────────────────────────────────── */

    private function part4RegisterTheChain(): void
    {
        $code = $this->actingAs($this->owner)->postJson('/api/v1/company-invite-codes', [
            'company_id' => $this->company->id,
            'code' => 'uat-stairstep',
            'label' => 'UAT-017',
            'expires_at' => null,
            'max_uses' => null,
        ])->assertCreated()->json('data.code');

        // D comes in through the company's own signup link — there is nobody
        // above D to recruit them. Approved by an admin.
        $d = $this->register('director', ['invite_code' => $code]);
        $this->assertNull($d->manager_id, 'D has no upline (no house account on this company)');
        $this->actingAs($this->owner)->putJson("/api/v1/agent-approvals/{$d->id}/approve")->assertOk();

        // Each leader: made a team leader by the admin, then recruits the next
        // person with their own link and approves them from the portal.
        $m = $this->recruit($d, 'manager');
        $l = $this->recruit($m, 'leader');
        $s = $this->recruit($l, 'seller');

        $this->assertSame($d->id, $m->fresh()->manager_id);
        $this->assertSame($m->id, $l->fresh()->manager_id);
        $this->assertSame($l->id, $s->fresh()->manager_id);

        // BR-1 — granted by hand in Academy → ความคืบหน้า. The exam route is a
        // different UAT; this one is about money.
        $basic = CertTier::where('key', 'basic')->firstOrFail();
        foreach ([$d, $m, $l, $s] as $person) {
            $this->actingAs($this->owner)->postJson('/api/v1/user-certifications', [
                'user_id' => $person->id,
                'cert_tier_id' => $basic->id,
            ])->assertCreated();
        }

        $this->people = ['D' => $d->fresh(), 'M' => $m->fresh(), 'L' => $l->fresh(), 'S' => $s->fresh()];
    }

    private function register(string $handle, array $how): User
    {
        $email = "uat017+{$handle}@example.test";

        $this->postJson('/api/v1/register', $how + [
            'first_name' => 'UAT',
            'last_name' => $handle,
            'email' => $email,
            'phone' => '0800000'.str_pad((string) (count($this->people) + random_int(100, 999)), 3, '0', STR_PAD_LEFT),
            'password' => 'Uat-017-long-enough!',
            'password_confirmation' => 'Uat-017-long-enough!',
        ])->assertSuccessful();

        $user = User::withoutGlobalScopes()->where('email', $email)->firstOrFail();

        // The one step no endpoint can stand in for: the person clicks the
        // link in the verification email.
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function recruit(User $leader, string $handle): User
    {
        $this->actingAs($this->owner)->putJson("/api/v1/users/{$leader->id}", ['is_team_leader' => true])->assertOk();

        $token = $this->actingAs($leader->fresh())->postJson('/api/v1/agent-invite-links', ['label' => "UAT-017 {$handle}"])
            ->assertCreated()->json('data.token');

        $recruit = $this->register($handle, ['ref_token' => $token]);

        // Approved by the leader who recruited them, from the portal.
        $this->actingAs($leader->fresh())->putJson("/api/v1/agent-approvals/{$recruit->id}/approve")->assertOk();

        return $recruit;
    }

    /* ── ส่วนที่ 5 · สร้างยอดให้แต่ละขั้น ──────────────────────────────── */

    private function part5BuildVolume(): void
    {
        // Nobody holds a rung yet, so every row here is priced as un-ranked.
        $this->assertLedgerFor($this->sell('S', 'UAT ลูกค้า 1'), ['S' => 50_000, 'L' => 0, 'M' => 0, 'D' => 0]);
        $this->assertLedgerFor($this->sell('L', 'UAT ลูกค้า 2'), ['L' => 50_000, 'M' => 0, 'D' => 0]);
        $this->assertLedgerFor($this->sell('M', 'UAT ลูกค้า 3'), ['M' => 50_000, 'D' => 0]);
        $this->assertLedgerFor($this->sell('M', 'UAT ลูกค้า 4'), ['M' => 50_000, 'D' => 0]);
        $this->assertLedgerFor($this->sell('D', 'UAT ลูกค้า 5'), ['D' => 50_000]);
        $this->assertLedgerFor($this->sell('D', 'UAT ลูกค้า 6'), ['D' => 50_000]);

        foreach ($this->people as $who => $person) {
            $this->assertNull($person->fresh()->current_rank_id, "{$who} holds no rung before the first ranking");
        }
    }

    /* ── ส่วนที่ 6 · จัดขั้นครั้งแรก ──────────────────────────────────── */

    private function part6FirstRanking(): void
    {
        // The sheet quotes this line (§7 ข้อ 6.1), so it is pinned here.
        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => self::SLUG])
            // One line of output carries both halves, and Artisan's expectation
            // consumes the line it matches, so this is asserted as one string.
            ->expectsOutputToContain('Recalculated rank for 4 agent(s) in UAT · Stairstep ทดสอบมือ')
            ->assertSuccessful();

        $this->assertRanks([
            'S' => 'UAT ขั้นเริ่มต้น',     // ฿10,000
            'L' => 'UAT ขั้นผู้นำ',         // ฿10,000 + S ฿10,000 = ฿20,000
            'M' => 'UAT ขั้นผู้จัดการ',     // ฿20,000 + L's ฿20,000 = ฿40,000
            'D' => 'UAT ขั้นผู้อำนวยการ',   // ฿20,000 + M's ฿40,000 = ฿60,000
        ]);

        // The same day, a second run changes nothing — and says why.
        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => self::SLUG])
            ->expectsOutputToContain('not due yet')
            ->expectsOutputToContain('Nothing was recalculated')
            ->assertSuccessful();
    }

    /* ── ส่วนที่ 7 · ดีลทดสอบ ───────────────────────────────────────── */

    private function part7TheTestSales(): void
    {
        // T1 — S sells. Differential up the chain; stops above the breakaway.
        $this->assertLedgerFor($this->sell('S', 'UAT ลูกค้า T1'), [
            'S' => 50_000,   // own rung 5%
            'L' => 70_000,   // 12 − 5
            'M' => 80_000,   // 20 − 12
            'D' => 0,        // M below him is the breakaway rung
        ]);

        // T2 — L sells. A ranked seller is paid their OWN rung (ADR-043).
        $this->assertLedgerFor($this->sell('L', 'UAT ลูกค้า T2'), [
            'L' => 120_000,  // 12%, not the 5% flat rate
            'M' => 80_000,   // 20 − 12
            'D' => 0,
        ]);
    }

    /* ── ส่วนที่ 8 · วันถัดไป ────────────────────────────────────────── */

    private function part8TheNextDay(): void
    {
        // The one thing no endpoint can do: wait a day.
        $this->travel(25)->hours();

        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => self::SLUG])
            ->expectsOutputToContain('Recalculated rank for 4 agent(s)')
            ->assertSuccessful();

        /*
         * M held the breakaway rung when this run began, so M's whole leg
         * stops counting toward D (ADR-044): D is measured on D's own ฿20,000
         * and falls to ผู้นำ. Without that rule D would stand on ฿90,000 and
         * stay ผู้อำนวยการ — that difference is what this part is here to show.
         *
         * Below M the team grew by T1 and T2, and everyone climbs one rung.
         */
        $this->assertRanks([
            'S' => 'UAT ขั้นผู้นำ',         // ฿20,000
            'L' => 'UAT ขั้นผู้จัดการ',     // ฿20,000 + S ฿20,000 = ฿40,000
            'M' => 'UAT ขั้นผู้อำนวยการ',   // ฿20,000 + L's ฿40,000 = ฿60,000
            'D' => 'UAT ขั้นผู้นำ',         // ฿20,000 — M's leg no longer counts
        ]);

        // T3 — S sells again. The breakaway is now L, one rung down the chain.
        $this->assertLedgerFor($this->sell('S', 'UAT ลูกค้า T3'), [
            'S' => 120_000,  // own rung ผู้นำ 12%
            'L' => 80_000,   // 20 − 12
            'M' => 0,        // L below them is the breakaway rung now
            'D' => 0,
        ]);
    }

    /* ── helpers ─────────────────────────────────────────────────────── */

    /**
     * The whole sale as the two screens do it: the agent (portal) records the
     * customer, the deal and the order; the owner (admin → รายการชำระเงิน)
     * attaches the slip and confirms. Returns the referral id.
     */
    private function sell(string $who, string $clientName): int
    {
        $agent = $this->people[$who]->fresh();

        $client = $this->actingAs($agent)->postJson('/api/v1/clients', [
            'name' => $clientName,
            'phone' => '0811111111',
        ])->assertCreated()->json('data.id');

        $referral = $this->actingAs($agent)->postJson('/api/v1/referrals', [
            'client_id' => $client,
            'product_id' => $this->productId,
            'branch' => 'UAT',
            'preferred_time' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data.id');

        // The agent cannot close their own sale (ADR-046) — proven here on
        // every single sale, not just once.
        $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral}/advance")->assertStatus(422);

        $order = $this->actingAs($agent)->postJson('/api/v1/orders', [
            'referral_id' => $referral,
            'payment_method' => 'bank_transfer',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->owner)->post("/api/v1/orders/{$order}/slip", [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order}/confirm")->assertOk();

        return $referral;
    }

    /**
     * @param  array<string, int>  $expected  person => satang; 0 means NO ROW,
     *                                        which is different from a ฿0 row
     */
    private function assertLedgerFor(int $referralId, array $expected): void
    {
        $rows = CommissionLedger::withoutGlobalScopes()->where('referral_id', $referralId)->get();

        foreach ($expected as $who => $satang) {
            $mine = $rows->where('agent_id', $this->people[$who]->id);

            if ($satang === 0) {
                $this->assertCount(0, $mine, "referral {$referralId}: {$who} must have no row");

                continue;
            }

            $this->assertCount(1, $mine, "referral {$referralId}: {$who} must have exactly one row");
            $this->assertSame($satang, $mine->first()->amount_satang, "referral {$referralId}: {$who}");
        }

        // Nobody outside the chain was paid, and nobody was paid twice.
        $this->assertCount(count(array_filter($expected)), $rows, "referral {$referralId}: row count");

        foreach ($rows as $row) {
            $this->assertContains($row->earned_via, [CommissionEarnedVia::Direct, CommissionEarnedVia::StairstepOverride], 'stairstep pays only these two kinds');
        }
    }

    /** @param array<string, string> $expected */
    private function assertRanks(array $expected): void
    {
        foreach ($expected as $who => $rankName) {
            $rank = AgentRank::withoutGlobalScopes()->find($this->people[$who]->fresh()->current_rank_id);
            $this->assertSame($rankName, $rank?->name, "{$who}'s rung");
        }
    }
}
