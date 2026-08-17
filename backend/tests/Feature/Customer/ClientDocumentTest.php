<?php

namespace Tests\Feature\Customer;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// CLAUDE.md Section 5 rule 6 — tenant-scoped by path, access-checked
// before download, never a public URL.
class ClientDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_upload_a_document_to_their_own_client(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $file = UploadedFile::fake()->create('id-card.pdf', 500, 'application/pdf');

        $this->actingAs($agent)
            ->postJson("/api/v1/clients/{$client->id}/documents", ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.original_filename', 'id-card.pdf');

        // File actually landed on the (faked) 'local' disk, tenant-scoped
        // by company_id/client_id in the path — never the 'public' disk.
        Storage::disk('local')->assertExists("client-documents/{$company->id}/{$client->id}");
    }

    public function test_agent_cannot_upload_a_document_to_a_colleagues_client(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $colleaguesClient = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentB->id]);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->actingAs($agentA)
            ->postJson("/api/v1/clients/{$colleaguesClient->id}/documents", ['file' => $file])
            ->assertForbidden();
    }

    public function test_download_requires_the_same_visibility_as_the_parent_client(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agentB->id]);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $this->actingAs($agentB)->postJson("/api/v1/clients/{$client->id}/documents", ['file' => $file])->assertCreated();

        $documentId = \App\Models\ClientDocument::first()->id;

        $this->actingAs($agentA)->getJson("/api/v1/client-documents/{$documentId}/download")->assertForbidden();
        $this->actingAs($agentB)->getJson("/api/v1/client-documents/{$documentId}/download")->assertOk();
    }

    public function test_upload_rejects_a_disallowed_file_type(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);

        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $this->actingAs($agent)
            ->postJson("/api/v1/clients/{$client->id}/documents", ['file' => $file])
            ->assertUnprocessable();
    }
}
