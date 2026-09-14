<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Commission\CommissionResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-09-14 — "สินค้าตัวนี้จ่ายเท่าไหร่ และมาจากชั้นไหน", per product.
 *
 * Feeds the product x layer table on ขั้นที่ 3 and ขั้นที่ 4. It exists as an
 * endpoint rather than as a computation in the browser for one reason, and it
 * is written down here because the cheap version will look tempting again: the
 * screen ALREADY recomputes this ladder in JavaScript for its badges, and the
 * last time that copy drifted from the server's, a 5% rate configured for Thai
 * Life was shown and paid on AIA, into ledger rows BR-4 forbids correcting.
 * A table people use to decide what their agents earn is answered by the code
 * that pays them, or it is not answered.
 *
 * ── PERMISSIONS ──
 *
 * Ability::CommissionReadinessView, deliberately reused rather than minted
 * fresh. It is held by Company Admin and Super Admin and by nobody else, which
 * is exactly this payload's audience, and it already means "may see how this
 * company's commission is set up". A new ability would have to be granted to
 * the same two rows and would then need its own reason for existing.
 *
 * An Agent is refused for the same reason they are refused the readiness
 * verdict: they cannot change a rate, and a table of everyone's rates is not
 * theirs to read.
 *
 * ?company_id= narrows for a Super Admin ONLY; every other role is resolved
 * from the actor and a supplied id is ignored outright (BR-6). Same shape as
 * CommissionReadinessController, on purpose.
 */
class CommissionResolutionController extends Controller
{
    public function show(Request $request, CommissionResolutionService $service): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->can(Ability::CommissionReadinessView), 403);

        $companyId = $user->isSuperAdmin() && $request->filled('company_id')
            ? $request->integer('company_id')
            : $user->company_id;

        /*
         * A Super Admin on "ทุกบริษัท" has no single answer, and inventing one
         * by picking a tenant would be the screen asserting a rate belongs to
         * a company nobody named. An empty envelope, and the table says "เลือก
         * บริษัทก่อน" — the same refusal step 2 already makes.
         */
        if ($companyId === null) {
            return response()->json(['data' => null]);
        }

        $company = Company::find($companyId);

        abort_if($company === null, 404, 'ไม่พบบริษัท');

        return response()->json(['data' => $service->forCompany($company)]);
    }
}
