<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MOB-12 (2026-10-02) — in-app account deletion, as a request an admin decides.
 *
 * Apple guideline 5.1.1(v): an app that lets people create an account must
 * let them delete it from inside the app. Owner decision: the agent's press
 * files a REQUEST; a Company Admin settles pending commission and moves the
 * downline/clients elsewhere with the screens that already exist, then
 * approves, and only then is the account anonymised. See
 * App\Services\Account\AccountDeletionService for the whole flow.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 *   1. TWO PENDING REQUESTS FOR ONE PERSON. Approving one and rejecting the
 *      other would leave the login gate reading whichever row it found first
 *      — a person told "rejected, sign in again" who still cannot, or the
 *      reverse. The service checks under a row lock, and `pending_user_id`
 *      below makes the database refuse it too: it carries the user id ONLY
 *      while the row is pending and NULL afterwards, and a UNIQUE index
 *      ignores NULLs on both MySQL and SQLite. That is a partial unique index
 *      ("one pending row per user") spelled in a way both engines support,
 *      because MySQL has no `WHERE` clause on an index. Written only by the
 *      model's own saving hook, never by a request.
 *
 *   2. CASCADING THE USER. `users` rows are never hard-deleted in this
 *      system (they are FK targets for the immutable commission ledger, BR-4),
 *      but restrictOnDelete says so explicitly: a request is the record that
 *      the owner asked for their data to go, and it must not vanish with the
 *      thing it describes.
 *
 *   3. company_id NOT FOLLOWING THE USER. It is captured when the request is
 *      made, like every ledger row's own company_id. A Super Admin moving the
 *      agent between companies afterwards must not hand the pending request
 *      to a different company's admin.
 *
 * reason / decision_note are string(500), not text: the agent's own words and
 * the admin's reply, both short by intent, and the Form Requests cap them at
 * the same length so the database is never the thing that refuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // App\Enums\AccountDeletionRequestStatus — pending | approved | rejected
            $table->string('status', 16)->default('pending');

            // See WHAT BREAKS SILENTLY 1. Not a foreign key on purpose: it is
            // an index trick, not a relation, and nothing ever joins on it.
            $table->unsignedBigInteger('pending_user_id')->nullable()->unique();

            $table->string('reason', 500)->nullable();
            $table->timestamp('requested_at');

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();

            // MOB-12 follow-up (owner decision 2026-10-03). How the request was
            // closed — App\Enums\AccountDeletionResolution: `immediate` (the
            // agent owed nothing, or waived what was owed, and the account was
            // deleted on the spot) or `admin` (decided by an admin). NULL while
            // pending.
            $table->string('resolution', 16)->nullable();
            // What the agent gave up on an immediate deletion, in satang
            // (BR-3). NULL when nothing was waived. The ledger rows themselves
            // carry payment_status = forfeited; this is the total as it stood
            // at that moment, so the admin queue can show it without
            // re-summing rows that a later reversal may have joined.
            $table->bigInteger('forfeited_commission_satang')->nullable();

            $table->timestamps();

            // The admin queue: "this company's requests in this status, newest first".
            $table->index(['company_id', 'status', 'requested_at'], 'adr_company_status_requested_idx');
            // The login gate: "does this user have a blocking request?"
            $table->index(['user_id', 'status'], 'adr_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};
