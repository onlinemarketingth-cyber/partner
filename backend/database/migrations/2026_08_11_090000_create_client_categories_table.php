<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-056 Sprint P2 — client segmentation. BR-7 (admin-editable config,
// never hardcoded) — the seeded starter set (ClientCategorySeeder) is a
// default the human confirmed 2026-07-29, always editable per company.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('client_category_id')->nullable()->after('lead_source')
                ->constrained('client_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_category_id');
        });
        Schema::dropIfExists('client_categories');
    }
};
