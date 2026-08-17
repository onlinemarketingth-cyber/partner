<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\CertTierTargetMode;
use App\Enums\MediaSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Agent-view IA item 1.6 — Admin-authored newsfeed post. company_id
 * nullable = own company or platform-wide default, same "not
 * TenantScope'd, index() narrows manually" shape as Badge/RewardItem.
 *
 * image_path / video_* (human request, 2026-07-23): an announcement can
 * carry one optional image (always a direct upload) and one optional
 * video (upload OR embed link, App\Enums\MediaSourceType — same
 * established shape as ProductMedia/ProductSalesMaterial, ADR-007).
 */
class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'title',
        'content',
        'audience',
        'target_cert_tier_id',
        'target_cert_tier_mode',
        'is_pinned',
        // TASK-080 — modal / inline-banner display switches. Not exclusive:
        // an announcement may be both, either, or neither (neither = it only
        // appears in the news list).
        'show_as_modal',
        'show_as_banner',
        'banner_pages',
        'published_at',
        'expires_at',
        'created_by',
        'image_path',
        'video_source_type',
        'video_path',
        'video_embed_url',
    ];

    protected function casts(): array
    {
        return [
            'audience' => AnnouncementAudience::class,
            'target_cert_tier_mode' => CertTierTargetMode::class,
            'video_source_type' => MediaSourceType::class,
            'is_pinned' => 'boolean',
            'show_as_modal' => 'boolean',
            'show_as_banner' => 'boolean',
            // Plain array, not a cast-to-enum collection: this is a set of
            // page keys the frontend filters on, and Eloquent has no
            // built-in "array of backed enums" cast. Values are validated
            // against AnnouncementBannerPage in the Form Requests.
            'banner_pages' => 'array',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<CertTier, $this> */
    public function targetCertTier(): BelongsTo
    {
        return $this->belongsTo(CertTier::class, 'target_cert_tier_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * TASK-156 — THE AGENT AUDIENCE GATE, IN ONE PLACE.
     *
     * Publication window + audience targeting, exactly as `index()` has always
     * applied it. It lives here rather than inline in the controller because
     * `show()` did NOT apply it: `AnnouncementPolicy::view()` returns true for
     * anyone in the company, so an Agent who knew or guessed an id could read
     * a draft, a scheduled-for-next-month post, an expired one, or one aimed
     * at a cert tier they have not earned — while the list that deliberately
     * hid all four sat one route away.
     *
     * A scope, not a `isVisibleTo(User)` PHP predicate, precisely so the two
     * routes cannot drift again: this is the same SQL, evaluated once. A
     * hand-written PHP re-implementation of the `and_above` sort_order
     * comparison below is exactly the second implementation that would rot.
     *
     * Callers must apply company scoping themselves — this method answers
     * "may this agent see it", not "whose is it".
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Announcement>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Announcement>
     */
    public function scopeVisibleToAgent(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        $now = now();

        return $query
            ->where('published_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->where(function ($q) use ($user) {
                $q->where('audience', 'all_agents')
                    ->orWhere(function ($q2) use ($user) {
                        // TASK-042 §4 (BR-7 confirmed 2026-07-23): mode is a
                        // per-row column, so both branches are OR'd together
                        // rather than picked once outside the query — one call
                        // mixes 'exact' and 'and_above' announcements.
                        $q2->where('audience', 'cert_tier')
                            ->where(function ($q3) use ($user) {
                                $q3->where(function ($qExact) use ($user) {
                                    // Exact mode (default): identical to the
                                    // pre-TASK-042 query.
                                    $qExact->where('target_cert_tier_mode', CertTierTargetMode::Exact->value)
                                        ->whereIn('target_cert_tier_id', function ($sub) use ($user) {
                                            $sub->select('cert_tier_id')
                                                ->from('user_certifications')
                                                ->where('user_id', $user->id);
                                        });
                                })->orWhere(function ($qAndAbove) use ($user) {
                                    // AndAbove mode: the agent holds ANY
                                    // certification whose cert_tiers.sort_order
                                    // is >= the announcement's target tier's
                                    // (sort_order is the established ranking —
                                    // see User::highestPassedCertTier():
                                    // Basic < Intermediate < High).
                                    $qAndAbove->where('target_cert_tier_mode', CertTierTargetMode::AndAbove->value)
                                        ->whereExists(function ($sub) use ($user) {
                                            $sub->select(DB::raw(1))
                                                ->from('user_certifications')
                                                ->join('cert_tiers as agent_tier', 'agent_tier.id', '=', 'user_certifications.cert_tier_id')
                                                ->join('cert_tiers as target_tier', 'target_tier.id', '=', 'announcements.target_cert_tier_id')
                                                ->where('user_certifications.user_id', $user->id)
                                                ->whereColumn('agent_tier.sort_order', '>=', 'target_tier.sort_order');
                                        });
                                });
                            });
                    });
            });
    }
}
