<?php

use App\Enums\TeamVisibilityLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-106 / ADR-024 §5 (human-confirmed 2026-08-05) — BR-7: how much of a
// subordinate's client data a team leader may see is a per-company admin
// decision, never a hardcoded rule. Same "one optional row per company"
// singleton shape as video_processing_settings / announcement_settings.
//
// Unlike those two, there is NO config/*.php platform-default file here.
// The absence of a row is itself meaningful: it means "this company has
// never chosen", and per ADR-024 §5 that must resolve to counts_only (the
// least-disclosing level) rather than to some platform-wide preference an
// operator could later widen for every tenant at once. The column default
// below mirrors that same fail-closed value so a row created by any future
// path (seeder, manual INSERT) starts closed too.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_visibility_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // Free string + application-layer enum validation, matching
            // video_processing_settings.target_resolution: adding a fourth
            // level later must not require a schema migration, and this
            // project does not install doctrine/dbal for ->change().
            $table->string('client_visibility_level')->default(TeamVisibilityLevel::CountsOnly->value);
            // Master switch. Off = the company's leaders get no team screen
            // at all; DownlineService::resolveLevel() treats it exactly like
            // a missing row (counts_only).
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_visibility_settings');
    }
};
