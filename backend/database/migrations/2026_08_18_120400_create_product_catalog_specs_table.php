<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-036 §2/§8 — shared key-value spec rows for a product_catalog_item,
// same "new dedicated table, not a widened product_specs" reasoning as
// product_catalog_media above. BR-7: no fixed spec taxonomy, same as
// product_specs (ADR-007).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_catalog_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('product_catalog_items')->cascadeOnDelete();
            $table->string('spec_group')->nullable();
            $table->string('spec_key');
            $table->text('spec_value');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['catalog_item_id', 'sort_order'], 'product_catalog_specs_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalog_specs');
    }
};
