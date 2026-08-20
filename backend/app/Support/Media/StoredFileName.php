<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * TASK-220 — one place that decides the extension a stored upload gets.
 *
 * WHY THIS EXISTS
 * ---------------
 * Sixteen call sites built their stored filename as
 * `Str::uuid().'.'.$file->getClientOriginalExtension()`. That method
 * returns the extension from the CLIENT-SUPPLIED filename, and returns an
 * EMPTY STRING when the upload had none — so a file the browser sent as
 * "logo" (no dot) was stored at `.../<uuid>.`, a path ending in a bare
 * dot. Apache serves that with no usable Content-Type and the browser
 * renders nothing: an "upload succeeded, image is broken" report with
 * nothing wrong on disk and nothing in any log.
 *
 * It is also client input reaching a filesystem path. `getClientOriginal*`
 * is untrusted by definition; guessing from the file's actual MIME type
 * first means a caller cannot choose the extension at all in the normal
 * case.
 *
 * ModuleLessonService::safeExtension() already did exactly this, correctly,
 * for Academy lesson files (TASK-093). This is that method promoted to a
 * shared helper rather than copied a fifteenth time — one rule, one place.
 *
 * ORDER, and why:
 *   1. `$file->extension()` — Symfony's guess from the real MIME type.
 *      Authoritative: it describes the bytes, not the name.
 *   2. the client extension, stripped to [a-z0-9]. A `.` or `..` or
 *      `tar.gz` cannot survive this, so nothing here can climb a directory
 *      or reintroduce the empty-extension bug.
 *   3. 'bin'. Never an empty string, so a stored path can never end in a
 *      bare dot.
 */
final class StoredFileName
{
    public static function extensionFor(UploadedFile $file): string
    {
        $guessed = $file->extension();

        if (is_string($guessed) && $guessed !== '') {
            return $guessed;
        }

        return preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension())) ?: 'bin';
    }

    /**
     * The whole filename: a random name plus a trustworthy extension.
     *
     * The original filename is never reused — it is client input, and
     * several of these directories are shared across a company, so a
     * predictable name is both a collision and a way to guess a
     * neighbour's URL. Callers that need the human name (client documents,
     * sales materials) already store it in a separate column.
     */
    public static function random(UploadedFile $file, string $prefix = ''): string
    {
        return $prefix.Str::uuid()->toString().'.'.self::extensionFor($file);
    }
}
