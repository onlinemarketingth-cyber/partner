<?php

namespace Tests\Feature\Share;

use App\Mail\ShareLinkMail;
use App\Models\AgentInviteLink;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\PlatformMailSetting;
use App\Models\ProductShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TASK-212 — POST /api/v1/share-emails, the platform-sent replacement for
 * <ShareLinkModal>'s `mailto:` button (human, 2026-08-19: "ระบบ อีเมล์ให้
 * ส่งผ่านระบบ").
 *
 * Mail::fake() throughout — the suite never opens an outbound SMTP
 * connection, same rule SendTestMailTest established.
 *
 * The load-bearing tests here are the ownership ones. This endpoint sends
 * mail FROM the platform's verified address TO an arbitrary string, so the
 * only thing standing between it and being a phishing relay is that the
 * actor must already be allowed to view the target, and that the URL in
 * the body is derived server-side from that target rather than supplied.
 */
class ShareLinkEmailTest extends TestCase
{
    use RefreshDatabase;

    private function enableMail(): void
    {
        PlatformMailSetting::query()->create([
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'encryption' => 'ssl',
            'username' => 'noreply@example.test',
            'password' => 'qa-fixture-not-a-real-secret',
            'from_address' => 'noreply@example.test',
            'from_name' => 'SyncVision CRM',
            'is_enabled' => true,
        ]);
    }

    /** @return array{0: Company, 1: User, 2: Order} */
    private function orderForAgent(?string $clientEmail = 'client@example.test'): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
            'email' => $clientEmail,
        ]);
        $order = Order::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'client_id' => $client->id,
        ]);

        return [$company, $agent, $order];
    }

    public function test_an_agent_can_email_their_own_order_pay_link(): void
    {
        Mail::fake();
        $this->enableMail();
        [, $agent, $order] = $this->orderForAgent();

        $this->actingAs($agent)
            ->postJson('/api/v1/share-emails', ['type' => 'order', 'id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.sent_to', 'client@example.test');

        Mail::assertSent(ShareLinkMail::class, function (ShareLinkMail $mail) use ($order, $agent) {
            return $mail->hasTo('client@example.test')
                && $mail->senderName === $agent->name
                && str_contains($mail->heading, $order->order_number)
                // The URL is BUILT here, never accepted from the caller.
                && str_contains($mail->url, "/pay/{$order->public_token}");
        });
    }

    /** The recipient may be overridden — the field is prefilled, not fixed. */
    public function test_a_supplied_email_overrides_the_clients_own(): void
    {
        Mail::fake();
        $this->enableMail();
        [, $agent, $order] = $this->orderForAgent();

        $this->actingAs($agent)
            ->postJson('/api/v1/share-emails', [
                'type' => 'order',
                'id' => $order->id,
                'email' => 'someone.else@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.sent_to', 'someone.else@example.test');

        Mail::assertSent(ShareLinkMail::class, fn (ShareLinkMail $m) => $m->hasTo('someone.else@example.test'));
    }

    /**
     * THE test. Without this the endpoint is an authenticated open relay:
     * any agent could mail any other company's pay link — and, worse, could
     * confirm the link exists — from this platform's From: address.
     */
    public function test_an_agent_cannot_email_another_agents_order(): void
    {
        Mail::fake();
        $this->enableMail();
        [, , $order] = $this->orderForAgent();

        $outsider = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($outsider)
            ->postJson('/api/v1/share-emails', ['type' => 'order', 'id' => $order->id])
            ->assertNotFound(); // TenantScope hides it before any Policy runs

        Mail::assertNothingSent();
    }

    public function test_an_agent_cannot_email_a_colleagues_order_in_the_same_company(): void
    {
        Mail::fake();
        $this->enableMail();
        [$company, , $order] = $this->orderForAgent();

        $colleague = User::factory()->agent()->create(['company_id' => $company->id]);

        // Same company, so TenantScope lets the row through — OrderPolicy
        // is what refuses here.
        $this->actingAs($colleague)
            ->postJson('/api/v1/share-emails', ['type' => 'order', 'id' => $order->id])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_a_product_share_link_requires_an_explicit_recipient(): void
    {
        Mail::fake();
        $this->enableMail();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $link = ProductShareLink::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/share-emails', ['type' => 'product_share', 'id' => $link->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();

        $this->actingAs($agent)
            ->postJson('/api/v1/share-emails', [
                'type' => 'product_share',
                'id' => $link->id,
                'email' => 'lead@example.test',
            ])
            ->assertOk();

        Mail::assertSent(ShareLinkMail::class, fn (ShareLinkMail $m) => $m->hasTo('lead@example.test')
            && str_contains($m->url, "/p/{$link->token}"));
    }

    public function test_an_agent_invite_link_can_be_emailed_by_its_owner(): void
    {
        Mail::fake();
        $this->enableMail();
        $company = Company::factory()->create();
        $leader = User::factory()->agent()->create(['company_id' => $company->id, 'is_team_leader' => true]);
        $link = AgentInviteLink::factory()->create(['company_id' => $company->id, 'agent_id' => $leader->id]);

        $this->actingAs($leader)
            ->postJson('/api/v1/share-emails', [
                'type' => 'agent_invite',
                'id' => $link->id,
                'email' => 'recruit@example.test',
            ])
            ->assertOk();

        Mail::assertSent(ShareLinkMail::class, fn (ShareLinkMail $m) => str_contains($m->url, "/register?ref={$link->token}"));
    }

    /**
     * Fail closed, same rule as PlatformMailSettingService::sendTest(): with
     * mail disabled, `mail.default` is still the .env `log` mailer, so a
     * "success" would mean the message went to a log file while the agent
     * was told the customer had it.
     */
    public function test_sending_fails_with_422_when_platform_mail_is_disabled(): void
    {
        Mail::fake();
        [, $agent, $order] = $this->orderForAgent(); // no enableMail()

        $this->actingAs($agent)
            ->postJson('/api/v1/share-emails', ['type' => 'order', 'id' => $order->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'กรุณาเปิดใช้งานและบันทึกการตั้งค่า SMTP ก่อนทดสอบส่งอีเมล');

        Mail::assertNothingSent();
    }

    public function test_an_unknown_share_type_is_rejected(): void
    {
        Mail::fake();
        $this->enableMail();
        [, $agent, $order] = $this->orderForAgent();

        $this->actingAs($agent)
            ->postJson('/api/v1/share-emails', ['type' => 'invoice', 'id' => $order->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        Mail::assertNothingSent();
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/share-emails', ['type' => 'order', 'id' => 1])
            ->assertUnauthorized();

        Mail::assertNothingSent();
    }
}
