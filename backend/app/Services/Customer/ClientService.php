<?php

namespace App\Services\Customer;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;

class ClientService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Client
    {
        $data['company_id'] = $actor->company_id;

        // An Agent can only ever refer clients to themselves — never
        // trust a client-supplied referring_agent_id for that role,
        // even though the Form Request already requires it be omitted.
        $data['referring_agent_id'] = $actor->isAgent() ? $actor->id : $data['referring_agent_id'];

        // Every new client starts as a fresh lead — status is never
        // client-settable at creation (StoreClientRequest doesn't
        // accept it), only changeable afterwards via update().
        $data['status'] = ClientStatus::New;

        return Client::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Client $client, array $data): Client
    {
        $client->update($data);

        return $client;
    }
}
