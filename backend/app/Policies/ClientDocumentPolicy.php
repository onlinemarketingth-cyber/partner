<?php

namespace App\Policies;

use App\Models\ClientDocument;
use App\Models\User;

// Section 5 rule 6 — tenant-scoped by path, access-checked before
// download, never a public URL. Visibility mirrors the parent Client's:
// whoever can view the client can view/download its documents.
class ClientDocumentPolicy
{
    public function view(User $user, ClientDocument $clientDocument): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $clientDocument->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $clientDocument->client->referring_agent_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true; // gated against the parent Client's own visibility in the Controller (must be able to view the client to upload to it)
    }

    public function delete(User $user, ClientDocument $clientDocument): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $clientDocument->company_id);
    }
}
