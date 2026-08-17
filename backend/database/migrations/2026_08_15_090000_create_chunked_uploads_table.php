<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-094 — chunked upload sessions.
 *
 * Human-confirmed (2026-08-03): production is Hostinger shared hosting.
 * A 44MB video was rejected with 413 because PHP checks `post_max_size`
 * PER REQUEST. Slicing the file into 5MB chunks keeps every request tiny,
 * so no PHP limit has to be raised on any environment — which was the
 * human's explicit constraint ("ถ้าไปปรับขนาดจะมีปัญหากับ production").
 *
 * This table exists so the upload session can be bound to a tenant. The
 * token is issued by the server, never chosen by the client: if the
 * client picked its own id, company A could guess company B's id and
 * append bytes into B's in-flight file — a direct BR-6 violation. It also
 * carries `received_bytes` so the cumulative size ceiling is enforced
 * server-side; checking only per-chunk size would let an attacker send
 * unlimited 5MB chunks and fill the disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunked_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Random, server-issued. Unique so a lookup by token alone is
            // unambiguous, but every query still goes through TenantScope.
            $table->string('token', 64)->unique();

            $table->string('original_filename');
            $table->string('mime_type')->nullable();

            // What the client SAYS it will send — advisory only, used to
            // reject an obviously-too-large upload before a single byte is
            // written. `received_bytes` is the number that is actually
            // enforced, because it is the only one the server measures.
            $table->unsignedBigInteger('declared_bytes')->nullable();
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->unsignedInteger('max_bytes');

            // Relative to the 'local' disk. One .part file per session —
            // chunks are APPENDED, never stored as separate files, because
            // the production host is at 306K of its 600K inode quota.
            $table->string('part_path');

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Drives the stale-session cleanup command.
            $table->index(['completed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunked_uploads');
    }
};
