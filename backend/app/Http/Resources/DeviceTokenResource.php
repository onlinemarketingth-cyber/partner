<?php

namespace App\Http\Resources;

use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 2026-10-02 — MOB-10. Deliberately minimal: no token (the client already
 * holds it, and echoing a push credential back is a leak with no upside), no
 * user or company id (the caller knows who they are).
 *
 * @mixin DeviceToken
 */
class DeviceTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'app_version' => $this->app_version,
        ];
    }
}
