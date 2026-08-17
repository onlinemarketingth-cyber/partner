<?php

namespace App\Policies;

use App\Models\PipelineTemplate;
use App\Models\User;

/**
 * TASK-136 — authorization for the READ-ONLY pipeline-template list.
 *
 * A pipeline template is BR-7 config (which stages a product's customers
 * walk through), so it is gated like every other piece of company config:
 * Company Admin within their own company, Super Admin across companies
 * (§5 rule 4). TenantScope already narrows the query; this Policy decides
 * who may ask at all.
 *
 * DELIBERATELY NARROWER THAN BrandPolicy/ClientCategoryPolicy, which
 * return true from viewAny() because an Agent genuinely needs those lists
 * to filter their own work. An Agent has no use for the template
 * CATALOGUE: what an Agent needs is the journey of the specific referral
 * in front of them, and that is exposed per-row on ReferralResource
 * (`pipeline.stages` / `pipeline.next_stage`) without handing out the
 * company's whole config surface. If a future Agent-Portal screen really
 * needs the catalogue, widen viewAny() here on purpose rather than by
 * accident.
 *
 * There are no create/update/delete methods on purpose either. Authoring
 * is TASK-134b and cannot ship before the §3.5 invariants
 * (PipelineTemplateResolver::assertValidStageSequence) are wired into a
 * Form Request — a template saved without `complete_payment` would be a
 * silent BR-4 commission outage. No route exists for those verbs, so no
 * Policy method should imply one does.
 */
class PipelineTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    /**
     * No route uses this today (the resource is index-only), but
     * authorizeResource() maps `show` to it and a future single-template
     * read must not accidentally be world-readable — so the cross-tenant
     * check is written now, matching every other Policy in this app.
     */
    public function view(User $user, PipelineTemplate $pipelineTemplate): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $pipelineTemplate->company_id);
    }
}
