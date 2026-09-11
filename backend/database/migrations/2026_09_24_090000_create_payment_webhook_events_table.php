<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-09-11 (human: "ข้อ 1 ขึ้นค่า debug จริงว่า stripe คืนค่าอะไรมา จะได้รู้
// ปัญหาเกิดจากอะไร").
//
// ── THE QUESTION THIS TABLE EXISTS TO ANSWER ──
//
// A card was declined in testing and the order came back paid. Nothing in
// this system could say what the gateway had actually sent: the decision was
// made from the payload, the payload was interpreted, and then it was thrown
// away. `Log::info('Payment webhook applied')` records our CONCLUSION —
// result, charge id, amount — which is precisely the thing under suspicion
// when the conclusion looks wrong. Reading our own summary cannot tell us
// whether we misread the evidence.
//
// So the evidence is kept. One row per signature-verified delivery, with the
// body exactly as it arrived (minus the redactions below), whether it was
// acted on, ignored, or matched no order at all.
//
// ── ONLY AFTER THE SIGNATURE VERIFIES ──
//
// Nothing unverified is written here, matching the rule
// PaymentWebhookController already states for logging: the body of a rejected
// request is attacker-chosen, and a table anyone on the internet can write
// into is a place to put an attack, not a place to find one.
//
// ── WHAT IS REMOVED FROM THE BODY ──
//
// PaymentWebhookRecorder strips any key whose name says it is a secret —
// `client_secret` above all, which Stripe includes on a PaymentIntent and
// which can be used to confirm that intent from a browser. Section 6's rule
// is that a credential never leaves the service that holds it; a debugging
// table is not an exemption from it.
//
// ── WHY IT IS PRUNED ──
//
// Volume is not under our control: the set of event types delivered here is
// chosen in the provider's dashboard, and "select all" is one click. Thirty
// days is long enough to investigate a payment somebody is disputing and
// short enough that a mistake in that dashboard costs disk rather than a
// database. See PaymentWebhookEvent::prunable() and routes/console.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();

            // The company whose gateway account sent it — taken from the URL
            // and already used to pick the verifying secret, never from the
            // payload (see the controller's docblock).
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider');

            // The provider's own id for this delivery (evt_… on Stripe).
            // NOT unique: a provider re-sends the same event id on retry, and
            // seeing the retries is half the point of keeping them.
            $table->string('event_id')->nullable()->index();
            $table->string('event_type')->nullable()->index();

            /*
             * The order, when we could find one. Null is a real answer and a
             * loud one: a verified PAID event naming no order of ours means a
             * customer was charged and nothing downstream will ever know.
             * nullOnDelete so deleting an order never deletes the record that
             * money arrived for it.
             */
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_token')->nullable();

            // How this system read it: paid | failed | expired | refunded |
            // ignore, plus 'unmatched' when no order could be found.
            $table->string('result')->nullable();

            $table->string('charge_id')->nullable()->index();
            $table->unsignedBigInteger('amount_satang')->nullable(); // BR-3

            /*
             * The body. longText because an expanded Stripe object with line
             * items runs well past a TEXT column, and a payload silently cut
             * in half is worse than none: it looks complete.
             */
            $table->longText('payload')->nullable();

            $table->timestamp('received_at')->index();
            $table->timestamps();

            // The two questions actually asked of this table: "what came in
            // for this order" and "what came in just now".
            $table->index(['order_id', 'received_at']);
            $table->index(['company_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
