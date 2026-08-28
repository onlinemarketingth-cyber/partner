<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-08-27 — agent-initiated commission withdrawal ("เบิกค่าคอมมิชชั่น").
//
// Until now the ONLY payout mechanism was an admin opening the commission
// ledger and pressing "mark paid" per row. There was no way for an agent to
// ask for their money, and no record that they had asked. This table is that
// record.
//
// TWO-STEP BY DESIGN (human decision, 2026-08-27): `approved` means an admin
// agreed the request is legitimate; `transferred` means the money actually
// left the bank. They are separate because approving is a decision and
// transferring is an event, and collapsing them would make the ledger claim
// rows were paid at the moment somebody clicked "yes" — before any money
// moved, and with no way to represent a transfer that later failed.
//
// amount_satang, never a float: BR-3. Every money value in this system is an
// integer number of satang for the same reason.
//
// THE BANK SNAPSHOT IS DELIBERATE. bank_* are copied from the agent's
// profile AT REQUEST TIME rather than read live at payout time. An agent may
// edit their bank details between asking and being paid — that is allowed
// and normal — but the account an admin approved must be the account that
// was on screen when they approved it. Reading it live would let a profile
// edit silently redirect an already-approved payout.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();

            $table->unsignedBigInteger('amount_satang'); // BR-3

            // App\Enums\WithdrawalStatus — pending_review | approved |
            // rejected | transferred | cancelled
            $table->string('status')->default('pending_review');

            // WHO decided, and when. Nullable because a request that nobody
            // has looked at yet has no answer to either question.
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // Shown to the agent verbatim, so they know what to fix. Required
            // by the application on a rejection (not by the DB, which cannot
            // express "required only for one status value").
            $table->text('rejection_reason')->nullable();

            $table->timestamp('transferred_at')->nullable();
            // Free-text reference the admin can paste from their banking app
            // (transfer ref / slip no). Optional: not every transfer produces
            // one worth recording, and forcing a value invites made-up ones.
            $table->string('transfer_reference')->nullable();

            // Snapshot — see the header note for why these are copied.
            $table->string('bank_name')->nullable();
            $table->text('bank_account_number')->nullable(); // encrypted cast on the model — §6/PDPA
            $table->string('bank_account_holder_name')->nullable();

            $table->timestamps();

            // The one query this table exists to answer fast: "what does this
            // agent have open right now" (available-balance maths) and "what
            // is waiting for me" (admin queue). Both are company + status.
            $table->index(['company_id', 'status'], 'cwr_company_status_idx');
            $table->index(['company_id', 'agent_id', 'status'], 'cwr_company_agent_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_withdrawal_requests');
    }
};
