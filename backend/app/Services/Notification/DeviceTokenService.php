<?php

namespace App\Services\Notification;

use App\Enums\DevicePlatform;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 2026-10-02 — MOB-10. The one place a phone is attached to, or detached
 * from, a user for push notifications.
 *
 * ── THE TOKEN BELONGS TO THE DEVICE, NOT TO THE USER ──
 *
 * Owner decision: a phone can change hands. When user B signs in on a phone
 * that user A used to be signed in on, the app registers the same FCM token
 * again, and from that moment the phone is B's: A must stop receiving pushes
 * on it, or A's notifications would light up on B's lock screen.
 *
 * So register() looks the token up WITHOUT TenantScope — the previous owner
 * may be in another company — and moves the row to the caller. This is the
 * one deliberate crossing of BR-6 in this feature, and it reveals nothing:
 * the previous owner's row is deleted and a fresh one is created for the
 * caller, so neither its id nor its timestamps reach the response.
 *
 * unregister() never crosses: it is narrowed to the caller's own user_id —
 * strictly narrower than TenantScope — and it reports nothing about whether a
 * row existed, so it cannot be used to probe for somebody else's token.
 *
 * ── WHY unregister() DROPS TenantScope ANYWAY ──
 *
 * A user can be moved to another company (MoveUserCompany). Their phone's
 * row still carries the OLD company_id, so under TenantScope (which filters
 * by the caller's NEW company) the logout call would find nothing, the row
 * would survive, and the phone would keep buzzing after logout. Narrowing by
 * user_id = caller is the stronger guarantee of the two, so the tenant
 * filter is removed rather than left to hide the caller's own row. register()
 * re-stamps company_id for the same reason.
 */
class DeviceTokenService
{
    public function register(User $user, DevicePlatform $platform, string $token, ?string $appVersion): DeviceToken
    {
        try {
            return $this->upsert($user, $platform, $token, $appVersion);
        } catch (UniqueConstraintViolationException) {
            // Two registrations of the same token racing (the app retries on a
            // flaky network). The loser's insert hit the unique hash; by now
            // the winner's row exists, so one more pass finds and updates it.
            return $this->upsert($user, $platform, $token, $appVersion);
        }
    }

    public function unregister(User $user, string $token): void
    {
        DeviceToken::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('token_hash', DeviceToken::hashToken($token))
            ->delete();
    }

    private function upsert(User $user, DevicePlatform $platform, string $token, ?string $appVersion): DeviceToken
    {
        $hash = DeviceToken::hashToken($token);

        return DB::transaction(function () use ($user, $platform, $token, $appVersion, $hash) {
            // Unscoped on purpose — see the class docblock.
            $existing = DeviceToken::withoutGlobalScopes()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->user_id !== $user->id) {
                $existing->delete();
                $existing = null;
            }

            $attributes = [
                'company_id' => $user->company_id,
                'platform' => $platform,
                'app_version' => $appVersion,
                'last_seen_at' => now(),
            ];

            if ($existing !== null) {
                $existing->fill($attributes)->save();

                return $existing;
            }

            return DeviceToken::create($attributes + [
                'user_id' => $user->id,
                'token' => $token,
                'token_hash' => $hash,
            ]);
        });
    }
}
