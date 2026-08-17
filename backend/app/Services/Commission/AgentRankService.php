<?php

namespace App\Services\Commission;

use App\Models\AgentRank;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// ADR-011/TASK-031 — plain CRUD, same "force the correct company_id"
// shape as BrandService/CommissionOverrideRuleService. No overlap
// invariant (see StoreAgentRankRequest's own comment for why).
class AgentRankService
{
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

        return AgentRank::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AgentRank $agentRank, array $data): AgentRank
    {
        $agentRank->update($data);

        return $agentRank;
    }
}
