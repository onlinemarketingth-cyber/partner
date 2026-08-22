<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-agent off switch for notification email.
 *
 * Shipped in the SAME change as the email itself, deliberately. Email an
 * agent cannot turn off is not a feature, it is a complaint queue: the first
 * person who finds five "ค่าคอมมิชชั่นจ่ายแล้ว" mails in their inbox has no
 * recourse except to filter the sender, which silently costs them the
 * approval and payment mails too.
 *
 * Defaults TRUE, because the events chosen for email in
 * config/notifications.php are the ones an agent would be worse off missing —
 * their account being approved or rejected, their money being paid, their
 * customer's payment clearing. An opt-IN default would mean nobody is
 * reached, which is the same as not building this.
 *
 * Scope note: this is the agent's own preference over their own mail. It is
 * NOT the company-level "which events email at all" setting — that lives in
 * config/notifications.php and is flagged in the task report as needing an
 * admin screen before it counts as configurable under BR-7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications_enabled')->default(true)->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_notifications_enabled');
        });
    }
};
