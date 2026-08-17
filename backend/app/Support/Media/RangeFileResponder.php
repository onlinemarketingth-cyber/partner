<?php

namespace App\Support\Media;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-143 / ADR-028 §2.5 — RFC 9110 §14 byte-range support for our
 * PRIVATE media streams.
 *
 * WHY THIS EXISTS
 * `Storage::disk()->response()` answers 200 with the whole file and no
 * Accept-Ranges, so a browser cannot seek: `<video>` must download every
 * preceding byte before it can jump. "Resume at 18:42" on a 200 MB lesson
 * video therefore meant "download 200 MB, then jump" — which is why
 * ADR-028 §2.5 calls range support a DEPENDENCY of the resume feature,
 * not a nice-to-have.
 *
 * WHAT THIS DELIBERATELY IS NOT
 * The easy way to make a video seekable is to put it on a public disk and
 * let the web server handle ranges. That is a §5 rule 6 violation and an
 * automatic rejection (TASK-142/143 R4): these files are tenant-scoped
 * course material and client-facing collateral. Authorization therefore
 * still runs in the Controller BEFORE this class is ever called, and this
 * class never sees a request it was not already told to serve. Nothing
 * here relaxes an auth check; it only changes which bytes come back.
 *
 * The parsing is written out explicitly rather than delegated to
 * Symfony's BinaryFileResponse::prepare(), for two reasons: the behaviour
 * that matters (206/416/Content-Range) is then visible and directly
 * testable in this repo, and it does not depend on a framework internal
 * that could change semantics under us on a minor upgrade.
 *
 * Single-range only. RFC 9110 §14.2 explicitly permits a server to ignore
 * a Range header it does not wish to satisfy and answer 200 with the full
 * representation, which is what a multipart/byteranges request gets here —
 * no player in this app asks for one, and a half-correct multipart
 * implementation would be worse than none.
 */
class RangeFileResponder
{
    public const DISPOSITION_INLINE = 'inline';

    public const DISPOSITION_ATTACHMENT = 'attachment';

    /**
     * Typed against the concrete FilesystemAdapter (what Storage::disk()
     * actually returns) rather than the Filesystem contract, because
     * mimeType() lives on the adapter, not on the interface.
     *
     * @param  FilesystemAdapter  $disk  the PRIVATE disk the file lives on
     * @param  string  $path  path relative to that disk
     * @param  string  $disposition  self::DISPOSITION_* — presentation only, never authorization
     * @param  string|null  $filename  suggested download name; defaults to the stored basename
     */
    public static function respond(
        FilesystemAdapter $disk,
        string $path,
        Request $request,
        string $disposition = self::DISPOSITION_INLINE,
        ?string $filename = null,
    ): StreamedResponse {
        abort_unless($disk->exists($path), 404);

        $size = (int) $disk->size($path);
        $range = self::parseRange($request->header('Range'), $size);

        $headers = [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            // Advertising this is what makes a browser attempt a range
            // request at all — without it players fall back to buffering
            // the whole file.
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => self::contentDisposition($disposition, $filename ?? basename($path)),
            // §5 rule 6 — a tenant-scoped file must never sit in a shared
            // proxy cache where the next request could be served it
            // without passing the Policy again.
            'Cache-Control' => 'private, no-store, max-age=0',
        ];

        // Unsatisfiable range — RFC 9110 §15.5.17. Answering 200 with the
        // whole file here (the naive fallback) would silently hand a
        // seeking player megabytes it did not ask for.
        if ($range === false) {
            $headers['Content-Range'] = "bytes */{$size}";
            $headers['Content-Length'] = '0';

            return new StreamedResponse(fn () => null, 416, $headers);
        }

        [$start, $end] = $range ?? [0, max($size - 1, 0)];
        $length = $size === 0 ? 0 : $end - $start + 1;

        $status = 200;

        if ($range !== null) {
            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $headers['Content-Length'] = (string) $length;

        return new StreamedResponse(function () use ($disk, $path, $start, $length) {
            if ($length <= 0) {
                return;
            }

            $source = $disk->readStream($path);

            if ($source === null || $source === false) {
                return;
            }

            $output = fopen('php://output', 'wb');

            try {
                // stream_copy_to_stream's $offset argument seeks (or skips
                // forward on a non-seekable stream) without ever holding
                // the file in memory — the whole point on a 200 MB video.
                stream_copy_to_stream($source, $output, $length, $start);
            } finally {
                fclose($source);
                fclose($output);
            }
        }, $status, $headers);
    }

    /**
     * @return array{0: int, 1: int}|false|null
     *                                          array — a satisfiable single range [start, end] (inclusive)
     *                                          null  — no range asked for, or one we choose to ignore: serve the whole file
     *                                          false — asked for, but unsatisfiable: 416
     */
    private static function parseRange(?string $header, int $size): array|false|null
    {
        $header = $header === null ? '' : trim($header);

        if ($header === '' || $size === 0) {
            return null;
        }

        // Single range only; anything else (multipart, a non-`bytes` unit,
        // garbage) is ignored per RFC 9110 §14.2 — see the class docblock.
        if (preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) !== 1) {
            return null;
        }

        [, $rawStart, $rawEnd] = $matches;

        if ($rawStart === '' && $rawEnd === '') {
            return null;
        }

        if ($rawStart === '') {
            // Suffix form `bytes=-N` — the LAST N bytes.
            $suffixLength = (int) $rawEnd;

            if ($suffixLength <= 0) {
                return false;
            }

            $start = max(0, $size - $suffixLength);
            $end = $size - 1;
        } else {
            $start = (int) $rawStart;
            $end = $rawEnd === '' ? $size - 1 : (int) $rawEnd;

            // A last-byte-pos past EOF is clamped, not rejected
            // (RFC 9110 §14.1.2) — only a first-byte-pos past EOF is 416.
            $end = min($end, $size - 1);
        }

        if ($start >= $size || $start > $end) {
            return false;
        }

        return [$start, $end];
    }

    /**
     * Built by hand rather than via a header helper so the escaping is
     * visible: the filename reaches here from stored data and must never
     * be able to inject a second header or break out of the quoted string.
     * Uploaded files are stored as `{uuid}.{ext}`, so the sanitiser below
     * is a belt-and-braces guard, not the primary defence.
     */
    private static function contentDisposition(string $disposition, string $filename): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'file';

        return $disposition.'; filename="'.$safe.'"';
    }
}
