<?php

namespace App\Services\Auth;

use App\Enums\AgentApprovalStatus;
use App\Enums\LoginBlockReason;
use App\Exceptions\LoginBlockedException;
use App\Models\User;

/**
 * TASK-115 (implements TASK-021, pulled in by ADR-025 §8) — THE login gate.
 *
 * Before this existed, `agent_approval_status` gated nothing: LoginRequest
 * was stock Breeze with no approval check and no hasVerifiedEmail() check, so
 * a self-registered stranger could log in and work normally the moment they
 * submitted the form. That made ADR-005's whole approval flow decorative and
 * made ADR-025 decision 7 ("a leader may approve their own recruits")
 * meaningless — there was nothing to approve them INTO.
 *
 * Section 7 layering: this is business logic, so it lives in a Service, not
 * in the Controller and (per ADR-025 §8, explicitly) never in Vue. A UI-only
 * gate is not a gate — the API is the thing an attacker talks to.
 *
 * ── NON-ENUMERATION ANALYSIS (Section 6) ─────────────────────────────────
 * The requirement is that a wrong password and a blocked-but-correct
 * password must not let an attacker learn which emails are registered.
 *
 * What this design guarantees:
 *
 *   1. This Service is only ever reached AFTER
 *      Auth::guard('web')->attempt() has returned true — i.e. after the
 *      caller has already supplied the correct password for that account.
 *      An attacker who can reach a 403 here already possesses the
 *      credentials; the 403 tells them nothing they did not already know.
 *
 *   2. Every path that does NOT reach here is byte-identical:
 *        * unknown email        -> attempt() false -> 422 __('auth.failed')
 *        * known email, wrong password -> attempt() false -> 422 __('auth.failed')
 *      Same status, same body, same `errors.email` key, same
 *      RateLimiter::hit() on the same throttle key. There is no observable
 *      difference between "no such user" and "wrong password".
 *
 *   3. The three blocked states ARE distinguishable from each other, by
 *      design and on purpose. That is not the enumeration boundary: the only
 *      audience that can ever see them is the account's own owner (see 1).
 *      Collapsing them would not raise the bar for an attacker by one bit,
 *      and would leave a legitimate owner unable to tell "verify your email"
 *      from "wait for approval" from "you were rejected" — the exact
 *      confusion TASK-021 and ADR-005 decision 6 were written to remove.
 *
 *   4. Throttling and lockout are untouched: RateLimiter::hit() still fires
 *      only on a failed credential check, and the 5-attempt lockout in
 *      LoginRequest::ensureIsNotRateLimited() runs before anything here.
 *      A blocked account does NOT consume attempts (the password was right),
 *      so a pending user retrying cannot lock themselves out of the resend
 *      affordance.
 *
 * KNOWN, PRE-EXISTING, NOT INTRODUCED HERE: a timing side channel. Laravel's
 * EloquentUserProvider only runs the bcrypt comparison when a matching row
 * exists, so an unknown email answers measurably faster than a known one.
 * That is stock framework behaviour present before this task and unchanged
 * by it; fixing it means hashing a dummy password on the miss path, which is
 * a change to LoginRequest's credential flow and therefore out of scope
 * here. Flagged rather than silently ignored (Guardrail 6).
 * ─────────────────────────────────────────────────────────────────────────
 */
class LoginGateService
{
    /**
     * @throws LoginBlockedException when the account exists and the password
     *                               was correct, but the account is not
     *                               permitted to hold a session yet.
     */
    public function assertMayLogIn(User $user): void
    {
        // ── ORDER: company status FIRST — ABOVE the isAgent() early return.
        // TASK-183 §3.2. Two independent reasons it cannot sit anywhere else
        // in this chain, stated here in the same style as the "rejected first"
        // note below:
        //
        //   1. It is the ONLY refusal in this method that must also reach a
        //      COMPANY ADMIN. Everything after the early return is about an
        //      Agent's own registration state, which ADR-025 §8 deliberately
        //      exempts admins from. But "this tenant is closed" is a fact
        //      about the company, not about how the person joined it —
        //      and if the switch stopped the agents while leaving the Company
        //      Admin logged in, it would leave the account with the MOST
        //      authority inside the closed tenant still able to create users,
        //      edit commission config and confirm payments. That is not a
        //      partial control, it is the wrong one.
        //
        //   2. Nothing the person does can clear it. Verifying an email or
        //      waiting for approval does not reopen a closed company, so
        //      answering either of those first would hand out a false
        //      instruction — precisely the reasoning that already puts
        //      ApprovalRejected ahead of EmailUnverified below.
        //
        // Super Admin is unaffected: see User::belongsToOperationalCompany(),
        // which exempts them explicitly (and which is also the only reason a
        // deactivated company remains reactivatable through the API at all).
        //
        // This is the LOGIN half only. A session or token minted BEFORE the
        // deactivation never passes through here again, so the same predicate
        // is re-asked on every authenticated request by
        // App\Http\Middleware\EnsureCompanyIsOperational (TASK-183 §3.3).
        // Login-only enforcement would mean "deactivate" takes effect at the
        // next login, which for an already-active user could be never.
        if (! $user->belongsToOperationalCompany()) {
            throw new LoginBlockedException(LoginBlockReason::CompanyInactive);
        }

        // ADR-025 §8 / TASK-021's out-of-scope note: "Company Admin/Super
        // Admin accounts are never created via this self-registration path,
        // so they're never subject to this gate." Their rows were backfilled
        // to `approved` by 2026_07_14_120000 and they are created out-of-band
        // or by another Admin. Returning early here is what makes
        // frontend-admin's login provably unaffected by this task.
        if (! $user->isAgent()) {
            return;
        }

        // ── ORDER: rejected FIRST. ────────────────────────────────────────
        // TASK-021 (written 2026-07, before the approval queue shipped)
        // specified unverified -> pending -> rejected. That order is wrong
        // for one reachable combination, so this deviates deliberately and
        // says so:
        //
        //   The Admin approval queue (AgentApprovalController::index) lists
        //   every Pending user regardless of email verification, so an Admin
        //   CAN reject someone who has not verified yet. Under TASK-021's
        //   order that person is told "please verify your email" — they do
        //   the work, click the link, log in again, and only THEN discover
        //   they were rejected all along. That is a false instruction, not
        //   just a suboptimal one.
        //
        // A rejection is a decision that has already been made; nothing the
        // person does to their email changes it. So it is answered first.
        // Flagged to ag-lead in the TASK-115 completion report.
        if ($user->agent_approval_status === AgentApprovalStatus::Rejected) {
            throw new LoginBlockedException(
                LoginBlockReason::ApprovalRejected,
                // ADR-005 decision 7 — they may re-apply, so this is context
                // for a fresh attempt, not a tombstone.
                $user->approval_rejection_reason,
            );
        }

        // ADR-005 decision 4 scopes email verification to the SELF-
        // REGISTRATION path ("required in addition to Admin approval, for
        // the email/password path"). isSelfRegistered() is that scope — see
        // User::isSelfRegistered() for why it is the right discriminator and
        // what it deliberately does NOT block.
        if ($user->isSelfRegistered() && ! $user->hasVerifiedEmail()) {
            throw new LoginBlockedException(LoginBlockReason::EmailUnverified);
        }

        if ($user->agent_approval_status === AgentApprovalStatus::Pending) {
            throw new LoginBlockedException(LoginBlockReason::ApprovalPending);
        }
    }
}
