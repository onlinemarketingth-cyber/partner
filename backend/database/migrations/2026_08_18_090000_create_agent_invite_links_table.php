<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-112 / ADR-025 §3 — a team leader's shareable "join my team" link.
// Modelled on product_share_links (token + revoked_at + usage counter +
// TenantScope + a Policy scoping an agent to their own rows) with the
// expiry semantics of company_invite_codes folded in.
//
// TWO deliberate departures from company_invite_codes, both from the
// human's answer "ตั้งค่าได้ทั้งวันหมดอายุ และจำนวนคน หรือไม่ limit"
// (ADR-025 §3):
//   - expires_at is NULLABLE here (mandatory there). NULL = never expires.
//   - max_uses is new and also nullable. NULL = unlimited recruits.
// Neither limit gets a default value in this migration on purpose — a
// default would be a business number baked into schema, which BR-7
// forbids; "no limit" is the human's chosen meaning of "left blank", not
// a fallback we invented.
//
// token is a 64-char cryptographically random string (Str::random(64),
// minted in TASK-113's service), never an incrementing id — Section 5
// rule 5's IDOR concern, same convention as every other public-token
// table in this app.
//
// MIGRATION ORDER: this must run BEFORE
// 2026_08_18_090100_add_team_leader_fields_to_users_table, which adds
// users.recruited_via_agent_link_id as an FK pointing at this table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_invite_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // The inviter. Named agent_id (not inviter_id/leader_id) to
            // match product_share_links/affiliate_links, so the Policy and
            // the "own rows only" query shape read identically everywhere.
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            // NULL = never expires (ADR-025 §3). See AgentInviteLink::isUsable().
            $table->dateTime('expires_at')->nullable();
            // NULL = unlimited recruits (ADR-025 §3).
            $table->unsignedInteger('max_uses')->nullable();
            // Incremented inside the same transaction + row lock as the
            // recruit's User::create() in TASK-114 (ADR-025 §4) — this is
            // the one counter in the app where a race can defeat a quota.
            $table->unsignedInteger('used_count')->default(0);
            // Soft revoke only. TASK-113 never hard-deletes a link, so
            // users.recruited_via_agent_link_id attribution survives.
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'agent_id'], 'agent_invite_links_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_invite_links');
    }
};
