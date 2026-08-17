<?php

namespace App\Http\Middleware;

use App\Models\ChunkedUpload;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-094 — swap a completed `upload_token` for a real uploaded file
 * BEFORE the route's Form Request validates.
 *
 * WHY A MIDDLEWARE AND NOT FOUR SERVICE CHANGES
 * Four endpoints accept media (product media, sales materials, Academy
 * lessons, spec attachments). Each already has a Form Request carrying
 * the rules that matter — the per-company `max:` ceiling, the
 * `mimes:` allow-list, the embed-vs-upload exclusivity — and a Service
 * that consumes an `UploadedFile`. Teaching all four about tokens would
 * duplicate that logic four times and risk one of them forgetting a
 * check.
 *
 * Injecting the file into the request's file bag instead means those
 * rules run UNCHANGED against the reassembled file. A chunked upload is
 * therefore validated exactly as strictly as a direct one — including
 * the mime allow-list, which is the check that actually matters, since
 * the chunk endpoint deliberately cannot inspect mime on a mid-file byte
 * range.
 *
 * The `true` on UploadedFile is Symfony's "test mode": it skips the
 * `is_uploaded_file()` check (this file came from our own storage, not
 * from PHP's upload handler) and makes `move()` use rename() instead of
 * move_uploaded_file(). Without it `storeAs()` throws.
 */
class ResolveChunkedUpload
{
    private const DISK = 'local';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input('upload_token');

        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        // TenantScope on ChunkedUpload makes another company's token
        // simply absent — 404, not 403, so nothing leaks (BR-6 / §5.5).
        $upload = ChunkedUpload::where('token', $token)->firstOrFail();

        if ($upload->completed_at === null) {
            throw ValidationException::withMessages([
                'upload_token' => 'ไฟล์ยังอัปโหลดไม่ครบ กรุณารอให้เสร็จก่อน',
            ]);
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($upload->part_path)) {
            throw ValidationException::withMessages([
                'upload_token' => 'ไฟล์ชั่วคราวหมดอายุแล้ว กรุณาอัปโหลดใหม่',
            ]);
        }

        $request->files->set('file', new UploadedFile(
            $disk->path($upload->part_path),
            $upload->original_filename,
            $upload->mime_type,
            null,
            true,
        ));

        // The Form Requests use `prohibited`/`required` combinations on
        // `file`; leaving an unknown extra key in the input is harmless,
        // but removing it keeps validated() output clean for the Services.
        $request->request->remove('upload_token');

        // The session row is consumed. The .part file itself is NOT
        // deleted here — the Service is about to move it via storeAs(),
        // and deleting first would pull the file out from under it.
        // uploads:prune sweeps anything a failed validation leaves behind.
        $upload->delete();

        return $next($request);
    }
}
