<?php

namespace App\Services\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;

/**
 * 2026-09-12 — the company-level commission settings, read and written
 * through commission's own door.
 *
 * ── WHY THIS EXISTS, WHEN THE COLUMNS LIVE ON `companies` ──
 *
 * The admin commission screen needs two facts about the company: which base
 * its percentages apply to (`commission_basis`) and which plan it runs
 * (`commission_plan_type`). Both are columns on the company row, so the
 * screen first read them with GET /companies/{id}.
 *
 * That worked, and it worked by leaning on an exception. `routes/api.php`
 * describes the companies resource as "Super Admin only end to end", which is
 * true of index/store/update/destroy and NOT of show — CompanyPolicy::view has
 * always also allowed a user to read their OWN company. The whole screen
 * balanced on that one clause.
 *
 * The owner's point (2026-09-12): somebody tightening CompanyPolicy::view to
 * `isSuperAdmin()` alone — on the strength of that very comment — would break
 * nothing visible. No exception, no failing test near the change. A Company
 * Admin's screen would simply catch the 403 and render its defaults: 'ราคาขาย'
 * shown as the selected basis at a company that pays on PV. A confident, wrong
 * answer about how their agents are paid.
 *
 * So the dependency is removed rather than documented. This is the same shape
 * every other per-company commission setting already has
 * (CommissionSplitSettingService, CommissionBinarySettingService,
 * CommissionMatrixSettingService, …): forCompany() + a narrow write, its own
 * endpoint, its own Ability. The earlier read through the platform CRUD
 * resource was the odd one out.
 *
 * ── WHY THE WRITE IS NARROWER THAN THE READ ──
 *
 * Only `commission_basis` is writable here. `commission_plan_type` is read
 * and returned, because the screen has to SHOW which plan the company runs,
 * but changing it stays where it already lives (CompanyManagementView, via
 * the companies resource). Two doors onto one column is the thing this class
 * exists to stop; adding a second one while removing another would be a poor
 * trade.
 */
class CommissionSettingService
{
    /**
     * Always returns a value, never null — the same contract every sibling
     * service holds. A caller must not have to tell "not configured" from
     * "configured": every company HAS a basis (the column defaults to
     * 'price') and a plan, and making the screen guess which it is looking at
     * is how it ends up asserting one it was never told.
     *
     * A null company id is a Super Admin on "ทุกบริษัท", where the question
     * has no single answer. Price and null are returned as the honest
     * placeholder — the screen refuses to render step 2 in that state
     * anyway — rather than picking one tenant's settings to speak for all.
     *
     * @return array{commission_basis: CommissionBasis, commission_plan_type: CommissionPlanType|null}
     */
    public function forCompany(?int $companyId): array
    {
        $company = $companyId === null ? null : Company::find($companyId);

        return [
            'commission_basis' => $company?->commission_basis ?? CommissionBasis::Price,
            'commission_plan_type' => $company?->commission_plan_type,
        ];
    }

    /**
     * @return array{commission_basis: CommissionBasis, commission_plan_type: CommissionPlanType|null}
     */
    public function updateBasis(int $companyId, CommissionBasis $basis, ?User $actor = null): array
    {
        $company = Company::findOrFail($companyId);
        $before = $company->commission_basis ?? CommissionBasis::Price;

        if ($before !== $basis) {
            $company->update(['commission_basis' => $basis]);

            /*
             * Section 6 — "record every action that affects money [or]
             * commission." This one decides what EVERY percentage in the
             * company is a percentage of, which makes it the widest-reaching
             * single field in the commission configuration: one write changes
             * the amount of every future payout on every product.
             *
             * Audited for the same reason CommissionSplitSettingService
             * audits its flag and the other per-company settings services do
             * not — those do not move money, and these two do. The rows
             * already written are untouched (BR-4); what changes is every
             * calculation from this moment on, and this is the only record of
             * when "this moment" was.
             */
            if ($actor) {
                AuditLog::create([
                    'company_id' => $companyId,
                    'actor_user_id' => $actor->id,
                    'action' => 'commission_basis.updated',
                    'auditable_type' => Company::class,
                    'auditable_id' => $company->id,
                    'old_values' => ['commission_basis' => $before->value],
                    'new_values' => ['commission_basis' => $basis->value],
                    'ip_address' => request()?->ip(),
                ]);
            }
        }

        return $this->forCompany($companyId);
    }
}
