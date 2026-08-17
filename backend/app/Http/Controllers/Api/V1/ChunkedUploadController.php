<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChunkedUpload;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TASK-094 — receive a large file as a sequence of small requests.
 *
 * WHY (2026-08-03, human-confirmed): production is Hostinger shared
 * hosting; a 44MB video 413'd because PHP enforces `post_max_size` per
 * request. Sending 5MB at a time means no PHP limit has to be raised
 * anywhere — the human's stated constraint was specifically that
 * changing server limits would be a problem in production.
 *
 * FLOW
 *   POST /uploads/init   -> { token, chunk_bytes, max_bytes }
 *   POST /uploads/{token}/chunk  (repeat, in order)  -> { received_bytes, complete }
 *   ...then the caller passes `upload_token` to the normal create
 *      endpoint (see ResolveChunkedUpload middleware), which is what
 *      actually validates mime/size and creates the record.
 *
 * This controller deliberately does NOT create any domain record. It only
 * reassembles bytes; every business rule (allowed mime types, per-company
 * video cap, who may attach media to which product) stays where it
 * already lives, in the existing Form Requests and Policies.
 *
 * SECURITY
 *  - The token is generated here, never accepted from the client. A
 *    client-chosen id would let one tenant append into another tenant's
 *    in-flight file (BR-6).
 *  - ChunkedUpload carries TenantScope, so another company's token is
 *    "not found" rather than "forbidden" — no existence leak.
 *  - `received_bytes` is measured server-side and checked against
 *    `max_bytes` on every chunk. Trusting a per-chunk size only would let
 *    an attacker send unlimited chunks and fill the disk.
 */
class ChunkedUploadController extends Controller
{
    /** Chunk sessions live on the private 'local' disk, never 'public'. */
    private const DISK = 'local';

    public function __construct(private readonly VideoProcessingSettingService $videoSettings) {}

    public function init(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'size_bytes' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // The ceiling is the company's own configured video cap (BR-7 —
        // admin-editable, never hardcoded). It is applied to every file
        // type here purely as a disk guard; the REAL per-type limit is
        // still the `max:` rule on the create endpoint's Form Request.
        $maxBytes = $this->videoSettings->forCompany($user->company_id)['max_upload_mb'] * 1024 * 1024;

        if (($data['size_bytes'] ?? 0) > $maxBytes) {
            throw ValidationException::withMessages([
                'size_bytes' => 'ไฟล์ใหญ่เกินขนาดที่บริษัทกำหนด ('.round($maxBytes / 1024 / 1024).' MB)',
            ]);
        }

        $token = Str::random(64);

        $upload = ChunkedUpload::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'token' => $token,
            'original_filename' => $data['filename'],
            'mime_type' => $data['mime_type'] ?? null,
            'declared_bytes' => $data['size_bytes'] ?? null,
            'received_bytes' => 0,
            'max_bytes' => $maxBytes,
            'part_path' => "chunked-uploads/{$token}.part",
        ]);

        // Create the empty target now so the first chunk's append has
        // something to open, and so a session with zero chunks still
        // leaves a file for uploads:prune to clean up.
        Storage::disk(self::DISK)->put($upload->part_path, '');

        return response()->json([
            'data' => [
                'token' => $token,
                'chunk_bytes' => $this->safeChunkBytes(),
                'max_bytes' => $maxBytes,
            ],
        ], 201);
    }

    /**
     * The largest chunk THIS PHP will actually accept.
     *
     * Bug fix (2026-08-03, human: a 2.0MB mp4 failed with "The file failed
     * to upload." even after chunking shipped). Two limits apply to an
     * upload, and the first version only respected one of them:
     *
     *   post_max_size       — the whole request body
     *   upload_max_filesize — EACH FILE inside it, and a chunk IS a file
     *
     * PHP's stock `upload_max_filesize` is 2M. So a 5MB chunk is rejected
     * by the same rule that rejected the original file, and PHP reports it
     * as an upload ERROR rather than an over-limit request — which Laravel
     * surfaces as the generic "The file failed to upload.", giving no hint
     * that a size limit was involved at all.
     *
     * Reading the live ini values instead of assuming them also means a
     * host with generous limits automatically gets bigger chunks (fewer
     * round trips) with no config change.
     */
    private function safeChunkBytes(): int
    {
        $configured = (int) config('media.upload.chunk_mb') * 1024 * 1024;

        $limits = array_filter([
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ]);

        if ($limits === []) {
            return $configured;
        }

        // 256KB headroom for the multipart envelope and the other form
        // fields, which also count toward post_max_size.
        $ceiling = min($limits) - (256 * 1024);

        // Never return <= 0 (a pathologically small ini would otherwise
        // produce an infinite upload loop client-side).
        return max(256 * 1024, min($configured, $ceiling));
    }

    /** Parse PHP's ini shorthand ("2M", "8M", "512K") into bytes. */
    private static function iniBytes(string $key): int
    {
        $raw = trim((string) ini_get($key));

        if ($raw === '') {
            return 0;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    public function chunk(Request $request, string $token): JsonResponse
    {
        $request->validate([
            // Not 'file' — a chunk is a raw byte range, not a whole
            // document, so mime/extension rules are meaningless here and
            // would reject a mid-file slice of a valid mp4.
            'chunk' => ['required', 'file'],
        ]);

        $upload = ChunkedUpload::where('token', $token)->firstOrFail();

        if ($upload->completed_at !== null) {
            throw ValidationException::withMessages([
                'chunk' => 'การอัปโหลดนี้เสร็จสิ้นไปแล้ว',
            ]);
        }

        $chunk = $request->file('chunk');
        $incoming = (int) $chunk->getSize();

        if ($upload->received_bytes + $incoming > $upload->max_bytes) {
            // Delete the part immediately: an over-limit session has no
            // legitimate continuation, and leaving it costs disk on a host
            // that is already half-way through its inode quota.
            Storage::disk(self::DISK)->delete($upload->part_path);
            $upload->delete();

            throw ValidationException::withMessages([
                'chunk' => 'ไฟล์ใหญ่เกินขนาดที่บริษัทกำหนด ('.round($upload->max_bytes / 1024 / 1024).' MB)',
            ]);
        }

        // Append, not store-as-new-file: one inode per upload session
        // regardless of how many chunks arrive.
        $absolute = Storage::disk(self::DISK)->path($upload->part_path);
        $handle = fopen($absolute, 'ab');

        if ($handle === false) {
            throw ValidationException::withMessages(['chunk' => 'เขียนไฟล์ชั่วคราวไม่สำเร็จ']);
        }

        try {
            fwrite($handle, (string) file_get_contents($chunk->getRealPath()));
        } finally {
            fclose($handle);
        }

        $upload->received_bytes += $incoming;

        // "Complete" is decided by the declared size when the client gave
        // one; otherwise the caller signals it explicitly. Never by chunk
        // count — a retried chunk would break that.
        $isLast = $request->boolean('is_last')
            || ($upload->declared_bytes !== null && $upload->received_bytes >= $upload->declared_bytes);

        if ($isLast) {
            $upload->completed_at = now();
        }

        $upload->save();

        return response()->json([
            'data' => [
                'received_bytes' => $upload->received_bytes,
                'complete' => $isLast,
            ],
        ]);
    }
}
