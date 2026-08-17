<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserCertificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'cert_tier' => $this->whenLoaded('certTier', fn () => [
                'id' => $this->certTier->id,
                'key' => $this->certTier->key,
                'name' => $this->certTier->name,
            ]),
            'passed_at' => $this->passed_at,
        ];
    }
}
