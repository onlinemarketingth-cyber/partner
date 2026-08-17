<?php

namespace App\Services\Order;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineStage;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use App\Services\Catalog\ProductPricingService;
use App\Services\Notification\NotificationService;
use App\Services\Referral\PipelineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-017 (TASK-054) — Order & Payment Collection. This Service owns the
 * order lifecycle and the ONE linkage into the sale-close pipeline: it never
 * writes commission_ledger directly (BR-4). Confirming a paid order calls
 * the existing PipelineService::advance() so CommissionService fires exactly
 * as it does for any other Complete Payment — no second commission path.
 *
 * §5 — every order's tenant/ownership fields come from the bound Referral,
 * never from caller input. Slip files live on the PRIVATE disk (§6), the
 * same 'local' disk ClientDocumentService uses.
 */
class OrderService
{
    // Same private disk as ClientDocumentService (config/filesystems.php:
    // storage/app/private) — never the 'public' disk, so there is no direct
    // URL to a payment slip; it's served only via the access-checked
    // authenticated download endpoint (§6 / §5 rule 6).
    private const DISK = 'local';

    public function __construct(
        private PipelineService $pipelineService,
        // TASK-136 (risk R1) — see createForReferral()'s amount_satang line.
        private ProductPricingService $productPricingService,
        // ADR-033 (TASK-189) §2.2 — mints the post-payment voucher inside
        // confirmPayment()'s transaction, see below.
        private OrderVoucherService $orderVoucherService,
        // TASK-190 §4.1 — the agent notification on payment confirm sits in
        // the exact same transaction, under the exact same guard, as the
        // voucher line above.
        private NotificationService $notificationService,
    ) {}

    /**
     * Create an order for a referral. company_id/client_id/agent_id/
     * product_id and amount_satang are all taken FROM the referral (§5 —
     * never trusted from the caller). Blocks a duplicate active order for
     * the same referral (a previous cancelled order does not block a new one).
     */
    public function createForReferral(Referral $referral, PaymentMethod $method): Order
    {
        $hasActiveOrder = Order::withoutGlobalScopes()
            ->where('referral_id', $referral->id)
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::AwaitingVerification->value,
                OrderStatus::Paid->value,
            ])
            ->exists();

        if ($hasActiveOrder) {
            throw ValidationException::withMessages([
                'referral_id' => 'มีคำสั่งซื้อที่ยังไม่ปิด/ชำระแล้วสำหรับรายการอ้างอิงนี้อยู่แล้ว ไม่สามารถสร้างซ้ำได้',
            ]);
        }

        return Order::create([
            'company_id' => $referral->company_id,
            'referral_id' => $referral->id,
            'client_id' => $referral->client_id,
            'agent_id' => $referral->agent_id,
            'product_id' => $referral->product_id,
            'order_number' => $this->generateOrderNumber($referral->company_id),
            'public_token' => $this->generatePublicToken(),
            // BR-3 — snapshot of the customer-facing price at creation.
            //
            // TASK-136 (risk R1): this used to read `price_satang` — the
            // LIST price — while CommissionService (TASK-047) computed
            // BR-4 commission from the DISCOUNTED price whenever a
            // product_price_promotions row was active. Same sale, two
            // prices. Harmless-looking while only an agent ever saw an
            // order; the moment a customer can check out from a public
            // share link, it becomes "the page said 8,000 and I was
            // charged 8,900".
            //
            // Both sides now read ProductPricingService, so the amount a
            // customer is asked to pay and the base commission is computed
            // from are the same number by construction. Note the promotion
            // is resolved at ORDER-CREATION time and snapshotted here (the
            // customer must be charged what they were quoted), while
            // commission resolves it again at Complete Payment — TASK-047's
            // deliberate rule, left untouched. A promotion that starts or
            // ends between those two moments therefore still moves
            // commission without moving the agreed price, which is correct:
            // the order is a quote to the customer, the ledger is a payout
            // to the agent.
            'amount_satang' => $this->productPricingService->effectivePriceSatang($referral->product),
            'payment_method' => $method,
            'status' => OrderStatus::Pending,
        ]);
    }

    /**
     * Store an uploaded payment slip on the private disk (tenant-scoped
     * path) and move the order to awaiting_verification.
     *
     * Called from the PUBLIC (unauthenticated) controller, so it takes NO
     * tenant context from the request — everything derives from the $order
     * already resolved by its public_token.
     *
     * ADR-033 (TASK-189) §2.5/D2 — $shipping carries the three shipping_*
     * fields ONLY WHEN PRESENT in the request (see array_key_exists below,
     * not just non-null) — a re-submission that omits them (e.g. a
     * non-physical product) must not blank out values a customer already
     * saved on a prior visit. SubmitSlipRequest already enforced they are
     * present when product.requires_shipping; this method does not
     * re-derive that rule, it only persists what validation already let
     * through.
     *
     * @param  array<string, string|null>  $shipping
     */
    public function submitSlip(Order $order, UploadedFile $slip, array $shipping = []): Order
    {
        $path = $slip->storeAs(
            "orders/slips/{$order->company_id}",
            Str::uuid()->toString().'.'.$slip->getClientOriginalExtension(),
            self::DISK,
        );

        $update = [
            'slip_path' => $path,
            'status' => OrderStatus::AwaitingVerification,
        ];

        foreach (['shipping_recipient_name', 'shipping_phone', 'shipping_address'] as $field) {
            if (array_key_exists($field, $shipping)) {
                $update[$field] = $shipping[$field];
            }
        }

        $order->update($update);

        return $order->fresh();
    }

    /**
     * Verify/confirm a payment. On success the order becomes paid and the
     * referral is advanced to Complete Payment, which fires the existing
     * BR-4 commission (idempotent — CommissionService dedups on referral_id).
     *
     * ADR-026 §3.7 (TASK-133) — the precondition is no longer the hardcoded
     * medical stage. It is now:
     *
     *   > the referral's NEXT stage under its OWN template is
     *   > complete_payment, or it is already at/past it.
     *
     * For a `medical_package_default` referral (and for a legacy referral
     * with no template snapshot, which falls back to CLAUDE.md §4.3's
     * original five stages) this evaluates to exactly the old behaviour:
     * only `finish_1st_doctor_meeting` immediately precedes payment, so the
     * medical gate is not weakened — only made specific to the products
     * that actually have one. For a `direct_sale_default` referral it is
     * satisfied at `complete_registered`, which is the point of the ADR.
     *
     * "Already at/past" is computed from the template's own ordering
     * (PipelineService::hasReachedStage), never from enum case order. If
     * the referral is already at/past Complete Payment we mark the order
     * paid WITHOUT advancing again (the commission already exists) so a
     * re-confirm is idempotent.
     */
    public function confirmPayment(Order $order, User $actor): Order
    {
        // Idempotent re-confirm of an already-paid order — no-op.
        if ($order->status === OrderStatus::Paid) {
            return $order->fresh();
        }

        if (! $order->isPayable()) {
            throw ValidationException::withMessages([
                'status' => 'คำสั่งซื้อนี้ไม่อยู่ในสถานะที่ยืนยันการชำระเงินได้ (ถูกยกเลิกไปแล้ว)',
            ]);
        }

        $referral = $order->referral;

        $alreadyClosed = $this->pipelineService->hasReachedStage($referral, PipelineStage::CompletePayment);

        if (! $alreadyClosed && $this->pipelineService->nextStageFor($referral) !== PipelineStage::CompletePayment) {
            // Name the step that is actually missing ON THIS JOURNEY.
            // The medical wording is preserved verbatim for the medical
            // case so an existing Thai Life user sees no change; every
            // other journey gets an accurate message built from the
            // stage's English label(), because Thai stage labels live in
            // the UI layer, not in the enum (§7 / PipelineStage docblock)
            // and inventing new ones here would duplicate them.
            $required = $this->pipelineService->stageBefore($referral, PipelineStage::CompletePayment);

            throw ValidationException::withMessages([
                'referral' => $required === PipelineStage::Finish1stDoctorMeeting
                    ? 'ต้องผ่านขั้น "พบแพทย์ครั้งแรก" ก่อนจึงจะยืนยันการชำระเงินได้'
                    : 'รายการอ้างอิงนี้ยังไม่ถึงขั้นตอนก่อนหน้าการชำระเงินตามเส้นทางการขายของสินค้านี้'
                        .($required ? ' (ต้องผ่านขั้น "'.$required->label().'" ก่อน)' : '')
                        .' จึงยังยืนยันการชำระเงินไม่ได้',
            ]);
        }

        return DB::transaction(function () use ($order, $referral, $actor, $alreadyClosed) {
            $order->update([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
                'verified_by_user_id' => $actor->id,
            ]);

            // Advance ONLY when the referral hasn't already reached Complete
            // Payment — this is what fires BR-4 commission, exactly once.
            if (! $alreadyClosed) {
                $this->pipelineService->advance($referral, $actor);
            }

            // ADR-033 (TASK-189) §2.2/B1 — mint the voucher under the EXACT
            // same guard as the commission advance above, so a re-confirm
            // that races past the top-of-method idempotency check still
            // cannot mint a second voucher for this order.
            if (! $alreadyClosed) {
                $this->orderVoucherService->issueFor($order);
            }

            // TASK-190 §4.1 — let the referral's agent know their customer's
            // payment was confirmed. SAME guard as the two blocks above, for
            // the same reason: a plain DB row write, no external call, safe
            // inside the transaction, and it must not duplicate on a
            // re-confirm any more than the voucher does. Unconditional on
            // the referral actually having an agent (BR-1 — every referral
            // is created by/for an agent — but null-guarded defensively the
            // same way CommissionLedgerController::markPaid() guards
            // $commissionLedger->agent).
            if (! $alreadyClosed && $referral->agent) {
                $this->notificationService->notify(
                    $referral->agent,
                    NotificationType::OrderPaymentConfirmed,
                    'ยืนยันการชำระเงินแล้ว',
                    "คำสั่งซื้อ {$order->order_number} ได้รับการยืนยันการชำระเงินเรียบร้อยแล้ว",
                    '/orders',
                    ['order_id' => $order->id],
                );
            }

            return $order->fresh();
        });
    }

    /**
     * TASK-176 §1.2 — of a referral's orders, the ONE a board may act on
     * (or null). Pure: it takes an ALREADY-LOADED collection and never
     * queries, because its caller (ReferralResource) renders a whole
     * company's Kanban board and a query here would be one per row.
     *
     * The rule, in order:
     *   - `cancelled` is never actionable;
     *   - a non-terminal order (pending / awaiting_verification) wins — that
     *     is the one "รับชำระเงินแล้ว" would close;
     *   - failing that, the newest `paid` order, so a completed sale can
     *     still show "ยืนยันโดย …" (§4.3). It is NOT actionable — the caller
     *     reads `status` to decide that — it is the row's history;
     *   - otherwise null.
     *
     * "Newest" is created_at desc with id desc as the tie-breaker: created_at
     * only has second precision, so two orders minted in the same second
     * (routine in tests) would otherwise order arbitrarily.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function actionableOrder(Collection $orders): ?Order
    {
        $usable = $orders->reject(fn (Order $order) => $order->status === OrderStatus::Cancelled);

        return $this->newest($usable->filter(fn (Order $order) => $order->isPayable()))
            ?? $this->newest($usable->filter(fn (Order $order) => $order->status === OrderStatus::Paid));
    }

    /** @param  Collection<int, Order>  $orders */
    private function newest(Collection $orders): ?Order
    {
        return $orders
            ->sortByDesc(fn (Order $order) => [$order->created_at?->getTimestamp() ?? 0, $order->id])
            ->first();
    }

    /** Cancel an order (only from a non-terminal, unpaid state). */
    public function cancel(Order $order): Order
    {
        if (! $order->isPayable()) {
            throw ValidationException::withMessages([
                'status' => 'ยกเลิกได้เฉพาะคำสั่งซื้อที่ยังไม่ชำระเงินเท่านั้น',
            ]);
        }

        $order->update(['status' => OrderStatus::Cancelled]);

        return $order->fresh();
    }

    public function disk(): string
    {
        return self::DISK;
    }

    /**
     * Human-readable, per-company-unique order number: 'ORD-'+8 random
     * upper-case chars, re-rolled on the astronomically-unlikely collision
     * with an existing number in the same company (the DB unique index on
     * (company_id, order_number) is the real guarantee; this just avoids the
     * insert ever failing on it).
     */
    private function generateOrderNumber(int $companyId): string
    {
        do {
            $number = 'ORD-'.strtoupper(Str::random(8));
        } while (Order::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('order_number', $number)
            ->exists());

        return $number;
    }

    /** Unguessable 40-char public share token, unique across all orders. */
    private function generatePublicToken(): string
    {
        do {
            $token = Str::random(40);
        } while (Order::withoutGlobalScopes()->where('public_token', $token)->exists());

        return $token;
    }
}
