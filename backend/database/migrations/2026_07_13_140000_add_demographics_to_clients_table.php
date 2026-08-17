<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-014: demographic fields for age-based package pricing
// (date_of_birth), regional sales analytics (province), and risk
// context (occupation) — human request (2026-07-13), following a
// CRM-standards comparison. General personal data (Section 6), NOT
// the "sensitive" health category — see TASK-014's Design notes for
// why these are not added to the model's `encrypted` cast.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('lead_source');
            $table->text('address')->nullable()->after('date_of_birth');
            $table->string('province')->nullable()->after('address');
            $table->string('occupation')->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'address', 'province', 'occupation']);
        });
    }
};
