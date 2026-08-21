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
 *  • uploadAsset() : same admin gate; stores a logo/background image.
 * The PUBLIC read-by-slug endpoint lives in PublicThemeController.
 */
class CompanyThemeController extends Controller
{
    public function me(Request $request, ThemeService $service): ThemeResource
    {
        return new ThemeResource($service->forCompany($request->user()->company));
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
