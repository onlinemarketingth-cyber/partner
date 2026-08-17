<?php

namespace App\Console\Commands;

use App\Models\ChunkedUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-094 — delete abandoned chunk sessions.
 *
 * Every dropped connection mid-upload leaves a `.part` file and its row
 * behind. Nothing else ever removes them, so without this the production
 * host (Hostinger shared: 50GB disk, and already 306K of a 600K inode
 * quota) would accumulate dead partial videos indefinitely.
 *
 * Runs `withoutGlobalScopes()` on purpose: this is a scheduled system
 * task with no authenticated user, so TenantScope has no company to scope
 * to and would otherwise match nothing.
 */
class PruneChunkedUploadsCommand extends Command
{
    protected $signature = 'uploads:prune {--hours= : Override the media.upload.stale_hours config}';

    protected $description = 'Delete chunked-upload sessions (and their .part files) older than the configured window.';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('media.upload.stale_hours'));
        $cutoff = now()->subHours($hours);

        $stale = ChunkedUpload::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->get();

        $disk = Storage::disk('local');
        $freedBytes = 0;

        foreach ($stale as $upload) {
            if ($disk->exists($upload->part_path)) {
                $freedBytes += (int) $disk->size($upload->part_path);
                $disk->delete($upload->part_path);
            }

            $upload->delete();
        }

        $this->info(sprintf(
            'Pruned %d session(s) older than %dh, freeing %.1f MB.',
            $stale->count(),
            $hours,
            $freedBytes / 1024 / 1024,
        ));

        return self::SUCCESS;
    }
}
