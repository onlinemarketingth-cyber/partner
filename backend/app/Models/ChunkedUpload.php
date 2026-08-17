<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-094 — one in-flight chunked upload. See the migration for why the
 * token is server-issued and why bytes are counted here.
 *
 * TenantScope (BR-6 / §5) means a token belonging to another company is
 * simply not found, so the chunk endpoint answers 404 rather than leaking
 * that the id exists at all.
 */
class ChunkedUpload extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'token',
        'original_filename',
        'mime_type',
        'declared_bytes',
        'received_bytes',
        'max_bytes',
        'part_path',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'declared_bytes' => 'integer',
            'received_bytes' => 'integer',
            'max_bytes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
