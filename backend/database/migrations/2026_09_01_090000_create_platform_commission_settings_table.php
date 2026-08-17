<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TASK-196 §2.1 — a single platform-wide row, same shape as
// platform_mail_settings (TASK-190), DELIBERATELY no company_id. This is
// BR-7 config ("never hardcode a business value"), but — same reasoning as
// platform_mail_settings' own docblock — it is a platform-wide ceiling, not
// a per-tenant one: every company's commission rules are checked against
// the same cap, so a company_id column here would recreate the exact
// "Super Admin has no company_id" defect class already open as task #583
// rather than avoid it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_commission_settings', function (Blueprint $table) {
            $table->id();
            // BR-2/BR-3-adjacent: basis points, same unit
            // commission_rules.rate_value already uses for
            // rate_type=percentage (500 = 5.00%), so a fixed_satang rule's
            // implied rate is directly comparable without a unit
            // conversion at validation time. Default 3000 = 30.00% is the
            // human's own stated default (TASK-196 §1); NOT NULL with no
            // app-level nullable fallback needed because the row below is
            // seeded in this same migration.
            $table->unsignedInteger('max_commission_rate_basis_points')->default(3000);
            $table->timestamps();
        });

        // §2.1 — "seed the default row in the migration itself ... every
        // environment has a cap from the moment this migrates," same
        // fail-closed/always-a-value reasoning as `is_enabled` defaults
        // elsewhere in this app. Unlike platform_mail_settings (whose row
        // is only created the first time an admin saves the form),
        // PlatformCommissionSettingService's own validation call site
        // (StoreCommissionRuleRequest/UpdateCommissionRuleRequest) must
        // never see "no row yet" as a state, since it runs on every
        // commission-rule write, not just from a settings screen.
        DB::table('platform_commission_settings')->insert([
            'max_commission_rate_basis_points' => 3000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_commission_settings');
    }
};
