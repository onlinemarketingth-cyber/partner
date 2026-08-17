<?php

namespace App\Policies;

use App\Models\ClientActivity;
use App\Models\User;

// TASK-015 — a NEW dedicated Policy (not reused from ClientPolicy):
// ownership rules genuinely differ. viewAny/create mirror the parent
// Client's own visibility (ClientPolicy::view — checked against the
// Client in the Controller, since viewAny has no model instance to
// check against). update is narrower than "anyone who can see the
// client" — only the original logged_by_user_id may correct their own
// entry. delete is narrower still — Company Admin/Super Admin only,
// same asymmetry as ClientPolicy::delete (an agent can't erase their
// own contact history).
class ClientActivityPolicy
{
    public function view(User $user, ClientActivity $activity): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $activity->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $activity->client->referring_agent_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true; // gated against the parent Client's own visibility in the Controller (must be able to view the client to log activity on it)
    }

    public function update(User $user, ClientActivity $activity): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $activity->company_id) {
            return false;
        }

        // Deliberately NOT the same reach as view() — only the person
        // who logged the entry may correct it, not any Company Admin
        // and not a colleague who can also see the client.
        return $activity->logged_by_user_id === $user->id;
    }

    public function delete(User $user, ClientActivity $activity): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $activity->company_id);
    }
}
