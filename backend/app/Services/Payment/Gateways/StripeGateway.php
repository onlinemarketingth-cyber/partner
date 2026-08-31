<?php

namespace App\Services\Payment\Gateways;

use App\Enums\PaymentProvider;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Stripe, via Checkout (the payment page Stripe hosts).
 *
 * ── NO SDK, FOR THE SAME REASON OMISE HAS NONE ──
 *
 * Credentials here are PER COMPANY and two tenants' requests can be in
 * flight in one process. stripe-php is configured through a static
 * (Stripe::setApiKey), and a static key on a multi-tenant server is a way to
 * charge company A's customer into company B's account — the single
 * highest-consequence bug this system can have. Laravel's HTTP client holds
 * no state between calls, so every request carries the credentials of the
 * company it belongs to and nothing can leak sideways.
 *
 * ── CHECKOUT, NOT AN OWN CARD FORM ──
 *
 * Omise's flow tokenises in the browser and charges server-side. Stripe's
 * equivalent would mean building and maintaining a second card UI for no
 * gain. Checkout hands the customer to a page Stripe hosts and returns them
 * afterwards, which keeps card data off this origin entirely — the same PCI
 * position as the Omise flow, reached with less of our own code.
 *
 * The consequence is that there is NO synchronous charge step: money is
 * confirmed by webhook, exactly like the manual flow's slip. charge() below
 * therefore refuses rather than pretending.
 *
 * ── AMOUNTS ──
 *
 * Stripe counts THB in satang, which is what BR-3 already stores. There is
 * no conversion anywhere in this file, deliberately: a x100 error on a
 * payment gateway is the most expensive bug available here, and the absence
 * of a conversion is easier to verify than the correctness of one.
 */
class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    /**
     * How far out of date a webhook's own timestamp may be.
     *
     * Stripe's documented default. It is what stops a signature captured
     * once from being replayed forever — the signature stays valid maths
     * indefinitely, so the timestamp is the only thing that expires it.
     */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Stripe;
    }

    public function credentialFields(): array
    {
        return [
            [
                'key' => 'publishable_key',
                'label' => 'Publishable key (pk_)',
                'required' => true,
                // NOT secret: Stripe publishes it to the browser by design.
                // Masking it in the admin screen would imply a
                // confidentiality that does not exist.
                'secret' => false,
                'help' => 'คีย์สาธารณะของ Stripe — ไม่ใช่ความลับ',
            ],
            [
                'key' => 'secret_key',
                'label' => 'Secret key (sk_)',
                'required' => true,
                'secret' => true,
                'help' => 'เก็บเข้ารหัส และไม่เคยถูกส่งกลับออกมาทาง API อีกเลย',
            ],
            [
                'key' => 'webhook_secret',
                'label' => 'Webhook signing secret (whsec_)',
                'required' => true,
                'secret' => true,
                'help' => 'ได้จาก Stripe Dashboard > Webhooks — ถ้าไม่มี ใครก็ส่ง webhook ปลอมมาสั่งให้ออเดอร์กลายเป็นจ่ายแล้วได้',
            ],
        ];
    }

    /**
     * Ask Stripe whose account these keys open.
     *
     * The returned business name / account id goes in the note on purpose:
     * a green tick cannot tell an admin they have just connected the WRONG
     * company's Stripe, and on a platform where each tenant's revenue lands
     * in their own account that is the mistake worth catching.
     */
    public function verifyCredentials(Company $company, array $credentials, bool $isLive): string
    {
        $secret = trim($credentials['secret_key'] ?? '');
        $publishable = trim($credentials['publishable_key'] ?? '');
        $webhookSecret = trim($credentials['webhook_secret'] ?? '');

        $this->assertKeyMode($secret, 'sk', $isLive);
        $this->assertKeyMode($publishable, 'pk', $isLive);

        // Checked here rather than at the first webhook. A wrong signing
        // secret fails silently and asynchronously: payments succeed at
        // Stripe, every webhook is rejected as forged, and the orders simply
        // never turn paid — a failure nobody sees until a customer asks.
        if (! str_starts_with($webhookSecret, 'whsec_')) {
            throw new GatewayException('Webhook signing secret ต้องขึ้นต้นด้วย whsec_');
        }

        try {
            $response = Http::withToken($secret)
                ->timeout(15)
                ->acceptJson()
                ->get(self::API.'/account');
        } catch (Throwable $e) {
            throw new GatewayException('เชื่อมต่อ Stripe ไม่สำเร็จ: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            throw new GatewayException('Stripe ปฏิเสธ secret key นี้');
        }

        if (! $response->successful()) {
            throw new GatewayException('Stripe ตอบกลับผิดพลาด (HTTP '.$response->status().')');
        }

        $name = (string) ($response->json('settings.dashboard.display_name')
            ?? $response->json('business_profile.name')
            ?? $response->json('id')
            ?? 'ไม่ทราบ');
        $mode = $isLive ? 'LIVE' : 'TEST';

        return "เชื่อมต่อสำเร็จ — บัญชี {$name} (โหมด {$mode}) กรุณาตรวจว่าเป็นบัญชีของบริษัทนี้จริง";
    }

    /**
     * Both directions are refused, same rule as Omise's.
     *
     * A TEST key in LIVE mode takes payments that never settle; a LIVE key in
     * TEST mode charges real customers real money during testing. The second
     * is worse and neither is acceptable.
     */
    private function assertKeyMode(string $key, string $prefix, bool $isLive): void
    {
        $isTestKey = str_starts_with($key, $prefix.'_test_');
        $isLiveKey = str_starts_with($key, $prefix.'_live_');

        if (! $isTestKey && ! $isLiveKey) {
            throw new GatewayException("รูปแบบ {$prefix} ไม่ถูกต้อง — ต้องขึ้นต้นด้วย {$prefix}_test_ หรือ {$prefix}_live_");
        }

        if ($isLive && $isTestKey) {
            throw new GatewayException("ตั้งค่าเป็นโหมด LIVE แต่ใส่ {$prefix} ของ TEST — การชำระเงินจะดูสำเร็จแต่เงินไม่เข้า");
        }

        if (! $isLive && $isLiveKey) {
            throw new GatewayException("ตั้งค่าเป็นโหมด TEST แต่ใส่ {$prefix} ของ LIVE — จะเรียกเก็บเงินลูกค้าจริง");
        }
    }

    /**
     * Create a Checkout Session and hand back its URL.
     *
     * ── WHAT IS SENT, AND WHY EACH PART ──
     *
     * `unit_amount` is the ORDER's amount, read here and never from anything
     * the browser sent. A client-supplied amount is a client-supplied price.
     *
     * `metadata[order_token]` AND `payment_intent_data[metadata][order_token]`
     * are both set, and that duplication is deliberate: the session carries
     * metadata on `checkout.session.*` events, while `charge.*` and
     * `payment_intent.*` events carry only the PaymentIntent's own. Setting
     * one and not the other produces a webhook that is an amount and an id
     * belonging to nobody — which is exactly the refund case, months later,
     * when nobody remembers why.
     *
     * `client_reference_id` repeats it a third time because it is the field
     * Stripe's own dashboard search looks at, and a human handling a dispute
     * should be able to find the order without a database.
     *
     * PromptPay is offered alongside cards: at 1.65% against 3.65% it is the
     * cheaper rail by a wide margin, and it is what most Thai customers
     * reach for anyway.
     */
    public function startPayment(Order $order, array $credentials, bool $isLive): PaymentIntent
    {
        $secret = trim($credentials['secret_key'] ?? '');
        $payUrl = $this->publicPayUrl($order);

        try {
            $response = Http::withToken($secret)
                ->timeout(30)
                ->asForm()
                ->acceptJson()
                ->post(self::API.'/checkout/sessions', [
                    'mode' => 'payment',
                    // Back to our own pay page either way. It already knows
                    // how to show "paid" or "still awaiting payment" from the
                    // order itself, so neither URL is trusted as proof of
                    // anything — the webhook is what marks an order paid.
                    'success_url' => $payUrl.'?stripe=success',
                    'cancel_url' => $payUrl.'?stripe=cancelled',
                    'client_reference_id' => $order->public_token,
                    'payment_method_types' => ['card', 'promptpay'],
                    'line_items' => [
                        [
                            'quantity' => 1,
                            'price_data' => [
                                'currency' => 'thb',
                                // BR-3 straight through: Stripe's THB unit IS satang.
                                'unit_amount' => $order->amount_satang,
                                'product_data' => [
                                    // No customer name: Stripe's dashboard is a
                                    // third party's system and PDPA data does
                                    // not need to be there.
                                    'name' => 'Order '.$order->order_number,
                                ],
                            ],
                        ],
                    ],
                    'metadata' => ['order_token' => $order->public_token],
                    'payment_intent_data' => [
                        'metadata' => ['order_token' => $order->public_token],
                    ],
                ]);
        } catch (Throwable $e) {
            throw new GatewayException('เชื่อมต่อ Stripe ไม่สำเร็จ: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new GatewayException(
                (string) ($response->json('error.message') ?? 'สร้างรายการชำระเงินไม่สำเร็จ กรุณาลองใหม่')
            );
        }

        $url = (string) ($response->json('url') ?? '');

        if ($url === '') {
            throw new GatewayException('Stripe ไม่ได้ส่งลิงก์หน้าชำระเงินกลับมา');
        }

        return new PaymentIntent(
            kind: 'redirect',
            amountSatang: $order->amount_satang,
            publicKey: trim($credentials['publishable_key'] ?? ''),
            redirectUrl: $url,
            extra: [
                'currency' => 'THB',
                'session_id' => (string) ($response->json('id') ?? ''),
            ],
        );
    }

    /**
     * Where the customer comes back to. Built from the SAME config the share
     * and pay links are built from, so a company that moves domain does not
     * end up with Stripe returning customers to the old one.
     */
    private function publicPayUrl(Order $order): string
    {
        $frontend = rtrim((string) config('services.agent_portal.frontend_url'), '/');

        return $frontend.'/pay/'.$order->public_token;
    }

    /**
     * There is no synchronous charge step in a Checkout flow.
     *
     * Refuses rather than inventing one. The customer pays on Stripe's page
     * and the webhook is what says so — pretending otherwise here would give
     * callers a success they could not rely on, which is worse than an
     * honest refusal. Same position ManualGateway takes for the same reason.
     */
    public function charge(Order $order, array $credentials, string $paymentToken): WebhookOutcome
    {
        throw new GatewayException(
            'Stripe ใช้หน้าชำระเงินของ Stripe เอง — ระบบจะยืนยันการชำระเงินผ่าน webhook ไม่ใช่ที่ขั้นตอนนี้'
        );
    }

    /**
     * Stripe signs every webhook; an unsigned or mis-signed one is not ours.
     *
     * The header looks like `t=1614556800,v1=<hex>,v0=<hex>`. The signed
     * payload is the timestamp, a dot, then the RAW body — raw, because
     * re-encoding the JSON would change bytes Stripe hashed and every
     * signature would fail.
     *
     * TWO checks, both required:
     *
     *   1. hash_equals on v1. Not `===`: a comparison that short-circuits on
     *      the first differing byte leaks the signature one character at a
     *      time to anyone willing to measure. The thing being protected is
     *      "can a stranger mark orders paid".
     *   2. The timestamp is within tolerance. Without it a signature captured
     *      once is valid forever, and a replayed "paid" event is free goods
     *      plus an immutable BR-4 commission row for money that never
     *      arrived a second time.
     */
    public function verifyWebhook(Request $request, array $credentials): bool
    {
        $secret = trim($credentials['webhook_secret'] ?? '');
        $header = (string) $request->header('Stripe-Signature', '');

        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                // A header may legitimately carry several v1 signatures
                // during a secret rotation; any one matching is a pass.
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stripe's event vocabulary, translated into ours.
     *
     * `checkout.session.completed` alone does NOT mean paid — it means the
     * customer finished the page. PromptPay settles asynchronously, so a
     * session can complete while payment is still pending; only
     * `payment_status: paid` is payment. Treating the event name as success
     * would mark unpaid PromptPay orders as paid, which is the expensive
     * direction to be wrong in.
     *
     * `async_payment_succeeded` / `async_payment_failed` are the events that
     * resolve exactly that pending case, and they are why this method handles
     * more than one event name.
     *
     * chargeId prefers the PaymentIntent id over the session id: it is the
     * id that survives into charges and refunds, so the UNIQUE
     * `gateway_charge_id` guard still recognises the same payment when a
     * refund event arrives later.
     *
     * Everything unrecognised is Ignore, not an error — Stripe emits a great
     * many event types and alarming on the uninteresting ones trains people
     * to stop reading alarms.
     */
    public function interpret(array $payload): WebhookOutcome
    {
        $type = (string) ($payload['type'] ?? '');
        $object = $payload['data']['object'] ?? [];

        if (! is_array($object)) {
            return WebhookOutcome::ignore();
        }

        $orderToken = $this->orderTokenFrom($object);

        if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
            $status = (string) ($object['payment_status'] ?? '');

            // 'unpaid' here is a PromptPay charge still in flight, not a
            // failure: the async_payment_* event will say which it became.
            // Reporting it as Failed would cancel an order that is about to
            // succeed.
            if ($status === 'unpaid') {
                return WebhookOutcome::ignore();
            }

            return new WebhookOutcome(
                result: $status === 'paid' || $status === 'no_payment_required'
                    ? WebhookResult::Paid
                    : WebhookResult::Failed,
                chargeId: $this->chargeIdFrom($object),
                amountSatang: isset($object['amount_total']) ? (int) $object['amount_total'] : null,
                orderToken: $orderToken,
                failureMessage: $status === 'paid' ? null : $status,
            );
        }

        if ($type === 'checkout.session.async_payment_failed') {
            return new WebhookOutcome(
                result: WebhookResult::Failed,
                chargeId: $this->chargeIdFrom($object),
                amountSatang: isset($object['amount_total']) ? (int) $object['amount_total'] : null,
                orderToken: $orderToken,
                failureMessage: 'ชำระเงินไม่สำเร็จ',
            );
        }

        if ($type === 'charge.refunded') {
            return new WebhookOutcome(
                result: WebhookResult::Refunded,
                chargeId: isset($object['payment_intent']) ? (string) $object['payment_intent'] : null,
                amountSatang: isset($object['amount_refunded']) ? (int) $object['amount_refunded'] : null,
                orderToken: $orderToken,
            );
        }

        return WebhookOutcome::ignore();
    }

    /**
     * The PaymentIntent id when there is one, else the object's own id.
     *
     * A Checkout Session and its PaymentIntent are two ids for one payment,
     * and the later charge/refund events only ever carry the second. Storing
     * the session id would mean a refund arriving under an id the orders
     * table has never seen.
     *
     * @param  array<string, mixed>  $object
     */
    private function chargeIdFrom(array $object): ?string
    {
        if (isset($object['payment_intent']) && is_string($object['payment_intent'])) {
            return $object['payment_intent'];
        }

        return isset($object['id']) ? (string) $object['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function orderTokenFrom(array $object): ?string
    {
        if (isset($object['metadata']['order_token'])) {
            return (string) $object['metadata']['order_token'];
        }

        // Set on the session as a third copy precisely so this lookup has a
        // fallback when an event carries no metadata of its own.
        if (isset($object['client_reference_id']) && is_string($object['client_reference_id'])) {
            return $object['client_reference_id'];
        }

        return null;
    }
}
