<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-10 (human: "เพิ่มเรื่องการกระจายสิทธิ์ให้ Company Admin และ Admin
 * ที่ได้สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงานคนละหน้าที่กัน").
 *
 * ── WHAT THIS IS ──
 *
 * ADR-032 §2.2/Phase 3's per-user grant, built for the first ability that
 * actually needed it. The ADR names the case exactly: "let one company grant
 * an accountant `commission.*` without also granting `academy.*`". Redeeming
 * a voucher is that shape — it is a front-desk job, not a management one, and
 * being a Company Admin should stop implying it.
 *
 * The table is general (`ability` is any Ability case) but only
 * `voucher.redeem` is grant-only today: it is the one ability that has been
 * REMOVED from the Company Admin role row, so from this release a Company
 * Admin holds it because somebody gave it to them, not because of what they
 * are.
 *
 * ── NOBODY LOSES ANYTHING ON DEPLOY ──
 *
 * Removing the ability from the role would, on its own, take redemption away
 * from every Company Admin the instant this ships — mid-day, with customers
 * standing at counters. So every existing Company Admin is granted it here.
 * The rule changes for everyone made AFTER this migration; it does not
 * change under anyone standing at a desk today.
 *
 * `granted_by_user_id` is nullable precisely so this backfill can be honest:
 * NULL means "the system, at migration time", and inventing a Super Admin id
 * would put a person's name against a decision they never made.
 *
 * unique(user_id, ability) — a grant is held or it is not; granting twice is
 * the same fact, not two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_abilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ability');
            // Who granted it. Null = this migration's backfill. nullOnDelete
            // rather than cascade: removing the manager who granted a right
            // must not silently revoke the right.
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'ability']);
        });

        $now = now();

        $existing = DB::table('users')
            ->where('role', 'company_admin')
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($existing->chunk(500) as $chunk) {
            DB::table('user_abilities')->insert(
                $chunk->map(fn ($id) => [
                    'user_id' => $id,
                    'ability' => 'voucher.redeem',
                    'granted_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_abilities');
    }
};
