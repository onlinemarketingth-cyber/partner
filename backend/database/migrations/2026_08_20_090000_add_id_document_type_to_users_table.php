<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-122 (human-requested 2026-08-05) — self-registration now REQUIRES an
// identity document, which may be a Thai national ID or a passport. This
// column says which one `users.national_id` holds.
//
// WHY THE EXISTING COLUMN IS NOT RENAMED: `users.national_id` now holds "the
// identity document number, of the type named by `id_document_type`". Its
// name is load-bearing in five places — the blind index
// (`national_id_hash`), User::maskNationalId(), the `user.national_id_updated`
// audit action, the Admin Manage-Agents form, and the `?national_id=` search
// parameter on GET /users. Renaming buys nothing functional and would touch
// all five plus every existing audit row's meaning.
//
// WHY NULLABLE: every row in this table predates this requirement and has no
// document type on file. A NOT NULL column would need a backfill value, and
// any value we invented would be a claim about what was collected that is
// simply not true — including for the ~all rows where national_id is itself
// null. Null here means "unknown / never captured", and
// User::hashNationalId() treats a null type as the pre-TASK-122 digits-only
// algorithm precisely so existing hashes keep matching (see its docblock).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // String rather than a DB enum: adding a third document type
            // later must be a code change (App\Enums\IdDocumentType), not a
            // schema migration on a large table. Same treatment as
            // users.role / users.registered_via.
            $table->string('id_document_type', 32)->nullable()->after('national_id_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('id_document_type');
        });
    }
};
