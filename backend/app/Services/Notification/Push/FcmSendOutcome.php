<?php

namespace App\Services\Notification\Push;

/**
 * 2026-10-02 — MOB-11. What one FCM send meant for the token it went to.
 *
 * Three outcomes, not a bool, because "failed" has two very different
 * consequences: a token FCM says is gone must be deleted (or it is retried
 * on every notification forever), while a timeout or a 5xx says nothing
 * about the token and must leave it alone.
 */
enum FcmSendOutcome
{
    case Sent;
    case TokenGone;
    case Failed;
}
