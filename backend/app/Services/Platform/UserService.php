<?php

namespace App\Services\Platform;

use App\Enums\CommissionPlanType;
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

    public function __construct(private readonly MatrixCommissionService $matrixCommissionService)
    {
    }

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
        $data['company_id'] = $actor->isSuperAdmin() ? $data['company_id'] : $actor->company_id;

        return DB::transaction(function () use ($data, $actor) {
            $user = User::create($data);

            $this->writeAudit('user.created', $user, $actor, null, $this->auditableRightsFields($user));

            return $user;
        });
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
        if (array_key_exists('manager_id', $data)) {
            $this->assertValidManager($target, $data['manager_id']);
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
        $target = DB::transaction(function () use ($target, $data, $actor) {
            $oldBankValues = $this->maskedBankFields($target);
            $oldIdDocument = $this->maskedIdDocumentFields($target);
            // TASK-183 §4.1 — snapshot BEFORE the write, same shape and same
            // reason as the two lines above it.
            $oldRights = $this->auditableRightsFields($target);

            $target->update($data);

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

                $this->writeAudit(
                    self::RIGHTS_AUDIT_ACTIONS[$column],
                    $target,
                    $actor,
                    [$column => $oldRights[$column]],
                    [$column => $newValue],
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
    public function resetPassword(User $target, string $newPassword, User $actor): User
    {
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
     */
    public function deactivate(User $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor) {
            $target->delete(); // SoftDeletes — see UserPolicy/TASK-009 design notes.
            $this->revokeApiTokens($target, 'account deactivated');

            $this->writeAudit(
                'user.deactivated',
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
