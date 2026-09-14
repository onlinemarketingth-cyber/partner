<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\PreviewCommissionRateImpactRequest;
use App\Models\Company;
use App\Services\Commission\CommissionRateImpactService;
use Illuminate\Http\JsonResponse;

/**
 * 2026-09-14 — "บันทึกแล้วจะเกิดอะไรขึ้น" (owner's ข้อเสนอ 3).
 *
 * A rate form can only refuse invalid input. The mistakes that cost money here
 * are VALID rates that do something other than what the admin pictured — a
 * category rate touching four products instead of the one they had in mind, a
 * company default that changes nothing because everything already overrides
 * it, a rate that reaches no product at all. None of those is an error, so the
 * only honest intervention is to show the arithmetic before the ledger makes
 * it permanent (BR-4).
 *
 * READ-ONLY by construction. There is no flag on this endpoint that makes it
 * write; the write lives on the rate resources where it always did.
 */
class CommissionRateImpactController extends Controller
{
    public function store(PreviewCommissionRateImpactRequest $request, CommissionRateImpactService $service): JsonResponse
    {
        // The server decides the company, never the client — a Company Admin
        // cannot pass authorize() today, so the else-branch is a structural
        // guarantee rather than a live path (BR-6).
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $company = Company::find($companyId);

        abort_if($company === null, 404, 'ไม่พบบริษัท');

        $overrideMode = $request->validated('override_mode');

        return response()->json([
            'data' => $service->preview(
                $company,
                $request->validated('kind'),
                CommissionRateType::from($request->validated('rate_type')),
                (int) $request->validated('rate_value'),
                $request->validated('product_id'),
                $request->validated('product_category_id'),
                $overrideMode === null ? null : CommissionOverrideMode::from($overrideMode),
                $request->validated('exclude_rule_id'),
            ),
        ]);
    }
}
