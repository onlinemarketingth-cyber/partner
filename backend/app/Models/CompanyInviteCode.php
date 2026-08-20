<?php

namespace App\Models;

use App\Models\Concerns\HasTrackedLink;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-005 (decision 6) — a Company's self-registration invite code.
 * Several may be simultaneously valid per company; every code has a
 * mandatory expiry. Deliberately NOT TenantScope'd the same way most
 * business tables are — it's managed exclusively by Super Admin
 * (TASK-022), never queried through an Agent/Company Admin's own
 * tenant-scoped session, so the extra scope would only get in the way.
 */
class CompanyInviteCode extends Model
{
    use HasFactory;
    use HasTrackedLink;

    protected $fillable = [
        'company_id',
        'code',
        'label',
        'max_uses',
        'used_count',
        'expires_at',
        'revoked_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The one, single definition of "is this code usable right now" —
     * every consumer (TASK-018's resolver, TASK-022's listing UI) must
     * call this rather than re-checking `revoked_at`/`expires_at`
     * itself, so the rule can never drift out of sync between callers.
     */
    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        // TASK-233 — NULL now means "never expires", not "misconfigured".
        // Before this the check demanded `expires_at !== null`, because the
        // column could not be null; a printed signup link has to be able to
        // outlive any date somebody would have picked for it.
        if ($this->expires_at !== null && ! $this->expires_at->isFuture()) {
            return false;
        }

        // NULL max_uses = unlimited, the same meaning `agent_invite_links`
        // gives it (ADR-025 §3). Written as an explicit null check rather
        // than a comparison so that `0` — a code deliberately set to allow
        // nobody — behaves as the exhausted code it is, instead of falling
        // through as "no limit".
        return $this->max_uses === null || $this->used_count < $this->max_uses;
    }
}
