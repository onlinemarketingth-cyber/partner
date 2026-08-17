<?php

namespace App\Services\Referral;

use App\Enums\GamificationSourceType;
use App\Enums\PipelineStage;
use App\Models\AffiliateAttributionSetting;
use App\Models\AffiliateLink;
use App\Models\AffiliateLinkClick;
use App\Models\Client;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\Referral;
use App\Services\Gamification\GamificationService;
use App\Services\Pipeline\PipelineTemplateResolver;
use Illuminate\Support\Facades\DB;

/**
 * ADR-011 Section 4 (TASK-032) — the business logic behind
 * POST /api/v1/public/affiliate-leads/{token}, the first unauthenticated
 * WRITE endpoint in this codebase. Deliberately mirrors ReferralService::
 * create() (same Client + Referral + PipelineStageLog + XP shape) rather
 * than inventing a parallel lead-capture mechanic, per ADR-011's own
 * framing ("creates a Client + Referral, same as a manually-submitted
 * SWS Referral does today").
 */
class AffiliateLeadCaptureService
{
    public function __construct(
        private GamificationService $gamificationService,
        private PipelineTemplateResolver $pipelineTemplateResolver,
    ) {}

    /**
     * ag-lead judgment call (not spelled out in the task spec beyond
     * "applies the attribution-window rule"): a lead capture ALWAYS
     * creates the Client + Referral and always credits them to the
     * link's own agent — losing a genuine lead entirely just because a
     * click aged out of the attribution window would be worse for the
     * business than simply not crediting it as an "attributed
     * conversion" for reporting purposes. What the attribution window
     * actually gates is narrower: whether `referrals.affiliate_link_id`
     * gets stamped at all.
     *
     * Attribution rule: the MOST RECENT click on this link (last-click
     * attribution — the standard/default model, same "documented
     * algorithmic choice" treatment as every other walk/placement
     * algorithm in this codebase) must be within
     * affiliate_attribution_settings.attribution_window_days of NOW. No
     * settings row configured, or no click at all, both mean "not
     * attributed" — same "config gap never blocks the underlying
     * action" philosophy as CommissionService::recordForReferral().
     *
     * BR-1 is still enforced here (the link's agent must currently hold
     * Basic certification) — an affiliate link is a selling channel
     * like any other, and BR-1 gates selling channels uniformly.
     *
     * Returns null (never throws) if the link's agent has lost BR-1
     * access — the Controller turns that into a generic public-facing
     * rejection rather than leaking WHY.
     */
    public function capture(AffiliateLink $link, array $data): ?Referral
    {
        if (! $link->agent || ! $link->agent->hasPassedCertTier('basic')) {
            return null;
        }

        $productId = $data['product_id'] ?? $link->product_id;
        if (! $productId) {
            return null;
        }

        $isAttributed = $this->hasValidClickWithinAttributionWindow($link);

        // ADR-026 §3.4 (TASK-132) — same creation-time template snapshot
        // ReferralService::create() takes, so a lead captured through a
        // public link follows the same journey an agent-submitted referral
        // for that product would. Resolved from the LINK's company (BR-6):
        // this runs unauthenticated, where TenantScope is a complete no-op,
        // so the company filter has to be explicit.
        $product = Product::where('company_id', $link->company_id)->find($productId);
        $pipelineTemplateId = $product
            ? $this->pipelineTemplateResolver->resolveForProduct($product)?->id
            : null;

        return DB::transaction(function () use ($link, $data, $productId, $isAttributed, $pipelineTemplateId) {
            $client = Client::create([
                'company_id' => $link->company_id,
                'referring_agent_id' => $link->agent_id,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'consent_given_at' => now(),
                'lead_source' => 'Affiliate Link',
            ]);

            $referral = Referral::create([
                'company_id' => $link->company_id,
                'client_id' => $client->id,
                'agent_id' => $link->agent_id,
                'product_id' => $productId,
                'branch' => $data['branch'],
                'preferred_time' => $data['preferred_time'] ?? null,
                'current_stage' => PipelineStage::CompleteRegistered,
                'meeting_number' => null,
                'submitted_at' => now(),
                'affiliate_link_id' => $isAttributed ? $link->id : null,
                'pipeline_template_id' => $pipelineTemplateId,
            ]);

            // Section 4.3 audit trail — same initial-entry shape as
            // ReferralService::create(). changed_by_user_id has no
            // nullable column (there is no authenticated actor on a
            // public route) — the link's own agent is the closest thing
            // to "who this action is on behalf of", same reasoning as
            // crediting them the XP below.
            PipelineStageLog::create([
                'company_id' => $referral->company_id,
                'referral_id' => $referral->id,
                'from_stage' => null,
                'to_stage' => $referral->current_stage,
                'changed_by_user_id' => $link->agent_id,
                'changed_at' => $referral->submitted_at,
            ]);

            $this->gamificationService->awardXp($link->agent, GamificationSourceType::ReferralSubmitted, $referral->id);

            return $referral;
        });
    }

    private function hasValidClickWithinAttributionWindow(AffiliateLink $link): bool
    {
        $settings = AffiliateAttributionSetting::where('company_id', $link->company_id)->first();
        if (! $settings) {
            return false;
        }

        $lastClick = AffiliateLinkClick::where('link_id', $link->id)
            ->orderByDesc('clicked_at')
            ->first();

        if (! $lastClick) {
            return false;
        }

        return $lastClick->clicked_at->gte(now()->subDays($settings->attribution_window_days));
    }
}
