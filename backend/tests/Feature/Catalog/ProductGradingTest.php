<?php

namespace Tests\Feature\Catalog;

use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Company;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Services\Catalog\ProductGradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * TASK-180 §3 (B1) / §6 — ProductGradingService's "sold" definition.
 *
 * The Service used to carry its own SOLD_STAGES = [complete_payment,
 * ongoing_next_meeting]. Since ADR-026 a template may continue into
 * จัดส่ง / นัดใช้บริการ / ติดตามผล, so a product whose deals get advanced
 * into a post-sale stage lost sold_count and could fall to grade D while
 * it was in fact selling. ABC grading drives merchandising decisions, so
 * these assertions are about what the business does, not a label.
 *
 * Every test here fails if that two-stage list is restored.
 */
class ProductGradingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A referral for $product, at $stage, carrying the
     * to_stage=complete_payment log a real PipelineService::advance()
     * would have written on the way through.
     */
    private function closedReferral(Company $company, User $agent, Product $product, PipelineStage $stage): Referral
    {
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
        ]);

        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'current_stage' => $stage,
        ]);

        PipelineStageLog::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $referral->id,
            'from_stage' => PipelineStage::Finish1stDoctorMeeting,
            'to_stage' => PipelineStage::CompletePayment,
            'changed_by_user_id' => $agent->id,
            'changed_at' => now(),
        ]);

        return $referral;
    }

    /** @return array<string, mixed> */
    private function rowFor(Collection $grades, Product $product): array
    {
        $row = $grades->firstWhere('product_id', $product->id);
        $this->assertNotNull($row, "Product {$product->id} missing from the grade table");

        return $row;
    }

    /**
     * §6's named case: a product whose ONLY deals sit at `delivery` still
     * counts as sold. Under the old two-stage list this product had
     * sold_count 0 and grade D — "stop stocking it" — while every one of
     * its customers had paid.
     */
    public function test_a_product_whose_only_deals_sit_at_delivery_still_counts_as_sold(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);

        $this->closedReferral($company, $agent, $product, PipelineStage::Delivery);
        $this->closedReferral($company, $agent, $product, PipelineStage::Delivery);

        $row = $this->rowFor(app(ProductGradingService::class)->computeGrades($actor, null), $product);

        $this->assertSame(2, $row['sold_count']);
        // D is reserved for "zero sales in the window". Which of A/B/C a
        // selling product lands on is the pre-existing Pareto arithmetic
        // (the cumulative running total includes the product's own share,
        // so a lone product reads 100% and grades C) — untouched here and
        // out of scope. What this task changes is that it is no longer D.
        $this->assertNotSame('D', $row['grade'], 'A product every customer paid for must not be graded "no sales"');
        // BR-3: satang integers all the way through, no round(), no float.
        $this->assertSame(2 * 890000, $row['estimated_revenue_satang']);
    }

    /**
     * The whole post-sale group (ADR-026 §5 Q1), plus the two stages the
     * old list did happen to cover — so restoring the old list is caught
     * by the count, not only by one stage's presence.
     */
    public function test_every_stage_at_or_past_complete_payment_counts_as_sold(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $sold = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 100000]);
        $unsold = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 100000]);

        foreach ([
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
            PipelineStage::Delivery,
            PipelineStage::ServiceAppointment,
            PipelineStage::FollowUp,
        ] as $stage) {
            $this->closedReferral($company, $agent, $sold, $stage);
        }

        // Not yet paid — must NOT count, in either direction.
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $unsold->id,
            'current_stage' => PipelineStage::WaitingAppointment,
        ]);

        $grades = app(ProductGradingService::class)->computeGrades($actor, null);

        $this->assertSame(5, $this->rowFor($grades, $sold)['sold_count']);
        $this->assertSame(0, $this->rowFor($grades, $unsold)['sold_count']);
        $this->assertSame('D', $this->rowFor($grades, $unsold)['grade']);
    }

    /**
     * BR-6 / §5. NOT empty-vs-empty: company B owns a real, genuinely
     * closed referral that points at company A's product — the shape an
     * import or a moved product produces, and exactly what an IDOR-ish
     * scope hole would surface. A's sold_count must count A's deal and
     * only A's deal; deleting the `company_id` filter from
     * ProductGradingService's sold-counts query turns this 1 into a 2.
     */
    public function test_grades_never_count_another_companys_closed_deals(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $actorA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);

        $productA = Product::factory()->create(['company_id' => $companyA->id, 'price_satang' => 100000]);

        $this->closedReferral($companyA, $agentA, $productA, PipelineStage::Delivery);
        // Company B's row, aimed at company A's product.
        $this->closedReferral($companyB, $agentB, $productA, PipelineStage::Delivery);

        $grades = app(ProductGradingService::class)->computeGrades($actorA, null);

        $this->assertCount(1, $grades, 'Company A must see only its own products');
        $this->assertSame(1, $this->rowFor($grades, $productA)['sold_count']);
        $this->assertSame(100000, $this->rowFor($grades, $productA)['estimated_revenue_satang']);
    }

    /** The caller-supplied window still applies on top of the predicate. */
    public function test_window_still_excludes_deals_submitted_before_the_cutoff(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 100000]);

        $recent = $this->closedReferral($company, $agent, $product, PipelineStage::FollowUp);
        $recent->forceFill(['submitted_at' => now()->subDays(3)])->save();

        $old = $this->closedReferral($company, $agent, $product, PipelineStage::FollowUp);
        $old->forceFill(['submitted_at' => now()->subDays(120)])->save();

        $grades = app(ProductGradingService::class)->computeGrades($actor, 30);

        $this->assertSame(1, $this->rowFor($grades, $product)['sold_count']);
    }
}
