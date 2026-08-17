<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\UpdateModuleLessonProgressRequest;
use App\Http\Resources\ModuleLessonProgressResource;
use App\Http\Resources\MyModuleLessonProgressResource;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonProgress;
use App\Services\Academy\ModuleLessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * TASK-146 / ADR-028 §2.3, §4.
 *
 * Three endpoints with deliberately asymmetric audiences:
 *
 *   PUT  /module-lessons/{lesson}/progress — the LEARNER reports raw
 *        positions. Answers `{"completed": bool}` and nothing else
 *        (TASK-165 §3.4).
 *   GET  /module-lessons/{lesson}/progress — the ADMIN reads what was
 *        recorded, for support. Everything: last, max, totals.
 *   GET  /me/module-lessons/{lesson}/progress — the LEARNER reads their
 *        OWN bookmark. Two fields, nothing else (ADR-028 §4.1).
 *
 * No dedicated Policy, same convention as ModuleLessonController: a
 * lesson's authorization is always a question about its parent Section.
 */
class ModuleLessonProgressController extends Controller
{
    /**
     * TASK-165 §3.4 — ONE BOOLEAN, and nothing else.
     *
     * This used to answer 204 No Content, for a reason that still holds:
     * "do not tell a blocked learner how far they got" (ADR-028 §4, human
     * decision). Echoing the freshly-computed max_position_seconds /
     * max_page back would hand them exactly the number that decision
     * withholds, one arithmetic step from the threshold.
     *
     * What changed is that the progress ping can now RECORD A COMPLETION
     * on its own (§3.2), and the row on the learner's screen has to be
     * able to flip without polling. So the response carries `completed`
     * and remains a single boolean — asserted as an exact key set in
     * LessonProgressCompletionTest, so a future added field fails a test
     * rather than shipping quietly.
     *
     * **This does not reopen ADR-028 §4.** What §4 withholds is the
     * MEASUREMENT: how far they got, the threshold, their quiz score.
     * Whether they are now complete is not withheld — GET
     * /module-completions has always told the learner exactly that, and
     * the lesson list already renders it.
     */
    public function update(
        UpdateModuleLessonProgressRequest $request,
        ModuleLesson $moduleLesson,
        ModuleLessonProgressService $service,
    ): JsonResponse {
        $result = $service->record($moduleLesson, $request->user(), $request->validated());

        return response()->json(['completed' => $result['completed']]);
    }

    /**
     * ADR-028 §4 — the Admin readout. "Admin needs to *see* the recorded
     * progress even though the learner does not."
     *
     * Authorized on ModulePolicy::update (Company Admin own company /
     * Super Admin), NOT ::view — an Agent can view a module in order to
     * learn from it, and must not be able to read this. Cross-tenant is
     * already 404 at route-model binding (TenantScope, §5 rule 5).
     */
    public function index(ModuleLesson $moduleLesson): AnonymousResourceCollection
    {
        $this->authorize('update', $moduleLesson->module);

        $progress = ModuleLessonProgress::query()
            ->with('user')
            ->where('module_lesson_id', $moduleLesson->id)
            ->orderByDesc('updated_at')
            ->paginate();

        return ModuleLessonProgressResource::collection($progress);
    }

    /**
     * GET /me/module-lessons/{lesson}/progress — ADR-028 §4.1, the
     * LEARNER-SCOPED read.
     *
     * ag-ui found that resume only survived within a browser session,
     * because the only progress read was the Admin one above. ag-lead's
     * ruling: "a bookmark is not the withheld number" — add a
     * learner-scoped GET returning last_position_seconds / last_page only.
     *
     * Two structural properties, both deliberate:
     *
     * 1. **There is no `{user}` in this route and no filter parameter.**
     *    The row is looked up by the AUTHENTICATED user's id, resolved
     *    server-side. Reading somebody else's progress is not "forbidden"
     *    here, it is unrepresentable — there is no input that could ask
     *    for it. Same construction as every other /me/* route.
     *
     * 2. **A missing row answers 200 with two nulls, not 404.** A learner
     *    who has never opened the lesson has a bookmark of "nowhere",
     *    which is a legitimate answer; and a 404/200 split would make the
     *    endpoint report whether a row exists, which is one bit more than
     *    it needs to give.
     *
     * Authorized on ModulePolicy::view — the same check the PUT and
     * ModuleLessonController::stream() make, so "can open the file", "can
     * record having opened it" and "can resume where I left off" can never
     * diverge. Cross-tenant is already 404 at route-model binding
     * (TenantScope, §5 rule 5).
     */
    public function me(Request $request, ModuleLesson $moduleLesson): MyModuleLessonProgressResource
    {
        $this->authorize('view', $moduleLesson->module);

        $progress = ModuleLessonProgress::query()
            ->where('user_id', $request->user()->id)
            ->where('module_lesson_id', $moduleLesson->id)
            ->first() ?? new ModuleLessonProgress;

        return new MyModuleLessonProgressResource($progress);
    }
}
