<?php

namespace App\Models;

use App\Enums\AccountDeletionRequestStatus;
use App\Enums\AccountDeletionResolution;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MOB-12 (2026-10-02) — an agent's request to have their account deleted.
 *
 * Tenant-scoped like every other business row (CLAUDE.md §5 rule 2): a
 * Company Admin's query, and their route-model binding, only ever see their
 * own company's requests, so another company's id 404s before any Policy runs.
 *
 * `pending_user_id` is deliberately NOT fillable. It exists only to give the
 * database a "one pending request per user" constraint (see the migration),
 * and it is derived here from `status` on every save, so no caller — and no
 * request body — can ever set it out of step with the status it mirrors.
 */
class AccountDeletionRequest extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::saving(function (self $request): void {
            $request->pending_user_id = $request->status === AccountDeletionRequestStatus::Pending
                ? $request->user_id
                : null;
        });
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'status',
        'reason',
        'requested_at',
        'decided_at',
        'decided_by',
        'decision_note',
        'resolution',
        'forfeited_commission_satang',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountDeletionRequestStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'resolution' => AccountDeletionResolution::class,
            'forfeited_commission_satang' => 'integer',
        ];
    }

    /**
     * The person who asked. withTrashed(): once approved the account is
     * soft-deleted (the same state a deactivated account is in), and the
     * request must still be able to say whose it was.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isPending(): bool
    {
        return $this->status === AccountDeletionRequestStatus::Pending;
    }
}
