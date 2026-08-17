<?php

namespace App\Http\Resources;

use App\Enums\PipelineStage;
use App\Enums\TeamVisibilityLevel;
use App\Models\Client;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * TASK-107 / ADR-024 §5 — a subordinate's client, rendered at exactly the
 * level the COMPANY has configured.
 *
 * THE LEVEL IS ENFORCED HERE, NEVER IN THE FRONTEND. A field the level does
 * not permit is an absent key in the JSON — not null, not an empty string,
 * not a value the Vue component is trusted to skip rendering. That is the
 * whole point of ADR-024 §5: hiding a field in a component hides it from a
 * user, not from anyone reading the response.
 *
 * The level is passed in explicitly (constructor) rather than re-read from
 * config inside toArray(): the Controller has already resolved it from the
 * authenticated caller via DownlineService::resolveLevel(), and a Resource
 * silently re-resolving authorisation state is exactly how two code paths
 * end up disagreeing about what a viewer may see.
 */
class TeamClientResource extends JsonResource
{
    /**
     * @param  list<int>  $visibleAgentIds  TASK-111 (D3) — agent ids whose
     *                                      identity this caller may see (their
     *                                      own subtree + themself). Defaults to
     *                                      EMPTY on purpose: a call site that
     *                                      forgets to pass it discloses no
     *                                      agent identity at all, rather than
     *                                      all of them.
     */
    public function __construct(
        $resource,
        private readonly TeamVisibilityLevel $level,
        private readonly array $visibleAgentIds = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * Build a level-aware collection. JsonResource::collection() cannot be
     * used because it constructs each item with the resource alone and has
     * nowhere to carry the level.
     *
     * @param  iterable<int, Client>  $clients
     * @param  list<int>  $visibleAgentIds
     * @return Collection<int, self>
     */
    public static function forLevel(iterable $clients, TeamVisibilityLevel $level, array $visibleAgentIds = []): Collection
    {
        return collect($clients)
            ->map(fn (Client $client) => new self($client, $level, $visibleAgentIds))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return match ($this->level) {
            // The full Client File exactly as the subordinate sees it —
            // delegated to ClientResource rather than re-listed here, so
            // this Resource can never drift into exposing a field the
            // subordinate's own screen does not show, or into missing one
            // that gets added there later.
            //
            // NOTE (PDPA): ClientResource applies its own TASK-049 gate to
            // the decrypted national_id — a viewer who is not the referring
            // agent gets `national_id: null` plus the mask. A team leader is
            // not the referring agent, so a leader sees the MASK even at
            // full_file. That is deliberate: widening TASK-049's gate is a
            // separate human decision, and failing closed is the safe side
            // to be wrong on.
            TeamVisibilityLevel::FullFile => $this->fullFile($request),

            // Name + current pipeline stage, and nothing else. phone, email,
            // national_id (masked or not), address, province, occupation,
            // date_of_birth, health_notes, documents, consent and category
            // are all ABSENT KEYS — this array is built by listing what is
            // allowed, never by unsetting what is not, so a new column on
            // clients cannot leak here by default.
            TeamVisibilityLevel::Names => [
                // The id is needed to key the list and to open nothing else
                // — there is no /me/team client-detail endpoint. It is not
                // personal data on its own.
                'id' => (int) $this->resource->id,
                'name' => $this->resource->name,
                'current_stage' => $this->currentStage(),
            ],

            // Unreachable in practice — the Controller 403s at counts_only
            // before ever constructing this Resource. Kept as an explicit
            // fail-closed arm so that if a future call site forgets that
            // guard, the response is empty rather than accidentally full.
            TeamVisibilityLevel::CountsOnly => [],
        };
    }

    /**
     * The full Client File — delegated to ClientResource (see the NOTE in
     * toArray()) with ONE narrowing applied on top.
     *
     * TASK-111 (D3) — ADR-024 §3 answers 404 when a leader asks about a
     * sibling leader's node, so one branch of the org chart cannot enumerate
     * another. Before this fix a SHARED client walked straight around that
     * boundary: clientsFor() eager-loads every referral on the client, and
     * ReferralResource emits `agent.id` / `agent.name`, so any client touched
     * by two agents disclosed the second agent's name — including people in a
     * sibling leader's team, or in no team of the caller's at all.
     *
     * The fix keeps the referral ROW (the client's own deal history is
     * legitimate context, and dropping rows would silently misstate how many
     * deals exist on that person) and blanks only the IDENTITY, to `null`.
     * Deliberately null rather than a placeholder string: inventing Thai copy
     * like "เอเจนต์ท่านอื่น" here would hardcode a UI decision in the API —
     * ag-ui owns the wording, and a null is unambiguous to render against.
     *
     * @return array<string, mixed>
     */
    private function fullFile(Request $request): array
    {
        $payload = (new ClientResource($this->resource))->toArray($request);

        if ($this->resource->relationLoaded('referrals')) {
            $payload['referrals'] = collect($this->resource->referrals)
                ->map(fn (Referral $referral) => $this->narrowedReferral($referral, $request))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * One referral row with any out-of-subtree agent identity removed.
     *
     * Built from ReferralResource rather than re-listed field by field, for
     * the same reason the full file delegates to ClientResource: this must
     * never drift into showing a different set of referral fields than the
     * subordinate's own screen shows.
     *
     * @return array<string, mixed>
     */
    private function narrowedReferral(Referral $referral, Request $request): array
    {
        $row = (new ReferralResource($referral))->toArray($request);

        if (! $this->mayIdentifyAgent($referral->agent_id)) {
            $row['agent'] = null;
        }

        // co_agent is an agent identity too (TASK-026 split commission) and
        // is just as capable of naming someone outside the caller's subtree.
        //
        // TASK-174 — only narrow a key that ReferralResource actually
        // emitted. With the company's co-agent split switched off the key is
        // absent by design (spec §7), and writing `null` into it here would
        // quietly hand the field back — the one place a "hidden" feature
        // could have leaked back into a response through the side door.
        if (array_key_exists('co_agent', $row) && ! $this->mayIdentifyAgent($referral->co_agent_id)) {
            $row['co_agent'] = null;
        }

        return $row;
    }

    /**
     * Whitelist, not blacklist: unknown => not disclosed.
     *
     * @param  mixed  $agentId
     */
    private function mayIdentifyAgent($agentId): bool
    {
        return $agentId !== null && in_array((int) $agentId, $this->visibleAgentIds, true);
    }

    /**
     * The client's furthest-advanced stage among the referrals loaded for
     * this view (the Service loads only the subject subordinate's own
     * referrals at the `names` level, so this answers "how far did THIS
     * agent get with this client").
     *
     * "Furthest advanced" rather than "most recent": §4.3 transitions are
     * sequential and forward-only, so the highest-ordered stage is the true
     * current position, and it does not flip backwards just because an older
     * deal for the same client was touched last.
     *
     * @return array{key:string, label:string}|null
     */
    private function currentStage(): ?array
    {
        // Fail closed rather than lazy-load: an unloaded relation would pull
        // EVERY referral on this client, including other agents' deals on
        // the same person, which this level does not entitle the leader to.
        // The Service is responsible for loading exactly the right subset.
        if (! $this->resource->relationLoaded('referrals')) {
            return null;
        }

        // PipelineStage::cases() is declared in §4.3 sequence order, so the
        // array index IS the progression rank.
        $sequence = PipelineStage::cases();
        $best = null;
        $bestRank = -1;

        foreach ($this->resource->referrals as $referral) {
            /** @var Referral $referral */
            $rank = array_search($referral->current_stage, $sequence, true);

            if ($rank !== false && $rank > $bestRank) {
                $bestRank = $rank;
                $best = $referral->current_stage;
            }
        }

        return $best === null
            ? null
            : ['key' => $best->value, 'label' => $best->label()];
    }
}
