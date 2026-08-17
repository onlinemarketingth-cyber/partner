<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-056 Sprint P1 — "Product Share" links. Distinct purpose from
// affiliate_links (lead capture) and sales_material_share_links (single
// file): this is a full public showcase of ONE product (media + all its
// sales materials), tied to the Agent who shared it for attribution, with
// NO lead-capture form (view-only — ag-lead decision, confirmed with the
// human 2026-07-29). token is a 64-char cryptographically random string,
// same convention as every other public-token table in this app (never an
// incrementing id — Section 5 rule 5's IDOR concern).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'agent_id'], 'product_share_links_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_share_links');
    }
};
