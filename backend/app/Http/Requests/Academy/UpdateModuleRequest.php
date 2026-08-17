<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-009 — Module is now a "Section": all content-item fields
// (previously here) moved to UpdateModuleLessonRequest.
class UpdateModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('module'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\Module $module */
        $module = $this->route('module');

        return [
            'cert_tier_id' => ['sometimes', 'required', 'integer', 'exists:cert_tiers,id'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $module->company_id)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
            /*
             * ADR-031 §2.2 — the sequential-unlock switch. Per Section, and
             * a deliberate act: default false means turning it on is
             * something an admin DID, never something that happened to a
             * course on deploy.
             */
            'enforce_sequential' => ['sometimes', 'boolean'],
            /*
             * ADR-031 §2.3 — `nullable` is meaningful: NULL means "available
             * immediately", which is a different statement from 0 and is the
             * value every existing Section carries.
             *
             * 0..3650 are SANITY bounds, not business values (BR-7's target
             * is the number itself, which is admin-chosen config with no
             * platform default to seed). 3650 = ten years; anything past
             * that is a typo, not a drip schedule, and an unbounded integer
             * would overflow the unsignedSmallInteger column into a driver
             * error instead of a 422.
             */
            'drip_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
