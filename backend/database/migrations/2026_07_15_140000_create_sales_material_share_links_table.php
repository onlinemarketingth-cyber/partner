<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-007 — signed, time-limited, revocable public link for one
// specific sales material (agent -> external prospect who has no
// account, "1-to-many" per the human's request). A deliberate, NARROW
// exception to CLAUDE.md §5 rule 6 ("never a public URL") — see
// ADR-007's Decision 3 for the reasoning. Minting a link is
// authenticated + Policy-checked exactly like every other access in
// this app (SalesMaterialShareLinkService); only the resulting token is
// unauthenticated, and it only ever grants access to the ONE material
// it was minted for.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_material_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_material_id')->constrained('product_sales_materials')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'sales_material_id'], 'sales_material_share_links_material_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_material_share_links');
    }
};
