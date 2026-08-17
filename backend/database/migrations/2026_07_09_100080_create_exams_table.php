<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Academy — ERD-001 open question #6 (exam engine shape). `config` is a
// placeholder json blob; table shape itself is not blocked by that.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cert_tier_id')->constrained('cert_tiers')->restrictOnDelete();
            $table->string('title');
            $table->unsignedInteger('passing_score');
            $table->json('config')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
