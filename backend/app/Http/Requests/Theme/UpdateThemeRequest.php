<?php

namespace App\Http\Requests\Theme;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-055 / ADR-018 — validate a company-theme write. Only Company Admin
 * (own company) or Super Admin may write (§5/BR-6 — the Controller forces
 * the target company_id server-side). Accepts ONLY presentational config
 * values; file paths are set exclusively via the asset-upload endpoint and
 * company_id is derived server-side, so neither is accepted here.
 */
class UpdateThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Ability::SettingsCompanyThemeUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'primary_hex' => $hex,
            'accent_hex' => $hex,
            'nav_bg_hex' => $hex,
            // TASK-161 §3.1 — nav bar solid/gradient. Same shape as
            // background_type/background_config, but validated per-stop:
            // a 'gradient' with a missing stop is a 422, NOT a silent
            // fall back to the solid colour (an admin who half-filled the
            // form must be told, not quietly given the old bar back).
            'nav_bg_type' => ['nullable', 'in:solid,gradient'],
            'nav_bg_config' => ['nullable', 'required_if:nav_bg_type,gradient', 'array'],
            'nav_bg_config.color1' => ['required_if:nav_bg_type,gradient', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'nav_bg_config.color2' => ['required_if:nav_bg_type,gradient', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // Optional: the client falls back to its own default angle.
            'nav_bg_config.angle' => ['nullable', 'integer', 'between:0,360'],
            'nav_text_hex' => $hex,
            // Bottom-nav "active tab" override; null = keep following the
            // generated brand-600 ramp (primary_hex) as before.
            'nav_active_hex' => $hex,
            'card_bg_hex' => $hex,
            'card_text_hex' => $hex,
            // Border: a hex colour OR the literal 'none' (borderless); null = default.
            'card_border_hex' => ['nullable', 'string', 'max:7', 'regex:/^(#[0-9A-Fa-f]{6}|none)$/'],
            'card_shadow' => ['nullable', 'in:none,sm,md,lg,xl'],
            'loading_bg_hex' => $hex,

            'background_type' => ['nullable', 'in:solid,gradient,image'],
            'background_config' => ['nullable', 'array'],

            'font_family' => ['nullable', 'string', 'max:100'],
            // Split per-script fonts (Thai vs Latin/English). Legacy
            // font_family above stays as a single-font fallback.
            'font_family_thai' => ['nullable', 'string', 'max:100'],
            'font_family_latin' => ['nullable', 'string', 'max:100'],
            // A sane, curated set of Google Font weights (BR-7 config, not
            // hardcoded logic). Rejects nonsense like 137 or 1000.
            'font_weights' => ['nullable', 'array'],
            'font_weights.*' => ['integer', 'in:100,200,300,400,500,600,700,800,900'],

            'loading_message' => ['nullable', 'string', 'max:255'],

            // Curated key => text label map. Keys are strings, values short
            // strings — NOT a full i18n surface (ADR-018 decision 3).
            'label_overrides' => ['nullable', 'array'],
            'label_overrides.*' => ['nullable', 'string', 'max:200'],

            // TASK-057 — key => Icon.vue icon-name map for the bottom-nav
            // icons (BR-7). Same key set as label_overrides, deliberately
            // NOT validated against a hardcoded icon-name whitelist here —
            // Icon.vue already falls back to a neutral default for any
            // unrecognized name (no crash/injection risk, it's just an SVG
            // path lookup), so keeping this a free-form short string avoids
            // having to keep a duplicate icon-name list in sync between the
            // backend and both frontends' Icon.vue components.
            'nav_icon_overrides' => ['nullable', 'array'],
            'nav_icon_overrides.*' => ['nullable', 'string', 'max:50', 'regex:/^[a-z_]+$/'],

            // ADR-020 (storefront row 4 "recommended" auto-fill cap) — BR-7,
            // must be admin-editable, never a hardcoded Service constant.
            // Bounded 1-20: 0 would silently blank the row, and there's no
            // legitimate merchandising reason to show more than a screen or
            // two of recommendations.
            'recommended_slot_count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
