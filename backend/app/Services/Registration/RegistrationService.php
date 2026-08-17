<?php

namespace App\Services\Registration;

use App\Enums\AgentApprovalStatus;
use App\Enums\IdDocumentType;
use App\Enums\RegistrationChannel;
use App\Enums\UserRole;
use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Platform\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// ADR-005 — the ONE place every registration path (email here, and
// TASK-019's three social providers) resolves an invite code and
// creates a brand-new Agent User. company_id is never accepted
// directly from the client anywhere in this Service — it always comes
// from the resolved CompanyInviteCode, mirroring the existing
// StoreClientRequest "never trust client input for tenant-determining
// fields" pattern.
//
// TASK-114 / ADR-025 §5 adds a SECOND way to arrive: a team leader's
// recruit link (`ref_token`). It REPLACES the invite code rather than
// stacking with it — a link already carries company_id (from its
// inviter), so a recruit arriving via ?ref=<token> is never asked for a
// code. Both paths obey the same rule above: company_id (and now
// manager_id) is derived server-side from whichever credential was
// supplied, never from the request body.
//
// TASK-122 adds one more obligation every path shares: record the
// registrant's IDENTITY DOCUMENT (Thai national ID or passport, per
// IdDocumentType) and refuse one that is already registered IN THAT COMPANY.
// The duplicate check lives here rather than in RegisterRequest for exactly
// the same reason company_id does — the company is resolved server-side,
// after validation, from whichever credential was supplied. See
// assertDocumentNotAlreadyUsed().
class RegistrationService
{
    public function __construct(private readonly UserService $userService)
    {
    }

    /**
     * Resolves a raw invite code string to a valid, usable
     * CompanyInviteCode. Every registration path must call this rather
     * than re-implementing the "find by code + isValid()" lookup.
     */
    public function resolveInviteCode(string $rawCode): ?CompanyInviteCode
    {
        $code = CompanyInviteCode::where('code', $rawCode)->first();

        if (! $code || ! $code->isValid()) {
            return null;
        }

        /*
         * TASK-183 §3.5 — a closed tenant recruits nobody.
         *
         * An invite code is a long-lived credential printed on collateral and
         * pasted into chat threads; it outlives the recruiting drive that
         * minted it. Without this, a deactivated or soft-deleted company keeps
         * accepting brand-new Agent rows into itself — accounts that then sit
         * in an approval queue nobody can reach, because every Admin of that
         * company is now refused at login.
         *
         * Answered by returning null, not by throwing: null is already this
         * method's single "this code will not work" answer, and both callers
         * (the resolve endpoint's 404 and registerViaEmail()'s 422) deliberately
         * give ONE generic reason for every failure mode, so an anonymous
         * caller cannot tell "no such code" from "that company is switched
         * off" (ADR-005's generic-message rule, §3.4).
         */
        return Company::isOperationalById($code->company_id) ? $code : null;
    }

    /**
     * TASK-114 / ADR-025 §5 — the recruit-link counterpart of
     * resolveInviteCode(). Deliberately the same shape: a single lookup
     * helper both the Form Request and the Controller share, so "is this
     * token usable" can never mean two different things in two places.
     *
     * withoutGlobalScopes() because there is no authenticated user on a
     * public registration request to scope by — the exact precedent
     * PublicProductShareController::resolveUsableLink() and
     * PublicPaymentController::resolve() already set. Safe despite BR-6:
     * the lookup key is a 64-char unguessable token, not an id, and the
     * caller learns nothing about any tenant it did not already hold a
     * token for.
     *
     * Returns the link with `company` eager-loaded and `agent` set to the
     * exact inviter row this method validated (see resolveActiveInviter()
     * — the caller must not re-resolve it through a differently scoped
     * relation query and risk getting a different answer).
     */
    public function resolveRefToken(string $rawToken): ?AgentInviteLink
    {
        $link = AgentInviteLink::withoutGlobalScopes()
            ->with('company')
            ->where('token', $rawToken)
            ->first();

        if (! $link || ! $link->isUsable()) {
            return null;
        }

        // TASK-183 §3.5 — the recruit-link twin of the invite-code check in
        // resolveInviteCode() above, for the same reason and with the same
        // "return null so every failure mode reads identically" treatment. A
        // team leader's link must stop admitting recruits the moment their
        // company stops operating, not at whatever point the link happens to
        // expire.
        if (! Company::isOperationalById($link->company_id)) {
            return null;
        }

        $inviter = $this->resolveActiveInviter($link);

        if (! $inviter) {
            return null;
        }

        $link->setRelation('agent', $inviter);

        return $link;
    }

    /**
     * ag-lead ruling on TASK-114 item 5 (ADR-025 §5 left it open):
     * a minted link becomes UNUSABLE if its inviter has since been
     * soft-deleted, has lost `is_team_leader`, or no longer belongs to the
     * link's company.
     *
     * WHY THIS LIVES HERE AND NOT IN AgentInviteLink::isUsable():
     * isUsable() is a PURE IN-MEMORY PREDICATE over three columns of the
     * link row itself. Several callers — AgentInviteLinkResource's
     * `is_usable` field, rendered once per row in a leader's or an Admin's
     * list — assume it issues NO query. Loading the inviter relation
     * inside it would turn every list render into a hidden N+1. So the
     * split is: isUsable() owns the LINK's own state, this method owns the
     * INVITER's state, and every consumption path must call BOTH. That
     * pairing is enforced in exactly the two places it matters —
     * resolveRefToken() (feeding the public resolver and the Form Request)
     * and the in-lock re-check inside registerViaRecruitLink().
     *
     * withoutGlobalScopes([TenantScope::class]) rather than a bare
     * withoutGlobalScopes(): dropping ALL scopes would also drop
     * SoftDeletingScope and hand back a deactivated inviter, defeating the
     * first third of the ruling. Only the tenant filter is unwanted here
     * (a public request has no tenant context to filter by).
     */
    private function resolveActiveInviter(AgentInviteLink $link): ?User
    {
        $inviter = User::withoutGlobalScopes([TenantScope::class])->find($link->agent_id);

        if (! $inviter) {
            return null; // deactivated (SoftDeletes) or hard-gone.
        }

        if (! $inviter->is_team_leader) {
            return null; // ADR-025 §2 — the flag was revoked; no more recruiting.
        }

        if ($inviter->company_id !== $link->company_id) {
            return null; // moved company (UserService::moveToCompany) — the link's tenant is no longer theirs.
        }

        return $inviter;
    }

    /**
     * TASK-115 (TASK-021 item 3) — re-send the verification link for the ONE
     * actionable login-blocked state (LoginBlockReason::EmailUnverified).
     *
     * RETURNS NOTHING, ON PURPOSE. The Controller answers with the same 200
     * and the same message whether an email went out or not, so this method
     * must never signal its decision back — a bool return would eventually
     * be leaked into a response by a well-meaning future edit, and that
     * response is the enumeration oracle this endpoint has to avoid (see
     * ResendVerificationEmailRequest's docblock for the three-layer split).
     *
     * withoutGlobalScopes([TenantScope::class]) — not a bare
     * withoutGlobalScopes(): a public caller has no tenant context to filter
     * by, but SoftDeletingScope must STAY, so a deactivated account cannot
     * be pinged. Same reasoning, same idiom, as resolveActiveInviter() above.
     *
     * Nothing is sent when the account is not an Agent, was Admin-created
     * (isSelfRegistered() false — it was never asked to verify), is already
     * verified, or was rejected. The rejected case matters: mailing a
     * verification link to someone the company has already turned away would
     * imply the decision is still open, and the login gate would block them
     * on the rejection anyway (LoginGateService answers Rejected first).
     */
    public function resendVerificationEmail(string $email): void
    {
        $user = User::withoutGlobalScopes([TenantScope::class])
            ->where('email', $email)
            ->first();

        if (! $user
            || ! $user->isAgent()
            || ! $user->isSelfRegistered()
            || $user->hasVerifiedEmail()
            || $user->agent_approval_status === AgentApprovalStatus::Rejected
            // TASK-183 §3.5 — nothing is sent for a closed tenant either.
            // Same reasoning as the Rejected case one line up: the login gate
            // refuses them on company status regardless, so mailing a "verify
            // your email" link would advertise an action that leads nowhere.
            // Silent, like every other branch here — the Controller answers
            // 200 with the same sentence either way, and a visible difference
            // would turn this endpoint into an oracle for which tenants are
            // suspended.
            || ! $user->belongsToOperationalCompany()) {
            return;
        }

        // Same notification the original registration sent — a signed URL
        // into the Agent Portal (VerifyRegistrationEmailNotification), with
        // its own expiry. No custom token scheme invented (ADR-005).
        $user->sendEmailVerificationNotification();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function registerViaEmail(array $data): User
    {
        // ADR-025 §5 — either/or, never both, never neither. RegisterRequest
        // has already enforced the mutual exclusion; this branch just picks
        // the path. `ref_token` wins on presence alone because the Form
        // Request guarantees `invite_code` cannot also be there.
        if (($data['ref_token'] ?? null) !== null) {
            return $this->registerViaRecruitLink($data);
        }

        $inviteCode = $this->resolveInviteCode($data['invite_code']);

        // Defense in depth — RegisterRequest's validation already
        // checked this, but time may have passed (code revoked/expired
        // between the resolve step and this submission).
        abort_unless($inviteCode, 422, 'รหัสเชิญไม่ถูกต้องหรือหมดอายุแล้ว');

        // TASK-122 — the company is only known NOW (it came from the code),
        // which is exactly why this check cannot live in RegisterRequest.
        // No transaction here, unlike the recruit-link path: this path opens
        // none, so there is no lock to run inside. Two simultaneous
        // registrations of the same document under the same invite code
        // could both pass this check — see assertDocumentNotAlreadyUsed()'s
        // docblock for why that is a recorded limitation and not a silent one.
        $this->assertDocumentNotAlreadyUsed(
            (int) $inviteCode->company_id,
            $data['national_id'],
            IdDocumentType::from($data['id_document_type']),
        );

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            // TASK-122 — the identity document. `national_id` holds the
            // NUMBER (encrypted at rest, §6/PDPA) and `id_document_type`
            // says which kind of document it is; User's saving hook derives
            // `national_id_hash` from the pair. Both come from validated
            // input — they describe the person, not the tenant.
            'national_id' => $data['national_id'],
            'id_document_type' => $data['id_document_type'],
            'company_id' => $inviteCode->company_id,
            'role' => UserRole::Agent,
            'agent_approval_status' => AgentApprovalStatus::Pending,
            'registered_via' => RegistrationChannel::Email,
            'registered_via_invite_code_id' => $inviteCode->id,
            'email_verified_at' => null,
        ]);

        $user->sendEmailVerificationNotification();

        return $user;
    }

    /**
     * TASK-114 / ADR-025 §4, §5, §6 — registration through a team leader's
     * recruit link.
     *
     * Everything that decides WHO this user becomes is derived from the
     * link row, server-side: company_id from the link's company,
     * manager_id from the link's agent_id, recruited_via_agent_link_id
     * from the link itself. $data contributes only the person's own
     * details (name/email/phone/password) — RegisterRequest defines no
     * rule for company_id / manager_id / agent_approval_status /
     * recruited_via_agent_link_id, so validated() drops them and they can
     * never reach this method even if a client sends them.
     *
     * @param  array<string, mixed>  $data
     */
    private function registerViaRecruitLink(array $data): User
    {
        // ── Courtesy pre-check (NOT the real one) ──────────────────────
        // This is the THIRD time the token is checked (RegisterRequest's
        // closure was the second) and, like the invite-code path's
        // abort_unless above, it exists so an already-dead link fails fast
        // and cheaply with a clear message before we open a transaction and
        // take a row lock. It is NOT what makes the quota safe — see the
        // in-lock re-check below.
        $preview = $this->resolveRefToken($data['ref_token']);

        if (! $preview) {
            throw $this->unusableRefToken();
        }

        $linkId = $preview->id;

        // ── TASK-119 / QA finding D1: `attempts: 3` below is the fix ──────
        // attempts defaults to 1 — and with 1, a transaction that dies of a
        // concurrency error is not retried, it just propagates. That matters
        // here because the lock taken below is held for the WHOLE closure,
        // which includes User::create() AND assignManager() → a Matrix tree
        // walk. Under real contention on MySQL 8 a queued request can sit on
        // that row past innodb_lock_wait_timeout (default 50s) and come back
        // with a QueryException — i.e. a 500, when ADR-025 §4 and the TASK-118
        // spec promise the loser gets a clean 422.
        //
        // Laravel retries ONLY on a concurrency error (deadlock / "Lock wait
        // timeout exceeded" / serialization failure — see
        // Illuminate\Database\ConcurrencyErrorDetector). The ValidationException
        // thrown by the in-lock re-check is NOT one of those, so a genuine
        // "link is used up" answer still fails fast on the first attempt and
        // is never retried into a different outcome.
        //
        // WHY THE RETRY CANNOT DOUBLE-CONSUME THE QUOTA: a retry re-enters the
        // closure from the top, which means it re-reads the link under a fresh
        // lock and re-evaluates isUsable() + resolveActiveInviter() again. A
        // failed attempt was rolled back in full — its increment and its user
        // row are both gone — so attempt N+1 sees exactly the state the
        // database is really in. If the winner committed in the meantime, the
        // re-check now fails and this request gets its 422. The invariant
        // "used_count never exceeds max_uses" therefore holds for ANY number
        // of attempts; it is a property of the in-lock re-check, not of
        // attempts = 1.
        //
        // !! IF YOU ADD ANYTHING TO THIS CLOSURE, READ THIS FIRST !!
        // A retry re-runs EVERY statement in here, including User::create().
        // Only work the transaction can roll back may live inside. Anything
        // non-transactional — mail, a queued job dispatched on the sync
        // driver, an HTTP call, a filesystem write — would happen once per
        // attempt and could not be undone. The verification email is already
        // (and must stay) outside, below the commit.
        $user = DB::transaction(function () use ($data, $linkId) {
            // ── ADR-025 §4: the real check ─────────────────────────────
            // Re-read the SAME row under a row-level write lock. Every
            // concurrent registration against this link now queues here,
            // one at a time, until this transaction commits or rolls back.
            //
            // !! DO NOT DELETE THIS AS A "DUPLICATE" OF THE PRE-CHECK !!
            // The pre-check above read the row with no lock; between that
            // read and this line another request may already have consumed
            // the last remaining use, revoked the link, or had the inviter
            // de-flagged. Only a check performed INSIDE the lock, in the
            // same transaction as the increment that follows it, can make
            // "used_count can never exceed max_uses" true. Two recruits
            // submitting simultaneously against max_uses = 1 must yield
            // exactly one 201 and one 422; delete this block and they both
            // get a 201. That is TASK-118 test case 2, and it is the defect
            // most likely to survive a naive implementation.
            $link = AgentInviteLink::withoutGlobalScopes()
                ->whereKey($linkId)
                ->lockForUpdate()
                ->first();

            // isUsable() covers the link's OWN state (revoked / expired /
            // quota); resolveActiveInviter() covers the inviter's state;
            // Company::isOperationalById() covers the TENANT's state
            // (TASK-183 §3.5). All three, per the ruling documented on
            // resolveActiveInviter().
            //
            // The tenant check is repeated here rather than left to the
            // pre-check above for exactly the reason the quota re-check is:
            // the pre-check ran with no lock, and an Admin may deactivate the
            // company in the window between it and this line. Re-asking it
            // inside the lock is what makes "no user is ever created into a
            // closed company" true rather than merely likely.
            if (! $link
                || ! $link->isUsable()
                || ! Company::isOperationalById($link->company_id)
                || ! $this->resolveActiveInviter($link)) {
                throw $this->unusableRefToken();
            }

            // TASK-122 — INSIDE the transaction, and after the lock, on
            // purpose. Two things follow from that placement:
            //   * the company is only known here (it comes from the link),
            //     so the check cannot be a Form Request rule; and
            //   * every concurrent registration against this link is already
            //     queued behind the lock above, so two recruits submitting
            //     the same document through the SAME link cannot both pass
            //     this check — the loser re-runs it after the winner's row
            //     is committed and gets the 422.
            // (Two different links in the same company are still racy — see
            // the method's own docblock.) A rejection here rolls back the
            // used_count increment above with everything else.
            $this->assertDocumentNotAlreadyUsed(
                (int) $link->company_id,
                $data['national_id'],
                IdDocumentType::from($data['id_document_type']),
            );

            // Consume the slot first, so the row this transaction holds is
            // already decremented for anyone queued behind us the instant
            // we commit. (Ordering inside the transaction is cosmetic —
            // atomicity comes from the lock plus the transaction boundary —
            // but "reserve, then use" is the easier invariant to read.)
            $link->increment('used_count');

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                // TASK-122 — same pair as the invite-code path above; the
                // two self-registration routes must never disagree about
                // what identity is required.
                'national_id' => $data['national_id'],
                'id_document_type' => $data['id_document_type'],
                // ADR-025 §5 — from the link, never from the body.
                'company_id' => $link->company_id,
                'role' => UserRole::Agent,
                // Unchanged from the invite-code flow: a recruit is still
                // pending and still unverified. A team leader's link admits
                // nobody on its own (approval is TASK-115).
                'agent_approval_status' => AgentApprovalStatus::Pending,
                'registered_via' => RegistrationChannel::Email,
                // ADR-025 §6 — immutable attribution, mirroring
                // registered_via_invite_code_id. Survives the leader later
                // losing the flag, the link being revoked, or an Admin
                // re-pointing manager_id.
                'recruited_via_agent_link_id' => $link->id,
                'email_verified_at' => null,
                // NOTE the absence of 'manager_id' here. See below.
            ]);

            // ADR-025 §6 — manager_id is set through the SAME guarded
            // routine the Admin path uses, never written into the
            // User::create() array above. Two reasons, both load-bearing:
            //   * assertValidManager() (same-company / no-self / no-cycle)
            //     is the documented contract for this column. Cycle risk is
            //     nil for a brand-new user, but "we knew it was safe this
            //     one time" is how the guard stops being universal.
            //   * On a Matrix-plan company this is the ONLY thing that puts
            //     the recruit into the placement tree. Skipping it produces
            //     an agent who exists, sells, and pays nobody — silently.
            // Inside the transaction on purpose: if placement fails, the
            // user, the manager assignment and the consumed quota slot all
            // roll back together.
            $this->userService->assignManager($user, $link->agent_id);

            return $user;
        }, attempts: 3);

        // Outside the transaction deliberately (and see the attempts note
        // above — a retry would send it twice): an email cannot be un-sent if
        // a later statement rolls the registration back.
        $user->sendEmailVerificationNotification();

        return $user;
    }

    /**
     * TASK-122 — one identity document may register only ONCE PER COMPANY.
     *
     * WHY PER COMPANY AND NOT GLOBALLY. This is a multi-tenant platform
     * (BR-6): the same real person may legitimately be an agent at Thai Life
     * and at another company on the same install, and a global uniqueness
     * rule would let company A's roster silently veto a signup at company B —
     * and, worse, would turn this endpoint into an oracle for "is this person
     * an agent somewhere on this platform", which no anonymous caller is
     * entitled to know. The scope is therefore the resolved company_id, which
     * matches the shape of the existing index
     * `users(company_id, national_id_hash)`.
     *
     * WHY IT IS A HASH LOOKUP, NOT A `unique:` VALIDATION RULE. `national_id`
     * is encrypted at rest, so the stored ciphertext differs on every write
     * for the same input — a SQL comparison against it can never match. The
     * deterministic blind index is the only searchable form, and it is
     * type-aware as of this task (User::hashNationalId), so a passport and a
     * Thai ID that share digits do NOT collide here.
     *
     * WHY SOFT-DELETED ROWS COUNT. A deactivated agent is still that person's
     * account in this company; letting the same document open a second one
     * would create two identities for one human, with the earnings history
     * split across them. This also matches what already happens to the OTHER
     * identifier on this form: `unique:users,email` is a plain SQL uniqueness
     * check and has always seen soft-deleted rows. The remedy for a genuine
     * returner is for an Admin to restore the account (POST
     * /users/{user}/restore), which is the reason that endpoint exists.
     *
     * KNOWN LIMITATION, STATED RATHER THAN PAPERED OVER: this is a read
     * followed by a write, not a database constraint. On the recruit-link
     * path the caller runs it inside the link's row lock, which serialises
     * every registration through that one link. Nothing serialises two
     * registrations arriving through DIFFERENT credentials of the same
     * company at the same instant, so a determined duplicate is still
     * possible in that window. Closing it properly needs a unique index on
     * `(company_id, national_id_hash)` — the existing index is deliberately
     * NON-unique because pre-TASK-122 rows may share a null hash, and adding
     * uniqueness would also have to answer what "unique" means across the
     * soft-delete boundary. Flagged for ag-lead; not decided here.
     *
     * The message names no one: telling an anonymous caller WHOSE account
     * already holds this number would leak the existence and identity of
     * another agent (§6).
     */
    private function assertDocumentNotAlreadyUsed(int $companyId, string $document, IdDocumentType $type): void
    {
        $hash = User::hashNationalId($document, $type);

        if ($hash === null) {
            // Nothing to compare against — a value that normalizes to
            // nothing cannot be a duplicate. The Form Request's IdDocument
            // rule has already rejected any such value on the public paths;
            // this is only a guard against a future caller that has not.
            return;
        }

        // withoutGlobalScopes([TenantScope::class]) — dropped EXPLICITLY,
        // not because it currently bites. TenantScope no-ops when there is no
        // authenticated user (see its apply()), which is the normal case on a
        // public registration request. But if this Service is ever called
        // from an authenticated context, that scope would filter by the
        // ACTOR's company rather than the one resolved from the credential —
        // and a duplicate check that quietly searched the wrong tenant would
        // pass every time. Dropping it makes the ->where('company_id', ...)
        // below the single source of truth for the scope, which is the only
        // thing this method is allowed to be sure about.
        //
        // NOT a bare withoutGlobalScopes(): that would also drop
        // SoftDeletingScope implicitly, and whether deleted rows count is a
        // decision, not a side effect — hence the explicit ->withTrashed()
        // (see the docblock for why they count).
        $exists = User::withoutGlobalScopes([TenantScope::class])
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('national_id_hash', $hash)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'national_id' => 'เลขที่เอกสารนี้ถูกใช้สมัครสมาชิกในบริษัทนี้แล้ว',
            ]);
        }
    }

    /**
     * One message for every "this link will not work" outcome — unknown
     * token, expired, revoked, quota exhausted, inviter deactivated or
     * de-flagged. Same reasoning as ADR-005's generic invite-code message:
     * an anonymous caller must not be able to tell WHICH of those applied,
     * or they can probe a leader's recruiting state.
     *
     * 422 (not 404) because by this point the token is a field on a
     * submitted form, so it belongs with the other per-field errors —
     * `resolve-ref-token`, which is a lookup rather than a submission,
     * 404s instead, exactly like resolveInviteCode().
     *
     * Returns the exception rather than throwing it so both call sites
     * read `throw $this->unusableRefToken();` — which keeps PHP's (and
     * PHPStan's) null-narrowing intact at the call site, unlike a
     * void helper that throws internally.
     */
    private function unusableRefToken(): ValidationException
    {
        return ValidationException::withMessages([
            'ref_token' => 'ลิงก์ชวนเข้าทีมนี้ไม่ถูกต้อง หมดอายุ ถูกยกเลิก หรือมีผู้ใช้ครบจำนวนแล้ว',
        ]);
    }
}
