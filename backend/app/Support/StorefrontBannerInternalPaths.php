<?php

namespace App\Support;

// TASK-073 — single source of truth for the "internal" link_type
// whitelist on storefront_banners, shared by Store/UpdateStorefrontBannerRequest.
// Deliberately NOT free text (never trust the client, per CLAUDE.md
// §6): must be one of the Agent Portal's own authenticated route paths
// (frontend/src/router/index.ts). Keep in sync manually if routes
// change — there's no automated cross-repo check between this list and
// the Vue router.
class StorefrontBannerInternalPaths
{
    public const ALLOWED = [
        '/',
        '/clients',
        '/products',
        '/orders',
        '/referrals',
        '/pipeline',
        '/academy',
        '/commission',
        '/leaderboard',
        '/affiliate-links',
        '/profile',
        '/notifications',
        // TASK-075 — full announcements list + search page.
        '/announcements',
    ];
}
