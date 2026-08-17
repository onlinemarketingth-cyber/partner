<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ExamResource hides `config` (effectively the answer key) from Agent —
// a policy decision layered on top of Section 6's "never leak fields"
// rule, worth its own explicit test since it's easy to regress silently.
class ExamResourceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_does_not_see_exam_config_but_admin_does(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'config' => ['answer_key' => 'secret']]);

        $this->actingAs($agent)
            ->getJson("/api/v1/exams/{$exam->id}")
            ->assertOk()
            ->assertJsonPath('data.config', null);

        $this->actingAs($admin)
            ->getJson("/api/v1/exams/{$exam->id}")
            ->assertOk()
            ->assertJsonPath('data.config.answer_key', 'secret');
    }
}
