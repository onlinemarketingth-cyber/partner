<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'exam' => $this->whenLoaded('exam', fn () => [
                'id' => $this->exam->id,
                'title' => $this->exam->title,
            ]),
            'score' => $this->score,
            'passed' => $this->passed,
            'attempted_at' => $this->attempted_at,
        ];
    }
}
