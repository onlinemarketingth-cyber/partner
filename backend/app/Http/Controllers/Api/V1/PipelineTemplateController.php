<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineTemplateResource;
use App\Models\PipelineTemplate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ADR-026 (TASK-136) — GET /api/v1/pipeline-templates, READ-ONLY.
 *
 * Until this existed there was no controller, resource, policy or route
 * for pipeline templates at all: the admin product form had nothing to
 * populate a template chooser from, so the whole configurable-pipeline
 * feature was inert — TASK-134a's migration assigned every existing
 * product `direct_sale_default` and no screen could change it.
 *
 * index-only, deliberately. Authoring (create/update/delete) is TASK-134b
 * and must not ship before the ADR-026 §3.5 invariants are enforced in a
 * Form Request as well as the Service — a template saved without
 * `complete_payment` is a silent BR-4 commission outage for every product
 * pointing at it. There is no route for those verbs and no Policy method
 * implying one (see PipelineTemplatePolicy).
 *
 * Thin per §7: no filters, no business logic. TenantScope on
 * PipelineTemplate does the BR-6 narrowing; the Policy decides who may
 * ask (Company Admin / Super Admin).
 */
class PipelineTemplateController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(PipelineTemplate::class, 'pipeline_template');
    }

    public function index(): AnonymousResourceCollection
    {
        return PipelineTemplateResource::collection(
            // `stages` is ordered by position on the relation itself
            // (PipelineTemplate::stages()), so eager-loading it here can
            // never yield an out-of-order journey.
            PipelineTemplate::query()
                ->with('stages')
                ->orderBy('name')
                ->get()
        );
    }
}
