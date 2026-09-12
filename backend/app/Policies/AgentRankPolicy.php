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
//
// 2026-09-11 (owner decision) — writes narrowed to Super Admin, with
// reads untouched; see CommissionRulePolicy's docblock for the reasoning
// and the cost. It is the rate_type/rate_value on a rank that drags it
// in: a Company Admin who could still add a rank could still set a
// commission rate, which is the exact capability the decision removes.
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
        return $user->isSuperAdmin();
    }

    public function update(User $user, AgentRank $agentRank): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, AgentRank $agentRank): bool
    {
        return $user->isSuperAdmin();
    }
}
