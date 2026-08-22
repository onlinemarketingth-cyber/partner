<?php

namespace App\Models;

use App\Enums\NotificationType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-053 / ADR-016 — a single notification to one user. Tenant-scoped
 * (§5) via the shared TenantScope; the Controller additionally narrows to
 * the authenticated user (a user only ever sees their OWN notifications).
 */
class Notification extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'title',
        'body',
        'link',
        'data',
        'read_at',
        // Email delivery intent. `emailed_at` and `email_attempts` are
        // deliberately NOT fillable: only NotificationMailer may claim or
        // release a row, and it does so with forceFill. Mass-assigning
        // "already sent" from anywhere else would silently cancel an email.
        'email_due_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'datetime',
            'email_due_at' => 'datetime',
            'emailed_at' => 'datetime',
            'email_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
