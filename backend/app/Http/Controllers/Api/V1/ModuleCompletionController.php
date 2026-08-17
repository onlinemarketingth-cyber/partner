<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreModuleCompletionOverrideRequest;
use App\Http\Requests\Academy\StoreModuleCompletionRequest;
use App\Http\Resources\ModuleCompletionResource;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\User;
use App\Services\Academy\ModuleCompletionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Append-only log — only index/store exist (no update/destroy route),
// so this deliberately does NOT use authorizeResource() (which expects
// all 7 REST abilities on the Policy); each action authorizes itself
// explicitly against ModuleCompletionPolicy's viewAny/view/create.
class ModuleCompletionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ModuleCompletion::class);

        $query = ModuleCompletion::query()->with('moduleLesson.module');

        // Agent sees only their own; Company Admin/Super Admin see all
        // in company (TenantScope already handles the company part).
        if ($request->user()->isAgent()) {
            $query->where('user_id', $request->user()->id);
        }

        return ModuleCompletionResource::collection($query->latest('completed_at')->paginate());
    }

    public function store(StoreModuleCompletionRequest $request, ModuleCompletionService $service): ModuleCompletionResource
    {
        $lesson = ModuleLesson::findOrFail($request->validated('module_lesson_id'));

        $completion = $service->complete($lesson, $request->user(), $request->validated('score'));

        return new ModuleCompletionResource($completion->load('moduleLesson.module'));
    }

    /**
     * POST /module-lessons/{moduleLesson}/completions/override
     *
     * ADR-028 §2.3 guard 2 — the escape hatch for the verified-progress
     * gate. A rule with no override becomes a support queue, and ADR-028
     * §5 asks for it to be discoverable BEFORE rollout, not after.
     *
     * Audit-logged in the Service (CLAUDE.md §6): a lesson completion
     * feeds the BR-1 Basic gate that unlocks selling rights, so this
     * touches certification.
     */
    public function override(
        StoreModuleCompletionOverrideRequest $request,
        ModuleLesson $moduleLesson,
        ModuleCompletionService $service,
    ): ModuleCompletionResource {
        // withoutGlobalScopes is safe and necessary here: the Form Request
        // has already constrained user_id to the LESSON's company (which
        // TenantScope in turn constrained to the actor's, unless the actor
        // is a Super Admin), so this narrows to an already-authorized row
        // rather than widening past a scope.
        $target = User::withoutGlobalScopes()->findOrFail($request->validated('user_id'));

        $completion = $service->overrideComplete($moduleLesson, $target, $request->user());

        return new ModuleCompletionResource($completion->load('moduleLesson.module'));
    }
}
