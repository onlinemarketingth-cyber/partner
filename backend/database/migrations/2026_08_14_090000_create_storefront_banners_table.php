<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-068 / ADR-020 row 2 — admin-curated storefront banner carousel
// (Agent Portal ProductBrowseView.vue). Same image_path upload/validation
// convention as announcements.image_path (public disk — a banner is meant
// to be shown directly to every agent in the company, not access-checked
// per-row). product_id is REQUIRED (ADR-020 decision #2, human-confirmed
// 2026-07-31): a banner click-target is always exactly one internal
// Product in the SAME company — no external URLs, no free-text links this
// round (kept out of scope to avoid an open-redirect review burden).
// restrictOnDelete (not cascade/nullOnDelete): deleting a product that
// still has a live banner pointing at it must be blocked, not silently
// orphan/null the banner — the admin has to remove the banner first.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('image_path');
            $table->string('title')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'sort_order'], 'storefront_banners_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_banners');
    }
};
