<?php

namespace App\Services\Registration;

use App\Enums\TrackedLinkGroup;
use App\Models\AgentInviteLink;
use App\Models\User;
use App\Services\Link\TrackedLinkService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TASK-113 / ADR-025 §3 — minting, and soft-revoking, a team leader's
 * "join my team" recruit link.
 *
 * Modelled on ProductShareLinkService: the Policy answers "whose row is
 * this" (AgentInviteLinkPolicy), this Service answers "are you allowed to
 * create one at all". Keeping the capability gate here rather than in the
 * Policy is the same split the product-share precedent uses for BR-1, and
 * it is what lets TASK-114's registration path reuse the model without
 * inheriting a minting rule it has no business enforcing.
 *
 * Consuming a link (increment used_count under lockForUpdate) is
 * deliberately NOT here — that is TASK-114, per ADR-025 §4.
 */
class AgentInviteLinkService
{
    public function __construct(private readonly TrackedLinkService $trackedLinks) {}

    /**
     * Mint a new recruit link owned by $agent.
     *
     * @param  array<string, mixed>  $attributes  Already validated by
     *                                            StoreAgentInviteLinkRequest: only label / expires_at /
     *                                            max_uses can ever be present. company_id, agent_id, token,
     *                                            used_count and revoked_at are derived here, server-side —
     *                                            never read from $attributes even if a caller passed them
     *                                            (BR-6, Section 5 rule 5).
     *
     * @throws ValidationException when $agent may not recruit (User::canRecruit(), ADR-049).
     */
    public function create(User $agent, array $attributes): AgentInviteLink
    {
        // ADR-049 (supersedes ADR-025 §1's flag-only gate) — who may
        // recruit is the company's own choice, asked through
        // User::canRecruit() so this, the registration consume path and the
        // leader's approval can never disagree. A designated team leader
        // still always may; under the default policy so may any agent who
        // has passed Basic.
        //
        // ADR-025 §2 still holds: the team monitor stays keyed on HAVING
        // DIRECT REPORTS, so losing this right stops future recruiting
        // without blinding anyone to the team they already manage.
        if (! $agent->canRecruit()) {
            throw ValidationException::withMessages([
                // Key kept as `is_team_leader`: it is what the portal already
                // reads, and the designated-leader flag is still the one admin
                // action that always unblocks this.
                'is_team_leader' => 'ยังชวนเข้าทีมไม่ได้ — ต้องผ่านหลักสูตร Basic ก่อน หรือให้แอดมินเปิดสิทธิ์หัวหน้าทีม',
            ]);
        }

        // DELIBERATELY NOT IDEMPOTENT.
        //
        // ProductShareLinkService::create() — the file this one is copied
        // from — reuses an existing unrevoked link for the same agent+product
        // instead of minting a second one. That is CORRECT THERE and WRONG
        // HERE, so do not "restore" it: a recruit link carries a label, an
        // expiry and a usage quota, and a leader running two drives at once
        // ("งาน Open House ต.ค. / max 20" and "เพื่อนแนะนำเพื่อน / ไม่จำกัด")
        // needs both alive simultaneously with different limits. ADR-005
        // decision 6 already established that several valid codes may coexist,
        // so this is not a new rule. Reuse would also make `used_count`
        // meaningless as a per-campaign figure — the exact opposite of the
        // reason product shares reuse (one running view_count per
        // agent+product).
        $link = AgentInviteLink::create([
            'company_id' => $agent->company_id,
            'agent_id' => $agent->id,
            'token' => $this->generateUniqueToken(),
            'label' => $attributes['label'] ?? null,
            // Both limits null = UNLIMITED (ADR-025 §3) — the human's chosen
            // meaning of "leave it blank", not a missing value to default
            // (BR-7). Never substitute a house default here.
            'expires_at' => $attributes['expires_at'] ?? null,
            'max_uses' => $attributes['max_uses'] ?? null,
            'used_count' => 0,
        ]);

        // TASK-232 — the short code. `label` is passed through so the
        // campaign name the leader typed is on the tracked link too, and
        // the links dashboard can group by it without joining back here.
        $this->trackedLinks->mintFor(
            TrackedLinkGroup::TeamSignup,
            $link,
            $agent,
            $attributes['label'] ?? null,
        );

        // Not `fresh()` — see ProductShareLinkService for why re-fetching
        // here costs the caller `wasRecentlyCreated`.
        return $link;
    }

    /**
     * Soft revoke — NEVER a hard delete.
     *
     * `users.recruited_via_agent_link_id` is a nullOnDelete FK, so deleting
     * the row would silently null every recruit's attribution and destroy
     * the record of who brought them into the company (ADR-025 §6). The row
     * must survive its own revocation. AgentInviteLink::isUsable() already
     * treats a non-null revoked_at as unusable, so nothing else has to
     * change for the link to stop working.
     */
    public function revoke(AgentInviteLink $link): AgentInviteLink
    {
        // Idempotent: re-revoking keeps the FIRST revocation timestamp, so a
        // double-tap in the UI can't rewrite when the link actually stopped
        // working (that timestamp is audit evidence, per Section 6).
        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => now()]);
        }

        return $link;
    }

    /**
     * 64 random chars — unguessable (Section 5 rule 5), the same convention
     * as every other public-token table in this app.
     *
     * The retry loop mirrors OrderService::generatePublicToken(): the DB's
     * unique index on `token` is the real guarantee, this just stops an
     * insert ever failing on it. withoutGlobalScopes() because a collision
     * with ANOTHER company's token would still break the public lookup in
     * TASK-114 — uniqueness here is platform-wide, not tenant-wide.
     */
    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (AgentInviteLink::withoutGlobalScopes()->where('token', $token)->exists());

        return $token;
    }
}
