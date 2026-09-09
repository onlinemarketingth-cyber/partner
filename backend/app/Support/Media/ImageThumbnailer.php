<?php

namespace App\Support\Media;

use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 2026-09-09 (human: "รูปสินค้าไม่มีไฟล์ย่อเลย … รูปขนาด 2 MB ถูกโหลดมา
 * ทั้งก้อนเพื่อแสดงเป็นสี่เหลี่ยม 52 พิกเซล").
 *
 * ── WHAT WAS ACTUALLY HAPPENING ──
 *
 * `thumbnail_path` existed on product_media from the start, but only ONE
 * thing ever wrote it: CompressUploadedVideo, for videos. An uploaded
 * IMAGE got a `file_path` and nothing else, so every place that wanted a
 * small picture — ProductResource::thumbnail_url, the admin catalogue
 * row, the agent's product card — fell through to
 * `route('product-media.stream')`, which is the ORIGINAL FILE. A phone
 * photo straight off a camera roll is 2–5 MB; a catalogue of thirty
 * products was tens of megabytes of transfer to paint a grid of 52-pixel
 * squares, on the mobile connection the agents actually work on.
 *
 * ── WHY GD AND NOT AN IMAGE LIBRARY ──
 *
 * Intervention/Imagine would each pull a dependency tree into a shared-
 * hosting deploy for one operation this codebase performs in one place.
 * ext-gd is already installed (it is what PHP images mean on Hostinger),
 * and this is the same call the library would make. Same reasoning as
 * CompressUploadedVideo shelling out to ffmpeg rather than wrapping it.
 *
 * ── EVERY FAILURE IS A NULL, NEVER AN EXCEPTION ──
 *
 * A thumbnail is an OPTIMISATION. `thumbnail_path` staying null is the
 * behaviour this whole system has had since day one — the caller streams
 * the original, exactly as before. So a corrupt upload, a missing GD
 * format, an image too large to decode inside the memory limit: all of
 * them return null and leave a working upload. Failing the upload
 * instead would turn a slow picture into no picture at all.
 */
final class ImageThumbnailer
{
    /**
     * The longest edge of the generated file.
     *
     * Not 52. The same thumbnail is used by the admin's 52 px catalogue
     * row AND the agent's product card, which is roughly 340 px wide on a
     * phone and drawn on a 2–3× display — so a 52 px file would look like
     * a smear in the place it matters most. 480 covers both at retina
     * density and still lands around 30–60 KB.
     */
    public const MAX_EDGE = 480;

    /**
     * One number for both encoders. WebP at 76 and JPEG at 76 are not the
     * same picture, but they are the same JUDGEMENT — visually clean at the
     * size this file is displayed — and a second constant would only
     * invite the two to drift apart for no reason anyone could state.
     */
    private const QUALITY = 76;

    /**
     * The blur placeholder's longest edge.
     *
     * 20 px, because it is meant to be UNRECOGNISABLE as detail and
     * recognisable as shape — the browser stretches it over the whole tile
     * and blurs it. Larger would cost bytes in a payload that is already
     * carrying every product on the page, for detail that is thrown away
     * by the blur anyway.
     */
    private const PLACEHOLDER_EDGE = 20;

    /** Aggressive on purpose: at 20 px nobody can see the artefacts. */
    private const PLACEHOLDER_QUALITY = 45;

    /**
     * GD decodes to a full uncompressed bitmap: 4 bytes per pixel, plus a
     * second one for the resized copy. 40 MP is ~160 MB and past what a
     * 256 MB PHP process survives, and a fatal OOM would take the whole
     * upload request with it — so an image that big is declined here,
     * while it is still a null instead of a 500.
     */
    private const MAX_SOURCE_PIXELS = 40_000_000;

    /**
     * Produce a small copy of an image beside it on the same disk.
     *
     * @param  FilesystemAdapter  $disk  the PRIVATE disk the original lives on
     * @param  string  $sourcePath  path relative to that disk
     * @return string|null the thumbnail's path relative to the same disk, or
     *                     null when no thumbnail was made (the caller then
     *                     keeps serving the original, as it always has)
     */
    public static function generate(FilesystemAdapter $disk, string $sourcePath, int $maxEdge = self::MAX_EDGE): ?string
    {
        try {
            return self::attempt($disk, $sourcePath, $maxEdge);
        } catch (\Throwable $e) {
            // Deliberately warning, not error: nothing is broken for the
            // person who uploaded — their picture works. This is a line
            // for whoever later asks why one row has no thumbnail.
            Log::warning("ImageThumbnailer: no thumbnail for {$sourcePath} — original left usable as-is. ".$e->getMessage());

            return null;
        }
    }

    /**
     * A ~20 px copy of the picture, as a base64 data URI.
     *
     * 2026-09-09 (human: "ค่อยทำให้ภาพชัดขึ้นเรื่อยๆ จนโหลดเสร็จได้หรือไม่").
     *
     * ── WHAT IT IS FOR ──
     *
     * Every product image in this app is behind an authenticated stream, so
     * the browser cannot simply point an <img> at it: the app fetches it,
     * and until that finishes there is a grey box where the photo goes. A
     * grid of grey boxes that pop into photos one by one is the "โหลดรูปมา
     * ทีหลัง" the human is describing.
     *
     * This is small enough to travel inside the JSON that already lists the
     * products — a few hundred bytes — so the blurred shape of the photo is
     * on screen in the FIRST paint, and sharpens into the real picture when
     * it arrives. Nothing is faster than data you already have.
     *
     * ── GENERATED FROM THE THUMBNAIL, WHEN THERE IS ONE ──
     *
     * Callers pass the thumbnail's path if it exists, and the original's
     * otherwise. Decoding a 480 px file to make a 20 px one costs almost
     * nothing, and gives a result identical to decoding the original a
     * second time.
     *
     * @return string|null a `data:image/…;base64,…` URI, or null if
     *                     the image could not be read at all
     */
    public static function placeholder(FilesystemAdapter $disk, string $sourcePath): ?string
    {
        try {
            return self::attemptPlaceholder($disk, $sourcePath);
        } catch (\Throwable $e) {
            Log::warning("ImageThumbnailer: no blur placeholder for {$sourcePath}. ".$e->getMessage());

            return null;
        }
    }

    private static function attemptPlaceholder(FilesystemAdapter $disk, string $sourcePath): ?string
    {
        $absolute = $disk->path($sourcePath);

        if (! is_file($absolute)) {
            return null;
        }

        $size = @getimagesize($absolute);

        if ($size === false) {
            return null;
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];
        $type = (int) ($size[2] ?? 0);

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $source = self::read($absolute, $type);

        if ($source === null) {
            return null;
        }

        try {
            /*
             * `min(1, …)` — never ENLARGE. An image that is already tiny
             * (a 12 px spacer, say) would otherwise be blown up to 20 px
             * and land in the database bigger than it started.
             */
            $scale = min(1, self::PLACEHOLDER_EDGE / max($width, $height));
            $tiny = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

            if ($tiny === false) {
                return null;
            }

            try {
                self::preserveTransparency($tiny);
                imagecopyresampled($tiny, $source, 0, 0, 0, 0, imagesx($tiny), imagesy($tiny), $width, $height);
                $tiny = self::applyExifOrientation($tiny, $absolute, $type);

                [$mime, $bytes] = self::encode($tiny, $type !== IMAGETYPE_JPEG, self::PLACEHOLDER_QUALITY);

                if ($bytes === null) {
                    return null;
                }

                return 'data:'.$mime.';base64,'.base64_encode($bytes);
            } finally {
                imagedestroy($tiny);
            }
        } finally {
            imagedestroy($source);
        }
    }

    private static function attempt(FilesystemAdapter $disk, string $sourcePath, int $maxEdge): ?string
    {
        $absolute = $disk->path($sourcePath);

        if (! is_file($absolute)) {
            return null;
        }

        $size = @getimagesize($absolute);

        if ($size === false) {
            return null; // not an image at all, or a format GD cannot read
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];
        $type = (int) ($size[2] ?? 0);

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        /*
         * ALREADY SMALL — no thumbnail, on purpose.
         *
         * A 300 px logo re-encoded at 480 px is a second file that is not
         * meaningfully smaller than the first, and now has to be kept in
         * step with it forever. Null here means the caller streams the
         * original, which is already the right size.
         */
        if (max($width, $height) <= $maxEdge) {
            return null;
        }

        $source = self::read($absolute, $type);

        if ($source === null) {
            return null;
        }

        try {
            $scale = $maxEdge / max($width, $height);
            $thumbnail = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

            if ($thumbnail === false) {
                return null;
            }

            try {
                self::preserveTransparency($thumbnail);

                imagecopyresampled(
                    $thumbnail, $source,
                    0, 0, 0, 0,
                    imagesx($thumbnail), imagesy($thumbnail),
                    $width, $height,
                );

                /*
                 * Rotate AFTER resizing, not before: the rotation is the
                 * same picture either way, and doing it on the small copy
                 * costs a fraction of the memory of turning a 12 MP
                 * original round.
                 */
                $thumbnail = self::applyExifOrientation($thumbnail, $absolute, $type);

                return self::write($disk, $sourcePath, $thumbnail, $type);
            } finally {
                imagedestroy($thumbnail);
            }
        } finally {
            imagedestroy($source);
        }
    }

    private static function read(string $absolute, int $type): ?GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_PNG => @imagecreatefrompng($absolute),
            IMAGETYPE_GIF => @imagecreatefromgif($absolute),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
            default => false,
        };

        if ($image === false) {
            return null;
        }

        // A palette source (most GIFs, some PNGs) resamples to mush
        // without this — GD picks nearest-colour instead of blending.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * A product photo cut out on transparency is common (packshots), and
     * flattening it onto black — which is what an untouched truecolor
     * canvas does — is the single most visible way to get this wrong.
     */
    private static function preserveTransparency(GdImage $canvas): void
    {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, imagesx($canvas) - 1, imagesy($canvas) - 1, $transparent);
        }

        // Back on for the resample itself, so edges blend rather than
        // hard-replace.
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
    }

    /**
     * Phone cameras store the picture as the sensor read it and record
     * "…and it is on its side" in an EXIF tag. Browsers honour that tag
     * when they draw the ORIGINAL — GD does not when it resamples — so
     * skipping this produces the specific bug where the thumbnail is
     * rotated 90° and the full-size image beside it is not.
     */
    private static function applyExifOrientation(GdImage $thumbnail, string $absolute, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $thumbnail;
        }

        $exif = @exif_read_data($absolute);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if ($orientation <= 1 || $orientation > 8) {
            return $thumbnail;
        }

        // imagerotate turns COUNTER-clockwise for a positive angle.
        $rotation = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        $mirror = in_array($orientation, [2, 4, 5, 7], true);

        if ($rotation !== 0) {
            $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
            $rotated = imagerotate($thumbnail, $rotation, $transparent === false ? 0 : $transparent);

            if ($rotated !== false) {
                imagedestroy($thumbnail);
                imagesavealpha($rotated, true);
                $thumbnail = $rotated;
            }
        }

        if ($mirror) {
            imageflip($thumbnail, IMG_FLIP_HORIZONTAL);
        }

        return $thumbnail;
    }

    /**
     * WebP when the host can write it, because it is the one choice that
     * is both small AND keeps transparency; otherwise PNG for anything
     * that might have an alpha channel and JPEG for the rest. The format
     * is decided per file and recorded in the extension, so nothing else
     * in the system has to know which branch ran — the stream controller
     * reads the mime type off the file.
     */
    private static function write(FilesystemAdapter $disk, string $sourcePath, GdImage $thumbnail, int $type): ?string
    {
        [$mime, $bytes] = self::encode($thumbnail, $type !== IMAGETYPE_JPEG, self::QUALITY);

        if ($bytes === null) {
            return null;
        }

        $extension = match ($mime) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            default => 'jpg',
        };

        /*
         * Beside the original, with its own random name.
         *
         * Same directory means the existing per-owner layout
         * (product-media/{owner}/{product}/…, OwnerDirectory) and
         * AuditMediaFilesCommand's orphan sweep both keep working with no
         * change. A random name rather than "{original}_thumb" so a
         * re-generated thumbnail can never be served from a browser cache
         * that still holds the previous one.
         */
        $directory = dirname($sourcePath);
        $path = ($directory === '.' || $directory === '' ? '' : $directory.'/').Str::uuid()->toString().'.'.$extension;

        return $disk->put($path, $bytes) ? $path : null;
    }

    /**
     * Turn a GD image into bytes, and say what they are.
     *
     * One ladder, used by both the thumbnail and the placeholder, so the
     * two can never disagree about what this host can encode:
     *
     *   WebP — small AND keeps transparency, so it wins whenever the host
     *          can write it (every PHP 8 build with GD in practice);
     *   PNG  — the fallback for anything that might have an alpha channel,
     *          because JPEG would flatten a cut-out packshot onto black;
     *   JPEG — everything else.
     *
     * @return array{0: string, 1: string|null} [mime, bytes] — bytes null on failure
     */
    private static function encode(GdImage $image, bool $mayHaveAlpha, int $quality): array
    {
        [$mime, $write] = match (true) {
            function_exists('imagewebp') => ['image/webp', fn () => imagewebp($image, null, $quality)],
            $mayHaveAlpha => ['image/png', fn () => imagepng($image, null, 7)],
            default => ['image/jpeg', fn () => imagejpeg($image, null, $quality)],
        };

        ob_start();

        try {
            $ok = $write();
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if ($ok === false || ! is_string($bytes) || $bytes === '') {
            return [$mime, null];
        }

        return [$mime, $bytes];
    }
}
