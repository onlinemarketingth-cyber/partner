<?php

namespace App\Http\Resources;

use App\Models\CompanyThemeSetting;
use App\Services\Catalog\ProductRecommendationService;
use App\Services\Theme\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-055 / ADR-018 — PRESENTATIONAL theme payload, safe for the PUBLIC
 * (unauthenticated) /public/theme/{slug} endpoint. §5/BR-6 + §6: exposes
 * ONLY branding fields — company name/slug, colors, background, font,
 * logo URLs, loading config, label overrides. NO ids beyond the company,
 * no counts, no PDPA, nothing sensitive. Null fields fall back to the
 * neutral code defaults (BR-7) via ThemeService::defaults().
 *
 * @mixin CompanyThemeSetting
 */
class ThemeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $defaults = app(ThemeService::class)->defaults();

        return [
            'company' => [
                'name' => $this->company?->name,
                'slug' => $this->company?->slug,
            ],
            // TASK-063 — branded Login-page link for this company, built
            // server-side (same "read the shared FRONTEND_URL config"
            // pattern as ProductShareLinkResource's public share links,
            // §7) so frontend-admin never needs to know/hardcode the
            // Agent Portal's origin. Distinct from logos.login_url below
            // (that's the login-PAGE LOGO IMAGE, this is the login PAGE
            // LINK). Appending ?company=<slug> is what
            // frontend/src/stores/theme.ts's resolveSlug() reads to skip
            // the chicken-and-egg "no company known pre-login" gap
            // (human-reported 2026-07-31) and paint this company's theme
            // on /login before any auth call succeeds.
            'login_link' => $this->company?->slug
                ? rtrim((string) Config::get('services.agent_portal.frontend_url'), '/').'/login?company='.$this->company->slug
                : null,
            'primary_hex' => $this->primary_hex ?? $defaults['primary_hex'],
            'accent_hex' => $this->accent_hex ?? $defaults['accent_hex'],
            // App-chrome (top bar + bottom nav) colours; null → client uses
            // its neutral white-bar / slate-text default.
            'nav_bg_hex' => $this->nav_bg_hex,
            // TASK-161 §3.1 — nav bar gradient. `nav_bg_hex` above stays
            // the solid value; a null/absent type means solid, so a client
            // that ignores these two fields keeps its old behaviour.
            'nav_bg_type' => $this->nav_bg_type,
            'nav_bg_config' => $this->nav_bg_config,
            'nav_text_hex' => $this->nav_text_hex,
            // Bottom-nav "active tab" colour override; null → client keeps
            // following the generated brand-600 ramp (primary_hex).
            'nav_active_hex' => $this->nav_active_hex,
            // Content-card / surface colour; null → client uses white.
            'card_bg_hex' => $this->card_bg_hex,
            'card_text_hex' => $this->card_text_hex,
            'card_border_hex' => $this->card_border_hex,
            'card_shadow' => $this->card_shadow,
            'background' => [
                'type' => $this->background_type ?? $defaults['background_type'],
                'config' => $this->background_config,
                'image_url' => $this->fileUrl($this->background_image_path),
            ],
            'font_family' => $this->font_family ?? $defaults['font_family'],
            // Split per-script fonts. Thai falls back to the legacy single
            // font (then the code default); Latin falls back to the legacy
            // single font (then a neutral sans, resolved client-side).
            'font_family_thai' => $this->font_family_thai ?? $this->font_family ?? $defaults['font_family'],
            'font_family_latin' => $this->font_family_latin ?? $this->font_family,
            'font_weights' => $this->font_weights,
            'logos' => [
                'nav_url' => $this->fileUrl($this->logo_nav_path),
                'login_url' => $this->fileUrl($this->logo_login_path),
                'favicon_url' => $this->fileUrl($this->favicon_path),
                'loading_url' => $this->fileUrl($this->logo_loading_path),
            ],
            'loading' => [
                'bg_hex' => $this->loading_bg_hex,
                'message' => $this->loading_message,
            ],
            'label_overrides' => $this->label_overrides ?? (object) [],
            // TASK-057 — key => icon-name map for the bottom-nav; null/
            // missing keys fall back to the client's built-in default icon.
            'nav_icon_overrides' => $this->nav_icon_overrides ?? (object) [],
            // ADR-020 — storefront row 4 "recommended for you" slot cap.
            // Not sensitive/PDPA, same "plain config int" shape as every
            // other field here, so it rides the existing public+authenticated
            // theme payload rather than needing a separate endpoint.
            // ProductRecommendationService::DEFAULT_SLOT_COUNT is the
            // fallback when a company has no theme-settings row at all.
            'recommended_slot_count' => $this->recommended_slot_count
                ?? ProductRecommendationService::DEFAULT_SLOT_COUNT,
        ];
    }

    private function fileUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
