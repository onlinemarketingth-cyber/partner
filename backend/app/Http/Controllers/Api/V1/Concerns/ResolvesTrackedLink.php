<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\TrackedLinkGroup;
use App\Models\TrackedLink;
use App\Services\Link\TrackedLinkService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * TASK-232 — lets a public resolver accept a SHORT CODE or the LEGACY TOKEN
 * it already understood, without either caller learning about the other.
 *
 * WHY BOTH, INDEFINITELY. The 40- and 64-character tokens are already out
 * in the world: pasted into LINE conversations, sitting in customers'
 * inboxes, printed inside posts that were shared onward. Rewriting them
 * would break links that people are holding right now, and would break
 * them for the customer rather than for us — they would simply see a dead
 * page and assume the company is gone. So the short code is a SECOND door
 * into the same room, and the old one is never bricked up.
 *
 * The order matters. The short code is checked first because it is the one
 * that carries a visit row; a legacy token resolves to the same target but
 * has no tracked link to count against, and honestly reporting nothing is
 * better than inventing a link record for a URL that predates the feature.
 */
trait ResolvesTrackedLink
{
    /**
     * Resolve a short code to its target and record the visit.
     *
     * Returns null when `$code` is not a short code at all — which is the
     * normal path for every legacy token — so the caller can fall through
     * to its own lookup. It is deliberately NOT an abort: this method
     * cannot tell "not a code" from "not anything", and only the caller
     * knows whether its own token table is still worth asking.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return TModel|null
     */
    protected function resolveViaTrackedLink(
        string $code,
        TrackedLinkGroup $group,
        string $expected,
        Request $request,
        TrackedLinkService $service,
    ): ?Model {
        $link = $service->resolve($code);

        if (! $link || $link->group !== $group) {
            return null;
        }

        $target = $link->target()->withoutGlobalScopes()->first();

        // A tracked link whose target has been deleted is not an error to
        // report — it is a dead link, and dead links answer 404 like every
        // other dead link here. Returning null lets the caller reach its
        // own abort with the same message a revoked token gets, so a
        // visitor can never tell the two apart.
        if (! $target instanceof $expected) {
            return null;
        }

        $this->recordTrackedVisit($link, $request, $service);

        return $target;
    }

    /**
     * Count the visit, but never let counting break the page.
     *
     * A visit row is analytics. The page it is attached to is a customer
     * reading a product, or paying for an order. If the counter write ever
     * fails — a lock, a full disk, a migration halfway applied — the right
     * outcome is a slightly wrong statistic, not a customer staring at a
     * 500 on the page where they were about to hand over money.
     *
     * The failure is swallowed rather than logged loudly on purpose: this
     * runs on a public, unauthenticated endpoint that anyone can hit as
     * often as they like, so an error path that writes a log line per
     * request is an amplification vector.
     */
    protected function recordTrackedVisit(TrackedLink $link, Request $request, TrackedLinkService $service): void
    {
        try {
            $service->recordVisit($link, $request);
        } catch (\Throwable) {
            // Intentionally ignored — see the comment above.
        }
    }
}
