<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-5 config — XP amounts never hardcoded in a Service. company_id
// nullable = platform default; a per-company row overrides it. Composing
// "company override OR platform default" is Service-layer logic, not
// built here — see ERD-001 §"Gamification".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gamification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type'); // App\Enums\GamificationSourceType
            $table->unsignedInteger('xp_value');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gamification_rules');
    }
};
