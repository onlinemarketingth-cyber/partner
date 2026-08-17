<?php

namespace App\Services\Referral;

use App\Enums\GamificationSourceType;
use App\Enums\PipelineStage;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Services\Gamification\GamificationService;
use App\Services\Pipeline\PipelineTemplateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function __construct(
        private GamificationService $gamificationService,
        private PipelineTemplateResolver $pipelineTemplateResolver,
        private PipelineService $pipelineService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Referral
    {
        $data['company_id'] = $actor->company_id;

        // Same pattern as ClientService::create() — an Agent can only
        // ever submit a referral for themselves; never trust a
        // client-supplied agent_id for that role, even though the Form
        // Request already requires it be omitted.
        $data['agent_id'] = $actor->isAgent() ? $actor->id : $data['agent_id'];

        // BR-1 (Access Gate): "An agent must pass the Basic
        // certification before gaining access to SWS Referral
        // submission." This checks the RESOLVED referring agent, not
        // the actor — so a Company Admin submitting on behalf of an
        // agent who hasn't passed Basic is blocked too, not just an
        // Agent submitting for themselves. User::hasPassedCertTier() is
        // the one canonical check for this (see its docblock).
        $referringAgent = User::findOrFail($data['agent_id']);
        if (! $referringAgent->hasPassedCertTier('basic')) {
            throw ValidationException::withMessages([
                'agent_id' => 'BR-1: this agent has not passed the Basic certification yet, so no SWS Referral can be submitted on their behalf.',
            ]);
        }

        // TASK-026 — co_agent_id can't be resolved against the FINAL
        // agent_id until now (a Company Admin's agent_id isn't known to
        // the Form Request). same-company + role=agent already checked
        // by StoreReferralRequest's exists() rule.
        // TASK-170 — Thai, and no `TASK-026:` tag: this string is rendered
        // verbatim to a salesperson (the 422 body is the most specific
        // reason available, so both the drawer form and CoAgentEditor
        // prefer it over any generic copy). An internal task number in
        // front of it tells them nothing and reads as a system error.
        if (! empty($data['co_agent_id']) && (int) $data['co_agent_id'] === (int) $data['agent_id']) {
            throw ValidationException::withMessages([
                'co_agent_id' => 'ผู้ร่วมแบ่งคอมมิชชั่นต้องเป็นตัวแทนคนอื่น ไม่ใช่ตัวแทนเจ้าของรายการนี้',
            ]);
        }

        $data['current_stage'] = PipelineStage::CompleteRegistered;
        $data['meeting_number'] = null;
        $data['submitted_at'] = now();

        // ADR-026 §3.4 — snapshot the resolved pipeline template ONCE,
        // here at creation, and never re-resolve it. An admin editing a
        // template afterwards must not reroute or strand this customer
        // mid-journey (same reasoning as BR-4's immutable ledger).
        //
        // Never taken from $data: the Form Request does not accept a
        // pipeline_template_id and a client must not be able to pick its
        // own journey (§6 "never trust the client").
        //
        // The product is re-read scoped to the referral's own company
        // (BR-6) rather than via a bare find() — the actor here may be a
        // Super Admin, for whom TenantScope is a no-op. Null (product not
        // in this company / no template resolvable) leaves the snapshot
        // NULL, which TASK-133 treats as the legacy enum-default journey
        // rather than guessing one.
        $product = Product::where('company_id', $data['company_id'])->find($data['product_id'] ?? null);
        $data['pipeline_template_id'] = $product
            ? $this->pipelineTemplateResolver->resolveForProduct($product)?->id
            : null;

        return DB::transaction(function () use ($data, $actor, $referringAgent) {
            $referral = Referral::create($data);

            // Section 4.3: "Every status change must be recorded in an
            // audit log (who, when, from-status -> to-status)" — the
            // initial CompleteRegistered state counts as the first
            // entry, with from_stage null (nothing preceded it).
            PipelineStageLog::create([
                'company_id' => $referral->company_id,
                'referral_id' => $referral->id,
                'from_stage' => null,
                'to_stage' => $referral->current_stage,
                'changed_by_user_id' => $actor->id,
                'changed_at' => $referral->submitted_at,
            ]);

            // BR-5 (XP source (b): "closing a sale / moving a client
            // through the pipeline") — credited to the REFERRING agent
            // ($referringAgent, already resolved above), never the
            // actor. These can differ: a Company Admin may submit a
            // referral on behalf of an agent, and the sales-activity
            // credit must go to that agent, not whoever happened to
            // click submit.
            $this->gamificationService->awardXp($referringAgent, GamificationSourceType::ReferralSubmitted, $referral->id);

            return $referral;
        });
    }

    /**
     * TASK-026 — the ONE narrow, named-ability mutation allowed on an
     * already-submitted referral (same pattern as advance(): referrals
     * are otherwise never free-form edited, see ReferralPolicy's
     * comment on why there's no generic update()).
     *
     * THE EDIT CUTOFF (settled by the human 2026-08-11, TASK-170):
     *
     *   > A co-agent split may be edited until the referral reaches
     *   > `complete_payment` under ITS OWN pipeline template.
     *
     * Not a new business value — it FOLLOWS from BR-4: the commission
     * ledger row is written at Complete Payment and is immutable after,
     * so the last honest moment to change who gets paid is the moment
     * before it is written.
     *
     * @param  array{co_agent_id: int|null, split_percentage: int|null}  $data
     */
    public function setCoAgent(Referral $referral, array $data, User $actor): Referral
    {
        if (! $this->splitIsStillEditable($referral)) {
            throw ValidationException::withMessages([
                'co_agent_id' => 'รายการนี้ผ่านขั้นตอนการชำระเงินไปแล้ว จึงไม่สามารถแก้ไขการแบ่งคอมมิชชั่นได้ (ค่าคอมมิชชั่นถูกบันทึกไว้แล้ว)',
            ]);
        }

        if (! empty($data['co_agent_id']) && (int) $data['co_agent_id'] === (int) $referral->agent_id) {
            throw ValidationException::withMessages([
                'co_agent_id' => 'ผู้ร่วมแบ่งคอมมิชชั่นต้องเป็นตัวแทนคนอื่น ไม่ใช่ตัวแทนเจ้าของรายการนี้',
            ]);
        }

        $referral->update([
            'co_agent_id' => $data['co_agent_id'],
            'split_percentage' => $data['split_percentage'],
        ]);

        return $referral;
    }

    /**
     * TASK-170 — the cutoff above, asked of the referral's OWN journey.
     *
     * ONE implementation, and it is not here: PipelineService already
     * owns "where does this referral stand on its own template"
     * (ADR-026 §3.6 — it is the same primitive OrderService::
     * confirmPayment() gates on, §3.7). This method only chooses the
     * direction and the failure mode.
     *
     * What it replaces: an ALLOW-list of the three pre-payment MEDICAL
     * stages. That list happened to be right for every template the
     * authoring rules permit — a valid template can only place
     * complete_registered / waiting_appointment / finish_1st_doctor_meeting
     * before complete_payment (§4.3 pins complete_registered to position
     * 0, the three post-sale stages must follow complete_payment, and
     * ongoing_next_meeting may only be last) — but it was right by
     * coincidence, not by construction: it named STAGES where the rule
     * names a POSITION, and it said nothing at all about the two cases
     * below.
     *
     * UNREADABLE TEMPLATE → REFUSED (fail closed, §6). isBeforeStage()
     * throws when the referral's snapshotted template is missing from
     * its own company or has been emptied of stages, and returns false
     * when the referral is parked at a stage its own journey does not
     * contain. In every one of those cases we cannot say whether BR-4's
     * ledger row has already been written, and "who gets paid" is not a
     * question to answer on a guess. The read-only display of an
     * existing split is unaffected — only the ability to change it.
     */
    private function splitIsStillEditable(Referral $referral): bool
    {
        try {
            return $this->pipelineService->isBeforeStage($referral, PipelineStage::CompletePayment);
        } catch (ValidationException) {
            return false;
        }
    }
}
