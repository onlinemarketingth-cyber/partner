<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\User;
use App\Support\Media\StoredFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Section 7: business logic lives here, not in the Controller. For a
// plain catalog entity like Brand there isn't much beyond "force the
// correct company_id" — but that rule is security-critical (BR-6), so
// it still gets its own Service rather than living in the Controller.
//
// TASK-205 (human, 2026-08-19: "ผมต้องการเฉพาะแบรนด์มีการ upload รูปแบรนด์
// ได้") added the logo file handling below. It is a column-for-column copy
// of StorefrontBannerService's image handling — same disk, same
// uuid-filename convention, same delete-old-file-on-replace — because two
// image uploads on the same screen behaving differently would be its own
// bug.
class BrandService
{
    /**
     * The 'public' disk, like StorefrontBannerService::DISK. A brand mark is
     * marketing artwork every agent in the company is meant to see, not a
     * per-row access-checked document: Section 5 rule 6's tenant-scoped-path
     * requirement is about client documents (PDPA), which this is not.
     */
    private const DISK = 'public';

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, ?UploadedFile $logo = null): Brand
    {
        unset($data['logo'], $data['remove_logo']);

        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            // Defense-in-depth: the Form Request already requires company_id
            // for Super Admin, but the Service must never silently fall
            // through to a null tenant (BR-6) if that validation is ever
            // loosened.
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        $this->applyLogo($data, $logo);

        return Brand::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Brand $brand, array $data, ?UploadedFile $logo = null): Brand
    {
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        unset($data['logo'], $data['remove_logo']);

        if ($logo) {
            // Replacing: drop the old file first so a brand that has had its
            // logo swapped five times does not leave five orphans on disk.
            $this->deleteFile($brand->logo_path);
            $this->applyLogo($data, $logo);
        } elseif ($removeLogo) {
            $this->deleteFile($brand->logo_path);
            $data['logo_path'] = null;
        }

        // No file and no remove flag => logo_path is left untouched, so an
        // ordinary rename can never silently wipe the mark.

        $brand->update($data);

        return $brand;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyLogo(array &$data, ?UploadedFile $logo): void
    {
        if (! $logo) {
            return;
        }

        // UUID filename, never the client's own: the original name is
        // attacker-controlled (path traversal, collisions across companies).
        $data['logo_path'] = $logo->storeAs(
            'brand-logos',
            StoredFileName::random($logo),
            self::DISK,
        );
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
