<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-132 / ADR-026 §3.2 — the ordered stage list of one template.
//
// `stage` is a plain string column cast to App\Enums\PipelineStage in the
// model, matching how referrals.current_stage and
// pipeline_stage_logs.from_stage/to_stage already store the same
// vocabulary (and matching this project's standing choice not to install
// doctrine/dbal, so adding an enum case never needs a schema change).
// It is NOT free text: PipelineTemplateResolver::assertValidStageSequence()
// rejects any value that is not a PipelineStage case (ADR-026 §2 Option C
// — a free-text stage would break the enum-cast audit trail and could
// silently produce a journey with no complete_payment, i.e. a BR-4
// commission outage).
//
// unique(pipeline_template_id, stage) is the DB-level half of the "each
// stage at most once per template" invariant (ADR-026 §5 Q1); the Service
// enforces the same rule up front so the user gets a 422 rather than a
// driver-level constraint violation.
//
// index(pipeline_template_id, position) serves the only read this table
// ever gets: "give me this template's stages in order".
//
// company_id is denormalised from the parent template (BR-6, §5 rule 1 —
// "every business table must include a company_id") so TenantScope can
// filter this table directly instead of always requiring a join.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_template_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_template_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['pipeline_template_id', 'stage']);
            $table->index(['pipeline_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_template_stages');
    }
};
