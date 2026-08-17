<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 4 (TASK-032) — nullable, set only when a referral
// originated from a tracked-link lead capture AND a valid click existed
// within the company's attribution_window_days (see
// AffiliateLeadCaptureService's own comment) — every other referral
// (manually submitted SWS Referral, or a lead-capture submission
// outside the attribution window) leaves this null, same "opt-in,
// zero behavior change for anyone not using the feature" precedent as
// referrals.next_renewal_date (TASK-024) and co_agent_id (TASK-026).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('affiliate_link_id')->nullable()->after('co_agent_id')
                ->constrained('affiliate_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_link_id');
        });
    }
};
