<?php

namespace App\Support;

use App\Enums\NotificationType;
use App\Models\Notification;

/**
 * Where a notification points, for callers that are not the SPA.
 *
 * ── THIS IS A SECOND COPY, AND THAT IS A COST WORTH NAMING ──
 *
 * frontend/src/utils/notificationLink.ts is the original and stays the
 * authority for in-app navigation. This exists because an EMAIL cannot ask
 * the SPA where a notification goes — it has to bake an absolute URL at send
 * time — and PHP cannot import a TypeScript module.
 *
 * The 2026-08-22 bug was caused by exactly this shape: two copies of a
 * routing rule, one of which nobody updated. So the surface is kept as small
 * as it can be. Everything except announcements simply returns the `link`
 * column verbatim — no mapping to drift. Only the announcement case adds
 * anything (?a={id}, so the mail opens the announcement rather than a list
 * of headlines), and NotificationLinkTest asserts that one case matches what
 * the TS resolver produces, character for character.
 *
 * If a third caller ever needs this, the answer is to move the rule into the
 * `link` column at write time and delete both resolvers — not a third copy.
 */
class NotificationLink
{
    /**
     * The in-app path, or null when this notification has nowhere to go.
     */
    public static function for(Notification $notification): ?string
    {
        if ($notification->type === NotificationType::Announcement) {
            $id = $notification->data['announcement_id'] ?? null;

            return $id === null ? '/announcements' : '/announcements?a='.$id;
        }

        $link = $notification->link;

        // Rows written before 2026-08-22 still say '/news', which was never a
        // route. Kept in step with the TS resolver's identical fallback.
        if ($link === '/news') {
            return '/announcements';
        }

        // Only in-app paths. An absolute URL would be concatenated onto the
        // portal origin and produce nonsense.
        if ($link === null || ! str_starts_with($link, '/')) {
            return null;
        }

        return $link;
    }
}
