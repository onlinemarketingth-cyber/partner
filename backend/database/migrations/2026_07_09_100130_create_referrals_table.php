<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Referral & Pipeline — ERD-001 §"Referral & Pipeline" (rev. 3): the
// transaction connecting Agent, Customer and Product. CLAUDE.md §2 (SWS
// Referral), §4.3 (Pipeline Stage). current_stage per BR-1 gate — only
// reachable once the Agent holds a passed `basic` user_certifications row
// (enforced in a Policy/Service, not the DB).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('branch');
            $table->dateTime('preferred_time');
            $table->string('current_stage'); // App\Enums\PipelineStage
            $table->unsignedTinyInteger('meeting_number')->nullable(); // only when stage = ongoing_next_meeting — ERD-001 open question #7
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['company_id', 'agent_id', 'current_stage'], 'referrals_agent_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
