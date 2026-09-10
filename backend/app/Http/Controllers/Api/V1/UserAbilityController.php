<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateUserAbilitiesRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserAbility;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-10 (human: "การกระจายสิทธิ์ให้ Company Admin และ Admin ที่ได้สิทธิ์
 * ในการตัดได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงานคนละหน้าที่กัน").
 *
 * Grants that are given to a PERSON, on top of what their role holds
 * (ADR-032 §2.2/Phase 3).
 *
 * ── WHY THE GRANTABLE SET IS A CONSTANT AND NOT "any ability" ──
 *
 * Everything in the Ability catalogue is grantable in principle, and almost
 * none of it should be reachable from a screen yet. A general endpoint would
 * let a Company Admin hand themselves `settings.payment_gateway.update` — the
 * one ADR-027 withholds from them precisely because it names the bank account
 * their company's revenue lands in.
 *
 * So the set below is the closed list of abilities a screen may hand out
 * today. Widening it is a code change plus a review, which is the same shape
 * the Ability enum itself chose (§7 / ADR-032 §2.2).
 *
 * ── EVERY CHANGE IS AUDITED ──
 *
 * A grant is the answer to "who let them do that", asked months later,
 * usually after something went wrong. `granted_by_user_id` on the row says
 * who, and the audit log says when and what changed — both, because the row
 * survives only while the grant does and a revocation would otherwise leave
 * no trace at all.
 */
class UserAbilityController extends Controller
{
    /**
     * The abilities a screen may hand out today.
     *
     * @var list<Ability>
     */
    public const GRANTABLE = [
        Ability::VoucherRedeem,
    ];

    /** PUT /users/{user}/abilities — the complete set this person is granted. */
    public function update(UpdateUserAbilitiesRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        /*
         * The request carries the WHOLE set, not a diff. A toggle that sent
         * "add X" and "remove Y" separately would let two admins working at
         * once leave a person holding a combination neither of them chose;
         * replacing the set makes the last write the answer, and makes the
         * screen's state and the stored state the same shape.
         */
        $wanted = array_map(
            fn (string $value) => Ability::from($value),
            $request->validated('abilities', []),
        );

        /*
         * `->pluck('ability')` on an Eloquent builder applies the model's
         * cast, so this arrives as Ability instances. It is mapped back to
         * strings — and sorted — because the comparison below decides whether
         * an audit row is written: enums against strings would differ every
         * time, and so would the same set in a different order.
         */
        $before = $user->abilityGrants()
            ->whereIn('ability', array_map(fn (Ability $a) => $a->value, self::GRANTABLE))
            ->pluck('ability')
            ->map(fn (Ability $ability) => $ability->value)
            ->sort()
            ->values()
            ->all();

        DB::transaction(function () use ($user, $wanted, $request) {
            // Only ever touches the GRANTABLE window: a grant made by a
            // migration or a future screen for some other ability is not
            // this endpoint's to remove.
            $user->abilityGrants()
                ->whereIn('ability', array_map(fn (Ability $a) => $a->value, self::GRANTABLE))
                ->whereNotIn('ability', array_map(fn (Ability $a) => $a->value, $wanted))
                ->delete();

            foreach ($wanted as $ability) {
                UserAbility::firstOrCreate(
                    ['user_id' => $user->id, 'ability' => $ability->value],
                    ['granted_by_user_id' => $request->user()->id],
                );
            }
        });

        $after = array_map(fn (Ability $a) => $a->value, $wanted);
        sort($after);
        $after = array_values(array_unique($after));

        if ($before !== $after) {
            AuditLog::create([
                'company_id' => $user->company_id,
                'actor_user_id' => $request->user()->id,
                'action' => 'user.abilities_updated',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'old_values' => ['abilities' => $before],
                'new_values' => ['abilities' => $after],
                'ip_address' => $request->ip(),
            ]);
        }

        return new UserResource($user->fresh()->load('abilityGrants'));
    }
}
