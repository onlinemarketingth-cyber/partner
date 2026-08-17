<?php

namespace App\Support;

/**
 * TASK-068 / ADR-020 row 3 — the single source of truth for which
 * Icon.vue icon names may be picked for public-facing storefront content
 * (currently: product_categories.icon). Deliberately the SAME curated
 * subset as ThemeSettingsView.vue's `ICON_CHOICES` array (frontend-admin
 * icon-picker grid, TASK-057/ADR-018) rather than Icon.vue's full ~150-
 * icon PATHS set — kept in sync manually per TASK-069's own note ("same
 * list the frontend icon picker uses").
 *
 * Unlike company_theme_settings.nav_icon_overrides (an internal-admin-
 * only nav setting, deliberately left un-whitelisted — see
 * UpdateThemeRequest's own comment on why), a category icon is rendered
 * directly on the Agent Portal storefront, so ADR-020/TASK-068 explicitly
 * calls for rejecting unknown names server-side too, not just client-side.
 */
class CuratedIcons
{
    /**
     * @var list<string>
     */
    public const WHITELIST = [
        'home', 'dashboard', 'users', 'contact', 'user', 'user_plus',
        'brain', 'book', 'template', 'trophy', 'star', 'sparkles',
        'money', 'dollar', 'chart', 'bar_chart', 'pie_chart', 'receipt',
        'bell', 'calendar', 'shield', 'flag', 'box', 'layout',
    ];
}
