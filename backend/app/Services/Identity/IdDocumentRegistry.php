<?php

namespace App\Services\Identity;

use App\Enums\IdDocumentType;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Is this identity document already on somebody's account in this company?"
 *
 * ── WHY THIS IS ITS OWN CLASS (2026-08-27) ──
 *
 * The check used to be a private method on RegistrationService, because
 * registration was the only place a person could ever supply the number.
 * That stopped being true when the document was removed from the sign-up
 * form and moved to the profile: there are now TWO write paths for the same
 * value, and a duplicate guard that only one of them runs is not a guard at
 * all — it is a guard with a documented way around it.
 *
 * So the rule lives here once and both callers use it. RegistrationService
 * keeps its own method as a thin delegation so its long docblock (which
 * explains the race window this check does NOT close) stays attached to the
 * call site it was written about.
 */
class IdDocumentRegistry
{
    /**
     * @param  int|null  $ignoreUserId  the account being edited, when the caller
     *                                  is an UPDATE rather than a registration.
     *                                  Without it, saving your own unchanged
     *                                  number would find your own row and
     *                                  report you as a duplicate of yourself.
     *
     * @throws ValidationException  keyed on `national_id`, so both the
     *                              registration form and the profile form can
     *                              render it inline on the field the person
     *                              can actually change.
     */
    public function assertNotAlreadyUsed(
        int $companyId,
        string $document,
        IdDocumentType $type,
        ?int $ignoreUserId = null,
    ): void {
        $hash = User::hashNationalId($document, $type);

        // Nothing to compare against — a value that normalizes to nothing
        // cannot be a duplicate, and App\Rules\IdDocument has already
        // rejected it as a shape. Returning quietly here keeps this class
        // from inventing a second opinion about validity.
        if ($hash === null) {
            return;
        }

        // withoutGlobalScopes([TenantScope::class]): the scope would narrow
        // this to the ACTOR's company rather than the one passed in, and a
        // duplicate check that quietly searched the wrong tenant would pass
        // every time. The explicit ->where('company_id') below is the single
        // source of truth for the scope.
        //
        // NOT a bare withoutGlobalScopes(): that would also drop
        // SoftDeletingScope implicitly, and whether deleted rows count is a
        // decision, not a side effect — hence the explicit ->withTrashed().
        // A deactivated agent's document still belongs to that person.
        $query = User::withoutGlobalScopes([TenantScope::class])
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('national_id_hash', $hash);

        if ($ignoreUserId !== null) {
            $query->whereKeyNot($ignoreUserId);
        }

        if ($query->exists()) {
            // The message names no one: telling the caller WHOSE account
            // already holds this number would leak the existence and
            // identity of another agent (CLAUDE.md §6).
            throw ValidationException::withMessages([
                'national_id' => 'เลขที่เอกสารนี้ถูกใช้สมัครสมาชิกในบริษัทนี้แล้ว',
            ]);
        }
    }
}
