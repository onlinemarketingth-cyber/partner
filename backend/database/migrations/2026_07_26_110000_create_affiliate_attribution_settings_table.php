<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 4 (TASK-032) — one row per company, same singleton
// shape as commission_binary_settings/commission_matrix_settings.
// attribution_window_days: BR-7, admin-configurable, never hardcoded —
// how many days after a click a lead-capture submission still counts as
// attributed to that click (see AffiliateLeadCaptureService's own
// comment for the exact rule). new_vs_returning_rate_differential_enabled:
// BR-7 flag reserved for a future rate differential between new vs.
// returning visitors — NOT implemented by this task (no differential
// calculation exists yet); the column exists now, per ADR-011's own
// explicit mention, so TASK-033/034 don't need another migration to
// surface the toggle in the Admin UI.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_attribution_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedInteger('attribution_window_days');
            $table->boolean('new_vs_returning_rate_differential_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_attribution_settings');
    }
};
