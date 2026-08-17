<?php

namespace App\Exceptions;

use App\Enums\LoginBlockReason;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-115 — thrown by LoginGateService when credentials were CORRECT but
 * the account is not yet allowed to hold a session (ADR-025 §8).
 *
 * 403, not 422: the request was well-formed and the credentials were right;
 * what failed is authorization, not validation. Using 422 here would put the
 * message under `errors.email` alongside "รหัสผ่านไม่ถูกต้อง" and the SPA
 * would render it as a field error on the password box — exactly the
 * confusion TASK-021 was written to remove.
 *
 * Laravel's exception handler calls render() automatically when an exception
 * defines it, so no try/catch is needed in AuthController and no bespoke
 * wiring is needed in bootstrap/app.php.
 *
 * RESPONSE SHAPE (contract for TASK-116 — all five keys are ALWAYS present,
 * so the frontend can bind them without null-guarding each branch):
 *
 *   403 {
 *     "message":                 "<Thai, user-facing>",
 *     "error_code":              "email_unverified" | "approval_pending" | "approval_rejected",
 *     "can_resend_verification": bool,   // true only for email_unverified
 *     "can_reapply":             bool,   // true only for approval_rejected (ADR-005 d7)
 *     "rejection_reason":        string|null  // populated only for approval_rejected
 *   }
 */
class LoginBlockedException extends Exception
{
    public function __construct(
        public readonly LoginBlockReason $reason,
        public readonly ?string $rejectionReason = null,
    ) {
        parent::__construct($reason->message());
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->reason->message(),
            'error_code' => $this->reason->value,
            // The ONE actionable case (TASK-021: "a resend verification email
            // action, since that's actionable; the pending/rejected cases are
            // informational only").
            'can_resend_verification' => $this->reason === LoginBlockReason::EmailUnverified,
            // ADR-005 decision 7 — never present rejection as terminal.
            'can_reapply' => $this->reason === LoginBlockReason::ApprovalRejected,
            // Only ever non-null for the rejected case; the admin's own words
            // from users.approval_rejection_reason. Echoing it back to the
            // account's owner leaks nothing — they are the subject of it.
            'rejection_reason' => $this->rejectionReason,
        ], 403);
    }
}
