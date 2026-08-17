<?php

namespace App\Policies;

use App\Models\AgentPromotion;
use App\Models\User;

// Authoring (create/update/delete) is Company Admin (own company) /
// Super Admin only — same shape as CommissionRulePolicy, since a
// promotion campaign is compensation-adjacent config. Reading is wider:
// an Agent must be able to see promotions that target them (index is
// narrowed to "applies to me" at the query level in
// AgentPromotionController::index, same "own-only narrowing" pattern as
// CommissionLedgerController) — view() here only gates a single-resource
// GET, so it re-checks targeting via AgentPromotion::appliesToAgent().
class AgentPromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AgentPromotion $agentPromotion): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $agentPromotion->company_id) {
            return false;
        }

        if ($user->isCompanyAdmin()) {
            return true;
        }

        return $agentPromotion->appliesToAgent($user);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, AgentPromotion $agentPromotion): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $agentPromotion->company_id);
    }

    public function delete(User $user, AgentPromotion $agentPromotion): bool
    {
        return $this->update($user, $agentPromotion);
    }
}
