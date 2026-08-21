<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Mail\OrderPaymentConfirmedMail;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Referral;
use App\Services\Commission\CommissionReversalService;
use App\Services\Order\OrderService;
use App\Services\Platform\PlatformMailSettingService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

// ADR-017 (TASK-054) — authenticated order management. §5 rule 4: index
// narrows to the Agent's own orders (same shape as ReferralController);
// every single-order action is Policy-gated (own/company/super). The slip
// download mirrors ClientDocumentController — access-checked private-disk
// stream, never a public URL (§6). authorizeResource covers index/show/
// store; the custom confirm/cancel/slip abilities authorize explicitly.
class OrderController extends Controller
{
    /**
     * What an Order must arrive with, for EVERY action on this controller.
     * ONE list, not five — same reasoning as ClientController::RELATIONS.
     *
     * TASK-176 §1.3 — `verifiedBy` feeds OrderResource's `verified_by`. The
     * agent's own OrdersView must show WHO confirmed a payment rather than
     * guess it, and that means loading it everywhere an order is returned,
     * including straight after confirm().
     */
    private const RELATIONS = ['client', 'product', 'agent', 'verifiedBy'];

    public function __construct()
    {
        $this->authorizeResource(Order::class, 'order');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Order::with(self::RELATIONS);
        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('referral_id')) {
            $query->where('referral_id', $request->integer('referral_id'));
        }

        return OrderResource::collection($query->latest()->paginate());
    }

    public function store(StoreOrderRequest $request, OrderService $service): OrderResource
    {
        // TenantScope narrows this to the actor's company; the Agent-only
        // "own referral" gate was already enforced in StoreOrderRequest.
        $referral = Referral::with('product')->findOrFail($request->validated('referral_id'));

        $order = $service->createForReferral($referral, $request->enum('payment_method', PaymentMethod::class));

        return new OrderResource($order->load(self::RELATIONS));
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(self::RELATIONS));
    }

    /** POST /orders/{order}/confirm — verify payment, close the sale (BR-4). */
    public function confirm(Order $order, Request $request, OrderService $service, PlatformMailSettingService $mailSettingService): OrderResource
    {
        $this->authorize('confirm', $order);

        $order = $service->confirmPayment($order, $request->user());
        $order->load(self::RELATIONS);

        // TASK-190 §4.3 — sent AFTER confirmPayment()'s transaction has
        // already committed above, never from inside it (a slow/failing
        // SMTP call must not hold the DB transaction open, and a rollback
        // must never have already sent an email). Only attempted when the
        // client has an email AND platform mail is enabled — silently
        // skipped otherwise (the agent's in-app notification, fired inside
        // OrderService::confirmPayment() itself, is the guaranteed path
        // either way). Wrapped in try/catch: a mail-send failure is logged
        // and must NEVER surface as an error to the Admin who just
        // confirmed a real payment, and must never affect the response
        // built below.
        if (filled($order->client?->email) && ($mailSettingService->get()['is_enabled'] ?? false)) {
            try {
                Mail::to($order->client->email)->send(new OrderPaymentConfirmedMail($order));
            } catch (Throwable $e) {
                Log::error('TASK-190: OrderPaymentConfirmedMail failed to send', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return new OrderResource($order);
    }

    /** POST /orders/{order}/cancel */
    public function cancel(Order $order, OrderService $service): OrderResource
    {
        $this->authorize('cancel', $order);

        $order = $service->cancel($order);

        return new OrderResource($order->load(self::RELATIONS));
    }

    /**
     * POST /orders/{order}/slip — an admin uploads the slip FOR the customer.
     *
     * Follow-up to the 2026-08-21 audit (human ruling). Requiring a slip
     * before confirmation closed a fraud path and simultaneously stranded
     * every customer who pays cash at a branch or sends the slip to their
     * agent over LINE — the public /pay page was the only thing that could
     * create one. This is that missing door, and it is the ONLY difference
     * from the public route: same validation, same private disk, same
     * resulting status.
     *
     * The public POST /pay/{token}/slip is untouched and remains how a
     * customer does it themselves.
     */
    public function uploadSlip(Request $request, Order $order, OrderService $service): OrderResource
    {
        $this->authorize('submitSlip', $order);

        if (! $order->isPayable()) {
            throw ValidationException::withMessages([
                'status' => 'อัปโหลดสลิปได้เฉพาะคำสั่งซื้อที่ยังรอชำระเงินอยู่เท่านั้น (สถานะปัจจุบัน: '.$order->status->label().')',
            ]);
        }

        /*
         * The shipping fields are NOT accepted here, and that is deliberate.
         *
         * On the public page they are captured from the customer, who is
         * the only person who knows where they live. An admin typing a
         * delivery address on somebody's behalf, from a phone call, is how
         * a parcel goes to the wrong place with the system's full
         * confidence. If the product needs shipping details, the customer
         * still supplies them through /pay.
         */
        $validated = $request->validate([
            'slip' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $service->submitSlip($order, $validated['slip'], [], $request->user());

        /*
         * Audited, because this is a member of staff asserting that a
         * payment they did not receive personally exists. The same admin
         * may then confirm it (OrderPolicy::submitSlip explains why that is
         * accepted), so this row is what makes the sequence visible
         * afterwards rather than merely permitted at the time.
         */
        AuditLog::create([
            'company_id' => $order->company_id,
            'actor_user_id' => $request->user()->id,
            'action' => 'order.slip_uploaded_by_staff',
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'old_values' => null,
            'new_values' => [
                'order_number' => $order->order_number,
                'amount_satang' => $order->amount_satang,
            ],
            'ip_address' => $request->ip(),
        ]);

        return new OrderResource($order->fresh()->load(self::RELATIONS));
    }

    /**
     * POST /orders/{order}/refund — undo a paid sale (SECURITY AUDIT V15, ruling D3).
     *
     * Super Admin only; see OrderPolicy::refund() for why it is narrower
     * than confirm(). A reason is REQUIRED, not optional: a money movement
     * with no stated cause is a gap in the audit trail at the exact point
     * somebody will later need to read it.
     */
    public function refund(Request $request, Order $order, CommissionReversalService $reversals): OrderResource
    {
        $this->authorize('refund', $order);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $reversals->refundOrder($order, $request->user(), $validated['reason']);

        return new OrderResource($order->fresh()->load(self::RELATIONS));
    }

    /** GET /orders/{order}/slip — access-checked private-disk download (§6). */
    public function slip(Order $order, OrderService $service): mixed
    {
        $this->authorize('view', $order);

        abort_if($order->slip_path === null, 404);

        return Storage::disk($service->disk())->download($order->slip_path, "{$order->order_number}-slip");
    }
}
