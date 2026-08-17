<?php

namespace App\Policies;

use App\Models\User;

/**
 * Section 6 audit trail — admin-only sensitive data (who changed what,
 * when). Agent role has zero access. AuditLog is deliberately NOT
 * TenantScope'd (see its own docblock), so row-level "own company only"
 * narrowing for Company Admin happens in AuditLogController::index(),
 * not here — this Policy only gates whether the endpoint is reachable
 * at all (viewAny), same "list-level gate, no per-row view()" shape as
 * other pure-reporting endpoints in this codebase (e.g. ProductGradingService
 * has no Policy of its own — it reuses ProductPolicy's viewAny).
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCompanyAdmin() || $user->isSuperAdmin();
    }
}
