<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * TASK-136 — one Service instance per REQUEST, for API Resources that
 * need a Service while rendering a COLLECTION.
 *
 * The problem it solves: an API Resource is instantiated once per row, so
 * `app(SomeService::class)` inside toArray() builds a fresh object graph
 * (and throws away that object's memo) for every item in the list. Two
 * Resources need exactly that pattern now:
 *
 *   - ReferralResource  → PipelineService, to expose each referral's own
 *                         stage sequence (ADR-026 §3.6). Its
 *                         $sequenceCache is keyed by template id, so all
 *                         referrals sharing a journey cost one lookup —
 *                         but only if they share the instance.
 *   - ProductResource   → PipelineTemplateResolver, for
 *                         `effective_pipeline_template`. Same story.
 *
 * Scoped to the Request object (not a container singleton, not a static)
 * on purpose: a singleton would survive across the several requests a
 * single feature test fires, so a template edited between two calls could
 * be served stale. The Request is the exact lifetime we want — one HTTP
 * call — and it is already threaded into every toArray().
 *
 * Not a general-purpose caching layer. If a third caller appears that
 * isn't "a Resource rendering a collection", reconsider rather than
 * widen this.
 */
final class RequestScopedService
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function get(Request $request, string $class): object
    {
        $key = 'request_scoped_service.'.$class;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app($class));
        }

        /** @var T $service */
        $service = $request->attributes->get($key);

        return $service;
    }
}
