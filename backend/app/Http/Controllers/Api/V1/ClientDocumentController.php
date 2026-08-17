<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreClientDocumentRequest;
use App\Http\Resources\ClientDocumentResource;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Services\Customer\ClientDocumentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

// Section 5 rule 6 — every action here re-checks against the PARENT
// Client's visibility (ClientPolicy::view), not just the document's own
// company_id, since "can see this client" is what actually determines
// "can see this client's files." download() is the one and only way to
// read file content — nothing in this API ever returns a raw URL.
class ClientDocumentController extends Controller
{
    public function index(Client $client): AnonymousResourceCollection
    {
        $this->authorize('view', $client);

        return ClientDocumentResource::collection($client->documents()->latest()->get());
    }

    public function store(StoreClientDocumentRequest $request, Client $client, ClientDocumentService $service): ClientDocumentResource
    {
        $this->authorize('view', $client);

        $document = $service->store($client, $request->file('file'), $request->user());

        return new ClientDocumentResource($document);
    }

    public function download(ClientDocument $clientDocument, ClientDocumentService $service): mixed
    {
        $this->authorize('view', $clientDocument);

        return Storage::disk($service->disk())->download($clientDocument->file_path, $clientDocument->original_filename);
    }

    public function destroy(ClientDocument $clientDocument, ClientDocumentService $service): Response
    {
        $this->authorize('delete', $clientDocument);

        $service->delete($clientDocument);

        return response()->noContent();
    }
}
