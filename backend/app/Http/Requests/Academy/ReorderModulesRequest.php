<?php

namespace App\Http\Requests\Academy;

use App\Models\Module;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-151 / ADR-031 §2.1 — the FULL ordered list of Section ids within one
 * cert tier.
 *
 * What this class does NOT do, deliberately: it does not check that the ids
 * belong to the cert tier, to one company, or to this actor's company. All
 * three live in ModuleOrderService, because two of them are only answerable
 * after the rows are loaded (a Super Admin is exempt from TenantScope, so
 * "which company is this?" is a property of the DATA here, not of the
 * request), and splitting them would leave the Form Request enforcing half
 * a rule.
 */
class ReorderModulesRequest extends FormRequest
{
    /**
     * "May this actor author Academy content at all" — the same ability
     * StoreModuleRequest uses. The per-row `update` check against each
     * Section's own company (BR-6) is re-made in the Service, which is the
     * only place that has the rows.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Module::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'module_ids' => ['required', 'array', 'min:1'],
            // `distinct` matters more than it looks: a duplicated id would
            // pass the count check in the Service by accident and then be
            // written twice, leaving the last position wins and a sibling
            // silently unnumbered.
            'module_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
