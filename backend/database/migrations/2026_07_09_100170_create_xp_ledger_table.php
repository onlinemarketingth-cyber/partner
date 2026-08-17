<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BR-5: Agent's total XP = SUM(xp_awarded), never stored/duplicated on
// the user row. Append-only. source_id is a plain reference to whichever
// table source_type names (module_completions/exam_attempts/referrals) —
// not an Eloquent morph (source_type is an event-kind enum, not a class).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xp_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // Agent
            $table->string('source_type'); // App\Enums\GamificationSourceType
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('xp_awarded');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'user_id'], 'xp_ledger_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xp_ledger');
    }
};
