<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreSupplierRequest;
use App\Http\Requests\Platform\UpdateSupplierRequest;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierSettlementLedger;
use App\Models\User;
use App\Services\Supplier\SupplierPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-09-17 — MANAGING SUPPLIERS. The screen that was missing.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * The supplier feature shipped with no way to create a supplier. Every column
 * was built, every service read them, and the only way to make one was to edit
 * the database by hand — so the whole chain was unusable from its first step:
 * no supplier meant an empty picker on the product form, which meant no
 * supplied products, which meant nothing to pay.
 *
 * The first attempt to fix that put the settings on the COMPANY screen, as a
 * panel next to the commission plan. The owner rejected it outright, and was
 * right to: a tenant and a supplier are different counterparties with
 * different money flowing in different directions, and editing one on the
 * other's screen teaches everybody the wrong model of the business.
 *
 * Worth recording as a CLASS of mistake rather than a one-off: the tests all
 * passed, because every one of them built its own fixture with the supplier
 * already configured. Fixtures never walk the path a person walks, so a
 * missing screen is invisible to them. SupplierSetupPathTest exists to walk
 * it.
 *
 * ── SUPER ADMIN ONLY, EVERY METHOD ──
 *
 * A supplier is platform data: its products are sold by every company on the
 * platform and its sales span all of them. There is no single tenant whose
 * admin could own it, and letting one edit it would let them set our margin on
 * other companies' sales.
 *
 * ── THE ROUTE PREFIX ──
 *
 * `/suppliers`, plural — NOT `/supplier`, which RestrictScopedRole opens to
 * the partner role. That middleware matches whole segments, so `suppliers`
 * neither equals `supplier` nor starts with `supplier/`, and a partner
 * reaching this file gets 403. Renaming this to the singular would hand every
 * supplier the screen that edits every supplier's deal.
 */
class SupplierController extends Controller
{
    /** Everyone we buy from, with enough on each row to spot a problem. */
    public function index(Request $request, SupplierPayoutService $payouts): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $query = Supplier::query()->withCount('products');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%");
            });
        }

        // Tri-state on purpose: absent means "all", which is what the screen
        // opens on. `boolean()` would read a missing parameter as false and
        // silently hide every active supplier.
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $suppliers = $query->orderBy('name')->paginate(50);

        return response()->json([
            'data' => $suppliers->getCollection()->map(function (Supplier $supplier) use ($payouts) {
                $balance = $payouts->balanceFor($supplier);

                return [
                    ...$this->present($supplier),
                    'products_count' => (int) $supplier->products_count,
                    /*
                     * The balance is on the LIST, not just the detail page,
                     * because the question this screen actually gets opened
                     * for is "who are we behind with". Making that a click
                     * away per row means nobody looks.
                     */
                    'payable_satang' => $balance['payable_satang'],
                    'unreleased_satang' => $balance['unreleased_satang'],
                ];
            }),
            'meta' => [
                'current_page' => $suppliers->currentPage(),
                'last_page' => $suppliers->lastPage(),
                'total' => $suppliers->total(),
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create([
            ...$request->validated(),
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($supplier)], 201);
    }

    public function show(Request $request, Supplier $supplier, SupplierPayoutService $payouts): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return response()->json([
            'data' => [
                ...$this->present($supplier),
                ...$payouts->balanceFor($supplier),
                /*
                 * How much we have actually paid, all time. Not derivable from
                 * the three balance figures — they are all about money still
                 * outstanding — and it is the number somebody checks a
                 * supplier's own statement against.
                 */
                'paid_satang' => (int) SupplierSettlementLedger::query()
                    ->where('supplier_id', $supplier->id)
                    ->where('payment_status', PaymentStatus::Paid->value)
                    ->sum('amount_satang'),
            ],
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return response()->json(['data' => $this->present($supplier->fresh())]);
    }

    /**
     * Remove a supplier created by mistake.
     *
     * ── WHY THIS REFUSES SO OFTEN ──
     *
     * A supplier with settlement history is a supplier we have owed money to.
     * Soft-deleting one takes it off the payout screen — which lists suppliers
     * from this table — with the debt fully intact and nothing anywhere
     * showing it. That is the kind of disappearance nobody notices until the
     * supplier telephones.
     *
     * Deactivating (`is_active = false`) is the move for a deal that has
     * ENDED: the history stays readable, the balance stays payable, and the
     * row stays on the payout screen marked inactive. Deleting is only for a
     * row that never meant anything.
     *
     * Products are checked separately from settlements because a supplier can
     * have products that have never sold — deleting that one would null the
     * supplier on live catalogue entries (the FK is nullOnDelete) and leave
     * products nobody can be paid for, silently.
     */
    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        abort_if(
            SupplierSettlementLedger::where('supplier_id', $supplier->id)->exists(),
            422,
            'คู่ค้ารายนี้มีประวัติการตั้งหนี้แล้ว จึงลบไม่ได้ — ให้ปิดการใช้งานแทน',
        );

        abort_if(
            Product::withoutGlobalScopes()->where('supplier_id', $supplier->id)->exists(),
            422,
            'ยังมีสินค้าผูกกับคู่ค้ารายนี้อยู่ — ย้ายหรือลบสินค้าก่อนจึงจะลบคู่ค้าได้',
        );

        abort_if(
            User::withoutGlobalScopes()->where('supplier_id', $supplier->id)->exists(),
            422,
            'ยังมีบัญชีผู้ใช้ผูกกับคู่ค้ารายนี้อยู่ — ลบบัญชีก่อนจึงจะลบคู่ค้าได้',
        );

        $supplier->delete();

        return response()->json(['data' => ['id' => $supplier->id]]);
    }

    /**
     * The products this supplier brings in, and the GP that applies to each.
     *
     * `effective_gp` rather than the raw override, because the question a
     * person opens this tab with is "what do we keep on this item" and the
     * answer is the product's override when it has one and the deal's
     * otherwise. Showing only the override answers it correctly for the few
     * products that have one and leaves the rest blank.
     */
    public function products(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $products = Product::withoutGlobalScopes()
            ->where('supplier_id', $supplier->id)
            ->orderBy('name')
            ->paginate(50);

        return response()->json([
            'data' => $products->getCollection()->map(function (Product $product) use ($supplier) {
                $hasOverride = $product->supplier_gp_mode !== null && $product->supplier_gp_value !== null;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'is_active' => (bool) $product->is_active,
                    'price_satang' => $product->price_satang,
                    'gp_mode' => $hasOverride ? $product->supplier_gp_mode?->value : $supplier->gp_mode?->value,
                    'gp_value' => $hasOverride ? $product->supplier_gp_value : $supplier->gp_value,
                    'gp_is_override' => $hasOverride,
                    'wht_rate' => $product->supplier_wht_rate ?? $supplier->wht_rate,
                    'wht_is_override' => $product->supplier_wht_rate !== null,
                ];
            }),
            'meta' => ['total' => $products->total()],
        ]);
    }

    /**
     * The logins that belong to this supplier.
     *
     * Read-only here. Creating one is POST /users with role=company_partner
     * and this supplier's id — the same endpoint, policy and audit trail every
     * other account goes through. A second creation path would be a second
     * place for the password rules, the audit write and the role gate to
     * drift.
     */
    public function users(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $users = User::withoutGlobalScopes()
            ->where('supplier_id', $supplier->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role->value,
                // Surfaced because a login whose role drifted off
                // company_partner still carries supplier_id and would sit in
                // this list looking like a partner while reaching nothing.
                'is_partner_role' => $user->role === UserRole::CompanyPartner,
                'created_at' => $user->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'legal_name' => $supplier->legal_name,
            'tax_id' => $supplier->tax_id,
            'contact_name' => $supplier->contact_name,
            'contact_phone' => $supplier->contact_phone,
            'contact_email' => $supplier->contact_email,
            'address' => $supplier->address,
            'is_active' => (bool) $supplier->is_active,

            'gp_mode' => $supplier->gp_mode?->value,
            'gp_value' => $supplier->gp_value,
            'release_trigger' => $supplier->release_trigger?->value,
            'min_withdrawal_satang' => $supplier->min_withdrawal_satang,
            'wht_rate' => $supplier->wht_rate,

            'payout_bank_name' => $supplier->payout_bank_name,
            'payout_bank_account_number' => $supplier->payout_bank_account_number,
            'payout_bank_account_name' => $supplier->payout_bank_account_name,

            'terms_complete' => $supplier->hasCompleteTerms(),
            'missing_terms' => $supplier->missingTerms(),
            'created_at' => $supplier->created_at?->toIso8601String(),
        ];
    }
}
