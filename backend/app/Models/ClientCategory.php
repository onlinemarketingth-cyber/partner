<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-056 Sprint P2 — client segmentation (BR-7: admin-editable config,
 * seeded with a starter set per company, never hardcoded in logic). Peer
 * domain to Brand/ProductCategory — same "browsable by anyone in the
 * company, managed by Company Admin/Super Admin only" shape.
 */
class ClientCategory extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
