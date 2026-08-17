<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LoginBlockReason;
use App\Events\AgentReadyForApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\RegisterRequest;
use App\Http\Requests\Registration\ResendVerificationEmailRequest;
use App\Http\Requests\Registration\ResolveInviteCodeRequest;
use App\Http\Requests\Registration\ResolveRefTokenRequest;
use App\Models\User;
use App\Services\Registration\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// ADR-005 — public, unauthenticated registration endpoints (Agent
// Portal only; frontend-admin never links here). Every action is
// rate-limited at the route level (Section 6). verifyEmail() also
// requires a valid `signed` route (see the route registration + the
// custom VerifyRegistrationEmailNotification for why the email link
// points at the frontend rather than this endpoint directly).
class RegisterController extends Controller
{
    public function resolveInviteCode(ResolveInviteCodeRequest $request, RegistrationService $service): JsonResponse
    {
        $inviteCode = $service->resolveInviteCode($request->validated('invite_code'));

        // 404, not 422 — this is a lookup, not a validation of
        // already-known data. Same generic reason regardless of why it
        // failed (unknown / expired / revoked) — never leaked (ADR-005).
        abort_unless($inviteCode, 404, 'ไม่พบรหัสเชิญนี้ในระบบ หรือรหัสหมดอายุ/ถูกยกเลิกแล้ว');

        return response()->json(['company_name' => $inviteCode->company->name]);
    }

    /**
     * TASK-114 / ADR-025 §5 — the recruit-link twin of resolveInviteCode()
     * above, and deliberately just as thin.
     *
     * RETURNS EXACTLY TWO KEYS. This endpoint is unauthenticated: anyone
     * holding (or brute-forcing) a token reaches it. It must not become a
     * window onto the company's agent roster, the size of a recruiting
     * drive, or how far along it is — so no `used_count`, no `max_uses`, no
     * `expires_at`, no link id, no agent id, and above all not the token
     * back. AgentInviteLinkResource exposes all of those and is therefore
     * NOT reused here; see that class's own docblock, which says so.
     *
     * The only thing a recruit needs to see before typing their password is
     * "you are joining <inviter> at <company>" — TASK-116 renders exactly
     * that in place of the invite-code step.
     */
    public function resolveRefToken(ResolveRefTokenRequest $request, RegistrationService $service): JsonResponse
    {
        $link = $service->resolveRefToken($request->validated('ref_token'));

        // 404 for the same reason resolveInviteCode() 404s, with the same
        // single generic reason for every failure mode — unknown token,
        // expired, revoked, quota exhausted, or an inviter who was
        // deactivated / lost is_team_leader / changed company. Splitting
        // those apart would let an anonymous caller probe a leader's state.
        abort_unless($link, 404, 'ไม่พบลิงก์ชวนเข้าทีมนี้ หรือลิงก์หมดอายุ/ถูกยกเลิก/มีผู้ใช้ครบจำนวนแล้ว');

        return response()->json([
            'company_name' => $link->company->name,
            // `name` is the derived "first last" column User maintains in
            // its saving hook — never the email, phone or id.
            'inviter_name' => $link->agent->name,
        ]);
    }

    public function register(RegisterRequest $request, RegistrationService $service): JsonResponse
    {
        $service->registerViaEmail($request->validated());

        return response()->json([
            'message' => 'สมัครสำเร็จ กรุณายืนยันอีเมลของคุณก่อนเข้าสู่ระบบ',
        ], 201);
    }

    /**
     * TASK-115 (TASK-021 item 3) — the affordance behind the
     * `can_resend_verification: true` flag on the login gate's 403.
     *
     * ALWAYS 200, ALWAYS THE SAME MESSAGE. Not "for tidiness" — this is the
     * enumeration guard. If an unknown address 404'd and a real one 200'd,
     * this endpoint would be a free membership oracle for the entire
     * platform, reachable without any password at all. That would be a
     * strictly worse leak than anything the login gate itself could produce,
     * since the gate at least demands correct credentials first.
     *
     * The message is phrased conditionally ("หากอีเมลนี้อยู่ในระบบ...") so it
     * is honest in both branches rather than claiming a send that may not
     * have happened. RegistrationService decides silently whether to send.
     */
    public function resendVerificationEmail(ResendVerificationEmailRequest $request, RegistrationService $service): JsonResponse
    {
        $service->resendVerificationEmail($request->validated('email'));

        return response()->json([
            'message' => 'หากอีเมลนี้อยู่ในระบบและยังไม่ได้ยืนยัน เราได้ส่งลิงก์ยืนยันไปให้แล้ว กรุณาตรวจสอบกล่องจดหมายของคุณ',
        ]);
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        // The 'signed' route middleware already rejected an invalid or
        // expired link before this method runs at all — see the route
        // definition in routes/api.php.
        $user = User::withoutGlobalScopes()->findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'ลิงก์ยืนยันไม่ถูกต้อง');

        /*
         * TASK-183 §3.5 — refuse for a closed tenant.
         *
         * This endpoint grants no access on its own (the login gate refuses
         * this person on company status either way), so the direct risk is
         * low. It is still refused, for two concrete reasons rather than for
         * symmetry: marking the email verified FIRES AgentReadyForApproval,
         * which notifies the Company Admins of a company that has stopped
         * operating — an alert about work nobody can do — and it writes
         * `email_verified_at` into a closed tenant's data from an
         * unauthenticated request.
         *
         * 403 with a message about the company, not 404: unlike every other
         * public endpoint here, this one is reached from a SIGNED link
         * addressed to one specific known user, so there is no enumeration
         * boundary to protect and no reason to leave them guessing why a link
         * they were sent stopped working.
         */
        abort_if(! $user->belongsToOperationalCompany(), 403, LoginBlockReason::CompanyInactive->message());

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'อีเมลนี้ยืนยันแล้ว']);
        }

        $user->markEmailAsVerified();

        // TASK-020 hook point — a listener registered there notifies
        // the company's Company Admin(s) that this registration is now
        // ready for review. This controller never needs to know that
        // listener exists.
        event(new AgentReadyForApproval($user));

        return response()->json(['message' => 'ยืนยันอีเมลสำเร็จ — บัญชีของคุณรอการอนุมัติจากบริษัทของคุณ']);
    }
}
