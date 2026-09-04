<?php

namespace Tests\Feature\Commission;

use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The withdrawal review queue answers for ONE company at a time.
 *
 * ── THE GAP THIS CLOSES (human-reported 2026-09-04) ──
 *
 * "เปลี่ยนบริษัทที่ Admin ... ข้อมูลที่เฉพาะบริษัทไม่เปลี่ยนตาม". On this
 * screen the scope was missing on BOTH sides: the browser sent no
 * company_id, and index() applied no filter if it had. TenantScope pins a
 * Company Admin and deliberately does not pin a Super Admin — they are the
 * cross-company operator — so a Super Admin reviewed every tenant's requests
 * under a header naming one of them. Real money, one "อนุมัติ" away from
 * being approved against the wrong company.
 *
 * ── WHAT IS PINNED, AND WHY EACH ──
 *
 * 1. The scope NARROWS for a Super Admin who asks for one company.
 * 2. No company_id is still the deliberate "ทุกบริษัท" read-across view
 *    (ADR-038), not an empty result — a filter that defaults to nothing
 *    would quietly hide the whole queue.
 * 3. A Company Admin's own scope cannot be widened by the parameter. This
 *    is the security half (BR-6): CompanyScopeFilter must only ever narrow,
 *    and for a non-Super-Admin the query string is ignored outright.
 */
class WithdrawalQueueCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $genesenn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life insurance']);
        $this->genesenn = Company::factory()->create(['name' => 'Genesenn']);

        $this->requestFor($this->thaiLife, 250000);
        $this->requestFor($this->genesenn, 990000);
    }

    /** One pending request, from an agent who belongs to that company. */
    private function requestFor(Company $company, int $amountSatang): CommissionWithdrawalRequest
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        return CommissionWithdrawalRequest::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'amount_satang' => $amountSatang,
            'status' => 'pending_review',
        ]);
    }

    public function test_a_super_admin_asking_for_one_company_gets_only_that_company(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/v1/commission-withdrawals?company_id={$this->thaiLife->id}")
            ->assertOk();

        $this->assertSame([250000], array_column($response->json('data'), 'amount_satang'));
    }

    public function test_no_company_id_is_still_the_read_across_view_not_an_empty_queue(): void
    {
        // ADR-038: null is "ทุกบริษัท", a deliberate state a Super Admin can
        // pick — never "nothing selected, so show nothing".
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/v1/commission-withdrawals')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_a_company_admin_cannot_widen_their_scope_with_the_parameter(): void
    {
        // The security half. TenantScope already pins them; the filter must
        // not become a way to ask for somebody else's queue.
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/commission-withdrawals?company_id={$this->genesenn->id}")
            ->assertOk();

        $this->assertSame([250000], array_column($response->json('data'), 'amount_satang'));
    }
}
