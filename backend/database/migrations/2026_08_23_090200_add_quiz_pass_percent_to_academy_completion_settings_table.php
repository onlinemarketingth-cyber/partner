<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-029 §2.4 — the COMPANY-LEVEL half of the pass-mark resolution chain
 * (BR-7: business values are admin-editable config, never constants).
 *
 *     module_lessons.quiz_pass_percent
 *       ?? academy_completion_settings.quiz_pass_percent   ← this column
 *
 * 80 is the human's stated default (ADR-029 §2.4, "1+2"), exactly as
 * ADR-028 §4's 80/100 were, so it carries no `TODO: CONFIRM`.
 * config/academy.php holds the same number as the platform-wide fallback
 * for a company with no settings row at all —
 * AcademyCompletionSettingService::forCompany() is the one place both are
 * read from.
 *
 * This column is deliberately NOT Agent-readable (see
 * AcademyCompletionSettingController): a threshold is a number a learner
 * is not shown, consistent with ADR-028 §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academy_completion_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('quiz_pass_percent')->default(80)->after('pdf_read_percent');
        });
    }

    public function down(): void
    {
        Schema::table('academy_completion_settings', function (Blueprint $table) {
            $table->dropColumn('quiz_pass_percent');
        });
    }
};
