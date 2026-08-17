<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\AgentReadyForApproval;
use App\Models\User;
use App\Notifications\NewAgentRegistrationNotification;
use Illuminate\Support\Facades\Notification;

// TASK-020 (ADR-005 decision 3) — the sole consumer of
// AgentReadyForApproval today (registered in AppServiceProvider::boot(),
// Laravel 12 has no default EventServiceProvider — see the same pattern
// used for SocialiteWasCalled once TASK-019 lands).
//
// Notifies *every* Company Admin of the registrant's company (there can
// be more than one) — not just the oldest/first, and not whoever
// created the invite code (that information isn't even reliably known —
// an invite code's created_by_user_id is nullable). Flag if a narrower
// targeting rule is ever wanted (TASK-020's own design note).
//
// withoutGlobalScopes() is deliberate, not a bypass of BR-6: this is a
// system-level lookup ("who administers this company"), not a
// user-facing query — the registrant's own company_id is already fixed
// and correct (set server-side in RegistrationService), so there is
// nothing to leak across tenants here.
class NotifyCompanyAdminsOfPendingAgent
{
    public function handle(AgentReadyForApproval $event): void
    {
        $admins = User::withoutGlobalScopes()
            ->where('company_id', $event->user->company_id)
            ->where('role', UserRole::CompanyAdmin->value)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new NewAgentRegistrationNotification($event->user));
    }
}
