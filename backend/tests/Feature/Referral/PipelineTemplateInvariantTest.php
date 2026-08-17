<?php

namespace Tests\Feature\Referral;

use App\Enums\PipelineStage;
use App\Services\Pipeline\PipelineTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-026 §3.5 + §5 Q1 (TASK-132) — the stage-sequence invariants.
 *
 * These call the SERVICE directly, with no HTTP request and therefore no
 * Form Request anywhere in the picture. That is the point (CLAUDE.md §6
 * "never trust the client"): a template missing complete_payment is a
 * silent BR-4 commission outage, so it must be unrepresentable through
 * every write path — seeder, console command, or an endpoint that forgets
 * its Request — not only through the validated one.
 */
class PipelineTemplateInvariantTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): PipelineTemplateResolver
    {
        return app(PipelineTemplateResolver::class);
    }

    /**
     * @param  list<PipelineStage|string>  $stages
     */
    private function assertRejected(array $stages): void
    {
        try {
            $this->resolver()->assertValidStageSequence($stages);
            $this->fail('Expected the Service to reject this stage sequence, but it accepted it.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stages', $exception->errors());
        }
    }

    public function test_a_template_without_complete_payment_is_rejected(): void
    {
        // ADR-026 §3.5 — BR-4 fires at complete_payment and nowhere else.
        $this->assertRejected([
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
        ]);
    }

    public function test_a_template_without_complete_registered_is_rejected(): void
    {
        // CLAUDE.md §4.3 — complete_registered is the mandatory entry stage.
        $this->assertRejected([
            PipelineStage::WaitingAppointment,
            PipelineStage::CompletePayment,
        ]);
    }

    public function test_an_empty_template_is_rejected(): void
    {
        $this->assertRejected([]);
    }

    public function test_a_repeated_stage_is_rejected(): void
    {
        // ADR-026 §5 Q1 — "each at most once".
        $this->assertRejected([
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::CompletePayment,
        ]);
    }

    public function test_complete_registered_must_be_the_first_stage(): void
    {
        $this->assertRejected([
            PipelineStage::WaitingAppointment,
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
    }

    public function test_a_post_sale_stage_before_complete_payment_is_rejected(): void
    {
        // ADR-026 §5 Q1 — "a post-sale step before the sale is closed is
        // not a thing".
        $this->assertRejected([
            PipelineStage::CompleteRegistered,
            PipelineStage::Delivery,
            PipelineStage::CompletePayment,
        ]);
    }

    public function test_a_stage_outside_the_enum_is_rejected(): void
    {
        // ADR-026 §3.2 / §2 Option C — stages are a closed enum, never
        // admin-typed free text.
        $this->assertRejected([
            'complete_registered',
            'ship_it_somehow',
            'complete_payment',
        ]);
    }

    public function test_post_sale_stages_after_complete_payment_are_accepted_in_any_order(): void
    {
        // ADR-026 §5 Q1 — optional and unordered AS A GROUP.
        $this->resolver()->assertValidStageSequence([
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
            PipelineStage::FollowUp,
            PipelineStage::Delivery,
            PipelineStage::ServiceAppointment,
        ]);

        $this->assertTrue(true, 'A valid post-sale sequence must not raise.');
    }

    public function test_the_two_seeded_system_sequences_are_valid(): void
    {
        // Guards against the invariants and the seeder drifting apart:
        // medical_package_default IS the resolver's fail-safe, so it must
        // never become unrepresentable (ADR-026 §3.1, §3.3).
        $this->resolver()->assertValidStageSequence([
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ]);

        $this->resolver()->assertValidStageSequence([
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);

        $this->assertTrue(true, 'Both seeded system templates must satisfy the invariants.');
    }

    public function test_stage_values_accept_plain_strings_as_well_as_enum_cases(): void
    {
        // TASK-134's Form Request will hand this method raw request
        // strings, not enum cases — both must be understood identically.
        $this->resolver()->assertValidStageSequence(['complete_registered', 'complete_payment']);

        $this->assertTrue(true, 'String stage values must be normalised to enum cases.');
    }
}
