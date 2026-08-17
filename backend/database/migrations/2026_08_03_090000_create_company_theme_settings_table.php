<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-055 / ADR-018 — per-company theming / white-label. One row per
// company (unique company_id) holding every brand value a tenant can
// override: colors, background, Google Font, logos, loading screen and a
// curated set of label overrides. BR-7: EVERY value here is admin-editable
// config/seed — nothing is hardcoded in logic; code only holds a neutral
// default fallback (ThemeService::defaults()). All columns nullable so a
// company with no theme simply falls back to that default. §5/BR-6: the
// row is TenantScope'd via the model.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_theme_settings', function (Blueprint $table) {
            $table->id();
            // Unique — one theme row per company. cascadeOnDelete so a
            // removed company takes its theme with it.
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            // Colors (hex strings incl. leading '#', so length 7).
            $table->string('primary_hex', 7)->nullable();
            $table->string('accent_hex', 7)->nullable();

            // Background: solid | gradient | image. background_config holds
            // colors/angle for solid/gradient; background_image_path the
            // uploaded image (public disk) for the image type.
            $table->string('background_type')->nullable();
            $table->json('background_config')->nullable();
            $table->string('background_image_path')->nullable();

            // Font (Google Font name + selected weights).
            $table->string('font_family')->nullable();
            $table->json('font_weights')->nullable();

            // Logos (public disk paths, all nullable).
            $table->string('logo_nav_path')->nullable();
            $table->string('logo_login_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('logo_loading_path')->nullable();

            // Loading splash config.
            $table->string('loading_bg_hex', 7)->nullable();
            $table->string('loading_message')->nullable();

            // Curated label overrides (key => text map).
            $table->json('label_overrides')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_theme_settings');
    }
};
