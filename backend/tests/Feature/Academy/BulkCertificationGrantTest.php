<?php

namespace Tests\Feature\Academy;

use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-28 — the roster's "อนุมัติการเรียน N คน": the BR-1 admin override
 * (ManualCertificationTest) for several agents at once. Same ability, same
 * target rule, same audit — only the list is new, so the list is what these
 * tests are about: all-or-nothing, already-held reported, and nobody outside
 * the admin's reach slipped in among the rest.
 */
class BulkCertificationGrantTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private CertTier $basic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        $this->basic = CertTier::factory()->create(['key' => 'basic']);
    }

    private function agents(int $n): array
    {
        return User::factory()->agent()->count($n)->create(['company_id' => $this->company->id])->all();
    }

    private function bulk(User $actor, array $ids, ?CertTier $tier = null)
    {
        return $this->actingAs($actor)->postJson('/api/v1/user-certifications/bulk', [
            'user_ids' => $ids,
            'cert_tier_id' => ($tier ?? $this->basic)->id,
        ]);
    }

    public function test_it_grants_every_selected_agent_and_logs_each(): void
    {
        [$m, $l, $s] = $this->agents(3);

        $this->bulk($this->admin, [$m->id, $l->id, $s->id])
            ->assertOk()
            ->assertJsonPath('data.granted_user_ids', [$m->id, $l->id, $s->id])
            ->assertJsonPath('data.already_held_user_ids', [])
            ->assertJsonPath('data.cert_tier.key', 'basic');

        foreach ([$m, $l, $s] as $agent) {
            $this->assertTrue($agent->fresh()->hasPassedCertTier('basic'));
        }
        $this->assertSame(3, AuditLog::where('action', 'user_certification.manual_grant')->where('actor_user_id', $this->admin->id)->count());
    }

    public function test_an_agent_who_already_holds_the_tier_is_reported_not_logged_again(): void
    {
        [$d, $m] = $this->agents(2);
        UserCertification::create(['company_id' => $this->company->id, 'user_id' => $d->id, 'cert_tier_id' => $this->basic->id, 'passed_at' => now()]);

        $this->bulk($this->admin, [$d->id, $m->id])
            ->assertOk()
            ->assertJsonPath('data.granted_user_ids', [$m->id])
            ->assertJsonPath('data.already_held_user_ids', [$d->id]);

        $this->assertSame(1, UserCertification::where('user_id', $d->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'user_certification.manual_grant')->count());
    }

    public function test_one_agent_from_another_company_refuses_the_whole_list(): void
    {
        [$m] = $this->agents(1);
        $foreign = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->bulk($this->admin, [$m->id, $foreign->id])->assertStatus(422);

        $this->assertFalse($m->fresh()->hasPassedCertTier('basic'), 'nobody is half-approved');
        $this->assertFalse($foreign->fresh()->hasPassedCertTier('basic'));
    }

    public function test_only_active_agents_can_be_named(): void
    {
        [$m] = $this->agents(1);
        $otherAdmin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        [$removed] = $this->agents(1);
        $removed->delete();

        $this->bulk($this->admin, [$m->id, $otherAdmin->id])->assertStatus(422);
        $this->bulk($this->admin, [$m->id, $removed->id])->assertStatus(422);

        $this->assertSame(0, UserCertification::count());
    }

    public function test_an_agent_cannot_bulk_grant(): void
    {
        [$me, $friend] = $this->agents(2);

        $this->bulk($me, [$me->id, $friend->id])->assertForbidden();

        $this->assertSame(0, UserCertification::count());
    }

    public function test_a_super_admin_may_grant_across_companies(): void
    {
        [$m] = $this->agents(1);
        $elsewhere = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->bulk(User::factory()->superAdmin()->create(), [$m->id, $elsewhere->id])
            ->assertOk()
            ->assertJsonPath('data.granted_user_ids', [$m->id, $elsewhere->id]);
    }

    public function test_the_list_is_bounded_and_may_not_repeat(): void
    {
        [$m] = $this->agents(1);

        $this->bulk($this->admin, [])->assertStatus(422);
        $this->bulk($this->admin, [$m->id, $m->id])->assertStatus(422);
        $this->bulk($this->admin, range(1, 201))->assertStatus(422)->assertJsonValidationErrors('user_ids');
    }
}
