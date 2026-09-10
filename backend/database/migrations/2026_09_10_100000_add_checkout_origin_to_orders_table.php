<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-10 — the portal domain this order was actually bought from.
 *
 * The portal is served from more than one first-party address (FRONTEND_URL
 * plus the parked aliases in CORS_EXTRA_ORIGINS). Every customer-facing link
 * this system produced was built from FRONTEND_URL alone, so somebody who read
 * the page, typed their details and paid on the alias received a confirmation
 * email pointing at a domain they had never seen — which reads as phishing and
 * gets deleted.
 *
 * NULL means "the canonical host", and that is the value for every order that
 * already exists as well as for every order an agent creates in the console.
 * Storing the canonical host as itself would pin those orders to today's
 * domain and break their links the day the company moves.
 *
 * The value written here is never the raw request header: App\Support\
 * PortalOrigin only accepts an origin this deployment already declares as its
 * own. See that class for why a public checkout's Origin cannot be trusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('checkout_origin')->nullable()->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('checkout_origin');
        });
    }
};
