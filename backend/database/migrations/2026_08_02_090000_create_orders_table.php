<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-017 (TASK-054) — Order & Payment Collection. An order is a payment
// -collection layer bound to a Referral: an agent creates it, the client
// pays via bank transfer / PromptPay on a public token-gated page and
// uploads a slip, then an agent/admin verifies — which advances the
// referral to Complete Payment (§4.3) and fires the existing BR-4
// commission (CommissionService, unchanged). No second commission path.
//
// company_id carries TenantScope (BR-6, §5). Money is satang integer
// (BR-3). Company payment config (bank/PromptPay) is BR-7 admin config —
// see the sibling migration add_payment_config_to_companies_table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Same FK style as the referrals table: company cascades,
            // the denormalized business FKs (client/agent/product) restrict
            // so a referenced row can't be deleted out from under an order.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // Human-readable reference, unique within a company (see unique
            // index below). Generated in OrderService, never client-supplied.
            $table->string('order_number');
            // Unguessable public share link id (Str::random(40)) — the /pay/{token}
            // page is looked up by THIS, never by the enumerable primary id
            // (§5 rule 5 IDOR concern, same treatment as affiliate links).
            $table->string('public_token', 40)->unique();
            // BR-3 — customer-facing amount, snapshot of product.price_satang
            // at creation. Commission still uses CommissionService's own
            // promo-aware price resolution as the source of truth (ADR-017).
            $table->unsignedBigInteger('amount_satang');
            $table->string('payment_method'); // App\Enums\PaymentMethod
            $table->string('status')->default('pending'); // App\Enums\OrderStatus
            // Uploaded payment slip on the PRIVATE disk (§6) — nullable until
            // the customer uploads one. Never a public URL; served only via
            // the access-checked authenticated download endpoint.
            $table->string('slip_path')->nullable();
            // Reserved for a future payment-gateway reference (ADR-017) —
            // no gateway is wired this phase.
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'agent_id', 'status'], 'orders_agent_status_idx');
            $table->unique(['company_id', 'order_number'], 'orders_company_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
