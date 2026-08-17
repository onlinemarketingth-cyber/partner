<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer — ERD-001 §"Customer" (rev. 3), CLAUDE.md §2 "Client". PDPA
// (Section 6): health_notes is encrypted at rest (model cast), consent
// captured via consent_given_at. ERD-001 open questions #4/#5.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referring_agent_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->timestamp('consent_given_at')->nullable();
            $table->text('health_notes')->nullable(); // encrypted cast on the model — PDPA
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
