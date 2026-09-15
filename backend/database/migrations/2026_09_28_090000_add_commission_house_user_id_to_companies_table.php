<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-15 — THE COMPANY AS A TEAM LEADER.
 *
 * Owner: "หัวหน้าทีมในที่นี้มีได้ 2 ความหมาย คือหัวหน้าทีมที่เป็น user จริงในระบบ
 * กับหัวหน้าทีมที่เป็นตัวบริษัทเองที่ได้ค่าคอมจากการขาย".
 *
 * Until now a leader override could only reach a real person up the
 * `manager_id` chain, so an agent with nobody above them kept the whole
 * commission and the company earned no share of their sales. The chosen fix
 * (แนวทาง 2, after four alternatives were weighed) is deliberately NOT a new
 * branch in the payout code: the company gets a user row of its own and is
 * placed at the TOP of the hierarchy, so `CommissionService` finds it by
 * walking the chain it already walks. Not one line of the commission
 * calculation changes.
 *
 * ── WHY ONE COLUMN HERE AND NO FLAG ON `users` ──
 *
 * The obvious alternative was `users.is_commission_house_account`, which
 * makes "is this the house?" a column read instead of a lookup. It was
 * rejected because it creates a SECOND place the same fact lives: a flag set
 * on a user the company does not point at, or a company pointing at a user
 * whose flag was cleared, are both states nothing would notice — and both
 * decide where money goes. One direction, one answer.
 *
 * `nullOnDelete` rather than `restrictOnDelete`: the house user itself cannot
 * be deleted while it owns commission rows (commission_ledger already
 * restricts that), so this constraint only ever fires in a scenario the other
 * one has already refused. Null is then the honest residue — the company has
 * no house account — rather than a dangling id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('commission_house_user_id')
                ->nullable()
                ->after('commission_override_mode')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_house_user_id');
        });
    }
};
