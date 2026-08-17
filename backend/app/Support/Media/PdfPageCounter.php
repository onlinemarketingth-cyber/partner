<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * ADR-028 §2.3 — reads a PDF's real page count with `pdfinfo`
 * (poppler-utils), so the PDF completion gate has a SERVER-KNOWN
 * denominator.
 *
 * Why this matters: without it, `total_pages` is whatever the browser
 * says, and a learner who reports "this document has 1 page, and I am on
 * page 1" satisfies a 100% rule instantly. ModuleLessonProgressService
 * makes the client-reported count monotonic as a partial mitigation, but
 * a count we measured ourselves is the only real answer.
 *
 * Extracted from GeneratePdfThumbnail::readPageCount(), which does the
 * same parse for ADR-008 spec attachments. That job is left alone
 * deliberately (it is working, queued, and out of this sprint's scope) —
 * but its copy hard-codes the bare `pdfinfo` command, which TASK-093
 * showed does not resolve on shared hosting. This one reads the
 * configured path.
 *
 * Best-effort by contract: every failure path returns null, and callers
 * must treat null as "unknown", never as zero. Poppler is already a
 * documented deployment requirement (SETUP.md, ADR-008) but the app has
 * always degraded gracefully without it, and that does not change here.
 */
class PdfPageCounter
{
    private const TIMEOUT_SECONDS = 30;

    public static function count(string $absolutePath): ?int
    {
        try {
            if (! is_file($absolutePath)) {
                return null;
            }

            $process = new Process([(string) config('media.binaries.pdfinfo'), $absolutePath]);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            foreach (explode("\n", $process->getOutput()) as $line) {
                if (preg_match('/^Pages:\s*(\d+)/', trim($line), $matches) === 1) {
                    $pages = (int) $matches[1];

                    return $pages > 0 ? $pages : null;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('PdfPageCounter: page count unavailable — the PDF stays fully readable, but its completion gate falls back to the client-reported page count (ADR-028 §2.3). '.$e->getMessage());

            return null;
        }
    }
}
