<?php

namespace App\Services\Payment\Gateways;

use App\Enums\PaymentProvider;
use App\Models\Company;
use App\Models\Order;
use App\Services\Payment\PromptPayService;
use Illuminate\Http\Request;

/**
 * The flow every company uses today, stated as a provider.
 *
 * PromptPayService builds an EMVCo QR locally, the customer transfers
 * bank-to-bank, uploads a slip, and a person presses confirm. Money moves and
 * nobody's API is involved — which is exactly why it belongs here rather than
 * being modelled as "no gateway configured".
 *
 * Writing it out is what makes PaymentGateway a description of two real
 * things instead of one implementation and a guess. Several of the
 * interface's decisions came from this class refusing to fit an
 * Omise-shaped contract: there is no charge id, no signature, and no webhook
 * at all, and the interface had to be honest about that.
 */
class ManualGateway implements PaymentGateway
{
    public function __construct(private readonly PromptPayService $promptPay) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Manual;
    }

    /**
     * NONE — and that absence is the point.
     *
     * The PromptPay proxy this flow needs already lives on
     * `companies.payment_promptpay_id` (ADR-017 / TASK-054) and the public
     * pay page already reads it there. Declaring it here would copy one value
     * into two homes, and the copy that stopped being updated would send
     * customers' money to an old account — which is the worst possible
     * consequence of ordinary drift.
     *
     * So this provider's settings screen shows no fields, and says where its
     * configuration actually is.
     */
    public function credentialFields(): array
    {
        return [];
    }

    /**
     * There is nobody to ask, so the check is on the company's own record.
     *
     * A PromptPay proxy is a destination, not an account we hold a key to.
     * Verification can only confirm its SHAPE, and this says so plainly: an
     * admin who reads "verified" must not believe the destination was tested.
     */
    public function verifyCredentials(Company $company, array $credentials, bool $isLive): string
    {
        $proxy = preg_replace('/\D/', '', (string) $company->payment_promptpay_id) ?? '';

        if (! in_array(strlen($proxy), [10, 13], true)) {
            throw new GatewayException(
                'บริษัทนี้ยังไม่ได้ตั้งค่าพร้อมเพย์ — ตั้งที่หน้าข้อมูลบริษัท (เบอร์ 10 หลัก หรือเลขบัตร 13 หลัก)'
            );
        }

        return 'ตรวจรูปแบบพร้อมเพย์ของบริษัทผ่าน — โหมดนี้ไม่มีการเชื่อมต่อ API จึงยืนยันปลายทางจริงไม่ได้ กรุณาทดสอบโอนเองหนึ่งครั้ง';
    }

    public function startPayment(Order $order, array $credentials, bool $isLive): PaymentIntent
    {
        return new PaymentIntent(
            kind: 'qr',
            amountSatang: $order->amount_satang,
            // From the company, the same source the existing pay page uses.
            qrPayload: $this->promptPay->payload(
                (string) $order->company?->payment_promptpay_id,
                $order->amount_satang,
            ),
            expectsSlipUpload: true,
        );
    }

    /**
     * There is no charge to make — a bank transfer already happened, or has
     * not.
     *
     * Throws rather than returning a hopeful "paid". A caller that reaches
     * this has routed a card payment at a company configured for slips, and
     * the honest answer is that it cannot be done, not a success the customer
     * would act on.
     */
    public function charge(Order $order, array $credentials, string $paymentToken): WebhookOutcome
    {
        throw new GatewayException('ช่องทางนี้ชำระผ่านการโอนและแนบสลิป ไม่มีการตัดบัตรอัตโนมัติ');
    }

    /**
     * No webhook exists, so nothing can be verified as genuine.
     *
     * Returns FALSE rather than true. If a route ever reaches this method it
     * means something is POSTing to a webhook endpoint for a provider that
     * has none, and the only safe answer is no. A permissive stub here would
     * be a way to mark orders paid without any signature at all.
     */
    public function verifyWebhook(Request $request, array $credentials): bool
    {
        return false;
    }

    public function interpret(array $payload): WebhookOutcome
    {
        return WebhookOutcome::ignore();
    }
}
