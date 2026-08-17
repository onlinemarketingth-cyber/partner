<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-5, ERD-001 open question #9 — condition_config is a placeholder
// json blob. company_id nullable = platform default, same pattern as
// gamification_rules.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description');
            $table->string('icon');
            $table->json('condition_config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};
