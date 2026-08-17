<?php

namespace App\Services\Academy;

use App\Models\Module;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// ADR-009 — Module is now a "Section": pure grouping/ordering
// container, no content-item handling here anymore (see
// ModuleLessonService for that — video upload, mimes, embed, etc.).
class ModuleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Module
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        return Module::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Module $module, array $data): Module
    {
        $module->update($data);

        return $module;
    }
}
