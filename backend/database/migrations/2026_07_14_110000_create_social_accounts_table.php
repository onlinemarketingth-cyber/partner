<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-005 (TASK-017 design note) — deliberately no company_id column:
// a social identity always belongs to exactly one User, and that User
// is already tenant-scoped; duplicating company_id here would be
// redundant data that could drift out of sync. No provider access/
// refresh tokens are stored — nothing here ever calls back into the
// provider's API after the initial login.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
