<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ADR-028 §4.1 — the LEARNER's own bookmark, and nothing else.
 *
 * "A bookmark is not the withheld number." ADR-028 §4 withholds *how close
 * the learner is to passing*; where they stopped reading is not that.
 * Refusing a learner their own resume position would be privacy theatre
 * paid for by the learner (TASK-147: close the app, reopen, resume).
 *
 * So this resource is an ALLOW-LIST OF EXACTLY TWO FIELDS, and it must
 * stay that way:
 *
 *   - NO `max_position_seconds` / `max_page` — those ARE the gate
 *     (ADR-028 §2.3), and max-against-threshold is the withheld number.
 *   - NO `total_pages` / `duration_seconds` — a denominator plus a max is
 *     the percentage rebuilt by hand on the client.
 *   - NO threshold, no percentage, no field from
 *     `academy_completion_settings` (those stay Admin-only — see
 *     AcademyCompletionSettingController).
 *   - NO `id` / `user_id` / `company_id` either. Not because they leak the
 *     number, but because they are the fields that make a future "just add
 *     a filter" change look reasonable. There is nothing here to filter.
 *
 * Anything a support agent needs lives on the ADMIN readout
 * (ModuleLessonProgressResource, gated on ModulePolicy::update).
 *
 * Unwrapped on purpose: the contract with ag-ui is a flat two-key object,
 * not `{ "data": { ... } }`. This is the only resource in the codebase
 * that opts out, and it does so because the shape is fixed by ADR-028 §4.1
 * rather than by our own JSON conventions.
 */
class MyModuleLessonProgressResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{last_position_seconds: int|null, last_page: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'last_position_seconds' => $this->last_position_seconds,
            'last_page' => $this->last_page,
        ];
    }
}
