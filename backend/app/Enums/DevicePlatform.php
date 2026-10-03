<?php

namespace App\Enums;

/**
 * 2026-10-02 — MOB-10 / MOB-13. The two mobile platforms the Capacitor app
 * ships on.
 *
 * One enum for both features on purpose: a device token is registered FROM a
 * platform, and the version policy is set PER platform, and the two must
 * never disagree about what the platforms are called. A third platform (a
 * web-push PWA, say) is a code change here, and both features pick it up.
 *
 * Note that the platform does NOT decide how a push is sent: the app's
 * plugin is `@capacitor-firebase/messaging`, so the token is always an FCM
 * registration token and both platforms go through FCM HTTP v1. Kept for
 * support ("which phones does this agent have?") and for the APNs block of
 * the FCM payload, which FCM simply ignores on Android.
 */
enum DevicePlatform: string
{
    case Ios = 'ios';
    case Android = 'android';

    public function label(): string
    {
        return match ($this) {
            self::Ios => 'iOS',
            self::Android => 'Android',
        };
    }
}
