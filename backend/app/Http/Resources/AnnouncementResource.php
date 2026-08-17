<?php

namespace App\Http\Resources;

use App\Enums\MediaSourceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id, // null = platform-wide default
            'title' => $this->title,
            'content' => $this->content,
            'audience' => $this->audience,
            'target_cert_tier_id' => $this->target_cert_tier_id,
            'target_cert_tier_mode' => $this->target_cert_tier_mode,
            'target_cert_tier_name' => $this->whenLoaded('targetCertTier', fn () => $this->targetCertTier?->name),
            'is_pinned' => $this->is_pinned,
            // TASK-080 (2026-08-03) — display switches. The Agent Portal
            // filters on these client-side off the SAME /announcements
            // response it already loads, so no extra endpoint: the modal
            // auto-pop considers only show_as_modal rows, and each page's
            // banner carousel takes show_as_banner rows whose banner_pages
            // include that page. `banner_pages` null is treated as "all
            // pages" by the frontend (see the migration comment) so a
            // half-configured announcement still renders somewhere.
            'show_as_modal' => $this->show_as_modal,
            'show_as_banner' => $this->show_as_banner,
            'banner_pages' => $this->banner_pages,
            'published_at' => $this->published_at,
            'expires_at' => $this->expires_at,
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at,
            // Human request (2026-07-23): "สามารถเพิ่มรูป และวิดีโอในประกาศได้"
            // — image_url is a direct public URL (see AnnouncementService's
            // own docblock on why 'public' disk is the right fit here).
            // 'video' is null | {type, url} rather than three separate
            // raw columns, so the frontend never has to re-derive "which
            // of video_path/video_embed_url is the live one" itself —
            // that decision is made once, here, from video_source_type.
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'video' => $this->video_source_type === MediaSourceType::Upload && $this->video_path
                ? ['type' => 'upload', 'url' => Storage::disk('public')->url($this->video_path)]
                : ($this->video_source_type === MediaSourceType::Embed && $this->video_embed_url
                    ? ['type' => 'embed', 'url' => $this->video_embed_url]
                    : null),
        ];
    }
}
