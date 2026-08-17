<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

// ADR-017 (TASK-054), §5 rule 4 — an Order belongs to the referring agent
// (same "own records only" shape as ReferralPolicy/CommissionLedgerPolicy).
// Agent: only their own orders (agent_id = self) within their company.
// Company Admin: any order within their company. Super Admin: across
// companies. Cross-tenant/cross-agent access → denied (403/404). The
// public /pay page is intentionally NOT gated here — it's unauthenticated,
// token-gated in the PublicPaymentController (never touches this Policy).
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // narrowed to "own only" at the query level for Agent — see OrderController::index
    }

    public function view(User $user, Order $order): bool
    {
        return $this->ownsOrManages($user, $order);
    }

    public function create(User $user): bool
    {
        // Any authenticated company member may create an order; the
        // referral-ownership gate (Agent → own referral only) is enforced
        // in StoreOrderRequest against the resolved referral.
        return true;
    }

    /** Verifying a payment — same visibility shape as view(). */
    public function confirm(User $user, Order $order): bool
    {
        return $this->ownsOrManages($user, $order);
    }

    /** Cancelling an order — same visibility shape as view(). */
    public function cancel(User $user, Order $order): bool
    {
        return $this->ownsOrManages($user, $order);
    }

    private function ownsOrManages(User $user, Order $order): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $order->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $order->agent_id === $user->id;
    }
}
