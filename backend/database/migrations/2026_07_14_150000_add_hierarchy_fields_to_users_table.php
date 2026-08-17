<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-025 (Unilevel manager chain) + ADR-006 Round 4 (Binary leg
// placement) — both plan types share the same manager_id column
// (self-referencing "who is this agent's upline"); binary_leg is only
// meaningful when the agent's company is on a Binary plan.
//
// manager_id: nullOnDelete — losing a manager should never delete/
// break the report, just leave them un-managed until reassigned.
// Same-company + no-cycle validation happens in the Service layer
// (BR-6), not the DB — a self-referencing FK can't express "no
// cycles" or "same company_id as the row it points to".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('company_id')
                ->constrained('users')->nullOnDelete();
            $table->string('binary_leg')->nullable()->after('manager_id'); // App\Enums\BinaryLeg
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn('binary_leg');
        });
    }
};
