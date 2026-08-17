<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-112 / ADR-025 §1, §6.
//
// WHY A FLAG AND NOT A FOURTH ROLE. "Team leader" is an admin-granted
// capability, not an identity: role stays `agent`. Introducing a
// `partner`/`leader` role would silently change the meaning of all 97
// isCompanyAdmin() call sites and all 34 isAgent() narrowing sites, and
// would break the "not an Agent => an admin" assumption documented in
// ADR-024. A boolean column changes the meaning of exactly nothing that
// already exists — which is the whole reason ADR-025 §1 chose it.
//
// It also stays deliberately separate from "can see the team monitor"
// (ADR-024), which remains keyed on having direct reports: revoking this
// flag must stop someone recruiting WITHOUT blinding them to the team
// they still manage (ADR-025 §2).
//
// Default false, and NO backfill (unlike 2026_07_14_120000, which had to
// explicitly backfill agent_approval_status so it never retroactively
// locked anyone out): false is the correct, safe value for every existing
// row here. This flag only ever GRANTS a new capability, so defaulting it
// off can't take anything away from anyone.
//
// MIGRATION ORDER: run 2026_08_18_090000_create_agent_invite_links_table
// FIRST — recruited_via_agent_link_id below is an FK into that table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_team_leader')->default(false)->after('role');

            // Mirrors the existing registered_via_invite_code_id
            // (2026_07_14_120000) exactly: nullable + nullOnDelete, so
            // attribution is reporting-only and can never block a delete.
            // ADR-025 §6 — recorded at registration so "who recruited
            // this person" survives even if the leader later changes,
            // which manager_id alone would not preserve.
            $table->foreignId('recruited_via_agent_link_id')
                ->nullable()
                ->after('registered_via_invite_code_id')
                ->constrained('agent_invite_links')
                ->nullOnDelete();

            // Supports "find the leaders in this company" — the query
            // TASK-117's Admin UI and the leader-approval queue both run.
            // company_id leads because BR-6 means every such query is
            // ALWAYS tenant-filtered first (TenantScope), so the composite
            // is usable left-to-right; a lone index on is_team_leader
            // would be near-useless at that selectivity.
            $table->index(['company_id', 'is_team_leader'], 'users_company_team_leader_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_company_team_leader_idx');
            $table->dropConstrainedForeignId('recruited_via_agent_link_id');
            $table->dropColumn('is_team_leader');
        });
    }
};
