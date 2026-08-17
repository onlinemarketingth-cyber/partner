<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-028 §4 / BR-7. Wraps the array AcademyCompletionSettingService
// returns (company override merged over the platform default), same shape
// as VideoProcessingSettingResource.
class AcademyCompletionSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'video_watch_percent' => $this['video_watch_percent'],
            'pdf_read_percent' => $this['pdf_read_percent'],
            // ADR-029 §2.4. Admin-only, like the other two — the controller
            // 403s an Agent outright, because a threshold is exactly the
            // class of number ADR-028 §4 decided a learner is not shown.
            'quiz_pass_percent' => $this['quiz_pass_percent'],
        ];
    }
}
