<?php

namespace Tests\Feature\Link;

use App\Enums\TrackedLinkGroup;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\TrackedLink;
use App\Models\User;
use App\Services\Link\TrackedLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-234 — the links dashboard.
 *
 * ── THE THREE THAT MATTER ──
 *
 * 1. AN AGENT SEES ONLY THEIR OWN. These rows say who is selling what and
 *    how well. One agent reading another's numbers is a leak of the
 *    company's commercial position to a person with no need for it, and
 *    "the list is filtered in the UI" is not a defence — the id is in the
 *    URL.
 *
 * 2. THE CONVERSION RATE DIVIDES BY UNIQUE OPENS. A customer who reads the
 *    page four times before buying is ONE person who converted. Dividing by
 *    total opens says the link is four times worse than it is, and agents
 *    use exactly this number to choose where to spend their effort.
 *
 * 3. NOTHING-YET IS NULL, NOT ZERO. "0%" reads as "this link is failing";
 *    "—" reads as "nobody has opened it yet". Those call for opposite
 *    actions from the agent, and collapsing them into 0 is the single most
 *    natural "simplification" for somebody tidying this later.
 */
class TrackedLinkStatsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TrackedLinkService
    {
        return app(TrackedLinkService::class);
    }

    private function linkFor(Company $company, User $owner): TrackedLink
    {
        $share = ProductShareLink::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $owner->id,
            'product_id' => Product::factory()->create(['company_id' => $company->id])->id,
            'token' => Str::random(64),
        ]);

        return $this->service()->mintFor(TrackedLinkGroup::ProductShare, $share, $owner);
    }

    private function open(TrackedLink $link, string $ip, ?string $referer = null, ?string $ua = null): void
    {
        $request = Request::create('/p/'.$link->code, 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        if ($referer) {
            $request->headers->set('referer', $referer);
        }
        if ($ua) {
            $request->headers->set('User-Agent', $ua);
        }

        $this->service()->recordVisit($link, $request);
    }

    public function test_an_agent_sees_only_the_links_they_created(): void
    {
        $company = Company::factory()->create();
        $me = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleague = User::factory()->agent()->create(['company_id' => $company->id]);

        $mine = $this->linkFor($company, $me);
        $theirs = $this->linkFor($company, $colleague);

        $response = $this->actingAs($me)->getJson('/api/v1/tracked-links')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, "an agent must not see a colleague's numbers");
    }

    public function test_an_agent_cannot_reach_a_colleagues_link_by_id(): void
    {
        // The list being filtered is not a defence — the id is in the URL.
        $company = Company::factory()->create();
        $me = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleague = User::factory()->agent()->create(['company_id' => $company->id]);
        $theirs = $this->linkFor($company, $colleague);

        $this->actingAs($me)->getJson('/api/v1/tracked-links/'.$theirs->id)->assertForbidden();
    }

    public function test_a_company_admin_sees_every_link_in_their_company_and_no_others(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $mine->id]);

        $ours = $this->linkFor($mine, User::factory()->agent()->create(['company_id' => $mine->id]));
        $foreign = $this->linkFor($theirs, User::factory()->agent()->create(['company_id' => $theirs->id]));

        $ids = array_column(
            $this->actingAs($admin)->getJson('/api/v1/tracked-links')->assertOk()->json('data'),
            'id',
        );

        $this->assertContains($ours->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_the_conversion_rate_divides_by_unique_opens_not_total(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = $this->linkFor($company, $agent);

        // One person, four reads, then they buy.
        for ($i = 0; $i < 4; $i++) {
            $this->open($link, '203.0.113.42');
        }
        $this->service()->recordConversion($link->refresh());

        $stats = $this->actingAs($agent)
            ->getJson('/api/v1/tracked-links/'.$link->id)
            ->assertOk()
            ->json('data.stats');

        $this->assertSame(4, $stats['click_count']);
        $this->assertSame(1, $stats['unique_click_count']);
        // 100%, not 25% — one person came and that person bought.
        //
        // assertEquals, not assertSame: round() gives a float and JSON
        // encodes 100.0 as `100`, so the type on the wire is an artefact of
        // serialisation rather than a decision worth pinning. The VALUE is
        // the assertion.
        $this->assertEquals(100, $stats['conversion_rate']);
    }

    public function test_a_link_nobody_has_opened_reports_null_not_zero_percent(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = $this->linkFor($company, $agent);

        $row = $this->actingAs($agent)->getJson('/api/v1/tracked-links')->json('data.0');

        $this->assertNull($row['conversion_rate'], '"0%" says the link is failing; "—" says nobody has looked yet');
        $this->assertNull($row['first_clicked_at']);
    }

    public function test_visitors_with_no_referrer_are_a_named_bucket_not_dropped(): void
    {
        // Typing the URL, scanning a QR, or an app that strips the header
        // are all real traffic. Dropping them would make the percentages
        // add up to less than the click count.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = $this->linkFor($company, $agent);

        $this->open($link, '203.0.113.1', 'https://line.me/x');
        $this->open($link, '203.0.113.2');

        $referrers = $this->actingAs($agent)
            ->getJson('/api/v1/tracked-links/'.$link->id)
            ->json('data.stats.referrers');

        $total = array_sum(array_column($referrers, 'count'));
        $this->assertSame(2, $total, 'every open must land in exactly one bucket');
        $this->assertContains('เข้าตรง', array_column($referrers, 'label'));
    }

    public function test_the_hourly_chart_always_has_all_twenty_four_buckets(): void
    {
        // A chart containing only the hours something happened is not a
        // chart of when people read this — it is that chart with the quiet
        // hours silently removed and the axis lying about the gap.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = $this->linkFor($company, $agent);
        $this->open($link, '203.0.113.1');

        $byHour = $this->actingAs($agent)
            ->getJson('/api/v1/tracked-links/'.$link->id)
            ->json('data.stats.by_hour');

        $this->assertCount(24, $byHour);
        $this->assertSame(range(0, 23), array_column($byHour, 'hour'));
    }

    public function test_the_summary_rolls_up_per_group(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $link = $this->linkFor($company, $agent);
        $this->open($link, '203.0.113.1');

        $summary = $this->actingAs($admin)
            ->getJson('/api/v1/tracked-links?summary=1')
            ->assertOk()
            ->json('data');

        $row = collect($summary)->firstWhere('group', 'product_share');
        $this->assertNotNull($row);
        $this->assertSame('แชร์สินค้า', $row['label']);
        $this->assertSame(1, $row['link_count']);
        $this->assertSame(1, $row['clicks']);
    }

    public function test_only_the_label_can_be_edited(): void
    {
        // Expiry and revocation belong to the thing BEHIND the link, so
        // that there is exactly one place where "is this still live" is
        // decided. Two places would eventually disagree.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = $this->linkFor($company, $agent);

        $this->actingAs($agent)
            ->putJson('/api/v1/tracked-links/'.$link->id, ['label' => 'โพสต์กลุ่ม LINE'])
            ->assertOk()
            ->assertJsonPath('data.label', 'โพสต์กลุ่ม LINE');

        $this->actingAs($agent)
            ->putJson('/api/v1/tracked-links/'.$link->id, ['label' => null, 'code' => 'hijacked'])
            ->assertOk();

        $this->assertSame($link->code, $link->fresh()->code, 'the code is the printed part of the URL');
    }

    public function test_there_is_no_way_to_delete_a_tracked_link(): void
    {
        // A deleted link cascades its visit rows away and NULLs the
        // attribution on the orders and agents it produced — the exact
        // failure TASK-236 is fixing on the affiliate side.
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();
        $link = $this->linkFor($company, User::factory()->agent()->create(['company_id' => $company->id]));

        $this->actingAs($admin)
            ->deleteJson('/api/v1/tracked-links/'.$link->id)
            ->assertStatus(405);
    }

    public function test_the_pre_existing_view_counter_is_finally_surfaced(): void
    {
        // `product_share_links.view_count` has been incremented on every
        // public page load since TASK-056 and rendered by no screen in
        // either frontend. This is the first time anybody can see it.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = $this->linkFor($company, $agent);

        $link->target()->withoutGlobalScopes()->first()->update(['view_count' => 137]);

        $row = $this->actingAs($agent)->getJson('/api/v1/tracked-links')->json('data.0');

        $this->assertSame(137, $row['legacy_view_count']);
        // Reported SEPARATELY, never folded into click_count: the two count
        // different things over different periods, and a sum would be true
        // of neither.
        $this->assertSame(0, $row['click_count']);
    }

    public function test_a_group_with_no_such_counter_reports_null_not_zero(): void
    {
        // 0 would claim "this link predates the short code and nobody
        // opened it", which is a statement about a column that does not
        // exist on this target at all.
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();
        $service = app(TrackedLinkService::class);
        $link = $service->mintFor(TrackedLinkGroup::CompanyLogin, $company, $admin);

        $row = $this->actingAs($admin)->getJson('/api/v1/tracked-links/'.$link->id)->json('data');

        $this->assertNull($row['legacy_view_count']);
    }
}
