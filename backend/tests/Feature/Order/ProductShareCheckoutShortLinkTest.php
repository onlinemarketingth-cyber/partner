<?php

namespace Tests\Feature\Order;

use App\Enums\PipelineStage;
use App\Enums\TrackedLinkGroup;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Link\TrackedLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A SHORT product-share link can be bought from, not just looked at.
 *
 * ── THE BUG, REPORTED 2026-08-21 WITH A SCREENSHOT ──
 *
 * A customer filled in the checkout sheet on a page that had loaded
 * perfectly and got "ไม่พบข้อมูลที่ต้องการ อาจถูกลบไปแล้ว" — the SPA's
 * generic 404 copy — on a link that was alive.
 *
 * TASK-232 gave every public share URL a short code (/p/R4TB8WM2XK) beside
 * the original 64-character token, and taught
 * PublicProductShareController::resolveUsableLink() to accept either. It did
 * not teach StoreProductShareCheckoutRequest, which carried its OWN copy of
 * the lookup and matched the `token` column alone.
 *
 * So the GET went through the controller's resolver and answered 200, and
 * the POST never reached that resolver at all: Laravel runs a FormRequest
 * before the controller body, so the Request's abort fired first. Every
 * short link in existence could show a product and refuse to sell it, and
 * the message told the customer the product had been deleted.
 *
 * ── WHY IT SURVIVED THE EXISTING SUITE ──
 *
 * ProductShareCheckoutTest is thorough — happy path, BR-1, honeypot,
 * pipeline gate, deactivated company, promotions — and every one of its
 * cases posts to `{$link->token}`. The long token never stopped working, so
 * a suite written before short codes existed could not notice that a second
 * way of naming the same link had appeared. The cases below are the ones
 * that only exist because there are now TWO doors, and they assert that both
 * open.
 */
class ProductShareCheckoutShortLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProductShareLink, 1: TrackedLinkService} */
    private function sellableShare(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        // complete_registered -> complete_payment: the journey that makes a
        // product buyable straight from the page. paymentReachableFromEntry()
        // asks only whether complete_payment is the SECOND stage (ADR-026
        // §3.7), which is the same shape ProductShareCheckoutTest's
        // directSaleTemplate() builds.
        $template = PipelineTemplate::create([
            'company_id' => $company->id,
            'key' => PipelineTemplate::KEY_DIRECT_SALE_DEFAULT,
            'name' => 'Direct sale',
            'is_system' => true,
        ]);
        foreach ([PipelineStage::CompleteRegistered, PipelineStage::CompletePayment] as $position => $stage) {
            PipelineTemplateStage::create([
                'company_id' => $company->id,
                'pipeline_template_id' => $template->id,
                'stage' => $stage,
                'position' => $position,
            ]);
        }

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'price_satang' => 890000,
            'pipeline_template_id' => $template->id,
        ]);

        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        return [$link, app(TrackedLinkService::class)];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'สมชาย ใจดี',
            'phone' => '0812345678',
            'email' => 'somchai@example.com',
            'consent' => true,
        ];
    }

    public function test_checkout_works_through_the_short_code(): void
    {
        // THE REPORTED CASE. Before the fix this was 404 with the "may have
        // been deleted" message, on a link that had just rendered a product.
        [$link, $service] = $this->sellableShare();
        $code = $service->mintFor(TrackedLinkGroup::ProductShare, $link)->code;

        $this->postJson("/api/v1/public/product-shares/{$code}/checkout", $this->payload())
            ->assertOk()
            ->assertJsonStructure(['pay_url']);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_the_page_and_the_checkout_agree_about_the_same_short_code(): void
    {
        // The heart of it: reading and buying must resolve identically. A
        // link that shows a product and refuses to sell it is worse than one
        // that is simply dead, because the customer blames the product.
        [$link, $service] = $this->sellableShare();
        $code = $service->mintFor(TrackedLinkGroup::ProductShare, $link)->code;

        $this->getJson("/api/v1/public/product-shares/{$code}")->assertOk();
        $this->postJson("/api/v1/public/product-shares/{$code}/checkout", $this->payload())->assertOk();
    }

    public function test_the_long_token_still_works(): void
    {
        // Tokens are already out in the world — pasted into LINE, sitting in
        // inboxes. The short code is a SECOND door; this asserts the first
        // was not bricked up by the fix.
        [$link] = $this->sellableShare();

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())
            ->assertOk()
            ->assertJsonStructure(['pay_url']);
    }

    public function test_an_unknown_code_is_still_refused(): void
    {
        $this->sellableShare();

        $this->postJson('/api/v1/public/product-shares/NOSUCHCODE1/checkout', $this->payload())
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_revoked_short_code_is_refused(): void
    {
        // Revoking the short link must stop the sale, not just the page.
        [$link, $service] = $this->sellableShare();
        $tracked = $service->mintFor(TrackedLinkGroup::ProductShare, $link);
        $tracked->update(['revoked_at' => now()]);

        $this->postJson("/api/v1/public/product-shares/{$tracked->code}/checkout", $this->payload())
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_buying_does_not_count_as_another_page_view(): void
    {
        // The Request resolves with resolveTarget(), NOT the controller's
        // resolveViaTrackedLink() — same resolution, no side effect. The
        // latter records a visit, and a submitted order is not a page view:
        // counting it would inflate every short link's open count by one per
        // purchase, quietly corrupting the campaign stats an agent reads.
        [$link, $service] = $this->sellableShare();
        $tracked = $service->mintFor(TrackedLinkGroup::ProductShare, $link);

        $this->getJson("/api/v1/public/product-shares/{$tracked->code}")->assertOk();
        $opensAfterOneView = $tracked->fresh()->open_count;

        $this->postJson("/api/v1/public/product-shares/{$tracked->code}/checkout", $this->payload())->assertOk();

        $this->assertSame($opensAfterOneView, $tracked->fresh()->open_count);
    }
}
