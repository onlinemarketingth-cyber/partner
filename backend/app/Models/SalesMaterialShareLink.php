<?php

namespace App\Models;

use App\Models\Concerns\HasTrackedLink;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-007 — signed, time-limited, revocable public link for one
 * sales material. See ADR-007 Decision 3 for why this is a deliberate,
 * narrow exception to CLAUDE.md §5 rule 6 ("never a public URL").
 * `token` is looked up WITHOUT TenantScope by the public download
 * route (SalesMaterialShareLinkController::show(), no authenticated
 * user exists there to scope by) — this model still carries TenantScope
 * for every OTHER (authenticated, Policy-checked) access path, e.g. the
 * agent's own "my share links" list.
 */
class SalesMaterialShareLink extends Model
{
    use HasFactory;
    use HasTrackedLink;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'sales_material_id',
        'created_by_user_id',
        'token',
        'expires_at',
        'revoked_at',
        'view_count',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<ProductSalesMaterial, $this> */
    public function salesMaterial(): BelongsTo
    {
        return $this->belongsTo(ProductSalesMaterial::class, 'sales_material_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
