<?php

namespace App\Services\Platform;

use App\Enums\AgentApprovalStatus;
use App\Enums\CommissionPlanType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Commission\MatrixCommissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// "Manage Agents" — Section 7 business logic layer. Password hashing
// itself is handled entirely by User's existing 'password' => 'hashed'
// cast (Section 6: bcrypt/argon2, never done manually here).
class UserService
{
    /**
     * TASK-183 §4.3 — column => audit action name, for the three
     * rights-affecting columns. A map rather than three literals scattered
     * through update()/assignManager() so the two write paths for `manager_id`
     * can never drift onto two different action names (a filter on
     * `user.manager_changed` that silently missed the registration path would
     * be worse than no filter). Names follow the existing
     * `user.bank_account_updated` / `agent_approval.approved` vocabulary and
     * are exactly the ones §4.3 suggested.
     */
    private const RIGHTS_AUDIT_ACTIONS = [
        'role' => 'user.role_changed',
        'is_team_leader' => 'user.team_leader_changed',
        'manager_id' => 'user.manager_changed',
    ];

    /**
     * 2026-09-18 — what `source` says on the Super-Admin audit rows this
     * class can write. Its counterpart is the string
     * CreateSuperAdminCommand records, `artisan admin:create-super`.
     */
    private const SUPER_ADMIN_UI_SOURCE = 'ui:จัดการผู้ใช้ระบบ';

    public function __construct(private readonly MatrixCommissionService $matrixCommissionService) {}

    /**
     * TASK-183 §4.1 — creating a user IS a permissions event (§6: "record
     * every action that affects ... permissions"). It hands somebody a role, a
     * company and, from that moment, the ability to act inside a tenant; that
     * it happened, and who did it, has to be recoverable.
     *
     * Transaction for the same reason update() has one (see its BUG FIX note):
     * an audit write that throws after the user row committed would leave a
     * real account in the database with no trail and a 500 on the Admin's
     * screen.
     *
     * §4.2 — the audited payload is BUILT EXPLICITLY by auditableRightsFields(),
     * never `$data`. $data carries the temporary password StoreUserRequest
     * accepted, and dumping it into new_values would put a plaintext credential
     * into the one table that is read by more people, for longer, than the row
     * it describes.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): User
    {
        /*
         * 2026-09-17 — a supplier's login belongs to a supplier, not a tenant.
         *
         * Without this branch the line below would have forced `company_id` on
         * a Company Partner, which is the exact conflation the whole supplier
         * rework exists to undo: one column meaning "the tenant this person
         * works for" in fifty places and "the supplier this person works for"
         * in one. StoreUserRequest prohibits `company_id` on this path and
         * requires `supplier_id`; this keeps the service honest about it
         * rather than trusting every future caller to pass the right shape.
         */
        /*
         * 2026-09-18 — and a platform owner belongs to NEITHER.
         *
         * Same shape as the partner branch above and the same reason: the
         * column would be a claim no query agrees with. StoreUserRequest
         * prohibits `company_id` on this path; this makes the service say so
         * too rather than trusting every future caller.
         */
        $isNewSuperAdmin = ($data['role'] ?? null) === UserRole::SuperAdmin->value;

        if ($isNewSuperAdmin) {
            $data['company_id'] = null;
            $data['supplier_id'] = null;
        } elseif (($data['role'] ?? null) === UserRole::CompanyPartner->value) {
            $data['company_id'] = null;
        } else {
            $data['company_id'] = $actor->isSuperAdmin() ? $data['company_id'] : $actor->company_id;
            $data['supplier_id'] = null;
        }

        return DB::transaction(function () use ($data, $actor, $isNewSuperAdmin) {
            $user = User::create($data);

            if ($isNewSuperAdmin) {
                $this->openTheLoginGatesForSuperAdmin($user);
            }

            /*
             * 2026-09-18 — a Super Admin's creation gets its OWN action name,
             * the one `artisan admin:create-super` already writes. One event,
             * one name, whichever door it came through: an auditor asking
             * "who has ever been made a platform owner, and by whom" must not
             * have to know the answer is split across two vocabularies
             * because the second door was added later.
             *
             * `source` says which door, for the same reason the command
             * records its own — the two mean very different things about how
             * the actor was authenticated (SSH to the host vs. a browser
             * session), and the command's rows carry a null actor while
             * these carry a real one.
             */
            $this->writeAudit(
                $isNewSuperAdmin ? 'user.super_admin_created' : 'user.created',
                $user,
                $actor,
                null,
                $isNewSuperAdmin
                    ? [...$this->auditableRightsFields($user), 'company_id' => null, 'source' => self::SUPER_ADMIN_UI_SOURCE]
                    : $this->auditableRightsFields($user),
            );

            return $user;
        });
    }

    /**
     * 2026-09-18 — the columns a new or newly-promoted Super Admin needs
     * before they can actually sign in, lifted from CreateSuperAdminCommand
     * (which learned them the hard way — see the bug note in its handle()).
     *
     * `forceFill`, because `email_verified_at` is deliberately NOT in
     * User::$fillable: an HTTP request must never be able to mark an address
     * verified, and mass assignment would drop the column IN SILENCE,
     * leaving an account that reports "created" and cannot log in.
     *
     * Strictly, LoginGateService returns early for anyone who is not an
     * agent, so a Super Admin passes every gate today regardless. This is
     * set anyway for the case that motivated it in the command: a row
     * RAISED to the role keeps whatever approval state it had as an agent,
     * and a later demotion hands it back. Leaving a pending or unverified
     * stamp on the row would make the demotion — not the promotion — the
     * thing that locks somebody out, weeks later, for no visible reason.
     */
    private function openTheLoginGatesForSuperAdmin(User $user): void
    {
        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'agent_approval_status' => AgentApprovalStatus::Approved,
        ])->save();
    }

    /**
     * TASK-044 Phase A adds $actor (Company Admin or Super Admin
     * performing this edit — distinct from $target, unlike
     * UserProfileService's self-service equivalent) so bank field
     * changes can be audit-logged with "who did this" (Section 6).
     * BR-6 tenant scoping for WHICH agents an Admin may reach here is
     * already enforced by UserPolicy::update()/view() (Company Admin
     * limited to their own company_id) before this method ever runs —
     * unchanged by this addition.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $target, array $data, User $actor): User
    {
        /*
         * The house account accepts a new display name and nothing else.
         * `role` would take it out of every list that hides it; `manager_id`
         * would put the company under one of its own agents; the rest are
         * fields of a person this row is not.
         */
        if ($target->isCommissionHouseAccount()) {
            $forbidden = array_diff(array_keys($data), ['name']);

            if ($forbidden !== []) {
                throw ValidationException::withMessages([
                    'user' => 'บัญชีบริษัทแก้ได้เฉพาะชื่อที่แสดง — ปิดใช้งานได้ที่ขั้นตอนที่ 4 ของหน้าตั้งค่าแนะนำ',
                ]);
            }
        }

        if (array_key_exists('manager_id', $data)) {
            $this->assertValidManager($target, $data['manager_id']);
        }

        /*
         * 2026-09-18 — THE TWO ENDS OF THE PLATFORM-OWNER ROLE.
         *
         * Both directions have to move `company_id` with the role, and both
         * would be wrong in the same way if they did not:
         *
         *   PROMOTION clears it. A Super Admin scoped to one company is a
         *   contradiction — TenantScope reads the ROLE and leaves them
         *   unscoped, so the column would sit there claiming a tenant no
         *   query honours (CreateSuperAdminCommand::promote() clears it for
         *   exactly this reason).
         *
         *   DEMOTION sets it, from the company UpdateUserRequest requires on
         *   that one path. Leaving it null would produce a company_admin
         *   whom fail-closed TenantScope filters with `1 = 0`: signs in,
         *   sees an empty system, is told nothing.
         *
         * Read off `$target` BEFORE the write, because afterwards there is
         * no way to tell a promotion from an edit to somebody who was
         * already a Super Admin.
         */
        $promotingToSuperAdmin = ($data['role'] ?? null) === UserRole::SuperAdmin->value && ! $target->isSuperAdmin();

        if ($promotingToSuperAdmin) {
            $data['company_id'] = null;
            $data['supplier_id'] = null;
        }

        // BUG FIX (2026-07-23) — $target->update() and the AuditLog::create()
        // below used to run un-wrapped: a real production incident showed
        // that if AuditLog::create() throws AFTER $target->update() already
        // committed (e.g. the JsonEncodingException that motivated this
        // fix, see User::maskBankAccountNumber()'s docblock), the request
        // still 500s but the underlying write had already gone through —
        // the Admin sees "save failed" while the data silently changed
        // with NO audit trail (a worse outcome than either succeeding or
        // failing cleanly). DB::transaction() makes the data write and its
        // audit log entry atomic: either both persist or neither does,
        // same pattern this class's own moveToCompany() already uses.
        $target = DB::transaction(function () use ($target, $data, $actor, $promotingToSuperAdmin) {
            $oldBankValues = $this->maskedBankFields($target);
            $oldIdDocument = $this->maskedIdDocumentFields($target);
            // TASK-183 §4.1 — snapshot BEFORE the write, same shape and same
            // reason as the two lines above it.
            $oldRights = $this->auditableRightsFields($target);
            // 2026-09-18 — not a "right" in auditableRightsFields' sense (it
            // is not something one grants), but a role change to or from
            // super_admin moves it, and a row saying "became a Super Admin"
            // without saying which company they left is half the event.
            // Snapshotted here for the same reason as the three lines above:
            // after the write it is gone.
            $oldCompanyId = $target->company_id;

            $target->update($data);

            if ($promotingToSuperAdmin) {
                $this->openTheLoginGatesForSuperAdmin($target);
            }

            // Section 6 Audit Log rule — bank fields are money-adjacent
            // (payout destination) same as the self-service path
            // (UserProfileService::updateBankAccount()). Only fires when an
            // Admin actually touched one of the 3 bank columns — every other
            // field this endpoint can edit (name/email/role/manager_id) is
            // NOT newly audit-logged here; that's pre-existing behavior,
            // out of this task's scope.
            if ($target->wasChanged(['bank_name', 'bank_account_number', 'bank_account_holder_name'])) {
                AuditLog::create([
                    'company_id' => $target->company_id,
                    'actor_user_id' => $actor->id,
                    'action' => 'user.bank_account_updated',
                    'auditable_type' => User::class,
                    'auditable_id' => $target->id,
                    'old_values' => $oldBankValues,
                    'new_values' => $this->maskedBankFields($target),
                    'ip_address' => request()?->ip(),
                ]);
            }

            // TASK-059 — same Section 6 rule for national_id (PDPA):
            // masked-only in the audit trail, never the plaintext, same
            // as bank_account_number above.
            //
            // TASK-122 — id_document_type is audited alongside it, under the
            // SAME action name. Two reasons it is not a separate entry:
            // "this agent's document changed from a Thai ID ending 0708 to a
            // passport ending 4567" is ONE event to a human reading the
            // trail, and splitting it would make the two halves separately
            // deletable/filterable. And a type change on its own is a real
            // identity change even when the digits are untouched — it
            // re-derives the blind index and changes what the number means —
            // so it must trigger the entry by itself, hence both columns in
            // the wasChanged() check.
            if ($target->wasChanged(['national_id', 'id_document_type'])) {
                AuditLog::create([
                    'company_id' => $target->company_id,
                    'actor_user_id' => $actor->id,
                    'action' => 'user.national_id_updated',
                    'auditable_type' => User::class,
                    'auditable_id' => $target->id,
                    'old_values' => $oldIdDocument,
                    'new_values' => $this->maskedIdDocumentFields($target),
                    'ip_address' => request()?->ip(),
                ]);
            }

            /*
             * TASK-183 §4.1/§4.3 — the three RIGHTS-AFFECTING columns this
             * endpoint can edit. The comment on the bank block above used to
             * end "every other field this endpoint can edit (name/email/role/
             * manager_id) is NOT newly audit-logged here; that's pre-existing
             * behavior, out of this task's scope." That is what this closes:
             * `role` (agent <-> company_admin), `is_team_leader` (ADR-025 §1 —
             * the most permission-like write in the system, it decides who may
             * recruit) and `manager_id` (which decides who may approve whom via
             * LeaderRecruitScope, and on a Matrix company where the money
             * flows) are all §6 "actions that affect permissions".
             *
             * `name` and `email` are still deliberately NOT audited: they
             * identify the person, they do not grant them anything. Widening
             * this to every column would turn the trail into a change feed and
             * bury the rows that matter.
             *
             * THREE SEPARATE ROWS, one per column that actually changed — the
             * opposite of the national_id/id_document_type pair above, and for
             * a stated reason: those two are one identity document described by
             * two columns, so splitting them would make half an event
             * separately filterable. These three are three independent grants
             * that happen to be reachable through one form. "This agent became
             * a Company Admin" and "this agent was moved under a different
             * manager" are two events to a human reading the trail, they have
             * two names in §4.3, and each must be findable by its own action.
             */
            foreach ($this->auditableRightsFields($target) as $column => $newValue) {
                if ($oldRights[$column] === $newValue) {
                    continue;
                }

                /*
                 * 2026-09-18 — a role change that crosses the super_admin
                 * line is named for what it IS, and carries the company it
                 * moved.
                 *
                 * `user.role_changed` is the right name for agent ↔
                 * company_admin ↔ voucher_staff: one company's staff being
                 * rearranged. Handing somebody the platform, or taking it
                 * back, is not that event, and burying it under the same
                 * action would mean the only way to find it is to read every
                 * role change ever made and check the values. The two names
                 * used here are the ones `artisan admin:create-super`
                 * already writes.
                 */
                $isSuperAdminGrant = $column === 'role' && $newValue === UserRole::SuperAdmin->value;
                $isSuperAdminRevoke = $column === 'role' && $oldRights['role'] === UserRole::SuperAdmin->value;

                $this->writeAudit(
                    match (true) {
                        $isSuperAdminGrant => 'user.super_admin_granted',
                        $isSuperAdminRevoke => 'user.super_admin_revoked',
                        default => self::RIGHTS_AUDIT_ACTIONS[$column],
                    },
                    $target,
                    $actor,
                    $isSuperAdminGrant || $isSuperAdminRevoke
                        ? [$column => $oldRights[$column], 'company_id' => $oldCompanyId]
                        : [$column => $oldRights[$column]],
                    $isSuperAdminGrant || $isSuperAdminRevoke
                        ? [$column => $newValue, 'company_id' => $target->company_id, 'source' => self::SUPER_ADMIN_UI_SOURCE]
                        : [$column => $newValue],
                );

                /*
                 * TASK-238 — a ROLE change is the one of these three that
                 * changes what the holder may DO, so their live tokens must
                 * stop working now rather than at the next 12-hour expiry.
                 * Demotion is the dangerous direction and the reason this is
                 * here; promotion revokes too, because a token minted under
                 * the old role is a token whose abilities nobody re-checked.
                 *
                 * Not for is_team_leader / manager_id: those change what a
                 * person SEES within rights they already hold, and logging
                 * somebody out for a reporting-line edit would be noise.
                 */
                if ($column === 'role') {
                    $this->revokeApiTokens($target, 'role changed');
                }
            }

            return $target;
        });

        if (array_key_exists('manager_id', $data)) {
            $this->placeInMatrixIfApplicable($target, $data['manager_id']);
        }

        return $target;
    }

    /**
     * ADR-025 §6 / TASK-114 — the GUARDED "give this user a manager"
     * routine, extracted so the public registration path can reuse it
     * verbatim instead of writing `manager_id` straight into
     * `User::create()`.
     *
     * Why extraction rather than a second implementation: a recruit
     * arriving through a team leader's invite link must end up in exactly
     * the same state as one an Admin assigned by hand through
     * `PUT /users/{user}`. Writing the column directly would skip BOTH
     * halves of that contract —
     *   1. assertValidManager()'s same-company + no-self + no-cycle checks
     *      (BR-6: an Eloquent FK cannot express "same tenant"), and
     *   2. the Matrix placement below, which is the ONLY way an agent ever
     *      enters a Matrix-plan company's tree (ADR-011/TASK-030). A recruit
     *      silently missing from that tree earns and pays nothing, and the
     *      omission is invisible until commission runs.
     * TASK-114's acceptance criteria call that parity out explicitly, so
     * the two paths deliberately share ONE routine — if this ever grows a
     * third rule, both callers inherit it for free.
     *
     * TASK-183 §4.1 — NOW AUDIT-LOGGED, and the note that used to sit here
     * ("deliberately NOT audit-logged ... manager_id has never been audited on
     * the Admin path. Adding it for registration only would make the two paths
     * differ again") is what this closes. Both paths write
     * `user.manager_changed` now: the Admin path from update()'s own loop, this
     * path from here, so the parity argument in this docblock still holds.
     *
     * $actor IS NULLABLE, AND ONLY HERE. Every other audit write in this class
     * has a real Admin behind it. This method's other caller is
     * RegistrationService::registerViaRecruitLink(), which runs on a PUBLIC,
     * unauthenticated request: nobody is acting, the recruit is placing
     * themselves under the leader whose link they used. A null actor_user_id
     * (the column is nullable for exactly this "system/self-service" case) is
     * the honest record of that. Naming the recruit as their own actor would
     * read as "this person assigned themselves a manager", and naming the
     * inviter would attribute a decision they did not make at that moment.
     *
     * @param  User|null  $actor  The Admin performing the change, or null when
     *                            this is self-registration through a recruit
     *                            link (see above).
     *
     * NOTE for the caller: this method WRITES. Callers that need the write
     * to be atomic with something else (registration's used_count increment)
     * must already be inside their own DB::transaction() — this method
     * deliberately does not open one, so it can compose.
     */
    public function assignManager(User $target, ?int $managerId, ?User $actor = null): User
    {
        $this->assertValidManager($target, $managerId);

        $oldManagerId = $target->manager_id;

        $target->update(['manager_id' => $managerId]);

        if ($target->wasChanged('manager_id')) {
            $this->writeAudit(
                self::RIGHTS_AUDIT_ACTIONS['manager_id'],
                $target,
                $actor,
                ['manager_id' => $oldManagerId],
                ['manager_id' => $managerId],
            );
        }

        $this->placeInMatrixIfApplicable($target, $managerId);

        return $target;
    }

    /**
     * ADR-011/TASK-030 — this is the ONLY place a Matrix placement is
     * ever created: reusing TASK-025's existing "assign a manager"
     * workflow (the same Manage Agents dropdown) as the sponsor
     * signal, rather than building a separate placement UI/endpoint
     * no one asked for. Only fires when the COMPANY's default plan
     * type is Matrix — placement is a company-wide tree structure
     * (like binary_leg/manager_id), not something that makes sense
     * to key off a single product's override (TASK-027). Silently
     * no-ops (via MatrixCommissionService::place()'s own idempotency
     * check) if the agent is already placed.
     *
     * TASK-114 note: place() can throw a ValidationException (no Matrix
     * settings configured for the company / sponsor not yet in the tree).
     * That was always true on the Admin path; registration now inherits it,
     * which means a misconfigured Matrix company rejects a recruit-link
     * signup with a 422 rather than quietly creating an unplaced agent.
     * That is the deliberate reading of "parity with the admin path" —
     * flagged here because it is a behaviour ag-lead may want to soften
     * later, not something to silently work around.
     */
    private function placeInMatrixIfApplicable(User $target, ?int $managerId): void
    {
        if ($managerId === null || $target->company?->commission_plan_type !== CommissionPlanType::Matrix) {
            return;
        }

        $sponsor = User::withoutGlobalScopes()->find($managerId);

        if ($sponsor) {
            $this->matrixCommissionService->place($target, $sponsor);
        }
    }

    /**
     * TASK-025 / BR-6: manager_id must belong to the same company (an
     * Eloquent FK constraint can't express "same tenant") and must
     * never create a cycle (A manages B manages A) — an Eloquent FK
     * constraint can't express that either, so both are guarded here
     * rather than in the FormRequest (Section 7 — business logic never
     * lives in a Request/Controller). Uses withoutGlobalScopes() to
     * look up the candidate manager regardless of the acting user's own
     * TenantScope, specifically so a cross-company manager_id is
     * rejected with a clear message instead of silently 404ing via
     * route-model-binding-style scoping.
     */
    private function assertValidManager(User $target, ?int $managerId): void
    {
        if ($managerId === null) {
            return;
        }

        if ($managerId === $target->id) {
            throw ValidationException::withMessages(['manager_id' => 'An agent cannot be their own manager.']);
        }

        /*
         * 2026-09-15 — the house account is the ROOT and stays there. Giving
         * the company a manager would put one of its own agents above it in
         * the payout walk, which pays that agent an override on every sale in
         * the company and is not a configuration anybody would mean to make.
         */
        if ($target->isCommissionHouseAccount()) {
            throw ValidationException::withMessages([
                'manager_id' => 'บัญชีบริษัทอยู่ยอดสุดของสายงานเสมอ ตั้งหัวหน้าให้ไม่ได้',
            ]);
        }

        $manager = User::withoutGlobalScopes()->find($managerId);

        if (! $manager || $manager->company_id !== $target->company_id) {
            throw ValidationException::withMessages(['manager_id' => 'The selected manager must belong to the same company.']);
        }

        // Walk upward from the candidate manager — if $target is ever
        // reached, assigning this manager would create a cycle. Capped
        // depth as a defensive circuit breaker only (TASK-025's design
        // has no real depth limit) in case of any pre-existing bad data.
        $cursor = $manager;
        $depth = 0;
        while ($cursor?->manager_id !== null && $depth < 100) {
            if ($cursor->manager_id === $target->id) {
                throw ValidationException::withMessages(['manager_id' => 'This assignment would create a management cycle.']);
            }

            $cursor = User::withoutGlobalScopes()->find($cursor->manager_id);
            $depth++;
        }
    }

    /**
     * TASK-183 §4.1/§4.2 — an Admin setting somebody else's password is a
     * credential event and gets a row.
     *
     * !! THE ROW RECORDS THAT A RESET HAPPENED, AND BY WHOM. IT RECORDS NO
     * PASSWORD MATERIAL — NOT THE PLAINTEXT AND NOT THE HASH. !!
     *
     * old_values and new_values are BOTH null on purpose, and that is not an
     * oversight to be "fixed" later by dropping the value in:
     *   * The plaintext is obviously forbidden.
     *   * The bcrypt hash is not a safe substitute either. It is an offline
     *     crackable artefact, and audit_logs is readable by every Company
     *     Admin through GET /audit-logs (AuditLogController) — a strictly
     *     wider audience than the `users` row it came from, which is exactly
     *     the argument the national_id masking already makes.
     *   * A masked form ("****") would carry no information at all while
     *     implying the column is stored there.
     * Everything the trail needs is already in the row: WHAT happened
     * (`action`), TO WHOM (auditable_type/id), BY WHOM (actor_user_id), WHEN
     * (created_at) and FROM WHERE (ip_address). PasswordResetAuditTest asserts
     * that neither the submitted password nor the resulting hash appears
     * anywhere in the serialized row.
     */
    /**
     * 2026-09-15 — the company's own seat in its hierarchy is not a person,
     * and every control a person gets is refused on it.
     *
     * One helper rather than four copied conditions, because the list of
     * things it must refuse will grow: anything that can move, rename,
     * re-home, deactivate or hand somebody a credential for this row is a way
     * to take the company out of its own payout chain, or into somebody's
     * hands. The row exists only so `CommissionService` has an id to pay.
     *
     * The one door left open is DISPLAY NAME, which is the company's own
     * label on its own seat and changes nothing about where money goes. It is
     * allowed through `update()` below and nowhere else.
     *
     * Turning the seat on and off is CommissionHouseAccountService's job, not
     * this class's — it has to move every agent's manager_id in the same
     * breath, which nothing here does.
     */
    private function assertNotHouseAccount(User $target, string $what): void
    {
        if ($target->isCommissionHouseAccount()) {
            throw ValidationException::withMessages([
                'user' => "บัญชีบริษัท{$what}ไม่ได้ — ปิดใช้งานได้ที่ขั้นตอนที่ 4 ของหน้าตั้งค่าแนะนำ",
            ]);
        }
    }

    public function resetPassword(User $target, string $newPassword, User $actor): User
    {
        // A working credential for this row is a login that can reach the
        // agent portal and ask for the company's own margin. There is no
        // legitimate reason anybody signs in as it.
        $this->assertNotHouseAccount($target, 'ตั้งรหัสผ่าน');

        return DB::transaction(function () use ($target, $newPassword, $actor) {
            $target->update(['password' => $newPassword]);
            $this->revokeApiTokens($target, 'password reset by an admin');

            $this->writeAudit('user.password_reset_by_admin', $target, $actor, null, null);

            return $target;
        });
    }

    /**
     * TASK-183 §4.1 — deactivation withdraws every right the account had, so
     * it is a permissions event under §6 even though it writes one column.
     *
     * old/new_values name `deleted_at` rather than an invented "is_active"
     * key, because that IS the column this operation moves — a reader of the
     * trail can go straight to it.
     *
     * ── TWO ACTIONS, ONE OPERATION (2026-09-08) ──
     *
     * Removing a sign-up that never completed and switching off a trading
     * agent write the identical column, but they are not the same event, and
     * "why did this account go away" is most of what an audit trail is for.
     * So the row is labelled `user.applicant_removed` or `user.deactivated`
     * accordingly.
     *
     * Which one it was is decided HERE, from the target's own state at the
     * moment of deletion — never from a parameter the caller supplies. A
     * client that could name its own audit action could name the wrong one.
     */
    public function deactivate(User $target, User $actor): void
    {
        // Soft-deleting the seat would leave every agent pointing at a
        // deleted manager and stop the company earning, silently.
        $this->assertNotHouseAccount($target, 'ปิดการใช้งานจากหน้านี้');

        DB::transaction(function () use ($target, $actor) {
            $action = $target->isUnconfirmedApplicant()
                ? 'user.applicant_removed'
                : 'user.deactivated';

            $target->delete(); // SoftDeletes — see UserPolicy/TASK-009 design notes.
            $this->revokeApiTokens($target, 'account deactivated');

            $this->writeAudit(
                $action,
                $target,
                $actor,
                ['deleted_at' => null],
                ['deleted_at' => $target->deleted_at?->toIso8601String()],
            );
        });
    }

    /** TASK-183 §4.1 — the mirror of deactivate(): restoring hands every right back. */
    public function restore(User $target, User $actor): User
    {
        return DB::transaction(function () use ($target, $actor) {
            $deletedAt = $target->deleted_at?->toIso8601String();

            $target->restore();

            $this->writeAudit(
                'user.restored',
                $target,
                $actor,
                ['deleted_at' => $deletedAt],
                ['deleted_at' => null],
            );

            return $target;
        });
    }

    /**
     * Phase 11 — Super-Admin-only (UserPolicy::move()). Historical
     * commission_ledger/xp_ledger/audit rows all carry their OWN
     * independent company_id column captured at write time (BR-4/BR-5),
     * never derived from the user's current company — so moving a user
     * here does NOT retroactively rewrite any of their past earnings or
     * activity history to the new company. Only the user row itself
     * (and therefore what they can see/do FROM NOW ON, via TenantScope)
     * changes. Every move is audit-logged (Section 6 — "record every
     * action that affects ... permissions").
     */
    public function moveToCompany(User $target, int $newCompanyId, User $actor): User
    {
        // It belongs to ONE company by definition — the one pointing at it.
        $this->assertNotHouseAccount($target, 'ย้ายบริษัท');

        return DB::transaction(function () use ($target, $newCompanyId, $actor) {
            $oldCompanyId = $target->company_id;

            AuditLog::create([
                'company_id' => $oldCompanyId,
                'actor_user_id' => $actor->id,
                'action' => 'move_to_company',
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'old_values' => ['company_id' => $oldCompanyId],
                'new_values' => ['company_id' => $newCompanyId],
                'ip_address' => request()?->ip(),
            ]);

            $target->update(['company_id' => $newCompanyId]);
            $this->revokeApiTokens($target, 'moved to another company');

            return $target;
        });
    }

    /**
     * TASK-044 Phase A — same masked shape as
     * UserProfileService::maskedBankFields() (kept as a private copy
     * rather than a shared trait/base class — the two Services don't
     * otherwise share code, and User::maskBankAccountNumber() is
     * already the real single source of truth for the masking rule
     * itself; this just assembles the 3-key array for an AuditLog row).
     *
     * @return array{bank_name: ?string, bank_account_number: ?string, bank_account_holder_name: ?string}
     */
    /**
     * TASK-122 — the identity-document pair as it may be written to an
     * AuditLog: the TYPE in full, the NUMBER masked to its last 4 characters
     * and never in plaintext (Section 6 / PDPA — an audit trail is read by
     * more people, for longer, than the record it describes; putting the raw
     * document there would undo the encryption on the column itself).
     *
     * @return array{national_id_masked: ?string, id_document_type: ?string}
     */
    private function maskedIdDocumentFields(User $user): array
    {
        return [
            'national_id_masked' => $user->maskedNationalId(),
            'id_document_type' => $user->id_document_type?->value,
        ];
    }

    private function maskedBankFields(User $user): array
    {
        return [
            'bank_name' => $user->bank_name,
            'bank_account_number' => $user->maskedBankAccountNumber(),
            'bank_account_holder_name' => $user->bank_account_holder_name,
        ];
    }

    /**
     * TASK-183 §4.1 — the three columns that decide what a user MAY DO,
     * flattened to scalars so they compare with === and serialize into
     * old_values/new_values as plain JSON.
     *
     * The keys are deliberately identical to RIGHTS_AUDIT_ACTIONS's, so
     * update()'s loop can pair a changed column with its action name without a
     * second lookup table to keep in sync.
     *
     * `role` is unwrapped from its UserRole enum here rather than left as a
     * backed enum: an enum instance would encode as its value anyway, but only
     * after a round-trip through the JSON cast, and the strict comparison in
     * update() needs both sides to already be the same primitive type.
     *
     * NOTHING SENSITIVE IS EVER IN HERE (§4.2). No password, no
     * national_id, no bank column — those have their own masked writers above,
     * and this method must never grow into "a snapshot of the user".
     *
     * @return array{role: ?string, is_team_leader: bool, manager_id: ?int}
     */
    private function auditableRightsFields(User $user): array
    {
        return [
            'role' => $user->role?->value,
            'is_team_leader' => (bool) $user->is_team_leader,
            'manager_id' => $user->manager_id,
        ];
    }

    /**
     * TASK-183 §4.1 — the ONE audit writer for this class's new rows, so
     * company_id / auditable_type / ip_address are assembled identically
     * everywhere and a new call site cannot forget one.
     *
     * Shape is byte-for-byte the one `user.bank_account_updated` already uses
     * (UserService.php's own bank block) — this extends a working pattern, it
     * does not invent a second one. The pre-existing bank/national_id blocks
     * are left inline rather than routed through here on purpose: they are not
     * this task's code, and rewriting them would put an unrelated,
     * money-adjacent audit path into an urgent security fix's diff.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    /**
     * TASK-238 — WHEN SOMEBODY'S RIGHTS CHANGE, THEIR TOKENS DIE.
     *
     * ── THE GAP THIS CLOSES (2026-09-05) ──
     *
     * The agent portal authenticates with a bearer token that lives for 12
     * hours (AuthController). Deactivating an account, changing its role,
     * resetting its password or moving it to another company all wrote the
     * new state to the database and touched nothing else — so the person
     * just locked out kept a working token, on a system that moves money,
     * for as long as twelve hours. "Deactivated" has to mean deactivated
     * NOW, not at the next expiry.
     *
     * ── WHAT IS NOT TOUCHED ──
     *
     * The admin console logs in with a cookie session, whose
     * currentAccessToken() is Sanctum's TransientToken and never appears in
     * this table at all. Deleting rows here cannot log an admin out of their
     * own console — see AuthController::logout for the same distinction.
     *
     * The reason is a parameter so the audit trail can say WHY a token
     * disappeared; a revocation with no cause is the kind of entry that
     * makes a reviewer suspect a breach.
     */
    private function revokeApiTokens(User $target, string $reason): void
    {
        $revoked = $target->tokens()->delete();

        // No row when there was nothing to revoke: an audit trail that
        // records non-events is one people stop reading.
        if ($revoked === 0) {
            return;
        }

        $this->writeAudit('user.api_tokens_revoked', $target, null, null, [
            'revoked_count' => $revoked,
            'reason' => $reason,
        ]);
    }

    private function writeAudit(string $action, User $target, ?User $actor, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::create([
            'company_id' => $target->company_id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
        ]);
    }
}
