<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Theme\ApplyThemePresetRequest;
use App\Http\Requests\Theme\IndexThemePresetRequest;
use App\Http\Requests\Theme\StoreThemePresetRequest;
use App\Http\Requests\Theme\UpdateThemePresetRequest;
use App\Http\Resources\ThemePresetResource;
use App\Http\Resources\ThemeResource;
use App\Models\Company;
use App\Models\ThemePreset;
use App\Services\Theme\ThemePresetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * TASK-161 §3.2 — company colour presets (Company Admin / Super Admin;
 * an Agent gets 403 on EVERY route here via ThemePresetPolicy).
 *
 * Thin by construction (§7): validation lives in the Form Requests,
 * authorization in ThemePresetPolicy (`authorizeResource` + an explicit
 * check on apply, which is not a resource verb), and all business logic —
 * what a preset contains, and the transactional write — in
 * ThemePresetService.
 *
 * Isolation is layered (§5/BR-6): TenantScope on the model turns another
 * company's id into a 404 at route-model-binding time, and the Policy
 * turns a Super-Admin-visible record reaching the wrong admin into a 403.
 */
class ThemePresetController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ThemePreset::class, 'theme_preset');
    }

    /**
     * §5.2 — always scoped to ONE company. The explicit where() is what
     * scopes a SUPER ADMIN, who TenantScope does not constrain; for a
     * Company Admin it restates what the scope already guarantees.
     */
    public function index(IndexThemePresetRequest $request): AnonymousResourceCollection
    {
        return ThemePresetResource::collection(
            ThemePreset::query()
                ->where('company_id', $request->effectiveCompanyId())
                ->orderByDesc('id')
                ->get()
        );
    }

    /**
     * Save the company's CURRENT colours under a name. Deliberately takes
     * no colour payload — see StoreThemePresetRequest.
     */
    public function store(StoreThemePresetRequest $request, ThemePresetService $service): ThemePresetResource
    {
        return new ThemePresetResource($service->snapshot(
            // Already validated to exist (§5.2) — findOrFail is the
            // belt-and-braces half, not the check itself.
            Company::findOrFail($request->effectiveCompanyId()),
            $request->validated()['name'],
            $request->user(),
        ));
    }

    /**
     * Copy the preset back into the company's theme row (one transaction —
     * ThemePresetService::apply). Responds with the resulting THEME, so the
     * Admin screen repaints from the server's truth rather than guessing
     * what it just wrote.
     *
     * §5.2: the company the CALLER named is passed alongside the preset so
     * the Service can refuse a mismatch — applying company A's preset while
     * acting on company B is rejected, not silently redirected.
     */
    public function apply(ApplyThemePresetRequest $request, ThemePreset $themePreset, ThemePresetService $service): JsonResponse
    {
        // TASK-164 §1 — 'apply', not 'update': a system preset is read-only
        // but still applicable. See ThemePresetPolicy::apply().
        $this->authorize('apply', $themePreset);

        return (new ThemeResource($service->apply($themePreset, $request->effectiveCompanyId())))
            ->response()
            ->setStatusCode(200);
    }

    public function update(UpdateThemePresetRequest $request, ThemePreset $themePreset, ThemePresetService $service): ThemePresetResource
    {
        return new ThemePresetResource(
            $service->rename($themePreset, $request->validated()['name'])
        );
    }

    /**
     * TASK-164 §1 — the delete goes through the Service so the read-only
     * rule is re-checked there and not only by `authorizeResource`'s
     * `can:delete` middleware. `$themePreset->delete()` inline is precisely
     * the shape that leaves a Policy as the single point of failure.
     */
    public function destroy(ThemePreset $themePreset, ThemePresetService $service): Response
    {
        $service->delete($themePreset);

        return response()->noContent();
    }
}
