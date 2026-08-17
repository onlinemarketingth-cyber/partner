<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 4 (TASK-032) — Affiliate plan type, first genuinely
// public/unauthenticated write surface in this codebase (flagged
// explicitly in ADR-011, not built silently). token is a 64-char
// cryptographically random string (Str::random(64), same convention as
// sales_material_share_links.token) — NEVER an incrementing id, so
// nothing about another agent's link can be discovered by enumeration
// (Section 5 rule 5's IDOR concern, extended here to a fully public
// route). product_id nullable — a link can point at one specific
// product or be a general agent link (the public lead-capture form
// then asks the visitor which product themselves).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamps();

            $table->index(['company_id', 'agent_id'], 'affiliate_links_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_links');
    }
};
