<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-220 — `php artisan media:audit`.
 *
 * Answers, for a live installation, the question that started this task:
 * "is anything ELSE uploaded-but-not-showing?"
 *
 * Production had every uploaded file 404ing because `public/storage` was
 * missing. That was found by a human noticing one broken logo. Every other
 * broken file — avatars, brand logos, banners, announcement images — was
 * equally broken and nobody had looked. A symptom that can only be found
 * by browsing to it is a symptom that gets found late.
 *
 * READ-ONLY. It never deletes a row, never re-uploads, never repairs. It
 * reports, and every finding names the fix. A command that quietly
 * "cleans up" rows whose file is missing is a command that deletes a
 * client's PDPA document the one time a disk is temporarily unmounted.
 *
 * What it checks per path column:
 *   - the file exists on the disk that column's service writes to
 *   - it is not zero bytes (a truncated or failed write)
 *   - the path does not end in a bare '.' (the empty-extension bug
 *     StoredFileName now prevents — existing rows can still carry it)
 *
 * Plus one installation-level check that is the actual root cause when
 * EVERYTHING public is broken at once: the storage symlink.
 */
class AuditMediaFilesCommand extends Command
{
    protected $signature = 'media:audit {--company= : limit to one company id}';

    protected $description = 'Report uploaded files that are missing, empty, or unservable (read-only).';

    /**
     * table, path column, disk, and the company column to filter on
     * (null = the table has no company_id, so --company cannot narrow it).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: ?string}>
     */
    private const SOURCES = [
        // --- public disk: served statically through public/storage ---
        ['users', 'avatar_path', 'public', 'company_id'],
        ['users', 'background_image_path', 'public', 'company_id'],
        ['brands', 'logo_path', 'public', null],
        ['storefront_banners', 'image_path', 'public', 'company_id'],
        ['company_theme_settings', 'logo_nav_path', 'public', 'company_id'],
        ['company_theme_settings', 'logo_login_path', 'public', 'company_id'],
        ['company_theme_settings', 'favicon_path', 'public', 'company_id'],
        ['company_theme_settings', 'logo_loading_path', 'public', 'company_id'],
        ['company_theme_settings', 'background_image_path', 'public', 'company_id'],
        ['announcements', 'image_path', 'public', 'company_id'],
        ['announcements', 'video_path', 'public', 'company_id'],
        // --- local disk: streamed through authorized routes ---
        ['product_media', 'file_path', 'local', 'company_id'],
        ['product_media', 'thumbnail_path', 'local', 'company_id'],
        ['product_spec_attachments', 'file_path', 'local', 'company_id'],
        ['product_spec_attachments', 'thumbnail_path', 'local', 'company_id'],
        ['product_sales_materials', 'file_path', 'local', 'company_id'],
        ['client_documents', 'file_path', 'local', 'company_id'],
        ['orders', 'slip_path', 'local', 'company_id'],
    ];

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        $this->components->info('Auditing uploaded files'.($companyId ? " for company {$companyId}" : ' across every company'));

        $symlinkOk = $this->checkStorageSymlink();

        $problems = 0;
        $checked = 0;

        foreach (self::SOURCES as [$table, $column, $disk, $companyColumn]) {
            // Skip a table/column this installation does not have rather
            // than crash: this command must stay runnable on a database
            // that is mid-migration, which is exactly when it is useful.
            if (! $this->columnExists($table, $column)) {
                continue;
            }

            $query = DB::table($table)->whereNotNull($column)->where($column, '!=', '');
            if ($companyId !== null && $companyColumn !== null) {
                $query->where($companyColumn, $companyId);
            }

            foreach ($query->select('id', $column)->cursor() as $row) {
                $checked++;
                $path = (string) $row->{$column};
                $issue = $this->inspect($disk, $path);

                if ($issue !== null) {
                    $problems++;
                    $this->line("  <fg=red>✗</> {$table}#{$row->id}.{$column} [{$disk}] {$path} — {$issue}");
                }
            }
        }

        $this->newLine();
        $this->components->info("Checked {$checked} stored file(s).");

        /*
         * The two findings are reported SEPARATELY on purpose. A missing
         * symlink and a missing file are different failures with different
         * fixes, and rolling them into one verdict hides the worse one: if
         * the symlink is gone, every public file 404s no matter how
         * healthy each row below it looks, and a summary reading "0
         * problems" would be actively misleading.
         */
        if ($problems === 0) {
            $this->components->info('No missing, empty or malformed files — every row points at a real file.');
        } else {
            $this->components->warn("{$problems} file(s) are missing, empty, or unservable (listed above).");
            $this->line('  A MISSING file cannot be recovered by this command — re-upload it from the screen that owns it.');
            $this->line('  Nothing was deleted or changed. Row and file both left exactly as found.');
        }

        if (! $symlinkOk) {
            $this->newLine();
            $this->components->warn('public/storage is missing — nothing on the public disk is reachable from the web, however healthy the rows above look. Fix that first.');
        }

        // Deliberately still exit 0. This is a report, and a report that
        // fails a deploy pipeline because a single test avatar was tidied
        // off disk months ago is a report people switch off.
        return self::SUCCESS;
    }

    /**
     * The one check that explains EVERY public file being broken at once
     * (production, 2026-08-20). Worth its own line before the per-row list,
     * because if this is wrong the rest of the output is noise.
     */
    private function checkStorageSymlink(): bool
    {
        $link = public_path('storage');

        if (is_link($link) || is_dir($link)) {
            $this->line('  <fg=green>✓</> public/storage is present.');

            return true;
        }

        $this->line('  <fg=red>✗</> public/storage is MISSING — every file on the public disk will 404.');
        $this->line('     Fix: ln -s "$(pwd)/storage/app/public" public/storage');
        $this->line('     (not `artisan storage:link` — hosts that disable symlink()/exec() cannot run it)');

        return false;
    }

    private function inspect(string $disk, string $path): ?string
    {
        if (str_ends_with($path, '.')) {
            return 'path ends in a bare dot (no extension) — served with the wrong Content-Type';
        }

        if (! Storage::disk($disk)->exists($path)) {
            return 'file does not exist on disk';
        }

        if (Storage::disk($disk)->size($path) === 0) {
            return 'file is 0 bytes (truncated or failed write)';
        }

        return null;
    }

    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, $column);
    }
}
