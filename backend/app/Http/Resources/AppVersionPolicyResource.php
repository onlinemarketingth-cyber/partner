<?php

namespace App\Http\Resources;

use App\Models\AppVersionPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 2026-10-02 — MOB-13. The same four fields for the public read and the
 * Super Admin screen; nulls mean "no policy set" and block nobody.
 *
 * @mixin AppVersionPolicy
 */
class AppVersionPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'platform' => $this->platform->value,
            'min_supported_version' => $this->min_supported_version,
            'latest_version' => $this->latest_version,
            'store_url' => $this->store_url,
        ];
    }
}
