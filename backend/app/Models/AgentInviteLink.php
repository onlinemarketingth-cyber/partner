<?php

namespace App\Models;

use App\Models\Concerns\HasTrackedLink;
use App\Models\Scopes\TenantScope;
use Database\Factories\AgentInviteLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-112 / ADR-025 §3 — a team leader's shareable "join my team" link.
 * A recruit opens `/register?ref={token}`, and registering through it sets
 * their company_id, manager_id and recruited_via_agent_link_id server-side
 * (TASK-114) — none of which is ever taken from the request body.
 *
 * Minting requires `users.is_team_leader = true` (ADR-025 §1); that check
 * lives in TASK-113's AgentInviteLinkService, not here and not in the
 * Policy — same split as ProductShareLink, where the Policy answers "whose
 * row is this" and the Service answers "are you allowed to create one at
 * all".
 *
 * TenantScope'd like every other business table (BR-6). The one path that
 * must resolve a link WITHOUT tenant context is TASK-114's public
 * `resolve-ref-token` endpoint, which is unauthenticated — TenantScope is
 * a no-op for guests (see TenantScope::apply()), so that lookup works by
 * token alone, exactly as PublicProductShareController already does.
 */
class AgentInviteLink extends Model
{
    /** @use HasFactory<AgentInviteLinkFactory> */
    use HasFactory;

    use HasTrackedLink;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Section 6 — explicit $fillable, never $guarded = []. `token` is
     * fillable because the minting Service generates it (Str::random(64))
     * and passes it in; no Form Request ever accepts it from a client.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'agent_id',
        'token',
        'label',
        'expires_at',
        'max_uses',
        'used_count',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
        ];
    }

    /**
     * The SINGLE source of truth for "may this link be consumed right
     * now". Every consumer — TASK-113's listing Resource (`is_usable`),
     * TASK-114's public resolver and its registration path, TASK-116's UI
     * status pill — must call this rather than re-checking revoked_at /
     * expires_at / max_uses itself, so the rule can never drift out of
     * sync between callers (same reasoning as
     * CompanyInviteCode::isValid()).
     *
     * Three conditions, ANDed (ADR-025 §3):
     *   1. not revoked;
     *   2. expires_at is null (never expires) OR still in the future;
     *   3. max_uses is null (unlimited) OR used_count is still below it.
     * A NULL on either limit means UNLIMITED — that is the human's chosen
     * meaning of "leave it blank", not a missing value to be defaulted
     * (BR-7). A link created with both limits null is therefore usable
     * indefinitely, by design.
     *
     * !! DO NOT "OPTIMISE" THIS OUT OF THE TRANSACTION !!
     * TASK-114 calls this a SECOND time INSIDE a DB::transaction() after
     * `lockForUpdate()`ing the link row, immediately before
     * increment('used_count') — ADR-025 §4. That re-check looks redundant
     * next to the one the request already did, and it is not: it is the
     * only thing stopping two concurrent recruits from both passing a
     * `max_uses = 1` link. Reading it once outside the lock and trusting
     * the result is precisely the defect TASK-118 test case 2 exists to
     * catch.
     *
     * Deliberately NOT checked here: the state of the INVITER (soft
     * deleted, is_team_leader revoked, moved company). Those are
     * properties of a different row and would make this method issue a
     * query, which every caller above assumes it does not.
     *
     * RESOLVED (TASK-114 item 5, ag-lead ruling): such a link IS unusable,
     * but the check lives in
     * `RegistrationService::resolveActiveInviter()`, NOT here — precisely
     * to keep this method a pure in-memory predicate over three columns.
     * Adding a relation load here would turn AgentInviteLinkResource's
     * `is_usable` field into a hidden N+1 on every list render. The
     * contract is therefore: **isUsable() owns the link's own state, and
     * every CONSUMPTION path must additionally call
     * resolveActiveInviter()**. That pairing is enforced in the two places
     * that matter — `resolveRefToken()` (public resolver + Form Request)
     * and the in-lock re-check inside `registerViaRecruitLink()`. See that
     * method's docblock for the full reasoning.
     */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && ! $this->expires_at->isFuture()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> The team leader who minted (and owns) this link. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return HasMany<User, $this> Users who registered through this link
     *                              (ADR-025 §6 attribution). Note this is NOT the same set as the
     *                              inviter's directReports(): manager_id can be re-pointed by an Admin
     *                              later, while this attribution is immutable history.
     */
    public function recruits(): HasMany
    {
        return $this->hasMany(User::class, 'recruited_via_agent_link_id');
    }
}
