<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Deliberately does NOT expose file_path (the raw storage path) — the
// only way to get the file is the access-checked
// /client-documents/{id}/download route, never a direct link built
// from this response (Section 5 rule 6).
class ClientDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'created_at' => $this->created_at,
        ];
    }
}
