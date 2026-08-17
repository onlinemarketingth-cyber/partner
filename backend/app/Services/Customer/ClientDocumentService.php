<?php

namespace App\Services\Customer;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Section 5 rule 6 — files live on the 'local' disk (config/filesystems.php:
// storage/app/private, NOT the 'public' disk), so there is no direct URL
// to them at all; every read goes through
// ClientDocumentController::download's access-checked stream. The path
// itself is also tenant-scoped (company_id/client_id folders) as a
// second layer, independent of the DB-level Policy check.
class ClientDocumentService
{
    private const DISK = 'local';

    public function store(Client $client, UploadedFile $file, User $actor): ClientDocument
    {
        $path = $file->storeAs(
            "client-documents/{$client->company_id}/{$client->id}",
            Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
            self::DISK,
        );

        return ClientDocument::create([
            'company_id' => $client->company_id,
            'client_id' => $client->id,
            'uploaded_by_user_id' => $actor->id,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
    }

    public function delete(ClientDocument $document): void
    {
        Storage::disk(self::DISK)->delete($document->file_path);
        $document->delete();
    }

    public function disk(): string
    {
        return self::DISK;
    }
}
