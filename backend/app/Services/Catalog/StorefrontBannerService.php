<?php

namespace App\Services\Catalog;

use App\Models\StorefrontBanner;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TASK-068 / ADR-020 row 2. image_path lives on the 'public' disk — same
 * convention as AnnouncementService (a banner is meant to be shown
 * directly to every agent in the company, not access-checked per-row).
 */
class StorefrontBannerService
{
    private const DISK = 'public';

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, ?UploadedFile $image): StorefrontBanner
    {
        unset($data['image']);

        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            // Defense-in-depth — see ProductCategoryService::create()'s
            // identical comment; the Form Request already enforces this.
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        $this->applyImage($data, $image);

        return StorefrontBanner::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StorefrontBanner $banner, array $data, ?UploadedFile $image): StorefrontBanner
    {
        unset($data['image']);

        if ($image) {
            $this->deleteFile($banner->image_path);
            $this->applyImage($data, $image);
        }

        $banner->update($data);

        return $banner;
    }

    public function delete(StorefrontBanner $banner): void
    {
        $this->deleteFile($banner->image_path);
        $banner->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyImage(array &$data, ?UploadedFile $image): void
    {
        if (! $image) {
            return;
        }

        $data['image_path'] = $image->storeAs(
            'storefront-banners',
            Str::uuid()->toString().'.'.$image->getClientOriginalExtension(),
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
