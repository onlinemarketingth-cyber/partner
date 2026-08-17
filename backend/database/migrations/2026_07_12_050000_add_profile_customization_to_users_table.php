<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Personal profile customization (avatar + background) — human-requested
// feature, scoped to "each user manages their own" (see task discussion):
// stored server-side (not localStorage) so it follows the account across
// devices, per the human's explicit choice. Not governed by any BR — this
// is presentation preference, not business logic.
//
// avatar_path / background_image_path: paths on the PUBLIC disk (not the
// 'local'/private disk ClientDocumentService uses for PDPA client
// documents — see that Service's own comment on why those stay private).
// Avatars/backgrounds are decorative, non-sensitive images, so a public
// URL (via `php artisan storage:link`) is the simpler, standard approach
// here — deliberately NOT the same access-gated pattern as Section 5
// rule 6, which is specifically about client documents.
//
// background_type + background_config: a user picks ONE of "gradient"
// (background_config holds {color1, color2, angle}) or "image"
// (background_image_path holds the file) — never both at once, enforced
// in UserProfileService, not at the DB level.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('role');
            $table->string('background_type')->nullable()->after('avatar_path'); // 'gradient' | 'image'
            $table->json('background_config')->nullable()->after('background_type');
            $table->string('background_image_path')->nullable()->after('background_config');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'background_type', 'background_config', 'background_image_path']);
        });
    }
};
