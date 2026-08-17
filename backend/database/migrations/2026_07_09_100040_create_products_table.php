<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Product catalog — ERD-001 §"Product Catalog". CLAUDE.md §2 uses
// "Package / Product" as synonyms; this is the "Package" of the glossary.
// price_satang: BR-3, integer THB cents — never float.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('price_satang'); // BR-3 — 8,900/9,900 THB tiers are seed data (BR-7), not here
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
