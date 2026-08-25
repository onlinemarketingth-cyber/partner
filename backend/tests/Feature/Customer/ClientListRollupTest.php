<?php

namespace Tests\Feature\Customer;

use App\Enums\OrderStatus;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientActivity;
use App\Models\Company;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client LIST answers "who needs attention today".
 *
 * ── WHY (human, 2026-08-22, with a screenshot: "ดูยากมาก … แค่นี้ น้อยไปมาก") ──
 *
 * The list carried a name, a phone and a status. Finding out whether anybody
 * owed money meant opening customers one at a time.
 *
 * ── WHY SUBQUERIES, AND WHY THAT MATTERS TO THESE TESTS ──
 *
 * The rollups are correlated scalar subqueries, NOT eager-loaded relations —
 * one value per row, no objects, no N+1, on the one endpoint that returns
 * every client in the company. That choice is what these cases defend, and
 * two of them fail loudly if somebody "simplifies" it back into a relation.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 * 1. A ROLLUP COUNTS ANOTHER CLIENT'S ORDERS. `whereColumn` is one line, and
 *    getting it wrong produces numbers — plausible ones — on every row. An
 *    admin chases a payment that belongs to somebody else.
 *
 * 2. THE AGGREGATE COMES BACK AS A STRING. MySQL returns SUM/COUNT as
 *    strings, SQLite as ints. Without the cast the JSON contract changes
 *    shape between dev and production, and `count > 0` is true for "0".
 *
 * 3. ABSENT BECOMES ZERO. show() does not select the rollups. If the resource
 *    sent 0 instead of omitting them, the client modal would state "no
 *    unpaid orders" about a customer it never asked about — a confident
 *    wrong answer, and the exact failure mode that hid the missing `order`
 *    relation for weeks.
 *
 * 4. AN AGENT SEES THE COMPANY'S NUMBERS. TenantScope does not reach inside
 *    a subquery — `withoutGlobalScopes()` is on them deliberately, so the
 *    correlation is what must isolate them.
 */
class ClientListRollupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User} company, admin, agent */
    private function companyWithStaff(): array
    {
        $company = Company::factory()->create();

        return [
            $company,
            User::factory()->companyAdmin()->create(['company_id' => $company->id]),
            User::factory()->agent()->create(['company_id' => $company->id]),
        ];
    }

    private function clientFor(Company $company, User $agent, string $name = 'ลูกค้าทดสอบ'): Client
    {
        return Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
            'name' => $name,
        ]);
    }

    private function orderFor(Client $client, User $agent, OrderStatus $status, int $satang, ?string $slip = null): Order
    {
        $referral = Referral::factory()->create([
            'company_id' => $client->company_id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
        ]);

        return Order::factory()->create([
            'referral_id' => $referral->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'status' => $status,
            'amount_satang' => $satang,
            'slip_path' => $slip,
        ]);
    }

    /** @return array<string, mixed> the list row for $client */
    private function rowFor(User $actor, Client $client): array
    {
        $rows = $this->actingAs($actor)->getJson('/api/v1/clients')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $client->id);

        $this->assertNotNull($row, 'The client did not appear in the list at all.');

        return $row;
    }

    public function test_the_row_reports_unpaid_orders_and_what_they_are_worth(): void
    {
        [$company, $admin, $agent] = $this->companyWithStaff();
        $client = $this->clientFor($company, $agent);

        $this->orderFor($client, $agent, OrderStatus::Pending, 100000);
        $this->orderFor($client, $agent, OrderStatus::Pending, 250000);
        $this->orderFor($client, $agent, OrderStatus::Paid, 890000);

        $row = $this->rowFor($admin, $client);

        $this->assertSame(2, $row['unpaid_orders_count']);
        $this->assertSame(350000, $row['unpaid_amount_satang']);
        $this->assertSame(1, $row['paid_orders_count']);
    }

    public function test_the_row_flags_a_slip_nobody_has_verified(): void
    {
        // The only state blocked on OUR side, and the reason the chip ranks
        // it above "รอชำระ": an admin can act on it right now.
        [$company, $admin, $agent] = $this->companyWithStaff();
        $client = $this->clientFor($company, $agent);

        $this->orderFor($client, $agent, OrderStatus::AwaitingVerification, 890000, 'slips/x.jpg');

        $row = $this->rowFor($admin, $client);

        $this->assertSame(1, $row['awaiting_slip_orders_count']);
        // Still unpaid: a slip is a claim, not a payment.
        $this->assertSame(1, $row['unpaid_orders_count']);
        $this->assertSame(0, $row['paid_orders_count']);
    }

    public function test_an_unverified_order_with_no_slip_is_not_flagged(): void
    {
        // awaiting_verification WITHOUT a slip file is not something anybody
        // can verify. Counting it would send an admin looking for an
        // attachment that does not exist.
        [$company, $admin, $agent] = $this->companyWithStaff();
        $client = $this->clientFor($company, $agent);

        $this->orderFor($client, $agent, OrderStatus::AwaitingVerification, 890000, null);

        $this->assertSame(0, $this->rowFor($admin, $client)['awaiting_slip_orders_count']);
    }

    public function test_one_clients_orders_never_leak_into_anothers_row(): void
    {
        // THE `whereColumn` GUARD. Getting the correlation wrong produces
        // plausible numbers on every row, and an admin chases a payment that
        // belongs to somebody else.
        [$company, $admin, $agent] = $this->companyWithStaff();
        $quiet = $this->clientFor($company, $agent, 'ลูกค้าไม่มีออเดอร์');
        $busy = $this->clientFor($company, $agent, 'ลูกค้ามีออเดอร์');

        $this->orderFor($busy, $agent, OrderStatus::Pending, 500000);

        $this->assertSame(0, $this->rowFor($admin, $quiet)['unpaid_orders_count']);
        $this->assertSame(0, $this->rowFor($admin, $quiet)['unpaid_amount_satang']);
        $this->assertSame(1, $this->rowFor($admin, $busy)['unpaid_orders_count']);
    }

    public function test_the_row_reports_when_the_client_was_last_contacted(): void
    {
        [$company, $admin, $agent] = $this->companyWithStaff();
        $client = $this->clientFor($company, $agent);

        ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
            'occurred_at' => now()->subDays(9),
        ]);
        ClientActivity::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'logged_by_user_id' => $agent->id,
            'occurred_at' => now()->subDays(2),
        ]);

        $row = $this->rowFor($admin, $client);

        // The LATEST, not the first — the question is "who has been left
        // alone", and the oldest entry answers the opposite question.
        $this->assertNotNull($row['last_activity_at']);
        $this->assertStringContainsString(now()->subDays(2)->toDateString(), (string) $row['last_activity_at']);
    }

    public function test_a_client_nobody_has_contacted_reports_null_not_a_date(): void
    {
        // "Never contacted" is a real answer the row shows as
        // "ยังไม่มีการติดต่อ". A fabricated date would hide exactly the
        // customers this column exists to surface.
        [$company, $admin, $agent] = $this->companyWithStaff();
        $client = $this->clientFor($company, $agent);

        $row = $this->rowFor($admin, $client);

        $this->assertArrayHasKey('last_activity_at', $row);
        $this->assertNull($row['last_activity_at']);
    }

    public function test_the_counts_are_json_numbers_even_when_the_driver_returns_strings(): void
    {
        /*
         * MySQL returns COUNT/SUM from a subquery as STRINGS; SQLite returns
         * ints. So a test that goes through the test database proves nothing
         * about the cast — it proves SQLite is SQLite.
         *
         * Found by mutation-checking: removing `(int)` from the Resource left
         * the original version of this case green, because the value had
         * never been a string in the first place. The test was measuring the
         * driver.
         *
         * This version forces the MySQL shape onto the model directly, so it
         * exercises the Resource's cast rather than the connection's. It is
         * the only way to catch a bug that appears in production and nowhere
         * else — and `"0"` being truthy means every client would look like
         * they owed money there.
         */
        [$company, , $agent] = $this->companyWithStaff();
        $client = $this->clientFor($company, $agent);

        $client->setAttribute('unpaid_orders_count', '2');
        $client->setAttribute('unpaid_amount_satang', '350000');
        $client->setAttribute('awaiting_slip_orders_count', '0');
        $client->setAttribute('paid_orders_count', '1');

        $payload = (new ClientResource($client))->toArray(request());

        $this->assertSame(2, $payload['unpaid_orders_count']);
        $this->assertSame(350000, $payload['unpaid_amount_satang']);
        $this->assertSame(0, $payload['awaiting_slip_orders_count']);
        $this->assertSame(1, $payload['paid_orders_count']);
    }

    public function test_the_detail_endpoint_omits_the_rollups_rather_than_sending_zero(): void
    {
        /*
         * show() does not select the subqueries. Sending 0 there would state
         * "no unpaid orders" about a customer nobody asked about — the same
         * confident-wrong-answer failure that hid the missing `order`
         * relation, one level over.
         */
        [$company, $admin, $agent] = $this->companyWithStaff();
        $client = $this->clientFor($company, $agent);
        $this->orderFor($client, $agent, OrderStatus::Pending, 100000);

        $detail = $this->actingAs($admin)->getJson("/api/v1/clients/{$client->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('unpaid_orders_count', $detail);
        $this->assertArrayNotHasKey('last_activity_at', $detail);
    }

    public function test_an_agent_sees_only_their_own_clients_rollups(): void
    {
        // TenantScope does not reach inside a subquery — the correlation is
        // what isolates them, and the row filter is what isolates the list.
        [$company, , $agent] = $this->companyWithStaff();
        $other = User::factory()->agent()->create(['company_id' => $company->id]);

        $mine = $this->clientFor($company, $agent, 'ลูกค้าของฉัน');
        $theirs = $this->clientFor($company, $other, 'ลูกค้าคนอื่น');
        $this->orderFor($theirs, $other, OrderStatus::Pending, 900000);

        $rows = $this->actingAs($agent)->getJson('/api/v1/clients')->assertOk()->json('data');
        $ids = array_column($rows, 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, "An agent must not see another agent's client, rollups or otherwise.");
    }

    public function test_the_list_still_carries_the_deals_it_always_did(): void
    {
        /*
         * The redesign's single biggest win needed no backend work at all:
         * `referrals` was already eager-loaded on index() and the row simply
         * never rendered it. This pins the payload so a future "trim the
         * list payload" cannot quietly take the column away again.
         */
        [$company, $admin, $agent] = $this->companyWithStaff();
        $client = $this->clientFor($company, $agent);
        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
        ]);

        $row = $this->rowFor($admin, $client);

        $this->assertSame($referral->id, $row['referrals'][0]['id']);
        $this->assertNotNull($row['referrals'][0]['product']['name']);
        $this->assertNotNull($row['referrals'][0]['current_stage']['label']);
    }
}
