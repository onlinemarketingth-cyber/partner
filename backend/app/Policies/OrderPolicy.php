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

    /**
     * Verifying a payment — DELIBERATELY NARROWER THAN view().
     *
     * ── SECURITY AUDIT 2026-08-21, human ruling D1 ──
     *
     * This used to call ownsOrManages(), which grants the ORDER'S OWN AGENT
     * — the person who earns the commission the moment this succeeds. An
     * agent could create a client, submit a referral, walk their own
     * pipeline to the stage before payment, mint the order, confirm it, and
     * receive a real BR-4 ledger row for a sale nobody ever paid for. It was
     * proved by test before this change, not inferred.
     *
     * Whoever benefits from a sale must not also be the one who attests that
     * the money arrived. That is the whole rule, and it is why this method
     * can no longer share ownsOrManages() with view() and cancel(): an agent
     * still needs to SEE their own order, and pulling the agent out of the
     * shared helper would have blinded them to it. The duplication between
     * this method and ownsOrManages() is therefore the point, not an
     * oversight to refactor away later — they answer different questions
     * that happen to look alike today.
     *
     * A Company Admin is enough (human ruling): they neither own the sale
     * nor earn from it, and routing every tenant's payment confirmation
     * through the single platform Super Admin account would make the
     * platform owner a queue in front of every company's revenue.
     */
    public function confirm(User $user, Order $order): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && $user->company_id === $order->company_id;
    }

    /**
     * Uploading a payment slip ON THE CUSTOMER'S BEHALF (human ruling, 2026-08-21).
     *
     * ── WHY THIS ABILITY HAD TO EXIST ──
     *
     * The audit made "a slip is on file" a precondition of confirming a
     * payment, and the public /pay page was the only thing that could ever
     * create one. That stranded every customer who pays cash at a branch or
     * sends the slip to their agent over LINE: a real payment, and an order
     * nobody could close.
     *
     * ── THE SAME PEOPLE AS confirm(), AND THE HONEST CAVEAT ──
     *
     * The rule the audit established is that the person who EARNS from a
     * sale must not be the one attesting the money arrived. An admin earns
     * nothing from it, so an admin may do both halves. That is a weaker
     * control than two-person sign-off, and it is worth saying plainly: an
     * admin can now upload an image and then confirm it. What stops that
     * being invisible is the audit row this writes and the
     * slip_uploaded_by_user_id column, which tells the next reader the slip
     * came from staff and not from the customer.
     *
     * The agent stays out, as they do for confirm(), for the same reason.
     */
    public function submitSlip(User $user, Order $order): bool
    {
        return $this->confirm($user, $order);
    }

    /**
     * Refunding a paid order — SUPER ADMIN ONLY (human ruling D3).
     *
     * Narrower than confirm(), and deliberately so. Confirming says money
     * arrived, which the selling company is in the best position to know.
     * Refunding UNDOES a settled sale and writes negative entries into an
     * immutable ledger — the one operation in this system that reduces
     * commission already recorded as earned. A Company Admin refunding
     * their own agents' commissions is the same conflict of interest that
     * confirm() was just narrowed to remove, pointing the other way.
     *
     * If this becomes an operational bottleneck, the answer is a
     * maker/checker flow, not widening this line.
     */
    public function refund(User $user, Order $order): bool
    {
        return $user->isSuperAdmin();
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
