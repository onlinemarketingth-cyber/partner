<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-217 — TenantScope, plus rows that belong to NOBODY.
 *
 * For a table where `company_id` is nullable and NULL means "platform-wide,
 * every tenant may use it" (today: `theme_presets` — see that table's
 * migration for why colours are shareable when business data is not), plain
 * TenantScope is wrong in a way that is easy to miss: `where company_id = 5`
 * silently excludes NULL, so a Company Admin would never see a shared row
 * at all — not in the list, and not through route-model binding either,
 * which would turn every shared preset into a 404 for them.
 *
 * This scope keeps every TenantScope guarantee for OWNED rows (company A
 * still cannot see company B's) and additionally lets the ownerless ones
 * through:
 *
 *     where (company_id = :own OR company_id is null)
 *
 * The OR lives inside its own nested closure on purpose — appended to a
 * builder that already carries `where x and y`, an un-nested `orWhereNull`
 * would bind to the whole preceding chain and widen far more than
 * intended. That is a tenancy leak, not a formatting preference.
 *
 * WHAT THIS SCOPE DOES NOT DO: decide who may WRITE a shared row. Reading a
 * platform palette and editing one are different questions — the second is
 * ThemePresetPolicy's, and it answers Super Admin only.
 */
class SharedOrTenantScope extends TenantScope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = self::actor();

        if (self::seesEverything($user)) {
            return;
        }

        if (! isset($user->company_id)) {
            return;
        }

        $column = $model->getTable().'.company_id';

        $builder->where(function (Builder $query) use ($column, $user) {
            $query->where($column, $user->company_id)
                ->orWhereNull($column);
        });
    }
}
