<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer — CLAUDE.md Section 5 rule 6: tenant-scoped by path, never a
// public URL. Downloads must go through an access-checked controller
// action (a future task — not built here, schema only).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_documents');
    }
};
