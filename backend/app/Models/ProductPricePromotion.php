<?php

namespace App\Models;

use App\Enums\PromotionStatus;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product-view IA item 2.3b — customer-facing sale price for a window.
 * Display-only in this prototype — see migration comment for the
 * flagged BR-2/BR-4 question on wiring this into real commission calc.
 */
class ProductPricePromotion extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'product_id',
        'discounted_price_satang',
        'note',
        'status',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discounted_price_satang' => 'integer',
            'status' => PromotionStatus::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
     * ONE RULE, TWO EXPRESSIONS — KEEP THEM LINE-FOR-LINE IDENTICAL.
     * -------------------------------------------------------------
     * TASK-156 §3. "Currently active" is: status is Active, the window has
     * opened, and it has not closed. It needs both shapes:
     *
     *   scopeCurrentlyActive() — SQL, for filtering a LIST before it is read
     *                            (ProductPricePromotionController::index).
     *   isCurrentlyActive()    — PHP, for deciding about a row already in
     *                            memory (ProductPricingService picks the
     *                            promotion that applies to a sale; the
     *                            Resource exposes `is_currently_active`).
     *
     * Adding the SQL half rather than looping the PHP half over every row is
     * the whole point — index() must not read what it is about to hide. The
     * two are deliberately written as the same three clauses in the same
     * order so a change to one that is not mirrored in the other is visible
     * in review: a second implementation that can drift is exactly the bug
     * class TASK-156 exists to close (see Announcement::scopeVisibleToAgent(),
     * which avoided the problem by having no PHP twin at all — not an option
     * here, ProductPricingService genuinely needs the in-memory predicate).
     *
     * `starts_at`/`ends_at` are DATE columns (see the migration) and both
     * halves compare whole days, so a promotion is live for the entirety of
     * its first and last day. `status` is authoritative and independent of
     * the dates — an Admin can force-End an in-window promotion (see
     * PromotionStatus) and that must win here too.
     */

    /**
     * @param  Builder<ProductPricePromotion>  $query
     * @return Builder<ProductPricePromotion>
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->where('status', PromotionStatus::Active->value)
            ->whereDate('starts_at', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today));
    }

    /** The in-memory twin of scopeCurrentlyActive() — change both or neither. */
    public function isCurrentlyActive(): bool
    {
        if ($this->status !== PromotionStatus::Active) {
            return false;
        }

        $today = now()->toDateString();

        if ($this->starts_at->toDateString() > $today) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->toDateString() >= $today;
    }
}
