<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-007 — BR-7: video compression limits are admin-editable config,
// never hardcoded. One optional row per company; config/media.php
// holds the platform-wide fallback used when a company has no row
// (VideoProcessingSettingService::forCompany()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_processing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('max_upload_mb');
            $table->string('target_resolution'); // e.g. "720p" — free string, not an enum: new resolutions shouldn't need a migration
            $table->unsignedInteger('target_bitrate_kbps');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_processing_settings');
    }
};
