<?php

namespace App\Http\Resources\Concerns;

use App\Http\Resources\ThemeResource;
use App\Models\Company;
use App\Services\Theme\ThemeService;

/**
 * TASK-159 §3 — the `theme` key shared by the three PUBLIC, token-resolved
 * payloads a customer outside the platform can land on:
 * PublicProductShareResource (/p/{token}), PublicOrderResource
 * (/pay/{token}) and PublicAffiliateLinkContextResource (/l/{token}).
 *
 * ONE serialiser, ONE place to change: it delegates to the existing
 * ThemeResource, so these payloads are byte-identical in shape to
 * GET /public/theme/{slug}'s `data` and ag-ui can hand any of them to the
 * same theme store.
 *
 * BR-6, CLAUDE.md §5 rule 5 — the Company passed in here MUST be the one
 * hanging off the token-resolved model (`$this->company`), never anything
 * read from the request. A client-supplied company id on an unauthenticated
 * endpoint is a cross-tenant read, which is exactly the mistake TASK-159 §2
 * exists to stop repeating (the hostname/`?company=` slug guess).
 *
 * ORDER OF CHECKS: a Resource is only ever constructed after its controller
 * has resolved and validated the token (revoked / expired / product
 * deactivated → abort 404 in PublicProductShareController::resolveUsableLink()),
 * so an unusable token cannot reach this code and no theme is emitted for
 * one. That is structural, not a convention — see the test of the same name.
 */
trait ResolvesPublicTheme
{
    /**
     * Null only when the record has no company at all (not reachable via
     * the current schema — company_id is non-nullable on all three models
     * — but preferable to a hard failure on a customer-facing page). A
     * company that simply has no company_theme_settings row still gets a
     * full payload of ThemeService::defaults() values, never null.
     */
    protected function publicTheme(?Company $company): ?ThemeResource
    {
        if (! $company) {
            return null;
        }

        return new ThemeResource(app(ThemeService::class)->forCompanyPublic($company));
    }
}
