<?php

namespace App\Jobs;

use App\Enums\MediaProcessingStatus;
use App\Models\ModuleLesson;
use App\Models\ProductMedia;
use App\Models\ProductSalesMaterial;
use App\Services\Catalog\VideoProcessingSettingService;
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
 * ADR-007 — compresses an uploaded video to the OWNING COMPANY's
 * configured target resolution/bitrate (VideoProcessingSettingService),
 * shared by Product media, Sales materials, and Academy modules. Shells
 * out to the system `ffmpeg` binary via Symfony Process (already a
 * Laravel dependency — no new Composer package added, per this
 * project's established preference against adding dependencies where a
 * standard-library approach works — see ADR-007/SETUP.md for the
 * server-side `ffmpeg` binary requirement).
 *
 * Graceful degradation (never blocks the feature): if `ffmpeg` is
 * missing or the process fails for any reason, the ORIGINAL uploaded
 * file is left exactly as-is and usable — only processing_status flips
 * to `failed`, logged for a human to investigate. A company that hasn't
 * installed `ffmpeg` on its server still gets working (uncompressed)
 * video uploads, not a broken feature.
 */
class CompressUploadedVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes — generous ceiling for a large upload

    /**
     * @param  class-string<ProductMedia|ProductSalesMaterial|ModuleLesson>  $modelClass
     */
    public function __construct(
        public readonly int $modelId,
        public readonly string $modelClass,
        public readonly string $disk,
    ) {}

    public function handle(VideoProcessingSettingService $settingService): void
    {
        $model = $this->modelClass::withoutGlobalScopes()->find($this->modelId);

        if (! $model) {
            return; // deleted before the job ran — nothing to do
        }

        $pathColumn = $model instanceof ModuleLesson ? 'content_ref' : 'file_path';
        $sourceRelativePath = $model->{$pathColumn};

        if (! $sourceRelativePath) {
            return;
        }

        $model->update(['processing_status' => MediaProcessingStatus::Processing->value]);

        $settings = $settingService->forCompany($model->company_id);
        $resolutionHeight = match ($settings['target_resolution']) {
            '480p' => 480,
            '1080p' => 1080,
            default => 720,
        };

        $disk = Storage::disk($this->disk);
        $sourceAbsolute = $disk->path($sourceRelativePath);
        $compressedRelativePath = dirname($sourceRelativePath).'/'.Str::uuid()->toString().'.mp4';
        $compressedAbsolute = $disk->path($compressedRelativePath);

        try {
            if (! is_file($sourceAbsolute)) {
                throw new \RuntimeException("Source file missing on disk: {$sourceAbsolute}");
            }

            $process = new Process([
                // TASK-093 — configurable path; bare 'ffmpeg' only works
                // when the binary is on the web user's $PATH.
                (string) config('media.binaries.ffmpeg'), '-y', '-i', $sourceAbsolute,
                '-vf', "scale=-2:{$resolutionHeight}",
                '-b:v', "{$settings['target_bitrate_kbps']}k",
                '-c:v', 'libx264', '-preset', 'fast',
                '-c:a', 'aac', '-b:a', '128k',
                $compressedAbsolute,
            ]);
            $process->setTimeout($this->timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $disk->delete($sourceRelativePath);

            $update = [$pathColumn => $compressedRelativePath, 'processing_status' => MediaProcessingStatus::Ready->value];

            if ($model instanceof ProductSalesMaterial) {
                $update['mime_type'] = 'video/mp4';
            }

            // ADR-028 §2.3 — an Academy lesson video's duration is the
            // denominator of the completion gate, so it is recorded here,
            // server-side, and never accepted from the client. Only
            // ModuleLesson carries the column; the other two models have
            // no gate to feed.
            if ($model instanceof ModuleLesson) {
                $update['duration_seconds'] = $this->probeDurationSeconds($compressedAbsolute);
            }

            if ($model instanceof ProductMedia) {
                $update['thumbnail_path'] = $this->generateThumbnail($disk, $compressedAbsolute, dirname($compressedRelativePath));
            }

            $model->update($update);
        } catch (\Throwable $e) {
            Log::warning("CompressUploadedVideo: compression failed for {$this->modelClass}#{$this->modelId} — original upload left usable as-is. ".$e->getMessage());

            if ($disk->exists($compressedRelativePath)) {
                $disk->delete($compressedRelativePath);
            }

            $model->update(['processing_status' => MediaProcessingStatus::Failed->value]);
        }
    }

    /**
     * ADR-028 §2.3 — reads the real duration with `ffprobe`.
     *
     * Best-effort, same graceful-degradation contract as the thumbnail
     * below: a missing or failing binary returns null and never fails the
     * job. The consequence of null is documented at the call site that
     * matters — LessonCompletionGate::videoEarned() treats an unknown
     * duration as "not verifiable" and falls back to the button rather
     * than locking every learner out of every video lesson on a host
     * without ffprobe (TASK-093 says shared hosting frequently is one).
     */
    private function probeDurationSeconds(string $videoAbsolutePath): ?int
    {
        try {
            $process = new Process([
                (string) config('media.binaries.ffprobe'),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $videoAbsolutePath,
            ]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $seconds = (float) trim($process->getOutput());

            // floor, not round: a 599.7s video must not be recorded as
            // 600s, or the 100%-equivalent threshold becomes unreachable.
            return $seconds > 0 ? (int) floor($seconds) : null;
        } catch (\Throwable $e) {
            Log::warning('CompressUploadedVideo: ffprobe duration probe failed — the lesson video is still usable, but its completion gate will fall back to the button (ADR-028 §2.3). '.$e->getMessage());

            return null;
        }
    }

    /**
     * Best-effort — a thumbnail failure never fails the whole job (the
     * video itself already compressed successfully by this point).
     */
    private function generateThumbnail(mixed $disk, string $compressedVideoAbsolute, string $relativeDir): ?string
    {
        $thumbnailRelative = $relativeDir.'/'.Str::uuid()->toString().'.jpg';
        $thumbnailAbsolute = $disk->path($thumbnailRelative);

        try {
            $process = new Process([(string) config('media.binaries.ffmpeg'), '-y', '-ss', '00:00:01', '-i', $compressedVideoAbsolute, '-vframes', '1', $thumbnailAbsolute]);
            $process->setTimeout(60);
            $process->run();

            return $process->isSuccessful() ? $thumbnailRelative : null;
        } catch (\Throwable $e) {
            Log::warning('CompressUploadedVideo: thumbnail generation failed — video itself is still ready. '.$e->getMessage());

            return null;
        }
    }
}
