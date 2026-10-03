<?php

namespace App\Policies;

use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * MOB-12 (2026-10-02) — who may ask for an account deletion, and who decides.
 *
 * ── ASKING: AGENTS ONLY ──
 *
 * The App Store requirement this serves is about the agent portal app, and
 * an agent is the only role whose departure has a defined procedure (settle
 * commission, move the downline and clients, then anonymise). A Company Admin
 * or Super Admin deleting THEIR OWN account has no such procedure and a much
 * larger blast radius — the last admin of a company removing themselves would
 * leave nobody able to approve anything — so they are refused here and handled
 * by another admin through จัดการผู้ใช้ระบบ, as today. The company's own
 * commission seat (CommissionHouseAccountService) is not a person; its row is
 * role=company_admin so the role check already refuses it, and it is named
 * explicitly anyway so that refusal does not depend on which role the seat
 * happens to carry.
 *
 * ── DECIDING: SAME AUTHORITY AS THE REST OF THE ROSTER ──
 *
 * Company Admin within their own company, Super Admin anywhere — the same
 * line UserPolicy draws for deactivating an account, which is the nearest
 * existing action. The company comparison is made against the REQUEST's
 * company_id (captured when it was filed), not the user's current one; see
 * the migration's note 3. TenantScope has already 404'd a foreign id before
 * this runs for a Company Admin; the comparison is the belt to that brace.
 */
class AccountDeletionRequestPolicy
{
    public const ONLY_AGENTS_MESSAGE = 'การขอลบบัญชีผ่านแอปใช้ได้เฉพาะบัญชีสมาชิก (ตัวแทน) เท่านั้น — บัญชีผู้ดูแลระบบต้องให้ผู้ดูแลอีกคนดำเนินการให้';

    public function create(User $user): Response
    {
        if (! $user->isAgent() || $user->isCommissionHouseAccount()) {
            return Response::deny(self::ONLY_AGENTS_MESSAGE);
        }

        return Response::allow();
    }

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id !== null);
    }

    public function decide(User $user, AccountDeletionRequest $request): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id !== null && $user->company_id === $request->company_id);
    }
}
