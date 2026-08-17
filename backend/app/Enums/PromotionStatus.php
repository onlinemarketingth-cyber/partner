<?php

namespace App\Enums;

// Admin-controlled lifecycle for agent_promotions, independent of
// starts_at/ends_at — a promotion can be authored as Draft (not yet
// visible to agents) before its date window opens, and an Admin can
// force-Ended a still-in-window promotion early (e.g. product recalled,
// budget exhausted). Never inferred purely from dates.
enum PromotionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Ended = 'ended';
}
