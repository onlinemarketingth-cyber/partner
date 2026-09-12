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
 * ── BOTH FIELDS, AND WHY THE PLAN TYPE JOINED THEM ──
 *
 * `commission_basis` moved here first. `commission_plan_type` followed the
 * same day, for a reason that showed up as a UI complaint rather than an
 * architectural one: the owner pressed "เปลี่ยนแผน" on step 2 of the
 * commission screen and was thrown onto /companies to finish
 * ("ทำให้ UI สับสน"). That link existed only because the write lived behind
 * CompanyPolicy::update and the screen could not perform it. Moving the write
 * removed the reason for the link, and the two fields are the same sentence
 * anyway — "how does this company pay": the plan says who gets paid, the basis
 * says what a percentage is a percentage of.
 *
 * `PUT /companies/{id}` no longer accepts the plan type. One column, one write
 * door — CommissionBasisVisibilityTest is the tripwire on the closed one.
 * Company CREATION still carries it, which is a different operation: a tenant
 * is provisioned with a plan, it is not an edit of an existing value.
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
     * Writes whichever of the two was supplied, audits whichever actually
     * changed, and returns the settled state.
     *
     * Nullable parameters mean "not supplied", never "clear it" — neither
     * column is nullable and neither has an unset state. The Form Request
     * refuses a call that supplies neither, so a no-op here is a no-op the
     * caller asked for (saving the value already in place), not an empty
     * request that silently succeeded.
     *
     * @return array{commission_basis: CommissionBasis, commission_plan_type: CommissionPlanType|null}
     */
    public function update(int $companyId, ?CommissionBasis $basis = null, ?CommissionPlanType $planType = null, ?User $actor = null): array
    {
        $company = Company::findOrFail($companyId);

        if ($basis !== null) {
            $this->applyChange(
                $company,
                'commission_basis',
                ($company->commission_basis ?? CommissionBasis::Price)->value,
                $basis->value,
                'commission_basis.updated',
                $actor,
            );
        }

        if ($planType !== null) {
            $this->applyChange(
                $company,
                'commission_plan_type',
                $company->commission_plan_type?->value,
                $planType->value,
                'commission_plan_type.updated',
                $actor,
            );
        }

        return $this->forCompany($companyId);
    }

    /**
     * Section 6 — "record every action that affects money [or] commission."
     *
     * These two are audited where the other per-company settings services are
     * not, and the line is the same one CommissionSplitSettingService draws:
     * those do not move money, and these do. Between them they decide who is
     * paid and what they are paid a percentage of, so one write changes the
     * amount of every future payout on every product.
     *
     * Rows already in the ledger are untouched (BR-4), which is precisely why
     * the MOMENT of the switch has to be recorded somewhere — it is the only
     * thing that explains why two rows for the same product, a week apart, do
     * not agree.
     *
     * Nothing is written and nothing is logged when the value did not move: an
     * audit trail padded with writes that changed nothing is one nobody reads.
     */
    private function applyChange(Company $company, string $column, ?string $before, string $after, string $action, ?User $actor): void
    {
        if ($before === $after) {
            return;
        }

        $company->update([$column => $after]);

        if (! $actor) {
            return;
        }

        AuditLog::create([
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'old_values' => [$column => $before],
            'new_values' => [$column => $after],
            'ip_address' => request()?->ip(),
        ]);
    }
}
