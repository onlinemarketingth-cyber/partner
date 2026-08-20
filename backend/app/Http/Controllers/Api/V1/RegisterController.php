<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LoginBlockReason;
use App\Enums\TrackedLinkGroup;
use App\Events\AgentReadyForApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\RegisterRequest;
use App\Http\Requests\Registration\ResendVerificationEmailRequest;
use App\Http\Requests\Registration\ResolveInviteCodeRequest;
use App\Http\Requests\Registration\ResolveRefTokenRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\Link\TrackedLinkService;
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
    public function resolveInviteCode(ResolveInviteCodeRequest $request, RegistrationService $service, TrackedLinkService $trackedLinks): JsonResponse
    {
        $inviteCode = $service->resolveInviteCode($request->validated('invite_code'));

        // TASK-232 — this endpoint is what /c/thailife calls, so it is where
        // the open gets counted. Typing the code into the form by hand hits
        // the same line, which is correct: both are somebody arriving at
        // this company's signup through that campaign.
        $this->countLinkOpen($request, $trackedLinks, $request->validated('invite_code'), TrackedLinkGroup::CompanySignup);

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
    public function resolveRefToken(ResolveRefTokenRequest $request, RegistrationService $service, TrackedLinkService $trackedLinks): JsonResponse
    {
        $link = $service->resolveRefToken($request->validated('ref_token'));

        // TASK-232 — only counts when the visitor arrived via /j/<code>.
        // A legacy ?ref=<64 chars> link has no tracked link behind it and
        // reports nothing, which is the honest answer for a URL that
        // predates the feature rather than a gap to paper over.
        $this->countLinkOpen($request, $trackedLinks, $request->validated('ref_token'), TrackedLinkGroup::TeamSignup);

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

    /**
     * Count an open, and never let counting break the page.
     *
     * A registration page failing because an analytics write failed would
     * cost a recruit, which is worth strictly more than the statistic. Same
     * reasoning as ResolvesTrackedLink::recordTrackedVisit(); kept quiet for
     * the same reason too — this is an unauthenticated endpoint anyone can
     * hit at will, so an error path that logs per request is an
     * amplification vector.
     */
    private function countLinkOpen(Request $request, TrackedLinkService $trackedLinks, string $code, TrackedLinkGroup $group): void
    {
        try {
            $link = $trackedLinks->resolve($code);

            if ($link && $link->group === $group) {
                $trackedLinks->recordVisit($link, $request);
            }
        } catch (\Throwable) {
            // Intentionally ignored — see above.
        }
    }

    /**
     * TASK-235 (UAT) — resolve /in/<code> to the company's slug.
     *
     * FOUND BY CLICKING IT. The admin screen minted a short login link and
     * handed it over, and the agent portal had no route for it — a missing
     * route in this SPA does not throw, it renders the app chrome with
     * nothing inside, which reads as "the site is broken" rather than "that
     * link is wrong". The page needs the slug to become the branded
     * /login?company=<slug> it is short for, so this returns it.
     *
     * SLUG ONLY, and nothing else. It is already public — it sits in the
     * URL every company shares today — but this endpoint is
     * unauthenticated, so it must not become a way to read anything about
     * a company that is not already on that link.
     */
    public function resolveLoginLink(string $code, TrackedLinkService $trackedLinks, Request $request): JsonResponse
    {
        $company = $trackedLinks->resolveTarget($code, TrackedLinkGroup::CompanyLogin, Company::class);

        // Same generic 404 as every other resolver here: unknown, revoked
        // and expired must be indistinguishable.
        abort_unless($company && $company->slug, 404, 'ไม่พบลิงก์นี้ หรือลิงก์ถูกยกเลิกแล้ว');

        $this->countLinkOpen($request, $trackedLinks, $code, TrackedLinkGroup::CompanyLogin);

        return response()->json(['company_slug' => $company->slug]);
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
