<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\StoreCommissionHouseAccountRequest;
use App\Http\Requests\Commission\UpdateCommissionHouseAccountRequest;
use App\Http\Resources\CommissionSettingResource;
use App\Models\Company;
use App\Services\Commission\CommissionHouseAccountService;
use App\Services\Commission\CommissionSettingService;
use Illuminate\Http\Request;

/**
 * 2026-09-15 — turning the company's own seat in its hierarchy on and off.
 *
 * Owner: "ให้เพิ่ม user admin company เพิ่มเพื่อรับค่าคอมบริษัท".
 *
 * THREE ROUTES, on purpose. The seat is either there or it is not, and
 * everything about HOW it is paid is the ordinary leader rate in step 4,
 * which already has its own door. The third is rename(), added 2026-09-15:
 * the only mutable thing about the seat itself is its display name, and the
 * guards that keep this row from being edited as a person had closed that
 * too — see CommissionHouseAccountService::rename().
 *
 * All three writes go through Ability::SettingsCommissionPlanUpdate — the same
 * gate as writing a rate, and for the same reason: switching this on changes
 * what every agent in the company takes home on their next sale.
 *
 * ── WHY IT ANSWERS WITH THE WHOLE COMMISSION SETTING ──
 *
 * Creating the seat moves `deepest_manager_chain` (every unmanaged agent
 * gains an upline), and that number is what step 4 uses to show the deduction
 * ceiling. Returning only the new account would leave the screen holding a
 * ceiling that was true one request ago, on the screen where a wrong ceiling
 * is offered as a maximum somebody then types.
 */
class CommissionHouseAccountController extends Controller
{
    public function store(
        StoreCommissionHouseAccountRequest $request,
        CommissionHouseAccountService $houseAccounts,
        CommissionSettingService $settings,
    ): CommissionSettingResource {
        $company = $this->company($request);

        $houseAccounts->create($company, $request->input('display_name'));

        return new CommissionSettingResource($settings->forCompany($company->id));
    }

    /**
     * 2026-09-15 — the one edit of the seat that is allowed: its name.
     *
     * The docblock above used to say the display name "is an ordinary user
     * edit". It was not, and saying so hid a dead end: every guard this
     * feature added — UserPolicy::update, UserService's refusals — closes the
     * ordinary door on this row, which is correct and which left a company
     * that mistyped the name at setup with no way to fix it.
     */
    public function update(
        UpdateCommissionHouseAccountRequest $request,
        CommissionHouseAccountService $houseAccounts,
        CommissionSettingService $settings,
    ): CommissionSettingResource {
        $company = $this->company($request);

        $houseAccounts->rename($company, $request->string('display_name')->toString());

        return new CommissionSettingResource($settings->forCompany($company->id));
    }

    public function destroy(
        Request $request,
        CommissionHouseAccountService $houseAccounts,
        CommissionSettingService $settings,
    ): CommissionSettingResource {
        abort_unless($request->user()->can(Ability::SettingsCommissionPlanUpdate), 403);

        $company = $this->company($request);

        $houseAccounts->disable($company);

        return new CommissionSettingResource($settings->forCompany($company->id));
    }

    /**
     * The company being configured — never the client's word for it unless
     * the caller is a Super Admin, who has no company of their own to infer
     * from (BR-6, the same resolution every sibling settings controller uses).
     */
    private function company(Request $request): Company
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        abort_if(! $companyId, 422, 'กรุณาเลือกบริษัทก่อน');

        return Company::findOrFail($companyId);
    }
}
