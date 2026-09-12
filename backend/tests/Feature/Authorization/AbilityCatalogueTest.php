<?php

namespace Tests\Feature\Authorization;

use App\Enums\Ability;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\ExamAttempt;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Services\Authorization\PermissionResolver;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-185 §6 — the guards on the catalogue and the resolver themselves.
 *
 * RoleGateCharacterizationTest records what the APPLICATION does. This file
 * holds shut the three properties ADR-032 asks of the new machinery:
 *   1. fail closed — an ability no role holds is denied, as an assertion;
 *   2. Super Admin is ENUMERATED, not blanket-true, and the three abilities
 *      the audit found it excluded from really are excluded — proved both in
 *      the resolver AND against the real, unconverted Policies;
 *   3. the catalogue cannot grow a case that is silently denied by omission.
 */
class AbilityCatalogueTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // 1. Fail closed (ADR-032 §2.5)
    // -----------------------------------------------------------------

    public function test_an_ability_no_role_holds_is_denied_for_every_role(): void
    {
        $resolver = new PermissionResolver;

        // Ability::UserViewSuperAdminRecord appears in no row of
        // PermissionResolver::ROLE_ABILITIES. Nothing denies it explicitly —
        // it is denied because it is absent, which is the fail-closed rule.
        foreach (UserRole::cases() as $role) {
            $this->assertFalse(
                $resolver->roleGrants($role, Ability::UserViewSuperAdminRecord),
                "{$role->value} must not hold an ability that appears in no rule",
            );
        }
    }

    public function test_a_null_user_is_denied_every_ability(): void
    {
        $resolver = new PermissionResolver;

        foreach (Ability::cases() as $ability) {
            $this->assertFalse($resolver->may(null, $ability), "null user must be denied {$ability->value}");
        }
    }

    public function test_fail_closed_reaches_the_gate_too(): void
    {
        $company = Company::factory()->create();

        foreach ([
            User::factory()->agent()->create(['company_id' => $company->id]),
            User::factory()->companyAdmin()->create(['company_id' => $company->id]),
            User::factory()->superAdmin()->create(),
        ] as $user) {
            $this->assertFalse(
                $user->can(Ability::UserViewSuperAdminRecord->value),
                "{$user->role->value} must be denied through the Gate as well as the resolver",
            );
        }
    }

    // -----------------------------------------------------------------
    // 2. Super Admin is enumerated, not assumed
    // -----------------------------------------------------------------

    /**
     * The whole reason PermissionResolver writes Super Admin out by hand. A
     * `Gate::before` returning true for Super Admin would flip all three of
     * these to allowed, in one line, with no Policy appearing in the diff.
     */
    public function test_super_admin_does_not_hold_the_three_abilities_the_audit_found_it_excluded_from(): void
    {
        $resolver = new PermissionResolver;

        foreach ([
            Ability::AcademyExamAttemptCreate,   // ExamAttemptPolicy.php:24 — agent-only
            Ability::RewardRedemptionCreate,     // RewardRedemptionPolicy.php:34 — agent-only
            Ability::UserViewSuperAdminRecord,   // UserPolicy.php:29-33 — nobody, ever
        ] as $ability) {
            $this->assertFalse(
                $resolver->roleGrants(UserRole::SuperAdmin, $ability),
                "Super Admin must not hold {$ability->value}",
            );
        }
    }

    /**
     * The same three, asserted against the REAL Policies — which TASK-185 does
     * not touch. This is what would actually break if someone added a blanket,
     * because it exercises Laravel's own dispatch rather than the resolver's
     * table.
     */
    public function test_the_three_exclusions_hold_against_the_real_unconverted_policies(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $otherSuperAdmin = User::factory()->superAdmin()->create();
        $companyAdmin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // UserPolicy::view — a Super Admin target is refused to everyone,
        // including another Super Admin.
        $this->assertFalse($superAdmin->can('view', $otherSuperAdmin));
        $this->assertFalse($companyAdmin->can('view', $superAdmin));
        // ...and the same refusal is inherited by update/delete/restore, which
        // all delegate to view().
        $this->assertFalse($superAdmin->can('update', $otherSuperAdmin));
        $this->assertFalse($superAdmin->can('delete', $otherSuperAdmin));
        // Control: a non-Super-Admin target IS viewable, so the assertions
        // above are about the target's role and not about a broken Policy.
        $this->assertTrue($superAdmin->can('view', $agent));

        // ExamAttemptPolicy::create / RewardRedemptionPolicy::create — agent only.
        $this->assertFalse($superAdmin->can('create', ExamAttempt::class));
        $this->assertFalse($companyAdmin->can('create', ExamAttempt::class));
        $this->assertTrue($agent->can('create', ExamAttempt::class));

        $this->assertFalse($superAdmin->can('create', RewardRedemption::class));
        $this->assertFalse($companyAdmin->can('create', RewardRedemption::class));
        $this->assertTrue($agent->can('create', RewardRedemption::class));
    }

    /** No `Gate::before` blanket exists — asserted, not assumed (ADR-032/TASK-185 §3). */
    public function test_no_gate_before_callback_is_registered(): void
    {
        $gate = app(GateContract::class);

        $property = (new \ReflectionObject($gate))->getProperty('beforeCallbacks');
        $property->setAccessible(true);

        $this->assertSame(
            [],
            $property->getValue($gate),
            'A Gate::before callback would silently grant the abilities Super Admin is excluded from (ADR-032 §2.2 / TASK-185 §3).',
        );
    }

    /**
     * The positive half of the enumeration: Super Admin holds the 29
     * abilities derived from the 17+12 sites plus Ability::VoucherRedeem
     * (ADR-033 §2.1 — the first case NOT derived from a pre-existing site),
     * plus Ability::SettingsMailUpdate (TASK-190 §3.2 — the second such
     * case), plus Ability::CommissionRateCapUpdate (TASK-196 §2.2 — the
     * third), and Company Admin holds those minus the cross-company
     * platform report, minus the platform-wide mail settings, minus
     * the platform-wide commission-rate cap (none of the three has an
     * "own company" scope for a Company Admin to hold — see each case's
     * own docblock), minus the payment gateway and voucher redemption,
     * and — since the 2026-09-11 owner decision — minus the six
     * commission-rate *Update abilities.
     */
    public function test_super_admin_holds_every_phase_one_ability_and_company_admin_holds_all_but_the_platform_report(): void
    {
        $superAdmin = PermissionResolver::abilitiesFor(UserRole::SuperAdmin);
        $companyAdmin = PermissionResolver::abilitiesFor(UserRole::CompanyAdmin);

        /*
         * 35/31 since 2026-08-28 (632e1fd), which added
         * settings.commission_withdrawal.view and .update — both held by
         * BOTH roles, because withdrawal settings are per company and a
         * Company Admin runs their own company's payouts.
         *
         * The numbers are the point: they are a tripwire, not a fact. Adding
         * an ability has to be a deliberate edit somebody justifies here,
         * which is exactly what did not happen last time.
         */
        /*
         * 2026-09-10 — Company Admin dropped to 30: Ability::VoucherRedeem
         * left this row and became grant-only (human: "ที่ได้สิทธิ์ในการตัด
         * ได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงานคนละหน้าที่กัน"). Redeeming a
         * customer's paid entitlement is a front-desk job; holding it by
         * virtue of being an admin was a permission nobody chose to give and
         * nobody could take away.
         *
         * Super Admin keeps it — the platform owner is the fallback when a
         * company's own arrangement fails, and taking it away would leave a
         * counter with a customer and nobody able to help.
         */
        /*
         * 2026-09-11 — Company Admin dropped to 24, and this is the
         * deliberate edit this assertion exists to force somebody to
         * justify. THE DECISION: commission rate configuration becomes
         * writable by Super Admin only, because a commission rate is money
         * and the owner decided one person owns those numbers. Six
         * abilities left the Company Admin row:
         * SettingsCommissionBinaryUpdate, SettingsCommissionMatrixUpdate,
         * SettingsCommissionGenerationUpdate, SettingsAgentRankUpdate,
         * SettingsAffiliateAttributionUpdate,
         * SettingsCommissionSplitUpdate.
         *
         * THE COST, accepted knowingly: a Company Admin can no longer set
         * their own commission rate — including on a shared catalog product
         * they sell — and must ask the platform owner for every change.
         *
         * Super Admin's count is UNCHANGED at 35: nothing was granted, a
         * capability was withdrawn from one tier. Every matching
         * Settings...View ability stays with Company Admin, so reads are
         * untouched; if this count ever falls below 24 by a *View leaving,
         * that is a different decision and needs its own note.
         */
        /*
         * 2026-09-11 (second edit of the day) — 36/25. ONE ability was ADDED
         * and both tiers hold it: Ability::CommissionReadinessView, the
         * per-page "is commission set up well enough to pay anybody" check
         * behind GET /commission-readiness.
         *
         * THE JUSTIFICATION THIS ASSERTION EXISTS TO FORCE. The audience is
         * identical to Ability::ReportConfigHealthView's, so reusing that case
         * would have kept both counts where they were. It was rejected: this
         * file's rule is one case per distinct QUESTION, and "may I read the
         * cross-company BR-7 config report" is not "may I be told my own
         * company is currently paying nobody". Coupling them would mean
         * revoking the report silently switches the warning off on every page,
         * with no line in any diff saying so.
         *
         * BOTH tiers, note — on the same day six commission *Update abilities
         * were taken OFF the Company Admin row. That pairing is the decision,
         * not an inconsistency: the owner narrowed who may CHANGE a rate, not
         * who may be told the rates are broken. The endpoint's own `can_fix`
         * field carries the difference, so a Company Admin is shown the state
         * and told to contact the platform owner rather than handed a button
         * that 403s.
         */
        $this->assertCount(36, $superAdmin);
        $this->assertCount(25, $companyAdmin);

        // Held by both, so it adds no divergence to the filtered comparison at
        // the foot of this test — asserted here so "both" is a statement
        // somebody had to write, not a thing the count happens to allow.
        $this->assertContains(Ability::CommissionReadinessView, $superAdmin);
        $this->assertContains(Ability::CommissionReadinessView, $companyAdmin);

        $this->assertContains(Ability::ReportPlatformView, $superAdmin);
        $this->assertNotContains(Ability::ReportPlatformView, $companyAdmin);
        $this->assertContains(Ability::SettingsMailUpdate, $superAdmin);
        $this->assertNotContains(Ability::SettingsMailUpdate, $companyAdmin);
        $this->assertContains(Ability::CommissionRateCapUpdate, $superAdmin);
        /*
         * ADR-027 — names the bank account a company's customer revenue lands
         * in. Super Admin only, and unlike its two neighbours above NOT
         * because the setting is platform-wide: this one is entirely per
         * company. It is withheld from Company Admin because a Company Admin
         * who could edit it could redirect their own company's income, and the
         * change would look like an ordinary settings edit on every screen.
         */
        $this->assertContains(Ability::SettingsPaymentGatewayUpdate, $superAdmin);
        $this->assertNotContains(Ability::SettingsPaymentGatewayUpdate, $companyAdmin);
        $this->assertNotContains(Ability::CommissionRateCapUpdate, $companyAdmin);

        /*
         * 2026-09-11 — and the six commission-rate writes, the newest
         * Super-Admin-only group. Asserted as a paired contrast: each
         * *Update is gone from Company Admin while its *View sibling is
         * still there, which is precisely the shape of the owner's decision
         * (writes narrow, reads stay open) and the thing a careless "tidy
         * up the commission abilities" change would break.
         *
         * SettingsCommissionSplitUpdate and
         * SettingsAffiliateAttributionUpdate have no *View sibling in the
         * catalogue — reading those two is not gated on an Ability at all —
         * so they are asserted alone.
         */
        foreach ([
            [Ability::SettingsCommissionBinaryUpdate, Ability::SettingsCommissionBinaryView],
            [Ability::SettingsCommissionMatrixUpdate, Ability::SettingsCommissionMatrixView],
            [Ability::SettingsCommissionGenerationUpdate, Ability::SettingsCommissionGenerationView],
            [Ability::SettingsAgentRankUpdate, Ability::SettingsAgentRankView],
        ] as [$write, $read]) {
            $this->assertContains($write, $superAdmin);
            $this->assertNotContains($write, $companyAdmin, "{$write->value} is Super Admin only since 2026-09-11");
            $this->assertContains($read, $companyAdmin, "{$read->value} must stay — the decision narrowed writes, not reads");
        }
        $this->assertContains(Ability::SettingsAffiliateAttributionUpdate, $superAdmin);
        $this->assertNotContains(Ability::SettingsAffiliateAttributionUpdate, $companyAdmin);
        $this->assertContains(Ability::SettingsCommissionSplitUpdate, $superAdmin);
        $this->assertNotContains(Ability::SettingsCommissionSplitUpdate, $companyAdmin);

        // Company Admin's list is Super Admin's minus exactly those TEN
        // cases — no other divergence exists today. Enumerated rather than
        // derived, so a new Super-Admin-only grant cannot slip into the
        // platform unnoticed: adding one here is a deliberate edit somebody
        // has to justify, which is the whole purpose of this assertion. The
        // six on the second half of this list are the 2026-09-11 commission
        // rate decision; see the count note above for why and what it cost.
        $this->assertEqualsCanonicalizing(
            array_values(array_filter(
                $superAdmin,
                fn (Ability $a) => $a !== Ability::ReportPlatformView
                    && $a !== Ability::SettingsMailUpdate
                    && $a !== Ability::CommissionRateCapUpdate
                    && $a !== Ability::SettingsPaymentGatewayUpdate
                    && $a !== Ability::VoucherRedeem
                    && $a !== Ability::SettingsCommissionBinaryUpdate
                    && $a !== Ability::SettingsCommissionMatrixUpdate
                    && $a !== Ability::SettingsCommissionGenerationUpdate
                    && $a !== Ability::SettingsAgentRankUpdate
                    && $a !== Ability::SettingsAffiliateAttributionUpdate
                    && $a !== Ability::SettingsCommissionSplitUpdate,
            )),
            $companyAdmin,
        );
    }

    /**
     * 2026-09-10 — the fourth role, and the shortest row in the table.
     *
     * Front-desk staff exist to redeem vouchers at a branch. The whole value
     * of the role is what it does NOT hold: not sales, not commission, not a
     * customer's PDPA record. Asserted as an exact list, so a second ability
     * cannot be added to it without this line changing.
     */
    public function test_voucher_staff_holds_exactly_one_ability(): void
    {
        $this->assertSame(
            [Ability::VoucherRedeem],
            PermissionResolver::abilitiesFor(UserRole::VoucherStaff),
        );
    }

    /** An Agent holds none of the 29 admin-gated abilities. */
    public function test_agent_holds_no_admin_gated_ability(): void
    {
        $agentAbilities = PermissionResolver::abilitiesFor(UserRole::Agent);

        $this->assertSame(
            [Ability::AcademyExamAttemptCreate, Ability::RewardRedemptionCreate],
            $agentAbilities,
        );

        foreach (PermissionResolver::abilitiesFor(UserRole::SuperAdmin) as $adminAbility) {
            $this->assertNotContains($adminAbility, $agentAbilities);
        }
    }

    // -----------------------------------------------------------------
    // 3. The catalogue cannot grow a silently-denied case
    // -----------------------------------------------------------------

    public function test_every_ability_is_either_granted_to_a_role_or_listed_as_granted_to_none(): void
    {
        $granted = [];
        foreach (UserRole::cases() as $role) {
            $granted = array_merge($granted, PermissionResolver::abilitiesFor($role));
        }

        foreach (Ability::cases() as $ability) {
            $this->assertTrue(
                in_array($ability, $granted, true) || in_array($ability, PermissionResolver::GRANTED_TO_NO_ROLE, true),
                "Ability {$ability->value} is in no role's list and is not declared in GRANTED_TO_NO_ROLE. ".
                'Fail-closed means it is denied — say so on purpose rather than by omission.',
            );
        }
    }

    public function test_every_ability_value_follows_the_area_action_naming_convention(): void
    {
        foreach (Ability::cases() as $ability) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/',
                $ability->value,
                'ADR-032 §2.2 naming: area.action, lower_snake segments.',
            );
        }
    }

    // -----------------------------------------------------------------
    // 4. Gate wiring (wiring only — no call site uses it yet)
    // -----------------------------------------------------------------

    public function test_every_ability_is_defined_as_a_gate(): void
    {
        $gate = app(GateContract::class);

        foreach (Ability::cases() as $ability) {
            $this->assertTrue($gate->has($ability->value), "Gate not defined for {$ability->value}");
        }
    }

    public function test_the_gate_answers_the_same_as_the_resolver_for_every_role_and_ability(): void
    {
        $company = Company::factory()->create();
        $resolver = new PermissionResolver;

        foreach ([
            User::factory()->agent()->create(['company_id' => $company->id]),
            User::factory()->companyAdmin()->create(['company_id' => $company->id]),
            User::factory()->superAdmin()->create(),
        ] as $user) {
            foreach (Ability::cases() as $ability) {
                $this->assertSame(
                    $resolver->may($user, $ability),
                    $user->can($ability->value),
                    "Gate and resolver disagree for {$user->role->value} on {$ability->value}",
                );
            }
        }
    }
}
