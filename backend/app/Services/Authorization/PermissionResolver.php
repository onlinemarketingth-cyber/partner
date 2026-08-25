<?php

namespace App\Services\Authorization;

use App\Enums\Ability;
use App\Enums\UserRole;
use App\Models\User;

/**
 * ADR-032 §2.4 / TASK-185 §3 — the one place that answers "may this user do
 * this ability?".
 *
 * PHASE 1 SCOPE. The answer here is a PURE FUNCTION OF THE BASE ROLE, because
 * that is all the current code consults — every one of the 17 `abort_unless`
 * Controller sites and the 12 raw-role Form Requests asks nothing but
 * `isAgent()` / `isCompanyAdmin()` / `isSuperAdmin()`. ADR-032 §2.4's second
 * input (per-company feature toggles) arrives in Phase 2; there is a named seam
 * for it in may() and NOTHING ELSE. A resolver that read a table which does not
 * exist yet would be a resolver nobody could test (TASK-185 §3).
 *
 * NO `Gate::before` BLANKET FOR SUPER ADMIN — DELIBERATE AND LOAD-BEARING.
 * Super Admin is enumerated below like every other role. The audit found three
 * abilities Super Admin is genuinely excluded from
 * (Ability::AcademyExamAttemptCreate, Ability::RewardRedemptionCreate,
 * Ability::UserViewSuperAdminRecord); a `Gate::before` returning true for
 * Super Admin would silently grant all three, and nobody would review the
 * change because it would not appear in any diff of a Policy. See
 * AbilityCatalogueTest for the assertions that hold this shut.
 *
 * FAIL CLOSED (ADR-032 §2.5). An ability that appears in no row of the table
 * below is DENIED for everyone. That is a test, not a convention — see
 * AbilityCatalogueTest::test_an_ability_no_role_holds_is_denied_for_every_role.
 *
 * CLAUDE.md §7 — this is a Service. Controllers/Requests/Policies will call it
 * through Laravel's Gate (wired in AppServiceProvider), never construct it.
 */
class PermissionResolver
{
    /**
     * THE ROLE → ABILITY TABLE. This is the artifact TASK-185 exists to
     * produce; keep it a table, never logic scattered across methods.
     *
     * Written out per role in full rather than "company_admin plus X", so that
     * reading one row tells you everything that role holds. The cost is a
     * duplicated list; the benefit is that widening a role is a visible line in
     * a diff.
     *
     * @var array<string, list<Ability>>
     */
    private const ROLE_ABILITIES = [
        /*
         * AGENT.
         *
         * Holds NOTHING from the 17+12 sites — every one of them is an
         * admin-only gate, and the characterization suite records a 403 for an
         * agent at all 29 endpoints. The two entries here come from Policies
         * that are agent-ONLY (both admin tiers excluded), catalogued so this
         * table has to state that Super Admin does not hold them.
         */
        UserRole::Agent->value => [
            Ability::AcademyExamAttemptCreate,
            Ability::RewardRedemptionCreate,
        ],

        /*
         * COMPANY ADMIN.
         *
         * Every ability derived from the 17+12 sites EXCEPT
         * Ability::ReportPlatformView — PlatformReportController.php:22 is the
         * one site of the seventeen gated on `isSuperAdmin()` alone, and the
         * cross-company report is precisely what a tenant admin must not see
         * (CLAUDE.md §5 rule 4 / BR-6).
         */
        UserRole::CompanyAdmin->value => [
            Ability::ReportComplianceView,
            Ability::ReportConfigHealthView,
            Ability::SalesTeamOverviewView,
            Ability::SalesAgentDashboardMetricsView,
            Ability::CommissionAgentSummaryView,
            Ability::CommissionAgentSummaryExport,
            Ability::AgentTargetView,
            Ability::AgentTargetUpdate,
            Ability::SettingsTeamVisibilityView,
            Ability::SettingsAcademyCompletionView,
            Ability::SettingsCommissionBinaryView,
            Ability::SettingsCommissionMatrixView,
            Ability::SettingsCommissionGenerationView,
            Ability::SettingsAgentRankView,
            Ability::SettingsVideoProcessingView,
            Ability::SettingsCompanyThemeUploadAsset,
            Ability::SettingsCompanyThemeUpdate,
            Ability::SettingsTeamVisibilityUpdate,
            Ability::SettingsAcademyCompletionUpdate,
            Ability::SettingsCommissionBinaryUpdate,
            Ability::SettingsCommissionMatrixUpdate,
            Ability::SettingsCommissionGenerationUpdate,
            Ability::SettingsAgentRankUpdate,
            Ability::SettingsCommissionSplitUpdate,
            Ability::SettingsVideoProcessingUpdate,
            Ability::SettingsAffiliateAttributionUpdate,
            Ability::SettingsAnnouncementUpdate,
            Ability::AcademyCertificationGrant,
            // ADR-033 (TASK-189) §2.1 — interim voucher-redemption grant.
            // Unlike every case above, this one was never a bare role check
            // to "convert" — it is wired straight onto the resolver from the
            // moment it exists. Not Agent.
            Ability::VoucherRedeem,
        ],

        /*
         * SUPER ADMIN — ENUMERATED, NOT ASSUMED (TASK-185 §3).
         *
         * The 28 above plus Ability::ReportPlatformView, plus
         * Ability::VoucherRedeem (ADR-033 §2.1), plus
         * Ability::SettingsMailUpdate (TASK-190 §3.2 — Super Admin ONLY,
         * not granted to Company Admin above; see that case's own
         * docblock for why platform-wide SMTP config has no "own company"
         * scope to grant a Company Admin into), plus
         * Ability::CommissionRateCapUpdate (TASK-196 §2.2 — same
         * Super-Admin-only reasoning as SettingsMailUpdate). Note what is
         * NOT here: AcademyExamAttemptCreate, RewardRedemptionCreate and
         * UserViewSuperAdminRecord. Those three absences are the entire reason
         * this list is written out instead of `return true`.
         */
        UserRole::SuperAdmin->value => [
            Ability::ReportPlatformView,
            Ability::SettingsMailUpdate,
            Ability::CommissionRateCapUpdate,
            // ADR-027 — names the account a company's revenue lands in.
            // Not granted to Company Admin: see the case's own docblock.
            Ability::SettingsPaymentGatewayUpdate,
            Ability::ReportComplianceView,
            Ability::ReportConfigHealthView,
            Ability::SalesTeamOverviewView,
            Ability::SalesAgentDashboardMetricsView,
            Ability::CommissionAgentSummaryView,
            Ability::CommissionAgentSummaryExport,
            Ability::AgentTargetView,
            Ability::AgentTargetUpdate,
            Ability::SettingsTeamVisibilityView,
            Ability::SettingsAcademyCompletionView,
            Ability::SettingsCommissionBinaryView,
            Ability::SettingsCommissionMatrixView,
            Ability::SettingsCommissionGenerationView,
            Ability::SettingsAgentRankView,
            Ability::SettingsVideoProcessingView,
            Ability::SettingsCompanyThemeUploadAsset,
            Ability::SettingsCompanyThemeUpdate,
            Ability::SettingsTeamVisibilityUpdate,
            Ability::SettingsAcademyCompletionUpdate,
            Ability::SettingsCommissionBinaryUpdate,
            Ability::SettingsCommissionMatrixUpdate,
            Ability::SettingsCommissionGenerationUpdate,
            Ability::SettingsAgentRankUpdate,
            Ability::SettingsCommissionSplitUpdate,
            Ability::SettingsVideoProcessingUpdate,
            Ability::SettingsAffiliateAttributionUpdate,
            Ability::SettingsAnnouncementUpdate,
            Ability::AcademyCertificationGrant,
            Ability::VoucherRedeem,
        ],
    ];

    /**
     * Abilities the code grants to NO role today.
     *
     * Not the same thing as "forgotten": AbilityCatalogueTest asserts that
     * every Ability case is either in ROLE_ABILITIES or listed here, so a new
     * case cannot end up denied by accident and be mistaken for a decision.
     * Listing one here is a decision; omitting one is caught by a red test.
     *
     * @var list<Ability>
     */
    public const GRANTED_TO_NO_ROLE = [
        // UserPolicy.php:29-33 refuses a Super Admin target to everyone,
        // including another Super Admin. TODO: CONFIRM (behaviour recorded,
        // not endorsed).
        Ability::UserViewSuperAdminRecord,
    ];

    /**
     * The one public question (ADR-032 §2.4).
     *
     * A null user is denied — the callers this will eventually replace all sit
     * behind auth:sanctum, so a null here means something is wired wrong, and
     * "denied" is the safe answer to that (§2.5).
     */
    public function may(?User $user, Ability $ability): bool
    {
        if ($user === null) {
            return false;
        }

        /*
         * PHASE 2 SEAM (ADR-032 §2.4), intentionally NOT implemented:
         *
         *     if (! $this->companyHasFeatureEnabled($user->company_id, $ability)) {
         *         return false;
         *     }
         *
         * The company-level answer is a CEILING, never a floor — a feature
         * switched off for a company must not be re-openable for one user by
         * any grant. It goes here, ahead of the role question, when the table
         * behind it exists (Phase 2). Nothing reads it today and nothing
         * pretends to.
         */

        return $this->roleGrants($user->role, $ability);
    }

    /** Does this base role hold this ability? Unlisted ⇒ false (fail closed). */
    public function roleGrants(?UserRole $role, Ability $ability): bool
    {
        if ($role === null) {
            return false;
        }

        return in_array($ability, self::abilitiesFor($role), true);
    }

    /**
     * The full grant list for a role — used by the tests that enumerate Super
     * Admin, and by Phase 3's admin UI to seed a new company role's defaults.
     *
     * @return list<Ability>
     */
    public static function abilitiesFor(UserRole $role): array
    {
        return self::ROLE_ABILITIES[$role->value] ?? [];
    }
}
