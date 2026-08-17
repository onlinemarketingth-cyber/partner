<?php

namespace App\Http\Resources;

use App\Models\ThemePreset;
use App\Services\Theme\ThemePresetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-161 §3.2 — a saved colour preset. `colors` is re-projected through
 * ThemePresetService::COLOR_FIELDS on the way out so the payload shape is
 * stable even for rows saved before a field existed, and so a row that
 * somehow holds an extra key cannot leak it (§7: never return a raw model).
 *
 * @mixin ThemePreset
 */
class ThemePresetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stored = $this->colors ?? [];

        $colors = [];
        foreach (ThemePresetService::COLOR_FIELDS as $field) {
            $colors[$field] = $stored[$field] ?? null;
        }

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            // TASK-164 §1 — the UI hides rename/delete on these. Exposed
            // as the reason the controls are absent, NOT as the
            // enforcement: the server refuses both regardless (Policy +
            // Service), so a client that ignores this flag gets a 422.
            'is_system' => (bool) $this->is_system,
            // The seeding handle. Useful to a support engineer asking
            // "which palette is this row", and harmless to expose — it
            // names a platform palette, not anything tenant-specific.
            'key' => $this->key,
            'colors' => $colors,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
