<?php

namespace App\Http\Requests\Theme\Concerns;

use Illuminate\Validation\Rule;

/**
 * TASK-161 §5.2 — "a Super Admin works INSIDE one company's context".
 *
 * The same `effectiveCompanyId()` shape AcademyProgressSummaryRequest
 * established for exactly this question, shared by the three preset
 * requests that need it (list, save, apply) rather than pasted three
 * times: they must agree, and three copies is how they stop agreeing.
 *
 * WHY THE VALIDATION MATTERS MORE HERE THAN IT LOOKS
 * --------------------------------------------------
 * A Super Admin is exempt from TenantScope (CLAUDE.md §5 rule 4). An
 * unscoped preset list would return every tenant's saved colours in one
 * undifferentiated pile, and a stray or mistyped `company_id` would act
 * on the WRONG company silently — there is no scope left to catch it.
 * `exists:companies,id` plus requiredIf is therefore not politeness; it
 * is the only thing standing in the way (same reasoning as
 * ModuleOrderService's second check, TASK-151).
 *
 * DIFFERENCE FROM AcademyProgressSummaryRequest, deliberate: that request
 * answers a Company Admin who names someone else's company with a 403.
 * Here the human's §5.2 wording is "for a Company Admin it is their own
 * and any supplied value is ignored", so the key is STRIPPED before
 * validation instead. Stripping (rather than merely ignoring it in
 * effectiveCompanyId()) is what stops a Company Admin who sends a
 * nonexistent id from getting a confusing 422 about a field that has no
 * effect on their request.
 */
trait ResolvesPresetCompany
{
    /**
     * §5.2: a Company Admin's `company_id` is not a parameter, so it never
     * reaches the rules.
     */
    protected function prepareForValidation(): void
    {
        if ($this->user()?->isSuperAdmin()) {
            return;
        }

        // Both sources, deliberately. getInputSource() is the JSON bag on a
        // JSON request and the form bag otherwise (`$this->request` alone
        // silently misses a JSON body, which is every call this API gets);
        // the query bag is where it would arrive on the GET list route.
        $this->getInputSource()->remove('company_id');
        $this->query->remove('company_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function companyRules(): array
    {
        return [
            'company_id' => [
                // Required for a Super Admin, exactly as StoreModuleRequest
                // and AcademyProgressSummaryRequest require it: without a
                // named company the request means "every tenant at once",
                // which is not an answer anyone asked for and not one BR-6
                // wants computed by accident. The Admin screen already has
                // the company picker this leans on.
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                'integer',
                'exists:companies,id',
            ],
        ];
    }

    /**
     * The company this request may read/write. Never derived from the
     * client for anyone but a Super Admin, and for them only after
     * authorize()/rules() above have run.
     */
    public function effectiveCompanyId(): int
    {
        return $this->user()->isSuperAdmin()
            ? $this->integer('company_id')
            : (int) $this->user()->company_id;
    }
}
