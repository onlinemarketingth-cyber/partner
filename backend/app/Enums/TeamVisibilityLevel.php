<?php

namespace App\Enums;

// TASK-106 / ADR-024 §5 — how much of a subordinate's client data a team
// leader (an Agent with direct reports, see ADR-024 §1: leadership is
// emergent from users.manager_id, there is no leader role) may see in the
// Agent Portal's read-only team screen. Human-confirmed 2026-08-05: all
// three levels exist and the Company Admin picks one per company (BR-7 —
// this is config, never a hardcoded rule).
//
// The level is enforced in the API Resource, never in the Vue component
// (ADR-024 §5): a field the level does not permit must be ABSENT from the
// JSON, not merely hidden client-side.
enum TeamVisibilityLevel: string
{
    // Counts and pipeline-stage totals only — no client identity at all.
    // Also the fail-closed fallback: see self::default().
    case CountsOnly = 'counts_only';

    // Client name + current pipeline stage. No phone, email, national_id,
    // address, documents or health fields (PDPA, CLAUDE.md §6).
    case Names = 'names';

    // The full Client File exactly as the subordinate sees it. Discloses
    // sensitive health data, so ADR-024 §8 requires an audit-log write on
    // every view at this level (implemented in TASK-107, not here).
    case FullFile = 'full_file';

    /**
     * The value an unconfigured tenant resolves to.
     *
     * WHY a hardcoded constant is correct here and does not violate BR-7:
     * BR-7 forbids hardcoding a *business* value that an admin should own
     * — the three levels above ARE admin-selectable. This is the safety
     * default for a company that has never made that choice, and ADR-024
     * §5 fixes it deliberately: "an unconfigured tenant must fail closed,
     * not open". Making the fail-closed default itself configurable would
     * defeat the point.
     */
    public static function default(): self
    {
        return self::CountsOnly;
    }
}
