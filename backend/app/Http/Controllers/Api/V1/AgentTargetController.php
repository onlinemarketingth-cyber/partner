<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Enums\TargetMetric;
use App\Http\Controllers\Controller;
use App\Models\AgentTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * TASK-053 / ADR-016 Phase 1 — per-agent sales/deal/client targets.
 *  • me()  : the authenticated agent reads their OWN targets (for the goal
 *            ring on the home hub).
 *  • upsert(): Company Admin / Super Admin sets/updates an agent's target
 *            for a period (BR-7: the value is admin data, never hardcoded).
 *            A period is either 'YYYY-MM' (monthly) or 'YYYY' (yearly,
 *            TASK-130) — see the validation rule for why the shape carries
 *            that meaning.
 * Tenant-scoped via the model's TenantScope; upsert additionally forces
 * company_id from the actor and validates the agent is in that company.
 */
class AgentTargetController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $targets = AgentTarget::query()
            ->where('agent_id', $request->user()->id)
            ->get()
            ->map(fn (AgentTarget $t) => $this->toArray($t));

        return response()->json(['data' => $targets]);
    }

    /**
     * Company Admin / Super Admin reads one agent's targets (to prefill
     * the Admin target editor). TenantScope on the model keeps a Company
     * Admin to their own company — a foreign agent_id simply yields an
     * empty list (same IDOR-safe "narrower WHERE on a tenant-scoped
     * model" pattern as CommissionLedgerController::index()).
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Ability::AgentTargetView), 403);

        $targets = AgentTarget::query()
            ->where('agent_id', $request->integer('agent_id'))
            ->get()
            ->map(fn (AgentTarget $t) => $this->toArray($t));

        return response()->json(['data' => $targets]);
    }

    public function upsert(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Ability::AgentTargetUpdate), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? (int) $request->input('company_id', 0)
            : $request->user()->company_id;

        $validated = $request->validate([
            'agent_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId)->where('role', 'agent'),
            ],
            // TASK-130 §4+§5 — two SHAPES, and the shape IS the meaning:
            //   'YYYY-MM' (7 chars) = a monthly target — what /me/home's goal
            //                         ring reads (MeService::home filters on
            //                         Carbon::now()->format('Y-m')).
            //   'YYYY'    (4 chars) = a YEARLY target for that calendar year.
            // A regex, not a length check, so '2026-8' or '20261' are still
            // rejected. agent_targets.period is string(7), so a 4-character
            // year needs no migration, and unique(agent_id, period, metric)
            // keeps both rows apart on its own — '2026' and '2026-08' are
            // simply different periods.
            'period' => ['required', 'string', 'regex:/^\d{4}(-\d{2})?$/'],
            'metric' => ['required', Rule::enum(TargetMetric::class)],
            'target_value' => ['required', 'integer', 'min:0'],
        ]);

        $target = AgentTarget::updateOrCreate(
            [
                'agent_id' => $validated['agent_id'],
                'period' => $validated['period'],
                'metric' => $validated['metric'],
            ],
            [
                'company_id' => $companyId,
                'target_value' => $validated['target_value'],
            ],
        );

        return response()->json(['data' => $this->toArray($target)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(AgentTarget $t): array
    {
        return [
            'id' => $t->id,
            'agent_id' => $t->agent_id,
            'period' => $t->period,
            'metric' => $t->metric->value,
            'metric_label' => $t->metric->label(),
            'target_value' => $t->target_value,
        ];
    }
}
