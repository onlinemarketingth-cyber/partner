<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-234 — one row in "ลิงก์ของฉัน" / "ลิงก์ทั้งบริษัท".
 *
 * AUTHENTICATED ONLY, and there is deliberately no public counterpart. The
 * counts here say how many people a company is recruiting and how well its
 * agents are selling; a stranger holding a code must learn nothing beyond
 * the page the code was for.
 */
class TrackedLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'group' => $this->group->value,
            'group_label' => $this->group->label(),
            'code' => $this->code,
            'short_url' => $this->resource->publicUrl(),
            'label' => $this->label,

            'created_by_user_id' => $this->created_by_user_id,
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),

            // Null on either = forever / unlimited, never coerced to a
            // falsy number or date. The UI says "ไม่จำกัด"; a 0 here would
            // make it say the exact opposite.
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'is_usable' => $this->isUsable(),

            'click_count' => $this->click_count,
            'unique_click_count' => $this->unique_click_count,
            'conversion_count' => $this->conversion_count,

            // NULL, not 0, when nothing has been opened yet. A rate of "0%"
            // reads as "this link is failing"; "—" reads as "nobody has
            // opened it yet", which is what is true and is a completely
            // different thing for the agent to act on.
            'conversion_rate' => $this->unique_click_count > 0
                ? round($this->conversion_count / $this->unique_click_count * 100, 1)
                : null,

            /*
             * TASK-236 — the counter this app has been writing since
             * TASK-056 and never once showed anybody.
             *
             * `product_share_links.view_count` is incremented on every
             * public page load and is not rendered by ANY screen in either
             * frontend. Three years of a number collected for nothing.
             * (`sales_material_share_links.view_count` is the one
             * exception, surfaced in a modal inside the product editor.)
             *
             * Reported SEPARATELY from `click_count` rather than added to
             * it, and that is the whole point. The two count different
             * things over different periods: this one has been running
             * since the link was created and includes every bot that ever
             * fetched the page, while `click_count` starts when the short
             * code was minted and only counts real browsers. Summing them
             * would produce a number that is true of nothing.
             *
             * Null when the target has no such column — most groups do not.
             */
            'legacy_view_count' => $this->legacyViewCount(),

            'first_clicked_at' => $this->first_clicked_at,
            'last_clicked_at' => $this->last_clicked_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The pre-TASK-232 counter on the thing this link points at, if it has
     * one. Reads the loaded relation when present so a list of 50 links is
     * not 50 extra queries.
     */
    private function legacyViewCount(): ?int
    {
        $target = $this->resource->relationLoaded('target')
            ? $this->resource->getRelation('target')
            : $this->resource->target()->withoutGlobalScopes()->first();

        $count = $target?->getAttribute('view_count');

        // 0 is meaningful ("this predates the short code and nobody opened
        // it"), so only a MISSING column becomes null.
        return $count === null ? null : (int) $count;
    }
}
