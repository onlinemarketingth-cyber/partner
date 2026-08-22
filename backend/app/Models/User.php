<?php

namespace App\Models;

use App\Enums\AgentApprovalStatus;
use App\Enums\ApprovalSource;
use App\Enums\BinaryLeg;
use App\Enums\IdDocumentType;
use App\Enums\RegistrationChannel;
use App\Enums\UserRole;
use App\Models\Scopes\TenantScope;
use App\Notifications\VerifyRegistrationEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\HasApiTokens;

// ERD-001 §2 (rev. 3): "Agent" is not a separate table — it's this same
// User model filtered to role = agent. The relations below (referrals,
// certifications, commissionLedgerEntries, xpLedger, badges) only make
// sense for that role; nothing stops a company_admin/super_admin row
// from having empty collections here, which is expected.
//
// ADR-005 (TASK-018): implements MustVerifyEmail — every user CAN be
// verified, but only self-registered (email-path) Agents actually go
// through the "please verify your email" gate in practice (TASK-021's
// login check only blocks on this for `registered_via = email`
// accounts; every other existing account already has
// `email_verified_at` set by its seeder/factory/admin-creation flow,
// so this newly-enabled contract changes no existing behavior).
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmailTrait, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        // `name` is derived, not directly writable (see migration
        // 2026_07_12_090000 docblock) — kept in sync here so every
        // existing read site (UserResource, LeaderboardController, both
        // frontends' `auth.user.name`, initials() helper, audit logs,
        // etc.) keeps working unchanged after the first_name/last_name
        // split. Seeders use `WithoutModelEvents` and must therefore set
        // `name` explicitly themselves (see DatabaseSeeder).
        static::saving(function (User $user) {
            if ($user->isDirty(['first_name', 'last_name'])) {
                $user->name = trim("{$user->first_name} {$user->last_name}");
            }

            // TASK-059 — keep the blind index in lockstep with
            // national_id, same reasoning as Client::national_id_hash
            // (TASK-049): national_id is 'encrypted' (unsearchable), so
            // every write that touches it re-derives the deterministic
            // HMAC hash the /users search matches on.
            //
            // TASK-122 — id_document_type is now part of that lockstep, on
            // BOTH sides. It is in the isDirty() list because the hash is
            // derived from the type as well as the number (a passport and a
            // Thai ID canonicalise differently), so changing ONLY the type
            // must re-derive the hash or the row silently stops being
            // findable. And it is passed into hashNationalId() below rather
            // than left to a default, so the hash always matches the
            // document as it is stored on this same row.
            if ($user->isDirty(['national_id', 'id_document_type'])) {
                $user->national_id_hash = self::hashNationalId($user->national_id, $user->id_document_type);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'company_id',
        'role',
        // TASK-112 / ADR-025 §1 — admin-granted "may recruit" capability.
        // A FLAG, not a fourth role: `role` above stays `agent`, so no
        // isCompanyAdmin()/isAgent() call site anywhere changes meaning.
        // Writable ONLY through UpdateUserRequest, which prohibits it for
        // any actor who isn't a Company Admin / Super Admin — it is
        // deliberately absent from StoreUserRequest (a brand-new user is
        // never born a leader) and from every self-service Profile
        // request, so an Agent can never grant it to themselves.
        'is_team_leader',
        // TASK-025 / ADR-006 Round 4 — shared by Unilevel + Binary plans.
        'manager_id',
        'binary_leg',
        'avatar_path',
        'background_type',
        'background_config',
        'background_image_path',
        // TASK-044 Phase A — bank payout details, human-confirmed both
        // self-service (UserProfileService) and Admin (UserService) may
        // write these. bank_account_number is additionally cast
        // 'encrypted' below (Section 6 PDPA at-rest encryption); the
        // other two are not sensitive on their own so stay plain.
        'bank_name',
        'bank_account_number',
        'bank_account_holder_name',
        // TASK-059 — Thai national ID (เลขบัตรประชาชน), Admin-entry only
        // for now (Manage Agents create/edit form). Encrypted below;
        // national_id_hash is derived and deliberately NOT fillable (see
        // Client's identical precedent) so it can't be spoofed
        // independently of the encrypted value it mirrors.
        //
        // TASK-122 — this column now holds THE IDENTITY DOCUMENT NUMBER, OF
        // THE TYPE NAMED BY `id_document_type`: a Thai national ID or a
        // passport number. The name is historical (see the migration
        // 2026_08_20_090000 docblock for why it was deliberately not
        // renamed). It is no longer Admin-entry only either — both
        // self-registration paths now require it (ADR-025 §5 / TASK-122).
        'national_id',
        // TASK-122 — which document `national_id` above is. Fillable on the
        // same terms as national_id: written by RegistrationService (from
        // validated public input) and by UserService (Admin). Never derived,
        // never guessed — a null here means "we never captured one", which
        // is true of every row created before this task.
        'id_document_type',
        // ADR-005 — self-registration approval/verification state.
        //
        // TASK-115 NOTE: the three approval-ATTRIBUTION columns added by
        // 2026_08_19_090000 (approved_by_user_id, approved_at,
        // approval_source) are deliberately ABSENT from this list, same
        // "system owns this column" treatment as users.current_rank_id. They
        // are written only by AgentApprovalService via forceFill(), so no
        // Form Request anywhere can ever let a caller nominate their own
        // approver or forge "approved by an admin" (Section 6, mass
        // assignment). Reading them back is UserResource's job.
        'agent_approval_status',
        'approval_rejection_reason',
        'registered_via',
        'registered_via_invite_code_id',
        // TASK-112 / ADR-025 §6 — which recruit link brought this user in.
        // Fillable for exactly the same reason (and with exactly the same
        // caveat) as registered_via_invite_code_id above: TASK-114's
        // RegistrationService sets it inside User::create(). It must be
        // derived SERVER-SIDE from the resolved link, never copied out of
        // the request body — ADR-025 §5 says the same about company_id,
        // and the Form Request there must not expose this key at all.
        'recruited_via_agent_link_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Model-level defaults for columns whose DB default must also hold on an
     * unsaved / freshly-created in-memory instance.
     *
     * `is_team_leader` (TASK-112) has `->default(false)` on the column, so the
     * ROW is always correct — but a model returned by create() carries only the
     * attributes that were passed in, so `$user->is_team_leader` read back
     * NULL until something refreshed it from the database. `! null` and
     * `! false` behave identically, which is why the authorisation checks were
     * right and this stayed invisible; it surfaced as
     * `assertFalse(...) // null given` in AgentInviteLinkTest.
     *
     * That is not only a test problem. A caller doing
     * `$user->is_team_leader === false`, or serialising a just-created user
     * into an API Resource, would have seen null where the database says
     * false — and `is_team_leader` gates who may mint recruit links
     * (ADR-025 §1), so a three-state boolean is the wrong shape to leave lying
     * around in something that decides permissions.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_team_leader' => false,
        /*
         * 2026-08-22 — the column defaults to TRUE in the database, but a
         * DB-level default is not hydrated back onto the model that INSERTed
         * the row. A freshly created User held in memory therefore reads null
         * here, and NotificationService::wantsEmail() would read that as "not
         * enabled" and silently skip the email for every notification fired
         * in the same request that created the user — the approval mail most
         * of all, which is the one an applicant is actually waiting on.
         *
         * Declaring it makes the in-memory object agree with the schema,
         * which is the same reason is_team_leader is listed above.
         */
        'email_notifications_enabled' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            // TASK-112 / ADR-025 §1 — cast so `$user->is_team_leader`
            // is a real bool everywhere (MySQL returns tinyint 1/0,
            // SQLite returns int), never a truthy string.
            'is_team_leader' => 'boolean',
            // 2026-08-22 — the agent's own off switch for notification
            // email. Cast for the same reason is_team_leader is: MySQL
            // returns tinyint, SQLite returns int, and NotificationService
            // branches on it directly.
            'email_notifications_enabled' => 'boolean',
            'background_config' => 'array',
            'agent_approval_status' => AgentApprovalStatus::class,
            // TASK-115 / ADR-025 §7 — WHO approved this registration and
            // through which path. Stored (rather than derived from the
            // approver's current role) because a leader who is later promoted
            // to Company Admin must not retroactively turn every approval
            // they made as a leader into an "admin-approved" one.
            'approved_at' => 'datetime',
            'approval_source' => ApprovalSource::class,
            'registered_via' => RegistrationChannel::class,
            'binary_leg' => BinaryLeg::class,
            // TASK-044 Phase A / Section 6 (PDPA at-rest encryption for
            // sensitive fields) — Laravel transparently encrypts on
            // write and decrypts on read, so every existing PHP call
            // site ($user->bank_account_number) keeps working unchanged;
            // only the Resource/JSON layer needs its own masking on top
            // of this (see UserResource — encryption alone does not
            // stop the full number from being exposed in an API
            // response once decrypted into the array).
            'bank_account_number' => 'encrypted',
            // TASK-059 / Section 6 PDPA — same encrypted-at-rest pattern
            // as Client::national_id (TASK-049).
            'national_id' => 'encrypted',
            // TASK-122 — see IdDocumentType. Cast so every call site gets a
            // real enum (and so an unknown string in a legacy row would blow
            // up loudly on read rather than being silently compared as text).
            'id_document_type' => IdDocumentType::class,
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> TASK-025 — this agent's upline (Unilevel or Binary, both share manager_id). */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return HasMany<User, $this> TASK-025 — agents directly reporting to this manager. */
    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    /** @return HasOne<MatrixPlacement, $this> ADR-011/TASK-030 — this agent's own slot in their company's Matrix tree (null if not placed / company not on Matrix). */
    public function matrixPlacement(): HasOne
    {
        return $this->hasOne(MatrixPlacement::class);
    }

    /**
     * @return BelongsTo<AgentRank, $this> ADR-011/TASK-031 — system-derived
     *                                     (RecalculateAgentRanks), never directly user-writable — deliberately
     *                                     NOT in $fillable, same "system owns this column" treatment as
     *                                     users.current_rank_id's own migration comment.
     */
    public function currentRank(): BelongsTo
    {
        return $this->belongsTo(AgentRank::class, 'current_rank_id');
    }

    /** @return HasMany<AffiliateLink, $this> ADR-011/TASK-032 — this agent's own trackable links. */
    public function affiliateLinks(): HasMany
    {
        return $this->hasMany(AffiliateLink::class, 'agent_id');
    }

    /**
     * @return HasMany<AgentInviteLink, $this> TASK-112/ADR-025 §3 — recruit
     *                                         links this agent has minted. Only ever non-empty for a user with
     *                                         is_team_leader = true (the Service gate in TASK-113), but the
     *                                         relation itself is unconditional: revoking the flag must not
     *                                         retroactively hide the links an admin needs to see and revoke.
     */
    public function agentInviteLinks(): HasMany
    {
        return $this->hasMany(AgentInviteLink::class, 'agent_id');
    }

    /**
     * @return BelongsTo<AgentInviteLink, $this> TASK-112/ADR-025 §6 —
     *                                           reporting only: which leader's link brought this user in. Mirrors
     *                                           registeredViaInviteCode() below. Survives a later manager_id change,
     *                                           which is the whole point of storing it separately from manager_id.
     */
    public function recruitedViaAgentLink(): BelongsTo
    {
        return $this->belongsTo(AgentInviteLink::class, 'recruited_via_agent_link_id');
    }

    /** @return HasMany<Client, $this> Customers this Agent has referred in. */
    public function referredClients(): HasMany
    {
        return $this->hasMany(Client::class, 'referring_agent_id');
    }

    /** @return HasMany<Referral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'agent_id');
    }

    /** @return HasMany<UserCertification, $this> BR-1 gate records. */
    public function certifications(): HasMany
    {
        return $this->hasMany(UserCertification::class);
    }

    /** @return HasMany<ModuleCompletion, $this> */
    public function moduleCompletions(): HasMany
    {
        return $this->hasMany(ModuleCompletion::class);
    }

    /** @return HasMany<CommissionLedger, $this> BR-4 — amounts this Agent earned. */
    public function commissionLedgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedger::class, 'agent_id');
    }

    /** @return HasMany<XpLedger, $this> BR-5 — sum(xp_awarded) is this Agent's total XP. */
    public function xpLedger(): HasMany
    {
        return $this->hasMany(XpLedger::class);
    }

    /** @return HasMany<UserBadge, $this> */
    public function badges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    /** @return HasMany<SocialAccount, $this> ADR-005 — linked OAuth identities. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return BelongsTo<CompanyInviteCode, $this> ADR-005 — reporting only, which code/campaign brought this Agent in. */
    public function registeredViaInviteCode(): BelongsTo
    {
        return $this->belongsTo(CompanyInviteCode::class, 'registered_via_invite_code_id');
    }

    /**
     * @return BelongsTo<User, $this> TASK-115 / ADR-025 §7 — the Company
     *                                Admin, Super Admin or team leader who approved this registration.
     *                                Null for every account that predates 2026_08_19_090000 and for every
     *                                account created directly by an Admin (which is approved by
     *                                construction, with no approval event to attribute). Read together with
     *                                `approval_source`, which is what tells an Admin queue "leader-approved"
     *                                apart from "admin-approved".
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * TASK-115 — "did this person walk in off the street, or did an Admin
     * create them?"
     *
     * This is the discriminator the login gate uses to decide whether email
     * verification is REQUIRED (ADR-005 decision 4 scopes verification to the
     * self-registration path only). Both self-registration routes stamp
     * exactly one of these columns, server-side, at creation:
     *   * invite-code path  -> registered_via_invite_code_id   (ADR-005)
     *   * recruit-link path -> recruited_via_agent_link_id     (ADR-025 §6)
     * and an Admin-created account ("Manage Agents", UserService::create())
     * has neither.
     *
     * WHY NOT just `! hasVerifiedEmail()` for everyone: UserService::create()
     * does not set email_verified_at, so EVERY agent an Admin has ever
     * created through the Manage Agents form is sitting in the database
     * unverified right now. Gating on verification alone would lock all of
     * them out the moment this ships — the exact opposite of TASK-021's
     * strongest acceptance criterion ("no behaviour change whatsoever for
     * any account created via the existing Company-Admin-creates-Agent
     * flow"). An Admin creating an account IS the vetting, for the email as
     * much as for the person (ADR-005's own reasoning for why that path
     * defaults straight to `approved`).
     *
     * WHY NOT `registered_via`: that column defaults to 'email' at the
     * database level, so it says 'email' for Admin-created rows too and
     * cannot distinguish anything.
     *
     * KNOWN EDGE: company_invite_codes uses nullOnDelete, so deleting an
     * invite code blanks registered_via_invite_code_id on the agents it
     * brought in, and they stop being "self-registered" by this test. The
     * consequence is bounded and safe: such a user is still gated by
     * agent_approval_status, and if they are already `approved` a human has
     * already vetted them. Recorded here rather than defended against,
     * because the alternative (a dedicated `is_self_registered` column) is
     * schema for a case that only arises when an Admin hard-deletes a code.
     */
    public function isSelfRegistered(): bool
    {
        return $this->registered_via_invite_code_id !== null
            || $this->recruited_via_agent_link_id !== null;
    }

    /**
     * Overrides MustVerifyEmailTrait's default (Laravel's stock
     * VerifyEmail notification, which links to a route this SPA
     * doesn't have) — sends our own Notification instead, which builds
     * a link into the Agent Portal frontend (TASK-018/021). The
     * public method name/signature is exactly what the trait/contract
     * expect, so every framework internal that calls this (e.g.
     * TASK-021's resend-verification endpoint) keeps working unchanged.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyRegistrationEmailNotification($this));
    }

    /**
     * TASK-183 §3.1 — "may this user's tenant operate?", the ONE question the
     * login gate (§3.2) and the per-request middleware (§3.3) both ask.
     *
     * It does not re-implement the predicate; it resolves this user's tenant
     * and hands the answer to Company::isOperational() (via
     * Company::isOperationalById()), which is the single place
     * `is_active === true && deleted_at === null` is written down.
     *
     * SUPER ADMIN IS EXEMPT, AND THAT IS WHAT THE NULL BRANCH IS FOR. A Super
     * Admin is platform-level and carries `company_id = null` (CLAUDE.md §2,
     * §5 rule 4). There is no tenant to gate them on, and gating them anyway
     * would be actively harmful: the Super Admin is the only role that can
     * REACTIVATE a company, so locking them out of a platform whose companies
     * are deactivated would make the state unrecoverable through the API.
     *
     * The branch is keyed on isSuperAdmin() rather than on `company_id ===
     * null` alone, deliberately. Those are the same set today, but they are not
     * the same CLAIM: "has no tenant" would silently exempt any future
     * tenant-less row — an orphaned agent, a half-migrated import — from the
     * whole control. A non-Super-Admin with a null company_id therefore falls
     * through to isOperationalById(null), which is false. Fail closed: an agent
     * who belongs to no company cannot act on behalf of one.
     */
    public function belongsToOperationalCompany(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return Company::isOperationalById($this->company_id);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === UserRole::CompanyAdmin;
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::Agent;
    }

    /**
     * BR-1 access gate: "An agent must pass the Basic certification
     * before gaining access to SWS Referral submission and selling
     * features." This is the one check every future Policy gating
     * Referral/Pipeline access should call — never re-implement the
     * "does this agent have a passed cert_tiers row" query elsewhere.
     */
    public function hasPassedCertTier(string $tierKey): bool
    {
        return $this->certifications()
            ->whereHas('certTier', fn ($query) => $query->where('key', $tierKey))
            ->exists();
    }

    /**
     * BR-2: "Commission rate depends on the agent's cert tier x the
     * package sold" — when an agent has passed more than one tier, the
     * HIGHEST one is what a commission rate should be looked up
     * against (CertTier.sort_order is the ranking: Basic < Intermediate
     * < High). Returns null if the agent hasn't passed anything yet —
     * callers must handle that (never silently default to a tier).
     */
    public function highestPassedCertTier(): ?CertTier
    {
        $certTierId = $this->certifications()
            ->join('cert_tiers', 'cert_tiers.id', '=', 'user_certifications.cert_tier_id')
            ->orderByDesc('cert_tiers.sort_order')
            ->value('cert_tiers.id');

        return $certTierId ? CertTier::find($certTierId) : null;
    }

    /**
     * TASK-044 Phase A — this Agent's bank_account_number masked to only
     * the last 4 digits (e.g. "****1234"), the single source of truth
     * used by both UserResource (JSON masking) and the audit-log write
     * sites (Section 6 — never log the full number in plaintext either).
     * A static helper (not just an instance accessor) so the same
     * masking can be applied to an arbitrary old/new value string
     * without needing a hydrated model — see self::maskBankAccountNumber().
     */
    public function maskedBankAccountNumber(): ?string
    {
        return self::maskBankAccountNumber($this->bank_account_number);
    }

    public static function maskBankAccountNumber(?string $accountNumber): ?string
    {
        if ($accountNumber === null || $accountNumber === '') {
            return null;
        }

        // BUG FIX (2026-07-23) — this field is free-text (validated only
        // as `sometimes|nullable|string|max:255`, never numeric-only), so
        // it can legitimately contain multi-byte UTF-8 (Thai text typed
        // in during testing/data entry). The original strlen()/substr()
        // here operate on BYTES, not characters: for a multi-byte string
        // substr($str, -4) can slice through the middle of a UTF-8
        // character, producing an invalid byte sequence. That corrupted
        // string then fails json_encode() wherever it's written into an
        // AuditLog's JSON-cast old_values/new_values (Section 6 requires
        // masking it there too) — surfacing as an uncaught
        // JsonEncodingException / HTTP 500 on save, AFTER the underlying
        // $target->update()/$user->save() had already committed (see the
        // DB::transaction() wrap added around every caller of this
        // method, so a masking failure can no longer leave a "user saw
        // 500 but the write actually went through" inconsistency).
        // mb_strlen()/mb_substr() with an explicit 'UTF-8' encoding are
        // character-safe and never split a multi-byte character, while
        // being byte-for-byte identical to the old behavior for
        // ASCII-only input (every digit-only account number in every
        // existing test/seed).
        if (mb_strlen($accountNumber, 'UTF-8') <= 4) {
            return str_repeat('*', mb_strlen($accountNumber, 'UTF-8'));
        }

        return str_repeat('*', mb_strlen($accountNumber, 'UTF-8') - 4).mb_substr($accountNumber, -4, null, 'UTF-8');
    }

    /**
     * TASK-059 — deterministic blind index for exact-match search over the
     * encrypted national_id: normalize, then HMAC-SHA256 keyed by APP_KEY.
     * Returns null for a value that normalizes to nothing, so an agent with
     * no document on file has a null hash (never collides with another).
     *
     * TASK-122 — THE NORMALIZATION IS NOW TYPE-AWARE, AND THAT IS A BUG FIX,
     * NOT A REFINEMENT. The original implementation ran
     * preg_replace('/\D/', '', $value) unconditionally. Once this column can
     * hold a passport number, that means passports "AA1234567" and
     * "ZZ1234567" — two different people — both normalize to "1234567" and
     * therefore hash IDENTICALLY. Everything built on this hash would then
     * treat them as the same document: the duplicate-registration check
     * (RegistrationService) would reject the second person, and the /users
     * search would return the wrong agent. Stripping the letters from an
     * alphanumeric identifier discards most of its entropy.
     *
     * The two branches:
     *   * Thai national ID, AND null/unknown type — digits only, byte for
     *     byte the pre-TASK-122 algorithm. Null is mapped here deliberately:
     *     every row written before this task has a null id_document_type and
     *     a hash derived this way, so this branch MUST NOT CHANGE or all of
     *     them silently stop matching. (A Thai ID is 13 digits by rule, so
     *     digits-only is lossless for it.)
     *   * Passport — upper-cased [A-Z0-9], i.e. spaces and dashes stripped
     *     but letters KEPT and case-folded, so "aa-123 456" and "AA123456"
     *     are one document while "AA123456" and "ZZ123456" are two.
     *
     * @param  IdDocumentType|null  $type  Null means "unknown" and is treated
     *                                     as Thai for backward compatibility
     *                                     — see above.
     */
    public static function hashNationalId(?string $nationalId, ?IdDocumentType $type = null): ?string
    {
        if ($nationalId === null) {
            return null;
        }

        $normalized = $type === IdDocumentType::Passport
            ? preg_replace('/[^A-Z0-9]/', '', strtoupper($nationalId))
            : preg_replace('/\D/', '', $nationalId);

        if ($normalized === '' || $normalized === null) {
            return null;
        }

        return hash_hmac('sha256', $normalized, (string) Config::get('app.key'));
    }

    /**
     * TASK-059 — this agent's national_id masked to only the last 4
     * characters (e.g. "*********1234"), the single source of truth for both
     * UserResource (JSON masking for non-privileged viewers) and any
     * audit-log write. Mirrors Client::maskedNationalId()/maskNationalId().
     *
     * TASK-122 — deliberately left type-agnostic: it already operates on
     * characters (mb_substr), so a passport masks to "*****4567" with no
     * change. Making it type-aware would only serve to leak the document's
     * shape to a viewer who is not allowed to see the document.
     */
    public function maskedNationalId(): ?string
    {
        return self::maskNationalId($this->national_id);
    }

    public static function maskNationalId(?string $nationalId): ?string
    {
        if ($nationalId === null || $nationalId === '') {
            return null;
        }

        $len = mb_strlen($nationalId, 'UTF-8');
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).mb_substr($nationalId, -4, null, 'UTF-8');
    }
}
