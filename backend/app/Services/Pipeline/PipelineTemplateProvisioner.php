<?php

namespace App\Services\Pipeline;

use App\Enums\PipelineStage;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * ADR-026 §3.1 — provisions the two SYSTEM pipeline templates for a company.
 *
 * WHY THIS EXISTS AS A SERVICE (ag-lead, 2026-08-08, TASK-134a review)
 * ---------------------------------------------------------------------
 * This logic started life inside PipelineTemplateSeeder, which meant the
 * templates only ever existed for companies that were present when someone
 * last ran `php artisan db:seed`. `CompanyService::create()` writes a bare
 * `companies` row and nothing else, so **every company created through the
 * Admin UI after the TASK-132 deploy would have had zero templates**.
 *
 * That is not a cosmetic gap. PipelineTemplateResolver fails closed by
 * design (ADR-026 §3.3) — no template resolves, so no referral can advance
 * and no order can be confirmed. A brand-new tenant would have looked
 * perfectly healthy right up until their first sale, and then silently
 * refused to close it. Seeders are a development convenience; a tenant's
 * ability to make money is not.
 *
 * So: one definition of the two journeys, one write path, called from BOTH
 * the seeder (existing companies, re-runnable) and company creation (every
 * future company). Adding a third caller is fine; forking the definitions
 * is not.
 *
 * These sequences are STRUCTURE, not guessed business values — both are
 * written down verbatim in CLAUDE.md §4.3 and ADR-026 §3.1, so defining
 * them in code is not a BR-7 violation. Which template a given product
 * actually uses remains entirely the admin's call.
 */
class PipelineTemplateProvisioner
{
    public function __construct(private PipelineTemplateResolver $resolver) {}

    /**
     * The system templates, as data. Order within each list IS the journey.
     *
     * @return array<string, array{name: string, stages: list<PipelineStage>}>
     */
    public static function systemTemplates(): array
    {
        return [
            // CLAUDE.md §4.3's original five stages, verbatim and in order.
            // Still the correct journey for every product that genuinely
            // involves a doctor meeting — it merely stops being the only
            // one. Also the resolver's final fail-safe (ADR-026 §3.3),
            // which is why EVERY company must have it.
            PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT => [
                'name' => 'Medical Package (default)',
                'stages' => [
                    PipelineStage::CompleteRegistered,
                    PipelineStage::WaitingAppointment,
                    PipelineStage::Finish1stDoctorMeeting,
                    PipelineStage::CompletePayment,
                    PipelineStage::OngoingNextMeeting,
                ],
            ],
            // The "pay from a shared link, no medical component" journey.
            PipelineTemplate::KEY_DIRECT_SALE_DEFAULT => [
                'name' => 'Direct Sale (default)',
                'stages' => [
                    PipelineStage::CompleteRegistered,
                    PipelineStage::CompletePayment,
                ],
            ],
        ];
    }

    /**
     * Idempotent — safe to call on a company that already has them.
     */
    public function provision(Company $company): void
    {
        foreach (self::systemTemplates() as $key => $definition) {
            $this->provisionTemplate($company, $key, $definition['name'], $definition['stages']);
        }
    }

    /**
     * @param  list<PipelineStage>  $stages
     */
    private function provisionTemplate(Company $company, string $key, string $name, array $stages): void
    {
        // §6 "never trust the client" applies to a seeder and to an internal
        // service call too — these are write paths. The invariants (must
        // contain complete_registered first + complete_payment, post-sale
        // stages only after payment, ongoing_next_meeting only last, each
        // stage at most once) live in the Resolver so EVERY write path is
        // checked, not just the ones that happen to go through a Form
        // Request. If someone edits the sequences above into something
        // invalid, this throws here rather than shipping a broken tenant.
        $this->resolver->assertValidStageSequence($stages);

        DB::transaction(function () use ($company, $key, $name, $stages) {
            // TenantScope is bypassed EXPLICITLY rather than relied upon:
            // this runs both unauthenticated (seeder, where the scope
            // no-ops) and as a Super Admin creating a company they are not
            // a member of (where it also no-ops, but for a different
            // reason). Depending on two different accidents of context
            // producing the same result is not a guarantee — company_id is
            // always stated outright instead. BR-6.
            $template = PipelineTemplate::withoutGlobalScope(TenantScope::class)
                ->firstOrCreate(
                    ['company_id' => $company->id, 'key' => $key],
                    ['name' => $name, 'is_system' => true],
                );

            foreach ($stages as $position => $stage) {
                PipelineTemplateStage::withoutGlobalScope(TenantScope::class)
                    ->updateOrCreate(
                        ['pipeline_template_id' => $template->id, 'stage' => $stage->value],
                        ['company_id' => $company->id, 'position' => $position],
                    );
            }

            // Prune anything a previous revision of these definitions left
            // behind, so re-running never yields a template that is the
            // UNION of two versions of the same journey.
            PipelineTemplateStage::withoutGlobalScope(TenantScope::class)
                ->where('pipeline_template_id', $template->id)
                ->whereNotIn('stage', array_map(fn (PipelineStage $stage) => $stage->value, $stages))
                ->delete();
        });
    }
}
