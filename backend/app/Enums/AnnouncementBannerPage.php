<?php

namespace App\Enums;

/**
 * TASK-080 (2026-08-03, human-confirmed via AskUserQuestion) — the pages an
 * announcement banner may be rendered on in the Agent Portal.
 *
 * Deliberately a page identity, NOT a pixel position. storefront_banners
 * uses `placement` (top/middle/bottom) because all three of its slots live
 * on the single product page; announcements instead span several pages and
 * occupy one well-defined slot on each, so the meaningful axis here is
 * "which screen", not "how far down".
 *
 * Each value maps to exactly one route in frontend/src/router/index.ts —
 * keep this in sync if those paths ever change.
 */
enum AnnouncementBannerPage: string
{
    /** Agent Portal "/" — the home hub. */
    case Home = 'home';

    /** Agent Portal "/products" — alongside the storefront banners. */
    case Products = 'products';

    /** Agent Portal "/announcements" — top of the full news list. */
    case Announcements = 'announcements';
}
