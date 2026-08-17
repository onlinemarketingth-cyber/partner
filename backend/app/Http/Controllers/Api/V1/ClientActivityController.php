<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreClientActivityRequest;
use App\Http\Requests\Customer\UpdateClientActivityRequest;
use App\Http\Resources\ClientActivityResource;
use App\Models\Client;
use App\Models\ClientActivity;
use App\Services\Customer\ClientActivityService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// TASK-015 — index/store nested under the parent Client (mirrors
// ClientDocumentController); update/destroy address the activity
// directly. update is gated by ClientActivityPolicy::update (only the
// original logger, narrower than "can view the client"); destroy by
// ::delete (Company Admin/Super Admin only).
class ClientActivityController extends Controller
{
    public function index(Client $client): AnonymousResourceCollection
    {
        $this->authorize('view', $client);

        return ClientActivityResource::collection(
            $client->activities()->with('loggedBy')->latest('occurred_at')->get()
        );
    }

    public function store(StoreClientActivityRequest $request, Client $client, ClientActivityService $service): ClientActivityResource
    {
        $activity = $service->create($client, $request->validated(), $request->user());

        return new ClientActivityResource($activity->load('loggedBy'));
    }

    public function update(UpdateClientActivityRequest $request, ClientActivity $clientActivity, ClientActivityService $service): ClientActivityResource
    {
        return new ClientActivityResource($service->update($clientActivity, $request->validated())->load('loggedBy'));
    }

    public function destroy(ClientActivity $clientActivity): Response
    {
        $this->authorize('delete', $clientActivity);

        $clientActivity->delete();

        return response()->noContent();
    }
}
