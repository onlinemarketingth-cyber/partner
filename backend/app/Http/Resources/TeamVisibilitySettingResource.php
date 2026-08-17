<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TASK-106 — wraps the plain array returned by
// TeamVisibilitySettingService::forCompany() (never a raw model, §7).
// company_id is deliberately NOT echoed back: the caller already knows
// which company they asked about, and echoing it invites a frontend to
// start treating it as a writable field.
class TeamVisibilitySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'client_visibility_level' => $this['client_visibility_level'],
            'is_enabled' => $this['is_enabled'],
        ];
    }
}
