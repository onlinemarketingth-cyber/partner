<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-246 — the three account actions "จัดการผู้ใช้ระบบ" did not have.
 *
 * The endpoints all existed; the screen simply never called them, so a page
 * named for managing system users could change a role, close an account and
 * reset a password — but not OPEN an account, not fix a typo in the address
 * somebody signs in with, and never once read the `move_company` permission it
 * had been receiving since the day it was built.
 *
 * These tests pin the exact payloads that screen now sends, so a rename or a
 * rule change on either side fails here rather than in front of an admin.
 */
class SystemUserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $aia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $this->aia = Company::factory()->create(['name' => 'AIA']);
    }

    // ── Create ───────────────────────────────────────────────────────

    public function test_a_super_admin_creates_an_admin_in_the_company_they_named(): void
    {
        /*
         * company_id is REQUIRED from a Super Admin and there is nothing to
         * infer it from — they belong to no company. The screen sends the one
         * scoped in the header, which is the only answer it honestly has.
         */
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/users', [
                'company_id' => $this->aia->id,
                'first_name' => 'อารีย์',
                'last_name' => 'ทองดี',
                'email' => 'aree@example.com',
                'password' => 'Str0ngPassword',
                'role' => 'company_admin',
            ])
            ->assertCreated();

        $created = User::where('email', 'aree@example.com')->firstOrFail();

        $this->assertSame($this->aia->id, $created->company_id);
        $this->assertTrue($created->isCompanyAdmin());
    }

    public function test_a_company_admin_creates_inside_their_own_company_without_naming_it(): void
    {
        // And naming it is a rule violation, not an oversight: there is exactly
        // one company they could mean, so the server infers it.
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($actor)
            ->postJson('/api/v1/users', [
                'first_name' => 'สมชาย',
                'last_name' => 'ใจดี',
                'email' => 'somchai@example.com',
                'password' => 'Str0ngPassword',
                'role' => 'agent',
            ])
            ->assertCreated();

        $this->assertSame($this->thaiLife->id, User::where('email', 'somchai@example.com')->firstOrFail()->company_id);
    }

    public function test_a_company_admin_cannot_create_someone_in_another_company(): void
    {
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($actor)
            ->postJson('/api/v1/users', [
                'company_id' => $this->aia->id,
                'first_name' => 'สมชาย',
                'last_name' => 'ใจดี',
                'email' => 'somchai@example.com',
                'password' => 'Str0ngPassword',
                'role' => 'agent',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_the_password_is_never_echoed_back(): void
    {
        // The admin typed it and will hand it over in person; a response that
        // repeated it is one more place it can be read off a screen.
        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/users', [
                'company_id' => $this->aia->id,
                'first_name' => 'อารีย์',
                'last_name' => 'ทองดี',
                'email' => 'aree@example.com',
                'password' => 'Str0ngPassword',
                'role' => 'company_admin',
            ])
            ->assertCreated();

        $this->assertStringNotContainsString('Str0ngPassword', $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    // ── Edit the four fields the screen owns ─────────────────────────

    public function test_the_login_email_can_be_corrected(): void
    {
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        $target = User::factory()->agent()->create([
            'company_id' => $this->thaiLife->id,
            'email' => 'typo@example.com',
        ]);

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", [
                'first_name' => 'สมชาย',
                'last_name' => 'ใจดี',
                'email' => 'correct@example.com',
                'phone' => null,
            ])
            ->assertOk();

        $this->assertSame('correct@example.com', $target->refresh()->email);
    }

    public function test_a_blank_phone_is_sent_as_null_and_accepted(): void
    {
        // '' would fail the string rule; null is what the column holds for
        // "no phone", so that is what an emptied field sends.
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        $target = User::factory()->agent()->create([
            'company_id' => $this->thaiLife->id,
            'phone' => '0812345678',
        ]);

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", ['phone' => null])
            ->assertOk();

        $this->assertNull($target->refresh()->phone);
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $actor = User::factory()->superAdmin()->create();
        User::factory()->agent()->create(['company_id' => $this->thaiLife->id, 'email' => 'taken@example.com']);
        $target = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_company_admin_cannot_edit_someone_in_another_company(): void
    {
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        $target = User::factory()->agent()->create(['company_id' => $this->aia->id]);

        // 404, not 403: TenantScope hides the row, and a 403 would confirm it
        // exists — an existence oracle across tenants (BR-6).
        $this->actingAs($actor)
            ->putJson("/api/v1/users/{$target->id}", ['email' => 'new@example.com'])
            ->assertNotFound();
    }

    // ── Move to another company ──────────────────────────────────────

    public function test_a_super_admin_moves_an_account_to_another_company(): void
    {
        $target = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/users/{$target->id}/move-company", ['company_id' => $this->aia->id])
            ->assertOk();

        $this->assertSame($this->aia->id, $target->refresh()->company_id);
    }

    public function test_a_company_admin_may_not_move_anybody(): void
    {
        $actor = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        $target = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($actor)
            ->postJson("/api/v1/users/{$target->id}/move-company", ['company_id' => $this->aia->id])
            ->assertForbidden();

        $this->assertSame($this->thaiLife->id, $target->refresh()->company_id);
    }

    public function test_the_permission_payload_agrees_with_the_endpoint(): void
    {
        /*
         * The screen renders the "ย้ายบริษัท" button from `move_company`
         * alone. If that answer ever drifted from UserPolicy::move, the button
         * would be back to guessing — just with more confidence.
         */
        $target = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $superAdminSees = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/users?with_permissions=1')
            ->assertOk()
            ->json('data.0.permissions.move_company');

        $companyAdminSees = $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]))
            ->getJson('/api/v1/users?with_permissions=1')
            ->assertOk()
            ->json('data.0.permissions.move_company');

        $this->assertTrue($superAdminSees);
        $this->assertFalse($companyAdminSees);
        $this->assertNotNull($target->company_id);
    }
}
