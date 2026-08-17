<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

// ADR-005 — fired once a self-registered Agent becomes reviewable: for
// the email path, that's the moment email verification completes
// (TASK-018); for the social path, immediately on first login (TASK-019,
// subject to its LINE-email-fallback design note). TASK-020 owns the
// actual listener that sends NewAgentRegistrationNotification to the
// company's Admin(s) — this event is the clean hook point between the
// two tasks, so TASK-018/019 never need to know TASK-020 exists.
class AgentReadyForApproval
{
    use Dispatchable;

    public function __construct(public readonly User $user)
    {
    }
}
