<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY AUDIT 2026-08-21 follow-up — who put this slip here.
 *
 * ── WHY THIS COLUMN EXISTS AT ALL ──
 *
 * The 2026-08-21 audit closed a hole by requiring a slip on file before
 * anyone may confirm a payment. That immediately stranded the customers
 * who pay in cash at a branch or send the slip to their agent over LINE:
 * the public /pay page was the only way a slip could ever exist, so those
 * orders became unclosable. Letting an admin upload on the customer's
 * behalf is the fix (human ruling, 2026-08-21).
 *
 * But it moves the meaning of the slip. A slip that arrived through the
 * public page was uploaded by whoever holds the payment token — the
 * customer. A slip uploaded through the admin console was uploaded by
 * staff, and the person about to confirm it needs to know which of those
 * two things they are looking at BEFORE they attest that money arrived.
 *
 * NULL is the honest value for every existing row and for every future
 * customer upload: nobody on staff put it there. Not backfilled, because
 * there is no way to know after the fact and inventing an admin's name
 * next to a payment record would be worse than the gap it papers over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // nullOnDelete, not cascade: an admin leaving the company must
            // never take a payment record with them. Losing the name is
            // survivable; losing the order is not.
            $table->foreignId('slip_uploaded_by_user_id')
                ->nullable()
                ->after('slip_path')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('slip_uploaded_by_user_id');
        });
    }
};
