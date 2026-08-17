<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Deliberately does NOT expose file_path — the only way to get the file
// is the access-checked /sales-materials/{id}/download route, mirrors
// ClientDocumentResource's same rule (Section 5 rule 6 pattern).
class ProductSalesMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'material_group' => $this->material_group,
            'source_type' => $this->source_type?->value,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            // Human-requested 2026-07-20 — inline preview/thumbnails in
            // the redesigned grid (PdfThumbnail.vue / AuthenticatedMedia
            // / MediaPreviewModal), same pattern as product_media's
            // stream_url. Never a public URL (Section 5 rule 6) —
            // access-checked by ProductSalesMaterialController::stream.
            'stream_url' => $this->file_path ? route('sales-materials.stream', $this->id) : null,
            // ADR-007 — only set when source_type = embed; download
            // route above is the only file-access path either way.
            'embed_url' => $this->embed_url,
            'processing_status' => $this->processing_status?->value,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'created_at' => $this->created_at,
        ];
    }
}
