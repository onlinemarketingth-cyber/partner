<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds tenant + role columns to users — CLAUDE.md Section 5 rule 1
// ("Every business table must include a company_id column") and rule 4
// (Agent / Company Admin / Super Admin visibility levels), BR-6.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable: Super Admin is not scoped to any single company
            // (Section 5, rule 4). Agent/Company Admin having a null
            // company_id is an invalid application state, enforced via
            // Form Request validation + Policies, not a DB constraint —
            // Super Admin is the one legitimate exception to "NOT NULL".
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();

            // String-backed enum column (App\Enums\UserRole) — no magic
            // strings per Section 7. Defaults to 'agent', the most
            // restricted role; elevate explicitly.
            $table->string('role')->default('agent')->after('company_id');

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('role');
            $table->dropSoftDeletes();
        });
    }
};
