<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-080 (2026-08-03, human request: "ผมอยากได้ระบบข่าวสาร สามารถแสดง
 * เป็นแบบ banner ได้แบบ Product").
 *
 * Announcements can now render as an inline banner carousel — the same
 * treatment storefront_banners already gets on the product page — in
 * ADDITION to the auto-popup modal built in TASK-075/077.
 *
 * Deliberately extends `announcements` rather than creating banner rows in
 * `storefront_banners`. That table has placement/link_type/sort_order but
 * NO scheduling and NO audience targeting, while `announcements` already
 * carries published_at, expires_at, audience, target_cert_tier_id and
 * target_cert_tier_mode. Authoring a banner as a separate storefront_banners
 * row would mean the same news is written twice AND would silently lose all
 * of that — an expired announcement would keep showing as a live banner.
 * Reusing the announcement row means scheduling + BR-1 cert targeting come
 * for free and there is exactly one place to author news.
 * `announcements.image_path` already exists (same public disk, same 5 MB
 * limit, same mime list as storefront_banners), so no new media plumbing.
 *
 * Human-confirmed via AskUserQuestion (2026-08-03):
 *  - banner may appear on Home, Products and the Announcements list;
 *  - modal and banner are NOT exclusive — each announcement picks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Defaults preserve every existing row's current behaviour
            // exactly: modal on, banner off. No data backfill needed.
            $table->boolean('show_as_modal')->default(true)->after('is_pinned');
            $table->boolean('show_as_banner')->default(false)->after('show_as_modal');

            // Which pages the banner appears on. JSON array of
            // AnnouncementBannerPage values. NULL is treated by the
            // frontend as "all pages" so an admin who ticks
            // show_as_banner without choosing pages still gets a
            // working banner rather than an invisible one.
            $table->json('banner_pages')->nullable()->after('show_as_banner');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['show_as_modal', 'show_as_banner', 'banner_pages']);
        });
    }
};
