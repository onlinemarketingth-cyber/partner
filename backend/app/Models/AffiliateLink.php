<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-011 Section 4 (TASK-032/033) — a trackable link an Agent mints for
 * themselves (optionally scoped to one product). `token` is the public,
 * unguessable lookup key (Str::random(64) — see AffiliateLinkService)
 * used by the three PUBLIC routes: GET /l/{token} (click redirect),
 * GET /api/v1/public/affiliate-leads/{token} (landing-page context —
 * TASK-033 gap-fill, see PublicAffiliateLinkContextResource for the
 * exact field boundary), and POST to the same path (lead submission).
 * A valid token discloses company name, agent name, and product
 * name/price (never company_id/agent_id/the AffiliateLink id itself,
 * and never click/conversion data) — everything else about this model
 * stays behind Sanctum auth.
 */
class AffiliateLink extends Model
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
    ];

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

    /** @return BelongsTo<Product, $this> Nullable — a general (non-product-specific) link. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<AffiliateLinkClick, $this> */
    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateLinkClick::class, 'link_id');
    }

    /** @return HasMany<Referral, $this> Attributed conversions only (referrals.affiliate_link_id set). */
    public function attributedReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'affiliate_link_id');
    }
}
