<?php

namespace App\Enums;

// TASK-077 (2026-08-02, human-confirmed via AskUserQuestion) — 4 admin-
// selectable styles for the Agent Portal's announcement auto-pop modal,
// applied as ONE global setting per company (not per-announcement — see
// AnnouncementSetting model docblock). Rendering logic for each style
// lives in frontend/src/design-system/components/AnnouncementModal.vue.
enum AnnouncementDisplayStyle: string
{
    // Covers the entire viewport edge-to-edge, no dim backdrop — the
    // most intrusive/attention-grabbing option.
    case FullScreen = 'full_screen';

    // Slides up from the bottom on mobile (centered card on desktop),
    // dim backdrop behind it. This was the original TASK-075 behavior —
    // kept as the default for backward compatibility.
    case BottomSheet = 'bottom_sheet';

    // Small dialog centered in the viewport, dim backdrop, doesn't fill
    // the screen — least intrusive of the 3 "modal" styles.
    case CenteredCard = 'centered_card';

    // Starts as a small non-blocking bar pinned to the bottom of the
    // screen (no backdrop — the rest of the app stays usable); tapping
    // it expands into the same detail layout as CenteredCard.
    case BottomStrip = 'bottom_strip';
}
