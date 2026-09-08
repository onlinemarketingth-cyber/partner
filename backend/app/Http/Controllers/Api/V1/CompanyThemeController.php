<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Theme\UpdateThemeRequest;
use App\Http\Resources\ThemeResource;
use App\Models\Company;
use App\Services\Theme\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-055 / ADR-018 — per-company white-label theme, authenticated side.
 *  • me()          : ANY authenticated user reads their OWN company's theme
 *                    (agents need it to render the branded portal too).
 *  • update()      : Company Admin (own company) / Super Admin (any) writes
 *                    presentational theme fields. Role gate is in
 *                    UpdateThemeRequest::authorize(); target company_id is
 *                    forced server-side here (§5/BR-6 — a Company Admin can
 *                    never write another company's theme).
 *  • show()        : the theme of the company an admin is EDITING — a Super
 *                    Admin names it with company_id, a Company Admin is forced
 *                    to their own. See its docblock for why this exists.
 *  • uploadAsset() : same admin gate; stores a logo/background image.
 * The PUBLIC read-by-slug endpoint lives in PublicThemeController.
 */
class CompanyThemeController extends Controller
{
    public function me(Request $request, ThemeService $service): ThemeResource
    {
        return new ThemeResource($service->forCompany($request->user()->company));
    }

    /**
     * 2026-09-08 (human: "ผมเลือกใช้ชุดสี Live to 100 Club แล้วทำไมยังไม่
     * เปลี่ยน").
     *
     * The theme editor had no endpoint for "the theme of the company I am
     * editing". `me()` answers for the CALLER's company, and a Super Admin
     * belongs to none — so the admin console fell back to the PUBLIC
     * read-by-slug endpoint, GET /public/theme/{slug}, for its own editing
     * surface. Three consequences, all of them things somebody eventually
     * reports as "it didn't change":
     *
     *   1. IT IS A PUBLIC GET, so anything between the browser and PHP is
     *      entitled to cache it — the CDN in front of the API, a proxy, the
     *      browser itself. Applying a preset writes the row and then re-reads
     *      through the one URL on this screen that a cache is allowed to
     *      answer from memory, which shows the admin the colours from BEFORE
     *      their own click. `Cache-Control: no-store` below is why this
     *      endpoint cannot do that.
     *
     *   2. IT 404s FOR A DEACTIVATED COMPANY. PublicThemeController refuses a
     *      non-operational tenant on purpose (§3.4 — a closed tenant has no
     *      branded front door), which also meant a Super Admin could not open
     *      the theme of a company they had switched off. Reactivating it is
     *      one of the reasons they would want to.
     *
     *   3. IT SHARES THE PUBLIC THROTTLE (60/min) with real pre-login
     *      traffic, so admin editing and a company's login page compete for
     *      the same bucket.
     *
     * Deliberately NOT gated to admins only: it returns exactly what
     * `/me/theme` already returns to any authenticated user for their own
     * company, and a non-Super-Admin's `company_id` is ignored (resolveCompany
     * forces their own). So it widens nobody's reach — it only lets the ONE
     * role that has no company of its own name the company it is editing.
     */
    public function show(Request $request, ThemeService $service): JsonResponse
    {
        return (new ThemeResource($service->forCompany($this->resolveCompany($request))))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    // Force 200: this is an idempotent upsert (PUT), so even when
    // updateOrCreate creates the row on first save, the response is "here
    // is the current theme" (200) — not a REST 201-Created (Laravel
    // returns 201 by default for a Resource wrapping a wasRecentlyCreated
    // model, which is misleading for a settings upsert).
    public function update(UpdateThemeRequest $request, ThemeService $service): JsonResponse
    {
        $company = $this->resolveCompany($request);

        return (new ThemeResource($service->upsert($company, $request->validated())))
            ->response()
            ->setStatusCode(200);
    }

    public function uploadAsset(Request $request, ThemeService $service): JsonResponse
    {
        abort_unless($request->user()->can(Ability::SettingsCompanyThemeUploadAsset), 403);

        $request->validate([
            'slot' => ['required', 'in:nav,login,favicon,loading,background'],
            /*
             * SECURITY AUDIT 2026-08-21 (V7) — SVG REMOVED (human ruling
             * D4: it is not needed).
             *
             * The old comment read "SVG allowed for logos (crisp at any
             * size)", which is true and beside the point. An SVG is not an
             * image file, it is an executable document — <script>,
             * <foreignObject> and event-handler attributes all run — and
             * these assets are written to the PUBLIC disk and served
             * straight off it at a URL on the API's own origin. A Company
             * Admin uploading one was stored XSS with a permanent URL.
             *
             * `mimes:svg` was never a defence against that: it confirms the
             * file really is an SVG, which is precisely the problem.
             *
             * This codebase already knew. StoreBrandRequest carries the
             * rule verbatim — "SVG is deliberately NOT accepted... these
             * files are served straight off the public disk" — and this one
             * endpoint simply did not follow it. Two upload paths, one
             * policy: that is what makes this a fix rather than a taste.
             *
             * 5 MB cap unchanged, mirroring the announcement/avatar uploads.
             */
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $company = $this->resolveCompany($request);

        return (new ThemeResource(
            $service->storeAsset($company, $request->input('slot'), $request->file('file')),
        ))->response()->setStatusCode(200);
    }

    /**
     * Resolve the company being written: a Super Admin may target any
     * company by passing company_id; a Company Admin is FORCED to their own
     * company_id (any company_id they send is ignored). This is the same
     * self-scope-forcing pattern as AgentTargetController::upsert().
     */
    private function resolveCompany(Request $request): Company
    {
        if ($request->user()->isSuperAdmin()) {
            return Company::findOrFail($request->integer('company_id'));
        }

        return $request->user()->company;
    }
}
