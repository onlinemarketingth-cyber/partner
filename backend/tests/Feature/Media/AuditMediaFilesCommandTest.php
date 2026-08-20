<?php

namespace Tests\Feature\Media;

use App\Models\Brand;
use App\Models\Company;
use App\Models\User;
use App\Support\Media\StoredFileName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-220 — `media:audit`.
 *
 * The case that matters most is test_it_reports_a_path_with_no_extension:
 * that is the shape the empty-extension bug produced. The row looks
 * completely healthy, the file genuinely EXISTS on disk, and the only
 * symptom is a browser rendering nothing. An audit that merely stats files
 * would call it fine.
 */
class AuditMediaFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function brandWithLogo(string $path, ?string $contents = 'not-really-a-png'): Brand
    {
        if ($contents !== null) {
            Storage::disk('public')->put($path, $contents);
        }

        return Brand::factory()->create(['logo_path' => $path]);
    }

    public function test_it_reports_nothing_when_every_file_is_present(): void
    {
        $this->brandWithLogo('brand-logos/ok.png');

        $this->artisan('media:audit')
            ->expectsOutputToContain('every row points at a real file')
            ->assertExitCode(0);
    }

    public function test_it_reports_a_row_whose_file_is_gone(): void
    {
        $this->brandWithLogo('brand-logos/vanished.png', contents: null);

        $this->artisan('media:audit')
            ->expectsOutputToContain('file does not exist on disk')
            ->assertExitCode(0);
    }

    public function test_it_reports_a_zero_byte_file(): void
    {
        $this->brandWithLogo('brand-logos/empty.png', contents: '');

        $this->artisan('media:audit')
            ->expectsOutputToContain('0 bytes')
            ->assertExitCode(0);
    }

    public function test_it_reports_a_path_with_no_extension(): void
    {
        $this->brandWithLogo('brand-logos/broken.');

        $this->artisan('media:audit')
            ->expectsOutputToContain('bare dot')
            ->assertExitCode(0);
    }

    public function test_it_can_be_limited_to_one_company(): void
    {
        $wanted = Company::factory()->create();
        $other = Company::factory()->create();

        User::factory()->create(['company_id' => $other->id, 'avatar_path' => 'avatars/gone.png']);
        $ok = User::factory()->create(['company_id' => $wanted->id, 'avatar_path' => 'avatars/here.png']);
        Storage::disk('public')->put($ok->avatar_path, 'bytes');

        $this->artisan('media:audit', ['--company' => $wanted->id])
            ->expectsOutputToContain('every row points at a real file')
            ->assertExitCode(0);
    }

    /**
     * TASK-220 — the empty-extension bug at its source. An upload whose
     * filename carries no extension must never produce a path ending in a
     * bare dot; StoredFileName falls back to the sniffed type, then 'bin'.
     */
    public function test_an_upload_with_no_extension_still_gets_one(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->actingAs($admin);

        // No dot in the client filename at all.
        $file = UploadedFile::fake()->image('logo-without-extension');

        $path = $file->storeAs(
            'brand-logos',
            StoredFileName::random($file),
            'public',
        );

        $this->assertIsString($path);
        $this->assertFalse(str_ends_with($path, '.'), 'stored path must not end in a bare dot');
        $this->assertMatchesRegularExpression('/\.[a-z0-9]+$/', $path);
    }
}
