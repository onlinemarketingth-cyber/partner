<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-005 (decision 6, follow-up round): a company may hold several
// simultaneously-valid invite codes (e.g. one per recruitment campaign/
// branch), and every code MUST have an expiry — no column-on-companies
// design can represent "several, each expiring independently", hence a
// dedicated table. A code is valid only while `revoked_at IS NULL AND
// expires_at > now()` — see CompanyInviteCode::isValid().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invite_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('label')->nullable();
            // Required, no default — a Super Admin must explicitly pick
            // an expiry every time a code is created (TASK-022), never a
            // hardcoded duration (BR-7's spirit: this is a business-tunable
            // value, not something to bake into a migration/constant).
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invite_codes');
    }
};
