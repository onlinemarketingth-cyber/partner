<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-132 / ADR-026 §3.3, §3.4, §3.8 — the four places a pipeline
// template can be pointed at.
//
// Three of them are SCOPES, resolved most-specific-wins (ADR-026 §3.3,
// deliberately the same shape as CommissionRule scope resolution from
// TASK-028 so this codebase has one resolution idea, not two):
//
//     product.pipeline_template_id
//       ?? product.category.pipeline_template_id
//       ?? company.default_pipeline_template_id
//       ?? the seeded medical_package_default
//
// The fourth, referrals.pipeline_template_id, is NOT a scope — it is the
// SNAPSHOT (ADR-026 §3.4). It is stamped once at referral creation and
// never re-resolved, for the same reason BR-4's commission ledger is
// immutable: an admin editing a template must never reroute or strand a
// customer already mid-journey (a referral parked at Waiting Appointment
// when that stage is removed from its template would otherwise have no
// legal next stage and no legal previous one).
//
// NO DB-LEVEL DEFAULT on any of these columns, on purpose (ADR-026 §3.8):
// a hardcoded default column value is exactly the BR-7 smell this ADR
// exists to remove. NULL means "ask the next level up", and the
// resolver — not the schema — owns the fallback.
//
// nullOnDelete rather than cascade: deleting a template must never delete
// products, categories, companies or (least of all) referrals. It
// degrades them to "resolve from the next level up" instead.
//
// Backfilling existing rows (products -> direct_sale_default, in-flight
// referrals -> medical_package_default) is deliberately NOT done here —
// that is TASK-134, and it is a data decision (ADR-026 §3.8) that needs
// its own reversible migration and its own test with fixtures of both
// kinds.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('pipeline_template_id')->nullable()->after('commission_plan_type')
                ->constrained('pipeline_templates')->nullOnDelete();
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('pipeline_template_id')->nullable()->after('is_active')
                ->constrained('pipeline_templates')->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('default_pipeline_template_id')->nullable()->after('commission_plan_type')
                ->constrained('pipeline_templates')->nullOnDelete();
        });

        Schema::table('referrals', function (Blueprint $table) {
            // The snapshot (ADR-026 §3.4). Nullable because every referral
            // that already exists predates templates entirely — TASK-133
            // treats NULL as "fall back to PipelineStage's default edges",
            // so legacy rows keep working untouched.
            $table->foreignId('pipeline_template_id')->nullable()->after('affiliate_link_id')
                ->constrained('pipeline_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_template_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_pipeline_template_id');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_template_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_template_id');
        });
    }
};
