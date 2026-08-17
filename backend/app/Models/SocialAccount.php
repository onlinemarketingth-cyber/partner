<?php

namespace App\Models;

use App\Enums\RegistrationChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-005 (TASK-017 design note) — links one User to one social
 * provider identity (provider + provider_user_id). Deliberately no
 * TenantScope/company_id here — see the migration's own docblock.
 */
class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
    ];

    protected function casts(): array
    {
        return [
            // Reuses RegistrationChannel rather than a near-duplicate
            // enum — 'email' is a valid enum case but never actually
            // stored here in practice (only Facebook/Line/Google create
            // a social_accounts row; email registrations never do),
            // enforced by RegistrationService, not by this cast.
            'provider' => RegistrationChannel::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
