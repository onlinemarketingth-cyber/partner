<?php

namespace App\Models\Concerns;

use App\Models\TrackedLink;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * TASK-232 — for the models that a short link can point at.
 *
 * Four models (soon seven) each needed the same two things: the relation
 * back to their tracked link, and the short URL to hand a Resource. Written
 * once here so that the seventh one cannot quietly do it differently.
 */
trait HasTrackedLink
{
    /** @return MorphOne<TrackedLink, $this> */
    public function trackedLink(): MorphOne
    {
        return $this->morphOne(TrackedLink::class, 'target');
    }

    /**
     * The short URL, or null when this row predates the feature.
     *
     * NULL IS A REAL ANSWER AND MUST STAY ONE. Every product share, order
     * and invite created before TASK-232 has no tracked link, and there is
     * no honest way to invent one after the fact — the short code would be
     * new, so anyone holding the old link would not be holding this. The
     * callers render the long URL in that case, which is exactly what
     * those customers already have.
     *
     * `withoutGlobalScopes()` because this is reached from public
     * resolvers too, where there is no authenticated tenant to scope by.
     */
    public function shortUrl(): ?string
    {
        $link = $this->relationLoaded('trackedLink')
            ? $this->getRelation('trackedLink')
            : $this->trackedLink()->withoutGlobalScopes()->first();

        if (! $link instanceof TrackedLink) {
            return null;
        }

        return $link->publicUrl();
    }
}
