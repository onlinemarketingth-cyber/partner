<?php

namespace Tests\Unit\Support;

use App\Support\Media\ImageThumbnailer;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 2026-09-09 (human: "รูปสินค้าไม่มีไฟล์ย่อเลย … รูปขนาด 2 MB ถูกโหลดมา
 * ทั้งก้อนเพื่อแสดงเป็นสี่เหลี่ยม 52 พิกเซล").
 *
 * The unit half: the resizer itself, with no product, controller or
 * database anywhere near it.
 *
 * What these pin is mostly what the resizer REFUSES to do. Making a
 * smaller picture is the easy part; the ways this goes wrong in
 * production are a photo that comes out squashed, a packshot whose
 * transparent background turns black, a phone photo lying on its side,
 * and a corrupt upload taking the whole request down with it. Each of
 * those has a test below.
 */
class ImageThumbnailerTest extends TestCase
{
    private const DIRECTORY = 'product-media/platform/7';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function disk()
    {
        return Storage::disk('local');
    }

    /**
     * A real image with real content — not a blank canvas, which any
     * encoder compresses to almost nothing and would make the size
     * assertions meaningless.
     */
    private function putPhoto(string $name, int $width, int $height, string $format = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 8) {
            $colour = imagecolorallocate($image, ($x * 7) % 256, ($x * 3) % 256, 200);
            imagefilledrectangle($image, $x, 0, $x + 7, $height - 1, $colour === false ? 0 : $colour);
        }

        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = self::DIRECTORY."/{$name}";
        $this->disk()->put($path, $bytes);

        return $path;
    }

    private function putTransparentPng(string $name, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $clear === false ? 0 : $clear);

        // A solid blob in the middle, so the picture is not ENTIRELY
        // transparent — that would pass this test for the wrong reason.
        $solid = imagecolorallocatealpha($image, 220, 30, 30, 0);
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), $solid === false ? 0 : $solid);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = self::DIRECTORY."/{$name}";
        $this->disk()->put($path, $bytes);

        return $path;
    }

    /** @return array{0: int, 1: int} */
    private function dimensionsOf(string $path): array
    {
        $size = getimagesizefromstring((string) $this->disk()->get($path));

        $this->assertNotFalse($size, 'ไฟล์ย่อที่สร้างออกมาต้องเป็นไฟล์รูปที่อ่านได้จริง');

        return [(int) $size[0], (int) $size[1]];
    }

    // ── It makes a smaller picture ───────────────────────────────────

    public function test_a_large_landscape_photo_is_bounded_by_the_long_edge(): void
    {
        $source = $this->putPhoto('big.jpg', 1600, 1200);

        $thumbnail = ImageThumbnailer::generate($this->disk(), $source);

        $this->assertNotNull($thumbnail);
        $this->assertSame([ImageThumbnailer::MAX_EDGE, 360], $this->dimensionsOf($thumbnail));
    }

    public function test_a_portrait_photo_keeps_its_shape(): void
    {
        // The classic failure of a naive resize: forcing both edges to the
        // target and returning a squashed picture. A phone photo is
        // portrait, so this is the common case, not the exotic one.
        $source = $this->putPhoto('tall.jpg', 900, 1800);

        $thumbnail = ImageThumbnailer::generate($this->disk(), $source);

        $this->assertNotNull($thumbnail);
        $this->assertSame([240, ImageThumbnailer::MAX_EDGE], $this->dimensionsOf($thumbnail));
    }

    public function test_the_thumbnail_is_actually_smaller_on_disk(): void
    {
        // The entire point. A "thumbnail" that is not smaller than the
        // original is worse than none — it is a second file to keep.
        $source = $this->putPhoto('heavy.jpg', 2400, 1800);

        $thumbnail = ImageThumbnailer::generate($this->disk(), $source);

        $this->assertNotNull($thumbnail);
        $this->assertLessThan($this->disk()->size($source), $this->disk()->size($thumbnail));
    }

    public function test_it_lands_beside_the_original(): void
    {
        /*
         * The per-owner directory layout (OwnerDirectory) is what keeps a
         * company's files apart from another's, and what
         * AuditMediaFilesCommand walks when it looks for orphans. A
         * thumbnail written anywhere else would be invisible to both.
         */
        $source = $this->putPhoto('beside.jpg', 1000, 1000);

        $thumbnail = ImageThumbnailer::generate($this->disk(), $source);

        $this->assertNotNull($thumbnail);
        $this->assertSame(self::DIRECTORY, dirname($thumbnail));
        $this->assertNotSame($source, $thumbnail);
    }

    // ── It knows when NOT to ─────────────────────────────────────────

    public function test_an_image_already_smaller_than_the_target_is_left_alone(): void
    {
        // A 300 px logo does not need a 480 px copy of itself. Null means
        // the caller streams the original, which is already right-sized.
        $source = $this->putPhoto('small.jpg', 300, 200);

        $this->assertNull(ImageThumbnailer::generate($this->disk(), $source));
    }

    public function test_a_file_that_is_not_an_image_is_declined_quietly(): void
    {
        // An upload can be anything. This must be a null and a log line,
        // never an exception — the row it belongs to is already saved.
        $this->disk()->put(self::DIRECTORY.'/notes.txt', 'this is not a picture');

        $this->assertNull(ImageThumbnailer::generate($this->disk(), self::DIRECTORY.'/notes.txt'));
    }

    public function test_a_truncated_image_is_declined_quietly(): void
    {
        $source = $this->putPhoto('corrupt.jpg', 1200, 900);
        $this->disk()->put($source, substr((string) $this->disk()->get($source), 0, 200));

        $this->assertNull(ImageThumbnailer::generate($this->disk(), $source));
    }

    public function test_a_missing_file_is_declined_quietly(): void
    {
        $this->assertNull(ImageThumbnailer::generate($this->disk(), self::DIRECTORY.'/gone.jpg'));
    }

    // ── It does not wreck the picture ────────────────────────────────

    public function test_a_transparent_background_survives(): void
    {
        /*
         * Packshots — a product cut out on transparency — are the normal
         * shape of a supplement photo. An untouched GD canvas is BLACK,
         * so getting this wrong does not produce a subtle artefact: it
         * produces a black rectangle around every product on the
         * storefront.
         */
        $source = $this->putTransparentPng('packshot.png', 1200, 1200);

        $thumbnail = ImageThumbnailer::generate($this->disk(), $source);

        $this->assertNotNull($thumbnail);

        $image = imagecreatefromstring((string) $this->disk()->get($thumbnail));
        $this->assertNotFalse($image);

        $corner = imagecolorsforindex($image, imagecolorat($image, 1, 1));
        $middle = imagecolorsforindex($image, imagecolorat($image, (int) (imagesx($image) / 2), (int) (imagesy($image) / 2)));
        imagedestroy($image);

        $this->assertGreaterThan(100, $corner['alpha'], 'มุมภาพต้องยังโปร่งใสอยู่ ไม่ใช่กลายเป็นสีดำ');
        $this->assertLessThan(30, $middle['alpha'], 'ตัวสินค้าตรงกลางต้องยังทึบอยู่');
    }

    // ── The blur placeholder ─────────────────────────────────────────

    public function test_the_placeholder_is_a_tiny_inline_data_uri(): void
    {
        /*
         * 2026-09-09 (human: "ค่อยทำให้ภาพชัดขึ้นเรื่อยๆ").
         *
         * The size limit IS the feature. This travels inside the JSON that
         * lists every product on the page, so a "placeholder" of a few
         * kilobytes would make the list slower to arrive than the images
         * it was meant to cover for.
         */
        $source = $this->putPhoto('hero.jpg', 1600, 1200);

        $placeholder = ImageThumbnailer::placeholder($this->disk(), $source);

        $this->assertNotNull($placeholder);
        $this->assertMatchesRegularExpression('#^data:image/(webp|png|jpeg);base64,#', $placeholder);
        $this->assertLessThan(2000, strlen($placeholder), 'ภาพเบลอตัวอย่างต้องเล็กพอที่จะเดินทางมากับ JSON');
    }

    public function test_the_placeholder_is_a_real_readable_image(): void
    {
        // A data URI the browser cannot decode renders as a broken-image
        // icon — visibly worse than the grey box it replaces.
        $source = $this->putPhoto('shape.jpg', 1200, 600);

        $placeholder = (string) ImageThumbnailer::placeholder($this->disk(), $source);
        $bytes = base64_decode(substr($placeholder, (int) strpos($placeholder, ',') + 1), true);

        $this->assertIsString($bytes);
        $size = getimagesizefromstring($bytes);

        $this->assertNotFalse($size);
        $this->assertSame([20, 10], [(int) $size[0], (int) $size[1]], 'ต้องคงสัดส่วนภาพเดิมไว้');
    }

    public function test_the_placeholder_never_enlarges_a_tiny_image(): void
    {
        // A 12px image blown up to 20px would land in the database bigger
        // than the picture it came from.
        $source = $this->putPhoto('spacer.png', 12, 8, 'png');

        $placeholder = (string) ImageThumbnailer::placeholder($this->disk(), $source);
        $bytes = (string) base64_decode(substr($placeholder, (int) strpos($placeholder, ',') + 1), true);
        $size = getimagesizefromstring($bytes);

        $this->assertNotFalse($size);
        $this->assertSame([12, 8], [(int) $size[0], (int) $size[1]]);
    }

    public function test_an_unreadable_file_yields_no_placeholder(): void
    {
        $this->disk()->put(self::DIRECTORY.'/notes.txt', 'still not a picture');

        $this->assertNull(ImageThumbnailer::placeholder($this->disk(), self::DIRECTORY.'/notes.txt'));
        $this->assertNull(ImageThumbnailer::placeholder($this->disk(), self::DIRECTORY.'/nowhere.jpg'));
    }

    public function test_a_custom_target_edge_is_honoured(): void
    {
        // Not currently used by the app — but the constant is a default,
        // not a hard-coded number, and a caller that passes something
        // else must get what it asked for.
        $source = $this->putPhoto('custom.jpg', 2000, 1000);

        $thumbnail = ImageThumbnailer::generate($this->disk(), $source, 200);

        $this->assertNotNull($thumbnail);
        $this->assertSame([200, 100], $this->dimensionsOf($thumbnail));
    }
}
