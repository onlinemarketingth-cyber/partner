<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\ReorderModulesRequest;
use App\Http\Requests\Academy\StoreModuleRequest;
use App\Http\Requests\Academy\UpdateModuleRequest;
use App\Http\Resources\ModuleResource;
use App\Models\CertTier;
use App\Models\Module;
use App\Models\User;
use App\Services\Academy\ModuleOrderService;
use App\Services\Academy\ModuleService;
use App\Support\CompanyScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// ADR-009 — Module is now a "Section": pure grouping/ordering CRUD.
// See ModuleLessonController for the content-item CRUD + stream()
// (moved off this controller since content now lives on ModuleLesson).
class ModuleController extends Controller
{
    /**
     * Everything ModuleResource + ModuleLessonResource read, in one place so
     * the five actions below cannot drift apart.
     *
     * - `lessons.quiz` / `lessons.quizQuestions.options` — ADR-029 §2.1:
     *   ModuleLessonResource reads quizQuestions for EVERY lesson now (any
     *   lesson may carry a quiz), not just a content_type=quiz one, so the
     *   relation is eager-loaded rather than lazily hit once per lesson.
     * - `lessons.module` — ADR-031 §2.2: LessonAccessGate needs each lesson's
     *   parent Section to answer "is this locked". Loading it here costs one
     *   query for the whole page; leaving it out costs one per Section.
     *
     * @var array<int, string>
     */
    private const EAGER = [
        'certTier',
        'product',
        'lessons.module',
        'lessons.quiz',
        'lessons.quizQuestions.options',
    ];

    public function __construct()
    {
        $this->authorizeResource(Module::class, 'module');
    }

    /**
     * TASK-155 — DRAFTS ARE NOT PART OF THE COURSE, SO AGENTS DO NOT GET THEM.
     *
     * Until this, `index()` applied no `is_published` filter of any kind: a
     * draft Section and its draft lessons were serialised to every Agent, and
     * the Agent Portal hid the draft LESSONS client-side (`visibleLessons()`)
     * while showing the draft SECTION's header regardless. So the Section's
     * publish toggle did nothing a learner could observe, and an admin
     * drafting next quarter's material was publishing it.
     *
     * Hidden here, refused at LessonAccessGate. Two mechanisms because they
     * answer different questions: this one keeps drafts out of the list, the
     * gate answers a guessed id on stream/completion/progress/quiz. ADR-031 §4
     * item 2 chose shown-and-greyed for LOCKED lessons ("hiding it makes the
     * course look shorter than it is") — that reasoning is specifically about
     * material the learner will eventually reach, and does not extend to a
     * draft, which may never exist.
     *
     * Admins are exempt: they are authoring. Same split as
     * LessonAccessGate::reasonFor(), and the reason the Admin app's course
     * builder can show a draft Section at all.
     */
    private function visibleTo(?User $user): Builder
    {
        $query = Module::query()->with(self::EAGER);

        if ($user === null || ! $user->isAgent()) {
            return $query;
        }

        // The nested `lessons.*` entries in self::EAGER stay in effect; this
        // only replaces the constraint on `lessons` itself, so the nested
        // loads run against the already-filtered set.
        return $query
            ->where('is_published', true)
            ->with(['lessons' => fn ($q) => $q->where('is_published', true)]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->visibleTo($request->user())->orderBy('sort_order');

        // TASK-209 — Super Admin's header company scope, applied in SQL and
        // BEFORE paginate() (narrowing a paginator would page the unfiltered set).
        CompanyScopeFilter::apply($query, $request);

        return ModuleResource::collection($query->paginate());
    }

    public function store(StoreModuleRequest $request, ModuleService $service): ModuleResource
    {
        $module = $service->create($request->validated(), $request->user());

        return new ModuleResource($module->load(self::EAGER));
    }

    public function show(Request $request, Module $module): ModuleResource
    {
        // TASK-155 — same rule as index(), applied to the single-Section read
        // so the filter cannot be sidestepped by asking for the id directly.
        // 404, not 403: to an Agent a draft Section does not exist, and
        // distinguishing "no such Section" from "a Section you may not see"
        // is exactly the IDOR-adjacent leak CLAUDE.md §5.5 warns about.
        if ($request->user()?->isAgent() && ! $module->is_published) {
            abort(404);
        }

        $module->load(self::EAGER);

        if ($request->user()?->isAgent()) {
            $module->setRelation('lessons', $module->lessons->filter(fn ($l) => $l->is_published)->values());
        }

        return new ModuleResource($module);
    }

    public function update(UpdateModuleRequest $request, Module $module, ModuleService $service): ModuleResource
    {
        $module = $service->update($module, $request->validated());

        return new ModuleResource($module->load(self::EAGER));
    }

    /**
     * PUT /cert-tiers/{certTier}/modules/reorder — TASK-151 / ADR-031 §2.1.
     *
     * The FULL ordered list of Section ids under one cert tier, renumbered in
     * ONE transaction.
     *
     * NOT covered by authorizeResource() in the constructor — that only maps
     * the seven REST abilities, so this action authorizes itself:
     * ReorderModulesRequest establishes "may author Academy content", and
     * ModuleOrderService re-checks ModulePolicy::update against EVERY Section
     * in the payload. The second check is not belt-and-braces: a Super Admin
     * is exempt from TenantScope, so for them the request-level check is the
     * only thing standing between a stray id and another company's course.
     *
     * The parent is the cert tier rather than the company because that is the
     * list an admin is actually looking at, and because `cert_tiers` is
     * global config (no TenantScope) — the tenant comes from the Sections
     * themselves, which is why the Service and not this method decides it.
     */
    public function reorder(
        ReorderModulesRequest $request,
        CertTier $certTier,
        ModuleOrderService $service,
    ): AnonymousResourceCollection {
        $modules = $service->reorderSections($certTier, $request->validated('module_ids'), $request->user());

        return ModuleResource::collection($modules->load(self::EAGER));
    }

    public function destroy(Module $module): Response
    {
        $module->delete();

        return response()->noContent();
    }
}
