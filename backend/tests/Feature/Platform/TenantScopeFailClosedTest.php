<?php

namespace Tests\Feature\Platform;

use App\Models\Client;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\ThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-17 — the hole in TenantScope, and the shape that made it real.
 *
 * ── WHAT WAS WRONG ──
 *
 * TenantScope::apply() read, in full:
 *
 *     if (isset($user->company_id)) {
 *         $builder->where(..., $user->company_id);
 *     }
 *
 * An authenticated user who is not a Super Admin and has no `company_id` was
 * therefore NOT FILTERED AT ALL. That is not "sees their own tenant" — it is
 * "sees EVERY tenant". BR-6 inverted, by the one shape nobody writes a test
 * for, and inverted silently: the screen fills with data and looks right.
 *
 * It stayed theoretical while every non-super-admin row carried a company_id.
 * Then Company Partner arrived — a role that by design has `company_id = null`
 * and `supplier_id` instead — and the theoretical shape became the normal one.
 *
 * ── WHY THE TWO SCOPES FAIL DIFFERENTLY ──
 *
 * TenantScope returns NOTHING: every row it guards belongs to exactly one
 * tenant, and "belongs to somebody else" is never an acceptable answer.
 *
 * SharedOrTenantScope returns the SHARED rows only: it guards tables where a
 * NULL company_id genuinely means "platform-wide, anybody may use it", so
 * somebody with no tenant should see those and nothing else.
 *
 * ── WHY THIS IS A TEST AND NOT A COMMENT ──
 *
 * The failure it prevents is invisible in the UI and produces no error. The
 * only thing that can notice it is an assertion.
 */
class TenantScopeFailClosedTest extends TestCase
{
    use RefreshDatabase;

    /** A user with a role that is scoped, and no tenant to scope it to. */
    private function tenantlessUser(): User
    {
        $supplier = Supplier::factory()->create();

        return User::factory()->create([
            'company_id' => null,
            'supplier_id' => $supplier->id,
            'role' => 'company_partner',
        ]);
    }

    public function test_a_user_with_no_company_sees_no_tenant_rows_rather_than_all_of_them(): void
    {
        $companyOne = Company::factory()->create();
        $companyTwo = Company::factory()->create();

        Client::factory()->create(['company_id' => $companyOne->id]);
        Client::factory()->create(['company_id' => $companyTwo->id]);

        $this->actingAs($this->tenantlessUser());

        // Before the fix this returned 2 — every client belonging to every
        // company on the platform, names and phone numbers included.
        $this->assertSame(0, Client::query()->count());
    }

    public function test_the_same_query_still_scopes_normally_for_a_user_who_has_a_company(): void
    {
        // The fix must not turn the ordinary path into a blank screen.
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();

        Client::factory()->count(2)->create(['company_id' => $mine->id]);
        Client::factory()->create(['company_id' => $theirs->id]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $mine->id]));

        $this->assertSame(2, Client::query()->count());
    }

    public function test_a_super_admin_still_sees_everything(): void
    {
        // A Super Admin also has a null company_id, and must NOT be caught by
        // the new branch — they are exempt one line earlier, which is exactly
        // the distinction this asserts.
        $one = Company::factory()->create();
        $two = Company::factory()->create();

        Client::factory()->create(['company_id' => $one->id]);
        Client::factory()->create(['company_id' => $two->id]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertSame(2, Client::query()->count());
    }

    public function test_an_unauthenticated_query_is_unaffected(): void
    {
        // Public endpoints run with no actor and carry their own checks; the
        // scope has always waved them through and must keep doing so, or every
        // public surface in the app returns nothing.
        $company = Company::factory()->create();
        Client::factory()->create(['company_id' => $company->id]);

        $this->assertSame(1, Client::query()->count());
    }

    public function test_shared_rows_stay_visible_to_a_user_with_no_company(): void
    {
        $company = Company::factory()->create();

        // Written directly rather than through a factory: there is no
        // ThemePresetFactory, and the two rows only need a company_id apiece
        // for the scope to have something to decide about.
        $shared = ThemePreset::create(['company_id' => null, 'name' => 'ชุดกลาง', 'colors' => []]);
        $owned = ThemePreset::create(['company_id' => $company->id, 'name' => 'ของบริษัท', 'colors' => []]);

        $this->actingAs($this->tenantlessUser());

        $visible = ThemePreset::query()->pluck('id');

        // The shared one, and only the shared one. Before the fix this
        // returned both — including the palette belonging to a tenant this
        // account has nothing to do with.
        $this->assertTrue($visible->contains($shared->id));
        $this->assertFalse($visible->contains($owned->id));
    }
}
