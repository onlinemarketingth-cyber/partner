<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-005 — self-registration approval/verification state. Every
// pre-existing row is explicitly backfilled to 'approved'/'email' below
// (not just relying on the column default) so this migration's intent
// is unambiguous: an Agent created via the existing Company-Admin
// "Manage Agents" flow was already implicitly vetted by the Admin who
// created them — this migration must never retroactively lock anyone
// out of an account they could already log into.
//
// `phone` is also added here (ag-lead judgment call, not asked for
// explicitly, flagged in TASK-017's design notes): TASK-018's
// registration form collects it per ADR-005's flow, but `users` never
// had a phone column before this — it's basic contact info (same
// spirit as Client.phone), not a business rule, so it's added directly
// rather than raising a whole clarifying round for it. No existing
// "Manage Agents" UI is wired to edit it for already-existing agents —
// out of scope here, flag if wanted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('agent_approval_status')->default('approved')->after('role');
            $table->text('approval_rejection_reason')->nullable()->after('agent_approval_status');
            $table->string('registered_via')->default('email')->after('approval_rejection_reason');
            $table->foreignId('registered_via_invite_code_id')
                ->nullable()
                ->after('registered_via')
                ->constrained('company_invite_codes')
                ->nullOnDelete();
        });

        // Explicit backfill step (see docblock above) — the column
        // defaults above already cover this for any row inserted before
        // this migration ran, but this makes the intent unambiguous and
        // survives even if the defaults are ever changed later.
        DB::table('users')->update([
            'agent_approval_status' => 'approved',
            'registered_via' => 'email',
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_via_invite_code_id');
            $table->dropColumn(['phone', 'agent_approval_status', 'approval_rejection_reason', 'registered_via']);
        });
    }
};
