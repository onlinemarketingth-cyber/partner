<?php

namespace App\Models;

use App\Enums\ClientActivityType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-015 — Client Activity/Communication Log. A record of a single
 * call/chat/meeting/other contact with a Client, independent of the
 * Referral pipeline's stage-change log (Section 4.3). follow_up_at is
 * an optional reminder date; follow_up_notified_at is owned by
 * TASK-016 (never written here).
 */
class ClientActivity extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'client_id',
        'logged_by_user_id',
        'type',
        'summary',
        'occurred_at',
        'follow_up_at',
        'follow_up_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClientActivityType::class,
            'occurred_at' => 'datetime',
            'follow_up_at' => 'datetime',
            'follow_up_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }
}
