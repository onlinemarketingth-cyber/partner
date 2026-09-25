<?php

namespace Tests;

use App\Enums\PipelineStage;
use App\Models\Company;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use App\Services\Referral\PipelineService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A Company Admin who may confirm this company's payments.
     *
     * SECURITY AUDIT 2026-08-21 (human ruling D1). Confirming a payment used
     * to be something the order's own agent could do, so most tests that
     * needed a paid order simply acted as the agent they already had. That
     * is now forbidden — whoever earns the commission must not also be the
     * one attesting the money arrived — and roughly twenty tests across six
     * files needed the same new actor.
     *
     * Here rather than copied into each file so that the next test which
     * needs a confirmed payment cannot quietly reintroduce the agent by
     * writing its own helper. If who may confirm changes again, it changes
     * in one place and every test moves with it.
     */
    protected function paymentConfirmer(Company $company): User
    {
        return User::factory()->companyAdmin()->create(['company_id' => $company->id]);
    }

    /**
     * Close a sale the only way the application allows: walk the referral by
     * hand to the stage before payment, then have a Company Admin confirm a
     * paid order with proof on file.
     *
     * ═══ WHY EVERY TEST GOES THROUGH HERE (2026-09-25) ═══
     *
     * Until today most tests reached Complete Payment by pressing the advance
     * endpoint as the agent until the stage came up — because the endpoint
     * let them. That was the hole: an agent could book their own commission
     * with no order and no slip. PipelineService::advance() now refuses to
     * enter Complete Payment without the paid order that settles it, so the
     * old loops fail, correctly.
     *
     * One helper, here, for the same reason paymentConfirmer() is here: a
     * test that needs a closed sale must not be able to quietly reintroduce
     * the self-attested one by writing its own loop. If how a sale closes
     * changes again, it changes in one place.
     *
     * The walk before payment goes through the HTTP endpoint AS THE AGENT —
     * that is the path an agent really takes, and it keeps the stage-XP and
     * audit rows those earlier edges write. The close goes through
     * /orders/{id}/confirm as a Company Admin, which is the path BR-4 now
     * fires on.
     */
    protected function closeSale(Referral $referral, User $agent): Referral
    {
        $referral->refresh();
        $pipeline = app(PipelineService::class);

        if ($pipeline->hasReachedStage($referral, PipelineStage::CompletePayment)) {
            return $referral;
        }

        while ($pipeline->nextStageFor($referral) !== PipelineStage::CompletePayment) {
            // A journey with no payment stage ahead cannot be closed at all;
            // failing here names that instead of looping forever.
            $this->assertNotNull(
                $pipeline->nextStageFor($referral),
                "Referral {$referral->id} has no path to Complete Payment from {$referral->current_stage->value}.",
            );

            $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
            $referral->refresh();
        }

        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer(Company::withoutGlobalScopes()->findOrFail($referral->company_id)))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        return $referral->refresh();
    }

    /**
     * Walk a referral to $target, crossing Complete Payment the legitimate
     * way if the walk passes through it. The drop-in replacement for the
     * per-file `advanceToStage()` helpers, which pressed the endpoint across
     * the payment edge.
     */
    protected function advanceReferralTo(Referral $referral, User $agent, PipelineStage $target): Referral
    {
        $referral->refresh();
        $pipeline = app(PipelineService::class);

        while ($referral->current_stage !== $target) {
            if ($pipeline->nextStageFor($referral) === PipelineStage::CompletePayment) {
                $referral = $this->closeSale($referral, $agent);

                continue;
            }

            $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
            $referral->refresh();
        }

        return $referral;
    }
}
