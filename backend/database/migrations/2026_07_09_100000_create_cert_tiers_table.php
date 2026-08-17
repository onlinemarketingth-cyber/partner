<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Academy — CLAUDE.md Section 2 (Cert Tier), BR-1. Global / platform-wide
// (no company_id) per ERD-001 §"Academy" and open question #2 — proposed
// default, flagged for confirmation, not blocking since it's easy to add
// company_id later if a company ever needs custom tiers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cert_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // basic | intermediate | high
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_mandatory')->default(false); // BR-1: Basic is mandatory
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cert_tiers');
    }
};
