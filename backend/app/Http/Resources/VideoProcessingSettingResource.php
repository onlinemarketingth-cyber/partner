<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoProcessingSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'max_upload_mb' => $this['max_upload_mb'],
            'target_resolution' => $this['target_resolution'],
            'target_bitrate_kbps' => $this['target_bitrate_kbps'],
        ];
    }
}
