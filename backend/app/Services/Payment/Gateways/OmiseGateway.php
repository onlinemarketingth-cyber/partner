<?php

namespace App\Services\Payment\Gateways;

use App\Enums\PaymentProvider;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Omise (Opn Payments).
 *
 * ── NO SDK ──
 *
 * omise/omise-php configures itself from static globals (OmiseApiResource's
 * class-level key), which is exactly wrong for this application: credentials
 * are PER COMPANY and two requests for two tenants can be in flight in the
 * same process. A static key is a way to charge company A's customer against
 * company B's account, and that is the single highest-consequence bug this
 * system can have (ADR-027 §3 records the earlier .env draft that would have
 * done the same thing platform-wide).
 *
 * Omise's API is HTTP Basic with the secret key as the username. Laravel's
 * HTTP client does that in one line and holds no state between calls, so
 * every request carries the credentials of the company it belongs to and
 * nothing can leak sideways.
 *
 * ── AMOUNTS ──
 *
 * Omise counts in the currency's smallest unit — satang for THB — which is
 * what BR-3 already stores. No conversion anywhere in this file, deliberately,
 * and a test asserts it: a ×100 error on a payment gateway is the most
 * expensive bug available here, and the absence of a conversion is easier to
 * verify than the correctness of one.
 */
class OmiseGateway implements PaymentGateway
{
    private const API = 'https://api.omise.co';

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Omise;
    }

    public function credentialFields(): array
    {
        return [
            [
                'key' => 'public_key',
                'label' => 'Public key (pkey_)',
                'required' => true,
                // NOT secret: it is embedded in the pay page's HTML and every
                // customer's browser can read it. Masking it in the admin
                // screen would imply a confidentiality that does not exist.
                'secret' => false,
                'help' => 'ใช้ในเบราว์เซอร์ของลูกค้า — เลขบัตรจึงไม่เคยผ่านเซิร์ฟเวอร์เรา',
            ],
            [
                'key' => 'secret_key',
                'label' => 'Secret key (skey_)',
                'required' => true,
                'secret' => true,
                'help' => 'เก็บเข้ารหัส และไม่เคยถูกส่งกลับออกมาทาง API อีกเลย',
            ],
            [
                'key' => 'webhook_secret',
                'label' => 'Webhook signature secret',
                'required' => true,
                'secret' => true,
                'help' => 'ถ้าไม่มี ใครก็ส่ง webhook ปลอมมาสั่งให้ออเดอร์กลายเป็นจ่ายแล้วได้',
            ],
        ];
    }

    /**
     * Ask Omise who these keys belong to.
     *
     * GET /account returns the merchant account. The RETURNED EMAIL is put in
     * the note on purpose: a green tick cannot tell an admin they have just
     * connected the wrong company's Omise, and on a platform where each
     * tenant's revenue lands in their own account that is the mistake worth
     * catching. Seeing somebody else's email is what catches it.
     *
     * The key's own prefix is checked first, because skey_test_ in a live
     * configuration produces charges that look successful and never settle —
     * a failure whose symptom appears weeks later in a bank statement.
     */
    public function verifyCredentials(Company $company, array $credentials, bool $isLive): string
    {
        $secret = trim($credentials['secret_key'] ?? '');
        $public = trim($credentials['public_key'] ?? '');

        $this->assertKeyMode($secret, 'skey', $isLive, 'secret_key');
        $this->assertKeyMode($public, 'pkey', $isLive, 'public_key');

        try {
            $response = Http::withBasicAuth($secret, '')
                ->timeout(15)
                ->acceptJson()
                ->get(self::API.'/account');
        } catch (Throwable $e) {
            throw new GatewayException('เชื่อมต่อ Omise ไม่สำเร็จ: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            throw new GatewayException('Omise ปฏิเสธ secret key นี้', 'secret_key');
        }

        if (! $response->successful()) {
            throw new GatewayException('Omise ตอบกลับผิดพลาด (HTTP '.$response->status().')');
        }

        $email = (string) ($response->json('email') ?? 'ไม่ทราบ');
        $mode = $isLive ? 'LIVE' : 'TEST';

        return "เชื่อมต่อสำเร็จ — บัญชี {$email} (โหมด {$mode}) กรุณาตรวจว่าเป็นบัญชีของบริษัทนี้จริง";
    }

    /**
     * A key must match the mode it is configured for.
     *
     * Both directions are refused. A TEST key in LIVE mode takes payments
     * that never settle; a LIVE key in TEST mode charges real customers real
     * money during testing. The second is worse and neither is acceptable.
     */
    private function assertKeyMode(string $key, string $prefix, bool $isLive, string $field): void
    {
        $isTestKey = str_starts_with($key, $prefix.'_test_');
        $isLiveKey = str_starts_with($key, $prefix.'_live_');

        if (! $isTestKey && ! $isLiveKey) {
            throw new GatewayException(
                "รูปแบบ {$prefix} ไม่ถูกต้อง — ต้องขึ้นต้นด้วย {$prefix}_test_ หรือ {$prefix}_live_".self::looksLikeHint($key),
                $field,
            );
        }

        if ($isLive && $isTestKey) {
            throw new GatewayException(
                "ตั้งค่าเป็นโหมด LIVE แต่ใส่ {$prefix} ของ TEST — การชำระเงินจะดูสำเร็จแต่เงินไม่เข้า",
                $field,
            );
        }

        if (! $isLive && $isLiveKey) {
            throw new GatewayException(
                "ตั้งค่าเป็นโหมด TEST แต่ใส่ {$prefix} ของ LIVE — จะเรียกเก็บเงินลูกค้าจริง",
                $field,
            );
        }
    }

    /**
     * Name the credential a wrong-looking value actually is.
     *
     * Same reasoning as StripeGateway's copy: two of the three boxes on this
     * form are password fields showing dots, so a value pasted into the wrong
     * one is invisible, and "รูปแบบไม่ถูกต้อง" alone leaves an admin comparing
     * long strings by eye. Only the prefix is ever echoed, never the value.
     */
    private static function looksLikeHint(string $value): string
    {
        $known = [
            'pkey_test_' => 'Public key (โหมดทดสอบ)',
            'pkey_live_' => 'Public key (โหมดใช้งานจริง)',
            'skey_test_' => 'Secret key (โหมดทดสอบ)',
            'skey_live_' => 'Secret key (โหมดใช้งานจริง)',
        ];

        foreach ($known as $prefix => $name) {
            if (str_starts_with($value, $prefix)) {
                return " · ค่าที่ใส่มาดูเหมือนเป็น \"{$name}\" — วางผิดช่องหรือเปล่า";
            }
        }

        return '';
    }

    /**
     * The browser tokenises; this server never sees a card number.
     *
     * Hands back the PUBLIC key only. Omise.js exchanges the card for a token
     * directly with Omise, the page posts us the token, and Phase 2 turns the
     * token into a charge server-side with the secret key.
     */
    public function startPayment(Order $order, array $credentials, bool $isLive): PaymentIntent
    {
        return new PaymentIntent(
            kind: 'tokenize',
            // BR-3 straight through: Omise's unit for THB is satang.
            amountSatang: $order->amount_satang,
            publicKey: trim($credentials['public_key'] ?? ''),
            extra: ['currency' => 'THB'],
        );
    }

    /**
     * Exchange the browser's one-time token for a charge.
     *
     * ── WHAT IS SENT, AND WHY EACH PART ──
     *
     * `amount` is the ORDER's amount, read here and never from anything the
     * browser sent. A client-supplied amount is a client-supplied price.
     *
     * `metadata.order_token` is how the webhook finds its way back to this
     * order minutes later. Omise returns metadata verbatim on every event for
     * the charge, so this is the join key — without it a webhook is an amount
     * and a charge id belonging to nobody.
     *
     * `description` carries the order number so a human reading the Omise
     * dashboard during a dispute can match a row to an order without a
     * database. Deliberately no customer name: the dashboard is a third
     * party's system and PDPA data does not need to be there.
     *
     * The response is normalised through the SAME interpret() a webhook goes
     * through, so a synchronous success and an asynchronous one cannot be
     * treated differently by anything downstream.
     */
    public function charge(Order $order, array $credentials, string $paymentToken): WebhookOutcome
    {
        $secret = trim($credentials['secret_key'] ?? '');

        try {
            $response = Http::withBasicAuth($secret, '')
                ->timeout(30)
                ->asForm()
                ->acceptJson()
                ->post(self::API.'/charges', [
                    // BR-3 straight through: Omise's THB unit IS satang.
                    'amount' => $order->amount_satang,
                    'currency' => 'THB',
                    'card' => $paymentToken,
                    'description' => 'Order '.$order->order_number,
                    'metadata' => ['order_token' => $order->public_token],
                ]);
        } catch (Throwable $e) {
            throw new GatewayException('เชื่อมต่อ Omise ไม่สำเร็จ: '.$e->getMessage());
        }

        if (! $response->successful()) {
            /*
             * Omise's own message is passed through to the CUSTOMER here, and
             * that is deliberate: "บัตรถูกปฏิเสธ" and "ยอดเกินวงเงิน" are
             * things only the cardholder can act on, and hiding them behind a
             * generic failure turns a fixable problem into an abandoned sale.
             * It is a decline reason, not an internal detail.
             */
            throw new GatewayException(
                (string) ($response->json('message') ?? 'ชำระเงินไม่สำเร็จ กรุณาลองใหม่หรือใช้บัตรอื่น')
            );
        }

        // Shaped as the webhook's `data` so one interpreter serves both.
        return $this->interpret(['key' => 'charge.complete', 'data' => $response->json()]);
    }

    /**
     * Omise signs each webhook; an unsigned or mis-signed one is not ours.
     *
     * hash_equals, not `===`: string comparison that short-circuits on the
     * first differing byte leaks the signature one character at a time to
     * anyone willing to measure. Cheap to do right, and the thing being
     * protected is "can a stranger mark orders paid".
     */
    public function verifyWebhook(Request $request, array $credentials): bool
    {
        $secret = trim($credentials['webhook_secret'] ?? '');
        $signature = (string) $request->header('X-Omise-Signature', '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Omise's event vocabulary, translated into ours.
     *
     * Only `charge.complete` can mark an order paid, and only when the charge
     * itself says `status: successful` — the event name means "we finished
     * processing", not "it worked". Treating the event alone as success would
     * mark declined cards as paid.
     *
     * Everything unrecognised is Ignore, not an error: Omise emits many event
     * types and alarming on the uninteresting ones trains people to stop
     * reading alarms.
     */
    public function interpret(array $payload): WebhookOutcome
    {
        $key = (string) ($payload['key'] ?? '');
        $charge = $payload['data'] ?? [];

        if (! is_array($charge)) {
            return WebhookOutcome::ignore();
        }

        $chargeId = isset($charge['id']) ? (string) $charge['id'] : null;
        $amount = isset($charge['amount']) ? (int) $charge['amount'] : null;
        // Set when the charge was created — how we find the order again.
        $orderToken = isset($charge['metadata']['order_token'])
            ? (string) $charge['metadata']['order_token']
            : null;

        if ($key === 'charge.complete') {
            $status = (string) ($charge['status'] ?? '');

            return new WebhookOutcome(
                result: $status === 'successful' ? WebhookResult::Paid : WebhookResult::Failed,
                chargeId: $chargeId,
                amountSatang: $amount,
                orderToken: $orderToken,
                failureMessage: $status === 'successful' ? null : (string) ($charge['failure_message'] ?? $status),
            );
        }

        if ($key === 'refund.create') {
            return new WebhookOutcome(
                result: WebhookResult::Refunded,
                chargeId: $chargeId,
                amountSatang: $amount,
                orderToken: $orderToken,
            );
        }

        return WebhookOutcome::ignore();
    }
}
