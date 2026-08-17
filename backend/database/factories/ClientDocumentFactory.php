<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientDocument>
 */
class ClientDocumentFactory extends Factory
{
    protected $model = ClientDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            // Closure attributes run after the ones above resolve, with
            // access to the already-resolved values — this reads the
            // just-created Client's actual company_id rather than
            // spinning up an unrelated Company (or worse, reusing the
            // Client's own id as if it were a company_id).
            'company_id' => fn (array $attributes) => Client::find($attributes['client_id'])->company_id,
            'uploaded_by_user_id' => User::factory()->agent(),
            'file_path' => 'client-documents/test/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1000, 500000),
        ];
    }
}
