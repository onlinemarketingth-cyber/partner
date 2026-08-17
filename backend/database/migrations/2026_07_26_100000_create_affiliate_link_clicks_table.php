<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 4 (TASK-032) — one row per GET /l/{token} hit.
// company_id is denormalized from the parent link (BR-6 rule 1: "every
// business table must include a company_id column", even though it's
// technically derivable via a join — same convention as every other
// table in this app). ip_hash, never a raw IP (PDPA, Section 6) — see
// AffiliateLinkClickService's own comment for the hashing scheme.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('affiliate_links')->cascadeOnDelete();
            $table->timestamp('clicked_at');
            $table->string('ip_hash', 64);
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['link_id', 'clicked_at'], 'affiliate_link_clicks_link_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_link_clicks');
    }
};
