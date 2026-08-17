<?php

namespace App\Jobs;

use App\Enums\MediaProcessingStatus;
use App\Models\ProductSpecAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * ADR-008 — renders page 1 of an uploaded PDF spec attachment to a JPEG
 * thumbnail and reads its page count. Shells out to the system
 * `pdftoppm`/`pdfinfo` binaries (poppler-utils) via Symfony Process
 * (already a Laravel dependency — no new Composer package added), the
 * exact same convention `CompressUploadedVideo` uses for `ffmpeg` — see
 * ADR-007/SETUP.md for the server-side binary requirement, now extended
 * to poppler-utils for this job.
 *
 * Graceful degradation (never blocks the feature): if `pdftoppm`/
 * `pdfinfo` is missing or the job fails for any reason, the ORIGINAL
 * uploaded PDF is left exactly as-is and usable (streamable/viewable) —
 * only processing_status flips to `failed`, logged for a human to
 * investigate. A company that hasn't installed poppler-utils on its
 * server still gets a working (thumbnail-less, frontend falls back to a
 * generic PDF icon) upload, not a broken feature.
 */
class GeneratePdfThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // much shorter than video's 30 min — this is a single-page render

    public function __construct(
        public readonly int $modelId,
        public readonly string $modelClass,
        public readonly string $disk,
    ) {}

    public function handle(): void
    {
        $model = ProductSpecAttachment::withoutGlobalScopes()->find($this->modelId);

        if (! $model) {
            return; // deleted before the job ran — nothing to do
        }

        $sourceRelativePath = $model->file_path;

        if (! $sourceRelativePath) {
            return;
        }

        $model->update(['processing_status' => MediaProcessingStatus::Processing->value]);

        $disk = Storage::disk($this->disk);
        $sourceAbsolute = $disk->path($sourceRelativePath);
        $thumbnailRelativeNoExt = dirname($sourceRelativePath).'/'.Str::uuid()->toString();
        $thumbnailAbsoluteNoExt = $disk->path($thumbnailRelativeNoExt);

        try {
            if (! is_file($sourceAbsolute)) {
                throw new \RuntimeException("Source file missing on disk: {$sourceAbsolute}");
            }

            $pageCount = $this->readPageCount($sourceAbsolute);

            // -singlefile produces a deterministic "{output}.jpg" instead
            // of pdftoppm's default "{output}-1.jpg" numbering, which
            // would otherwise require globbing to locate.
            $process = new Process([
                // TASK-093 — configurable path, same reason as ffmpeg.
                (string) config('media.binaries.pdftoppm'), '-jpeg', '-f', '1', '-l', '1', '-r', '100', '-singlefile', $sourceAbsolute, $thumbnailAbsoluteNoExt,
            ]);
            $process->setTimeout($this->timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $thumbnailRelativePath = $thumbnailRelativeNoExt.'.jpg';

            if (! is_file($disk->path($thumbnailRelativePath))) {
                throw new \RuntimeException("pdftoppm reported success but no thumbnail found at {$thumbnailRelativePath}");
            }

            $model->update([
                'thumbnail_path' => $thumbnailRelativePath,
                'page_count' => $pageCount,
                'processing_status' => MediaProcessingStatus::Ready->value,
            ]);
        } catch (\Throwable $e) {
            Log::warning("GeneratePdfThumbnail: thumbnail generation failed for {$this->modelClass}#{$this->modelId} — original upload left usable as-is. ".$e->getMessage());

            $thumbnailRelativePath = $thumbnailRelativeNoExt.'.jpg';
            if ($disk->exists($thumbnailRelativePath)) {
                $disk->delete($thumbnailRelativePath);
            }

            $model->update(['processing_status' => MediaProcessingStatus::Failed->value]);
        }
    }

    /**
     * Best-effort — a parsing failure never fails the whole job (the
     * thumbnail render is the primary goal; page_count is a bonus).
     */
    private function readPageCount(string $sourceAbsolute): ?int
    {
        try {
            $process = new Process(['pdfinfo', $sourceAbsolute]);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            foreach (explode("\n", $process->getOutput()) as $line) {
                if (preg_match('/^Pages:\s*(\d+)/', trim($line), $matches)) {
                    return (int) $matches[1];
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('GeneratePdfThumbnail: page count parsing failed — thumbnail generation continues. '.$e->getMessage());

            return null;
        }
    }
}
