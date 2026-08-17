<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id, // null = platform-wide default
            'name' => $this->name,
            'description' => $this->description,
            'cost_points' => $this->cost_points,
            'stock_quantity' => $this->stock_quantity, // null = unlimited
            'is_active' => $this->is_active,
            'reward_type' => $this->reward_type, // App\Enums\RewardType — physical/digital, TASK-042 §2
            'created_at' => $this->created_at,
        ];
    }
}
