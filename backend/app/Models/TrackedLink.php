<?php

namespace App\Models;

use App\Enums\TrackedLinkGroup;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * TASK-232 — one public link, in any of the seven groups.
 *
 * TenantScope applies here like every other business model. The public
 * resolver deliberately steps around it with `withoutGlobalScopes()` —
 * a stranger opening a link has no company of their own, which is the
 * same reason SalesMaterialShareLink and ProductShareLink resolve that
 * way. Every OTHER path (mint, list, revoke, stats) stays scoped, so the
 * exception is one method wide.
 */
class TrackedLink extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'code',
        'group',
        'target_type',
        'target_id',
        'label',
        'created_by_user_id',
        'expires_at',
        'revoked_at',
        'click_count',
        'unique_click_count',
        'conversion_count',
        'first_clicked_at',
        'last_clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'group' => TrackedLinkGroup::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'click_count' => 'integer',
            'unique_click_count' => 'integer',
            'conversion_count' => 'integer',
            'first_clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
        ];
    }

    /**
     * Whether this link may still be opened.
     *
     * ONLY the link's own state. Whether the THING BEHIND IT is still
     * usable — a revoked product share, an order that has already been
     * paid, a team invite that hit its cap — stays the target's own
     * question, answered by the target's own `isUsable()`. Copying those
     * rules up here would mean two places that must agree forever about
     * when a product share is dead, and they would not.
     */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<TrackedLinkVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(TrackedLinkVisit::class);
    }

    /**
     * The path a browser should be sent to, e.g. `/p/R4TB8WM2XK`.
     *
     * A PATH, not a full URL, and the frontend origin is prepended at the
     * edge (Resource layer) instead of being baked in. The app is served
     * from more than one host already — agent portal, admin app, API — and
     * a stored absolute URL is a stale URL the first time one of them
     * moves. TASK-231 was a whole afternoon lost to exactly that class of
     * assumption about where things are served from.
     */
    public function publicPath(): string
    {
        return $this->group->publicPathFor($this->code);
    }

    /**
     * The full URL, from the origin that actually serves this group.
     *
     * Most groups open a page in the agent portal. A sales-material share
     * has never had a page there — its URL is an API endpoint that streams
     * the file — so building it against the portal's origin produced a
     * link to a route that does not exist. UAT caught it; see
     * TrackedLinkGroup::resolvesOnFrontend().
     */
    public function publicUrl(): string
    {
        $origin = $this->group->resolvesOnFrontend()
            ? rtrim((string) config('services.agent_portal.frontend_url'), '/')
            : rtrim((string) config('app.url'), '/');

        return $origin.$this->publicPath();
    }
}
