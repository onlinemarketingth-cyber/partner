<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company payment gateway credentials (ADR-027 §3, TASK-139).
 *
 * ── WHY PER COMPANY AND NOT .env ──
 *
 * config/services.php carries a long comment recording that an earlier draft
 * put OMISE_PUBLIC_KEY / OMISE_SECRET_KEY in .env and that this was WRONG:
 * customers' money lands in the SELLING company's own account, never the
 * platform's, so one key pair for the whole platform would have routed every
 * tenant's revenue into whichever account happened to be configured. That is
 * the highest-consequence bug this system could have, and it is prevented
 * structurally here rather than by care.
 *
 * ── WHY `credentials` IS ONE ENCRYPTED JSON COLUMN ──
 *
 * Providers need different fields: Omise wants public/secret/webhook keys,
 * 2C2P wants a merchant id and a secret, the manual flow wants a PromptPay
 * proxy id. Modelled as columns that is a migration per provider and a table
 * mostly full of nulls. One blob, with each driver declaring the fields it
 * requires (PaymentGateway::credentialFields()) and a FormRequest validating
 * against that declaration.
 *
 * The trade is real and worth naming: the database can no longer guarantee
 * the shape. The driver declaration is what replaces that guarantee, which is
 * why it lives in code that is tested rather than in a comment.
 *
 * `encrypted` cast, exactly as users.bank_account_number (TASK-044) and
 * platform_mail_settings.password already do. §6 says "secrets: .env only";
 * this is the narrow exception ADR-027 §3 argued, because companies are
 * created at runtime and .env cannot grow a key pair per tenant without a
 * deploy. §6's intent — never in git, never in a response body — both hold.
 *
 * ── WHY THERE IS NO `is_active` HERE ──
 *
 * The human's rule (2026-08-22): a company uses exactly ONE gateway, never a
 * choice of several. A boolean on this table can represent two active rows,
 * and MySQL has no partial unique index to forbid it. So "which one is live"
 * is a single column on `companies` instead — a shape in which two active
 * providers cannot be written down at all.
 *
 * A row here is therefore STORED CREDENTIALS, not an active configuration.
 * Keeping a disabled provider's keys is deliberate: switching to Omise and
 * back must not mean re-typing secrets that are printed nowhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // App\Enums\PaymentProvider

            // The credentials themselves. Nullable because a row can exist
            // for a provider whose setup was started and not finished.
            $table->text('credentials')->nullable();

            /*
             * TEST OR LIVE, on the SETTINGS and stamped onto every order.
             *
             * Without this a charge made with a test key is indistinguishable
             * from revenue in every report the platform has, and no amount of
             * later work can separate them — the orders simply do not record
             * which world they happened in.
             */
            $table->boolean('is_live')->default(false);

            /*
             * When the credentials were last proven to work against the
             * provider's API. `companies.payment_provider` may not be switched
             * to a provider that has never passed — see the service. A gateway
             * whose keys are wrong fails at the customer, silently, one
             * payment at a time.
             */
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_note')->nullable();

            $table->timestamps();

            // One settings row per provider per company.
            $table->unique(['company_id', 'provider'], 'company_gateway_unique');
        });

        Schema::table('companies', function (Blueprint $table) {
            /*
             * THE ONE ACTIVE GATEWAY. Exactly one, by construction.
             *
             * Defaults to 'manual', which is what every company is already
             * doing today: a PromptPay QR built locally by PromptPayService,
             * a slip uploaded by the customer, and a human pressing confirm.
             * That flow is a payment provider whose worker is a person, and
             * modelling it as one is what makes "how does this company get
             * paid" a question with a single answer everywhere.
             */
            $table->string('payment_provider')->default('manual')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('payment_provider'));
        Schema::dropIfExists('company_payment_gateway_settings');
    }
};
