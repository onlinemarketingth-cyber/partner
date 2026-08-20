<?php

namespace App\Services\Share;

use App\Enums\ShareLinkType;
use App\Exceptions\MailSettingsNotConfiguredException;
use App\Mail\ShareLinkMail;
use App\Models\AgentInviteLink;
use App\Models\Order;
use App\Models\ProductShareLink;
use App\Models\User;
use App\Services\Platform\PlatformMailSettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

/**
 * TASK-212 — the ONE place a shareable link is emailed by the platform on
 * an agent's behalf (human, 2026-08-19: "ระบบ อีเมล์ให้ส่งผ่านระบบ").
 *
 * ═══ WHY THE CALLER DOES NOT PASS A URL ═══
 * <ShareLinkModal> is a dumb component that receives a plain URL string
 * and, until now, never talked to the API. The obvious way to keep that
 * shape would have been `POST {url, email}` — and it would have turned
 * this application into an open mail relay: every agent login would be
 * able to send any URL to any address, From: the company's own verified
 * domain. Phishing infrastructure, provided by us, authenticated.
 *
 * So the browser sends a ShareLinkType and an id. This Service resolves
 * the model, asks the EXISTING Policy whether the actor may view it, and
 * then derives the URL itself using the same construction the Resources
 * use. An agent can therefore only email a link they could already open,
 * and the address in the mail is one this codebase built.
 *
 * ═══ FAIL CLOSED ON MAIL CONFIG ═══
 * Same rule as PlatformMailSettingService::sendTest(): when no
 * platform_mail_settings row exists or is_enabled is false,
 * MailSettingsService::applyRuntimeConfig() leaves `mail.default` pointing
 * at the .env `log` mailer, so a send would be written to a log file and
 * reported to the agent as success. That false positive is worse than an
 * error, because the agent walks away believing the customer has the link.
 * Checked BEFORE Mail::to(), throwing the same exception the settings
 * screen already renders.
 *
 * Transport exceptions are deliberately NOT caught here — they propagate
 * to ShareLinkEmailController, which turns them into a 422 the modal can
 * show, exactly as PlatformMailSettingController::test() does.
 */
class ShareLinkEmailService
{
    public function __construct(private readonly PlatformMailSettingService $mailSettings) {}

    /**
     * @return string the address the mail was actually sent to
     *
     * @throws AuthorizationException            actor may not view the target
     * @throws MailSettingsNotConfiguredException platform mail is off/unset
     */
    public function send(User $actor, ShareLinkType $type, int $id, ?string $email = null): string
    {
        $target = $this->resolve($type, $id);

        // The existing per-model Policy, not a new rule invented here: an
        // Agent may only email their own order/link, a Company Admin their
        // company's, a Super Admin anything (BR-6 §5).
        if ($actor->cannot('view', $target['model'])) {
            throw new AuthorizationException;
        }

        $recipient = $email !== null && $email !== '' ? $email : $target['default_recipient'];

        if ($recipient === null || $recipient === '') {
            // Reached only when the agent cleared the field on a target that
            // has no known recipient — the Form Request requires `email`
            // for those types, so this is a belt-and-braces guard, not the
            // primary message path.
            throw new \RuntimeException('ไม่พบอีเมลผู้รับ กรุณากรอกอีเมล');
        }

        if (! ($this->mailSettings->get()['is_enabled'] ?? false)) {
            throw new MailSettingsNotConfiguredException;
        }

        Mail::to($recipient)->send(new ShareLinkMail($target['heading'], $target['url'], $actor->name));

        return $recipient;
    }

    /**
     * The default recipient a given target already knows about, so the
     * modal can PREFILL it (human's answer, 2026-08-19: "ดึงอีเมลลูกค้ามาให้
     * แก้ไขได้"). Null for the two link types, which are broadcast links
     * with no single intended reader.
     *
     * @throws AuthorizationException
     */
    public function defaultRecipientFor(User $actor, ShareLinkType $type, int $id): ?string
    {
        $target = $this->resolve($type, $id);

        if ($actor->cannot('view', $target['model'])) {
            throw new AuthorizationException;
        }

        return $target['default_recipient'];
    }

    /**
     * @return array{model: Model, url: string, heading: string, default_recipient: ?string}
     */
    private function resolve(ShareLinkType $type, int $id): array
    {
        $frontendUrl = rtrim((string) config('services.agent_portal.frontend_url'), '/');

        return match ($type) {
            // withoutGlobalScopes is NOT used anywhere below: TenantScope
            // narrowing the lookup to the actor's company is the first of
            // the two gates (the Policy is the second), and a cross-company
            // id must 404 here rather than reach an authorization check that
            // would confirm the row exists.
            ShareLinkType::Order => (function () use ($id) {
                $order = Order::with('client')->findOrFail($id);

                return [
                    'model' => $order,
                    // The ONE derivation of this URL, shared with the
                    // Resource — never a second string built by hand.
                    'url' => \App\Http\Resources\OrderResource::publicPayUrl($order),
                    'heading' => "ชำระเงิน {$order->order_number}",
                    'default_recipient' => $order->client?->email,
                ];
            })(),

            ShareLinkType::ProductShare => (function () use ($id, $frontendUrl) {
                $link = ProductShareLink::with('product')->findOrFail($id);

                return [
                    'model' => $link,
                    'url' => "{$frontendUrl}/p/{$link->token}",
                    'heading' => $link->product?->name ?? 'ลิงก์สินค้า',
                    // A product-share link is minted to be posted publicly;
                    // there is no "the" customer it belongs to.
                    'default_recipient' => null,
                ];
            })(),

            ShareLinkType::AgentInvite => (function () use ($id, $frontendUrl) {
                $link = AgentInviteLink::findOrFail($id);

                return [
                    'model' => $link,
                    'url' => "{$frontendUrl}/register?ref={$link->token}",
                    'heading' => $link->label ?: 'ลิงก์ชวนเข้าทีม',
                    'default_recipient' => null,
                ];
            })(),
        };
    }
}
