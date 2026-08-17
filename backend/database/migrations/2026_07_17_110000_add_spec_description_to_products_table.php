<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-008 Decision 1 — free-text spec narrative, additive alongside the
// existing key-value product_specs table (ADR-007) and the existing
// free-text `description` column — nothing is replaced.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('spec_description')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('spec_description');
        });
    }
};
