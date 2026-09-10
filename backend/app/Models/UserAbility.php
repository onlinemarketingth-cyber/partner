<?php

namespace App\Models;

use App\Enums\Ability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-032 §2.2/Phase 3 — one ability granted to one person by name.
 *
 * 2026-09-10, built for the first ability that needed it (human: "ที่ได้สิทธิ์
 * ในการตัดได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงานคนละหน้าที่กัน"). Redeeming a
 * voucher stopped being something a Company Admin holds by virtue of being
 * one; it is now given, by somebody, on purpose.
 *
 * ── NO TenantScope, DELIBERATELY ──
 *
 * A grant has no company of its own — it belongs to a USER, and that user
 * already carries the tenant boundary. Adding a `company_id` here would be a
 * second copy of the same fact, free to disagree with the first. Every read
 * goes through `$user->abilityGrants()`, so a grant can only ever be reached
 * via a user the caller was already allowed to load (BR-6 holds at the user,
 * where it is enforced once).
 *
 * ── ADDITIVE ONLY ──
 *
 * A row here means "also may". There is no row shape for "may not":
 * PermissionResolver's role table stays the readable answer to what a role
 * holds, and a grant can never quietly subtract from it.
 */
class UserAbility extends Model
{
    protected $table = 'user_abilities';

    protected $fillable = [
        'user_id',
        'ability',
        'granted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'ability' => Ability::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who gave it. Null means the 2026-09-10 migration's backfill — the
     * system, not a person; naming a Super Admin there would put somebody's
     * name against a decision they never made.
     *
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
