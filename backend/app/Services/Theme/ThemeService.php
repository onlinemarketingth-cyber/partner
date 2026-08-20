<?php

namespace App\Services\Theme;

use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Support\Media\StoredFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * TASK-055 / ADR-018 — business logic for per-company white-label themes.
 *
 * BR-7: this Service (and the seeder) are the ONLY places brand values are
 * held in code, and even here code holds only the neutral DEFAULT fallback
 * (defaults()) — every real value lives in the company_theme_settings row.
 * §5/BR-6: company_id is ALWAYS derived from the passed Company, never
 * trusted from request input, so a Company Admin can never write another
 * company's theme.
 */
class ThemeService
{
    private const DISK = 'public';

    /**
     * Uploadable asset slots → the column each maps to. Also the allowed
     * set validated in storeAsset() and the Controller/Request.
     *
     * @var array<string, string>
     */
    private const SLOT_COLUMN = [
        'nav' => 'logo_nav_path',
        'login' => 'logo_login_path',
        'favicon' => 'favicon_path',
        'loading' => 'logo_loading_path',
        'background' => 'background_image_path',
    ];

    /**
     * The company's theme row, or a fresh UNSAVED model (empty/null
     * fields) so callers always get an object to hand to ThemeResource.
     */
    public function forCompany(Company $company): CompanyThemeSetting
    {
        return $this->hydrate($company, $company->themeSetting()->first());
    }

    /**
     * TASK-159 §3 — the same row, for a PUBLIC (unauthenticated) request
     * that resolved its company from an opaque TOKEN (product share /
     * order pay page / affiliate link), never from request input.
     *
     * Deliberately reads the row WITHOUT the global TenantScope, for the
     * same reason every public token resolver in this codebase does
     * (PublicProductShareController::resolveUsableLink(),
     * PublicPaymentController::resolve(), PublicThemeController): there is
     * no authenticated user for TenantScope to resolve against. This is
     * NOT a BR-6 widening — the company is already pinned server-side by
     * the token before we get here, and CompanyThemeSetting is keyed
     * one-row-per-company, so the where(company_id) below is the whole
     * filter. Without it there is also a live bug: an agent of company A
     * who happens to be logged in and opens company B's share link would
     * hit TenantScope, match no row, and see platform defaults instead of
     * B's brand.
     */
    public function forCompanyPublic(Company $company): CompanyThemeSetting
    {
        return $this->hydrate($company, CompanyThemeSetting::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first());
    }

    /**
     * Falls back to a fresh UNSAVED model (empty/null fields) so callers
     * always get an object to hand to ThemeResource, which turns the nulls
     * into defaults() — never a null payload.
     */
    private function hydrate(Company $company, ?CompanyThemeSetting $theme): CompanyThemeSetting
    {
        $theme ??= new CompanyThemeSetting(['company_id' => $company->id]);

        // Attach the owning company so ThemeResource can expose name/slug
        // without an extra query (and correctly for a fresh unsaved row).
        $theme->setRelation('company', $company);

        return $theme;
    }

    /**
     * Create or update the company's single theme row. company_id is keyed
     * from the passed Company, never from $data (defence in depth — the
     * Request already refuses company_id/path fields).
     *
     * @param  array<string, mixed>  $data
     */
    public function upsert(Company $company, array $data): CompanyThemeSetting
    {
        // Never let request input smuggle a foreign company_id or a raw
        // file path in — those are set from the keying company / only via
        // storeAsset() respectively.
        unset(
            $data['company_id'],
            $data['background_image_path'],
            $data['logo_nav_path'],
            $data['logo_login_path'],
            $data['favicon_path'],
            $data['logo_loading_path'],
        );

        return CompanyThemeSetting::updateOrCreate(
            ['company_id' => $company->id],
            $data,
        );
    }

    /**
     * Store an uploaded logo/background image on the public disk under
     * themes/{company_id}/{slot}-{uuid}.ext, replacing any previous file
     * for that slot, and save the new path to the matching column.
     */
    public function storeAsset(Company $company, string $slot, UploadedFile $file): CompanyThemeSetting
    {
        if (! array_key_exists($slot, self::SLOT_COLUMN)) {
            throw new InvalidArgumentException("Invalid theme asset slot: {$slot}");
        }

        $column = self::SLOT_COLUMN[$slot];
        $theme = CompanyThemeSetting::firstOrCreate(['company_id' => $company->id]);

        // Replace the previous file for this slot, if any.
        if ($theme->{$column}) {
            Storage::disk(self::DISK)->delete($theme->{$column});
        }

        $path = $file->storeAs(
            "themes/{$company->id}",
            StoredFileName::random($file, $slot.'-'),
            self::DISK,
        );

        $theme->update([$column => $path]);

        return $theme->fresh();
    }

    /**
     * The neutral built-in fallback used by ThemeResource when a field is
     * null. BR-7: this is the ONLY place code holds brand values, and they
     * are the platform's neutral defaults (not a specific tenant's brand).
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            'primary_hex' => '#2F4183',
            'accent_hex' => '#8C704A',
            'font_family' => 'Kanit',
            'background_type' => 'solid',
        ];
    }
}
