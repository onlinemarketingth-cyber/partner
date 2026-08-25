<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TrackedLinkGroup;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTrackedLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ChargeOrderRequest;
use App\Http\Requests\Order\SubmitSlipRequest;
use App\Http\Resources\PublicOrderResource;
use App\Models\Company;
use App\Models\Order;
use App\Services\Link\TrackedLinkService;
use App\Services\Order\OrderService;
use App\Services\Payment\GatewayPaymentService;
use App\Services\Payment\Gateways\GatewayException;
use Illuminate\Validation\ValidationException;

// ADR-017 (TASK-054) — the PUBLIC, UNAUTHENTICATED payment page
// (GET /pay/{token}, POST /pay/{token}/slip), registered outside
// auth:sanctum and throttled (see routes/api.php) — same opaque-token,
// never-enumerable treatment as the affiliate /l/{token} redirect. The
// order is resolved by public_token WITHOUT TenantScope (there is no
// authenticated user to scope by); the response uses PublicOrderResource,
// which exposes only amount + company payment details + a PromptPay
// payload, never agent/commission/PDPA data (§6).
class PublicPaymentController extends Controller
{
    use ResolvesTrackedLink;

    /** @var list<string> ADR-033 (TASK-189) — 'voucher' added so PublicOrderResource can render it once paid. */
    private const RELATIONS = ['company', 'product', 'client', 'voucher'];

    public function show(string $token): PublicOrderResource
    {
        $order = $this->resolve($token);

        return new PublicOrderResource($order->load(self::RELATIONS));
    }

    public function submitSlip(string $token, SubmitSlipRequest $request, OrderService $service): PublicOrderResource
    {
        $order = $this->resolve($token);

        // Only an unpaid, non-cancelled order can receive a slip.
        abort_unless($order->isPayable(), 422, 'คำสั่งซื้อนี้ไม่สามารถอัปโหลดสลิปได้');

        // ADR-033 (TASK-189) §2.5/D2 — only() omits keys absent from the
        // request entirely (Arr::only), which is exactly the "present vs.
        // not" distinction OrderService::submitSlip() needs.
        $order = $service->submitSlip(
            $order,
            $request->file('slip'),
            $request->only(['shipping_recipient_name', 'shipping_phone', 'shipping_address']),
        );

        return new PublicOrderResource($order->load(self::RELATIONS));
    }

    /**
     * ADR-027 / TASK-139 — turn a browser-side payment token into a charge.
     *
     * PUBLIC and unauthenticated like its slip sibling, and throttled harder
     * still (see routes/api.php): this one moves money.
     *
     * `payment_token` is a ONE-TIME token the provider's own JS produced in
     * the customer's browser out of card details this server never sees and
     * never will. That is the whole PCI position, and it is why there is no
     * card number anywhere in this request.
     *
     * A GatewayException here carries the CUSTOMER'S message — "บัตรถูก
     * ปฏิเสธ", "ยอดเกินวงเงิน" — passed through as a 422 validation error so
     * the pay page shows it against the card field. A generic failure would
     * turn a fixable decline into an abandoned sale.
     */
    public function charge(string $token, ChargeOrderRequest $request, GatewayPaymentService $payments): PublicOrderResource
    {
        $order = $this->resolve($token);

        try {
            $order = $payments->chargeWithToken($order, (string) $request->validated('payment_token'));
        } catch (GatewayException $e) {
            throw ValidationException::withMessages(['payment_token' => $e->getMessage()]);
        }

        return new PublicOrderResource($order->load(self::RELATIONS));
    }

    private function resolve(string $token): Order
    {
        // No tenant context on a public request — look up by the unguessable
        // public_token alone, bypassing TenantScope deliberately.
        //
        // TASK-232 — `{token}` is now EITHER a 14-character short code
        // (/pay/H9F4VQ2NB7KTXM) or the original 40-character public_token.
        // 14 rather than the 10 every other group gets: this page shows an
        // order's contents and total, so shortening its front door had to
        // not shorten its protection.
        $order = $this->resolveViaTrackedLink(
            $token,
            TrackedLinkGroup::Payment,
            Order::class,
            request(),
            app(TrackedLinkService::class),
        );

        $order ??= Order::withoutGlobalScopes()->where('public_token', $token)->firstOrFail();

        /*
         * TASK-183 §3.5 — a closed tenant collects no money.
         *
         * This endpoint is the sharpest edge of the whole gap: without this
         * line a customer holding a pay link for a company that has been
         * deactivated or deleted can still upload a slip, an agent can still
         * confirm it, and BR-4 still writes a commission ledger row — for a
         * tenant that, as far as the Admin who flipped the switch is
         * concerned, stopped operating days ago.
         *
         * It is the single resolver behind BOTH public payment routes (GET
         * /pay/{token} and POST /pay/{token}/slip), so one check closes both;
         * the read is refused as well as the write on purpose, because showing
         * a customer the company's bank account and PromptPay QR is itself an
         * invitation to pay outside the system.
         *
         * 404, matching firstOrFail()'s answer for an unknown token above: the
         * customer learns their link is dead, not that a specific company
         * exists and has been switched off (§3.4).
         */
        abort_unless(Company::isOperationalById($order->company_id), 404);

        return $order;
    }
}
