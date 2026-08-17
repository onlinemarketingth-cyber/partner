<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\StoreAnnouncementRequest;
use App\Http\Requests\Engagement\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Services\Engagement\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

// Agent-view IA item 1.6. index() shape mirrors BadgeController: shared
// read (own company or platform default), Company Admin/Super Admin
// author. Agent-facing feed additionally hides not-yet-published/
// expired posts and posts targeted at a cert tier the Agent hasn't
// passed — Admin sees everything (including future-dated drafts) to
// manage the queue.
class AnnouncementController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Announcement::class, 'announcement');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Announcement::query();

        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            $query->where(fn ($q) => $q->where('company_id', $user->company_id)->orWhereNull('company_id'));
        }

        // TASK-156 — the publication window + audience gate moved to
        // Announcement::scopeVisibleToAgent() so that show() below applies the
        // IDENTICAL query. It used to live inline here, which is how show()
        // came to apply none of it.
        if ($user->isAgent()) {
            $query->visibleToAgent($user);
        }

        // Bug fix (2026-08-02, human-reported: Admin list showed "Cert
        // Tier: - ขึ้นไป" instead of the real tier name) — show() already
        // does ->load('targetCertTier'); index() was missing the same
        // eager load, so AnnouncementResource::target_cert_tier_name
        // (which uses whenLoaded()) silently resolved to null here. This
        // was a display-only bug — the actual audience gate above filters
        // on target_cert_tier_id/mode via SQL, not the loaded relation, so
        // agent-side visibility was never affected by this.
        return AnnouncementResource::collection(
            $query->with('targetCertTier')->orderByDesc('is_pinned')->orderByDesc('published_at')->get()
        );
    }

    public function store(StoreAnnouncementRequest $request, AnnouncementService $service): AnnouncementResource
    {
        return new AnnouncementResource($service->create(
            $request->validated(),
            $request->user(),
            $request->file('image'),
            $request->file('video'),
        ));
    }

    public function show(Request $request, Announcement $announcement): AnnouncementResource
    {
        /*
         * TASK-156 — THE GATE index() ALWAYS HAD, AND THIS ROUTE NEVER DID.
         *
         * AnnouncementPolicy::view() returns true for anyone in the company,
         * so before this an Agent who knew an id could read a draft, a post
         * scheduled for next month, an expired one, or one addressed to a cert
         * tier they have not earned — the four things index() goes to
         * considerable length to hide. A list that filters and a detail route
         * that does not is not a narrower leak than no filter at all: ids are
         * sequential.
         *
         * Re-running the scope rather than re-implementing it in PHP: see the
         * scope's own docblock. 404, not 403 — consistent with the rest of the
         * codebase's IDOR handling (CLAUDE.md §5.5), and because "this exists
         * but is not for you" is itself information about an unpublished post.
         */
        $user = $request->user();

        if ($user?->isAgent()) {
            abort_unless(
                Announcement::query()->visibleToAgent($user)->whereKey($announcement->getKey())->exists(),
                404,
            );
        }

        return new AnnouncementResource($announcement->load('targetCertTier'));
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement, AnnouncementService $service): AnnouncementResource
    {
        return new AnnouncementResource($service->update(
            $announcement,
            $request->validated(),
            $request->file('image'),
            $request->file('video'),
        ));
    }

    public function destroy(Announcement $announcement, AnnouncementService $service): Response
    {
        $service->delete($announcement);

        return response()->noContent();
    }
}
