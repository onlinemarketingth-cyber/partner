<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agent-view IA item 1.6 ("การแจ้งข่าว ให้ agent ... สินค้าใหม่ๆ") —
// Admin-authored newsfeed post shown on the Agent Portal. Same
// nullable-company_id "own company or platform-wide default" shape as
// Badge/GamificationRule (a Super Admin can push a platform-wide
// announcement across every company, e.g. maintenance notice).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('audience')->default('all_agents'); // App\Enums\AnnouncementAudience
            $table->foreignId('target_cert_tier_id')->nullable()->constrained('cert_tiers')->restrictOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('published_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'published_at'], 'announcements_company_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
