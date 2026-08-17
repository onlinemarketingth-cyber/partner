<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-161 §3.2 — named colour presets a Company Admin can save the
// company's CURRENT colours into and re-apply later.
//
// §5 rule 1 / BR-6: this is a business table, so it carries `company_id`
// and the model carries TenantScope — there is no "it's only colours"
// exception. Human decision (2026-08-11): a preset is visible to the
// OWNING COMPANY ONLY.
//
// `colors` holds ONLY the colour surface (see
// ThemePresetService::COLOR_FIELDS). Logos, favicon, fonts, labels,
// nav_icon_overrides, recommended_slot_count and background_image_path are
// deliberately NOT in a preset — those are a company's identity or its
// configuration, not a "look" (TASK-161 §3.2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_presets', function (Blueprint $table) {
            $table->id();
            // constrained() also indexes company_id; cascade so a removed
            // company takes its presets with it (same as its theme row).
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('colors');
            // Who saved it. nullOnDelete (not cascade) — deleting the admin
            // who created a preset must not delete the company's preset.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_presets');
    }
};
