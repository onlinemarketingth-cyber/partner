<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Services\Pipeline\PipelineTemplateProvisioner;
use App\Services\Theme\ThemePresetService;
use Illuminate\Support\Facades\DB;

// CLAUDE.md §2 "Company (Tenant)", Section 5. Deliberately thin — a
// Company itself is just name/slug/is_active, no computed fields.
//
// RESOLVED 2026-08-13 by TASK-183 (human decision: ship ahead of the
// permission-system plan). The note that stood here — "deactivating
// (`is_active = false`) or soft-deleting a company does NOT currently block
// its users from logging in or acting" — was accurate and is no longer true.
//
// What "deactivating a company" now enforces, all of it through the ONE
// predicate Company::isOperational() (`is_active === true && deleted_at ===
// null`):
//   * login is refused (LoginGateService, first in its refusal order — it
//     reaches Company Admins too, unlike every other entry there),
//   * every authenticated request is refused, so a session or token minted
//     before the deactivation stops working immediately rather than at the
//     next login (App\Http\Middleware\EnsureCompanyIsOperational),
//   * every PUBLIC endpoint that acts on behalf of a company refuses:
//     registration (invite code + recruit link), email verification, the
//     payment page, product-share landing/checkout, affiliate redirect and
//     lead capture, sales-material share download, and the pre-login theme.
// Super Admin (company_id = null) is exempt throughout — they are who
// reactivates a company, so gating them would make delete() irreversible
// through the API.
//
// delete() below is therefore now a REAL kill switch. Both it and
// update({is_active: false}) reach the same enforcement; nothing here needs
// to know which one an Admin used.
class CompanyService
{
    public function __construct(
        private PipelineTemplateProvisioner $pipelineTemplateProvisioner,
        private ThemePresetService $themePresetService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Company
    {
        $data['is_active'] = $data['is_active'] ?? true;

        // ADR-026 §3.1 / §3.3 (ag-lead, TASK-134a review). A company with
        // no pipeline templates is not a company with a mild config gap —
        // PipelineTemplateResolver fails closed by design, so NO referral
        // can advance and NO order can be confirmed for that tenant. The
        // templates were previously created only by a seeder, which by
        // definition can only cover companies that existed the last time
        // someone ran it; every company created through the Admin UI would
        // have looked perfectly healthy right up until its first sale and
        // then silently refused to close it.
        //
        // Same transaction as the company row: a company that exists but
        // cannot sell is worse than one that failed to be created, because
        // the first looks like success.
        return DB::transaction(function () use ($data) {
            $company = Company::create($data);

            $this->pipelineTemplateProvisioner->provision($company);

            // TASK-161 §5.1 (human decision, 2026-08-11). Same place and
            // the same reasoning as the line above: a brand-new tenant
            // opening ตั้งค่าระบบ to an empty preset list has nothing to
            // fall back to the moment they start experimenting with
            // colours. The preset is a snapshot of the theme the company
            // already has (resolved, so it is never a row of nulls) — it
            // invents no palette, which is exactly why it was approved.
            //
            // TASK-164 §3 widens this to the five DESIGNED palettes the
            // human supplied on 2026-08-11 (config/theme_presets.php),
            // alongside that snapshot — hence provisionSystemPresets()
            // rather than provisionDefault() alone. Still the same
            // transaction and the same reasoning: a tenant missing its
            // starter palettes looks healthy until an admin opens the
            // theme screen and finds nothing to start from.
            $this->themePresetService->provisionSystemPresets($company);

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): Company
    {
        $company->update($data);

        return $company;
    }

    public function delete(Company $company): void
    {
        $company->delete(); // SoftDeletes — see this class's own flagged note above.
    }
}
