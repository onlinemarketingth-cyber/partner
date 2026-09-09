<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-09 (human: "หน้า frontend load รูปมาที่หลังประสบการณ์ไม่ดี
 * ค่อยทำให้ภาพชัดขึ้นเรื่อยๆ จนโหลดเสร็จได้หรือไม่").
 *
 * A ~20-pixel-wide copy of the picture, stored as a base64 data URI on the
 * media row itself. It ships INSIDE the JSON that already lists the
 * products, so the blurred shape of every photo is on screen in the same
 * response that names them — no second request, nothing to wait for, and
 * nothing to authorise.
 *
 * ── WHY A COLUMN AND NOT A FILE ──
 *
 * A file would be a third request per image, each with its own round trip
 * and its own Policy check, to deliver a few hundred bytes. The whole
 * point of this is to have something on screen BEFORE any of that
 * happens, so it has to travel with the list.
 *
 * `text` rather than a sized string: the exact length depends on the
 * encoder the host has (WebP, PNG or JPEG — see ImageThumbnailer), and a
 * VARCHAR chosen too tight would silently truncate into a broken data URI
 * on exactly the hosts that differ from ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->text('placeholder')->nullable()->after('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->dropColumn('placeholder');
        });
    }
};
