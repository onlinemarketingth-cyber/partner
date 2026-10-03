<?php

namespace Tests\Feature\Notification;

use App\Enums\DevicePlatform;
use App\Models\Company;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2026-10-02 — MOB-10. POST / DELETE /api/v1/me/devices.
 *
 * ── WHAT BREAKS SILENTLY HERE, AND WHY EACH CASE EXISTS ──
 *
 *  1. SOMEONE ELSE'S PHONE KEEPS BUZZING. A phone that changes hands
 *     re-registers the same FCM token. If the row stays with the previous
 *     owner, their notifications appear on the new owner's lock screen —
 *     nothing errors, it simply goes to the wrong person.
 *
 *  2. LOGOUT DOES NOT STOP PUSHES. If DELETE cannot find the caller's row
 *     (a tenant filter hiding it after a company move), the 204 still comes
 *     back and the phone keeps receiving the account's notifications.
 *
 *  3. A TOKEN PROBE. DELETE answers the same 204 whether or not the token
 *     existed and whoever owns it, and never deletes another user's row —
 *     otherwise anyone could silence someone else's phone or learn that a
 *     token is registered (§5 rule 5, IDOR).
 *
 *  4. THE TOKEN LEAKS BACK OUT. The response must not echo the token.
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'fcm-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function agent(?Company $company = null): User
    {
        $company ??= Company::factory()->create();

        return User::factory()->agent()->create(['company_id' => $company->id]);
    }

    private function rowFor(string $token): ?DeviceToken
    {
        return DeviceToken::withoutGlobalScopes()->where('token_hash', DeviceToken::hashToken($token))->first();
    }

    // ── Register ──────────────────────────────────────────────────────────

    public function test_an_agent_registers_a_phone(): void
    {
        $agent = $this->agent();

        $response = $this->actingAs($agent)->postJson('/api/v1/me/devices', [
            'platform' => 'ios',
            'token' => self::TOKEN,
            'app_version' => '1.0.0',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.platform', 'ios')
            ->assertJsonPath('data.app_version', '1.0.0')
            ->assertJsonStructure(['data' => ['id', 'platform', 'app_version']]);

        // Case 4 — the push credential is never echoed back.
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertSame(['id', 'platform', 'app_version'], array_keys($response->json('data')));

        $row = $this->rowFor(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertSame($agent->id, $row->user_id);
        $this->assertSame($agent->company_id, $row->company_id);
        $this->assertSame(DevicePlatform::Ios, $row->platform);
        $this->assertSame(self::TOKEN, $row->token);
        $this->assertNotNull($row->last_seen_at);
    }

    public function test_registering_the_same_token_again_updates_the_one_row(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->postJson('/api/v1/me/devices', ['platform' => 'android', 'token' => self::TOKEN, 'app_version' => '1.0.0'])->assertCreated();
        $this->travel(5)->minutes();
        $this->actingAs($agent)->postJson('/api/v1/me/devices', ['platform' => 'android', 'token' => self::TOKEN, 'app_version' => '1.1.0'])
            ->assertCreated()
            ->assertJsonPath('data.app_version', '1.1.0');

        $this->assertSame(1, DeviceToken::withoutGlobalScopes()->count());
        $this->assertSame('1.1.0', $this->rowFor(self::TOKEN)->app_version);
    }

    public function test_a_phone_that_changes_hands_moves_to_the_new_user_even_across_companies(): void
    {
        // Case 1. The previous owner is in ANOTHER company: the move has to
        // see past TenantScope, and must not hand the caller that row.
        $previous = $this->agent();
        $this->actingAs($previous)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => self::TOKEN])->assertCreated();
        $previousRowId = $this->rowFor(self::TOKEN)->id;

        $next = $this->agent();
        $response = $this->actingAs($next)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => self::TOKEN])
            ->assertCreated();

        $this->assertSame(1, DeviceToken::withoutGlobalScopes()->count());
        $row = $this->rowFor(self::TOKEN);
        $this->assertSame($next->id, $row->user_id);
        $this->assertSame($next->company_id, $row->company_id);
        $this->assertNotSame($previousRowId, $response->json('data.id'));
        $this->assertFalse(DeviceToken::withoutGlobalScopes()->where('user_id', $previous->id)->exists());
    }

    public function test_one_user_may_have_several_phones(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => 'token-phone'])->assertCreated();
        $this->actingAs($agent)->postJson('/api/v1/me/devices', ['platform' => 'android', 'token' => 'token-tablet'])->assertCreated();

        $this->assertSame(2, DeviceToken::withoutGlobalScopes()->where('user_id', $agent->id)->count());
    }

    public function test_a_user_without_a_company_cannot_register(): void
    {
        // notifications.company_id is NOT NULL, so this user can never be
        // notified; a stored token would never be read.
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => self::TOKEN])
            ->assertForbidden();

        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());
    }

    public function test_registering_requires_authentication(): void
    {
        $this->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => self::TOKEN])->assertUnauthorized();
        $this->deleteJson('/api/v1/me/devices', ['token' => self::TOKEN])->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidRegistrations(): array
    {
        return [
            'unknown platform' => [['platform' => 'windows', 'token' => 'x'], 'platform'],
            'missing platform' => [['token' => 'x'], 'platform'],
            'missing token' => [['platform' => 'ios'], 'token'],
            'token too long' => [['platform' => 'ios', 'token' => str_repeat('a', 4097)], 'token'],
            'token not a string' => [['platform' => 'ios', 'token' => ['a']], 'token'],
            'app_version too long' => [['platform' => 'ios', 'token' => 'x', 'app_version' => str_repeat('1', 33)], 'app_version'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidRegistrations')]
    public function test_invalid_registrations_are_rejected(array $payload, string $field): void
    {
        $this->actingAs($this->agent())
            ->postJson('/api/v1/me/devices', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());
    }

    public function test_a_4096_character_token_is_accepted(): void
    {
        $token = str_repeat('t', 4096);

        $this->actingAs($this->agent())
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'token' => $token])
            ->assertCreated();

        $this->assertSame($token, $this->rowFor($token)->token);
    }

    // ── Unregister ────────────────────────────────────────────────────────

    public function test_an_agent_unregisters_their_own_phone(): void
    {
        $agent = $this->agent();
        $this->actingAs($agent)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => self::TOKEN])->assertCreated();

        $this->actingAs($agent)->deleteJson('/api/v1/me/devices', ['token' => self::TOKEN])->assertNoContent();

        $this->assertNull($this->rowFor(self::TOKEN));
    }

    public function test_unregistering_an_unknown_token_is_a_silent_204(): void
    {
        $this->actingAs($this->agent())
            ->deleteJson('/api/v1/me/devices', ['token' => 'never-registered'])
            ->assertNoContent();
    }

    public function test_another_companys_user_cannot_delete_a_token_and_learns_nothing(): void
    {
        // Case 3, cross-tenant (§5 rule 5).
        $owner = $this->agent();
        $this->actingAs($owner)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => self::TOKEN])->assertCreated();

        $intruder = $this->agent();
        $this->actingAs($intruder)->deleteJson('/api/v1/me/devices', ['token' => self::TOKEN])->assertNoContent();

        $row = $this->rowFor(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertSame($owner->id, $row->user_id);
    }

    public function test_a_colleague_in_the_same_company_cannot_delete_a_token_either(): void
    {
        // Case 3, cross-agent inside one tenant: TenantScope alone would not
        // stop this, the user_id narrowing does.
        $company = Company::factory()->create();
        $owner = $this->agent($company);
        $this->actingAs($owner)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => self::TOKEN])->assertCreated();

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->deleteJson('/api/v1/me/devices', ['token' => self::TOKEN])->assertNoContent();

        $this->assertSame($owner->id, $this->rowFor(self::TOKEN)->user_id);
    }

    public function test_logout_still_unregisters_after_the_user_moved_company(): void
    {
        // Case 2. The row carries the OLD company_id; TenantScope would
        // filter by the NEW one and quietly find nothing.
        $oldCompany = Company::factory()->create();
        $agent = $this->agent($oldCompany);
        $this->actingAs($agent)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => self::TOKEN])->assertCreated();

        $agent->forceFill(['company_id' => Company::factory()->create()->id])->save();

        $this->actingAs($agent->fresh())->deleteJson('/api/v1/me/devices', ['token' => self::TOKEN])->assertNoContent();

        $this->assertNull($this->rowFor(self::TOKEN));
    }

    public function test_unregister_validates_the_token(): void
    {
        $this->actingAs($this->agent())
            ->deleteJson('/api/v1/me/devices', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_device_rows_are_tenant_scoped_for_ordinary_queries(): void
    {
        // §5 rule 2 — the model carries TenantScope like every business model.
        $mine = $this->agent();
        $theirs = $this->agent();
        $this->actingAs($mine)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => 'mine'])->assertCreated();
        $this->actingAs($theirs)->postJson('/api/v1/me/devices', ['platform' => 'ios', 'token' => 'theirs'])->assertCreated();

        $this->actingAs($mine);
        $this->assertSame([$mine->id], DeviceToken::query()->pluck('user_id')->all());
    }
}
