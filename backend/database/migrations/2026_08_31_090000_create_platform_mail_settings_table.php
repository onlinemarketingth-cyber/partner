<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-190 §3.1 — a single platform-wide row, DELIBERATELY no company_id.
// This is real, admin-editable SMTP config (CLAUDE.md §8 rule 2 / BR-7 —
// never a .env-only value), but it is platform infrastructure, not tenant
// config: every company's mail goes out through the same one mailbox. A
// company_id column here would recreate the exact "Super Admin has no
// company_id" defect already open as a separate task against
// video_processing_settings (task #583) — that table's per-company shape
// makes sense (each tenant CAN override), this settings screen's doesn't
// (there is only ever one SMTP account for the whole platform), so the fix
// for this table is simply not to add the column rather than to patch
// around it later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            // 'ssl' / 'tls' / 'none' — free string like
            // video_processing_settings.target_resolution, not an enum
            // column: validated at the Form Request layer (Rule::in), so a
            // new encryption mode Laravel's Mailer supports later doesn't
            // need a migration to allow.
            $table->string('encryption')->nullable();
            $table->string('username')->nullable();
            // TASK-044 (bank_account_number) precedent — 'encrypted' cast on
            // the Model, PDPA/Section 6 at-rest encryption. Never returned
            // in plain by the API (PlatformMailSettingService::get()) and
            // never written to audit_logs in plain
            // (PlatformMailSettingService::update()).
            $table->text('password')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            // Fail closed (ADR-032 §2.5's rule applies here too, per spec
            // §3.1): default false, so an empty/half-filled settings row
            // never causes mail to attempt sending through an unconfigured
            // mailer. MailSettingsService::applyRuntimeConfig() only
            // overrides Laravel's mail config when this is true.
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_settings');
    }
};
