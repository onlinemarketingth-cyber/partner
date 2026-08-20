<?php

namespace Tests\Feature\Link;

use App\Enums\TrackedLinkGroup;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\TrackedLink;
use App\Models\TrackedLinkVisit;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Link\TrackedLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TASK-232 — the short-code registry.
 *
 * ── WHAT THESE PIN, AND WHY EACH ONE WOULD OTHERWISE ROT ──
 *
 * 1. THE ALPHABET. Excluding `0 O o 1 l I` is a decision that looks like a
 *    typo to anyone who finds it later, and "simplifying" it back to plain
 *    base62 breaks nothing that any test would notice — until a customer
 *    reads a code off a flyer and cannot tell O from 0. Asserted directly.
 *
 * 2. BOTH DOORS STAY OPEN. Every long token already out in the world must
 *    keep resolving forever. This is the assertion that a later cleanup —
 *    "nobody uses the old tokens now" — has to argue with.
 *
 * 3. REVOKED, EXPIRED AND UNKNOWN ARE INDISTINGUISHABLE. A resolver that
 *    says "expired" instead of "not found" hands an attacker an oracle for
 *    telling real codes from invented ones. It is also the single most
 *    natural thing for a future maintainer to "improve" in the name of a
 *    better error message.
 *
 * 4. IDEMPOTENCE. Minting twice for one target must give one link, or the
 *    counts the agent is shown split across however many times they
 *    happened to press the share button.
 */
class TrackedLinkTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TrackedLinkService
    {
        return app(TrackedLinkService::class);
    }

    private function shareLink(Company $company): ProductShareLink
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        return ProductShareLink::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'token' => Str::random(64),
        ]);
    }

    public function test_the_code_never_contains_a_character_a_human_can_misread(): void
    {
        $company = Company::factory()->create();

        // 40 links is enough that any of the six forbidden characters would
        // appear by chance if the alphabet included them (40 codes x 10
        // characters, drawn from 62, would be ~64 hits on average).
        $codes = [];
        for ($i = 0; $i < 40; $i++) {
            $codes[] = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $this->shareLink($company))->code;
        }

        foreach (['0', 'O', 'o', '1', 'l', 'I'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                implode('', $codes),
                "'{$forbidden}' is unreadable next to its lookalike on a printed page — codes get typed back in by hand.",
            );
        }
    }

    public function test_a_payment_code_is_longer_than_every_other_group(): void
    {
        // Not decoration: the pay page shows an order's contents and total,
        // and was previously behind a 40-character token. Shortening its
        // front door had to not shorten its protection.
        $this->assertSame(14, TrackedLinkGroup::Payment->codeLength());

        foreach (TrackedLinkGroup::cases() as $group) {
            if ($group !== TrackedLinkGroup::Payment) {
                $this->assertSame(10, $group->codeLength(), "{$group->value} should use the standard length");
            }
        }
    }

    public function test_minting_twice_for_the_same_target_returns_the_same_link(): void
    {
        $company = Company::factory()->create();
        $target = $this->shareLink($company);

        $first = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $target);
        $second = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $target);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TrackedLink::withoutGlobalScopes()->where('target_id', $target->id)->count());
    }

    public function test_a_label_supplied_later_edits_the_link_rather_than_minting_a_second(): void
    {
        $company = Company::factory()->create();
        $target = $this->shareLink($company);

        $this->service()->mintFor(TrackedLinkGroup::ProductShare, $target);
        $again = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $target, null, 'โพสต์กลุ่ม LINE');

        $this->assertSame('โพสต์กลุ่ม LINE', $again->label);
        $this->assertSame(1, TrackedLink::withoutGlobalScopes()->count());
    }

    public function test_resolve_returns_nothing_for_unknown_revoked_and_expired_alike(): void
    {
        $company = Company::factory()->create();
        $link = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $this->shareLink($company));

        $this->assertNotNull($this->service()->resolve($link->code));

        $link->update(['revoked_at' => now()]);
        $this->assertNull($this->service()->resolve($link->code), 'a revoked code must look exactly like an unknown one');

        $link->update(['revoked_at' => null, 'expires_at' => now()->subMinute()]);
        $this->assertNull($this->service()->resolve($link->code), 'an expired code must look exactly like an unknown one');

        $this->assertNull($this->service()->resolve('NEVERMINTED'));
    }

    public function test_a_custom_code_is_refused_for_a_group_that_must_stay_unguessable(): void
    {
        $company = Company::factory()->create();

        $this->expectException(ValidationException::class);
        $this->service()->mintFor(
            TrackedLinkGroup::ProductShare,
            $this->shareLink($company),
            null,
            null,
            'my-product',
        );
    }

    public function test_reserved_words_cannot_be_claimed_as_a_code(): void
    {
        // `admin` and `api` are real paths on the same host. A link that
        // dies because somebody deployed a folder is a failure nobody
        // thinks to look for.
        $this->assertContains('admin', $this->reservedProbe());
        $this->assertContains('api', $this->reservedProbe());
    }

    /** @return list<string> */
    private function reservedProbe(): array
    {
        $reflection = new \ReflectionClass(TrackedLinkService::class);

        /** @var list<string> $reserved */
        $reserved = $reflection->getConstant('RESERVED_CODES');

        return $reserved;
    }

    public function test_a_visit_records_the_referrer_host_but_never_the_full_url(): void
    {
        $company = Company::factory()->create();
        $link = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $this->shareLink($company));

        $request = Request::create('/p/'.$link->code, 'GET');
        $request->headers->set('referer', 'https://line.me/R/ti/g/SECRET-GROUP-ID?q=private+search');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148');

        $visit = $this->service()->recordVisit($link, $request);

        $this->assertSame('line.me', $visit->referrer_host);
        $this->assertStringNotContainsString('SECRET-GROUP-ID', (string) $visit->referrer_host);
        $this->assertStringNotContainsString('private+search', (string) $visit->referrer_host);
        $this->assertSame('mobile', $visit->device_type);
    }

    public function test_a_visit_never_stores_a_raw_ip(): void
    {
        $company = Company::factory()->create();
        $link = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $this->shareLink($company));

        $request = Request::create('/p/'.$link->code, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.42']);
        $visit = $this->service()->recordVisit($link, $request);

        $this->assertNotSame('203.0.113.42', $visit->ip_hash);
        $this->assertSame(64, strlen($visit->ip_hash), 'HMAC-SHA256 hex, per AffiliateLinkClickService');
        // A bare sha256 over the tiny IPv4 space is reversible from a
        // precomputed table, which is why the key matters and is asserted.
        $this->assertNotSame(hash('sha256', '203.0.113.42'), $visit->ip_hash);
    }

    public function test_the_same_visitor_twice_in_one_day_is_one_unique_but_two_clicks(): void
    {
        $company = Company::factory()->create();
        $link = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $this->shareLink($company));

        $request = Request::create('/p/'.$link->code, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.42']);
        $this->service()->recordVisit($link, $request);
        $this->service()->recordVisit($link, $request);

        $link->refresh();
        $this->assertSame(2, $link->click_count);
        $this->assertSame(1, $link->unique_click_count, 'reading a page twice in one evening is not two people');
        $this->assertSame(2, TrackedLinkVisit::withoutGlobalScopes()->count());
    }

    public function test_first_and_last_opened_are_recorded_because_nothing_else_could_answer_that(): void
    {
        $company = Company::factory()->create();
        $link = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $this->shareLink($company));

        $this->assertNull($link->first_clicked_at);

        $request = Request::create('/p/'.$link->code, 'GET');
        $this->service()->recordVisit($link, $request);
        $link->refresh();
        $firstSeen = $link->first_clicked_at;

        $this->assertNotNull($firstSeen);

        $this->travel(2)->hours();
        $this->service()->recordVisit($link, Request::create('/p/'.$link->code, 'GET'));
        $link->refresh();

        $this->assertTrue($firstSeen->equalTo($link->first_clicked_at), 'first open must never move');
        $this->assertTrue($link->last_clicked_at->greaterThan($firstSeen));
    }

    public function test_the_public_page_opens_from_the_short_code_an_d_the_legacy_token(): void
    {
        // The assertion that a future "nobody uses the old tokens now"
        // cleanup has to argue with. Those 64-character URLs are sitting in
        // LINE conversations and customers' inboxes right now; breaking them
        // breaks them for the CUSTOMER, who just sees a dead page.
        $company = Company::factory()->create();
        $target = $this->shareLink($company);
        $agent = User::withoutGlobalScopes()->find($target->agent_id);
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);

        $link = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $target);

        $this->getJson('/api/v1/public/product-shares/'.$target->token)->assertOk();
        $this->getJson('/api/v1/public/product-shares/'.$link->code)->assertOk();
    }

    public function test_only_the_short_code_records_a_visit(): void
    {
        // A legacy token has no tracked link behind it, so it has nothing to
        // count against. Reporting nothing for it is the honest answer for a
        // URL that predates the feature — inventing a link record after the
        // fact would attribute opens to a code nobody was ever given.
        $company = Company::factory()->create();
        $target = $this->shareLink($company);
        $link = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $target);

        $this->getJson('/api/v1/public/product-shares/'.$target->token);
        $this->assertSame(0, TrackedLinkVisit::withoutGlobalScopes()->count());

        $this->getJson('/api/v1/public/product-shares/'.$link->code);
        $this->assertSame(1, TrackedLinkVisit::withoutGlobalScopes()->count());
    }

    public function test_a_revoked_short_code_and_an_invented_one_answer_identically(): void
    {
        $company = Company::factory()->create();
        $link = $this->service()->mintFor(TrackedLinkGroup::ProductShare, $this->shareLink($company));
        $link->update(['revoked_at' => now()]);

        $revoked = $this->getJson('/api/v1/public/product-shares/'.$link->code);
        $invented = $this->getJson('/api/v1/public/product-shares/QQQQQQQQQQ');

        $revoked->assertNotFound();
        $invented->assertNotFound();

        // Compare the MESSAGE, not the whole body: APP_DEBUG is on under
        // test, so each response carries its own stack trace and the raw
        // bodies can never match. The message is what a stranger actually
        // sees in production, and it is what must not distinguish the two.
        $this->assertSame(
            $invented->json('message'),
            $revoked->json('message'),
            'a revoked code that says so is an oracle for telling real codes from invented ones',
        );
    }

    public function test_pressing_share_again_on_an_older_link_gives_it_a_short_code(): void
    {
        /*
         * FOUND IN UAT, 2026-08-20, by pressing "แชร์" and getting the
         * 64-character URL back.
         *
         * ProductShareLinkService::create() is idempotent per agent+product
         * — it reuses an existing unrevoked row — and the mint was added
         * AFTER that early return. So the short code was only ever created
         * for a product the agent had never shared before. On any live
         * database that is the rare case; every link that already existed
         * would have stayed long forever with nothing to explain why.
         *
         * The long token keeps working, so this is not backfilling: it just
         * means the moment an agent is about to hand a link out is the
         * moment it gets a short form to hand out.
         */
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        // A link from before the feature: a row with no tracked link.
        $legacy = ProductShareLink::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'token' => Str::random(64),
        ]);
        $this->assertNull($legacy->trackedLink()->withoutGlobalScopes()->first());

        $this->actingAs($agent)
            ->postJson('/api/v1/product-shares', ['product_id' => $product->id])
            ->assertSuccessful();

        $legacy->refresh();
        $link = $legacy->trackedLink()->withoutGlobalScopes()->first();
        $this->assertNotNull($link, 'pressing share again must give an older link its short code');
        $this->assertSame(10, strlen($link->code));

        // And pressing it a third time must not mint a second one.
        $this->actingAs($agent)->postJson('/api/v1/product-shares', ['product_id' => $product->id])->assertSuccessful();
        $this->assertSame(1, TrackedLink::withoutGlobalScopes()->where('target_id', $legacy->id)->count());
    }
}
