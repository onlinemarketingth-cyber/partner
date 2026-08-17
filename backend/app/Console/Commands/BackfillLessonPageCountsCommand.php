<?php

namespace App\Console\Commands;

use App\Enums\MediaSourceType;
use App\Enums\ModuleContentType;
use App\Models\ModuleLesson;
use App\Support\Media\PdfPageCounter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-028 §2.3 — count the pages of PDF lessons that were uploaded before
 * `pdfinfo` (poppler-utils) was available on the machine.
 *
 * WHY THIS EXISTS
 * ---------------
 * `module_lessons.page_count` is measured ONCE, at upload. That is the right
 * design (re-reading the file on every request would be wasteful), but it
 * means a host that gained poppler *after* some lessons were uploaded is left
 * with a permanent gap: those lessons fall back to trusting the page count
 * the learner's browser reports, which ADR-029 §2.7 notes is forgeable and
 * therefore a weaker completion gate.
 *
 * Re-uploading every affected file by hand is the alternative. This is the
 * same operation without the manual work.
 *
 * Safe to re-run: it only touches rows where page_count IS NULL, and a file
 * it still cannot read is left alone rather than written as 0 — a 0 would
 * look like a measured answer and silently make the PDF gate unsatisfiable.
 */
class BackfillLessonPageCountsCommand extends Command
{
    protected $signature = 'academy:backfill-page-counts {--dry-run : List what would change without writing}';

    protected $description = 'Count pages for uploaded PDF lessons that have no page_count yet (ADR-028 §2.3)';

    public function handle(): int
    {
        // withoutGlobalScopes: this runs from the console with no
        // authenticated user, so TenantScope would be a no-op anyway — stated
        // explicitly rather than relied on by accident (BR-6 reasoning is the
        // same as every other console command in this codebase).
        $lessons = ModuleLesson::withoutGlobalScopes()
            ->where('content_type', ModuleContentType::Pdf->value)
            ->where('source_type', MediaSourceType::Upload->value)
            ->whereNull('page_count')
            ->whereNotNull('content_ref')
            ->get();

        if ($lessons->isEmpty()) {
            $this->info('ไม่มีบทเรียน PDF ที่ยังไม่มีจำนวนหน้า — ไม่ต้องทำอะไร');

            return self::SUCCESS;
        }

        $this->line("พบ {$lessons->count()} บทเรียนที่ยังไม่มีจำนวนหน้า");

        $disk = Storage::disk('local');
        $updated = 0;
        $failed = 0;

        foreach ($lessons as $lesson) {
            if (! $disk->exists($lesson->content_ref)) {
                $this->warn("  ✗ #{$lesson->id} {$lesson->title} — ไม่พบไฟล์ ({$lesson->content_ref})");
                $failed++;

                continue;
            }

            $pages = PdfPageCounter::count($disk->path($lesson->content_ref));

            if ($pages === null) {
                // The most likely cause on a fresh install: pdfinfo is not on
                // PATH for the PHP process. Say so, because "ไม่สำเร็จ" alone
                // sends the operator hunting through the file instead of the
                // environment.
                $this->warn("  ✗ #{$lesson->id} {$lesson->title} — อ่านจำนวนหน้าไม่ได้ (ตรวจว่า PHP เรียก pdfinfo ได้หรือไม่ — ดู PDFINFO_PATH ใน .env)");
                $failed++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  · #{$lesson->id} {$lesson->title} → {$pages} หน้า (dry-run, ยังไม่บันทึก)");
                $updated++;

                continue;
            }

            $lesson->forceFill(['page_count' => $pages])->save();
            $this->info("  ✓ #{$lesson->id} {$lesson->title} → {$pages} หน้า");
            $updated++;
        }

        $this->newLine();
        $this->line($this->option('dry-run')
            ? "สรุป (dry-run): อ่านได้ {$updated} · อ่านไม่ได้ {$failed}"
            : "สรุป: บันทึกแล้ว {$updated} · อ่านไม่ได้ {$failed}");

        // Non-zero only when nothing at all could be read — that is an
        // environment problem worth failing a deploy over. A partial result
        // (one corrupt file among many) is not.
        return $updated === 0 ? self::FAILURE : self::SUCCESS;
    }
}
