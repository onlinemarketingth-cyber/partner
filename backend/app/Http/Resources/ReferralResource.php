<?php

namespace App\Http\Resources;

use App\Enums\PipelineStage;
use App\Services\Commission\CommissionSplitSettingService;
use App\Services\Order\OrderService;
use App\Services\Referral\PipelineService;
use App\Support\RequestScopedService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // ADR-026 §3.6 (TASK-136) — read once per row from a
        // request-shared PipelineService so a Kanban board full of
        // referrals costs one template lookup per DISTINCT journey, not
        // per row (PipelineService memoises by template id).
        $journey = RequestScopedService::get($request, PipelineService::class)
            ->journeyFor($this->resource);

        // TASK-174 §7 — with the co-agent split switched off for this
        // referral's company, `co_agent` and `split_percentage` are ABSENT
        // KEYS, not nulls: a field the switch does not permit must not be
        // in the response at all, exactly as TeamClientResource treats the
        // levels it does not permit. Read through the same request-scoped
        // instance PipelineService uses, so a 200-row Kanban board costs one
        // settings lookup per company, not one per row.
        $splitEnabled = RequestScopedService::get($request, CommissionSplitSettingService::class)
            ->isEnabledForCompany($this->resource->company_id);

        return [
            'id' => $this->id,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $this->client->phone,
            ]),
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
            ]),
            // TASK-026 — null unless this referral's commission is split
            // with a second agent. TASK-174 — both keys disappear entirely
            // while the company's split is switched off (see $splitEnabled).
            ...($splitEnabled ? [
                'co_agent' => $this->whenLoaded('coAgent', fn () => $this->coAgent ? [
                    'id' => $this->coAgent->id,
                    'name' => $this->coAgent->name,
                ] : null),
                'split_percentage' => $this->split_percentage,
            ] : []),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                // BR-3 — satang stays an integer all the way to the
                // wire; only the UI display layer divides by 100.
                'price_satang' => $this->product->price_satang,
            ]),
            // TASK-134a — nullable since the branch column was widened
            // (ag-lead ruling 2026-08-08). NULL means "this sale did not
            // happen at a branch" (a self-serve checkout), and it goes
            // over the wire as a raw null on purpose: the Thai label
            // "ผ่านลิงก์ออนไลน์" is a presentation decision and belongs to
            // the Vue layer (§7 — no business/display logic leaking into
            // the API). Never substitute a display string here; doing so
            // would make a real branch literally named that
            // indistinguishable from an online sale, which is the same
            // mistake the ruling rejected placeholder values for.
            'branch' => $this->branch,
            'preferred_time' => $this->preferred_time,
            'current_stage' => [
                'key' => $this->current_stage->value,
                'label' => $this->current_stage->label(),
            ],
            'meeting_number' => $this->meeting_number,
            // ADR-026 §3.4 (TASK-132) — the journey snapshot this
            // referral was created under. NULL on pre-ADR-026 referrals.
            'pipeline_template_id' => $this->pipeline_template_id,
            // ADR-026 §3.6 (TASK-136) — THIS referral's own ordered stage
            // sequence, so a Kanban board can render the columns that
            // actually exist for it instead of §4.3's five hardcoded ones.
            //
            // Shape notes:
            //  - `stages` is ORDERED; its index IS the journey position.
            //    Same {key,label} pair as `current_stage` above, so a
            //    board can compare identities without a second mapping.
            //  - English label() only. Thai stage labels are a UI concern
            //    (PipelineStage's own docblock, §7) — the enum has none
            //    and this Resource must not invent them.
            //  - `next_stage` is the ONE legal forward move, or null at
            //    the end of the journey. Volunteered here because the
            //    board needs it to decide which drop target is legal, and
            //    because deriving it client-side would duplicate the
            //    self-loop rule for `ongoing_next_meeting`.
            //  - Legacy referral (pipeline_template_id IS NULL) falls back
            //    to PipelineStage::defaultSequence() inside PipelineService,
            //    so the board looks exactly as it does today for every
            //    pre-ADR-026 row.
            //  - Both arrays are EMPTY / null when the referral's journey
            //    cannot be read (template deleted or emptied) — fail-closed,
            //    never a guessed default journey.
            //  - No company_id and no stage ids: a board needs the
            //    vocabulary, not the tenant or the PK of a config row
            //    (§5/§6 — expose the minimum).
            'pipeline' => [
                'stages' => array_map(
                    fn (PipelineStage $stage) => ['key' => $stage->value, 'label' => $stage->label()],
                    $journey['stages'],
                ),
                'next_stage' => $journey['next'] === null ? null : [
                    'key' => $journey['next']->value,
                    'label' => $journey['next']->label(),
                ],
            ],
            // TASK-176 §1.2 — the ONE order this referral's board row may act
            // on, or null. ABSENT unless `orders` was eager-loaded, so the
            // nested uses of this Resource (ClientResource, TeamClientResource)
            // are untouched and cost no extra query.
            //
            // Still a SUBSET of OrderResource (no `public_token` — the
            // frontend only needs the derived URL, not the raw token) except
            // for `public_pay_url`, added by TASK-191. TASK-176's original
            // reasoning for excluding it ("a live payment link... nobody
            // asked to publish") no longer holds: TASK-189 made this same
            // link the one place a paid voucher renders, and TASK-190 exists
            // specifically because nothing else re-surfaces it to a customer
            // after the fact — so the board now needs it to offer a share
            // button. Derived via OrderResource::publicPayUrl() — the one
            // place that builds the URL — not re-derived here.
            //
            // BR-3 — `amount_satang` stays an integer on the wire; the UI
            // divides by 100.
            //
            // BR-6 — this key is NOT a new visibility rule. It rides on the
            // referral row itself, which ReferralController::index already
            // narrows to `agent_id = self` for an Agent (§5 rule 4) and which
            // ReferralPolicy gates for show(); an Agent therefore only ever
            // sees an order hanging off a referral they already own. Asserted
            // in ReferralOrderTest.
            'order' => $this->whenLoaded('orders', function () use ($request) {
                $order = RequestScopedService::get($request, OrderService::class)
                    ->actionableOrder($this->orders);

                return $order === null ? null : [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'status_label' => $order->status->label(),
                    'amount_satang' => $order->amount_satang,
                    'public_pay_url' => OrderResource::publicPayUrl($order),
                    'has_slip' => $order->slip_path !== null,
                    'paid_at' => $order->paid_at,
                    // Null both when nobody has confirmed yet and when the
                    // confirming user has since been removed. The UI renders
                    // "ไม่ทราบ" for it — never a fabricated name (§4.3).
                    'verified_by' => $order->relationLoaded('verifiedBy') && $order->verifiedBy !== null
                        ? ['id' => $order->verifiedBy->id, 'name' => $order->verifiedBy->name]
                        : null,
                ];
            }),
            'submitted_at' => $this->submitted_at,
            'created_at' => $this->created_at,
        ];
    }
}
