<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\UpdateCommissionWithdrawalSettingRequest;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The per-company minimum an agent may ask to withdraw (2026-08-27).
 *
 * A single scalar, so it lives on `companies` rather than in a settings
 * table of its own — the same call the payment_* fields next to it already
 * made. It gets its own endpoint anyway, instead of riding on
 * CompanyController's update: that resource is Super-Admin-only, and a
 * Company Admin must be able to set their own company's floor without being
 * handed the whole company record to edit.
 *
 * Same authorization shape as CommissionBinarySettingController — Company
 * Admin within their own company, Super Admin anywhere.
 */
class CommissionWithdrawalSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Ability::SettingsCommissionWithdrawalView), 403);

        $company = $this->resolveCompany($request);

        return response()->json([
            'company_id' => $company?->id,
            // null means NO minimum — a real setting. The UI must render an
            // empty field, never a zero it then saves back as a floor.
            'min_withdrawal_satang' => $company?->min_withdrawal_satang,
        ]);
    }

    public function update(UpdateCommissionWithdrawalSettingRequest $request): JsonResponse
    {
        $company = $this->resolveCompany($request);

        abort_if($company === null, 404, 'ไม่พบบริษัท');

        $old = $company->min_withdrawal_satang;
        $new = $request->validated('min_withdrawal_satang');

        $company->update(['min_withdrawal_satang' => $new]);

        // §6 — this value decides when money may leave, so a change to it is
        // audited like every other money-adjacent setting.
        AuditLog::create([
            'company_id' => $company->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'settings.commission_withdrawal_minimum_updated',
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'old_values' => ['min_withdrawal_satang' => $old],
            'new_values' => ['min_withdrawal_satang' => $new],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'company_id' => $company->id,
            'min_withdrawal_satang' => $company->fresh()->min_withdrawal_satang,
        ]);
    }

    /**
     * A Super Admin says which company; everyone else gets their own, and
     * cannot ask for another. Reading the id from the request for a Company
     * Admin would be the whole tenant boundary undone by one query
     * parameter.
     */
    private function resolveCompany(Request $request): ?Company
    {
        if ($request->user()->isSuperAdmin()) {
            $id = $request->integer('company_id') ?: null;

            return $id ? Company::find($id) : null;
        }

        return $request->user()->company;
    }
}
