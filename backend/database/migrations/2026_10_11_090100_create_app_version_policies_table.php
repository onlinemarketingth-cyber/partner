<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-02 — MOB-13. Which versions of the mobile app may still be used,
 * per platform.
 *
 *   min_supported_version  Below this, the app blocks itself and sends the
 *                          user to the store (an API change it cannot
 *                          survive, a security fix).
 *   latest_version         Below this but at/above the minimum, the app
 *                          may suggest an update without forcing it.
 *   store_url              Where "update" takes them.
 *
 * ── WHY NO company_id ──
 *
 * There is ONE app binary in each store, shared by every company. What it
 * can survive is a fact about the platform's API, not about any tenant, so a
 * per-company minimum would only let one company's admin lock every other
 * company's agents out of the app. Same reasoning, and same shape, as
 * platform_mail_settings and supplier_platform_settings: platform-wide,
 * Super Admin only, no TenantScope.
 *
 * ── WHY A TABLE AND NOT .env ──
 *
 * BR-7: the values change with every release and are an operator's call, so
 * they are editable on a screen rather than by a deploy. Both rows are seeded
 * empty (all NULL = no policy, nobody is blocked) so the screen always has
 * exactly two rows to edit and the public read never has to invent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_version_policies', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16)->unique(); // App\Enums\DevicePlatform
            $table->string('min_supported_version', 32)->nullable();
            $table->string('latest_version', 32)->nullable();
            $table->string('store_url', 2048)->nullable();
            $table->timestamps();
        });

        foreach (['ios', 'android'] as $platform) {
            DB::table('app_version_policies')->insert([
                'platform' => $platform,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_version_policies');
    }
};
