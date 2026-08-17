<?php

namespace App\Http\Resources;

use App\Models\PipelineTemplateStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ADR-026 (TASK-136) — a pipeline template as the admin product form
 * needs it: its identity plus its ORDERED stage list.
 *
 * `stages` is the whole point of the endpoint. A template chooser that
 * only showed names ("Medical Package (default)" vs "Direct Sale
 * (default)") would ask an admin to pick a customer journey without
 * showing them the journey. The order of the array IS the journey.
 *
 * English `label()` only — Thai stage labels are a UI concern
 * (PipelineStage's own docblock, §7); the enum deliberately has none and
 * this Resource must not invent them.
 *
 * Read-only, so it exposes no counts of dependent rows and there is no
 * writable counterpart (see PipelineTemplatePolicy on why authoring is
 * TASK-134b).
 */
class PipelineTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Present because Super Admin lists across companies (§5 rule
            // 4) and would otherwise see two identically-named templates
            // with no way to tell them apart. Company Admins only ever
            // receive their own id, which they already know.
            'company_id' => $this->company_id,
            'key' => $this->key,
            'name' => $this->name,
            // Seeded platform template — TASK-134b's editor makes these
            // copy-only rather than editable (ADR-026 §3.1: every company
            // must keep medical_package_default, it is the resolver's
            // final fail-safe).
            'is_system' => $this->is_system,
            'stages' => $this->whenLoaded('stages', fn () => $this->stages
                ->map(fn (PipelineTemplateStage $stage) => [
                    'key' => $stage->stage->value,
                    'label' => $stage->stage->label(),
                    'position' => $stage->position,
                ])
                ->values()),
        ];
    }
}
