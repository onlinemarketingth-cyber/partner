<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// Section 7: business logic lives here, not in the Controller. For a
// plain catalog entity like Brand there isn't much beyond "force the
// correct company_id" — but that rule is security-critical (BR-6), so
// it still gets its own Service rather than living in the Controller.
class BrandService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Brand
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            // Defense-in-depth: the Form Request already requires company_id
            // for Super Admin, but the Service must never silently fall
            // through to a null tenant (BR-6) if that validation is ever
            // loosened.
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        return Brand::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Brand $brand, array $data): Brand
    {
        $brand->update($data);

        return $brand;
    }
}
