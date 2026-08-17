<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-056 Sprint P1 — a public, agent-attributed "showcase" link for one
 * product (media gallery + all its sales materials), distinct from
 * AffiliateLink (lead capture) and SalesMaterialShareLink (single file).
 * `token` is the public lookup key for GET /public/product-shares/{token}
 * (PublicProductShareController — resolved WITHOUT TenantScope, same
 * pattern as SalesMaterialShareLink's own comment); every other access
 * path (mint/list/revoke) stays authenticated + tenant-scoped.
 */
class ProductShareLink extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'agent_id',
        'product_id',
        'token',
        'view_count',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'view_count' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
