<?php

namespace Tests\Feature\Platform;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\AccountActivityProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-18 (human: "หากไม่มีกิจกรรม การซื้อขายอะไร ให้สามารถลบ รายชื่อสมาชิก
 * แบบ Soft Delete ได้" + "ปลดอีเมลให้สมัครใหม่ได้").
 *
 * Two things are being asserted here and they pull in opposite directions,
 * which is why they are in one file:
 *
 *   1. AN UNTOUCHED ACCOUNT CAN BE REMOVED, and its address comes back into
 *      circulation — the feature.
 *   2. AN ACCOUNT WITH HISTORY CANNOT, and a switch-off never releases an
 *      address whatever the caller asks for — the guard. Releasing an
 *      address that appears in a ledger, an audit row or somebody's downline
 *      would quietly rewrite who those records look like they belong to.
 */
class MemberRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
    }

    private function agent(array $overrides = []): User
    {
        return User::factory()->agent()->create(array_merge(['company_id' => $this->company->id], $overrides));
    }

    // ── The probe ─────────────────────────────────────────────────────

    public function test_a_brand_new_account_is_pristine(): void
    {
        $this->assertTrue(app(AccountActivityProbe::class)->isPristine($this->agent()));
    }

    public function test_a_referred_client_is_activity(): void
    {
        $agent = $this->agent();
        Client::factory()->create(['company_id' => $this->company->id, 'referring_agent_id' => $agent->id]);

        $blockers = app(AccountActivityProbe::class)->blockers($agent->refresh());

        $this->assertSame([['key' => AccountActivityProbe::CLIENTS, 'count' => 1]], $blockers);
    }

    public function test_somebody_reporting_to_them_is_activity(): void
    {
        // The one that would break something structural rather than just
        // losing a record: removing a manager strands their whole downline.
        $agent = $this->agent();
        $this->agent(['manager_id' => $agent->id]);

        $keys = collect(app(AccountActivityProbe::class)->blockers($agent->refresh()))->pluck('key')->all();

        $this->assertContains(AccountActivityProbe::DOWNLINE, $keys);
    }

    public function test_the_probe_reads_eager_counts_when_the_list_supplied_them(): void
    {
        /*
         * The list path counts seven relations in ONE query. If the probe
         * ignored those and re-queried per row, a roster of 200 people would
         * be 1,400 queries and nobody would notice until it was slow in
         * production.
         */
        $agent = $this->agent();
        Client::factory()->create(['company_id' => $this->company->id, 'referring_agent_id' => $agent->id]);

        $loaded = User::withoutGlobalScopes()
            ->withCount(AccountActivityProbe::countableRelations())
            ->findOrFail($agent->id);

        $this->assertGreaterThan(0, count(AccountActivityProbe::countableRelations()));
        $this->assertNotEmpty(app(AccountActivityProbe::class)->blockers($loaded));
    }

    // ── Removing one ──────────────────────────────────────────────────

    public function test_removing_an_untouched_account_releases_its_email(): void
    {
        $agent = $this->agent(['email' => 'unused@example.com']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/users/{$agent->id}", ['release_email' => true])
            ->assertNoContent();

        $agent = User::withoutGlobalScopes()->withTrashed()->findOrFail($agent->id);

        $this->assertNotNull($agent->deleted_at, 'Soft delete, never a hard one.');
        $this->assertSame('unused@example.com', $agent->email_released_from);
        $this->assertNotSame('unused@example.com', $agent->email);
    }

    public function test_the_released_address_can_be_registered_again(): void
    {
        // The whole point. `unique:users,email` has always seen soft-deleted
        // rows, which is what used to lock the address away for good.
        $agent = $this->agent(['email' => 'reuse@example.com']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/users/{$agent->id}", ['release_email' => true])
            ->assertNoContent();

        $this->assertFalse(
            User::withoutGlobalScopes()->withTrashed()->where('email', 'reuse@example.com')->exists(),
        );
    }

    public function test_the_tombstone_address_cannot_collide_between_two_removals(): void
    {
        // It carries the id, so it is unique by construction — two removals
        // must never fail on the UNIQUE index with an error nobody can read.
        $first = $this->agent(['email' => 'one@example.com']);
        $second = $this->agent(['email' => 'two@example.com']);

        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$first->id}", ['release_email' => true])->assertNoContent();
        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$second->id}", ['release_email' => true])->assertNoContent();

        $emails = User::withoutGlobalScopes()->withTrashed()->whereIn('id', [$first->id, $second->id])->pluck('email');

        $this->assertCount(2, $emails->unique());
    }

    public function test_an_account_with_history_cannot_be_removed(): void
    {
        $agent = $this->agent(['email' => 'trading@example.com']);
        Client::factory()->create(['company_id' => $this->company->id, 'referring_agent_id' => $agent->id]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/users/{$agent->id}", ['release_email' => true])
            ->assertUnprocessable();

        $agent->refresh();
        $this->assertNull($agent->deleted_at, 'Refused outright — not switched off as a consolation.');
        $this->assertSame('trading@example.com', $agent->email);
    }

    public function test_a_switch_off_never_releases_the_address(): void
    {
        /*
         * ปิดใช้งาน from the edit modal hits the same endpoint without the
         * flag. Releasing on a temporary switch-off would be a trap: the
         * admin re-enables the person next week and finds a stranger
         * registered their address in the meantime.
         */
        $agent = $this->agent(['email' => 'paused@example.com']);

        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$agent->id}")->assertNoContent();

        $agent = User::withoutGlobalScopes()->withTrashed()->findOrFail($agent->id);

        $this->assertNotNull($agent->deleted_at);
        $this->assertSame('paused@example.com', $agent->email);
        $this->assertNull($agent->email_released_from);
    }

    // ── Putting one back ──────────────────────────────────────────────

    public function test_restoring_gives_the_address_back(): void
    {
        $agent = $this->agent(['email' => 'back@example.com']);

        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$agent->id}", ['release_email' => true])->assertNoContent();
        $this->actingAs($this->admin)->postJson("/api/v1/users/{$agent->id}/restore")->assertOk();

        $agent = User::withoutGlobalScopes()->findOrFail($agent->id);

        $this->assertSame('back@example.com', $agent->email);
        $this->assertNull($agent->email_released_from, 'The column means "waiting to come back" — and it no longer is.');
        $this->assertNull($agent->deleted_at);
    }

    public function test_restoring_is_refused_when_somebody_else_took_the_address(): void
    {
        /*
         * The honest cost of releasing an address, and the reason the
         * confirm dialog warns about it: this has to be a readable refusal,
         * not a unique-constraint error surfacing as a 500.
         */
        $agent = $this->agent(['email' => 'contested@example.com']);

        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$agent->id}", ['release_email' => true])->assertNoContent();

        $this->agent(['email' => 'contested@example.com']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/users/{$agent->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertNotNull(
            User::withoutGlobalScopes()->withTrashed()->findOrFail($agent->id)->deleted_at,
            'Still removed — a failed restore must not half-succeed.',
        );
    }

    // ── What the screen is told ───────────────────────────────────────

    public function test_the_list_reports_why_a_row_cannot_be_removed(): void
    {
        $clean = $this->agent();
        $busy = $this->agent();
        Client::factory()->create(['company_id' => $this->company->id, 'referring_agent_id' => $busy->id]);

        $rows = collect(
            $this->actingAs($this->admin)
                ->getJson('/api/v1/users?with_activity=1')
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertSame([], $rows[$clean->id]['removal_blockers']);
        $this->assertNotEmpty($rows[$busy->id]['removal_blockers']);
        $this->assertSame(AccountActivityProbe::CLIENTS, $rows[$busy->id]['removal_blockers'][0]['key']);
    }

    public function test_the_field_is_absent_for_callers_that_did_not_ask(): void
    {
        // Absent must not be readable as "nothing is in the way" — the
        // screen treats undefined as "do not offer the button".
        $agent = $this->agent();

        $row = collect(
            $this->actingAs($this->admin)->getJson('/api/v1/users')->assertOk()->json('data')
        )->firstWhere('id', $agent->id);

        $this->assertArrayNotHasKey('removal_blockers', $row);
    }

    public function test_a_company_admin_cannot_remove_somebody_elses_agent(): void
    {
        // BR-6 — unchanged by any of this: the Policy runs first.
        $elsewhere = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/users/{$elsewhere->id}", ['release_email' => true])
            ->assertNotFound();

        $this->assertNull($elsewhere->refresh()->deleted_at);
    }
}
