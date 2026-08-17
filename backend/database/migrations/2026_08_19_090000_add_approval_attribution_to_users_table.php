<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-115 / ADR-025 §7 — WHO approved a registration, WHEN, and through
// WHICH path.
//
// ADR-025 §7 accepts a real residual risk knowingly: "a leader can now bring
// people into the company without an admin ever looking." The mitigations it
// names are (a) every approval is audited and (b) "the Admin approval queue
// shows leader-approved agents with their approver". The audit log already
// covered (a) via AgentApprovalService. (b) needs data ON THE USER ROW: an
// approval queue reads users, and once a row flips to `approved` the audit
// log is a separate table nobody joins on a list screen. These three columns
// are what TASK-117's "distinguishes admin-approved from leader-approved and
// names the approver" binds to.
//
// NO BACKFILL, deliberately. Every existing approved row was approved by
// someone this migration cannot identify — the 2026_07_14_120000 backfill
// set `approved` wholesale for pre-existing accounts, and TASK-020 approvals
// only ever wrote an AuditLog row. NULL is the honest answer for those, and
// UserResource renders it as "ไม่ระบุ" rather than inventing an approver.
// Nothing reads these columns for authorization, so a NULL is inert.
//
// SECURITY NOTE: none of the three is in User::$fillable (see the model's own
// comment). They are written exclusively by AgentApprovalService via
// forceFill(), so no Form Request can let a caller nominate their own
// approver or forge approval_source = 'admin' on a leader approval.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Self-referencing FK. nullOnDelete (not cascade!) — deleting an
            // approver must never delete the people they approved. Mirrors
            // company_invite_codes.created_by_user_id's own treatment.
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->after('approval_rejection_reason')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');

            // String + PHP enum cast (App\Enums\ApprovalSource), same style as
            // agent_approval_status / registered_via on this table — never a
            // native DB enum, which would need a schema change to extend.
            $table->string('approval_source')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn(['approved_at', 'approval_source']);
        });
    }
};
