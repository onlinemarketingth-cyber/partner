<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-107 / ADR-024 §3 — one downline member on the Agent Portal team
 * screen. Wraps the plain array built by TeamMonitorService (never a raw
 * model, §7).
 *
 * WHAT IS DELIBERATELY NOT HERE: the subordinate's email, phone,
 * national_id, bank details or approval status. A monitoring screen needs
 * to identify a team member, not to expose their personal file — and unlike
 * client data (which the company can widen via team_visibility_settings),
 * there is no configured level that turns any of these on. If a leader ever
 * legitimately needs them, that is a new human decision, not a field to
 * quietly add here.
 *
 * The three satang figures are integers straight from the ledger (BR-3 /
 * BR-4); nothing on this Resource is a float, so no rounding can creep in.
 */
class TeamNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this['user'];

        // One extra query per node. Accepted on purpose: hasPassedCertTier()
        // /highestPassedCertTier() is documented on the User model as the
        // one place the "which tier has this agent passed" query may live
        // (BR-2), and one rendered level of a team is a handful of rows,
        // lazily expanded. Correct-and-canonical beats a hand-rolled join
        // that could drift from the model's ranking rule.
        $tier = $user->highestPassedCertTier();

        return [
            'agent_id' => (int) $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_path
                ? Storage::disk('public')->url($user->avatar_path)
                : null,
            'cert_tier' => $tier
                ? ['id' => (int) $tier->id, 'key' => $tier->key, 'name' => $tier->name]
                : null,
            // Drives the expand control; the expansion itself re-authorises
            // server-side via ?parent_id= (never trusts this flag).
            'has_children' => (bool) $this['has_children'],
            'client_count' => (int) $this['client_count'],
            'deals_by_stage' => array_map('intval', $this['deals_by_stage']),
            'total_deals' => (int) $this['total_deals'],
            'closed_deals' => (int) $this['closed_deals'],
            // TASK-179 (D2) — the disclosure that keeps sales_satang below
            // honest: closed deals with no paid order, contributing zero
            // baht. Carried here for the same reason it is carried on the
            // subtree totals — the screen showing the figure has to be able
            // to show what could not be counted.
            'closed_deals_without_order' => (int) $this['closed_deals_without_order'],
            'sales_satang' => (int) $this['sales_satang'],
            'commission_satang' => (int) $this['commission_satang'],
            'my_override_satang' => (int) $this['my_override_satang'],
        ];
    }
}
