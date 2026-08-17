<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * TASK-053 / ADR-016 Phase 1 — a user's own notifications. Every action is
 * narrowed to auth()->id() on top of the model's TenantScope, so a user
 * can only ever see/modify their OWN notifications (a company admin does
 * NOT see other users' notifications through this endpoint — it's the
 * personal bell, not an admin log).
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['data' => ['unread_count' => $count]]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        // Explicit { data: ... } (rather than returning the Resource
        // directly) so the read_at/is_read of the just-updated row is
        // always reflected, regardless of single-resource wrapping config.
        return response()->json([
            'data' => (new NotificationResource($notification->refresh()))->resolve($request),
        ]);
    }

    public function markAllRead(Request $request): Response
    {
        Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->noContent();
    }
}
