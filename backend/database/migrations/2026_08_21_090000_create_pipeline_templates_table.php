<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-132 / ADR-026 §3.1-3.3 (human decision, KreangYot 2026-08-08) —
// BR-7: the SEQUENCE of pipeline stages is a business value, so it lives
// in config, not in the enum's edge list (PipelineStage::allowedNextStages(),
// renamed defaultAllowedNextStages() in TASK-133). A template is a
// named, ordered SUBSET of the PipelineStage vocabulary (rows in
// pipeline_template_stages); this table is just its identity.
//
// BR-6: company_id on every business table, cascade-deleted with the
// tenant. unique(company_id, key) — `key` is the stable machine handle
// the resolver and the seeder look templates up by (e.g.
// 'medical_package_default'), `name` is the human-facing label an admin
// may rename freely without breaking any lookup.
//
// is_system marks the two seeded templates. ADR-026/TASK-134 will make
// them non-deletable (copy-only) in the admin UI; nothing in TASK-132
// depends on that yet — the flag is stored now so the seeder does not
// have to be re-run to backfill it later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_templates');
    }
};
