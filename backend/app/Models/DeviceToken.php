<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 2026-10-02 — MOB-10. A phone that may receive push notifications for one
 * user. The token is an FCM registration token on both iOS and Android
 * (@capacitor-firebase/messaging), see App\Enums\DevicePlatform.
 *
 * Tenant-scoped (§5 rule 2) like every other business model. The places
 * that read it without that scope each say why where they do it:
 *
 *   - DeviceTokenService::register() — the token identifies the PHYSICAL
 *     device, and a phone can change hands across companies. Finding the
 *     previous owner's row is the only way to move it; nothing from that row
 *     is ever returned to the caller.
 *   - DeviceTokenService::unregister() — narrowed to user_id = caller, which
 *     is stricter than the tenant filter and survives a company move.
 *   - PushNotificationService — runs from a queue worker (no authenticated
 *     user) or inside a request whose actor is not the recipient, and
 *     narrows by user_id explicitly.
 *
 * The token is never part of an API response (DeviceTokenResource omits it):
 * the client already has it, and nobody else has any business seeing it.
 */
class DeviceToken extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'platform',
        'token',
        'token_hash',
        'app_version',
        'last_seen_at',
    ];

    protected $hidden = [
        'token',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The unique key for a token (see the migration for why the token text
     * itself cannot carry the UNIQUE index).
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
