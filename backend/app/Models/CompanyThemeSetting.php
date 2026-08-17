<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-055 / ADR-018 — a single company's white-label theme (colors,
 * background, Google Font, logos, loading splash, label overrides). One
 * row per company (unique company_id). Tenant-scoped (§5/BR-6) via the
 * shared TenantScope so a Company Admin/Agent only ever reaches their own
 * company's row; every value is admin config (BR-7).
 */
class CompanyThemeSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'primary_hex',
        'accent_hex',
        'nav_bg_hex',
        // TASK-161 §3.1 — nav bar solid/gradient, mirroring the
        // background_type/background_config pair below. null nav_bg_type
        // behaves as solid, so existing rows are unaffected.
        'nav_bg_type',
        'nav_bg_config',
        'nav_text_hex',
        'nav_active_hex',
        'card_bg_hex',
        'card_text_hex',
        'card_border_hex',
        'card_shadow',
        'background_type',
        'background_config',
        'background_image_path',
        'font_family',
        'font_family_thai',
        'font_family_latin',
        'font_weights',
        'logo_nav_path',
        'logo_login_path',
        'favicon_path',
        'logo_loading_path',
        'loading_bg_hex',
        'loading_message',
        'label_overrides',
        // TASK-057 — key => Icon.vue icon-name map for the bottom-nav icons
        // (BR-7 admin config). Same key set as label_overrides.
        'nav_icon_overrides',
        // TASK-068 / ADR-020 — how many product slots the Agent Portal's
        // "recommended for you" row renders (pinned-then-auto-fill). BR-7:
        // never a hardcoded constant inside ProductRecommendationService,
        // always this column (default 8 at the DB level).
        'recommended_slot_count',
    ];

    protected function casts(): array
    {
        return [
            'background_config' => 'array',
            'nav_bg_config' => 'array',
            'font_weights' => 'array',
            'label_overrides' => 'array',
            'nav_icon_overrides' => 'array',
            'recommended_slot_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
