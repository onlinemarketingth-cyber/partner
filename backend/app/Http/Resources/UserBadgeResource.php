<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserBadgeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'badge' => $this->whenLoaded('badge', fn () => [
                'id' => $this->badge->id,
                'key' => $this->badge->key,
                'name' => $this->badge->name,
                'icon' => $this->badge->icon,
            ]),
            'earned_at' => $this->earned_at,
            'created_at' => $this->created_at,
        ];
    }
}
