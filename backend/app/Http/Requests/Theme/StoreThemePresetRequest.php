<?php

namespace App\Http\Requests\Theme;

use App\Http\Requests\Theme\Concerns\ResolvesPresetCompany;
use App\Models\ThemePreset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-161 §3.2 — "save the company's CURRENT colours under a name".
 *
 * A NAME IS THE ONLY THING THIS ACCEPTS. The colours are read server-side
 * from `company_theme_settings` by ThemePresetService::snapshot(); no
 * colour payload is accepted from the client, because a client-supplied
 * blob is a way to write values that never passed UpdateThemeRequest's
 * field validation and then have `apply` paste them into the theme row.
 * Any extra keys sent are simply not in validated() and never reach the
 * Service.
 *
 * For a COMPANY ADMIN `company_id` is still not accepted — it is derived
 * from the actor (§5/BR-6), the same self-scope-forcing pattern as
 * CompanyThemeController, and any value they send is stripped by
 * ResolvesPresetCompany before validation.
 *
 * TASK-161 §5.2 changed this for a SUPER ADMIN only: they are exempt from
 * TenantScope, so the company can no longer be inferred and must be named
 * AND validated to exist. It used to be read straight off the request in
 * the controller (`Company::findOrFail($request->integer('company_id'))`),
 * which turned a missing id into a 404 about nothing and put a
 * tenant-selecting input outside the Form Request layer §6 requires it to
 * live in.
 */
class StoreThemePresetRequest extends FormRequest
{
    use ResolvesPresetCompany;

    public function authorize(): bool
    {
        return $this->user()->can('create', ThemePreset::class);
    }

    /**
     * TASK-217 — `is_shared` is a SUPER-ADMIN-ONLY parameter, stripped for
     * everyone else before validation.
     *
     * Stripped rather than rejected, matching how ResolvesPresetCompany
     * already treats `company_id` for a Company Admin: a field that has no
     * meaning for this actor should not produce a 422 about a control they
     * were never shown. What must never happen is a Company Admin creating
     * a palette that lands on every other tenant's screen, and that is what
     * this removal guarantees — validated() cannot contain a key that was
     * deleted from the input bag.
     *
     * Both bags, same reason as the trait's own note: JSON body here, query
     * string defensively.
     */
    protected function prepareForValidation(): void
    {
        // The trait's copy handles company_id; calling it keeps that
        // behaviour instead of shadowing it.
        $this->prepareCompanyForValidation();

        if ($this->user()?->isSuperAdmin()) {
            return;
        }

        $this->getInputSource()->remove('is_shared');
        $this->query->remove('is_shared');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->companyRules() + [
            'name' => ['required', 'string', 'max:100'],
            // Optional: omitting it means "save it for this company only",
            // which is the behaviour every caller had before this task.
            'is_shared' => ['sometimes', 'boolean'],
        ];
    }
}
