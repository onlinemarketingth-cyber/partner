<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-007 — admin-editable key-value spec table (BR-7: no fixed spec
// taxonomy, works for both a physical-goods spec set and a health-
// package spec set like coverage limit / hospital network / age
// eligibility / waiting period).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('spec_group')->nullable(); // e.g. "ข้อมูลทั่วไป" — purely a display grouping, admin-typed free text
            $table->string('spec_key');
            $table->string('spec_value');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'sort_order'], 'product_specs_product_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_specs');
    }
};
