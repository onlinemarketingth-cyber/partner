<?php

namespace App\Policies;

use App\Models\TrackedLink;
use App\Models\User;

/**
 * TASK-234 — who may read link statistics.
 *
 * EVERY authenticated role may list links, but `viewAny` is not the real
 * gate here — the controller narrows an Agent's query to their own rows
 * before it ever runs. An Agent seeing their own numbers is the entire
 * point of the agent dashboard; an Agent seeing a colleague's would tell
 * them who is selling what and how well, which is the company's business
 * and not theirs.
 *
 * `view` is where a single link is fetched by id, and that is where the
 * ownership check has to be exact, because an id is guessable in a way a
 * filtered list is not.
 *
 * NOBODY DELETES A TRACKED LINK. There is no `delete` method and the
 * controller exposes no route for one. Revoking is the operation — a
 * deleted link would take its visit rows with it (cascade) and NULL the
 * attribution on the orders and agents it produced, which is the exact
 * failure TASK-236 is fixing on the affiliate side.
 */
class TrackedLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TrackedLink $link): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ((int) $user->company_id !== (int) $link->company_id) {
            return false;
        }

        if ($user->isCompanyAdmin()) {
            return true;
        }

        // An Agent sees the links they created and nothing else.
        return (int) $link->created_by_user_id === (int) $user->id;
    }

    public function update(User $user, TrackedLink $link): bool
    {
        return $this->view($user, $link);
    }
}
