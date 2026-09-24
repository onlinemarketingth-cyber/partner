<?php

namespace App\Services\Commission;

use App\Models\AgentRank;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// ADR-011/TASK-031 — plain CRUD, same "force the correct company_id"
// shape as BrandService/CommissionOverrideRuleService. No overlap
// invariant on a single rank (see StoreAgentRankRequest's own comment).
//
// 2026-09-24 (owner choice 3ก) — the LADDER now has one, re-checked here
// as well as in the Form Requests. Belt and braces on the same pattern
// CLAUDE.md Section 4.3 requires of pipeline templates, and for the same
// reason: a Form Request only guards the HTTP door, while seeders,
// artisan commands and future jobs come in through this one.
class AgentRankService
{
    public function __construct(
        private readonly AgentRankLadderInspector $ladderInspector,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): AgentRank
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        $this->assertLadderNotWorse((int) $companyId, null, $data);

        return AgentRank::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AgentRank $agentRank, array $data): AgentRank
    {
        // The company is the rank's own, never anything in $data — a payload
        // carrying a company_id must not be able to validate this edit
        // against a different tenant's ladder (BR-6).
        $this->assertLadderNotWorse((int) $agentRank->company_id, $agentRank, $data);

        $agentRank->update($data);

        return $agentRank;
    }

    /**
     * Refuses a save that would RAISE the count of any ladder finding that
     * makes money wrong or unpredictable — not one that merely leaves the
     * ladder imperfect. See AgentRankLadderInspector::regressions() for why
     * the difference is the whole point.
     *
     * Reported on rate_value because that is the field the admin is almost
     * always holding when a ladder goes wrong, and an error with no field
     * renders nowhere on the form.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertLadderNotWorse(int $companyId, ?AgentRank $replacing, array $data): void
    {
        $regressions = $this->ladderInspector->regressionsForCompany($companyId, $replacing, $data);

        if ($regressions === []) {
            return;
        }

        throw ValidationException::withMessages([
            'rate_value' => $this->ladderInspector->regressionMessages($regressions),
        ]);
    }
}
