<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\ShippingStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierOrderResource;
use App\Models\Order;
use App\Models\SupplierSettlementLedger;
use App\Models\SupplierWithdrawalRequest;
use App\Services\Supplier\ShipmentService;
use App\Services\Supplier\SupplierPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 2026-09-16 — everything a Company Partner can do, in one place.
 *
 * Every route here lives under /supplier, which is the prefix
 * RestrictScopedRole opens for this role and nothing else. That is not a
 * naming convention — it is the security boundary. A supplier-visible endpoint
 * placed anywhere else would be unreachable; one placed here is reachable by
 * every partner, so this file is the list of what they may do and it should
 * stay short enough to read in one sitting.
 *
 * ── THE FILTER THAT IS THE WHOLE SECURITY MODEL ──
 *
 * `supplierScope()` below. Orders belong to the companies that SOLD them, and
 * a supplier is never one of those companies, so TenantScope gives no
 * protection here at all — it would scope to the supplier's own company and
 * return nothing. Every query therefore filters by
 * `products.supplier_company_id = the caller's company` by hand.
 *
 * Get that wrong in the permissive direction and a supplier sees another
 * supplier's orders, complete with customers' names and home addresses. There
 * is no second line of defence behind this one, which is why it is written
 * once, here, and every method calls it rather than rebuilding the condition.
 */
class SupplierPortalController extends Controller
{
    /**
     * Orders for products THIS supplier supplies, across every company that
     * sells them.
     *
     * Only PAID ones. An unpaid order is not something to ship, and it carries
     * a customer's name and address for a sale that may never happen —
     * disclosing that would be giving away our prospects, not fulfilling an
     * order.
     */
    public function orders(Request $request): AnonymousResourceCollection
    {
        $query = $this->supplierScope($request)
            ->where('status', OrderStatus::Paid->value)
            /*
             * withoutGlobalScopes() on BOTH relations, not just the query.
             *
             * Product and Client each carry a tenant scope keyed to the
             * AUTHENTICATED user, and a supplier's own company is never the
             * company that sold the order. Loaded normally they come back
             * null, so the rows render with no product name and no customer —
             * a screen that looks broken rather than one that looks wrong,
             * which is the safer failure but still a failure, and the exact
             * trap VoucherRedemptionService::lookup() documents.
             */
            ->with([
                'product' => fn ($q) => $q->withoutGlobalScopes(),
                'client' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->latest('paid_at');

        // "What do I still have to send?" — the only filter this screen needs
        // on day one, and the reason somebody opens it.
        if ($request->query('shipping_status') === ShippingStatus::Pending->value) {
            $query->where('shipping_status', ShippingStatus::Pending->value);
        }

        return SupplierOrderResource::collection($query->paginate(25));
    }

    /** Record a parcel against one of this supplier's orders. */
    public function ship(Request $request, int $orderId, ShipmentService $service): SupplierOrderResource
    {
        /*
         * Resolved by hand rather than through route-model binding.
         *
         * Binding applies Order's tenant scope, which keys to the caller's own
         * company — never the company that sold this order — so every single
         * shipment request 404s before the controller is even entered. The
         * access check below is the real one, and it has to be given something
         * to check.
         */
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);

        $this->assertBelongsToSupplier($request, $order);

        $validated = $request->validate([
            // Required, and the docblock on ShipmentService says why: it is
            // the only part of "I sent it" that anybody else can check.
            'tracking_number' => ['required', 'string', 'max:255'],
        ]);

        $updated = $service->markShipped($order, $request->user(), $validated['tracking_number']);

        return new SupplierOrderResource($updated->load([
            'product' => fn ($q) => $q->withoutGlobalScopes(),
            'client' => fn ($q) => $q->withoutGlobalScopes(),
        ]));
    }

    /**
     * What this supplier is owed, and what it is made of.
     *
     * Three numbers rather than one, because "you are owed X" invites the
     * reply "no, you owe me more than that" — and the answer is almost always
     * that the difference is money whose release trigger has not fired yet.
     * Showing it alongside means the screen answers the question instead of
     * starting a phone call.
     */
    public function balance(Request $request, SupplierPayoutService $payouts): JsonResponse
    {
        $supplier = $request->user()->company;

        return response()->json(['data' => $payouts->balanceFor($supplier)]);
    }

    /** The individual sales behind that balance. */
    public function settlements(Request $request): JsonResponse
    {
        $rows = SupplierSettlementLedger::query()
            ->where('supplier_company_id', $request->user()->company_id)
            ->with(['order:id,order_number,paid_at', 'product:id,name'])
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (SupplierSettlementLedger $row) => [
                'id' => $row->id,
                'order_number' => $row->order?->order_number,
                'product_name' => $row->product?->name,
                'sale_price_satang' => $row->sale_price_satang_at_time,
                // What they get. NOT the commission or the GP that produced
                // it — those are our side of the arithmetic (see
                // SupplierOrderResource for the same reasoning).
                'amount_satang' => $row->amount_satang,
                'released_at' => $row->released_at?->toIso8601String(),
                'payment_status' => $row->payment_status->value,
                'created_at' => $row->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /** Payments we have raised or made to this supplier. */
    public function payouts(Request $request): JsonResponse
    {
        $requests = SupplierWithdrawalRequest::query()
            ->where('supplier_company_id', $request->user()->company_id)
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'data' => $requests->getCollection()->map(fn (SupplierWithdrawalRequest $row) => [
                'id' => $row->id,
                'status' => $row->status->value,
                // All three figures, every time. A supplier reconciling a bank
                // statement sees `net` arrive and has to tie it back to the
                // `gross` on their invoice; withholding is the difference, and
                // showing only one of the three makes that impossible.
                'gross_satang' => $row->gross_satang,
                'wht_satang' => $row->wht_satang,
                'net_satang' => $row->net_satang,
                'wht_certificate_no' => $row->wht_certificate_no,
                'transferred_at' => $row->transferred_at?->toIso8601String(),
                'transfer_reference' => $row->transfer_reference,
                'rejection_reason' => $row->rejection_reason,
                'created_at' => $row->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * Orders reachable by the calling supplier — the one condition everything
     * here is built on.
     *
     * withoutGlobalScopes() on both sides is required, not defensive: Order
     * and Product both carry tenant scopes keyed to the authenticated user,
     * and a supplier's own company is never the selling company. Left on, this
     * returns an empty set for every supplier and the screen looks broken
     * rather than insecure — which is the safer failure, but still a failure.
     */
    private function supplierScope(Request $request)
    {
        $supplierId = $request->user()->company_id;

        return Order::withoutGlobalScopes()
            ->whereHas('product', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('supplier_company_id', $supplierId));
    }

    /**
     * 404, not 403, on an order belonging to a different supplier.
     *
     * Same reasoning as every other cross-tenant lookup here: 403 confirms the
     * row exists, and "exists but not yours" is itself information about
     * another supplier's business.
     */
    private function assertBelongsToSupplier(Request $request, Order $order): void
    {
        $user = $request->user();

        abort_unless($user->role === UserRole::CompanyPartner, 403);

        $product = $order->product()->withoutGlobalScopes()->first();

        abort_unless(
            $product?->supplier_company_id !== null
                && $product->supplier_company_id === $user->company_id,
            404,
        );
    }
}
