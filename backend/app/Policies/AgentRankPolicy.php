<?php

namespace App\Policies;

use App\Models\AgentRank;
use App\Models\User;

// ADR-011/TASK-031 — same "sensitive compensation config, Agent
// excluded entirely" access shape as CommissionRulePolicy/
// CommissionOverrideRulePolicy/CommissionMatrixLevelRatePolicy: an
// agent_ranks row carries a rate_type/rate_value (this rank's
// commission rate), not just a display label, so it gets the same
// treatment as every other rate table in this family. An Agent sees
// their OWN current rank via /me (UserResource), never the raw ladder.
class AgentRankPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, AgentRank $agentRank): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $agentRank->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, AgentRank $agentRank): bool
    {
        return $this->view($user, $agentRank);
    }

    public function delete(User $user, AgentRank $agentRank): bool
    {
        return $this->view($user, $agentRank);
    }
}
