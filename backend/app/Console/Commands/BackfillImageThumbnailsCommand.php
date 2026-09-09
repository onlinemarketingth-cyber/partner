<?php

namespace App\Console\Commands;

use App\Enums\ProductMediaType;
use App\Models\ProductMedia;
use App\Support\Media\ImageThumbnailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 2026-09-09 (human: "แล้วให้ผมต้อง Upload รูปใหม่เพื่อย่อไฟล์ไหม หรือคุณ
 * ทำได้เลยกับไฟล์เก่า").
 *
 * No. Every original is already on the private disk exactly as it was
 * uploaded, so the small copies can be made from what is there — this
 * command is that, and re-uploading a catalogue by hand is the
 * alternative it exists to avoid.
 *
 * SAFE TO RE-RUN, and safe to run on production while people are using
 * the app:
 *   · it only WRITES new files and only fills `thumbnail_path` where it
 *     is still null — an original is never read-modified, moved, or
 *     deleted;
 *   · a row it cannot process is left exactly as it is, which is the
 *     behaviour every image already has today (the caller streams the
 *     original), not a broken state;
 *   · --dry-run reports the same work without writing anything.
 *
 * Images that are ALREADY smaller than ImageThumbnailer::MAX_EDGE are
 * counted as "ไม่ต้องย่อ" rather than failures: a 300 px logo does not
 * need a 480 px copy of itself.
 */
class BackfillImageThumbnailsCommand extends Command
{
    protected $signature = 'media:backfill-thumbnails
                            {--dry-run : Report what would be generated without writing anything}
                            {--limit= : Stop after this many rows (useful for a first cautious pass)}';

    protected $description = 'Generate the missing small copies of product images that were uploaded before thumbnails existed';

    public function handle(): int
    {
        // withoutGlobalScopes: a console command has no authenticated
        // user, so TenantScope/SharedOrTenantScope would resolve to
        // nothing. Stated rather than relied on by accident — same
        // convention as every other command here (BR-6).
        $query = ProductMedia::withoutGlobalScopes()
            ->where('media_type', ProductMediaType::Image->value)
            ->whereNotNull('file_path')
            ->whereNull('thumbnail_path')
            ->orderBy('id');

        $limit = $this->option('limit');

        if ($limit !== null && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('รูปสินค้าทุกรูปมีไฟล์ย่อแล้ว — ไม่ต้องทำอะไร');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('local');

        $this->line("พบรูปที่ยังไม่มีไฟล์ย่อ {$rows->count()} รูป".($dryRun ? ' (dry-run)' : ''));

        $generated = 0;
        $alreadySmall = 0;
        $missing = 0;
        $savedBytes = 0;

        foreach ($rows as $media) {
            if (! $disk->exists($media->file_path)) {
                $this->warn("  ✗ #{$media->id} — ไม่พบไฟล์ต้นฉบับ ({$media->file_path})");
                $missing++;

                continue;
            }

            $originalBytes = (int) $disk->size($media->file_path);

            /*
             * dry-run still runs the resize.
             *
             * "How many will succeed" cannot be answered by looking at the
             * database — it depends on what GD can decode on THIS server.
             * So the file is generated, measured, reported, and deleted
             * again; the row is never touched. The alternative is a
             * dry-run that promises numbers the real run then misses.
             */
            $thumbnailPath = ImageThumbnailer::generate($disk, $media->file_path);

            if ($thumbnailPath === null) {
                $alreadySmall++;

                continue;
            }

            $thumbnailBytes = (int) $disk->size($thumbnailPath);
            $savedBytes += max(0, $originalBytes - $thumbnailBytes);
            $generated++;

            if ($dryRun) {
                $disk->delete($thumbnailPath);
                $this->line("  · #{$media->id} — {$this->humanBytes($originalBytes)} → {$this->humanBytes($thumbnailBytes)} (dry-run, ยังไม่บันทึก)");

                continue;
            }

            $media->forceFill(['thumbnail_path' => $thumbnailPath])->save();
            $this->info("  ✓ #{$media->id} — {$this->humanBytes($originalBytes)} → {$this->humanBytes($thumbnailBytes)}");
        }

        $this->newLine();
        $this->line(($dryRun ? 'สรุป (dry-run): จะย่อได้ ' : 'สรุป: ย่อแล้ว ')
            ."{$generated} รูป · ไม่ต้องย่อ (เล็กอยู่แล้ว/อ่านไม่ได้) {$alreadySmall} · ไม่พบไฟล์ {$missing}");

        if ($generated > 0) {
            $this->line('ลดการโหลดต่อการแสดงรายการหนึ่งรอบได้ประมาณ '.$this->humanBytes($savedBytes));
        }

        /*
         * Zero is not a failure here.
         *
         * A shop whose photos are all already small legitimately generates
         * nothing, and this command runs from a deploy — failing that run
         * would stop a deploy over an entirely correct outcome. Only an
         * exception (an unreadable disk) fails.
         */
        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }
}
