<?php

namespace App\Services\Customer;

use App\Models\Client;
use App\Models\ClientActivity;
use App\Models\User;

// TASK-015 — forces company_id/logged_by_user_id server-side, same
// pattern as every other Service in this codebase (never trust client
// input, Section 6).
class ClientActivityService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Client $client, array $data, User $actor): ClientActivity
    {
        $data['company_id'] = $client->company_id;
        $data['client_id'] = $client->id;
        $data['logged_by_user_id'] = $actor->id;
        $data['occurred_at'] = $data['occurred_at'] ?? now();

        return ClientActivity::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ClientActivity $activity, array $data): ClientActivity
    {
        $activity->update($data);

        return $activity;
    }
}
