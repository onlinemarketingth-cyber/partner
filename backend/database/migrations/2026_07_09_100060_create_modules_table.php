<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Academy — ERD-001 §"Academy", now downstream of Product catalog.
// product_id nullable: a module can teach about one specific product, or
// stay general (onboarding/compliance) — BR-1, syllabus content is BR-7.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cert_tier_id')->constrained('cert_tiers')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('title');
            $table->string('content_type'); // App\Enums\ModuleContentType
            $table->string('content_ref');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('xp_reward')->default(0); // BR-5/BR-7 — seeded, never hardcoded
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
