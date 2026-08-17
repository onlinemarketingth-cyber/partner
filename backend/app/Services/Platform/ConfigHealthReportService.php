<?php

namespace App\Services\Platform;

use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\GamificationRule;
use App\Models\Module;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * TASK-041 (4.4) — BR-7 config health tracker. BR-7: "anything the
 * source blueprint marks 'to be confirmed' must be designed as
 * admin-editable config/seed data — never hardcoded." This report
 * surfaces which companies have actually configured their OWN
 * commission_rules / gamification_rules overrides vs. which are
 * silently relying on platform defaults (or, for commission_rules
 * specifically, have configured NOTHING at all yet — company-scoped,
 * no platform-default fallback exists for that table).
 *
 * $companyId is a judgment-call addition to the literal
 * `buildReport(User $actor)` signature in this task's spec: the spec's
 * own Controller instructions require "Company Admin (own company
 * only, force company_id filter) or Super Admin (all companies, or
 * ?company_id= filter)" — that filtering has to be threaded through
 * somehow, and passing it as an explicit second param (rather than
 * having the Service reach into request()->query() itself, which would
 * hide the dependency) keeps the Service unit-testable without a bound
 * HTTP request. Defaults to null (no filter) so the 1-arg call shape
 * from the spec still works unchanged for the "all companies" case.
 */
class ConfigHealthReportService
{
    public function buildReport(User $actor, ?int $companyId = null): Collection
    {
        $companiesQuery = Company::query()->orderBy('name');

        if (! $actor->isSuperAdmin()) {
            // Company Admin — hard-scoped to their own company (BR-6),
            // never trust a client-supplied company_id for this role.
            $companiesQuery->where('id', $actor->company_id);
        } elseif ($companyId !== null) {
            $companiesQuery->where('id', $companyId);
        }

        return $companiesQuery->get()->map(function (Company $company) {
            $commissionRulesCount = CommissionRule::query()->where('company_id', $company->id)->count();
            // GamificationRule is deliberately NOT TenantScope'd (see its
            // own docblock — null company_id = platform default). Filtering
            // to this company's id here only counts its OWN override rows,
            // never the platform-default (null) rows — which is exactly
            // what "has this company configured anything of its own" means.
            $gamificationOverridesCount = GamificationRule::query()->where('company_id', $company->id)->count();
            $academyModulesCount = Module::query()->where('company_id', $company->id)->count();
            $productsCount = Product::query()->where('company_id', $company->id)->count();
            // TASK-055 (ADR-018) — white-label: has this company configured
            // its OWN theme, or is it silently on the platform default brand
            // (BR-7)? A row existing = they've set something of their own.
            $hasTheme = CompanyThemeSetting::query()->where('company_id', $company->id)->exists();

            return [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'commission_rules_count' => $commissionRulesCount,
                'has_commission_rules' => $commissionRulesCount > 0,
                'gamification_overrides_count' => $gamificationOverridesCount,
                'has_gamification_overrides' => $gamificationOverridesCount > 0,
                'academy_modules_count' => $academyModulesCount,
                'products_count' => $productsCount,
                'theme_configured' => $hasTheme,
            ];
        });
    }
}
