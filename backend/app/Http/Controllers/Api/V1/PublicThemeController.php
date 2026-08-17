<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThemeResource;
use App\Models\Company;
use App\Services\Theme\ThemeService;

/**
 * TASK-055 / ADR-018 — the PUBLIC, UNAUTHENTICATED theme endpoint
 * (GET /public/theme/{slug}), registered outside auth:sanctum and
 * throttled (see routes/api.php). Powers pre-login branding: the SPA
 * resolves a company by URL slug before anyone logs in and applies its
 * colors/font/logos.
 *
 * The company is resolved by slug WITHOUT any tenant/auth context
 * (Company::withoutGlobalScopes — there's no authenticated user to scope
 * by, and Company itself carries no TenantScope). The response uses
 * ThemeResource, which is PRESENTATIONAL ONLY — colors, font, logo URLs,
 * background, loading config, label overrides — never ids beyond the
 * company, counts, PDPA or any sensitive field (§5/BR-6, §6). A company
 * with no theme row still returns the neutral defaults, so the endpoint
 * can't be used to probe which companies have configured a theme.
 */
class PublicThemeController extends Controller
{
    public function showBySlug(string $slug, ThemeService $service): ThemeResource
    {
        $company = Company::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();

        /*
         * TASK-183 §3.5 — a closed tenant has no branded front door.
         *
         * Note what withoutGlobalScopes() above drops: SoftDeletingScope as
         * well as (the absent) TenantScope. So before this line, a
         * SOFT-DELETED company still served its full theme — logo, colours,
         * login-screen copy — to anyone who guessed the slug. Deactivation was
         * likewise never read here.
         *
         * 404 rather than a 403 or a neutral theme, matching the answer an
         * unknown slug already gets from firstOrFail() one line up: this
         * endpoint is reachable by anyone with a slug and no credentials at
         * all, so it must not become an oracle for "which companies exist but
         * are switched off" (§3.4 — do not leak whether the company merely
         * exists). The Agent Portal simply renders its neutral default theme,
         * which is what it already does for a company that has never
         * configured one, and the login attempt behind it is refused by
         * LoginGateService with the real reason.
         */
        abort_unless($company->isOperational(), 404);

        return new ThemeResource($service->forCompany($company));
    }
}
