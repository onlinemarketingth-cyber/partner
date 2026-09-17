<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateSupplierTermsRequest;
use App\Models\Company;
use App\Models\SupplierSettlementLedger;
use App\Models\SupplierWithdrawalRequest;
use App\Services\Supplier\SupplierPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-09-16 — OUR side of paying suppliers. Super Admin only.
 *
 * Note the route prefix: /supplier-payouts, NOT /supplier. That is deliberate
 * and load-bearing. RestrictScopedRole opens `supplier` to the partner role by
 * prefix, and its matching is segment-exact — `supplier-payouts` neither
 * equals `supplier` nor starts with `supplier/`, so a partner reaching for
 * this file gets 403. Renaming this prefix to something under /supplier would
 * hand every supplier the screen that decides what suppliers get paid.
 *
 * ── WHY SUPER ADMIN AND NOT COMPANY ADMIN ──
 *
 * A supplier's sales span several of our companies, so there is no single
 * tenant whose admin could own this queue — and a Company Admin approving a
 * payment that includes other companies' sales is exactly the cross-tenant
 * read BR-6 exists to prevent. Same reasoning that makes refunds Super-Admin
 * only (OrderPolicy::refund).
 */
class SupplierPayoutController extends Controller
{
    /**
     * Every supplier with money owed, and how much.
     *
     * One row per supplier, not per sale — this screen answers "who do we owe
     * and how much", and the sales behind a figure are one click away.
     */
    public function index(Request $request, SupplierPayoutService $payouts): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $suppliers = Company::withoutGlobalScopes()
            ->where('is_supplier', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $suppliers->map(function (Company $supplier) use ($payouts) {
                $balance = $payouts->balanceFor($supplier);

                return [
                    'supplier_company_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'bank_name' => $supplier->supplier_payout_bank_name,
                    'bank_account_number' => $supplier->supplier_payout_bank_account_number,
                    'bank_account_holder_name' => $supplier->supplier_payout_bank_account_name,
                    /*
                     * Surfaced so the screen can say WHY a supplier cannot be
                     * paid rather than simply not offering the button. "GP not
                     * configured" is a thing somebody can go and fix; a
                     * disabled button with no explanation is a support ticket.
                     */
                    'terms_complete' => $supplier->supplier_gp_mode !== null
                        && $supplier->supplier_gp_value !== null
                        && $supplier->supplier_release_trigger !== null,
                    ...$balance,
                ];
            }),
        ]);
    }

    /** Raise a payout for everything a supplier can currently be paid. */
    public function store(Request $request, SupplierPayoutService $payouts): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'supplier_company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $supplier = Company::withoutGlobalScopes()->findOrFail($validated['supplier_company_id']);

        abort_unless((bool) $supplier->is_supplier, 404);

        // CompanyPayout, so it opens Approved: the admin pressing this button
        // IS the decision, and asking them to approve it on the next screen
        // would be the rubber stamp WithdrawalSource warns about.
        $created = $payouts->open($supplier, $request->user(), WithdrawalSource::CompanyPayout);

        return response()->json(['data' => $this->present($created)], 201);
    }

    /** The queue: raised, awaiting review, awaiting transfer, or history. */
    public function requests(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $query = SupplierWithdrawalRequest::query()->with('supplier:id,name')->latest('id');

        if ($status = $request->query('status')) {
            // An unknown status narrows to nothing rather than widening to
            // everything — the same anti-footgun the payout report uses.
            $query->where('status', WithdrawalStatus::tryFrom((string) $status)?->value ?? '__none__');
        }

        return response()->json([
            'data' => $query->paginate(50)->getCollection()->map(fn ($r) => $this->present($r)),
        ]);
    }

    /** Money has left the bank. Settles the ledger rows behind this request. */
    public function markTransferred(Request $request, SupplierWithdrawalRequest $supplierWithdrawalRequest, SupplierPayoutService $payouts): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'transfer_reference' => ['nullable', 'string', 'max:255'],
            // Recorded here rather than on a separate screen because the
            // person doing the transfer is the person holding the paperwork.
            'wht_certificate_no' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $payouts->markTransferred(
            $supplierWithdrawalRequest,
            $request->user(),
            $validated['transfer_reference'] ?? null,
        );

        if (! empty($validated['wht_certificate_no'])) {
            $updated->update(['wht_certificate_no' => $validated['wht_certificate_no']]);
        }

        return response()->json(['data' => $this->present($updated->fresh())]);
    }

    /** The sales behind one supplier's balance. */
    public function settlements(Request $request, Company $company): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $rows = SupplierSettlementLedger::query()
            ->where('supplier_company_id', $company->id)
            ->with(['order:id,order_number', 'product:id,name', 'company:id,name'])
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (SupplierSettlementLedger $row) => [
                'id' => $row->id,
                'order_number' => $row->order?->order_number,
                'product_name' => $row->product?->name,
                // Which of OUR companies sold it — the thing a supplier
                // statement is unauditable without.
                'sold_by_company' => $row->company?->name,
                'sale_price_satang' => $row->sale_price_satang_at_time,
                // Shown on OUR screen and never on the supplier's: this is the
                // arithmetic of our own margin.
                'commission_satang' => $row->commission_satang_at_time,
                'gp_satang' => $row->gp_satang_at_time,
                'amount_satang' => $row->amount_satang,
                'wht_rate' => $row->wht_rate_at_time,
                'released_at' => $row->released_at?->toIso8601String(),
                'payment_status' => $row->payment_status->value,
            ]),
            'meta' => ['total' => $rows->total()],
        ]);
    }

    /**
     * The supplier deal for ONE company — read.
     *
     * Lives here rather than on CompanyController because it is the same
     * subject as everything else in this file: what we owe a supplier and on
     * what terms. CompanyController is about a tenant's identity.
     */
    public function terms(Request $request, Company $company): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return response()->json(['data' => $this->presentTerms($company)]);
    }

    /**
     * Set it.
     *
     * 2026-09-17 — the screen that was missing. Until this existed a company
     * could only be made a supplier by editing the database, which made the
     * whole feature unreachable from its own first step.
     */
    public function updateTerms(UpdateSupplierTermsRequest $request, Company $company): JsonResponse
    {
        $validated = $request->validated();

        /*
         * Turning a supplier OFF while we still owe them is refused.
         *
         * The settlement rows keep their own snapshots, so history is safe
         * either way — the problem is the payout screen, which lists suppliers
         * by this flag. Clearing it hides a company we owe money to, with the
         * debt fully intact and nothing on any screen showing it. That is the
         * kind of disappearance nobody notices until the supplier telephones.
         */
        if ($validated['is_supplier'] === false && $company->is_supplier) {
            $owed = SupplierSettlementLedger::query()
                ->where('supplier_company_id', $company->id)
                ->where('payment_status', PaymentStatus::Pending->value)
                ->exists();

            abort_if($owed, 422, 'ยังมียอดค้างจ่ายให้บริษัทนี้อยู่ — จ่ายให้ครบก่อนจึงจะยกเลิกสถานะคู่ค้าได้');
        }

        $company->update($validated);

        return response()->json(['data' => $this->presentTerms($company->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTerms(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'is_supplier' => (bool) $company->is_supplier,
            'supplier_gp_mode' => $company->supplier_gp_mode?->value,
            'supplier_gp_value' => $company->supplier_gp_value,
            'supplier_release_trigger' => $company->supplier_release_trigger?->value,
            'supplier_min_withdrawal_satang' => $company->supplier_min_withdrawal_satang,
            'supplier_wht_rate' => $company->supplier_wht_rate,
            'supplier_payout_bank_name' => $company->supplier_payout_bank_name,
            'supplier_payout_bank_account_number' => $company->supplier_payout_bank_account_number,
            'supplier_payout_bank_account_name' => $company->supplier_payout_bank_account_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SupplierWithdrawalRequest $r): array
    {
        return [
            'id' => $r->id,
            'supplier_company_id' => $r->supplier_company_id,
            'supplier_name' => $r->supplier?->name,
            'status' => $r->status->value,
            'source' => $r->source->value,
            'gross_satang' => $r->gross_satang,
            'wht_rate_at_time' => $r->wht_rate_at_time,
            'wht_satang' => $r->wht_satang,
            'net_satang' => $r->net_satang,
            'wht_certificate_no' => $r->wht_certificate_no,
            'bank_name' => $r->bank_name,
            'bank_account_number' => $r->bank_account_number,
            'bank_account_holder_name' => $r->bank_account_holder_name,
            'transferred_at' => $r->transferred_at?->toIso8601String(),
            'transfer_reference' => $r->transfer_reference,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
